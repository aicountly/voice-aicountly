<?php

declare(strict_types=1);

/**
 * Voice integration tests.
 *
 * These exercise the real controllers, the real services and a real PostgreSQL.
 * The only thing standing in for reality is the stub that answers for Manage,
 * Contacts, Calendar, CRM and the voice gateway — because a test suite must
 * never place a real call, launch a real campaign or write to a real product.
 *
 * Run with tests/run.sh, which sets up the database and the stub.
 */

namespace Aicountly\Api\Tests;

use Aicountly\Api\Auth;
use Aicountly\Api\Context;
use Aicountly\Api\Crypto;
use Aicountly\Api\Db;
use Aicountly\Api\Domain\AiAgentService;
use Aicountly\Api\Domain\BudgetService;
use Aicountly\Api\Domain\CallbackService;
use Aicountly\Api\Domain\CallingPolicy;
use Aicountly\Api\Domain\CallService;
use Aicountly\Api\Domain\CallStateMachine;
use Aicountly\Api\Domain\CampaignService;
use Aicountly\Api\Domain\CommitmentService;
use Aicountly\Api\Domain\FlowValidator;
use Aicountly\Api\Domain\RetentionService;
use Aicountly\Api\Env;
use Aicountly\Api\ExternalOperations;
use Aicountly\Api\Permissions;
use Aicountly\Api\Settings;
use Aicountly\Api\Support\Clock;
use Aicountly\Api\Support\Uuid;
use Aicountly\Api\Telephony\Capability;
use Aicountly\Api\Telephony\ProviderRegistry;

require __DIR__ . '/../src/Autoload.php';
require __DIR__ . '/support.php';

Env::load(__DIR__ . '/../.env');

const CMP = 4001;
const OTHER_CMP = 4002;
const USER = 'user-aaa';
const OTHER_USER = 'user-bbb';

truncateAll();
stubReset();

$owner = Auth::forTesting(USER, 'user', 'voice', ['acs_type' => 1]);
$member = Auth::forTesting(OTHER_USER, 'user', 'voice', []);

echo "Voice integration tests\n" . str_repeat('=', 62) . "\n";

// ===========================================================================
T::group('1. Tenant isolation');
// ===========================================================================
{
    $connectionId = seedConnection(CMP);
    seedNumber(CMP, $connectionId);
    seedSettings(CMP);

    $otherConnection = seedConnection(OTHER_CMP);
    seedNumber(OTHER_CMP, $otherConnection, '+918066000002');
    seedSettings(OTHER_CMP);

    $ctxA = scope(CMP, $owner);
    $ctxB = scope(OTHER_CMP, $owner);

    $callA = CallService::place($ctxA, $owner, ['to' => '+919876500001']);
    $callB = CallService::place($ctxB, $owner, ['to' => '+919876500002']);

    T::ok($callA['ok'], 'a call can be placed in company A');
    T::ok($callB['ok'], 'a call can be placed in company B');

    $idA = (int) $callA['call']['call_id'];

    // The whole point: company B must not be able to read company A's call.
    T::same(null, CallService::find($ctxB, $idA), 'company B cannot read company A’s call by id');
    T::ok(CallService::find($ctxA, $idA) !== null, 'company A can read its own call');

    // The list endpoint, through the router, with a real scope.
    Auth::adopt($owner);
    $listB = request('GET', '/v1/calls', ['cmp_id' => (string) OTHER_CMP]);
    $idsB = array_map(static fn (array $r): int => $r['call_id'], $listB['body']['data'] ?? []);
    T::ok(!in_array($idA, $idsB, true), 'company A’s call is absent from company B’s call list');

    // Transcript access is scoped too — this is the one that would leak a
    // private conversation rather than a row count.
    Db::insert('voice_transcript_segments', [
        'call_id' => $idA, 'cmp_id' => CMP, 'sequence_no' => 1,
        'speaker' => 'caller', 'text' => 'secret words from company A',
    ], 'segment_id');

    $transcriptB = request('GET', '/v1/calls/' . $idA . '/transcript', ['cmp_id' => (string) OTHER_CMP]);
    T::same(404, $transcriptB['status'], 'company B gets 404 for company A’s transcript');

    // A company the session may not open at all: Manage is asked for real here.
    Context::resetForTesting();
    $forbidden = request('GET', '/v1/calls', ['cmp_id' => '999']);
    T::same(403, $forbidden['status'], 'a company Manage refuses is 403, not an empty list');
    Context::resetForTesting();
    Context::trustForTesting(CMP, $owner);
    Context::trustForTesting(OTHER_CMP, $owner);
}

// ===========================================================================
T::group('2. Permission enforcement');
// ===========================================================================
{
    // A plain member, not an owner — an owner holds everything and would prove
    // nothing.
    Auth::adopt($member);
    Context::trustForTesting(CMP, $member);
    grant(CMP, OTHER_USER, ['voice.dashboard.view', 'voice.call.view']);

    $denied = request('POST', '/v1/calls', ['cmp_id' => (string) CMP], ['to' => '+919876500003']);
    T::same(403, $denied['status'], 'placing a call without voice.call.place is 403');

    $allowedRead = request('GET', '/v1/calls', ['cmp_id' => (string) CMP]);
    T::same(200, $allowedRead['status'], 'reading calls with voice.call.view is allowed');

    $recordings = request('GET', '/v1/recordings', ['cmp_id' => (string) CMP]);
    T::same(403, $recordings['status'], 'recordings without voice.recordings.listen is 403');

    $exportAudit = request('GET', '/v1/audit', ['cmp_id' => (string) CMP]);
    T::same(403, $exportAudit['status'], 'the audit trail without voice.audit.view is 403');

    $campaignLaunch = request('POST', '/v1/campaigns/1/actions', ['cmp_id' => (string) CMP], ['action' => 'start']);
    T::same(403, $campaignLaunch['status'], 'launching a campaign without voice.campaigns.launch is 403');

    // Day-one defaults must not include the expensive or intrusive ones.
    T::ok(!in_array('voice.recordings.listen', Permissions::DEFAULT_MEMBER_GRANTS, true),
        'recording access is not granted by default');
    T::ok(!in_array('voice.supervisor.monitor', Permissions::DEFAULT_MEMBER_GRANTS, true),
        'supervisor monitoring is not granted by default');
    T::ok(!in_array('voice.campaigns.launch', Permissions::DEFAULT_MEMBER_GRANTS, true),
        'campaign launch is not granted by default');

    Auth::adopt($owner);
}

// ===========================================================================
T::group('3. Duplicate and out-of-order provider events');
// ===========================================================================
{
    $ctx = scope(CMP, $owner);
    $connectionId = (int) Db::scalar('SELECT connection_id FROM voice_provider_connections WHERE cmp_id = :c LIMIT 1', ['c' => CMP]);

    $call = CallService::place($ctx, $owner, ['to' => '+919876500010']);
    $callId = (int) $call['call']['call_id'];

    $event = static fn (string $id, string $type, string $state): array => [
        'provider_event_id' => $id,
        'event_type'        => $type,
        'provider_ref'      => null,
        'leg_ref'           => null,
        'state'             => $state,
        'timestamp'         => Clock::iso(Clock::now()),
        'sequence'          => null,
        'detail'            => [],
    ];

    $first = CallStateMachine::applyProviderEvent(CMP, $connectionId, $callId, $event('e1', 'call.ringing', 'ringing'));
    T::same('applied', $first['outcome'], 'a ringing event is applied');

    // The same event again — a carrier retry.
    $repeat = CallStateMachine::applyProviderEvent(CMP, $connectionId, $callId, $event('e1', 'call.ringing', 'ringing'));
    T::same('duplicate', $repeat['outcome'], 'the same provider event twice is a no-op');

    CallStateMachine::applyProviderEvent(CMP, $connectionId, $callId, $event('e2', 'call.answered', 'answered'));

    // A queued event arriving after answered: progress going backwards.
    $late = CallStateMachine::applyProviderEvent(CMP, $connectionId, $callId, $event('e3', 'call.queued', 'queued'));
    T::same('skipped', $late['outcome'], 'a state that goes backwards is skipped');
    T::same('out_of_order', $late['reason'], 'and is recorded as out of order');

    CallStateMachine::applyProviderEvent(CMP, $connectionId, $callId, $event('e4', 'call.completed', 'completed'));

    // The case that resurrects a finished call if you get it wrong.
    $afterEnd = CallStateMachine::applyProviderEvent(CMP, $connectionId, $callId, $event('e5', 'call.ringing', 'ringing'));
    T::same('skipped', $afterEnd['outcome'], 'a ringing event after completion is skipped');
    T::same('terminal', $afterEnd['reason'], 'and is recorded as terminal');

    $state = (string) Db::scalar('SELECT state FROM voice_calls WHERE call_id = :id', ['id' => $callId]);
    T::same('completed', $state, 'the completed call stays completed');

    // Every event is stored, applied or not — it is evidence either way.
    $stored = (int) Db::scalar('SELECT COUNT(*) FROM voice_call_events WHERE call_id = :id', ['id' => $callId]);
    T::same(5, $stored, 'all five distinct events are recorded, including the skipped ones');
}

// ===========================================================================
T::group('4. Idempotent call creation');
// ===========================================================================
{
    Auth::adopt($owner);
    $key = 'test-idem-' . substr(Uuid::v4(), 0, 12);

    $first = request('POST', '/v1/calls', ['cmp_id' => (string) CMP], ['to' => '+919876500020'],
        ['Idempotency-Key' => $key]);
    T::same(201, $first['status'], 'the first call request is created');

    $second = request('POST', '/v1/calls', ['cmp_id' => (string) CMP], ['to' => '+919876500020'],
        ['Idempotency-Key' => $key]);
    T::same(201, $second['status'], 'the repeat replays the first answer');
    T::same(
        $first['body']['data']['call_id'] ?? null,
        $second['body']['data']['call_id'] ?? null,
        'the repeat returns the SAME call, not a second one',
    );

    $count = (int) Db::scalar(
        'SELECT COUNT(*) FROM voice_calls WHERE cmp_id = :c AND remote_e164 = :n',
        ['c' => CMP, 'n' => '+919876500020'],
    );
    T::same(1, $count, 'only one call row exists for that number');
    clearHeaders();
}

// ===========================================================================
T::group('5. Timeout after a possibly-successful external write');
// ===========================================================================
{
    $ctx = scope(CMP, $owner);
    stubMode('calendar', 'timeout');

    $result = CallbackService::create($ctx, $owner, [
        'e164'   => '+919876500030',
        'reason' => 'Ring back about the quote',
        'due_at' => Clock::iso(Clock::now()->modify('+1 hour')),
        'create_calendar_event' => true,
    ]);

    T::ok($result['ok'], 'the callback itself is created — it is Voice’s own record');
    T::ok($result['callback']['calendar_event_ref'] === null,
        'no calendar reference is invented when the outcome is unknown');

    $operation = Db::first(
        'SELECT * FROM voice_external_operations WHERE cmp_id = :c ORDER BY operation_id DESC LIMIT 1',
        ['c' => CMP],
    );
    T::same(ExternalOperations::UNKNOWN, (string) $operation['status'],
        'the write is recorded as UNKNOWN, not failed and not succeeded');
    T::ok(str_contains((string) $result['message'], 'not confirmed')
       || str_contains((string) $result['message'], 'could not'),
        'the message says the outcome is not confirmed');

    // The reconcile path: ask the owner what it holds, do not resend.
    $operationId = (int) $operation['operation_id'];
    ExternalOperations::reconcile($operationId, true, 'stub-event-reconciled');
    $after = ExternalOperations::find($ctx, $operationId);
    T::same(ExternalOperations::SUCCEEDED, (string) $after['status'],
        'reconciling with the owner settles it as succeeded');
    T::same('stub-event-reconciled', (string) $after['external_ref'],
        'and stores the owner’s reference');

    // The other branch: the owner never made one.
    $second = ExternalOperations::begin($ctx, 'calendar', 'create_event', [], [], USER);
    ExternalOperations::reconcile($second['operation_id'], false, null);
    $secondAfter = ExternalOperations::find($ctx, $second['operation_id']);
    T::same(ExternalOperations::FAILED, (string) $secondAfter['status'],
        'an owner with no record settles it as failed, cleanly');

    stubReset();
}

// ===========================================================================
T::group('6. Calendar failure creates no local event');
// ===========================================================================
{
    $ctx = scope(CMP, $owner);
    stubMode('calendar', 'down');

    $before = (int) Db::scalar('SELECT COUNT(*) FROM voice_callbacks WHERE cmp_id = :c', ['c' => CMP]);

    $result = CallbackService::create($ctx, $owner, [
        'e164'   => '+919876500031',
        'due_at' => Clock::iso(Clock::now()->modify('+2 hours')),
        'create_calendar_event' => true,
    ]);

    T::ok($result['ok'], 'the callback is still created when Calendar is down');
    T::same(null, $result['callback']['calendar_event_ref'],
        'no calendar reference is stored');

    // The decisive check: Voice has no table that could hold a mirrored
    // calendar event. voice_call_events and voice_audit_events are excluded by
    // name because they hold telephony events and audit rows, neither of which
    // is a scheduling record.
    $tables = Db::all(
        "SELECT tablename FROM pg_tables WHERE schemaname = 'public'
            AND (tablename LIKE '%event%' OR tablename LIKE '%appointment%' OR tablename LIKE '%booking%')
            AND tablename NOT IN ('voice_call_events', 'voice_audit_events')",
    );
    $names = array_map(static fn (array $r): string => (string) $r['tablename'], $tables);
    T::same([], $names,
        'there is no table that could hold a local calendar event to fall back to');

    T::same($before + 1, (int) Db::scalar('SELECT COUNT(*) FROM voice_callbacks WHERE cmp_id = :c', ['c' => CMP]),
        'exactly one callback was created');

    stubReset();
}

// ===========================================================================
T::group('7. Contacts failure creates no local contact mirror');
// ===========================================================================
{
    Auth::adopt($owner);
    stubMode('contacts', 'down');

    $search = request('GET', '/v1/contacts', ['cmp_id' => (string) CMP, 'q' => 'priya']);
    T::same(503, $search['status'], 'a contact search answers 503 when Contacts is down');
    T::ok(str_contains(strtolower((string) ($search['body']['message'] ?? '')), 'could not be reached')
       || str_contains(strtolower((string) ($search['body']['message'] ?? '')), 'not enabled'),
        'and says the directory could not be reached');

    $create = request('POST', '/v1/contacts', ['cmp_id' => (string) CMP], ['name' => 'Someone New']);
    T::same(503, $create['status'], 'creating a contact answers 503 when Contacts is down');

    // The decisive check: no table exists that could hold a contact.
    $tables = Db::all(
        "SELECT tablename FROM pg_tables WHERE schemaname = 'public' AND tablename LIKE '%contact%'",
    );
    T::same(0, count($tables), 'Voice has no contacts table at all');

    stubReset();
}

// ===========================================================================
T::group('8. Provider capability restrictions');
// ===========================================================================
{
    $ctx = scope(CMP, $owner);
    $adapter = ProviderRegistry::forCompany($ctx);
    $capabilities = $adapter->capabilities();

    T::ok($capabilities[Capability::BLIND_TRANSFER], 'the stub gateway reports blind transfer');
    T::ok(!$capabilities[Capability::ATTENDED_TRANSFER], 'and does NOT report attended transfer');

    $call = CallService::place($ctx, $owner, ['to' => '+919876500040']);
    $callId = (int) $call['call']['call_id'];

    $blind = CallService::control($ctx, $owner, $callId, 'transfer', ['destination' => '1002', 'mode' => 'blind']);
    T::ok($blind['ok'], 'a blind transfer is allowed');

    $attended = CallService::control($ctx, $owner, $callId, 'transfer', ['destination' => '1002', 'mode' => 'attended']);
    T::ok(!$attended['ok'], 'an attended transfer is refused');
    T::same('capability_unsupported', $attended['code'], 'and refused by name, not downgraded to blind');

    $barge = CallService::control($ctx, $owner, $callId, 'monitor', ['endpoint' => '1003', 'mode' => 'barge']);
    T::same('capability_unsupported', $barge['code'], 'barge-in is refused — the gateway does not report it');

    // A company with no connection can do nothing, and says so clearly.
    $emptyCtx = Context::forCompany(4999);
    Context::trustForTesting(4999, $owner);
    $nullAdapter = ProviderRegistry::forCompany($emptyCtx);
    T::same('null', $nullAdapter->key(), 'a company with no connection gets the null adapter');
    T::ok(!$nullAdapter->capabilities()[Capability::PLACE_CALL], 'which reports no capabilities at all');

    $refused = CallService::place($emptyCtx, $owner, ['to' => '+919876500041']);
    T::same('provider_not_configured', $refused['code'], 'and placing a call is refused with a reason');
}

// ===========================================================================
T::group('9. Webhook signature verification and replay');
// ===========================================================================
{
    $connectionId = (int) Db::scalar('SELECT connection_id FROM voice_provider_connections WHERE cmp_id = :c LIMIT 1', ['c' => CMP]);
    $row = Db::first('SELECT * FROM voice_provider_connections WHERE connection_id = :id', ['id' => $connectionId]);
    $adapter = ProviderRegistry::build($row);

    $payload = json_encode(['event_id' => 'w1', 'event' => 'call.answered']);
    $timestamp = (string) time();
    $signature = hash_hmac('sha256', $timestamp . '.' . $payload, 'stub-signing-secret');

    T::ok($adapter->verifyWebhook($payload, [
        'x-voice-signature' => $signature,
        'x-voice-timestamp' => $timestamp,
    ]), 'a correctly signed callback verifies');

    T::ok(!$adapter->verifyWebhook($payload, [
        'x-voice-signature' => $signature,
        'x-voice-timestamp' => (string) (time() - 600),
    ]), 'an old timestamp is refused even with a signature');

    T::ok(!$adapter->verifyWebhook($payload . 'x', [
        'x-voice-signature' => $signature,
        'x-voice-timestamp' => $timestamp,
    ]), 'an altered body is refused');

    T::ok(!$adapter->verifyWebhook($payload, []), 'an unsigned callback is refused');

    // No secret configured means nothing can be trusted.
    $unsigned = new \Aicountly\Api\Telephony\GatewayAdapter(0, [], '');
    T::ok(!$unsigned->verifyWebhook($payload, [
        'x-voice-signature' => $signature,
        'x-voice-timestamp' => $timestamp,
    ]), 'with no signing secret, every callback is refused');
}

// ===========================================================================
T::group('10. Campaign pause stops new dispatch');
// ===========================================================================
{
    $ctx = scope(CMP, $owner);
    $connectionId = (int) Db::scalar('SELECT connection_id FROM voice_provider_connections WHERE cmp_id = :c LIMIT 1', ['c' => CMP]);

    $campaignId = (int) Db::insert('voice_campaigns', [
        'cmp_id' => CMP, 'name' => 'Pause test', 'mode' => 'preview', 'status' => 'running',
        'connection_id' => $connectionId, 'timezone' => 'UTC',
        'window_start_min' => 0, 'window_end_min' => 1440,
        'window_days' => [0, 1, 2, 3, 4, 5, 6],
        'max_concurrent' => 5, 'calls_per_minute' => 10,
        'script' => ['body' => 'Hello', 'reviewed' => true, 'audience_purpose' => 'Requested callback'],
    ], 'campaign_id');

    for ($i = 0; $i < 5; $i++) {
        $refId = (int) Db::insert('voice_campaign_audience_refs', [
            'campaign_id' => $campaignId, 'cmp_id' => CMP,
            'source' => 'contacts', 'external_ref' => 'stub-' . $i,
        ], 'audience_ref_id');
        Db::insert('voice_campaign_attempts', [
            'campaign_id' => $campaignId, 'audience_ref_id' => $refId,
            'cmp_id' => CMP, 'attempt_no' => 1, 'status' => 'queued',
        ], 'attempt_id');
    }

    $claimed = CampaignService::claimBatch($campaignId, 2);
    T::same(2, count($claimed), 'a running campaign yields a bounded batch');

    // Two workers racing for the same rows.
    $second = CampaignService::claimBatch($campaignId, 5);
    $firstIds = array_map(static fn (array $a): int => (int) $a['attempt_id'], $claimed);
    $secondIds = array_map(static fn (array $a): int => (int) $a['attempt_id'], $second);
    T::same([], array_intersect($firstIds, $secondIds), 'two workers never claim the same attempt');

    $paused = CampaignService::act($ctx, $owner, $campaignId, 'pause');
    T::ok($paused['ok'], 'the campaign pauses');

    $afterPause = CampaignService::claimBatch($campaignId, 5);
    T::same(0, count($afterPause), 'a paused campaign dispatches nothing new');

    T::ok(str_contains((string) $paused['message'], 'in progress')
       || str_contains((string) $paused['message'], 'No new calls'),
        'and the message says what happens to calls already connected');

    // The duplicate-attempt guard.
    $refId = (int) Db::scalar('SELECT audience_ref_id FROM voice_campaign_audience_refs WHERE campaign_id = :c LIMIT 1', ['c' => $campaignId]);
    $duplicate = Db::scalar(
        'INSERT INTO voice_campaign_attempts (campaign_id, audience_ref_id, cmp_id, attempt_no, status)
         VALUES (:c, :a, :cmp, 1, \'queued\')
         ON CONFLICT (campaign_id, audience_ref_id, attempt_no) DO NOTHING
         RETURNING attempt_id',
        ['c' => $campaignId, 'a' => $refId, 'cmp' => CMP],
    );
    T::same(null, $duplicate, 'the same audience member cannot be queued twice for one attempt number');
}

// ===========================================================================
T::group('11. Campaign launch readiness');
// ===========================================================================
{
    $ctx = scope(CMP, $owner);

    $campaignId = (int) Db::insert('voice_campaigns', [
        'cmp_id' => CMP, 'name' => 'Readiness test', 'mode' => 'preview', 'status' => 'draft',
        'timezone' => 'UTC', 'window_start_min' => 0, 'window_end_min' => 1440,
        'window_days' => [0, 1, 2, 3, 4, 5, 6],
        'script' => [],
    ], 'campaign_id');

    $validation = CampaignService::validate($ctx, $campaignId);
    T::ok(!$validation['ready'], 'a campaign with no audience is not ready');

    $keys = array_column($validation['checks'], 'key');
    T::ok(in_array('audience_purpose', $keys, true),
        'readiness asks explicitly why the audience may be called');

    $purposeCheck = null;
    foreach ($validation['checks'] as $check) {
        if ($check['key'] === 'audience_purpose') {
            $purposeCheck = $check;
        }
    }
    T::same('error', (string) ($purposeCheck['status'] ?? ''), 'and refuses launch until it is answered');
    T::ok(str_contains(strtolower((string) ($purposeCheck['note'] ?? '')), 'not by itself permission'),
        'and says an existing relationship is not consent to marketing');

    // Starting is refused server-side regardless of what a browser thinks.
    $start = CampaignService::act($ctx, $owner, $campaignId, 'start');
    T::ok(!$start['ok'], 'starting an unready campaign is refused');
    T::same('not_ready', (string) $start['code'], 'with a not_ready code');

    // No compliance badge anywhere.
    $json = strtolower(json_encode($validation) ?: '');
    T::ok(!str_contains($json, '"compliant"') && !str_contains($json, 'legally'),
        'readiness never claims legal compliance');
}

// ===========================================================================
T::group('12. AI refusal, confirmation and permission boundaries');
// ===========================================================================
{
    // A consequential action can never be simply "allowed".
    T::same('confirm_with_caller', \Aicountly\Api\Ai\AiClient::resolveActionMode('create_booking', ['create_booking' => 'allowed']),
        'booking set to "allowed" is forced to require caller confirmation');
    T::same('confirm_with_caller', \Aicountly\Api\Ai\AiClient::resolveActionMode('create_payment_link', ['create_payment_link' => 'allowed']),
        'sending a payment link always requires caller confirmation');

    // A non-consequential one may be allowed outright.
    T::same('allowed', \Aicountly\Api\Ai\AiClient::resolveActionMode('check_availability', ['check_availability' => 'allowed']),
        'checking availability may be allowed outright');

    // Anything not in the allowlist is denied, whatever the config says.
    T::same('denied', \Aicountly\Api\Ai\AiClient::resolveActionMode('drop_database', ['drop_database' => 'allowed']),
        'an action outside the allowlist is denied however it is configured');
    T::same('denied', \Aicountly\Api\Ai\AiClient::resolveActionMode('create_booking', []),
        'an action with no permission configured is denied');
    T::same('denied', \Aicountly\Api\Ai\AiClient::resolveActionMode('create_booking', ['create_booking' => 'nonsense']),
        'an unrecognised permission value is denied');
}

// ===========================================================================
T::group('13. Flow validation refuses to drop a caller');
// ===========================================================================
{
    $capabilities = ProviderRegistry::forCompany(scope(CMP, $owner))->capabilities();

    $missingDestination = FlowValidator::validate([
        'entry' => 'greet',
        'nodes' => ['greet' => ['type' => 'greeting']],
    ], $capabilities);
    T::ok(!$missingDestination['valid'], 'a step with nowhere to go is invalid');
    T::ok(in_array('missing_destination', array_column($missingDestination['errors'], 'code'), true),
        'and is reported as a missing destination');

    $badBranch = FlowValidator::validate([
        'entry' => 'greet',
        'nodes' => [
            'greet' => ['type' => 'greeting', 'next' => 'nowhere'],
            'bye'   => ['type' => 'end_call'],
        ],
    ], $capabilities);
    T::ok(in_array('invalid_branch', array_column($badBranch['errors'], 'code'), true),
        'a branch pointing at a step that does not exist is invalid');

    $noTimeout = FlowValidator::validate([
        'entry' => 'ask',
        'nodes' => [
            'ask' => ['type' => 'intent', 'next' => 'bye'],
            'bye' => ['type' => 'end_call'],
        ],
    ], $capabilities);
    T::ok(in_array('missing_timeout', array_column($noTimeout['errors'], 'code'), true),
        'a step that waits for the caller but never times out is invalid');

    $loop = FlowValidator::validate([
        'entry' => 'a',
        'nodes' => [
            'a' => ['type' => 'knowledge', 'next' => 'b'],
            'b' => ['type' => 'knowledge', 'next' => 'a'],
        ],
    ], $capabilities);
    T::ok(in_array('unbounded_loop', array_column($loop['errors'], 'code'), true),
        'a loop with nothing to stop it is invalid');

    $unconfirmed = FlowValidator::validate([
        'entry' => 'greet',
        'nodes' => [
            'greet' => ['type' => 'greeting', 'next' => 'book'],
            'book'  => ['type' => 'api_action', 'action' => 'create_booking', 'next' => 'bye'],
            'bye'   => ['type' => 'end_call'],
        ],
    ], $capabilities, ['create_booking' => 'confirm_with_caller']);
    T::ok(in_array('confirmation_missing', array_column($unconfirmed['errors'], 'code'), true),
        'booking with no confirmation step before it is invalid');

    $confirmed = FlowValidator::validate([
        'entry' => 'greet',
        'nodes' => [
            'greet'   => ['type' => 'greeting', 'next' => 'confirm'],
            'confirm' => ['type' => 'confirm', 'timeout_seconds' => 10, 'next' => 'book', 'timeout' => 'bye'],
            'book'    => ['type' => 'api_action', 'action' => 'create_booking', 'next' => 'bye'],
            'bye'     => ['type' => 'end_call'],
        ],
    ], $capabilities, ['create_booking' => 'confirm_with_caller']);
    T::ok($confirmed['valid'], 'the same flow WITH a confirmation step is valid');

    $unreachable = FlowValidator::validate([
        'entry' => 'greet',
        'nodes' => [
            'greet'  => ['type' => 'greeting', 'next' => 'bye'],
            'bye'    => ['type' => 'end_call'],
            'orphan' => ['type' => 'knowledge', 'next' => 'bye'],
        ],
    ], $capabilities);
    T::ok(in_array('unreachable', array_column($unreachable['warnings'], 'code'), true),
        'an unreachable step is a warning, not a blocker');

    // A capability the provider does not have.
    $noDtmf = FlowValidator::validate([
        'entry' => 'menu',
        'nodes' => [
            'menu' => ['type' => 'dtmf_input', 'timeout_seconds' => 10, 'next' => 'bye', 'timeout' => 'bye'],
            'bye'  => ['type' => 'end_call'],
        ],
    ], ['dtmf' => false]);
    T::ok(in_array('capability_unsupported', array_column($noDtmf['errors'], 'code'), true),
        'a step needing a capability the provider lacks is invalid');
}

// ===========================================================================
T::group('14. AI publishing is gated server-side');
// ===========================================================================
{
    $ctx = scope(CMP, $owner);

    $agentId = (int) Db::insert('voice_ai_agents', [
        'cmp_id' => CMP, 'name' => 'Asha', 'role' => 'Appointment assistant', 'status' => 'draft',
    ], 'ai_agent_id');

    // No flow, no guardrails: not publishable.
    AiAgentService::saveDraft($ctx, $owner, $agentId, ['languages' => ['en'], 'guardrails' => []]);
    $blocked = AiAgentService::publish($ctx, $owner, $agentId);
    T::ok(!$blocked['ok'], 'an agent with validation errors cannot be published');
    T::same('validation_failed', (string) $blocked['code'], 'and the refusal names the reason');

    $status = (string) Db::scalar('SELECT status FROM voice_ai_agents WHERE ai_agent_id = :id', ['id' => $agentId]);
    T::same('draft', $status, 'and it stays a draft');

    // A valid flow and guardrails: publishable.
    $flowId = (int) Db::insert('voice_call_flows', ['cmp_id' => CMP, 'name' => 'Asha flow'], 'flow_id');
    $flowVersionId = (int) Db::insert('voice_call_flow_versions', [
        'flow_id' => $flowId, 'cmp_id' => CMP, 'version_no' => 1, 'status' => 'published',
        'definition' => [
            'entry' => 'greet',
            'nodes' => [
                'greet'    => ['type' => 'greeting', 'next' => 'disclose'],
                'disclose' => ['type' => 'disclosure', 'next' => 'intent'],
                'intent'   => ['type' => 'intent', 'timeout_seconds' => 8, 'next' => 'handover', 'timeout' => 'voicemail'],
                'handover' => ['type' => 'handover', 'destination' => 'support'],
                'voicemail' => ['type' => 'voicemail'],
            ],
        ],
    ], 'version_id');
    Db::update('voice_call_flows', ['published_version_id' => $flowVersionId], ['flow_id' => $flowId]);

    AiAgentService::saveDraft($ctx, $owner, $agentId, [
        'languages'  => ['en'],
        'flow_id'    => $flowId,
        'guardrails' => ['silence_timeout_seconds' => 8, 'max_clarifications' => 2, 'barge_in' => true],
        'action_permissions' => ['transfer_to_human' => 'allowed', 'check_availability' => 'allowed'],
    ]);

    $published = AiAgentService::publish($ctx, $owner, $agentId);
    T::ok($published['ok'], 'an agent whose checks pass can be published');

    $versionId = (int) Db::scalar('SELECT published_version_id FROM voice_ai_agents WHERE ai_agent_id = :id', ['id' => $agentId]);
    T::ok($versionId > 0, 'and gets a published version');

    // Editing a published agent must not rewrite what is live.
    AiAgentService::saveDraft($ctx, $owner, $agentId, ['persona' => ['tone' => 'formal']]);
    $stillPublished = (int) Db::scalar('SELECT published_version_id FROM voice_ai_agents WHERE ai_agent_id = :id', ['id' => $agentId]);
    T::same($versionId, $stillPublished, 'editing after publishing leaves the published version untouched');

    $newDraft = Db::scalar('SELECT draft_version_id FROM voice_ai_agents WHERE ai_agent_id = :id', ['id' => $agentId]);
    T::ok($newDraft !== null && (int) $newDraft !== $versionId, 'and creates a separate draft');

    $publishedStatus = (string) Db::scalar('SELECT status FROM voice_ai_agent_versions WHERE version_id = :id', ['id' => $versionId]);
    T::same('published', $publishedStatus, 'the published version is still marked published');
}

// ===========================================================================
T::group('15. Rehearsals produce real results');
// ===========================================================================
{
    $ctx = scope(CMP, $owner);
    $agentId = (int) Db::scalar('SELECT ai_agent_id FROM voice_ai_agents WHERE cmp_id = :c LIMIT 1', ['c' => CMP]);

    $run = AiAgentService::rehearse($ctx, $owner, $agentId, 'asks_for_human');
    T::ok($run['ok'], 'a rehearsal runs');
    T::ok($run['run']['simulated'], 'and is labelled simulated');
    T::ok(count($run['run']['checks']) > 0, 'and produces checks');

    // No call was placed and nothing was written anywhere else.
    $callsAfter = (int) Db::scalar('SELECT COUNT(*) FROM voice_calls WHERE cmp_id = :c', ['c' => CMP]);
    $run2 = AiAgentService::rehearse($ctx, $owner, $agentId, 'api_unavailable');
    T::same($callsAfter, (int) Db::scalar('SELECT COUNT(*) FROM voice_calls WHERE cmp_id = :c', ['c' => CMP]),
        'a rehearsal places no call');

    $stored = Db::first(
        'SELECT status, checks FROM voice_ai_test_runs WHERE ai_agent_id = :id ORDER BY test_run_id DESC LIMIT 1',
        ['id' => $agentId],
    );
    T::ok(in_array((string) $stored['status'], ['passed', 'failed'], true),
        'the run is recorded with a real verdict');

    // A scenario that must fail for a badly configured agent.
    $bare = (int) Db::insert('voice_ai_agents', ['cmp_id' => CMP, 'name' => 'Bare', 'status' => 'draft'], 'ai_agent_id');
    AiAgentService::saveDraft($ctx, $owner, $bare, ['guardrails' => []]);
    $bareRun = AiAgentService::rehearse($ctx, $owner, $bare, 'unsupported_question');
    T::same('failed', (string) $bareRun['run']['status'],
        'an agent with no flow and no limits fails its rehearsal — the badge is not decorative');
}

// ===========================================================================
T::group('16. Budget and concurrency are enforced server-side');
// ===========================================================================
{
    $ctx = scope(CMP, $owner);

    Db::insert('voice_budget_policies', [
        'cmp_id' => CMP, 'scope' => 'company', 'period' => 'monthly',
        'limit_minor' => 1000, 'currency' => 'INR', 'on_exceed' => 'block', 'is_active' => true,
    ], 'policy_id');

    Db::insert('voice_usage_entries', [
        'cmp_id' => CMP, 'category' => 'voice_minutes', 'quantity' => 10,
        'unit' => 'minute', 'amount_minor' => 1500, 'currency' => 'INR', 'basis' => 'estimated',
    ], 'usage_id');

    $check = BudgetService::check($ctx);
    T::ok(!$check['allowed'], 'a call is refused once the budget is spent');
    T::same('budget_exceeded', (string) $check['reason'], 'with a budget_exceeded reason');

    $call = CallService::place($ctx, $owner, ['to' => '+919876500050']);
    T::ok(!$call['ok'], 'and CallService refuses it too — not only the dashboard');
    T::same('budget_exceeded', (string) $call['code'], 'with the same reason');

    Db::run('DELETE FROM voice_budget_policies WHERE cmp_id = :c', ['c' => CMP]);
    Db::run('DELETE FROM voice_usage_entries WHERE cmp_id = :c', ['c' => CMP]);

    // Concurrency.
    seedSettings(CMP, ['max_concurrent_calls' => 1]);
    Db::run("UPDATE voice_calls SET ended_at = NULL, state = 'answered' WHERE cmp_id = :c AND call_id = (SELECT MIN(call_id) FROM voice_calls WHERE cmp_id = :c)", ['c' => CMP]);

    $capacity = BudgetService::capacityCheck($ctx);
    T::ok(!$capacity['allowed'], 'a call is refused once every channel is in use');
    T::same('capacity_reached', (string) $capacity['reason'], 'with a capacity reason, distinct from budget');

    seedSettings(CMP, ['max_concurrent_calls' => 10]);
    Db::run("UPDATE voice_calls SET ended_at = NOW(), state = 'completed' WHERE cmp_id = :c AND ended_at IS NULL", ['c' => CMP]);
}

// ===========================================================================
T::group('17. Calling window and suppression are rechecked at dispatch');
// ===========================================================================
{
    $ctx = scope(CMP, $owner);

    CallingPolicy::suppress($ctx, '+919876500060', 'opt_out', 'test', USER);
    $suppressed = CallingPolicy::check($ctx, '+919876500060');
    T::ok(!$suppressed['allowed'], 'a suppressed number cannot be called');
    T::same('suppressed', (string) $suppressed['reason'], 'with a suppressed reason');

    $call = CallService::place($ctx, $owner, ['to' => '+919876500060']);
    T::ok(!$call['ok'], 'and CallService refuses it');

    // Suppression is idempotent.
    CallingPolicy::suppress($ctx, '+919876500060', 'opt_out', 'test', USER);
    $count = (int) Db::scalar(
        'SELECT COUNT(*) FROM voice_suppressions WHERE cmp_id = :c AND e164 = :n',
        ['c' => CMP, 'n' => '+919876500060'],
    );
    T::same(1, $count, 'suppressing twice makes one entry');

    // "Asked not to be called" adds to the list rather than labelling one call.
    $free = CallService::place($ctx, $owner, ['to' => '+919876500061']);
    CallService::disposition($ctx, $owner, (int) $free['call']['call_id'], ['disposition' => 'do_not_call']);
    T::ok(CallingPolicy::isSuppressed($ctx, '+919876500061'),
        'the "asked not to be called" outcome suppresses the number for future campaigns');

    // The window is evaluated in the company's own timezone.
    $outside = CallingPolicy::windowCheckFor(
        new \DateTimeImmutable('2026-01-05T02:00:00Z'),
        'Asia/Kolkata',
        540, 1200, [1, 2, 3, 4, 5],
    );
    T::ok(!$outside['allowed'], '07:30 local is outside a 09:00–20:00 window');

    $inside = CallingPolicy::windowCheckFor(
        new \DateTimeImmutable('2026-01-05T06:00:00Z'),
        'Asia/Kolkata',
        540, 1200, [1, 2, 3, 4, 5],
    );
    T::ok($inside['allowed'], '11:30 local is inside it');

    $wrongDay = CallingPolicy::windowCheckFor(
        new \DateTimeImmutable('2026-01-04T06:00:00Z'),
        'Asia/Kolkata',
        540, 1200, [1, 2, 3, 4, 5],
    );
    T::ok(!$wrongDay['allowed'], 'a Sunday is refused when the window is weekdays only');
}

// ===========================================================================
T::group('18. Retention deletes the recording, the transcript and the index');
// ===========================================================================
{
    $ctx = scope(CMP, $owner);
    $call = CallService::place($ctx, $owner, ['to' => '+919876500070']);
    $callId = (int) $call['call']['call_id'];

    Db::insert('voice_transcript_segments', [
        'call_id' => $callId, 'cmp_id' => CMP, 'sequence_no' => 1,
        'speaker' => 'caller', 'text' => 'delete me please',
    ], 'segment_id');

    $recordingId = (int) Db::insert('voice_recordings', [
        'recording_uuid' => Uuid::v4(), 'call_id' => $callId, 'cmp_id' => CMP,
        'storage_key' => 'test/recording.mp3', 'status' => 'available',
        'delete_after' => Clock::sql(Clock::now()->modify('-1 day')),
    ], 'recording_id');

    // A SECOND call, with a hold on it. Deliberately not the same call: a hold
    // protects the whole conversation including its transcript, so putting one
    // on the call under test would mask the thing being tested.
    $heldCall = CallService::place($ctx, $owner, ['to' => '+919876500071']);
    $heldCallId = (int) $heldCall['call']['call_id'];
    Db::insert('voice_transcript_segments', [
        'call_id' => $heldCallId, 'cmp_id' => CMP, 'sequence_no' => 1,
        'speaker' => 'caller', 'text' => 'delete me please',
    ], 'segment_id');
    $held = (int) Db::insert('voice_recordings', [
        'recording_uuid' => Uuid::v4(), 'call_id' => $heldCallId, 'cmp_id' => CMP,
        'storage_key' => 'test/held.mp3', 'status' => 'available',
        'legal_hold' => true,
        'delete_after' => Clock::sql(Clock::now()->modify('-1 day')),
    ], 'recording_id');

    // Searchable before.
    $before = (int) Db::scalar(
        "SELECT COUNT(*) FROM voice_transcript_segments
          WHERE call_id = :id AND to_tsvector('simple', text) @@ plainto_tsquery('simple', 'delete')",
        ['id' => $callId],
    );
    T::same(1, $before, 'the transcript is searchable before retention runs');

    Settings::save($ctx, ['transcript_retention_days' => 1], 'test');
    Settings::resetForTesting();
    Db::run(
        'UPDATE voice_calls SET ended_at = NOW() - INTERVAL \'3 days\' WHERE call_id IN (:id, :held)',
        ['id' => $callId, 'held' => $heldCallId],
    );

    $result = RetentionService::sweep();

    T::ok($result['recordings_deleted'] >= 1, 'the due recording is deleted');
    T::same('deleted', (string) Db::scalar('SELECT status FROM voice_recordings WHERE recording_id = :id', ['id' => $recordingId]),
        'and its row is marked deleted');
    T::same(null, Db::scalar('SELECT storage_key FROM voice_recordings WHERE recording_id = :id', ['id' => $recordingId]),
        'and its storage key is cleared');

    T::same('available', (string) Db::scalar('SELECT status FROM voice_recordings WHERE recording_id = :id', ['id' => $held]),
        'a recording on legal hold survives the sweep');

    $heldSegments = (int) Db::scalar(
        'SELECT COUNT(*) FROM voice_transcript_segments WHERE call_id = :id',
        ['id' => $heldCallId],
    );
    T::same(1, $heldSegments, 'and so does the transcript of the call it is held against');

    $after = (int) Db::scalar(
        "SELECT COUNT(*) FROM voice_transcript_segments
          WHERE call_id = :id AND to_tsvector('simple', text) @@ plainto_tsquery('simple', 'delete')",
        ['id' => $callId],
    );
    T::same(0, $after, 'the transcript is gone from the search index with the rows');

    T::ok(Db::first('SELECT 1 FROM voice_calls WHERE call_id = :id', ['id' => $callId]) !== null,
        'the call record itself survives — retention is about the recording, not the history');

    Settings::save($ctx, ['transcript_retention_days' => 0], 'test');
    Settings::resetForTesting();
}

// ===========================================================================
T::group('19. Recording access is audited and never leaks a URL');
// ===========================================================================
{
    $ctx = scope(CMP, $owner);
    $call = CallService::place($ctx, $owner, ['to' => '+919876500080']);
    $callId = (int) $call['call']['call_id'];

    $uuid = Uuid::v4();
    Db::insert('voice_recordings', [
        'recording_uuid' => $uuid, 'call_id' => $callId, 'cmp_id' => CMP,
        'storage_key' => 'test/audit.mp3', 'status' => 'available', 'duration_seconds' => 120,
    ], 'recording_id');

    $result = \Aicountly\Api\Domain\RecordingService::playback($ctx, $owner, $uuid, false);
    T::ok($result['ok'], 'playback is granted to somebody who may listen');
    T::ok(str_contains((string) $result['playback']['url'], 'signature='), 'and the URL is signed');
    T::ok(str_contains((string) $result['playback']['url'], 'expires='), 'and carries an expiry');

    $audit = Db::first(
        'SELECT action, detail FROM voice_audit_events
          WHERE cmp_id = :c AND entity_id = :e ORDER BY audit_id DESC LIMIT 1',
        ['c' => CMP, 'e' => $uuid],
    );
    T::same('voice.recording.played', (string) $audit['action'], 'the access is audited');

    $detail = json_encode(Db::jsonColumn($audit['detail'])) ?: '';
    T::ok(!str_contains($detail, 'signature'), 'and the audit row contains no signature');
    T::ok(!str_contains($detail, 'http'), 'and no URL');

    // The listing never carries a URL either.
    $presented = json_encode(\Aicountly\Api\Domain\RecordingService::present(
        Db::first('SELECT * FROM voice_recordings WHERE recording_uuid = :u', ['u' => $uuid]) ?? [],
    )) ?: '';
    T::ok(!str_contains($presented, 'http') && !str_contains($presented, 'storage_key'),
        'a recording listing exposes neither a URL nor a storage key');
}

// ===========================================================================
T::group('20. Commitments stay suggestions until a person confirms');
// ===========================================================================
{
    $ctx = scope(CMP, $owner);
    $call = CallService::place($ctx, $owner, ['to' => '+919876500090']);
    $callId = (int) $call['call']['call_id'];

    $commitmentId = (int) Db::insert('voice_commitments', [
        'call_id' => $callId, 'cmp_id' => CMP, 'party' => 'business',
        'description' => 'Send the revised quotation',
        'due_text' => 'by Friday',
        'evidence' => [1, 2],
        'status' => 'suggested',
    ], 'commitment_id');

    $presented = CommitmentService::present(
        Db::first('SELECT * FROM voice_commitments WHERE commitment_id = :id', ['id' => $commitmentId]) ?? [],
    );
    T::same('suggested', $presented['status'], 'a found commitment starts as a suggestion');
    T::same('needs_clarification', $presented['due_state'],
        'an unsettled date is reported as needing clarification, not guessed');
    T::same(null, $presented['external_task_ref'], 'and no task exists anywhere yet');

    // Confirming creates the task in CRM.
    stubReset();
    $confirmed = CommitmentService::confirm($ctx, $owner, $commitmentId, [
        'due_at' => Clock::iso(Clock::now()->modify('+2 days')),
    ]);
    T::ok($confirmed['ok'], 'confirming creates the task in CRM');
    T::same('stub-task-1', (string) $confirmed['commitment']['external_task_ref'],
        'and stores CRM’s reference');

    // With CRM down, the confirmation stands but nothing is invented.
    $second = (int) Db::insert('voice_commitments', [
        'call_id' => $callId, 'cmp_id' => CMP, 'description' => 'Call back Monday', 'status' => 'suggested',
    ], 'commitment_id');
    stubMode('crm', 'down');
    $degraded = CommitmentService::confirm($ctx, $owner, $second, []);
    T::ok(!$degraded['ok'], 'with CRM down the task is not reported as created');
    T::same(null, $degraded['commitment']['external_task_ref'], 'and no task reference is invented');
    T::same('confirmed', (string) $degraded['commitment']['status'],
        'while the commitment is still confirmed in Voice, which is Voice’s own record');
    stubReset();
}

// ===========================================================================
T::group('21. Company switch clears prior tenant data');
// ===========================================================================
{
    // The backend half of the guarantee: a scope is verified per session, and a
    // second company does not inherit the first one's verdict.
    Context::resetForTesting();
    Permissions::forget();

    $ctxA = Context::forCompany(CMP);
    Context::trustForTesting(CMP, $owner);
    $permissionsA = Permissions::granted($ctxA, $owner);

    $ctxB = Context::forCompany(OTHER_CMP);
    // Deliberately NOT trusted — the real Manage check must run.
    $reached = true;
    try {
        $ctxB->assertAllowed($owner);
    } catch (\Aicountly\Api\ResponseSent $e) {
        $reached = false;
    }
    T::ok($reached, 'a company the stub allows is verified on its own, not inherited');

    Context::resetForTesting();
    $ctxForbidden = Context::forCompany(999);
    $refused = false;
    try {
        $ctxForbidden->assertAllowed($owner);
    } catch (\Aicountly\Api\ResponseSent $e) {
        $refused = $e->status === 403;
    }
    T::ok($refused, 'switching to a company the session may not open is refused, not silently empty');

    Context::trustForTesting(CMP, $owner);
    Context::trustForTesting(OTHER_CMP, $owner);
}

// ===========================================================================
T::group('22. Credentials are encrypted and never returned');
// ===========================================================================
{
    $sealed = Crypto::sealCredentials(['api_key' => 'super-secret-value', 'signing_secret' => 'also-secret']);
    T::ok(!str_contains($sealed, 'super-secret-value'), 'a sealed credential does not contain the plaintext');

    $opened = Crypto::openCredentials($sealed);
    T::same('super-secret-value', (string) ($opened['api_key'] ?? ''), 'and round-trips correctly');

    $described = Crypto::describe($sealed);
    T::ok($described['configured'], 'describe() says a credential is configured');
    T::same(['api_key', 'signing_secret'], $described['fields'], 'and names the fields');
    $json = json_encode($described) ?: '';
    T::ok(!str_contains($json, 'super-secret'), 'but exposes no part of any value');

    // Tampering must fail closed.
    $tampered = substr($sealed, 0, -4) . 'AAAA';
    T::same(null, Crypto::decrypt($tampered), 'an altered ciphertext does not decrypt');

    // The inventory a screen reads must not carry the encrypted blob either.
    $inventory = ProviderRegistry::inventory(scope(CMP, $owner), false);
    $inventoryJson = json_encode($inventory) ?: '';
    T::ok(!str_contains($inventoryJson, 'credentials_enc'), 'the connection inventory omits the encrypted blob');
    T::ok(!str_contains($inventoryJson, 'stub-signing-secret'), 'and contains no secret value');
}

// ===========================================================================
T::group('23. Usage keeps estimated and confirmed apart');
// ===========================================================================
{
    $ctx = scope(CMP, $owner);
    Db::run('DELETE FROM voice_usage_entries WHERE cmp_id = :c', ['c' => CMP]);

    Db::insert('voice_usage_entries', [
        'cmp_id' => CMP, 'category' => 'voice_minutes', 'quantity' => 10, 'unit' => 'minute',
        'amount_minor' => 1200, 'currency' => 'INR', 'basis' => 'estimated',
    ], 'usage_id');
    Db::insert('voice_usage_entries', [
        'cmp_id' => CMP, 'category' => 'voice_minutes', 'quantity' => 10, 'unit' => 'minute',
        'amount_minor' => 1350, 'currency' => 'INR', 'basis' => 'provider_confirmed',
        'provider_ref' => 'inv-1', 'connection_id' => (int) Db::scalar('SELECT connection_id FROM voice_provider_connections WHERE cmp_id = :c LIMIT 1', ['c' => CMP]),
    ], 'usage_id');

    $breakdown = \Aicountly\Api\Domain\UsageService::breakdown(
        $ctx,
        Clock::iso(Clock::now()->modify('-1 day')),
        Clock::iso(Clock::now()->modify('+1 day')),
    );

    T::same(1200, $breakdown['totals']['estimated_minor'], 'the estimated total is reported on its own');
    T::same(1350, $breakdown['totals']['confirmed_minor'], 'and the confirmed total on its own');
    T::ok(!isset($breakdown['totals']['total_minor']), 'there is no combined total that would double-count');
    T::ok($breakdown['note'] !== null, 'and the panel carries a note explaining why');
}

// ===========================================================================
T::group('24. No cross-app database access exists');
// ===========================================================================
{
    // A structural check, not a stylistic one: these are the things that would
    // make the live-API rule untrue.
    $srcFiles = [];
    $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator(__DIR__ . '/../src'));
    foreach ($iterator as $file) {
        if ($file->isFile() && str_ends_with((string) $file->getFilename(), '.php')) {
            $srcFiles[] = (string) $file->getPathname();
        }
    }

    $offenders = [];
    foreach ($srcFiles as $path) {
        $source = (string) file_get_contents($path);
        foreach ([
            'dblink'                 => 'PostgreSQL dblink',
            'postgres_fdw'           => 'a foreign data wrapper',
            'CREATE SERVER'          => 'a foreign server',
            'books_'                 => 'another product’s table prefix',
            'contacts_'              => 'another product’s table prefix',
            'calendar_events'        => 'another product’s table',
        ] as $needle => $what) {
            if (str_contains($source, $needle)) {
                $offenders[] = basename($path) . ' contains ' . $what;
            }
        }
    }
    T::same([], $offenders, 'no source file reaches into another product’s database');

    // Every table this product owns is prefixed voice_.
    $tables = Db::all("SELECT tablename FROM pg_tables WHERE schemaname = 'public' ORDER BY 1");
    $foreign = array_values(array_filter(
        array_map(static fn (array $r): string => (string) $r['tablename'], $tables),
        static fn (string $name): bool => !str_starts_with($name, 'voice_'),
    ));
    T::same([], $foreign, 'every table in the schema belongs to Voice');

    // And there is exactly one database connection in the codebase.
    $connectCount = 0;
    foreach ($srcFiles as $path) {
        $connectCount += substr_count((string) file_get_contents($path), 'new PDO(');
    }
    T::same(1, $connectCount, 'there is exactly one PDO connection, in Db.php');
}

exit(T::summary());
