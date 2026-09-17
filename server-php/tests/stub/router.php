<?php

declare(strict_types=1);

/**
 * A stand-in for Manage, Contacts, Calendar, CRM and the voice gateway.
 *
 * The test suite must never call a real product. This answers the handful of
 * endpoints Voice actually uses, and — more usefully — can be told to FAIL, so
 * the tests can check what Voice does when Calendar is down, when Contacts
 * times out, and when a write's outcome is genuinely unknown.
 *
 * Behaviour is driven by files in tests/stub/state/, written by the test:
 *
 *   calendar=down      → Calendar answers 503
 *   calendar=timeout   → Calendar answers 500 with no body (an unknown outcome)
 *   contacts=down      → Contacts answers 503
 *   crm=down           → CRM answers 503
 *
 * Run with: php -S 127.0.0.1:<port> tests/stub/router.php
 */

$stateDir = __DIR__ . '/state';
@mkdir($stateDir, 0777, true);

function stub_mode(string $service): string
{
    $path = __DIR__ . '/state/' . $service;

    return is_readable($path) ? trim((string) file_get_contents($path)) : 'up';
}

function stub_json(int $status, array $payload): never
{
    http_response_code($status);
    header('Content-Type: application/json');
    echo json_encode($payload);
    exit;
}

$path = (string) (parse_url((string) ($_SERVER['REQUEST_URI'] ?? '/'), PHP_URL_PATH) ?: '/');
$path = trim($path, '/');

// ApiClient::apiRoot() omits the /api segment for a localhost origin (the
// fleet's convention for a local spark server) and includes it for a real
// host. The stub is reached both ways, so it normalises to the bare form and
// every route below is written without the prefix.
if (str_starts_with($path, 'api/')) {
    $path = substr($path, 4);
}
$method = strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET'));
$body = json_decode((string) file_get_contents('php://input'), true) ?: [];

// ---------------------------------------------------------------------------
// Health — used by the integrations probe
// ---------------------------------------------------------------------------
if ($path === 'health') {
    stub_json(200, ['status' => 'ok', 'stub' => true]);
}

// ---------------------------------------------------------------------------
// Manage — the tenant check
// ---------------------------------------------------------------------------
if (str_starts_with($path, 'companyinfo')) {
    $cmpId = (int) ($_GET['comp_id'] ?? 0);

    // Company 999 is the one this session may NOT open — that is what the
    // tenant-isolation test uses.
    if ($cmpId === 999) {
        stub_json(403, ['message' => 'No access to that company.']);
    }

    stub_json(200, ['data' => ['cmp_id' => $cmpId, 'name' => 'Stub Company ' . $cmpId]]);
}

// ---------------------------------------------------------------------------
// Contacts
// ---------------------------------------------------------------------------
if (str_starts_with($path, 'contacts')) {
    if (stub_mode('contacts') === 'down') {
        stub_json(503, ['message' => 'Contacts is unavailable.']);
    }

    $segments = explode('/', $path);
    $ref = $segments[1] ?? '';

    if ($method === 'POST') {
        stub_json(201, ['data' => ['contact_uuid' => 'stub-contact-created', 'mobile' => '+919876500011']]);
    }

    if ($ref !== '') {
        // 'missing' has no number, which is a different outcome from the
        // directory being down.
        if ($ref === 'missing') {
            stub_json(200, ['data' => ['contact_uuid' => $ref, 'name' => 'No Number']]);
        }

        stub_json(200, ['data' => [
            'contact_uuid' => $ref,
            'name'         => 'Stub Contact',
            'mobile'       => '+919876500011',
        ]]);
    }

    stub_json(200, ['data' => [
        ['contact_uuid' => 'stub-1', 'name' => 'Stub One', 'mobile' => '+919876500011'],
    ], 'meta' => ['total' => 1]]);
}

// ---------------------------------------------------------------------------
// Calendar
// ---------------------------------------------------------------------------
if (str_starts_with($path, 'calendar/events')) {
    $mode = stub_mode('calendar');

    if ($mode === 'down') {
        stub_json(503, ['message' => 'Calendar is unavailable.']);
    }

    if ($mode === 'timeout') {
        // A 500 with no useful body: the caller cannot tell whether the event
        // was created. This is the case ExternalOperations calls UNKNOWN.
        http_response_code(500);
        exit;
    }

    if ($method === 'POST') {
        stub_json(201, ['data' => [
            'event_uuid'     => 'stub-event-' . substr(hash('sha256', (string) ($body['correlation_id'] ?? '')), 0, 12),
            'correlation_id' => $body['correlation_id'] ?? null,
        ]]);
    }

    // The reconciliation read. 'reconcile-yes' is a correlation id the owner
    // turns out to have; anything else it does not.
    $correlationId = (string) ($_GET['correlation_id'] ?? '');
    if (str_contains($correlationId, 'reconcile-yes')) {
        stub_json(200, ['data' => [['event_uuid' => 'stub-event-reconciled', 'correlation_id' => $correlationId]]]);
    }

    stub_json(200, ['data' => []]);
}

// ---------------------------------------------------------------------------
// CRM
// ---------------------------------------------------------------------------
if (str_starts_with($path, 'tasks')) {
    if (stub_mode('crm') === 'down') {
        stub_json(503, ['message' => 'CRM is unavailable.']);
    }

    if ($method === 'POST') {
        stub_json(201, ['data' => ['task_uuid' => 'stub-task-1']]);
    }

    stub_json(200, ['data' => []]);
}

if (str_starts_with($path, 'leads')) {
    if (stub_mode('crm') === 'down') {
        stub_json(503, ['message' => 'CRM is unavailable.']);
    }
    stub_json(200, ['data' => ['lead_uuid' => 'stub-lead-1', 'phone' => '+919876500022']]);
}

// ---------------------------------------------------------------------------
// Voice gateway
// ---------------------------------------------------------------------------
if ($path === 'capabilities') {
    // Deliberately NOT everything. `attended_transfer` and `monitor_barge` are
    // false so the capability-gating tests have something real to assert on.
    stub_json(200, ['capabilities' => [
        'place_call' => true, 'receive_call' => true, 'end_call' => true,
        'mute' => true, 'hold' => true, 'dtmf' => true,
        'blind_transfer' => true, 'attended_transfer' => false,
        'recording' => true, 'recording_pause' => true,
        'monitor_listen' => true, 'monitor_whisper' => false, 'monitor_barge' => false,
        'browser_calling' => true, 'live_transcript' => true, 'tts_playback' => true,
        'number_management' => false, 'usage_retrieval' => true,
        'signed_webhooks' => true, 'preconnect_failover' => true,
    ]]);
}

if ($path === 'calls' && $method === 'POST') {
    $mode = stub_mode('gateway');

    if ($mode === 'down') {
        http_response_code(500);
        exit;
    }
    if ($mode === 'refuse') {
        stub_json(400, ['error' => ['code' => 'invalid_destination', 'message' => 'That number is not routable.']]);
    }
    if ($mode === 'no_ref') {
        // Accepted with no reference — treated as unknown, not success.
        stub_json(201, ['data' => ['accepted' => true]]);
    }

    stub_json(201, ['data' => [
        'call_ref'       => 'stub-call-' . substr(hash('sha256', (string) ($body['correlation_id'] ?? '')), 0, 10),
        'correlation_id' => $body['correlation_id'] ?? null,
    ]]);
}

if (preg_match('#^calls/([^/]+)/commands$#', $path, $m) === 1) {
    stub_json(200, ['data' => ['accepted' => true, 'command' => $body['command'] ?? null]]);
}

if ($path === 'usage') {
    stub_json(200, ['entries' => [
        ['provider_ref' => 'stub-usage-1', 'category' => 'voice_minutes',
         'quantity' => 10, 'unit' => 'minute', 'amount_minor' => 1500,
         'currency' => 'INR', 'occurred_at' => gmdate('c')],
    ]]);
}

stub_json(404, ['message' => 'Stub has no route for /' . $path]);
