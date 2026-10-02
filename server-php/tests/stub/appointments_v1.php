<?php

declare(strict_types=1);

/**
 * A stand-in for Aicountly Appointments' partner API — written from its routes
 * and envelopes, not from Voice's client.
 *
 * Source: appointments-aicountly server-php/src/Routes.php, Http.php,
 * Idempotency.php, Controllers/BookingsController.php, AvailabilityController.php,
 * ServicesController.php, and calendar-react-app's CONTRACTS.md §13–§14. What a
 * service-key caller can reach:
 *
 *   GET  v1/services[?limit]              {data: [...], meta}
 *   GET  v1/services/{uuid}               {data: {service: {...}}}
 *   GET  v1/availability/slots            {data: {slots: [...], service, ...}};
 *                                          offers minus active bookings
 *   POST v1/bookings                      201 {data: {booking, calendar}};
 *                                          Idempotency-Key replays the stored
 *                                          answer verbatim; a time already
 *                                          booked is 409 {error: {code:
 *                                          "conflict", details: {reason:
 *                                          "slot_taken"}}}; a time not offered
 *                                          422 validation_failed, reason
 *                                          not_offered
 *   GET  v1/bookings?service_uuid&member_uuid&from&to&q   {data: [...], meta}
 *   GET  v1/bookings/{uuid}               {data: {booking, ...}}
 *
 * Every route needs X-Service-Key (401 otherwise) and cmp_id.
 *
 * Behaviour switches (tests/stub/state/appointments):
 *
 *   down              503 with no envelope: Appointments may or may not have acted
 *   timeout           500, empty body, NOTHING done (a dropped request)
 *   drop_once         the next booking request is lost the same way, then back to up
 *   commit_then_drop  the booking is MADE, then 502 with an empty body (a lost
 *                     answer); one request only, then back to up
 *   commit_then_drop_lookup_down   the same, then lookup_down
 *   reject_key        401 for every key
 *   lookup_down       the bookings list answers 503 (the read-back cannot be made)
 *   calendar_down     slots and bookings answer 503 calendar_unavailable (not taken)
 *
 * Its database is tests/stub/state/appointments.json: services, offers,
 * bookings, idempotency records and a log of every request.
 */

const APPT_KEYS = ['voice' => 'test-appointments-service-key-0123456789'];

function appt_db_path(): string
{
    return __DIR__ . '/state/appointments.json';
}

/** @return array<string, mixed> */
function appt_load(): array
{
    $raw = @file_get_contents(appt_db_path());
    $db = is_string($raw) ? json_decode($raw, true) : null;

    return (is_array($db) ? $db : []) + ['services' => [], 'offers' => [], 'bookings' => [], 'idempotency' => [], 'log' => [], 'seq' => 1000];
}

/** @param array<string, mixed> $db */
function appt_save(array $db): void
{
    file_put_contents(appt_db_path(), json_encode($db, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), LOCK_EX);
}

function appt_send(int $status, array $payload): never
{
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

/** Appointments' error envelope: {error: {code, message, details}, message}. */
function appt_error(int $status, string $code, string $message, array $details = []): never
{
    appt_send($status, ['error' => ['code' => $code, 'message' => $message, 'details' => $details], 'message' => $message]);
}

function appt_header(string $name): string
{
    $value = $_SERVER['HTTP_' . strtoupper(str_replace('-', '_', $name))] ?? '';

    return is_string($value) ? trim($value) : '';
}

function appt_uuid(): string
{
    $b = random_bytes(16);
    $b[6] = chr(ord($b[6]) & 0x0f | 0x40);
    $b[8] = chr(ord($b[8]) & 0x3f | 0x80);

    return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($b), 4));
}

/** An instant WITH its offset, or null (Appointments: datetime_offset_required). */
function appt_instant(mixed $value): ?int
{
    if (!is_string($value) || preg_match('/(Z|[+-]\d{2}:?\d{2})$/', $value) !== 1) {
        return null;
    }
    $ts = strtotime($value);

    return $ts === false ? null : $ts;
}

function appt_utc(int $ts): string
{
    return gmdate('Y-m-d\TH:i:s\Z', $ts);
}

/**
 * A booking as BookingsController::shape() answers it.
 *
 * @param array<string, mixed> $row
 * @return array<string, mixed>
 */
function appt_shape(array $row, array $db): array
{
    $service = $db['services'][$row['service_uuid']] ?? ['name' => ''];
    $state = (string) ($row['calendar_state'] ?? 'synced');

    return [
        'booking_uuid'   => $row['booking_uuid'],
        'reference'      => $row['reference'],
        'status'         => $row['status'],
        'mode'           => $row['mode'] ?? 'IN_PERSON',
        'booking_source' => $row['booking_source'],
        'starts_at'      => $row['starts_at'],
        'ends_at'        => $row['ends_at'],
        'timezone'       => $row['timezone'] ?? 'Asia/Kolkata',
        'service'        => ['service_uuid' => $row['service_uuid'], 'name' => $service['name'] ?? '', 'form_uuid' => null],
        'member'         => $row['member_uuid'] === null ? null : ['member_uuid' => $row['member_uuid'], 'label' => 'Dr Stub'],
        'client'         => [
            'contact_uuid' => $row['contact_uuid'] ?? null,
            'name'         => $row['client_name'] ?? '',
            'email'        => $row['client_email'] ?? '',
            'phone'        => $row['client_phone'] ?? '',
        ],
        'internal_notes' => $row['internal_notes'] ?? '',
        'calendar'       => ['state' => $state, 'error' => null, 'confirmed' => $state === 'synced', 'drift' => null, 'release_state' => 'none'],
        'lifecycle'      => ['cancellation_code' => $row['cancellation_code'] ?? null],
    ];
}

/** @param array<string, mixed> $db */
function appt_taken(array $db, string $memberUuid, int $start): bool
{
    foreach ($db['bookings'] as $b) {
        if (in_array($b['status'], ['PENDING', 'CONFIRMED'], true) && $b['member_uuid'] === $memberUuid
            && strtotime($b['starts_at']) === $start) {
            return true;
        }
    }

    return false;
}

function appointments_v1(string $method, string $path, array $body): never
{
    $mode = stub_mode('appointments');
    $db = appt_load();
    $db['log'][] = [
        'method'  => $method,
        'path'    => $path,
        'query'   => $_GET,
        'headers' => [
            'x-service-key'   => appt_header('X-Service-Key'),
            'x-actor-uuid'    => appt_header('X-Actor-Uuid'),
            'idempotency-key' => appt_header('Idempotency-Key'),
            'authorization'   => appt_header('Authorization'),
        ],
        'body'    => $body,
        'mode'    => $mode,
    ];
    appt_save($db);

    if ($mode === 'down') {
        http_response_code(503);
        echo 'Service Unavailable';
        exit;
    }
    if ($mode === 'timeout') {
        http_response_code(500);
        exit;
    }

    $label = null;
    foreach (APPT_KEYS as $name => $key) {
        if ($mode !== 'reject_key' && hash_equals($key, appt_header('X-Service-Key'))) {
            $label = $name;
        }
    }
    if ($label === null) {
        appt_error(401, 'unauthorized', 'Sign in again to continue.');
    }
    $cmpId = (int) ($_GET['cmp_id'] ?? 0);
    if ($cmpId <= 0) {
        appt_error(400, 'context_required', 'Pick a company first (cmp_id is required).');
    }

    // ---- services ---------------------------------------------------------
    if ($method === 'GET' && $path === 'v1/services') {
        $rows = array_values(array_filter($db['services'], static fn (array $s): bool => (int) $s['cmp_id'] === $cmpId));
        appt_send(200, ['data' => array_slice($rows, 0, (int) ($_GET['limit'] ?? 50)), 'meta' => ['total' => count($rows)]]);
    }
    if ($method === 'GET' && preg_match('#^v1/services/([^/]+)$#', $path, $m) === 1) {
        $service = $db['services'][$m[1]] ?? null;
        if ($service === null || (int) $service['cmp_id'] !== $cmpId) {
            appt_error(404, 'not_found', 'That service does not exist.');
        }
        appt_send(200, ['data' => ['service' => $service, 'staff' => [], 'resources' => [], 'locations' => []]]);
    }

    // ---- availability -----------------------------------------------------
    if ($method === 'GET' && $path === 'v1/availability/slots') {
        if ($mode === 'calendar_down') {
            appt_error(503, 'calendar_unavailable', 'Aicountly Calendar could not be read, so no times are offered.', ['retryable' => true, 'slots' => []]);
        }
        $serviceUuid = (string) ($_GET['service_uuid'] ?? '');
        if ($serviceUuid === '') {
            appt_error(422, 'validation_failed', 'service_uuid is required.');
        }
        $from = isset($_GET['from']) ? strtotime((string) $_GET['from']) : time();
        $to = isset($_GET['to']) ? strtotime((string) $_GET['to']) : time() + 8 * 86400;
        $limit = (int) ($_GET['limit'] ?? 200);
        $slots = [];
        foreach ($db['offers'] as $offer) {
            $start = strtotime($offer['starts_at']);
            if ($offer['service_uuid'] !== $serviceUuid || $start < $from || $start >= $to
                || (isset($_GET['member_uuid']) && $_GET['member_uuid'] !== $offer['member_uuid'])
                || appt_taken($db, $offer['member_uuid'], $start)) {
                continue;
            }
            $local = (new DateTimeImmutable('@' . $start))->setTimezone(new DateTimeZone('Asia/Kolkata'));
            $slots[] = [
                'starts_at' => appt_utc($start), 'ends_at' => appt_utc($start + 1800),
                'local_time' => $local->format('H:i'), 'local_date' => $local->format('Y-m-d'),
                'daypart' => 'morning', 'member_uuid' => $offer['member_uuid'], 'member_label' => 'Dr Stub',
                'bo_id' => 0, 'resource_uuids' => [], 'duration_minutes' => 30,
            ];
        }
        usort($slots, static fn (array $a, array $b): int => strcmp($a['starts_at'], $b['starts_at']));
        appt_send(200, ['data' => [
            'slots' => array_slice($slots, 0, $limit), 'window' => [], 'members' => [],
            'service' => ['service_uuid' => $serviceUuid, 'name' => $db['services'][$serviceUuid]['name'] ?? '', 'duration_minutes' => 30, 'modes' => ['IN_PERSON'], 'deposit_required' => false],
            'calendar_available' => true, 'members_unreadable' => [], 'calendar_message' => null,
        ]]);
    }

    // ---- bookings ---------------------------------------------------------
    if ($method === 'POST' && $path === 'v1/bookings') {
        if ($mode === 'drop_once') {
            // Lost on the way in: nothing done.
            file_put_contents(__DIR__ . '/state/appointments', 'up');
            http_response_code(500);
            exit;
        }
        $key = appt_header('Idempotency-Key');
        $scopeKey = $cmpId . '|booking.create|' . $key;
        if ($key !== '' && isset($db['idempotency'][$scopeKey])) {
            // Appointments today: the stored answer, verbatim.
            appt_send($db['idempotency'][$scopeKey]['status'], $db['idempotency'][$scopeKey]['body']);
        }
        if ($mode === 'calendar_down') {
            appt_error(503, 'calendar_unavailable', 'Cannot confirm availability with Aicountly Calendar right now.', ['retryable' => true]);
        }

        $service = $db['services'][(string) ($body['service_uuid'] ?? '')] ?? null;
        if ($service === null || (int) $service['cmp_id'] !== $cmpId) {
            appt_error(404, 'not_found', 'That service does not exist.');
        }
        $start = appt_instant($body['starts_at'] ?? null);
        if ($start === null) {
            appt_error(422, 'validation_failed', 'A start time with a timezone offset (or Z) is required.', ['reason' => 'datetime_offset_required']);
        }
        $member = (string) ($body['member_uuid'] ?? '');
        if (appt_taken($db, $member, $start)) {
            appt_error(409, 'conflict', 'That time was taken while you were booking.', ['retryable' => false, 'reason' => 'slot_taken']);
        }
        $offered = false;
        foreach ($db['offers'] as $offer) {
            if ($offer['service_uuid'] === $service['service_uuid'] && $offer['member_uuid'] === $member && strtotime($offer['starts_at']) === $start) {
                $offered = true;
            }
        }
        if (!$offered) {
            appt_error(422, 'validation_failed', 'That time is not one this service offers.', ['reason' => 'not_offered']);
        }

        $uuid = appt_uuid();
        $db['seq']++;
        $db['bookings'][$uuid] = [
            'booking_uuid' => $uuid, 'cmp_id' => $cmpId, 'reference' => 'APT-' . $db['seq'],
            'status' => ($service['requires_confirmation'] ?? false) ? 'PENDING' : 'CONFIRMED',
            'service_uuid' => $service['service_uuid'], 'member_uuid' => $member,
            'starts_at' => appt_utc($start), 'ends_at' => appt_utc($start + 1800),
            'timezone' => (string) ($body['timezone'] ?? 'Asia/Kolkata'),
            // Proven by the credential: there is no VOICE source.
            'booking_source' => 'API_INTEGRATION',
            'client_name' => (string) ($body['client_name'] ?? ''), 'client_phone' => (string) ($body['client_phone'] ?? ''),
            'client_email' => (string) ($body['client_email'] ?? ''), 'contact_uuid' => $body['contact_uuid'] ?? null,
            'internal_notes' => (string) ($body['internal_notes'] ?? ''),
            'calendar_state' => (string) ($service['calendar_state'] ?? 'synced'),
        ];
        $answer = ['data' => [
            'booking'  => appt_shape($db['bookings'][$uuid], $db),
            'calendar' => ['state' => $db['bookings'][$uuid]['calendar_state'], 'confirmed' => $db['bookings'][$uuid]['calendar_state'] === 'synced', 'message' => ''],
        ]];
        if ($key !== '') {
            $db['idempotency'][$scopeKey] = ['status' => 201, 'body' => $answer];
        }
        appt_save($db);

        if ($mode === 'commit_then_drop' || $mode === 'commit_then_drop_lookup_down') {
            file_put_contents(__DIR__ . '/state/appointments', $mode === 'commit_then_drop' ? 'up' : 'lookup_down');
            http_response_code(502);
            exit;
        }
        appt_send(201, $answer);
    }

    if ($method === 'GET' && $path === 'v1/bookings') {
        if ($mode === 'lookup_down') {
            appt_error(503, 'context_unavailable', 'Cannot confirm company access right now. Please retry.', ['retryable' => true]);
        }
        $from = isset($_GET['from']) ? strtotime((string) $_GET['from']) : null;
        $to = isset($_GET['to']) ? strtotime((string) $_GET['to']) : null;
        $rows = [];
        foreach ($db['bookings'] as $b) {
            $start = strtotime($b['starts_at']);
            if ((int) $b['cmp_id'] !== $cmpId
                || (isset($_GET['service_uuid']) && $b['service_uuid'] !== $_GET['service_uuid'])
                || (isset($_GET['member_uuid']) && $b['member_uuid'] !== $_GET['member_uuid'])
                || ($from !== null && $start < $from) || ($to !== null && $start >= $to)
                || (isset($_GET['q']) && stripos($b['client_phone'] . ' ' . $b['client_name'] . ' ' . $b['reference'], (string) $_GET['q']) === false)) {
                continue;
            }
            $rows[] = appt_shape($b, $db);
        }
        appt_send(200, ['data' => $rows, 'meta' => ['total' => count($rows), 'limit' => 50, 'offset' => 0]]);
    }

    if ($method === 'GET' && preg_match('#^v1/bookings/([^/]+)$#', $path, $m) === 1) {
        $b = $db['bookings'][$m[1]] ?? null;
        if ($b === null || (int) $b['cmp_id'] !== $cmpId) {
            appt_error(404, 'not_found', 'That appointment does not exist.');
        }
        appt_send(200, ['data' => ['booking' => appt_shape($b, $db), 'metadata' => [], 'reminders' => []]]);
    }

    appt_error(404, 'not_found', 'Stub Appointments has no route for /' . $path);
}
