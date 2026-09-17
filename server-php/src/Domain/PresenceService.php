<?php

declare(strict_types=1);

namespace Aicountly\Api\Domain;

use Aicountly\Api\Context;
use Aicountly\Api\Db;
use Aicountly\Api\Support\Clock;

/**
 * Agent availability.
 *
 * ## Presence expires
 *
 * An agent's browser is closed, their laptop sleeps, their network drops. None
 * of those send a "going offline" message, so a presence row that only changes
 * when somebody tells it to will show that agent as Available for the rest of
 * the day — and the router will keep sending them calls that ring out.
 *
 * So presence carries an expiry. The console heartbeats; if the heartbeats stop
 * the state lapses to `unknown`, which is NOT `available` and is not `offline`
 * either. The supervisor's screen shows it as stale, and the router skips it.
 * Saying "we do not know where this agent is" is more useful than guessing.
 */
final class PresenceService
{
    /** A heartbeat must arrive at least this often. */
    public const HEARTBEAT_SECONDS = 60;

    /** Grace on top, so one slow request does not drop an agent out of the queue. */
    private const GRACE_SECONDS = 30;

    public const STATES = ['available', 'busy', 'wrap_up', 'away', 'offline'];

    /**
     * @return array{ok: bool, code: ?string, message: ?string}
     */
    public static function set(Context $ctx, int $agentId, string $presence, ?string $reason = null): array
    {
        if (!in_array($presence, self::STATES, true)) {
            return ['ok' => false, 'code' => 'unknown_presence', 'message' => 'That is not an availability state.'];
        }

        $now = Clock::now();
        $updated = Db::update('voice_agents', [
            'presence'        => $presence,
            'presence_reason' => $reason,
            'presence_since'  => Clock::sql($now),
            // Offline is deliberate and does not lapse; everything else does.
            'presence_expires_at' => $presence === 'offline'
                ? null
                : Clock::sql($now->modify('+' . (self::HEARTBEAT_SECONDS + self::GRACE_SECONDS) . ' seconds')),
            'updated_at'      => Clock::sql($now),
        ], ['agent_id' => $agentId, 'cmp_id' => $ctx->cmpId]);

        return $updated === 0
            ? ['ok' => false, 'code' => 'not_found', 'message' => 'That agent is not in this company.']
            : ['ok' => true, 'code' => null, 'message' => null];
    }

    /** Extend without changing state. What the console calls on a timer. */
    public static function heartbeat(Context $ctx, int $agentId): bool
    {
        return Db::update('voice_agents', [
            'presence_expires_at' => Clock::sql(
                Clock::now()->modify('+' . (self::HEARTBEAT_SECONDS + self::GRACE_SECONDS) . ' seconds'),
            ),
        ], ['agent_id' => $agentId, 'cmp_id' => $ctx->cmpId]) > 0;
    }

    /**
     * The roster, with lapsed presence reported as what it is.
     *
     * @return list<array<string, mixed>>
     */
    public static function roster(Context $ctx): array
    {
        [$scope, $params] = $ctx->scopeClause('a');

        $rows = Db::all(
            'SELECT a.*,
                    (a.presence_expires_at IS NOT NULL AND a.presence_expires_at < NOW()) AS is_stale,
                    (SELECT COUNT(*) FROM voice_calls c
                      WHERE c.owner_agent_id = a.agent_id AND c.ended_at IS NULL) AS active_calls
               FROM voice_agents a
              WHERE ' . $scope . ' AND a.is_active = TRUE
              ORDER BY a.extension NULLS LAST, a.agent_id',
            $params,
        );

        return array_map(static fn (array $row): array => self::present($row), $rows);
    }

    /**
     * Counts for the Live Operations header.
     *
     * `unknown` is its own bucket and is never folded into `available`.
     *
     * @return array<string, int>
     */
    public static function summary(Context $ctx): array
    {
        [$scope, $params] = $ctx->scopeClause();

        $row = Db::first(
            'SELECT
                COUNT(*) AS total,
                COUNT(*) FILTER (WHERE presence = \'available\' AND (presence_expires_at IS NULL OR presence_expires_at >= NOW())) AS available,
                COUNT(*) FILTER (WHERE presence = \'busy\'    AND (presence_expires_at IS NULL OR presence_expires_at >= NOW())) AS busy,
                COUNT(*) FILTER (WHERE presence = \'wrap_up\' AND (presence_expires_at IS NULL OR presence_expires_at >= NOW())) AS wrap_up,
                COUNT(*) FILTER (WHERE presence = \'away\'    AND (presence_expires_at IS NULL OR presence_expires_at >= NOW())) AS away,
                COUNT(*) FILTER (WHERE presence = \'offline\') AS offline,
                COUNT(*) FILTER (WHERE presence <> \'offline\' AND presence_expires_at IS NOT NULL AND presence_expires_at < NOW()) AS unknown
               FROM voice_agents WHERE ' . $scope . ' AND is_active = TRUE',
            $params,
        ) ?? [];

        return array_map('intval', [
            'total'     => $row['total'] ?? 0,
            'available' => $row['available'] ?? 0,
            'busy'      => $row['busy'] ?? 0,
            'wrap_up'   => $row['wrap_up'] ?? 0,
            'away'      => $row['away'] ?? 0,
            'offline'   => $row['offline'] ?? 0,
            'unknown'   => $row['unknown'] ?? 0,
        ]);
    }

    /** @param array<string, mixed> $row @return array<string, mixed> */
    public static function present(array $row): array
    {
        $stale = (bool) ($row['is_stale'] ?? false);

        return [
            'agent_id'   => (int) $row['agent_id'],
            // The portal owns the name. This is the handle for looking it up.
            'user_uuid'  => (string) $row['user_uuid'],
            'extension'  => $row['extension'],
            'voice_role' => (string) $row['voice_role'],
            'skills'     => Db::jsonColumn($row['skills'] ?? null),
            'languages'  => Db::jsonColumn($row['languages'] ?? null),
            'presence'   => $stale ? 'unknown' : (string) $row['presence'],
            'presence_is_stale' => $stale,
            'last_known_presence' => (string) $row['presence'],
            'presence_since' => $row['presence_since'],
            'active_calls' => (int) ($row['active_calls'] ?? 0),
        ];
    }
}
