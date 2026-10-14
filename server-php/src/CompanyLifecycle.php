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
 *                 COMPANY_RESTRICTED to every write not in ALLOW; reads always pass. The telephony
 *                 provider's signed webhooks are not gated: they record calls that already happened.
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
    public static function enforce(int $cmpId): void
    {
        $refusal = self::refusal($cmpId, Http::method(), self::requestPath());
        if ($refusal !== null) {
            Http::json($refusal[0], $refusal[1]);
        }
    }

    /** @return array{0:int, 1:array<string,mixed>}|null */
    public static function refusal(int $cmpId, string $method, string $path): ?array
    {
        if (in_array(strtoupper($method), ['GET', 'HEAD', 'OPTIONS'], true)) {
            return null;
        }
        try {
            return self::kit()->guard(self::ALLOW)->check($cmpId, $method, $path);
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
