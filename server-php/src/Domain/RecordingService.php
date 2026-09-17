<?php

declare(strict_types=1);

namespace Aicountly\Api\Domain;

use Aicountly\Api\Audit;
use Aicountly\Api\Auth;
use Aicountly\Api\Context;
use Aicountly\Api\Db;
use Aicountly\Api\Env;
use Aicountly\Api\Settings;
use Aicountly\Api\Support\Clock;

/**
 * Access to recordings, and the retention clock on them.
 *
 * A recording is a private conversation between two people, at least one of
 * whom is not in the room when somebody presses play. Three things follow:
 *
 *  1. THE FILE IS NEVER PUBLIC. It lives in private storage. Playback happens
 *     through a URL that is minted for one person, expires in minutes, and is
 *     never stored, never logged and never returned anywhere but to the person
 *     who asked.
 *
 *  2. EVERY ACCESS IS AUDITED. Playing and downloading are separate
 *     permissions and separate audit actions, because downloading takes the
 *     conversation out of this product's controls entirely.
 *
 *  3. THE CLOCK STARTS WHEN THE RECORDING LANDS. `delete_after` is computed
 *     from the policy in force at that moment, so shortening the policy later
 *     does not retroactively destroy evidence somebody is relying on, and
 *     lengthening it does not resurrect what has already gone.
 */
final class RecordingService
{
    /** Long enough to start playing, short enough that a leaked link is stale. */
    private const PLAYBACK_TTL_SECONDS = 300;

    /**
     * A short-lived, single-purpose playback grant.
     *
     * The URL is returned to the caller and to nobody else. It is not written
     * to the audit row (that would make the audit trail a set of working keys)
     * and not written to any log.
     *
     * @return array{ok: bool, code: ?string, message: ?string, playback: ?array<string, mixed>}
     */
    public static function playback(Context $ctx, Auth $auth, string $recordingUuid, bool $download = false): array
    {
        $row = self::row($ctx, $recordingUuid);
        if ($row === null) {
            return ['ok' => false, 'code' => 'not_found', 'message' => 'That recording is not in this company.', 'playback' => null];
        }
        if ($row['deleted_at'] !== null) {
            return [
                'ok' => false, 'code' => 'deleted',
                'message' => 'This recording has been deleted under the retention policy.',
                'playback' => null,
            ];
        }
        if ((string) $row['status'] !== 'available' || empty($row['storage_key'])) {
            return [
                'ok' => false, 'code' => 'not_available',
                'message' => 'This recording is not available yet.',
                'playback' => null,
            ];
        }

        $expiresAt = Clock::now()->modify('+' . self::PLAYBACK_TTL_SECONDS . ' seconds');
        $url = self::signUrl((string) $row['storage_key'], $expiresAt->getTimestamp(), $download);

        if ($url === null) {
            return [
                'ok' => false, 'code' => 'storage_not_configured',
                'message' => 'Recording storage is not configured for this deployment.',
                'playback' => null,
            ];
        }

        Audit::record(
            $ctx,
            $auth,
            $download ? Audit::RECORDING_DOWNLOADED : Audit::RECORDING_PLAYED,
            'recording',
            $recordingUuid,
            // The recording's identity and duration. Never the URL.
            ['call_id' => (int) $row['call_id'], 'duration_seconds' => (int) $row['duration_seconds']],
        );

        return [
            'ok' => true, 'code' => null, 'message' => null,
            'playback' => [
                'url'        => $url,
                'expires_at' => Clock::iso($expiresAt),
                'media_type' => (string) $row['media_type'],
                'duration_seconds' => (int) $row['duration_seconds'],
            ],
        ];
    }

    /**
     * Register a recording the provider has produced, and start its clock.
     *
     * @param array<string, mixed> $input
     */
    public static function register(Context $ctx, int $callId, array $input): int
    {
        $settings = Settings::forCompany($ctx->cmpId);
        $retentionDays = (int) $settings['recording_retention_days'];

        return (int) Db::insert('voice_recordings', [
            'recording_uuid' => (string) ($input['recording_uuid'] ?? \Aicountly\Api\Support\Uuid::v4()),
            'call_id'        => $callId,
            'cmp_id'         => $ctx->cmpId,
            'bo_id'          => $ctx->boId,
            'kind'           => (string) ($input['kind'] ?? 'call'),
            'storage_key'    => $input['storage_key'] ?? null,
            'provider_ref'   => $input['provider_ref'] ?? null,
            'media_type'     => (string) ($input['media_type'] ?? 'audio/mpeg'),
            'duration_seconds' => (int) ($input['duration_seconds'] ?? 0),
            'bytes'          => (int) ($input['bytes'] ?? 0),
            'status'         => (string) ($input['status'] ?? 'available'),
            // Computed once, from the policy in force now.
            'delete_after'   => $retentionDays > 0
                ? Clock::sql(Clock::now()->modify('+' . $retentionDays . ' days'))
                : null,
        ], 'recording_id');
    }

    /** @return array<string, mixed>|null */
    public static function row(Context $ctx, string $recordingUuid): ?array
    {
        [$scope, $params] = $ctx->scopeClause();
        $params['uuid'] = $recordingUuid;

        return Db::first('SELECT * FROM voice_recordings WHERE ' . $scope . ' AND recording_uuid = :uuid', $params);
    }

    /** @param array<string, mixed> $row @return array<string, mixed> */
    public static function present(array $row): array
    {
        return [
            'recording_uuid' => (string) $row['recording_uuid'],
            'call_id'        => (int) $row['call_id'],
            'kind'           => (string) $row['kind'],
            'media_type'     => (string) $row['media_type'],
            'duration_seconds' => (int) $row['duration_seconds'],
            'status'         => $row['deleted_at'] !== null ? 'deleted' : (string) $row['status'],
            'legal_hold'     => (bool) $row['legal_hold'],
            'delete_after'   => $row['delete_after'],
            'created_at'     => $row['created_at'],
            // Deliberately absent: any URL. One is minted per request, on
            // request, by playback().
        ];
    }

    /**
     * Sign a storage URL.
     *
     * The HMAC covers the key AND the expiry, so neither can be edited in the
     * address bar to reach a different recording or to extend the grant.
     */
    private static function signUrl(string $storageKey, int $expiresAt, bool $download): ?string
    {
        $base = rtrim(Env::get('RECORDING_STORAGE_URL'), '/');
        $secret = Env::get('RECORDING_SIGNING_KEY');
        if ($base === '' || $secret === '') {
            return null;
        }

        $disposition = $download ? 'attachment' : 'inline';
        $signature = hash_hmac('sha256', $storageKey . '|' . $expiresAt . '|' . $disposition, $secret);

        return $base . '/' . ltrim($storageKey, '/') . '?' . http_build_query([
            'expires'     => $expiresAt,
            'disposition' => $disposition,
            'signature'   => $signature,
        ]);
    }
}
