<?php

declare(strict_types=1);

namespace Aicountly\Api;

/**
 * What another product's service key may do here (G18#31, G18#12; D2(b)).
 *
 * A service key used to be a superuser: any route, any company named in the
 * query, every permission, and an X-Actor-Uuid nobody checked. The interim
 * standard every product's own service keys now follow:
 *
 *   ROUTE ALLOW-LIST  each product may call only the routes listed for it
 *                     below, and holds only the permissions listed per route.
 *                     Anything else is 403 service_route_not_allowed.
 *   COMPANY BINDING   the company must be one the acting person is a member of
 *                     (their own session, forwarded as the Bearer and asked of
 *                     Manage) — or, for a product acting with no person, one
 *                     listed for that product in SERVICE_KEY_COMPANIES. Never
 *                     "any company the request names".
 *   VERIFIED ACTOR    a person is named only by their own session. A bare
 *                     X-Actor-Uuid is a claim: it is recorded as such and never
 *                     acted on. Bearer + X-Actor-Uuid that disagree are refused.
 *   ENVIRONMENT       the caller sends X-AIC-Environment and it must equal this
 *                     server's configured environment; with none configured,
 *                     no service key is accepted.
 */
final class ServicePolicy
{
    /**
     * product => list of [method, route pattern, permissions held on that route]
     *
     * @var array<string, list<array{0: string, 1: string, 2: list<string>}>>
     */
    public const ROUTES = [
        // The receptionist books a callback for a visitor at the desk.
        'lobby' => [
            ['POST', '/v1/callbacks', ['voice.callbacks.manage']],
        ],
        // Click-to-call and call history from a customer record. (CRM calls
        // with the person's own session today; this is what its key may do.)
        'crm' => [
            ['GET', '/v1/calls', ['voice.call.view']],
            ['POST', '/v1/calls', ['voice.call.view', 'voice.call.place']],
        ],
    ];

    /** The route this request is, normalised to the route table's form (e.g. /v1/calls/{id}). */
    public static function currentRoute(): array
    {
        $path = (string) (parse_url((string) ($_SERVER['REQUEST_URI'] ?? '/'), PHP_URL_PATH) ?: '/');
        $at = strpos($path, '/v1/');
        $path = $at === false ? $path : substr($path, $at);

        return [Http::method(), '/' . trim($path, '/')];
    }

    /**
     * The permissions a product holds on the route being served, or null when
     * the route is not allowed for it at all.
     *
     * @return list<string>|null
     */
    public static function grants(string $product, ?array $route = null): ?array
    {
        [$method, $path] = $route ?? self::currentRoute();
        foreach (self::ROUTES[strtolower($product)] ?? [] as [$allowedMethod, $pattern, $permissions]) {
            if ($allowedMethod === $method && self::matches($pattern, $path)) {
                return $permissions;
            }
        }

        return null;
    }

    /** Is this company bound to the product for actor-less calls? Explicit ids only. */
    public static function companyBound(string $product, int $cmpId): bool
    {
        $raw = Env::get('SERVICE_KEY_COMPANIES');
        foreach (explode(',', $raw) as $entry) {
            if (!str_contains($entry, ':')) {
                continue;
            }
            [$app, $list] = explode(':', $entry, 2);
            if (strtolower(trim($app)) !== strtolower($product)) {
                continue;
            }
            foreach (explode('|', $list) as $id) {
                if (ctype_digit(trim($id)) && (int) trim($id) === $cmpId) {
                    return true;
                }
            }
        }

        return false;
    }

    /** The environment a service call says it is for must be ours. */
    public static function environmentMatches(string $declared): bool
    {
        $mine = Environment::current();
        $theirs = Environment::normalise($declared);

        return $mine !== null && $theirs !== null && $mine === $theirs;
    }

    private static function matches(string $pattern, string $path): bool
    {
        $want = explode('/', trim($pattern, '/'));
        $got = explode('/', trim($path, '/'));
        if (count($want) !== count($got)) {
            return false;
        }
        foreach ($want as $i => $segment) {
            if (str_starts_with($segment, '{') && str_ends_with($segment, '}')) {
                continue;
            }
            if ($segment !== $got[$i]) {
                return false;
            }
        }

        return true;
    }
}
