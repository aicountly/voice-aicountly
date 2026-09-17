<?php

declare(strict_types=1);

/**
 * Voice API — front controller.
 *
 * Deployed to <document root>/api, so it is same-origin with the React app on
 * both voice.aicountly.com and voice.gh.aicountly.com.
 *
 * Two surfaces live here:
 *
 *   /api/global/{path}   the allow-listed relay to the portal auth API, which
 *                        predates the rest of this product and is unchanged.
 *   /api/...             the Voice API proper — see src/Routes.php for the
 *                        whole surface.
 *
 * Everything under /v1 requires a session and a company scope. The only
 * exceptions are /health and the signed telephony callbacks, both of which say
 * so in the route table.
 */

namespace Aicountly\Api;

require __DIR__ . '/src/Autoload.php';

Env::load(__DIR__ . '/.env');

/**
 * Portal paths this API relays for the browser.
 *
 * The relay exists so the SPA never makes a cross-origin call to the portal:
 * a new product domain is not in the portal's CORS allowlist on day one.
 *
 * It is an allowlist and must stay one. Forwarding arbitrary paths would turn
 * this host into an open proxy for the portal's whole auth surface — login,
 * signup, OTP, user lookups — with the portal seeing this server's IP instead
 * of the caller's, so anything it rate-limits per IP could be driven through
 * here instead.
 */
const RELAYED_PATHS = [
    'seskey',
    'seskey/refresh',
    'refresh_authtoken',
];

// ---------------------------------------------------------------------------
// Helpers
// ---------------------------------------------------------------------------

/**
 * The Authorization header, wherever this server happens to expose it.
 *
 * Under CGI/FastCGI Apache does not pass it to PHP unless it is copied
 * explicitly, and after an internal rewrite it arrives only under the
 * REDIRECT_ prefix. Reading just one of these is why an otherwise correct
 * deployment answers 401 to every sign-in.
 */
function authorization_header(): string
{
    $candidates = [
        $_SERVER['HTTP_AUTHORIZATION'] ?? '',
        $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ?? '',
    ];

    if (function_exists('apache_request_headers')) {
        foreach ((array) apache_request_headers() as $name => $value) {
            if (strcasecmp((string) $name, 'Authorization') === 0) {
                $candidates[] = (string) $value;
                break;
            }
        }
    }

    foreach ($candidates as $candidate) {
        if (is_string($candidate) && $candidate !== '') {
            return $candidate;
        }
    }

    return '';
}

/**
 * Collapse a routed path to the exact form RELAYED_PATHS is written in.
 *
 * Percent-escapes are decoded first so `%2e%2e` cannot smuggle a traversal
 * segment past the allowlist; exact matching does the rest.
 */
function normalise_path(string $path): string
{
    $decoded = str_replace('\\', '/', rawurldecode($path));
    $segments = array_values(array_filter(explode('/', $decoded), static fn ($s) => $s !== ''));

    return strtolower(implode('/', $segments));
}

/**
 * CORS for local development only.
 *
 * In both deployed environments the app and this API share an origin, so no
 * CORS headers are needed or sent. CORS_ALLOWED_ORIGINS in the server .env is
 * what lets `npm run dev` on localhost talk to a deployed API.
 */
function apply_cors(): void
{
    $origin = $_SERVER['HTTP_ORIGIN'] ?? '';
    if ($origin === '') {
        return;
    }

    $allowed = array_filter(array_map('trim', explode(',', Env::get('CORS_ALLOWED_ORIGINS'))));
    if (!in_array($origin, $allowed, true)) {
        return;
    }

    header('Access-Control-Allow-Origin: ' . $origin);
    header('Access-Control-Allow-Headers: Authorization, Content-Type, Idempotency-Key, X-Service-Key, X-Actor-Uuid, X-Correlation-Id, Last-Event-ID');
    header('Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS');
    header('Access-Control-Max-Age: 600');
    header('Vary: Origin');
}

// ---------------------------------------------------------------------------
// Routing
// ---------------------------------------------------------------------------

apply_cors();

$method = Http::method();

if ($method === 'OPTIONS') {
    http_response_code(204);
    exit;
}

$uri = (string) (parse_url((string) ($_SERVER['REQUEST_URI'] ?? '/'), PHP_URL_PATH) ?: '/');

// Strip the directory this front controller is mounted under, so the same file
// works at <docroot>/api and at the root of a dedicated API vhost.
$mountPoint = rtrim(str_replace('\\', '/', dirname((string) ($_SERVER['SCRIPT_NAME'] ?? '/index.php'))), '/');
if ($mountPoint !== '' && $mountPoint !== '/' && strpos($uri, $mountPoint) === 0) {
    $uri = substr($uri, strlen($mountPoint));
}

$path = normalise_path($uri);

// The portal auth relay, unchanged from before this product had an API.
if (strpos($path, 'global/') === 0) {
    $portalPath = substr($path, strlen('global/'));

    if (!in_array($portalPath, RELAYED_PATHS, true)) {
        Http::notFound('This path is not relayed. Call the portal API directly.');
    }

    $headers = [];
    $authorization = authorization_header();
    if ($authorization !== '') {
        $headers[] = 'Authorization: ' . $authorization;
    }
    $contentType = $_SERVER['CONTENT_TYPE'] ?? '';
    if (is_string($contentType) && $contentType !== '') {
        $headers[] = 'Content-Type: ' . $contentType;
    }

    $body = (string) file_get_contents('php://input');
    $result = Portal::forward($method, $portalPath, $headers, $body);

    if ($result['status'] === 504) {
        Http::error(504, 'auth_unavailable', 'Auth service unavailable — please retry.');
    }

    http_response_code($result['status']);
    header('Content-Type: ' . $result['contentType']);
    header('Cache-Control: no-store');
    echo $result['body'];
    exit;
}

// Who the caller is, per the portal. Predates /v1 and is kept for the SPA's
// boot sequence.
if ($path === 'session') {
    $header = authorization_header();
    $sesKey = preg_match('/Bearer\s+(.+)/i', $header, $matches) === 1 ? trim($matches[1]) : '';
    if ($sesKey === '') {
        Http::unauthorized('Missing bearer session key.');
    }

    $session = Portal::validateSesKey($sesKey);
    if ($session === null) {
        Http::unauthorized('Invalid or expired session.');
    }

    Http::data([
        'authenticated' => true,
        'uuid' => $session['uuid_aictly'] ?? ($session['uuid'] ?? ''),
    ]);
}

$router = new Router();
Routes::register($router);

try {
    if (!$router->dispatch($method, $path)) {
        Http::notFound();
    }
} catch (ResponseSent $sent) {
    // Only reachable under CLI, where Http throws instead of exiting.
    http_response_code($sent->status);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($sent->payload, JSON_UNESCAPED_SLASHES);
    exit;
} catch (\PDOException $e) {
    // A DSN or a bound parameter can appear in a PDO message, and a bound
    // parameter here can be a phone number.
    error_log('[voice] database error: ' . $e->getMessage());
    Http::error(503, 'database_unavailable', 'The service is temporarily unavailable. Please retry.');
} catch (\Throwable $e) {
    error_log('[voice] unhandled: ' . $e::class . ' ' . $e->getMessage() . ' at ' . $e->getFile() . ':' . $e->getLine());
    Http::error(500, 'internal_error', 'Something went wrong handling that request.');
}
