<?php

declare(strict_types=1);

/**
 * E8-ID / F155 — Voice's MessagingClient against the REAL Messaging service API.
 *
 * Messaging (messaging-aicountly/server-php) is migrated with its own runner and served by its own
 * front controller (index.php under `php -S`) twice: one instance where no company has allowed
 * Voice, one with SERVICE_KEY_COMPANIES=voice:<company> (what a company's "Products that may send"
 * setting does). Voice's real client calls them over HTTP. Nothing answers for Messaging here.
 *
 *   before E8-ID   POST /api/messages            -> 404 (Messaging has no such route)
 *                  no X-AIC-Environment          -> 401 service_environment_mismatch
 *   now            POST /api/v1/messages + env   -> 403 service_company_not_bound until the company
 *                                                   allows Voice, then Messaging's own send rules
 *                  wrong environment             -> 401 service_environment_mismatch
 *                  no environment configured     -> refused here, nothing sent
 *
 *   E8ID_MESSAGING_SERVER_PHP=<messaging-aicountly/server-php> \
 *   E8ID_MSG_DB_NAME=… E8ID_MSG_DB_USER=… E8ID_MSG_DB_PASS=… php server-php/tests/e8id_messaging_contract.php
 *
 * Without E8ID_MESSAGING_SERVER_PHP it says so and exits 0 (a Voice-only checkout stays runnable).
 */

namespace Aicountly\Api;

use Aicountly\Api\Clients\MessagingClient;

require __DIR__ . '/../src/Env.php';
require __DIR__ . '/../src/Autoload.php';

$msgDir = rtrim((string) getenv('E8ID_MESSAGING_SERVER_PHP'), '/');
if ($msgDir === '' || !is_file($msgDir . '/index.php') || !is_file($msgDir . '/bin/migrate.php')) {
    echo "e8id_messaging_contract: SKIPPED (set E8ID_MESSAGING_SERVER_PHP to a messaging-aicountly/server-php checkout)\n";
    exit(0);
}
if (is_file($msgDir . '/.env')) {
    fwrite(STDERR, "Refusing: {$msgDir}/.env would override this test's configuration.\n");
    exit(2);
}

$key = 'e8id-voice-to-messaging-key-0123456789';
$company = 7741;
$portBase = (int) (getenv('E8ID_MSG_PORT_BASE') ?: 33970);
$msgEnv = [
    'PATH'               => (string) getenv('PATH'),
    'APP_ENV'            => 'local',
    'APP_PRODUCT_KEY'    => 'messaging',
    'DB_HOST'            => (string) (getenv('E8ID_MSG_DB_HOST') ?: '127.0.0.1'),
    'DB_PORT'            => (string) (getenv('E8ID_MSG_DB_PORT') ?: '5432'),
    'DB_NAME'            => (string) getenv('E8ID_MSG_DB_NAME'),
    'DB_USER'            => (string) getenv('E8ID_MSG_DB_USER'),
    'DB_PASS'            => (string) getenv('E8ID_MSG_DB_PASS'),
    // Nothing on these answers: a service call with no person never asks the portal or Manage.
    'PORTAL_AUTH_BASE'   => 'http://127.0.0.1:9',
    'MANAGE_API_BASE'    => 'http://127.0.0.1:9',
    'PULSE_API_ORIGIN'   => 'http://127.0.0.1:9',
    'SERVICE_KEYS'       => 'voice:' . $key,
];

$migrate = proc_open([PHP_BINARY, $msgDir . '/bin/migrate.php'], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, $msgDir, $msgEnv);
$migrateOut = stream_get_contents($pipes[1]) . stream_get_contents($pipes[2]);
if (proc_close($migrate) !== 0) {
    fwrite(STDERR, "Messaging migrate failed:\n{$migrateOut}\n");
    exit(1);
}

$procs = [];
$serve = static function (int $port, array $env) use (&$procs, $msgDir): string {
    $procs[] = proc_open(['setsid', PHP_BINARY, '-S', '127.0.0.1:' . $port, '-t', $msgDir, $msgDir . '/index.php'], [0 => ['file', '/dev/null', 'r'], 1 => ['file', '/dev/null', 'w'], 2 => ['file', '/dev/null', 'w']], $p, $msgDir, $env);
    for ($i = 0; $i < 100; $i++) {
        $fp = @fsockopen('127.0.0.1', $port, $e, $s, 0.1);
        if ($fp) {
            fclose($fp);
            break;
        }
        usleep(50000);
    }

    return 'http://127.0.0.1:' . $port;
};
$unbound = $serve($portBase, $msgEnv);
$bound = $serve($portBase + 1, $msgEnv + ['SERVICE_KEY_COMPANIES' => 'voice:' . $company]);

$passed = 0;
$failed = 0;
$check = static function (string $name, bool $ok, mixed $detail = null) use (&$passed, &$failed): void {
    echo ($ok ? '  ok    ' : '  FAIL  ') . $name . ($ok || $detail === null ? '' : "\n        " . json_encode($detail)) . "\n";
    $ok ? $passed++ : $failed++;
};
$code = static fn (array $r): string => (string) ($r['body']['error']['code'] ?? '');
$client = static function (string $base, ?string $environment) use ($key): MessagingClient {
    putenv('MESSAGING_API_BASE=' . $base);
    putenv('MESSAGING_SERVICE_KEY=' . $key);
    putenv('VOICE_MESSAGING_ENABLED=1');
    putenv($environment === null ? 'AIC_ENVIRONMENT' : 'AIC_ENVIRONMENT=' . $environment);
    putenv('APP_ENV');

    return (new MessagingClient())->forActor('');
};
$payload = ['cmp_id' => $company, 'channel' => 'sms', 'to' => '+919800000001', 'template' => 'e8id_call_follow_up', 'variables' => ['name' => 'Asha'], 'purpose' => 'transactional'];

try {
    // What the old client sent: its path is not Messaging's.
    $legacy = $client($bound, 'local')->request('POST', 'messages', $payload, ['X-Service-Key' => $key, 'X-AIC-Environment' => 'local', 'Idempotency-Key' => 'e8id-legacy-1']);
    $check('the old path (POST /api/messages) is not a Messaging route: 404', $legacy['status'] === 404, $legacy);
    $noEnv = $client($bound, 'local')->request('POST', 'v1/messages', $payload, ['X-Service-Key' => $key, 'Idempotency-Key' => 'e8id-legacy-2']);
    $check('and without X-AIC-Environment Messaging answers 401 service_environment_mismatch', $noEnv['status'] === 401 && $code($noEnv) === 'service_environment_mismatch', $noEnv);

    $r = $client($unbound, 'local')->send($payload, 'e8id-send-unbound-1');
    $check('a company that has not allowed Voice: 403 service_company_not_bound (path, key and environment accepted)', $r['status'] === 403 && $code($r) === 'service_company_not_bound', $r);

    $r = $client($bound, 'local')->send($payload, 'e8id-send-bound-1');
    $check('once the company allows Voice, the send reaches Messaging\'s own rules (no auth/route refusal): ' . $r['status'] . ' ' . $code($r),
        $r['status'] !== 0 && !in_array($r['status'], [401, 404], true) && !in_array($code($r), ['service_company_not_bound', 'service_environment_mismatch', 'service_route_not_allowed'], true), $r);

    $r = $client($bound, 'production')->send($payload, 'e8id-send-prod-1');
    $check('a Voice configured for production is refused by a local Messaging: 401 service_environment_mismatch', $r['status'] === 401 && $code($r) === 'service_environment_mismatch', $r);

    $r = $client($bound, null)->send($payload, 'e8id-send-noenv-1');
    $check('with no environment configured, Voice sends nothing', $r['status'] === 0 && $r['error'] === 'environment_not_configured', $r);

    $r = $client($bound, 'local')->message('00000000-0000-4000-8000-000000000000', $company);
    $check('a status read is a Messaging route (an unknown message is Messaging\'s 404, not a route miss): ' . $r['status'] . ' ' . $code($r), $r['status'] === 404 && $code($r) !== '', $r);
} finally {
    foreach ($procs as $p) {
        $st = proc_get_status($p);
        if (($st['pid'] ?? 0) > 0) {
            @posix_kill(-(int) $st['pid'], SIGTERM);
        }
        proc_close($p);
    }
}

echo "\n{$passed} passed, {$failed} failed\n";
exit($failed === 0 ? 0 : 1);
