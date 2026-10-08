<?php

declare(strict_types=1);

namespace Aicountly\Api;

use Aicountly\Api\Ai\AiClient;
use Aicountly\Api\Support\Clock;
use PDOException;

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
    /** Migration bookkeeping table for this product. */
    private const MIGRATIONS_TABLE = 'voice_sql_migrations';

    public static function show(): never
    {
        $report = self::report();

        Http::json($report['data']['database']['ok'] ? 200 : 503, $report);
    }

    /** What /api/health answers, as data. @return array{data: array<string, mixed>} */
    public static function report(): array
    {
        $db = self::database();
        // Usable means connected AND migrated at all. "pending" and "ready" below say whether it is up to date.
        $ok = $db['reachable'] === true && (int) ($db['schema']['applied'] ?? 0) > 0;

        return [
            'data' => [
                'status'   => $ok ? 'ok' : 'degraded',
                'app'      => 'Voice',
                // From configuration (Environment), never from this request's
                // Host. "not_configured" means no sibling is called at all.
                'env'      => Environment::current() ?? 'not_configured',
                'time'     => Clock::iso(Clock::now()),
                // `ok`, `migrations_applied` and `error` are what this block always had. The rest says why.
                'database' => ['ok' => $ok] + $db + ($ok
                    ? ['migrations_applied' => (int) $db['schema']['applied']]
                    : ['error' => 'The database is not reachable or not migrated.']),
                'capabilities' => Features::all(),
                // Why each capability is off, for an administrator reading this
                // on a host they can change.
                'unconfigured' => self::unconfigured(),
                // AI runs through AI Pulse. With no user here, Pulse is asked
                // only when a service key is configured; otherwise this says so.
                'ai'       => AiClient::describeAvailability(),
            ],
        ];
    }

    /**
     * `source` says where the database name and username came from: "console" (CONSOLE_API_URL and
     * CONSOLE_DB_DETAILS_KEY are both set, so Console is asked) or "env" (DB_NAME / DB_USER in api/.env).
     * "env" on a deployed server is the sign that Console is not in use, whatever else is configured.
     *
     * A failure to get the name and username (Console refused, unreachable, or no key to ask with) is
     * reported by its own reason (console_key_missing, console_key_rejected, ...) with a fixed `hint` saying
     * what to do (DatabaseDiagnosis). Both come from a fixed list and can never echo a key or a name.
     *
     * @return array<string, mixed>
     */
    public static function database(): array
    {
        $source = ConsoleDatabaseDetails::isConfigured() ? 'console' : 'env';

        try {
            $pdo = Db::connect();
        } catch (PDOException $e) {
            $out = [
                'reachable' => false,
                'source'    => $source,
                'reason'    => self::categorise($e),
                'schema'    => null,
            ];
            if ($e instanceof DatabaseConnectionException) {
                $out['hint'] = DatabaseDiagnosis::hint($e->category);
            }

            return $out;
        } catch (\Throwable $e) {
            error_log('[health] database check failed: ' . $e->getMessage());

            return ['reachable' => false, 'source' => $source, 'reason' => 'error', 'schema' => null];
        }

        $onDisk = count(glob(__DIR__ . '/../database/migrations/*.sql') ?: []);

        try {
            $applied = (int) $pdo->query('SELECT COUNT(*) FROM ' . self::MIGRATIONS_TABLE)->fetchColumn();
        } catch (\Throwable) {
            // No bookkeeping table means migrate.php has never run here. That is
            // a normal state on a host somebody has only just created, not an
            // error worth logging.
            $applied = 0;
        }

        return [
            'reachable' => true,
            'source'    => $source,
            'reason'    => null,
            'schema'    => [
                'applied' => $applied,
                'pending' => max(0, $onDisk - $applied),
                // The one field worth reading at a glance: can this app be used?
                'ready'   => $onDisk > 0 && $applied >= $onDisk,
            ],
        ];
    }

    /**
     * A category a stranger may see, from a message they may not.
     *
     * The full driver text is logged, because the person who has to fix this
     * needs the database and role names that the category deliberately omits.
     */
    private static function categorise(PDOException $e): string
    {
        // A failure to obtain the database name / username already has its category (and its message is
        // ours, never the driver's): use it as it is, so Console's refusals are not squeezed into "error".
        if ($e instanceof DatabaseConnectionException) {
            error_log('[health] database unreachable [' . $e->category . ']: ' . $e->getMessage());

            return $e->category;
        }

        $message = $e->getMessage();
        error_log('[health] database unreachable: ' . $message);

        $m = strtolower($message);

        return match (true) {
            str_contains($m, 'not configured')       => 'not_configured',
            str_contains($m, 'pg_hba'),
            str_contains($m, 'password authentication'),
            str_contains($m, 'role ') && str_contains($m, 'does not exist') => 'refused',
            str_contains($m, 'does not exist')       => 'no_such_database',
            str_contains($m, 'connection refused'),
            str_contains($m, 'could not connect'),
            str_contains($m, 'timeout')              => 'unreachable',
            str_contains($m, 'could not find driver') => 'driver_missing',
            default                                   => 'error',
        };
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
