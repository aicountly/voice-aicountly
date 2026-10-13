<?php

declare(strict_types=1);

namespace Aicountly\Api\Metering;

use Aicountly\Api\Db;
use Aicountly\Api\Env;
use Aicountly\Metering\UsageMeter;

require_once __DIR__ . '/AicountlyUsageMeter.php';

/**
 * What Voice reports to Manage's central usage meter
 * (manage-aicountly docs/usage-metering/; the client is AicountlyUsageMeter.php, copied unchanged).
 *
 * ## Measures only
 *
 * Nothing here can refuse, delay or change a call. The meter writes to a local
 * spool and never throws, it records nothing unless AICOUNTLY_METERING_ENABLED=1,
 * and the one read this class makes is caught, so a metering problem can never
 * turn a provider callback into an error.
 *
 * ## What one call is
 *
 * Every ended call is one event: voice.calls = 1 and voice.call_seconds = the
 * talk time Voice computes from the gateway's answered and ended events (0 for
 * a call nobody answered). The provider is the AICOUNTLY Voice Gateway, which
 * holds the carrier contracts; Voice's RATE_* numbers are its own estimates and
 * are NOT sent as a vendor cost — Manage prices call seconds from its rate card.
 *
 * Voice is a gateway: a call placed by CRM, Lobby, Sales, Appointments or POS
 * with its service key is booked to that product. The product comes from the
 * call's `origin`, which the credential that created the call decided
 * (Auth::provenOrigin), never a request body.
 */
final class VoiceMeter
{
    /** Call origins that name a calling product, as its catalog id. Everything else is Voice's own. */
    private const ORIGIN_APPS = [
        'CRM'          => 'crm',
        'LOBBY'        => 'receptionist',
        'SALES'        => 'sales',
        'APPOINTMENTS' => 'appointments',
        'POS'          => 'pos',
    ];

    public static function meter(): UsageMeter
    {
        return UsageMeter::shared(static fn (string $key): string => Env::get($key));
    }

    /**
     * Report a call that has just ended. Called once, where the call reached its
     * terminal state; the key is the call's own id, so a repeat is the same event.
     */
    public static function recordCall(int $cmpId, int $callId): void
    {
        $meter = self::meter();
        if (!$meter->enabled()) {
            return;
        }

        try {
            $call = Db::first(
                'SELECT c.call_uuid, c.cmp_id, c.bo_id, c.direction, c.origin, c.state, c.talk_seconds, c.ended_at,
                        a.user_uuid
                   FROM voice_calls c
                   LEFT JOIN voice_agents a ON a.agent_id = c.owner_agent_id AND a.cmp_id = c.cmp_id
                  WHERE c.call_id = :id AND c.cmp_id = :cmp',
                ['id' => $callId, 'cmp' => $cmpId],
            );
        } catch (\Throwable $e) {
            error_log('[voice-metering] call ' . $callId . ' not metered: ' . $e->getMessage());

            return;
        }
        if ($call === null || $call['ended_at'] === null || (string) $call['call_uuid'] === '') {
            return;
        }

        $state = (string) $call['state'];
        $direction = (string) $call['direction'];
        $userUuid = $call['user_uuid'] ?? null;
        $endedAt = date_create((string) $call['ended_at']);

        $meter->record([
            'idempotency_key'    => 'voice:call:' . $call['call_uuid'],
            'occurred_at'        => $endedAt === false ? null : $endedAt->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d\TH:i:s\Z'),
            'origin_app'         => self::ORIGIN_APPS[(string) $call['origin']] ?? 'voice',
            'company_id'         => (int) $call['cmp_id'] > 0 ? (int) $call['cmp_id'] : null,
            'branch_id'          => (int) $call['bo_id'] > 0 ? (int) $call['bo_id'] : null,
            'user_uuid'          => is_string($userUuid) && preg_match('/^[A-Za-z0-9][A-Za-z0-9_-]{0,63}$/', $userUuid) === 1 ? $userUuid : null,
            'category'           => 'voice',
            'provider'           => 'aicountly_voice_gateway',
            'service'            => in_array($direction, ['inbound', 'outbound', 'internal'], true) ? $direction : null,
            'provider_operation' => 'call',
            'correlation_id'     => (string) $call['call_uuid'],
            'outcome'            => $state === 'completed' ? 'success' : 'failed',
            'provider_status'    => preg_match('/^[a-z_]{1,48}$/', $state) === 1 ? $state : null,
            'usage'              => [
                ['meter' => 'voice.call_seconds', 'quantity' => max(0, (int) $call['talk_seconds'])],
                ['meter' => 'voice.calls', 'quantity' => 1],
            ],
        ]);
    }
}
