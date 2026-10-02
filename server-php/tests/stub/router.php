<?php

declare(strict_types=1);

/**
 * A stand-in for Manage, Contacts, Calendar, Appointments, CRM and the voice gateway.
 *
 * The test suite must never call a real product. This answers the handful of
 * endpoints Voice actually uses, and — more usefully — can be told to FAIL, so
 * the tests can check what Voice does when Calendar is down, when Contacts
 * times out, and when a write's outcome is genuinely unknown.
 *
 * Behaviour is driven by files in tests/stub/state/, written by the test:
 *
 *   calendar=…         → see calendar_v1.php: down, timeout, commit_then_drop,
 *                        in_progress, schema_not_ready, reject_key, pre_v1
 *   appointments=…     → see appointments_v1.php: down, timeout, drop_once,
 *                        commit_then_drop, reject_key, lookup_down, calendar_down
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
    // Calendar v1 reports its contract version here (and in a header); a
    // pre-v1 Calendar does not, which is what its switch reproduces.
    if (stub_mode('calendar') === 'pre_v1') {
        stub_json(200, ['status' => 'ok', 'stub' => true]);
    }
    header('X-Calendar-Contract: 1');
    stub_json(200, ['status' => 'ok', 'stub' => true, 'contract_version' => 1, 'success' => true,
        'data' => ['status' => 'ok', 'contract_version' => 1]]);
}

// ---------------------------------------------------------------------------
// Portal — my.aicountly.com's auth surface
// ---------------------------------------------------------------------------
// Stands in for the portal so the app can be exercised without a real sign-in.
// It authenticates nothing: it hands back a fixed session for any bearer token.
// That is fine here and would be catastrophic anywhere else, which is why it
// lives in tests/ and is reachable only through PORTAL_AUTH_BASE.
if ($path === 'seskey' || $path === 'seskey/refresh') {
    stub_json(200, ['status' => 1, 'ses_key' => 'stub-ses-key', 'expires_in' => 900]);
}

if ($path === 'validatesession') {
    // The REAL contract (my-aicountly-com AuthController::validateSession):
    // status, an INTEGER uuid_aictly and the key — no name, no e-mail and no
    // acs_type. Ownership is Manage's to say, in companyinfo below.
    stub_json(200, ['status' => 1, 'uuid_aictly' => 101, 'ses_key' => 'stub-ses-key']);
}

if ($path === 'companies') {
    stub_json(200, ['data' => [
        ['cmp_id' => 4001, 'name' => 'Acme Enterprises', 'is_creator' => 1],
        ['cmp_id' => 4002, 'name' => 'Northwind Services', 'is_creator' => 1],
    ]]);
}

// ---------------------------------------------------------------------------
// Manage — the tenant check, in Manage's real companyinfo shape
// (manage-aicountly CompanyModel::companyInfo; 404 for a non-member).
// ---------------------------------------------------------------------------
if (str_starts_with($path, 'companyinfo')) {
    $cmpId = (int) ($_GET['comp_id'] ?? 0);
    $bearer = preg_match('/Bearer\s+(.+)/i', (string) ($_SERVER['HTTP_AUTHORIZATION'] ?? ''), $m) === 1 ? trim($m[1]) : '';

    // 999: this session may NOT open it — Manage says 404, "not found or access denied".
    if ($cmpId === 999) {
        stub_json(404, ['success' => false, 'message' => 'Company not found or access denied']);
    }
    // 998: Manage itself is failing.
    if ($cmpId === 998) {
        stub_json(502, ['message' => 'Bad gateway']);
    }
    // 997: an answer about a different company — never read as a yes.
    if ($cmpId === 997) {
        stub_json(200, ['success' => '1', 'data' => ['comp_id' => 1, 'cmp_id' => 1]]);
    }

    // The owner of every stub company is user-aaa (the suite's USER) and the
    // walkthrough's portal user; everybody else is a shared member.
    $owner = in_array($bearer, ['test-ses-key-user-aaa', 'stub-ses-key'], true);
    stub_json(200, ['success' => '1', 'data' => [
        'comp_id'     => $cmpId,
        'cmp_id'      => $cmpId,
        'comp_name'   => 'Stub Company ' . $cmpId,
        'branch_list' => [],
        'fy_list'     => [],
        'is_creator'  => $owner,
        'ownership'   => $owner ? 'owner' : 'shared',
        'access_type' => $owner ? 1 : 2,
    ]]);
}

// ---------------------------------------------------------------------------
// Contacts — contract v1, COMPANY endpoints, in the shape the real serializer
// emits (ContactApiSerializer::companyContactToApi). The conformance run against
// the REAL Contacts handlers is tests/contacts-conformance.php (e2e harness);
// this stub only lets the domain suite run without one.
// ---------------------------------------------------------------------------
function stub_contact(string $id, string $name, array $phones, int $cmp, string $state = 'active', ?string $into = null): array
{
    return [
        'id' => $id, 'displayName' => $name, 'contactKind' => 'personal', 'organizationName' => '',
        'ecosystemRoles' => [], 'categories' => [], 'tags' => [],
        'phones' => array_map(static fn (string $p): array => ['value' => $p], $phones),
        'emails' => [], 'addresses' => [], 'socialLinks' => [], 'note' => '',
        'aicountlyOnly' => true, 'syncEligible' => false, 'source' => 'aicountly',
        'archivedAt' => null, 'platformUserId' => null, 'platformUserUuid' => null, 'integrationMeta' => [],
        'version' => 1, 'state' => $state, 'mergedIntoId' => $into,
        'cmpId' => $cmp, 'visibility' => 'company', 'taxIds' => [], 'createdBy' => '101',
    ];
}

if (preg_match('#^companies/(\d+)/(contacts|delegations)(/.*)?$#', $path, $cm) === 1) {
    if (stub_mode('contacts') === 'down') {
        stub_json(503, ['status' => 0, 'error' => ['code' => 'service_unavailable', 'message' => 'Contacts is unavailable.'], 'message' => 'Contacts is unavailable.']);
    }
    $cmp = (int) $cm[1];
    $rest = trim($cm[3] ?? '', '/');
    $auth = (string) ($_SERVER['HTTP_AUTHORIZATION'] ?? '');
    if (str_starts_with($auth, 'Delegation ')) {
        if (($_SERVER['HTTP_X_AIC_SERVICE'] ?? '') !== 'voice' || trim(substr($auth, 11)) === 'dlg_expired') {
            stub_json(401, ['status' => 0, 'error' => ['code' => 'delegation_invalid', 'message' => 'Delegation is not valid.'], 'message' => 'Delegation is not valid.']);
        }
    } elseif (!str_starts_with($auth, 'Bearer ') || trim(substr($auth, 7)) === '') {
        stub_json(401, ['status' => 0, 'error' => ['code' => 'unauthorized', 'message' => 'Unauthorized'], 'message' => 'Unauthorized']);
    }

    if ($cm[2] === 'delegations') {
        if ($method === 'POST') {
            stub_json(201, ['status' => 1, 'data' => [
                'grantId' => 'grant-' . substr(hash('sha256', (string) microtime(true)), 0, 8),
                'token' => 'dlg_stub_' . bin2hex(random_bytes(6)),
                'expiresAt' => gmdate('Y-m-d\TH:i:s\Z', time() + (int) ($body['ttlSeconds'] ?? 28800)),
                'scopes' => $body['scopes'] ?? [], 'cmpId' => $cmp, 'product' => 'voice', 'actor' => '101', 'environment' => 'local',
            ]]);
        }
        http_response_code(204);
        exit;
    }

    if ($rest === 'lookup') {
        $phone = (string) ($_GET['phone'] ?? '');
        $rows = match ($phone) {
            '+919876500011' => [stub_contact('stub-1', 'Stub One', ['+919876500011'], $cmp)],
            '+919876500099' => [stub_contact('stub-2', 'Shared Desk A', ['+919876500099'], $cmp), stub_contact('stub-3', 'Shared Desk B', ['+919876500099'], $cmp)],
            default => [],
        };
        stub_json(200, ['status' => 1, 'data' => $rows, 'meta' => ['matchCount' => count($rows)]]);
    }

    if ($rest === 'resolve' && $method === 'POST') {
        $out = [];
        foreach ((array) ($body['ids'] ?? []) as $id) {
            $id = (string) $id;
            $out[] = str_starts_with($id, 'stub-')
                ? ['id' => $id, 'state' => 'active', 'survivorId' => $id, 'contact' => stub_contact($id, 'Stub ' . $id, ['+919876500011'], $cmp)]
                : ['id' => $id, 'state' => 'unknown', 'survivorId' => null];
        }
        stub_json(200, ['status' => 1, 'data' => $out]);
    }

    if ($rest === 'find-or-create' && $method === 'POST') {
        if (trim((string) ($body['displayName'] ?? '')) === '' && empty($body['phones']) && empty($body['emails'])) {
            stub_json(400, ['status' => 0, 'error' => ['code' => 'validation_failed', 'message' => 'displayName is required.', 'details' => ['fields' => ['displayName' => 'required']]], 'message' => 'displayName is required.']);
        }
        stub_json(201, ['status' => 1, 'created' => true, 'data' => stub_contact(
            'stub-created', (string) ($body['displayName'] ?? ''), array_column((array) ($body['phones'] ?? []), 'value'), $cmp,
        )]);
    }

    if (preg_match('#^([^/]+)/resolve$#', $rest, $rm) === 1) {
        stub_json(200, ['status' => 1, 'data' => match ($rm[1]) {
            'merged-old' => ['id' => 'merged-old', 'state' => 'merged', 'survivorId' => 'stub-1'],
            'gone'       => ['id' => 'gone', 'state' => 'deleted', 'survivorId' => null],
            default      => ['id' => $rm[1], 'state' => 'unknown', 'survivorId' => null],
        }]);
    }

    if ($rest !== '' && $method === 'GET') {
        if (in_array($rest, ['gone', 'merged-old'], true) || str_starts_with($rest, 'private-')) {
            stub_json(404, ['status' => 0, 'error' => ['code' => 'not_found', 'message' => 'Contact not found.'], 'message' => 'Contact not found.']);
        }
        // 'missing' has no number, which is a different outcome from the
        // directory being down.
        if ($rest === 'missing') {
            stub_json(200, ['status' => 1, 'data' => stub_contact('missing', 'No Number', [], $cmp)]);
        }
        // Stored nationally (an import), to prove Voice reads it in the company region.
        if ($rest === 'national') {
            stub_json(200, ['status' => 1, 'data' => stub_contact('national', 'National Format', ['98765 00012'], $cmp)]);
        }
        stub_json(200, ['status' => 1, 'data' => stub_contact($rest, 'Stub Contact', ['+919876500011'], $cmp)]);
    }

    stub_json(200, ['status' => 1, 'data' => [stub_contact('stub-1', 'Stub One', ['+919876500011'], $cmp)],
        'meta' => ['page' => 1, 'per_page' => 20, 'total' => 1, 'total_pages' => 1]]);
}

// The PERSONAL book must never be asked by Voice; answering loudly makes a
// regression visible.
if (str_starts_with($path, 'contacts')) {
    stub_json(410, ['status' => 0, 'error' => ['code' => 'personal_book_not_for_voice', 'message' => 'Voice must use the company endpoints.']]);
}

// ---------------------------------------------------------------------------
// Calendar — the Events API v1, from its contract (see calendar_v1.php)
// ---------------------------------------------------------------------------
if (str_starts_with($path, 'calendar/')) {
    require __DIR__ . '/calendar_v1.php';
    calendar_v1($method, $path, $body);
}

// ---------------------------------------------------------------------------
// Appointments — the partner API, from its routes (see appointments_v1.php)
// ---------------------------------------------------------------------------
if (str_starts_with($path, 'v1/')) {
    require __DIR__ . '/appointments_v1.php';
    appointments_v1($method, $path, $body);
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
