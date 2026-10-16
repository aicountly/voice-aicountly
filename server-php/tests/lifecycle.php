<?php

declare(strict_types=1);

/**
 * Manage's company lifecycle in Voice (lib/company-lifecycle, docs/COMPANY_LIFECYCLE.md).
 *
 *   tests/run.sh runs it after the integration suite.
 *
 * The reviewed manifest has no problems for the company and user scopes on the migrated schema (strict
 * mode — a migration that adds a company table without listing it fails here), every allow-list entry
 * is a real route, the receiver takes no session, the write gate answers 423 after the company check
 * (people and products), companyinfo is written through, and a signed purge runs end to end through
 * the worker: the calls, recordings and transcripts go, the stored recordings are deleted after the
 * commit — or, held behind RECORDING_STORAGE_URL where this server cannot delete them, stay pending
 * for ops — and the person's own saved searches go on an account purge.
 */

namespace Aicountly\Api\Tests;

use Aicountly\Api\Auth;
use Aicountly\Api\CompanyLifecycle;
use Aicountly\Api\Context;
use Aicountly\Api\Db;
use Aicountly\Api\Env;
use Aicountly\Api\Http;
use Aicountly\Api\LifecycleFiles;
use Aicountly\Api\ResponseSent;
use Aicountly\Api\Router;
use Aicountly\Api\Routes;
use Aicountly\Lifecycle\PurgeEngine;
use Aicountly\Lifecycle\PurgeManifest;
use Aicountly\Lifecycle\Signature;

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require __DIR__ . '/../src/Autoload.php';
require __DIR__ . '/../lib/company-lifecycle/autoload.php';
require __DIR__ . '/support.php';

Env::load(__DIR__ . '/../.env');

const LC_SECRET = 'test-lifecycle-secret-0123456789abcdef0123';
const LC_CMP = 4301;
const LC_PURGE = 4302;

putenv('LIFECYCLE_WEBHOOK_SECRET=' . LC_SECRET);
putenv('MANAGE_API_URL=https://manage.test.invalid');
putenv('MANAGE_PRODUCT_SERVICE_KEY');

$pdo = Db::connect();
$cleanup = static function () use ($pdo): void {
    $pdo->exec('DELETE FROM aic_lifecycle_acks; DELETE FROM aic_lifecycle_jobs; DELETE FROM aic_lifecycle_events; DELETE FROM aic_lifecycle_state; DELETE FROM aic_lifecycle_expiries; DELETE FROM ' . LifecycleFiles::TABLE);
    foreach ($pdo->query("SELECT nspname FROM pg_namespace WHERE nspname LIKE 'aic\\_lifecycle\\_archive\\_%'")->fetchAll(\PDO::FETCH_COLUMN) as $s) {
        $pdo->exec('DROP SCHEMA "' . $s . '" CASCADE');
    }
};
$cleanup();
truncateAll();

/** @return array{0:int, 1:array<string,mixed>} */
function receive(string $body, bool $sign = true): array
{
    $ts = time();
    clearHeaders();
    $r = request('POST', 'internal/manage/company-lifecycle', [], [], $sign ? ['X-Manage-Timestamp' => (string) $ts, 'X-Manage-Signature' => Signature::sign(LC_SECRET, $ts, $body)] : []);
    // request() sets the parsed body; the receiver reads the raw bytes.
    return [$r['status'], $r['body']];
}

function lcEvent(string $event, array $extra = []): string
{
    static $n = 0;
    $n++;

    return (string) json_encode(['event_id' => sprintf('%08x-0000-4000-8000-%012d', random_int(0, 0x7fffffff), $n), 'event' => $event,
        'app_key' => 'voice', 'occurred_at' => gmdate('Y-m-d\TH:i:s\Z', time() - 1000 + $n)] + $extra);
}

/** @return array{0:int, 1:array<string,mixed>} */
function lcReceive(string $body, bool $sign = true): array
{
    Http::setRawForTesting($body);
    try {
        return receive($body, $sign);
    } finally {
        Http::setRawForTesting(null);
    }
}

function lcState(int $cmpId): ?string
{
    $v = Db::scalar('SELECT state FROM aic_lifecycle_state WHERE target_cmp_id = :c', ['c' => $cmpId]);

    return $v === null || $v === false ? null : (string) $v;
}

/** @param list<array<string,mixed>> $acks */
function lcRun(array &$acks): array
{
    return CompanyLifecycle::build(['http' => static function (string $m, string $url, array $h, ?string $b) use (&$acks): array {
        $acks[] = json_decode((string) $b, true);

        return ['status' => 200, 'body' => '{}', 'error' => null];
    }])->worker()->run();
}

echo "Voice company lifecycle\n" . str_repeat('=', 62) . "\n";

T::group('the reviewed manifest');
$manifest = PurgeManifest::load(CompanyLifecycle::manifestPath());
T::same('voice', $manifest->appKey(), 'loads, is not a draft and names this app');
$engine = new PurgeEngine($pdo, $manifest);
T::same([], $engine->problems('company'), 'no problems on the migrated schema (company scope, strict)');
T::same([], $engine->problems('user'), 'no problems on the migrated schema (user scope, strict)');
T::same([], $manifest->warnings(), 'kit 1.1: nothing retained, so no period is missing');
T::same(hash_file('sha256', __DIR__ . '/../lib/company-lifecycle/schema-1.1.sql'), hash_file('sha256', __DIR__ . '/../database/migrations/016_aic_company_lifecycle_1_1.sql'), 'migration 016 is the kit\'s schema-1.1.sql verbatim');
T::ok((bool) Db::scalar("SELECT to_regclass('aic_lifecycle_expiries') IS NOT NULL"), 'aic_lifecycle_expiries exists');

T::group('the allow-list');
$router = new Router();
Routes::register($router);
$routes = $router->patterns();
T::ok(in_array('POST internal/manage/company-lifecycle', $routes, true), 'the receiver route is registered');
foreach (CompanyLifecycle::ALLOW as $entry) {
    [$method, $glob] = preg_split('/\s+/', $entry, 2);
    $re = '#^' . str_replace(['\*\*', '\*'], ['.*', '[^/]+'], preg_quote(ltrim($glob, '/'), '#')) . '$#';
    $hit = array_filter($routes, static function (string $r) use ($method, $re): bool {
        [$m, $p] = explode(' ', $r, 2);

        return ($method === '*' || $m === $method) && preg_match($re, 'api/' . $p) === 1;
    });
    T::ok($hit !== [], $entry . ' matches a real route');
}
foreach (CompanyLifecycle::MACHINE_ALLOW as $entry) {
    [$method, $glob] = preg_split('/\s+/', $entry, 2);
    $re = '#^' . str_replace(['\*\*', '\*'], ['.*', '[^/]+'], preg_quote(ltrim($glob, '/'), '#')) . '$#';
    $hit = array_filter($routes, static function (string $r) use ($method, $re): bool {
        [$m, $p] = explode(' ', $r, 2);

        return $m === $method && preg_match($re, 'api/' . ltrim($p, '/')) === 1;
    });
    T::ok($hit !== [], 'machine allow-list: ' . $entry . ' matches a real route');
}

T::group('the receiver (no session: the HMAC is the only credential)');
Auth::adopt(null);
T::same(401, lcReceive(lcEvent('company_restricted', ['cmp_id' => LC_CMP]), false)[0], 'unsigned → 401');
$other = (string) json_encode(['app_key' => 'books'] + json_decode(lcEvent('company_restricted', ['cmp_id' => LC_CMP]), true));
T::same(422, lcReceive($other)[0], 'another app → 422');
[$status, $body] = lcReceive(lcEvent('company_restricted', ['cmp_id' => LC_CMP, 'reason' => 'subscription_lapsed', 'restricted_until' => gmdate('Y-m-d\TH:i:s\Z', time() + 45 * 86400)]));
T::same(202, $status, 'signed company_restricted → 202');
T::same('applied', $body['outcome'] ?? null, 'applied');

T::group('the write gate');
$owner = markOwner(Auth::forTesting('7301'));
Auth::adopt($owner);
scope(LC_CMP, $owner);
clearHeaders();
$r = request('POST', 'v1/settings', ['cmp_id' => LC_CMP], ['recording_enabled' => false]);
T::same(423, $r['status'], 'a write answers 423');
T::same('COMPANY_RESTRICTED', $r['body']['code'] ?? null, 'code COMPANY_RESTRICTED');
T::same('COMPANY_RESTRICTED', $r['body']['error']['code'] ?? null, 'error.code (the SPA reads it here)');
T::ok(is_string($r['body']['restricted_until'] ?? null), 'restricted_until is given');
T::ok(request('GET', 'v1/settings', ['cmp_id' => LC_CMP])['status'] !== 423, 'a read passes');
T::ok(request('POST', 'v1/intelligence/search', ['cmp_id' => LC_CMP], ['q' => 'x'])['status'] !== 423, 'search (a read that is a POST) passes');
$service = Auth::forTesting('svc', 'service', 'appointments');
Context::trustForTesting(LC_CMP, $service);
try {
    Context::forCompany(LC_CMP)->assertAllowed($service);
    T::ok(false, 'a product writing into a read-only company is refused');
} catch (ResponseSent $e) {
    T::ok(in_array($e->status, [403, 423], true), 'a product writing into a read-only company is refused (' . $e->status . ')');
}
Context::resetForTesting();
// Kit 1.1 machine gating: a product with no person is refused like a person, except the telephony webhook
// and the gateway's AI action on a live call (MACHINE_ALLOW).
T::same(423, CompanyLifecycle::refusal(LC_CMP, 'POST', '/api/v1/callbacks', true)[0] ?? null, 'a machine write (lobby booking a callback) answers 423');
T::same(null, CompanyLifecycle::refusal(LC_CMP, 'POST', '/api/webhooks/telephony/{connectionId}', true), 'the telephony webhook is machine-allowed');
T::same(null, CompanyLifecycle::refusal(LC_CMP, 'POST', '/api/v1/calls/{id}/ai-actions', true), 'the gateway\'s AI action on a live call is machine-allowed');
T::same(423, CompanyLifecycle::refusal(LC_CMP, 'POST', '/api/v1/calls/{id}/ai-actions')[0] ?? null, 'the same route for a person is not');
$stranger = Auth::forTesting('7399');
Auth::adopt($stranger);
// 999 is the company the Manage stub refuses (tests/stub/router.php); read-only here too.
$pdo->exec("INSERT INTO aic_lifecycle_state (target_cmp_id, state) VALUES (999, 'restricted') ON CONFLICT (target_cmp_id) DO UPDATE SET state = 'restricted'");
T::ok(in_array(request('POST', 'v1/settings', ['cmp_id' => 999], ['recording_enabled' => false])['status'], [401, 403, 503], true), 'a caller who may not open the company is refused by the company check, never 423');
$pdo->exec('DELETE FROM aic_lifecycle_state WHERE target_cmp_id = 999');

T::group('companyinfo is the source of truth');
T::same(202, lcReceive(lcEvent('company_unrestricted', ['cmp_id' => LC_CMP, 'reason' => 'subscription_renewed']))[0], 'company_unrestricted → 202');
T::same('active', lcState(LC_CMP), 'lifted');
CompanyLifecycle::syncFromCompanyInfo(LC_CMP, ['ok' => true, 'status' => 200, 'body' => ['data' => ['comp_id' => LC_CMP, 'lifecycle' => 'purge_pending']]]);
T::same('purge_pending', lcState(LC_CMP), 'companyinfo purge_pending → purge_pending');
CompanyLifecycle::syncFromCompanyInfo(LC_CMP, ['ok' => true, 'status' => 200, 'body' => ['data' => ['comp_id' => LC_CMP, 'lifecycle' => 'active']]]);
T::same('active', lcState(LC_CMP), 'companyinfo active → active');

T::group('a signed purge, end to end');
$root = sys_get_temp_dir() . '/voice-lifecycle-' . getmypid();
@mkdir($root . '/rec', 0750, true);
file_put_contents($root . '/rec/a.wav', 'audio');
putenv('RECORDING_STORAGE_PATH=' . $root);
$p = LC_PURGE;
$pdo->exec("INSERT INTO voice_settings (cmp_id) VALUES ({$p})");
$callId = (int) Db::scalar("INSERT INTO voice_calls (call_uuid, cmp_id, direction) VALUES ('lc-call-1', {$p}, 'inbound') RETURNING call_id");
$pdo->exec("INSERT INTO voice_recordings (recording_uuid, call_id, cmp_id, kind, storage_key, status, legal_hold) VALUES ('00000000-0000-4000-8000-00000000a001', {$callId}, {$p}, 'call', 'rec/a.wav', 'available', TRUE)");
$pdo->exec("INSERT INTO voice_saved_searches (cmp_id, user_uuid, name) VALUES ({$p}, '7301', 's')");
[$status, $body] = lcReceive(lcEvent('company_purge_requested', ['cmp_id' => LC_PURGE, 'reason' => 'subscription_lapsed']));
T::same('queued', $body['outcome'] ?? null, 'the purge request is queued');
// Kit 1.1 legal hold: a recording under hold HOLDS the purge — nothing is touched, Manage is acked `held`.
$acks = [];
$summary = lcRun($acks);
T::same(1, $summary['held'] ?? null, 'the worker held it');
T::same('held', $acks[0]['status'] ?? null, 'acked held');
T::ok(str_contains((string) ($acks[0]['detail']['reason'] ?? ''), 'legal hold'), 'with the hold\'s reason: ' . json_encode($acks[0]['detail'] ?? null));
T::same('held', Db::scalar("SELECT status FROM aic_lifecycle_jobs WHERE kind = 'company' AND target_cmp_id = :c", ['c' => $p]), 'the job waits as held');
T::same(1, (int) Db::scalar('SELECT COUNT(*) FROM voice_recordings WHERE cmp_id = :c', ['c' => $p]), 'nothing was purged');
T::ok(is_file($root . '/rec/a.wav'), 'the held recording file is still there');
$acks = [];
lcRun($acks);
T::same([], $acks, 'not looked at again before the daily re-check');
// The hold is cleared; the daily re-check comes round.
$pdo->exec("UPDATE voice_recordings SET legal_hold = FALSE WHERE cmp_id = {$p}");
$pdo->exec("UPDATE aic_lifecycle_jobs SET next_attempt_at = now() - interval '1 minute' WHERE kind = 'company' AND target_cmp_id = {$p}");
$acks = [];
$summary = lcRun($acks);
T::same(1, $summary['purged'], 'the worker purged it once the hold was lifted');
T::same('purged', $acks[0]['status'] ?? null, 'acked purged');
T::same([], $acks[0]['retained'] ?? null, 'nothing retained');
T::ok(str_contains((string) ($acks[0]['detail'] ?? ''), '1 stored file(s) of the purged rows deleted'), 'the ack detail counts the deleted recording');
foreach (['voice_calls', 'voice_recordings', 'voice_settings', 'voice_saved_searches'] as $t) {
    T::same(0, (int) Db::scalar("SELECT COUNT(*) FROM {$t} WHERE cmp_id = :c", ['c' => $p]), $t . ' deleted');
}
T::ok(!is_file($root . '/rec/a.wav'), 'the recording file is gone');
T::same('deleted', Db::scalar('SELECT status FROM ' . LifecycleFiles::TABLE . ' WHERE target_cmp_id = :c', ['c' => $p]), 'the deletion audit');
exec('rm -rf ' . escapeshellarg($root));

T::group('recordings this server cannot delete stay pending, for ops');
putenv('RECORDING_STORAGE_PATH');
$pdo->exec('INSERT INTO ' . LifecycleFiles::TABLE . " (target_cmp_id, archive_schema, source_table, storage, storage_key) VALUES ({$p}, 'aic_lifecycle_archive_c{$p}_20260101000000', 'voice_recordings', 'recording', 'rec/b.wav')");
LifecycleFiles::sweep();
T::same(['pending', 1], array_values((array) Db::first('SELECT status, attempts FROM ' . LifecycleFiles::TABLE . " WHERE storage_key = 'rec/b.wav'")), 'external storage: pending after the first attempt, never a silent success');
T::ok(str_contains((string) Db::scalar('SELECT last_error FROM ' . LifecycleFiles::TABLE . " WHERE storage_key = 'rec/b.wav'"), 'RECORDING_STORAGE_URL'), 'with the reason');

T::group('account purge');
$pdo->exec("INSERT INTO voice_saved_searches (cmp_id, user_uuid, name) VALUES (" . LC_CMP . ", '7301', 'mine'), (" . LC_CMP . ", '7302', 'theirs')");
// Kit 1.1 legal hold: the person was the agent on a call whose recording is under hold.
$agentId = (int) Db::scalar("INSERT INTO voice_agents (cmp_id, user_uuid) VALUES (" . LC_CMP . ", '7301') RETURNING agent_id");
$heldCall = (int) Db::scalar("INSERT INTO voice_calls (call_uuid, cmp_id, direction) VALUES ('lc-call-held', " . LC_CMP . ", 'inbound') RETURNING call_id");
$pdo->exec("INSERT INTO voice_call_participants (call_id, cmp_id, party, agent_id) VALUES ({$heldCall}, " . LC_CMP . ", 'agent', {$agentId})");
$pdo->exec("INSERT INTO voice_recordings (recording_uuid, call_id, cmp_id, kind, status, legal_hold) VALUES ('00000000-0000-4000-8000-00000000a002', {$heldCall}, " . LC_CMP . ", 'call', 'available', TRUE)");
T::same(202, lcReceive(lcEvent('account_purge_requested', ['uuid' => '7301', 'reason' => 'account_closed']))[0], 'accepted');
$acks = [];
lcRun($acks);
T::same('held', $acks[0]['status'] ?? null, 'held while a recording of their call is under legal hold');
T::same(1, (int) Db::scalar("SELECT COUNT(*) FROM voice_saved_searches WHERE user_uuid = '7301'"), 'nothing purged while held');
$pdo->exec("UPDATE voice_recordings SET legal_hold = FALSE WHERE call_id = {$heldCall}");
$pdo->exec("UPDATE aic_lifecycle_jobs SET next_attempt_at = now() - interval '1 minute' WHERE kind = 'account'");
$acks = [];
$summary = lcRun($acks);
T::same('purged', $acks[0]['status'] ?? null, 'acked purged once the hold was lifted');
T::same(0, (int) Db::scalar("SELECT COUNT(*) FROM voice_saved_searches WHERE user_uuid = '7301'"), "the person's own saved searches go");
T::same(1, (int) Db::scalar("SELECT COUNT(*) FROM voice_saved_searches WHERE user_uuid = '7302'"), "someone else's stay");

T::group('the cron bootstrap');
$out = shell_exec(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(__DIR__ . '/../lib/company-lifecycle/bin/worker.php')
    . ' --bootstrap=' . escapeshellarg(__DIR__ . '/../config/lifecycle-kit.php') . ' --dry-run=company:' . LC_CMP . ' 2>&1');
$json = json_decode((string) $out, true);
T::same('voice', $json['app'] ?? null, 'config/lifecycle-kit.php builds the kit (' . trim((string) $out) . ')');
T::same(true, $json['dry_run']['ok'] ?? null, 'and a dry run works');
$out = shell_exec(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(__DIR__ . '/../lib/company-lifecycle/bin/worker.php')
    . ' --bootstrap=' . escapeshellarg(__DIR__ . '/../config/lifecycle-kit.php') . ' --check-manifest 2>&1');
$json = json_decode((string) $out, true);
T::same([true, []], [$json['ok'] ?? null, $json['warnings'] ?? null], '--check-manifest: no refusals, no warnings (' . trim((string) $out) . ')');

T::group('kit 1.1 DeferredSend: a Calendar diary write refused with 423 COMPANY_RESTRICTED');
$op = ['operation_id' => 1, 'operation' => 'create_event', 'source_ref' => 'voice:callback:1', 'calendar_event_ref' => null];
$until = gmdate('Y-m-d\\TH:i:s\\Z', time() + 900);
$v = \Aicountly\Api\Domain\CallbackDiary::classify($op, ['ok' => false, 'status' => 423, 'body' => ['code' => 'COMPANY_RESTRICTED', 'message' => 'Read-only.', 'restricted_until' => $until, 'lifecycle' => 'restricted'], 'error' => 'Read-only.']);
T::same(['restricted', 'deferred_restricted', $until], [$v['outcome'], $v['code'], $v['restricted_until'] ?? null], 'deferred, not failed');
$v = \Aicountly\Api\Domain\CallbackDiary::classify($op, ['ok' => false, 'status' => 423, 'body' => ['error' => ['code' => 'COMPANY_RESTRICTED', 'message' => 'Gone.'], 'lifecycle' => 'purged'], 'error' => 'Gone.']);
T::same(['company_deleted', 'dropped_company_deleted'], [$v['outcome'], $v['code']], 'purged there: dropped');
$opId = (int) Db::scalar("INSERT INTO voice_external_operations (cmp_id, target_app, operation, correlation_id, status, error_code, restricted_until, next_check_at)
    VALUES (" . LC_CMP . ", 'calendar', 'create_event', 'lc-deferred-1', 'deferred', 'deferred_restricted', now() + interval '2 hours', now() - interval '1 minute') RETURNING operation_id");
$row = Db::first('SELECT * FROM voice_external_operations WHERE operation_id = :i', ['i' => $opId]);
$manage = ['lifecycle' => 'restricted'];
$ds = new \Aicountly\Lifecycle\DeferredSend(
    \Aicountly\Lifecycle\Config::fromEnv('voice', static fn (string $n): ?string => ['MANAGE_API_URL' => 'https://manage.test.invalid', 'MANAGE_PRODUCT_SERVICE_KEY' => 'svc'][$n] ?? null),
    static function () use (&$manage): array {
        return ['status' => 200, 'body' => json_encode(['data' => ['companies' => [(string) LC_CMP => ['status' => 'active'] + $manage]]]), 'error' => null];
    },
);
$cache = [];
T::same('waiting', \Aicountly\Api\ExternalOperations::reviewRestricted($row, $cache, $ds), 'still read-only there: waits');
T::ok(strtotime((string) Db::scalar('SELECT next_check_at FROM voice_external_operations WHERE operation_id = :i', ['i' => $opId])) > time() + 3000, 'looked at again in an hour');
$manage = ['lifecycle' => 'active'];
$cache = [];
T::same(null, \Aicountly\Api\ExternalOperations::reviewRestricted($row, $cache, $ds), 'active again: resumed (the normal recovery sends the same attempt)');
$manage = [];
$cache = [];
$gone = new \Aicountly\Lifecycle\DeferredSend(
    \Aicountly\Lifecycle\Config::fromEnv('voice', static fn (string $n): ?string => ['MANAGE_API_URL' => 'https://manage.test.invalid', 'MANAGE_PRODUCT_SERVICE_KEY' => 'svc'][$n] ?? null),
    static fn (): array => ['status' => 200, 'body' => json_encode(['data' => ['companies' => [(string) LC_CMP => ['status' => 'deleted']]]]), 'error' => null],
);
T::same('dropped', \Aicountly\Api\ExternalOperations::reviewRestricted($row, $cache, $gone), 'deleted in Manage: dropped');
T::same(['failed', 'dropped_company_deleted'], array_values((array) Db::first('SELECT status, error_code FROM voice_external_operations WHERE operation_id = :i', ['i' => $opId])), 'recorded');
T::same(1, (int) Db::scalar("SELECT COUNT(*) FROM voice_audit_events WHERE action = 'external_operation.dropped'"), 'with an audit event');

$cleanup();
truncateAll();
Auth::adopt(null);

exit(T::summary());
