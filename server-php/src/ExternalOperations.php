<?php

declare(strict_types=1);

namespace Aicountly\Api;

use Aicountly\Api\Support\Clock;
use Aicountly\Api\Support\Uuid;

/**
 * Every write Voice makes into another AICOUNTLY product goes through here.
 *
 * ## The case this exists for
 *
 * An AI agent, mid-call, asks Calendar to create a booking. The request times
 * out. Voice now knows one thing and not another: it knows it sent the request,
 * and it does not know whether Calendar acted on it.
 *
 * There are three wrong answers and one right one.
 *
 *   WRONG — tell the caller it is booked. It may not be.
 *   WRONG — tell the caller it failed. It may have worked, and now there is a
 *           booking nobody will turn up to.
 *   WRONG — send it again. That is how one caller gets two appointments.
 *   RIGHT — record the outcome as UNKNOWN, say so, and find out from Calendar,
 *           through Calendar's API: for Calendar, the source_ref lookup, and
 *           only then the SAME attempt under the SAME Idempotency-Key (our
 *           correlation id), which Calendar replays rather than repeats.
 *
 * That is the whole design. `status` distinguishes succeeded, failed and
 * unknown, and only an authoritative acknowledgement from the owner API ever
 * produces `succeeded`. Nothing in this product may present an external write
 * as done on the strength of having sent it.
 *
 * ## What is stored
 *
 * The operation's own state, our correlation id, and — once acknowledged — the
 * owner's identifier for the record. NEVER the record. A booking's time lives
 * in Calendar; a task's text lives in CRM. What is here is a pointer and a
 * receipt, so that a screen showing "booked" can link to the thing that is
 * actually booked rather than to a copy that will drift.
 */
final class ExternalOperations
{
    public const TABLE = 'voice_external_operations';

    public const PENDING    = 'pending';
    public const SUCCEEDED  = 'succeeded';
    public const FAILED     = 'failed';
    public const UNKNOWN    = 'unknown';
    public const RECONCILED = 'reconciled';
    public const ABANDONED  = 'abandoned';
    /**
     * The owner refused BEFORE acting — its credentials, scope or schema are
     * not ready — so nothing happened there and the SAME attempt is sent again
     * later. Not failed (a configuration fix makes it work) and not unknown
     * (the owner told us it did not act).
     */
    public const DEFERRED   = 'deferred';

    /** How long to keep asking the owner API about an unknown before a human is asked instead. */
    private const MAX_RECONCILE_ATTEMPTS = 6;

    /**
     * Open an operation before the call goes out.
     *
     * The row exists BEFORE the request, not after: if this process dies
     * mid-request, the reconciler must still be able to find out what we
     * started. An operation only discovered after a successful response is an
     * operation that cannot be recovered from a crash.
     *
     * `$wire` is for an owner whose writes can be replayed (Calendar v1): the
     * exact request — owner_uuid, source_ref, request_method, request_path,
     * request_body, if_match — so a retry of THIS attempt is the same request
     * under the same key. It is our request, not their record.
     *
     * @param array<string, mixed> $requestSummary what we asked for — never the foreign record
     * @param array<string, int|null> $links call_id / commitment_id / callback_id
     * @param array<string, mixed> $wire
     * @return array{operation_id:int, correlation_id:string}
     */
    public static function begin(
        Context $ctx,
        string $targetApp,
        string $operation,
        array $requestSummary = [],
        array $links = [],
        ?string $actor = null,
        ?string $correlationId = null,
        array $wire = [],
    ): array {
        $correlationId ??= 'voice-' . Uuid::v4();

        $values = [
            'cmp_id'          => $ctx->cmpId,
            'bo_id'           => $ctx->boId,
            'target_app'      => $targetApp,
            'operation'       => $operation,
            'correlation_id'  => $correlationId,
            'idempotency_key' => $correlationId,
            'call_id'         => $links['call_id'] ?? null,
            'commitment_id'   => $links['commitment_id'] ?? null,
            'callback_id'     => $links['callback_id'] ?? null,
            'request_summary' => $requestSummary,
            'status'          => self::PENDING,
            'attempts'        => 1,
            'last_attempt_at' => Clock::sql(Clock::now()),
            'created_by'      => $actor,
        ];
        foreach (['owner_uuid', 'source_ref', 'request_method', 'request_path', 'request_body', 'if_match', 'next_check_at'] as $column) {
            if (array_key_exists($column, $wire)) {
                $values[$column] = $wire[$column];
            }
        }

        $operationId = (int) Db::insert(self::TABLE, $values, 'operation_id');

        return ['operation_id' => $operationId, 'correlation_id' => $correlationId];
    }

    /**
     * Set an operation's status and whatever goes with it, in one statement.
     *
     * For callers that classify the owner's answer themselves because the
     * owner's contract says more than 2xx/4xx/5xx (Calendar v1: see
     * Domain\CallbackDiary). The same rule holds: SUCCEEDED only with the
     * owner's identifier in hand.
     *
     * @param array<string, mixed> $values
     */
    public static function mark(int $operationId, string $status, array $values = []): void
    {
        if ($status === self::SUCCEEDED && trim((string) ($values['external_ref'] ?? '')) === '') {
            throw new \LogicException('An external operation cannot succeed without the owner\'s identifier.');
        }

        Db::update(self::TABLE, ['status' => $status, 'updated_at' => Clock::sql(Clock::now())] + $values, [
            'operation_id' => $operationId,
        ]);
    }

    /**
     * Record the owner API's answer.
     *
     * `$result` is an ApiClient result. The mapping from it to a status is the
     * point of this method, and the interesting branch is the last one:
     *
     *   - 2xx with the owner's id      → succeeded. The only path to success.
     *   - a 4xx that is not a timeout  → failed. The owner rejected it; there is
     *                                    nothing to reconcile, and a retry would
     *                                    be rejected identically.
     *   - transport failure, a 5xx, or → unknown. We do not know. Ask later.
     *     a 2xx with no identifier
     *
     * A 2xx whose body carries no identifier is deliberately NOT a success: if
     * the owner cannot tell us what it created, we cannot link to it, and
     * claiming success would leave a booking nobody can find.
     *
     * @param array{ok:bool, status:int, body:?array, error:?string} $result
     */
    public static function settle(int $operationId, array $result, ?string $externalRef = null): string
    {
        $status = self::classify($result, $externalRef);

        $values = [
            'status'      => $status,
            'http_status' => $result['status'] ?: null,
            'updated_at'  => Clock::sql(Clock::now()),
        ];

        if ($status === self::SUCCEEDED) {
            $values['external_ref'] = $externalRef;
            $values['error_code'] = null;
            $values['error_message'] = null;
        } else {
            $values['error_code'] = $status === self::UNKNOWN ? 'outcome_unknown' : 'rejected';
            $values['error_message'] = self::shortError($result);
            if ($status === self::UNKNOWN) {
                // Give the owner a moment to finish whatever it was doing before
                // asking it what happened.
                $values['next_check_at'] = Clock::sql(Clock::now()->modify('+2 minutes'));
            }
        }

        Db::update(self::TABLE, $values, ['operation_id' => $operationId]);

        return $status;
    }

    /**
     * The owner told us, on a later ask, what it holds.
     *
     * Reaching here means the uncertainty is resolved: either the owner has the
     * record (and we now hold its id) or it never created one (and the
     * operation failed, cleanly, and may be retried).
     */
    public static function reconcile(int $operationId, bool $ownerHasRecord, ?string $externalRef): void
    {
        Db::update(self::TABLE, [
            'status'       => $ownerHasRecord ? self::SUCCEEDED : self::FAILED,
            'external_ref' => $ownerHasRecord ? $externalRef : null,
            'error_code'   => $ownerHasRecord ? null : 'not_created',
            'error_message' => $ownerHasRecord ? null : 'The owning product has no record of this request.',
            'next_check_at' => null,
            'updated_at'   => Clock::sql(Clock::now()),
        ], ['operation_id' => $operationId]);
    }

    /** The owner is still not answering. Back off, or give up and say so. */
    public static function deferReconcile(int $operationId, int $attempts): void
    {
        if ($attempts >= self::MAX_RECONCILE_ATTEMPTS) {
            Db::update(self::TABLE, [
                'status'        => self::ABANDONED,
                'error_code'    => 'unresolved',
                'error_message' => 'Could not confirm the outcome with the owning product. A person needs to check it.',
                'next_check_at' => null,
                'updated_at'    => Clock::sql(Clock::now()),
            ], ['operation_id' => $operationId]);

            return;
        }

        // Exponential, capped. Hammering a product that is already struggling
        // is how one product's bad hour becomes two.
        $minutes = min(240, 2 ** $attempts);

        Db::update(self::TABLE, [
            'attempts'      => $attempts,
            'next_check_at' => Clock::sql(Clock::now()->modify('+' . $minutes . ' minutes')),
            'updated_at'    => Clock::sql(Clock::now()),
        ], ['operation_id' => $operationId]);
    }

    /**
     * Ask again later, and keep asking — never give up into "failed".
     *
     * For an owner that can answer the question (Calendar v1's source_ref
     * lookup), an unknown stays unknown until it answers: the contract (§12.4)
     * is that a lookup that keeps failing is "pending verification", shown as
     * such, with backoff — not an outcome somebody has to guess at.
     */
    public static function retryLater(int $operationId, int $attempts, ?string $errorMessage = null): void
    {
        $minutes = min(240, 2 ** max(1, min(8, $attempts)));

        $values = [
            'attempts'      => $attempts,
            'next_check_at' => Clock::sql(Clock::now()->modify('+' . $minutes . ' minutes')),
            'updated_at'    => Clock::sql(Clock::now()),
        ];
        if ($errorMessage !== null) {
            $values['error_message'] = mb_substr($errorMessage, 0, 300);
        }

        Db::update(self::TABLE, $values, ['operation_id' => $operationId]);
    }

    /**
     * Operations whose outcome nobody knows yet, and attempts waiting to be
     * sent again, oldest first.
     *
     * @return list<array<string, mixed>>
     */
    public static function dueForReconcile(int $limit = 50): array
    {
        return Db::all(
            'SELECT * FROM ' . self::TABLE . '
              WHERE status IN (:unknown, :pending, :deferred)
                AND (next_check_at IS NULL OR next_check_at <= NOW())
              ORDER BY created_at
              LIMIT ' . max(1, min(200, $limit)),
            ['unknown' => self::UNKNOWN, 'pending' => self::PENDING, 'deferred' => self::DEFERRED],
        );
    }

    /** @return array<string, mixed>|null */
    public static function find(Context $ctx, int $operationId): ?array
    {
        return Db::first(
            'SELECT * FROM ' . self::TABLE . ' WHERE operation_id = :id AND cmp_id = :cmp',
            ['id' => $operationId, 'cmp' => $ctx->cmpId],
        );
    }

    /**
     * An earlier attempt at the same logical action, if there was one.
     *
     * Checked BEFORE resubmitting anything: an operation sitting at `unknown`
     * must be reconciled, never re-sent.
     *
     * @return array<string, mixed>|null
     */
    public static function findByCorrelation(Context $ctx, string $targetApp, string $correlationId): ?array
    {
        return Db::first(
            'SELECT * FROM ' . self::TABLE . '
              WHERE cmp_id = :cmp AND target_app = :app AND correlation_id = :corr',
            ['cmp' => $ctx->cmpId, 'app' => $targetApp, 'corr' => $correlationId],
        );
    }

    /**
     * What a screen may say about this operation.
     *
     * The wording is part of the contract: "unknown" must not read as either
     * success or failure to the person looking at it.
     *
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    public static function present(array $row): array
    {
        $status = (string) $row['status'];

        return [
            'operation_id'  => (int) $row['operation_id'],
            'target_app'    => (string) $row['target_app'],
            'operation'     => (string) $row['operation'],
            'status'        => $status,
            'external_ref'  => $row['external_ref'] ?? null,
            'confirmed'     => $status === self::SUCCEEDED,
            'message'       => match ($status) {
                self::SUCCEEDED => 'Confirmed by ' . ucfirst((string) $row['target_app']) . '.',
                self::FAILED    => (string) ($row['error_message'] ?? 'The other product declined this.'),
                self::UNKNOWN   => 'Sent, but ' . ucfirst((string) $row['target_app'])
                                   . ' has not confirmed it. Checking — do not send it again.',
                self::DEFERRED  => ucfirst((string) $row['target_app']) . ' has not accepted it yet ('
                                   . (string) ($row['error_message'] ?? 'not ready') . '). Nothing was written there;'
                                   . ' Voice will send the same request again.',
                self::ABANDONED => 'Could not be confirmed with ' . ucfirst((string) $row['target_app'])
                                   . '. Check there before trying again.',
                default         => 'In progress.',
            },
            'created_at'    => $row['created_at'] ?? null,
            'updated_at'    => $row['updated_at'] ?? null,
        ];
    }

    /** @param array{ok:bool, status:int, body:?array, error:?string} $result */
    private static function classify(array $result, ?string $externalRef): string
    {
        if ($result['ok']) {
            // Acknowledged, but with nothing we can point at later.
            return ($externalRef === null || $externalRef === '') ? self::UNKNOWN : self::SUCCEEDED;
        }

        $status = (int) $result['status'];

        // No response at all, or the far side fell over mid-write: we cannot
        // tell whether it acted.
        if ($status === 0 || $status >= 500 || $status === 408 || $status === 429) {
            return self::UNKNOWN;
        }

        // 4xx: the owner understood and refused. Nothing happened there.
        return self::FAILED;
    }

    /** @param array{ok:bool, status:int, body:?array, error:?string} $result */
    private static function shortError(array $result): string
    {
        $error = (string) ($result['error'] ?? '');
        if ($error === '') {
            $error = 'HTTP ' . $result['status'];
        }

        return mb_substr($error, 0, 300);
    }
}
