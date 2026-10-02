<?php

declare(strict_types=1);

namespace Aicountly\Api;

use Aicountly\Api\Ai\AiClient;
use Aicountly\Api\Support\Clock;

/**
 * Liveness, and what this deployment is configured to do.
 *
 * Unauthenticated, so it says nothing about any tenant and never names a
 * credential's VALUE. Naming which environment variables are missing is the
 * point of it — that is what turns "the Call button does nothing" into "the
 * gateway URL was never set on this host".
 */
final class Health
{
    public static function show(): never
    {
        $database = self::database();

        Http::json($database['ok'] ? 200 : 503, [
            'data' => [
                'status'   => $database['ok'] ? 'ok' : 'degraded',
                'app'      => 'Voice',
                // From configuration (Environment), never from this request's
                // Host. "not_configured" means no sibling is called at all.
                'env'      => Environment::current() ?? 'not_configured',
                'time'     => Clock::iso(Clock::now()),
                'database' => $database,
                'capabilities' => Features::all(),
                // Why each capability is off, for an administrator reading this
                // on a host they can change.
                'unconfigured' => self::unconfigured(),
                // AI runs through AI Pulse. With no user here, Pulse is asked
                // only when a service key is configured; otherwise this says so.
                'ai'       => AiClient::describeAvailability(),
            ],
        ]);
    }

    /** @return array<string, mixed> */
    private static function database(): array
    {
        try {
            $applied = (int) (Db::scalar('SELECT COUNT(*) FROM voice_sql_migrations') ?? 0);

            return ['ok' => true, 'migrations_applied' => $applied];
        } catch (\Throwable $e) {
            // The message is deliberately generic: a PDO error can echo the DSN.
            return ['ok' => false, 'error' => 'The database is not reachable or not migrated.'];
        }
    }

    /** @return array<string, string> */
    private static function unconfigured(): array
    {
        $out = [];
        if (Environment::current() === null) {
            $out['ENVIRONMENT'] = Environment::explainUnconfigured();
        }
        foreach (Features::all() as $flag => $enabled) {
            if (!$enabled) {
                $out[$flag] = (string) Features::explain($flag);
            }
        }

        return $out;
    }
}
