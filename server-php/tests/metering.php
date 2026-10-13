<?php

declare(strict_types=1);

/**
 * Usage metering: what Voice reports to Manage's central usage meter
 * (manage-aicountly docs/usage-metering/).
 *
 * Run with tests/run.sh, after integration.php, against the same database and
 * stub gateway — with its own company, cleaned up afterwards. The gateway's
 * callbacks go through the REAL webhook route over HTTP (a php -S child that
 * inherits the metering settings below), and an agent's hang-up through the
 * real CallService. The meter spools to a temporary directory and can reach
 * nobody: no call is placed and nothing is sent to Manage.
 *
 * What these prove: one event per ended call, keyed on the call's own id;
 * talk seconds as Voice computes them; the calling product from the call's
 * proven origin; no number in anything spooled; and that a callback or a
 * hang-up answers the same with metering off or broken.
 */

namespace Aicountly\Api\Tests;

use Aicountly\Api\Auth;
use Aicountly\Api\Context;
use Aicountly\Api\Db;
use Aicountly\Api\Domain\CallService;
use Aicountly\Api\Env;
use Aicountly\Api\Metering\VoiceMeter;
use Aicountly\Metering\UsageMeter;

require __DIR__ . '/../src/Autoload.php';
require __DIR__ . '/../src/Metering/AicountlyUsageMeter.php';
require __DIR__ . '/support.php';

Env::load(__DIR__ . '/../.env');

const CMP = 4901;
const AGENT_UUID = 'user-meter-agent';

echo "Voice usage metering\n" . str_repeat('=', 62) . "\n";

function cleanUp(): void
{
    foreach ([
        'voice_call_events', 'voice_call_participants', 'voice_call_legs', 'voice_usage_entries', 'voice_calls',
        'voice_agents', 'voice_numbers', 'voice_provider_connections', 'voice_settings', 'voice_audit_events',
    ] as $table) {
        Db::run('DELETE FROM ' . $table . ' WHERE cmp_id = :cmp', ['cmp' => CMP]);
    }
}

function spoolDir(): string
{
    return (getenv('METERING_TEST_SPOOL') ?: sys_get_temp_dir()) . '/voice-meter-' . bin2hex(random_bytes(4));
}

/** @return list<array<string, mixed>> */
function spooled(string $dir): array
{
    $files = glob($dir . '/*.json') ?: [];
    sort($files);

    return array_map(static fn (string $f): array => json_decode((string) file_get_contents($f), true), $files);
}

function noNumbers(string $dir): bool
{
    foreach (glob($dir . '/*.json') ?: [] as $file) {
        $raw = (string) file_get_contents($file);
        if (str_contains($raw, '9876500') || str_contains($raw, '8066000')) {
            return false;
        }
    }

    return true;
}

/** The settings a deployment would carry, for the webhook server (a child process inherits them). */
function meterEnv(?string $spool, string $enabled = '1', string $url = 'http://127.0.0.1:9/api'): void
{
    foreach ([
        'AICOUNTLY_METERING_ENABLED' => $enabled, 'AICOUNTLY_METERING_URL' => $url, 'AICOUNTLY_METERING_KEY' => 'k',
        'AICOUNTLY_METERING_SOURCE' => 'voice', 'AICOUNTLY_METERING_ENVIRONMENT' => 'development',
        'AICOUNTLY_METERING_SPOOL_DIR' => $spool, 'AICOUNTLY_METERING_FLUSH' => 'cron',
    ] as $key => $value) {
        putenv($value === null ? $key : $key . '=' . $value);
    }
}

/** The real webhook route, served by a child process with the settings above. */
function webhookServer(int $connectionId): array
{
    $port = (int) (getenv('STUB_PORT') ?: 8794) + 9;
    $docroot = sys_get_temp_dir() . '/voice-meter-webhook-' . getmypid() . '-' . bin2hex(random_bytes(2));
    @mkdir($docroot);
    @symlink(dirname(__DIR__), $docroot . '/api');
    $server = proc_open(['php', '-S', '127.0.0.1:' . $port, '-t', $docroot, $docroot . '/api/index.php'], [1 => ['file', '/dev/null', 'w'], 2 => ['file', '/dev/null', 'w']], $pipes);
    for ($i = 0; $i < 40 && @fsockopen('127.0.0.1', $port) === false; $i++) {
        usleep(100000);
    }
    $send = static function (array $event) use ($port, $connectionId): array {
        $payload = (string) json_encode($event);
        $ts = (string) time();
        $ch = curl_init('http://127.0.0.1:' . $port . '/api/webhooks/telephony/' . $connectionId);
        curl_setopt_array($ch, [CURLOPT_POST => true, CURLOPT_POSTFIELDS => $payload, CURLOPT_RETURNTRANSFER => true, CURLOPT_HTTPHEADER => [
            'Content-Type: application/json',
            'X-Voice-Timestamp: ' . $ts,
            'X-Voice-Signature: ' . hash_hmac('sha256', $ts . '.' . $payload, 'stub-signing-secret'),
        ]]);
        $body = (string) curl_exec($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        curl_close($ch);

        return ['status' => $status, 'body' => json_decode($body, true) ?: []];
    };
    $stop = static function () use ($server, $docroot): void {
        proc_terminate($server);
        @unlink($docroot . '/api');
        @rmdir($docroot);
    };

    return [$send, $stop];
}

function inProcessMeter(string $dir, string $enabled = '1', string $url = 'http://127.0.0.1:9/api'): void
{
    UsageMeter::setShared(new UsageMeter([
        'enabled' => $enabled, 'flush' => 'cron', 'spool_dir' => $dir, 'source' => 'voice',
        'environment' => 'development', 'url' => $url, 'key' => 'k',
    ], static fn (): array => [0, '']));
}

cleanUp();
stubReset();
$connectionId = seedConnection(CMP);
seedNumber(CMP, $connectionId, '+918066000901');
seedSettings(CMP);
$agentId = seedAgent(CMP, AGENT_UUID, '1901');
$owner = markOwner(Auth::forTesting('user-meter-owner'));
// CRM placing a call with its service key: the call's origin is proven by the key.
$crm = Auth::forTesting('service:crm', 'service', 'crm');
$ctx = scope(CMP, $owner);

// ===========================================================================
T::group('1. A call CRM placed, ended by the gateway, is one event booked to CRM');
// ===========================================================================
{
    $dir = spoolDir();
    meterEnv($dir);
    [$send, $stop] = webhookServer($connectionId);

    $placed = CallService::place($ctx, $crm, ['to' => '+919876500901', 'agent_id' => $agentId]);
    T::ok($placed['ok'], 'CRM places a call through the stub gateway');
    $callId = (int) ($placed['call']['call_id'] ?? 0);
    $uuid = (string) Db::scalar('SELECT call_uuid FROM voice_calls WHERE call_id = :id', ['id' => $callId]);
    T::same('CRM', Db::scalar('SELECT origin FROM voice_calls WHERE call_id = :id', ['id' => $callId]), 'its origin is CRM, from the key');

    $answered = $send(['event_id' => 'm-ans-1', 'event' => 'call.answered', 'correlation_id' => $uuid]);
    T::same('answered', $answered['body']['data']['state'] ?? null, 'the gateway says it was answered');
    T::same([], spooled($dir), 'nothing is metered while the call is up');
    Db::run("UPDATE voice_calls SET answered_at = NOW() - INTERVAL '95 seconds' WHERE call_id = :id", ['id' => $callId]);

    $done = $send(['event_id' => 'm-end-1', 'event' => 'call.completed', 'correlation_id' => $uuid]);
    T::same(200, $done['status'], 'the completion callback answers 200');
    T::same('completed', $done['body']['data']['state'] ?? null, 'and ends the call');
    $talk = (int) Db::scalar('SELECT talk_seconds FROM voice_calls WHERE call_id = :id', ['id' => $callId]);

    $events = spooled($dir);
    T::same(1, count($events), 'one event');
    $e = $events[0] ?? [];
    T::same('voice:call:' . $uuid, $e['idempotency_key'] ?? null, 'keyed on the call\'s own id');
    T::same(UsageMeter::eventIdFor('voice', 'voice:call:' . $uuid), $e['event_id'] ?? null, 'a deterministic event id');
    T::same([['meter' => 'voice.call_seconds', 'quantity' => $talk], ['meter' => 'voice.calls', 'quantity' => 1]], $e['usage'] ?? null,
        'talk seconds as Voice computed them (' . $talk . ') and one call');
    T::ok($talk >= 95, 'which is the answered time');
    T::same(['voice', 'aicountly_voice_gateway', 'outbound'], [$e['category'] ?? null, $e['provider'] ?? null, $e['service'] ?? null], 'the Voice Gateway, outbound');
    T::same(['crm', CMP, AGENT_UUID], [$e['origin_app'] ?? null, $e['company_id'] ?? null, $e['user_uuid'] ?? null], 'booked to CRM, the company and the agent');
    T::same(['success', 'completed'], [$e['outcome'] ?? null, $e['provider_status'] ?? null], 'completed');
    T::ok(!isset($e['vendor_cost']), 'no vendor cost: the RATE_* estimate is not a vendor price');
    T::ok(noNumbers($dir), 'no phone number in the event');

    $again = $send(['event_id' => 'm-end-2', 'event' => 'call.completed', 'correlation_id' => $uuid]);
    T::same('skipped', $again['body']['data']['outcome'] ?? null, 'a second completion is skipped');
    T::same(1, count(spooled($dir)), 'and meters nothing more');

    // An unanswered inbound call is still a call: no seconds, one call, Voice's own.
    $send(['event_id' => 'm-in-1', 'event' => 'call.inbound', 'leg_ref' => 'leg-meter-in-1', 'from' => '+919876500902', 'to' => '+918066000901']);
    $missed = $send(['event_id' => 'm-in-2', 'event' => 'call.no_answer', 'leg_ref' => 'leg-meter-in-1']);
    T::same('unanswered', $missed['body']['data']['state'] ?? null, 'an inbound call nobody answers ends unanswered');
    $events = spooled($dir);
    $in = $events[1] ?? [];
    T::same([['meter' => 'voice.call_seconds', 'quantity' => 0], ['meter' => 'voice.calls', 'quantity' => 1]], $in['usage'] ?? null, 'is 0 seconds and one call');
    T::same(['voice', 'inbound', 'failed', 'unanswered'], [$in['origin_app'] ?? null, $in['service'] ?? null, $in['outcome'] ?? null, $in['provider_status'] ?? null],
        'booked to Voice, inbound, not a completed call');
    T::ok(!isset($in['user_uuid']), 'with no agent, no user');
    T::ok(noNumbers($dir), 'and no number');

    $stop();
}

// ===========================================================================
T::group('2. An agent hanging up ends the call here, and is metered here');
// ===========================================================================
{
    meterEnv(null);
    $dir = spoolDir();
    inProcessMeter($dir);

    $placed = CallService::place($ctx, $owner, ['to' => '+919876500903', 'agent_id' => $agentId]);
    $callId = (int) ($placed['call']['call_id'] ?? 0);
    Db::run("UPDATE voice_calls SET state = 'answered', answered_at = NOW() - INTERVAL '30 seconds' WHERE call_id = :id", ['id' => $callId]);

    $hung = CallService::control($ctx, $owner, $callId, 'hangup');
    T::ok($hung['ok'], 'the agent hangs up');
    $events = spooled($dir);
    T::same(1, count($events), 'one event');
    $e = $events[0] ?? [];
    T::same('voice', $e['origin_app'] ?? null, 'a call from the agent console is Voice\'s own');
    T::ok((int) ($e['usage'][0]['quantity'] ?? 0) >= 30, 'with its talk seconds');

    VoiceMeter::recordCall(CMP, $callId);
    $events = spooled($dir);
    T::same($events[0]['event_id'] ?? 'a', $events[1]['event_id'] ?? 'b', 'the same call again is the same event');
    T::same($events[0], $events[1], 'with the same content, so Manage counts it once');
    UsageMeter::setShared(null);
}

// ===========================================================================
T::group('3. Metering off or broken changes nothing a caller sees');
// ===========================================================================
{
    $placed = [];
    foreach (['off' => ['0', 'http://127.0.0.1:9/api'], 'broken' => ['1', '']] as $label => [$enabled, $url]) {
        $file = tempnam(sys_get_temp_dir(), 'not-a-dir');
        meterEnv($file . '/spool', $enabled, $url);   // a spool that cannot be created
        [$send, $stop] = webhookServer($connectionId);
        $call = CallService::place($ctx, $crm, ['to' => '+919876500904']);
        $uuid = (string) Db::scalar('SELECT call_uuid FROM voice_calls WHERE call_id = :id', ['id' => (int) ($call['call']['call_id'] ?? 0)]);
        $send(['event_id' => 'm-' . $label . '-1', 'event' => 'call.answered', 'correlation_id' => $uuid]);
        $done = $send(['event_id' => 'm-' . $label . '-2', 'event' => 'call.completed', 'correlation_id' => $uuid]);
        T::same([200, 'applied', 'completed'], [$done['status'], $done['body']['data']['outcome'] ?? null, $done['body']['data']['state'] ?? null],
            'metering ' . $label . ': the completion callback answers as always');
        $stop();

        UsageMeter::setShared(null);
        inProcessMeter($file . '/spool', $enabled, $url);
        $call = CallService::place($ctx, $owner, ['to' => '+919876500905']);
        $callId = (int) ($call['call']['call_id'] ?? 0);
        Db::run("UPDATE voice_calls SET state = 'answered', answered_at = NOW() WHERE call_id = :id", ['id' => $callId]);
        $hung = CallService::control($ctx, $owner, $callId, 'hangup');
        T::same([true, 'completed'], [$hung['ok'], Db::scalar('SELECT state FROM voice_calls WHERE call_id = :id', ['id' => $callId])],
            'metering ' . $label . ': a hang-up still ends the call');
        UsageMeter::setShared(null);
        @unlink($file);
    }
    meterEnv(null, '0');
}

cleanUp();
stubReset();

exit(T::summary());
