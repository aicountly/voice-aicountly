<?php

declare(strict_types=1);

/**
 * Seed data for a UI walkthrough.
 *
 * NOT a demo mode and NOT shipped to production: this is a script in tests/
 * that writes rows into the throwaway test database so the screens can be
 * looked at with something in them.
 *
 * The production app has no fixtures. Every screen reads the API, and an empty
 * company renders empty states — which is the correct thing for it to do.
 */

namespace Aicountly\Api;

use Aicountly\Api\Support\Clock;
use Aicountly\Api\Support\Uuid;

require __DIR__ . '/../src/Autoload.php';
require __DIR__ . '/support.php';

Env::load(__DIR__ . '/../.env');

const CMP = 4001;

Tests\truncateAll();

$auth = Auth::forTesting('stub-user-uuid', 'user', 'voice', ['acs_type' => 1]);
Auth::adopt($auth);
$ctx = Context::forCompany(CMP);
Context::trustForTesting(CMP, $auth);

Tests\seedSettings(CMP, [
    'timezone' => 'Asia/Kolkata',
    'recording_policy' => 'always',
    'max_concurrent_calls' => 80,
]);

$connectionId = Tests\seedConnection(CMP);
Db::insert('voice_provider_connections', [
    'cmp_id' => CMP, 'provider' => 'gateway', 'label' => 'Backup SIP',
    'role' => 'backup', 'max_concurrent' => 40, 'is_active' => true,
    'credentials_enc' => Crypto::sealCredentials(['signing_secret' => 'stub']),
    'config' => [],
], 'connection_id');

// Teams, numbers, queues
$teams = [];
foreach (['Sales', 'Support', 'Billing'] as $name) {
    $teams[$name] = (int) Db::insert('voice_teams', ['cmp_id' => CMP, 'name' => $name], 'team_id');
}

$flows = [];
foreach (['Sales IVR', 'Support IVR', 'Billing IVR'] as $name) {
    $flowId = (int) Db::insert('voice_call_flows', ['cmp_id' => CMP, 'name' => $name], 'flow_id');
    $versionId = (int) Db::insert('voice_call_flow_versions', [
        'flow_id' => $flowId, 'cmp_id' => CMP, 'version_no' => 1, 'status' => 'published',
        'definition' => [
            'entry' => 'greet',
            'nodes' => [
                'greet'    => ['type' => 'greeting', 'label' => 'Welcome and set context', 'next' => 'disclose'],
                'disclose' => ['type' => 'disclosure', 'label' => 'Recording disclosure', 'next' => 'intent'],
                'intent'   => ['type' => 'intent', 'label' => 'Understand what the caller needs',
                               'timeout_seconds' => 8, 'next' => 'check', 'timeout' => 'voicemail'],
                'check'    => ['type' => 'api_action', 'label' => 'Check availability',
                               'action' => 'check_availability', 'next' => 'confirm', 'on_failure' => 'handover'],
                'confirm'  => ['type' => 'confirm', 'label' => 'Repeat the details back',
                               'timeout_seconds' => 10, 'next' => 'book', 'timeout' => 'handover'],
                'book'     => ['type' => 'api_action', 'label' => 'Book through Calendar',
                               'action' => 'create_booking', 'next' => 'recap', 'on_failure' => 'handover'],
                'recap'    => ['type' => 'knowledge', 'label' => 'Recap the confirmed outcome', 'next' => 'bye'],
                'handover' => ['type' => 'handover', 'label' => 'Escalate to a team member', 'destination' => 'Support'],
                'voicemail' => ['type' => 'voicemail', 'label' => 'Take a message'],
                'bye'      => ['type' => 'end_call', 'label' => 'End the call'],
            ],
        ],
        'validation' => ['valid' => true, 'errors' => [], 'warnings' => []],
        'published_at' => Clock::sql(Clock::now()),
    ], 'version_id');
    Db::update('voice_call_flows', ['published_version_id' => $versionId], ['flow_id' => $flowId]);
    $flows[$name] = $flowId;
}

$numbers = [
    ['+918066004401', 'Sales',   'Sales IVR'],
    ['+918066007723', 'Support', 'Support IVR'],
    ['+918066001189', 'Billing', 'Billing IVR'],
];
foreach ($numbers as [$e164, $team, $flow]) {
    Db::insert('voice_numbers', [
        'cmp_id' => CMP, 'connection_id' => $connectionId, 'e164' => $e164,
        'label' => $team . ' line', 'team_id' => $teams[$team], 'inbound_flow_id' => $flows[$flow],
        'is_active' => true, 'routing_status' => 'active',
    ], 'number_id');
}

$queues = [];
foreach ([['Billing', 4], ['Sales', 2], ['Support', 1]] as [$name, $_waiting]) {
    $queues[$name] = (int) Db::insert('voice_queues', [
        'cmp_id' => CMP, 'name' => $name, 'strategy' => 'longest_idle',
        'ring_seconds' => 20, 'timeout_seconds' => 120,
        'overflow_type' => 'voicemail', 'is_active' => true,
    ], 'queue_id');
}

// Agents
$agents = [];
foreach ([
    ['agent-arjun', '1001', 'available'],
    ['agent-priya', '1002', 'busy'],
    ['agent-daniel', '1003', 'available'],
    ['agent-fatima', '1004', 'available'],
    ['agent-rohan', '1005', 'wrap_up'],
] as $index => [$uuid, $extension, $presence]) {
    $agents[] = (int) Db::insert('voice_agents', [
        'cmp_id' => CMP, 'user_uuid' => $uuid, 'extension' => $extension,
        'voice_role' => $index === 0 ? 'supervisor' : 'agent',
        'skills' => ['billing', 'sales'], 'languages' => ['en', 'hi'],
        'presence' => $presence, 'is_active' => true,
        'presence_since' => Clock::sql(Clock::now()->modify('-20 minutes')),
        'presence_expires_at' => Clock::sql(Clock::now()->modify('+5 minutes')),
    ], 'agent_id');
}

// Calls across the day, so the by-hour chart and the outcome mix have shape.
$dispositions = Db::all('SELECT disposition_id, code, category FROM voice_dispositions WHERE cmp_id = 0');
$byCode = [];
foreach ($dispositions as $row) {
    $byCode[$row['code']] = (int) $row['disposition_id'];
}

$outcomes = [
    ['ai_completed', 'resolved', 'ai'],
    ['human_completed', 'resolved', 'human'],
    ['handover_completed', 'follow_up', 'both'],
    ['no_answer', 'no_answer', 'unassigned'],
    ['voicemail', 'voicemail_left', 'unassigned'],
];

$callIds = [];
for ($i = 0; $i < 140; $i++) {
    [$outcome, $code, $handled] = $outcomes[$i % count($outcomes)];
    $hoursAgo = ($i % 20) + 1;
    $started = Clock::now()->modify('-' . $hoursAgo . ' hours')->modify('-' . ($i % 50) . ' minutes');
    $answered = in_array($outcome, ['no_answer'], true) ? null : $started->modify('+12 seconds');
    $talk = $answered === null ? 0 : 60 + (($i * 37) % 420);

    $callIds[] = (int) Db::insert('voice_calls', [
        'call_uuid' => Uuid::v4(),
        'cmp_id' => CMP,
        'connection_id' => $connectionId,
        'direction' => $i % 3 === 0 ? 'outbound' : 'inbound',
        'origin' => 'AGENT_CONSOLE',
        'remote_e164' => '+9198765' . str_pad((string) (10000 + $i), 5, '0', STR_PAD_LEFT),
        'local_e164' => $numbers[$i % 3][0],
        'contact_ref' => $i % 4 === 0 ? 'stub-contact-' . $i : null,
        'queue_id' => $queues[array_keys($queues)[$i % 3]],
        'owner_agent_id' => $handled === 'unassigned' ? null : $agents[$i % count($agents)],
        'handled_by' => $handled,
        'state' => $outcome === 'no_answer' ? 'unanswered' : 'completed',
        'outcome' => $outcome,
        'abandoned' => $outcome === 'no_answer' && $i % 7 === 0,
        'recording_state' => $answered === null ? 'none' : 'stored',
        'consent_state' => $answered === null ? 'not_applicable' : 'announced',
        'language' => $i % 5 === 0 ? 'hi' : 'en',
        'disposition_id' => $byCode[$code] ?? null,
        'initiated_at' => Clock::sql($started),
        'answered_at' => $answered === null ? null : Clock::sql($answered),
        'ended_at' => Clock::sql($started->modify('+' . ($talk + 20) . ' seconds')),
        'talk_seconds' => $talk,
        'total_seconds' => $talk + 20,
    ], 'call_id');
}

// Live calls, so Live Operations has something in it.
foreach ([['answered', 'Billing'], ['queued', 'Sales'], ['ringing', 'Support'], ['answered', 'Billing']] as $index => [$state, $queue]) {
    Db::insert('voice_calls', [
        'call_uuid' => Uuid::v4(), 'cmp_id' => CMP, 'connection_id' => $connectionId,
        'direction' => 'inbound', 'origin' => 'AGENT_CONSOLE',
        'remote_e164' => '+91982004' . str_pad((string) (821 + $index), 4, '0', STR_PAD_LEFT),
        'local_e164' => $numbers[$index % 3][0],
        'queue_id' => $queues[$queue],
        'owner_agent_id' => $state === 'answered' ? $agents[$index % count($agents)] : null,
        'handled_by' => $state === 'answered' ? 'human' : 'unassigned',
        'state' => $state,
        'recording_state' => $state === 'answered' ? 'recording' : 'none',
        'consent_state' => 'announced',
        'language' => 'en',
        'initiated_at' => Clock::sql(Clock::now()->modify('-' . (60 + $index * 42) . ' seconds')),
        'answered_at' => $state === 'answered' ? Clock::sql(Clock::now()->modify('-' . (40 + $index * 20) . ' seconds')) : null,
    ], 'call_id');
}

// Transcript, recording and commitments on one call, for Intelligence.
$evidenceCall = $callIds[0];
$segments = [
    ['caller', 0, 'We are generally happy with the proposal, but I wanted to confirm a few things around pricing and delivery.'],
    ['agent', 8000, 'Of course. Let me take you through the pricing first.'],
    ['caller', 21000, 'Please send the revised quotation by Friday.'],
    ['agent', 28000, 'I will share the revised quotation by Friday and include the updated delivery timelines.'],
    ['caller', 41000, 'Great, thank you. Once we have that, we can move this internally for approval.'],
];
foreach ($segments as $index => [$speaker, $startedMs, $text]) {
    Db::insert('voice_transcript_segments', [
        'call_id' => $evidenceCall, 'cmp_id' => CMP, 'sequence_no' => $index + 1,
        'speaker' => $speaker, 'started_ms' => $startedMs, 'ended_ms' => $startedMs + 6000,
        'is_final' => true, 'language' => 'en', 'text' => $text, 'confidence' => 0.93,
    ], 'segment_id');
}

Db::insert('voice_recordings', [
    'recording_uuid' => Uuid::v4(), 'call_id' => $evidenceCall, 'cmp_id' => CMP,
    'storage_key' => 'demo/quote-followup.mp3', 'status' => 'available',
    'duration_seconds' => 378,
], 'recording_id');

Db::insert('voice_commitments', [
    'call_id' => $evidenceCall, 'cmp_id' => CMP, 'party' => 'business',
    'description' => 'Send the revised quotation',
    'due_text' => 'by Friday', 'evidence' => [3, 4], 'confidence' => 0.88,
    'status' => 'suggested',
], 'commitment_id');

Db::insert('voice_commitments', [
    'call_id' => $callIds[1], 'cmp_id' => CMP, 'party' => 'business',
    'description' => 'Call back about the renewal',
    'due_at' => Clock::sql(Clock::now()->modify('-2 days')),
    'status' => 'confirmed', 'evidence' => [1],
], 'commitment_id');

// Callbacks, including overdue ones so the briefing has something to say.
for ($i = 0; $i < 18; $i++) {
    Db::insert('voice_callbacks', [
        'cmp_id' => CMP,
        'source_call_id' => $callIds[$i + 3] ?? null,
        'e164' => '+9198761' . str_pad((string) (20000 + $i), 5, '0', STR_PAD_LEFT),
        'reason' => $i % 2 === 0 ? 'Missed call from a high-value lead' : 'Customer asked to be rung back',
        'priority' => $i < 4 ? 'high' : 'normal',
        'due_at' => Clock::sql(Clock::now()->modify(($i < 5 ? '-' : '+') . (($i % 6) + 1) . ' hours')),
        'status' => 'open',
        'assigned_agent_id' => $agents[$i % count($agents)],
    ], 'callback_id');
}

// Campaigns
$campaignId = (int) Db::insert('voice_campaigns', [
    'cmp_id' => CMP, 'name' => 'Demo follow-ups', 'mode' => 'preview', 'status' => 'running',
    'connection_id' => $connectionId, 'timezone' => 'Asia/Kolkata',
    'window_start_min' => 600, 'window_end_min' => 1080, 'window_days' => [1, 2, 3, 4, 5],
    'max_concurrent' => 5, 'calls_per_minute' => 20,
    'script' => ['body' => 'Follow up with customers who requested a demo.', 'reviewed' => true,
                 'audience_purpose' => 'Customers who requested a demo and asked us to follow up'],
    'started_at' => Clock::sql(Clock::now()->modify('-3 days')),
], 'campaign_id');

Db::insert('voice_campaigns', [
    'cmp_id' => CMP, 'name' => 'Renewal conversations', 'mode' => 'ai_conversation', 'status' => 'draft',
    'timezone' => 'Asia/Kolkata', 'window_start_min' => 600, 'window_end_min' => 1080,
    'window_days' => [1, 2, 3, 4, 5], 'script' => [],
], 'campaign_id');

for ($i = 0; $i < 120; $i++) {
    $refId = (int) Db::insert('voice_campaign_audience_refs', [
        'campaign_id' => $campaignId, 'cmp_id' => CMP,
        'source' => 'contacts', 'external_ref' => 'stub-contact-' . $i,
        'resolution' => 'resolved',
    ], 'audience_ref_id');

    Db::insert('voice_campaign_attempts', [
        'campaign_id' => $campaignId, 'audience_ref_id' => $refId, 'cmp_id' => CMP,
        'attempt_no' => 1,
        'status' => $i < 86 ? 'completed' : ($i < 100 ? 'no_answer' : 'queued'),
        'dialled_e164' => '+9198765' . str_pad((string) (30000 + $i), 5, '0', STR_PAD_LEFT),
        'created_at' => Clock::sql(Clock::now()->modify('-' . (($i % 3) + 1) . ' days')),
    ], 'attempt_id');
}

// AI agent, with a real rehearsal behind its badge.
$aiAgentId = (int) Db::insert('voice_ai_agents', [
    'cmp_id' => CMP, 'name' => 'Asha', 'role' => 'Appointment Assistant',
    'description' => 'Helps callers check availability, book appointments and answer common questions.',
    'status' => 'draft',
], 'ai_agent_id');

Domain\AiAgentService::saveDraft($ctx, $auth, $aiAgentId, [
    'persona' => ['name' => 'Asha', 'tone' => 'Warm and professional'],
    'languages' => ['en', 'hi'],
    'flow_id' => $flows['Sales IVR'],
    'guardrails' => ['silence_timeout_seconds' => 8, 'max_clarifications' => 2, 'barge_in' => true],
    'action_permissions' => [
        'check_availability' => 'allowed',
        'create_booking' => 'confirm_with_caller',
        'transfer_to_human' => 'allowed',
        'lookup_contact' => 'allowed',
    ],
]);

foreach (['date_change_mid_sentence', 'asks_for_human', 'api_unavailable'] as $scenario) {
    Domain\AiAgentService::rehearse($ctx, $auth, $aiAgentId, $scenario);
}

// Usage and budget
foreach (['voice_minutes' => 218000, 'ai_processing' => 82000, 'number_rental' => 24000] as $category => $amount) {
    Db::insert('voice_usage_entries', [
        'cmp_id' => CMP, 'connection_id' => $connectionId, 'category' => $category,
        'quantity' => 100, 'unit' => 'minute', 'rate_minor' => 120,
        'amount_minor' => $amount, 'currency' => 'INR', 'basis' => 'estimated',
        'occurred_at' => Clock::sql(Clock::now()->modify('-2 hours')),
    ], 'usage_id');
}

Db::insert('voice_budget_policies', [
    'cmp_id' => CMP, 'scope' => 'company', 'period' => 'monthly',
    'limit_minor' => 3000000, 'currency' => 'INR', 'warn_percent' => 80,
    'on_exceed' => 'warn', 'is_active' => true,
], 'policy_id');

Db::insert('voice_usage_entries', [
    'cmp_id' => CMP, 'connection_id' => $connectionId, 'category' => 'voice_minutes',
    'quantity' => 900, 'unit' => 'minute', 'amount_minor' => 1518000,
    'currency' => 'INR', 'basis' => 'estimated',
    'occurred_at' => Clock::sql(Clock::now()->modify('-10 days')),
], 'usage_id');

echo "Seeded company " . CMP . ": " . count($callIds) . " calls, 18 callbacks, 2 campaigns, 1 AI agent.\n";
