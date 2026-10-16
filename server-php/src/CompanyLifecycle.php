<?php

declare(strict_types=1);

namespace Aicountly\Api;

use Aicountly\Lifecycle\Kit;

/**
 * Manage's company lifecycle in Voice, through the vendored receiver kit
 * (lib/company-lifecycle, kit README; contract: manage-aicountly docs/company-lifecycle/README.md).
 *
 *   - receive():  POST /api/internal/manage/company-lifecycle — Manage's signed events. No session,
 *                 no company scope: the HMAC (LIFECYCLE_WEBHOOK_SECRET) is the only credential.
 *   - enforce():  the write gate. Context::assertAllowed() calls it AFTER the company check (Manage's
 *                 companyinfo for a person, the stream ticket, or the product's company binding), so a
 *                 stranger still gets 403. A company whose Voice subscription lapsed answers 423
 *                 COMPANY_RESTRICTED to every write not in ALLOW; reads always pass. Kit 1.1: a product
 *                 acting with its service key and no person (and the telephony provider's signed
 *                 webhooks) is a MACHINE caller and goes through checkMachine(): refused the same way
 *                 unless in MACHINE_ALLOW — the telephony webhook and the gateway's AI action on a live
 *                 call, which record or act on calls that are happening and cannot be replayed later.
 *   - legalHold(): kit 1.1 Kit option legal_hold. A recording under legal hold
 *                 (voice_recordings.legal_hold) HOLDS the company's purge — and the account purge of a
 *                 person who was the agent on such a call — until the hold is cleared: Manage is acked
 *                 `held` with the reason, the worker looks again daily, then purges and acks `purged`.
 *   - syncFromCompanyInfo(): Manage's companyinfo is the source of truth (`lifecycle`,
 *                 `restricted_until`), written through wherever this product reads it, so a missed
 *                 event is corrected on the next request.
 *   - kit():      the kit, on this product's own PDO (Db::connect() — the database name and user come
 *                 from Console when CONSOLE_DB_DETAILS_KEY is set) and its own .env reader. The cron
 *                 worker builds the same kit through config/lifecycle-kit.php.
 *
 * Purges are reviewed in config/lifecycle-manifest.php and run by the cron worker, never here.
 */
final class CompanyLifecycle
{
    public const APP_KEY = 'voice';

    /**
     * Writes that stay open while a company is read-only: they only read, render or export, or they are
     * the signed-in person's own UI state. "METHOD /path" with `*` = one segment (a route's {param}),
     * `**` = anything. tests/lifecycle.php proves every entry matches a real route.
     */
    public const ALLOW = [
        // Reads that are POSTs: search, validation, a ticket for the live events stream.
        'POST /api/v1/intelligence/search',
        'POST /api/v1/call-flows/validate',
        'POST /api/v1/campaigns/*/validate',
        'POST /api/v1/events/ticket',
        // The signed-in agent's own presence.
        'POST /api/v1/agents/presence',
    ];

    /**
     * Machine writes (a product's service key with no person, the telephony provider's webhook) that stay
     * open while the company is read-only (kit 1.1 checkMachine()): ONLY telephony / provider webhooks
     * that cannot be replayed later. tests/lifecycle.php proves each matches a real route.
     */
    public const MACHINE_ALLOW = [
        // The telephony provider reports what happened on a call (signed per connection).
        'POST /api/webhooks/telephony/*',
        // The Voice Gateway runs an AI agent's action step on the call it is holding, mid-call.
        'POST /api/v1/calls/*/ai-actions',
    ];

    private static ?Kit $kit = null;

    public static function kit(): Kit
    {
        return self::$kit ??= self::build();
    }

    /**
     * A kit on this product's PDO and .env. $options are the kit's (hooks, on_finish, http, clock) —
     * the tests pass a fake http; config/lifecycle-kit.php passes nothing.
     *
     * @param array<string, mixed> $options
     */
    public static function build(array $options = []): Kit
    {
        $autoload = dirname(__DIR__) . '/lib/company-lifecycle/autoload.php';
        if (!is_file($autoload)) {
            throw new \RuntimeException('The company-lifecycle kit is not deployed (lib/company-lifecycle).');
        }
        require_once $autoload;

        $env = static function (string $name): ?string {
            $value = Env::get($name);

            return $value !== '' ? $value : null;
        };
        // The stored recordings of purged rows (LifecycleFiles): deleted after the purge commits, retried.
        $options += ['hooks' => LifecycleFiles::hooks()];
        $options += ['log' => [self::class, 'log']];
        // Kit 1.1: a recording under legal hold stops the purge (ack `held`, looked at again daily).
        $options += ['legal_hold' => [self::class, 'legalHold']];

        return Kit::fromEnv(self::APP_KEY, Db::connect(), self::manifestPath(), $env, [], $options);
    }

    public static function manifestPath(): string
    {
        return dirname(__DIR__) . '/config/lifecycle-manifest.php';
    }

    /** Test seam: forget the kit, so the next call reads the environment again. */
    public static function resetForTesting(): void
    {
        self::$kit = null;
    }

    /** The receiver route. Answers and stops. */
    public static function receive(): never
    {
        try {
            $headers = function_exists('getallheaders') ? (getallheaders() ?: $_SERVER) : $_SERVER;
            [$status, $json] = self::kit()->receiver()->handle(Http::raw(), is_array($headers) ? $headers : $_SERVER);
        } catch (\Throwable $e) {
            // The database could not be reached: not recorded, and Manage retries a 503.
            self::log('error', 'event not recorded: ' . $e->getMessage());
            [$status, $json] = [503, ['status' => 'error', 'code' => 'event_not_recorded', 'message' => 'This application could not record the event. Retry later.']];
        }

        Http::json($status, $json);
    }

    /**
     * The write gate for one company: returns when the request may go ahead, answers 423 when the
     * company is read-only here. A lifecycle table that cannot be read lets the request through (the
     * kit logs it): a database fault must never read as "your subscription ended".
     */
    public static function enforce(int $cmpId, bool $machine = false): void
    {
        $refusal = self::refusal($cmpId, Http::method(), self::requestPath(), $machine);
        if ($refusal !== null) {
            Http::json($refusal[0], $refusal[1]);
        }
    }

    /** The machine-caller gate (kit 1.1): a product's service key with no person, a provider webhook. */
    public static function enforceMachine(int $cmpId): void
    {
        self::enforce($cmpId, true);
    }

    /**
     * @param bool $machine a machine caller (kit 1.1 checkMachine(): MACHINE_ALLOW also stays open)
     * @return array{0:int, 1:array<string,mixed>}|null
     */
    public static function refusal(int $cmpId, string $method, string $path, bool $machine = false): ?array
    {
        if (in_array(strtoupper($method), ['GET', 'HEAD', 'OPTIONS'], true)) {
            return null;
        }
        try {
            $guard = self::kit()->guard(self::ALLOW, self::MACHINE_ALLOW);

            return $machine ? $guard->checkMachine($cmpId, $method, $path) : $guard->check($cmpId, $method, $path);
        } catch (\Throwable $e) {
            self::log('warning', 'write gate skipped for cmp=' . $cmpId . ': ' . $e->getMessage());

            return null;
        }
    }

    /**
     * The company's lifecycle as this API enforces it, for the SPA: {lifecycle, restricted_until}.
     * "active" when there is no state row or it cannot be read.
     *
     * @return array{lifecycle: string, restricted_until: ?string}
     */
    public static function lifecycle(int $cmpId): array
    {
        try {
            $row = self::kit()->store()->state($cmpId);
        } catch (\Throwable $e) {
            self::log('warning', 'lifecycle state unreadable for cmp=' . $cmpId . ': ' . $e->getMessage());
            $row = null;
        }

        return ['lifecycle' => (string) ($row['state'] ?? 'active'), 'restricted_until' => $row['restricted_until'] ?? null];
    }

    /**
     * Write Manage's companyinfo answer through. $result is ApiClient's {ok, status, body}; only a 2xx
     * that names this company is believed. Never throws: companyinfo must work whatever happens here.
     *
     * @param array<string, mixed> $result
     */
    public static function syncFromCompanyInfo(int $cmpId, array $result): void
    {
        $status = (int) ($result['status'] ?? 0);
        $body = $result['body'] ?? null;
        if ($cmpId <= 0 || $status < 200 || $status >= 300 || !is_array($body)) {
            return;
        }
        $data = is_array($body['data'] ?? null) && !array_is_list($body['data']) ? $body['data'] : $body;
        $named = null;
        foreach (['comp_id', 'cmp_id', 'company_id'] as $key) {
            if (isset($data[$key]) && is_scalar($data[$key])) {
                $named = trim((string) $data[$key]);
                break;
            }
        }
        if ($named !== (string) $cmpId || !isset($data['lifecycle'])) {
            return;
        }
        try {
            self::kit()->guard()->syncFromCompanyInfo($cmpId, $data);
        } catch (\Throwable $e) {
            self::log('warning', 'companyinfo lifecycle not recorded for cmp=' . $cmpId . ': ' . $e->getMessage());
        }
    }

    /**
     * Kit 1.1 legal hold (the Kit option legal_hold): a reason while a hold exists, else null.
     *
     *   company  any recording of the company under legal hold (voice_recordings.legal_hold, not yet
     *            deleted) — the purge would delete it, its transcript and its call;
     *   account  any such recording of a call the person took part in as an agent — the hold covers the
     *            conversation they were party to.
     *
     * A hold is set and cleared inside Voice (the retention sweeper already respects it); this only reads
     * it. A database fault is not a hold: it throws, and the kit retries the attempt.
     */
    public static function legalHold(string $scope, int|string $id): ?string
    {
        if ($scope === 'company') {
            $n = (int) Db::scalar('SELECT COUNT(*) FROM voice_recordings WHERE cmp_id = :c AND legal_hold = TRUE AND deleted_at IS NULL', ['c' => (int) $id]);

            return $n > 0
                ? sprintf('%d call recording(s) of this company are under a legal hold in Voice (voice_recordings.legal_hold); the purge waits until the hold is cleared.', $n)
                : null;
        }
        $n = (int) Db::scalar(
            'SELECT COUNT(DISTINCT r.recording_id) FROM voice_recordings r
               JOIN voice_call_participants p ON p.call_id = r.call_id
               JOIN voice_agents a ON a.agent_id = p.agent_id
              WHERE a.user_uuid = :u AND r.legal_hold = TRUE AND r.deleted_at IS NULL',
            ['u' => (string) $id],
        );

        return $n > 0
            ? sprintf('%d call recording(s) of calls this person took part in as an agent are under a legal hold in Voice (voice_recordings.legal_hold); the account purge waits until the hold is cleared.', $n)
            : null;
    }

    /** The kit's log lines and this class's, in this product's log. */
    public static function log(string $level, string $message): void
    {
        error_log('[' . self::APP_KEY . '-lifecycle] ' . $level . ': ' . $message);
    }

    /** "/api/v1/…/{id}/…": the matched route, so allow-list entries are written against routes. */
    private static function requestPath(): string
    {
        $route = Router::matchedRoute();
        if ($route !== '' && ($space = strpos($route, ' ')) !== false) {
            return '/api/' . ltrim(substr($route, $space + 1), '/');
        }
        $uri = (string) (parse_url((string) ($_SERVER['REQUEST_URI'] ?? '/'), PHP_URL_PATH) ?: '/');

        return '/' . ltrim($uri, '/');
    }
}
