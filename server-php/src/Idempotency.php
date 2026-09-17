<?php

declare(strict_types=1);

namespace Aicountly\Api;

use Aicountly\Api\Support\Clock;

/**
 * The same request twice is the same call once.
 *
 * ## Who needs this
 *
 * An agent on a laggy console taps Call, sees nothing happen, taps again.
 * Lobby's request to book a callback times out mid-flight and retries. Both are
 * normal and both must produce ONE outbound call — the second attempt should
 * get the first attempt's answer, not a second call to the same customer.
 *
 * A duplicate call is not a duplicate row. It is a second phone ringing in
 * somebody's house.
 *
 * ## How
 *
 * The caller sends `Idempotency-Key`. The first request runs, and its status
 * and body are stored against the key. A repeat replays them verbatim. There is
 * no attempt to decide whether the second request is "the same" beyond the key:
 * a key is a promise from the caller, and second-guessing it means guessing.
 *
 * ## No key, no protection
 *
 * A mutation with no key runs normally. Refusing it would break every caller
 * that has not been updated, and generating one server-side would protect
 * nothing — two taps produce two requests and two keys. The React client sends
 * one on every mutation for exactly this reason (see web/src/services/api.ts).
 */
final class Idempotency
{
    public const TABLE = 'voice_idempotency_keys';

    /** Keys older than this are housekeeping, not protection. */
    private const RETENTION_DAYS = 7;

    /**
     * Replay a previous answer, or null to go ahead.
     *
     * @return array{status: int, body: array<string, mixed>}|null
     */
    public static function replay(Context $ctx, string $scope, ?string $key): ?array
    {
        $key = self::normalise($key);
        if ($key === null) {
            return null;
        }

        $row = Db::first(
            'SELECT response_status, response_body FROM ' . self::TABLE . '
              WHERE cmp_id = :cmp AND scope = :scope AND idempotency_key = :key',
            ['cmp' => $ctx->cmpId, 'scope' => $scope, 'key' => $key],
        );

        if ($row === null) {
            return null;
        }

        return [
            'status' => (int) $row['response_status'],
            'body'   => Db::jsonColumn($row['response_body'] ?? null),
        ];
    }

    /**
     * Record what was answered, so a repeat gets the same thing.
     *
     * ON CONFLICT DO NOTHING rather than an upsert: if two copies of the same
     * request genuinely raced past replay(), the FIRST answer is the real one
     * and overwriting it with the second would hand the caller two different
     * truths depending on which retry landed last.
     *
     * @param array<string, mixed> $body
     */
    public static function remember(Context $ctx, string $scope, ?string $key, int $status, array $body): void
    {
        $key = self::normalise($key);
        if ($key === null) {
            return;
        }

        try {
            Db::run(
                'INSERT INTO ' . self::TABLE . ' (cmp_id, scope, idempotency_key, response_status, response_body)
                 VALUES (:cmp, :scope, :key, :status, :body)
                 ON CONFLICT (cmp_id, scope, idempotency_key) DO NOTHING',
                [
                    'cmp'    => $ctx->cmpId,
                    'scope'  => $scope,
                    'key'    => $key,
                    'status' => $status,
                    'body'   => json_encode($body, JSON_UNESCAPED_UNICODE),
                ],
            );
        } catch (\Throwable $e) {
            // Failing to record the key must not fail the operation that
            // already succeeded. The cost is that one retry could repeat — the
            // cost of the alternative is telling the caller their call failed
            // after it was placed.
            error_log('[idempotency] could not record key: ' . $e->getMessage());
        }
    }

    /** The key from the request header, if the caller sent one. */
    public static function fromRequest(): ?string
    {
        return self::normalise(Http::header('Idempotency-Key'));
    }

    /**
     * Housekeeping. Called by bin/retention.php, not on the request path.
     *
     * @return int rows removed
     */
    public static function prune(): int
    {
        $cutoff = Clock::now()->modify('-' . self::RETENTION_DAYS . ' days');

        return Db::run(
            'DELETE FROM ' . self::TABLE . ' WHERE created_at < :cutoff',
            ['cutoff' => Clock::sql($cutoff)],
        )->rowCount();
    }

    /**
     * 8–200 characters of a conservative alphabet, or nothing.
     *
     * A key is used as a lookup value only — never interpolated, never logged —
     * but bounding it here keeps a caller from using the table as storage.
     */
    private static function normalise(?string $key): ?string
    {
        $key = trim((string) $key);
        if ($key === '' || strlen($key) < 8 || strlen($key) > 200) {
            return null;
        }
        if (preg_match('/^[A-Za-z0-9._:-]+$/', $key) !== 1) {
            return null;
        }

        return $key;
    }
}
