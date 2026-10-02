<?php

declare(strict_types=1);

/**
 * A stand-in for Aicountly Calendar's Events API v1 — written from the
 * contract, not from Voice's client.
 *
 * Source: calendar-react-app docs/ecosystem-alignment/CONTRACTS.md §1–§7 and
 * §11–§12. Where Voice's client and this file disagree, this file is meant to
 * be right: it is what a Voice test proves Voice against. It implements the
 * parts of the contract a Mode S (service key) caller can reach:
 *
 *   POST  calendar/events               create; Idempotency-Key replay and
 *                                        conflict; source_ref natural key
 *                                        (409 source_ref_exists, cancelled
 *                                        included); conflict_policy "reject"
 *                                        over every busy event in the diary,
 *                                        half-open (409 slot_taken /
 *                                        availability_unverified)
 *   PATCH calendar/events/{id}          own-app only, else 404 event_not_found;
 *                                        If-Match (428 / 409 version_conflict);
 *                                        cancel unconditional, idempotent,
 *                                        terminal (409 event_cancelled)
 *   GET   calendar/events?source_app=&source_ref=   the lookup (0 or 1, cancelled
 *                                        included; 403 source_app_mismatch)
 *   GET   calendar/events/{id}           own-app only
 *   DELETE calendar/events/{id}          403 delete_not_allowed for a key
 *   GET   calendar/free-busy             times only; event_id for own events
 *   POST  calendar/conflict-check        checked:false is not free
 *
 * Strict mode (X-Calendar-Contract: 1): unknown body fields 422
 * unsupported_field, unknown filters 422 unsupported_filter, a reminder from a
 * service 422 reminder_not_allowed_for_service. Without the header, unknown
 * fields are ignored and named in X-Calendar-Ignored-Fields — exactly the
 * silent-drop a client must never rely on. Every answer carries
 * X-Calendar-Contract: 1 and the envelope {success, message, code, data,
 * errors}.
 *
 * Behaviour switches (tests/stub/state/calendar), for the cases a test needs
 * Calendar to misbehave in:
 *
 *   down               503, no code: Calendar may or may not have acted
 *   timeout            500, empty body, NOTHING done (a dropped request)
 *   commit_then_drop   the write is DONE, then 502 with an empty body (a lost
 *                      response); one request only, then back to up
 *   in_progress        writes answer 409 request_in_progress; once
 *   schema_not_ready   503 schema_not_ready
 *   reject_key         every key is refused, 401 unauthenticated
 *   pre_v1             behaves like Calendar before v1: create ignores
 *                      unknown fields and answers without version/source_ref;
 *                      the events list demands start and end (422)
 *   reject_fields      every write is a 422 unsupported_field
 *
 * Its database is tests/stub/state/calendar.json: events, idempotency records,
 * per-subscriber flags (denied / stale) and a log of every request, which is
 * what the tests read to assert on the wire.
 */

const CAL_KEYS = ['voice' => 'test-calendar-service-key-0123456789'];
const CAL_EVENT_FIELDS = [
    'title', 'description', 'start_at', 'end_at', 'all_day', 'start_date', 'end_date', 'timezone', 'category',
    'compliance_kind', 'workspace_scope', 'priority', 'status', 'busy_status', 'visibility',
    'reminder_offset_minutes', 'recurrence_rule', 'source', 'source_app',
];
const CAL_CREATE_EXTRA = ['source_ref', 'conflict_policy', 'ignore_event_ids'];
const CAL_PATCH_EXTRA = ['conflict_policy', 'expected_version', 'ignore_event_ids'];
const CAL_IMMUTABLE = ['source_ref', 'id', 'version', 'created_by_kind', 'created_by_app'];

function cal_db_path(): string
{
    return __DIR__ . '/state/calendar.json';
}

/** @return array<string, mixed> */
function cal_load(): array
{
    $raw = @file_get_contents(cal_db_path());
    $db = is_string($raw) ? json_decode($raw, true) : null;

    return (is_array($db) ? $db : []) + ['events' => [], 'idempotency' => [], 'log' => [], 'denied' => [], 'stale' => []];
}

/** @param array<string, mixed> $db */
function cal_save(array $db): void
{
    file_put_contents(cal_db_path(), json_encode($db, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), LOCK_EX);
}

/** @param array<string, string> $headers */
function cal_send(int $status, array $payload, array $headers = []): never
{
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    header('X-Calendar-Contract: 1');
    foreach ($headers as $name => $value) {
        header($name . ': ' . $value);
    }
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

/** @return array{0:int, 1:array<string, mixed>} */
function cal_error(int $status, string $code, string $message, mixed $data = null): array
{
    return [$status, ['success' => false, 'message' => $message, 'code' => $code, 'data' => $data, 'errors' => [$message]]];
}

/** @return array{0:int, 1:array<string, mixed>} */
function cal_ok(int $status, mixed $data, string $message = 'Success'): array
{
    return [$status, ['success' => true, 'message' => $message, 'code' => null, 'data' => $data, 'errors' => []]];
}

function cal_header(string $name): string
{
    $value = $_SERVER['HTTP_' . strtoupper(str_replace('-', '_', $name))] ?? '';

    return is_string($value) ? trim($value) : '';
}

function cal_uuid(): string
{
    $b = random_bytes(16);
    $b[6] = chr(ord($b[6]) & 0x0f | 0x40);
    $b[8] = chr(ord($b[8]) & 0x3f | 0x80);

    return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($b), 4));
}

/** RFC 3339 with an explicit offset or Z. Naive values are a 422 for every caller (§4). */
function cal_instant(mixed $value): ?int
{
    if (!is_string($value) || preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}(:\d{2}(\.\d+)?)?(Z|[+-]\d{2}:\d{2})$/', $value) !== 1) {
        return null;
    }
    $ts = strtotime($value);

    return $ts === false ? null : $ts;
}

function cal_utc(int $ts): string
{
    return gmdate('Y-m-d\TH:i:s\Z', $ts);
}

/** Recursively sorted, so "the same body" does not depend on key order. */
function cal_canonical(mixed $value): mixed
{
    if (!is_array($value)) {
        return $value;
    }
    if (!array_is_list($value)) {
        ksort($value);
    }

    return array_map('cal_canonical', $value);
}

/**
 * The event as the API shows it (§3). The owner is internal.
 *
 * @param array<string, mixed> $row
 * @return array<string, mixed>
 */
function cal_public(array $row): array
{
    unset($row['_subscriber']);

    return $row;
}

/**
 * Busy blocks for one subscriber that overlap [start, end) — half-open (§4):
 * back-to-back is not a clash, one second is. Cancelled and busy_status free
 * never block.
 *
 * @param array<string, mixed> $db
 * @param list<string> $ignore
 * @return list<array<string, mixed>>
 */
function cal_overlaps(array $db, string $subscriber, int $start, int $end, string $label, array $ignore = []): array
{
    $out = [];
    foreach ($db['events'] as $event) {
        if ($event['_subscriber'] !== $subscriber || $event['status'] === 'cancelled'
            || ($event['busy_status'] ?? 'busy') === 'free' || in_array($event['id'], $ignore, true)) {
            continue;
        }
        $s = strtotime($event['start_at']);
        $e = strtotime($event['end_at']);
        if ($s < $end && $e > $start) {
            $block = ['start_at' => $event['start_at'], 'end_at' => $event['end_at'], 'all_day' => (bool) $event['all_day']];
            if (($event['source_app'] ?? null) === $label) {
                // event_id only for the caller's own events (§5.2, §7).
                $block['event_id'] = $event['id'];
            }
            $out[] = $block;
        }
    }
    usort($out, static fn (array $a, array $b): int => strcmp($a['start_at'], $b['start_at']));

    return $out;
}

/**
 * Handle one Calendar request. Called by router.php for calendar/* paths.
 *
 * @param array<string, mixed> $body
 */
function calendar_v1(string $method, string $path, array $body): never
{
    $mode = stub_mode('calendar');
    $strict = cal_header('X-Calendar-Contract') === '1';
    $db = cal_load();
    $db['log'][] = [
        'method'  => $method,
        'path'    => $path,
        'query'   => $_GET,
        'headers' => [
            'x-service-key'       => cal_header('X-Service-Key'),
            'x-actor-uuid'        => cal_header('X-Actor-Uuid'),
            'x-tenant-ref'        => cal_header('X-Tenant-Ref'),
            'x-calendar-contract' => cal_header('X-Calendar-Contract'),
            'idempotency-key'     => cal_header('Idempotency-Key'),
            'if-match'            => cal_header('If-Match'),
            'authorization'       => cal_header('Authorization'),
        ],
        'body'    => $body,
        'mode'    => $mode,
    ];
    cal_save($db);

    if ($mode === 'down') {
        cal_send(503, ['success' => false, 'message' => 'Service unavailable.', 'code' => null, 'data' => null, 'errors' => []]);
    }
    if ($mode === 'timeout') {
        http_response_code(500);
        exit;
    }
    if ($mode === 'reject_key') {
        cal_send(...cal_error(401, 'unauthenticated', 'Unauthorized'));
    }
    if ($mode === 'schema_not_ready') {
        cal_send(...cal_error(503, 'schema_not_ready', 'Calendar contract v1 schema is not applied.'));
    }

    // --- Mode S authentication (§2.2) -------------------------------------
    $label = null;
    foreach (CAL_KEYS as $name => $key) {
        if (hash_equals($key, cal_header('X-Service-Key'))) {
            $label = $name;
        }
    }
    if ($label === null) {
        cal_send(...cal_error(401, 'unauthenticated', 'Unauthorized'));
    }
    $actor = cal_header('X-Actor-Uuid');
    if (preg_match('/^[1-9][0-9]{0,18}$/', $actor) !== 1) {
        cal_send(...cal_error(422, 'invalid_actor', 'X-Actor-Uuid must be a my.aicountly subscriber id.'));
    }
    $tenant = cal_header('X-Tenant-Ref');
    if ($tenant !== '' && preg_match('/^cmp:[1-9][0-9]*$/', $tenant) !== 1) {
        cal_send(...cal_error(422, 'validation_failed', 'X-Tenant-Ref must be cmp:<cmp_id>.'));
    }
    $isWrite = in_array($method, ['POST', 'PATCH', 'DELETE'], true) && str_starts_with($path, 'calendar/events');
    if ($isWrite && in_array($actor, $db['denied'], true)) {
        // CALENDAR_ACTOR_VERIFICATION=enforce, and Manage says no.
        cal_send(...cal_error(403, 'actor_not_authorized', 'The actor is not an active member of that company.'));
    }

    if ($mode === 'pre_v1') {
        cal_pre_v1($method, $path, $body, $actor, $db);
    }
    if ($mode === 'reject_fields' && $isWrite) {
        // A request Calendar will never accept, however often it is sent.
        cal_send(...cal_error(422, 'unsupported_field', 'Unsupported field(s): rejected by the stub.'));
    }

    // --- Idempotency (§5.1) -----------------------------------------------
    $idemScope = null;
    if ($isWrite) {
        $key = cal_header('Idempotency-Key');
        if ($key !== '') {
            if (preg_match('/^[A-Za-z0-9._:-]{1,128}$/', $key) !== 1) {
                cal_send(...cal_error(422, 'validation_failed', 'Idempotency-Key must be 1-128 of [A-Za-z0-9._:-].'));
            }
            $template = $method . ' ' . (preg_match('#^calendar/events/[^/]+$#', $path) === 1 ? 'calendar/events/{id}' : $path);
            $idemScope = hash('sha256', $label . '|' . $actor . '|' . $template . '|' . $key);
            $fingerprint = hash('sha256', json_encode(cal_canonical($body)) . '|' . cal_header('If-Match') . '|' . $path);
            $record = $db['idempotency'][$idemScope] ?? null;
            if ($record !== null) {
                if ($record['fingerprint'] !== $fingerprint) {
                    cal_send(...cal_error(409, 'idempotency_conflict', 'This Idempotency-Key was used for a different request.'));
                }
                cal_send($record['status'], $record['payload'], ['Idempotent-Replay' => 'true']);
            }
        }
        if ($mode === 'in_progress') {
            file_put_contents(__DIR__ . '/state/calendar', 'up');
            [$status, $payload] = cal_error(409, 'request_in_progress', 'The same request is still being processed.');
            cal_send($status, $payload, ['Retry-After' => '2']);
        }
    }

    [$status, $payload] = cal_route($method, $path, $body, $label, $actor, $strict, $db);
    $ignored = $payload['_ignored'] ?? [];
    unset($payload['_ignored']);

    // Whatever was decided is recorded against the key — including refusals,
    // so a retry gets the same refusal rather than a different answer.
    if ($idemScope !== null && $status < 500) {
        $db['idempotency'][$idemScope] = ['fingerprint' => $fingerprint, 'status' => $status, 'payload' => $payload];
    }
    cal_save($db);

    if ($mode === 'commit_then_drop' && $isWrite) {
        // Done — and the answer never arrives.
        file_put_contents(__DIR__ . '/state/calendar', 'up');
        http_response_code(502);
        exit;
    }

    $headers = [];
    if (isset($payload['data']['event']['version']) && $status < 300) {
        $headers['ETag'] = '"' . $payload['data']['event']['version'] . '"';
    }
    cal_send($status, $payload, $headers + $ignored);
}

/**
 * @param array<string, mixed> $body
 * @param array<string, mixed> $db
 * @return array{0:int, 1:array<string, mixed>}
 */
function cal_route(string $method, string $path, array $body, string $label, string $actor, bool $strict, array &$db): array
{
    if ($path === 'calendar/events' && $method === 'POST') {
        return cal_create($body, $label, $actor, $strict, $db);
    }
    if (preg_match('#^calendar/events/([^/]+)$#', $path, $m) === 1) {
        $id = rawurldecode($m[1]);
        $event = $db['events'][$id] ?? null;
        // Own-app rule (§2.2): another product's event, or another person's,
        // does not exist as far as this key is concerned.
        $visible = $event !== null && $event['_subscriber'] === $actor && ($event['source_app'] ?? null) === $label;

        if ($method === 'DELETE') {
            return cal_error(403, 'delete_not_allowed', 'Service keys may not delete events.');
        }
        if (!$visible) {
            return cal_error(404, 'event_not_found', 'Event not found.');
        }
        if ($method === 'GET') {
            return cal_ok(200, ['event' => cal_public($event)]);
        }
        if ($method === 'PATCH') {
            return cal_patch($id, $body, $label, $strict, $db);
        }
    }
    if ($path === 'calendar/events' && $method === 'GET') {
        return cal_lookup($label, $actor, $strict, $db);
    }
    if ($path === 'calendar/free-busy' && $method === 'GET') {
        return cal_free_busy($label, $strict, $db);
    }
    if ($path === 'calendar/conflict-check' && $method === 'POST') {
        return cal_conflict_check($body, $label, $db);
    }

    return cal_error(404, 'route_not_found', 'Route not found.');
}

/**
 * @param array<string, mixed> $body
 * @param array<string, mixed> $db
 * @return array{0:int, 1:array<string, mixed>}
 */
function cal_create(array $body, string $label, string $actor, bool $strict, array &$db): array
{
    $allowed = array_merge(CAL_EVENT_FIELDS, CAL_CREATE_EXTRA);
    $unknown = array_values(array_diff(array_keys($body), $allowed));
    if ($unknown !== [] && $strict) {
        return cal_error(422, 'unsupported_field', 'Unsupported field(s): ' . implode(', ', $unknown) . '.', ['fields' => $unknown]);
    }
    if (isset($body['source_app']) && $body['source_app'] !== $label) {
        return cal_error(422, 'source_app_mismatch', 'source_app must be the key\'s own label.');
    }
    if ($strict && array_key_exists('reminder_offset_minutes', $body) && $body['reminder_offset_minutes'] !== null) {
        return cal_error(422, 'reminder_not_allowed_for_service', 'A service may not set an owner reminder.');
    }
    if (($body['recurrence_rule'] ?? null) !== null) {
        return cal_error(403, 'scope_denied', 'events.recurring is not granted to this key.');
    }
    if (!is_string($body['title'] ?? null) || trim($body['title']) === '') {
        return cal_error(422, 'validation_failed', 'The field title is required.');
    }
    $start = cal_instant($body['start_at'] ?? null);
    $end = cal_instant($body['end_at'] ?? null);
    if (!isset($body['start_at'], $body['end_at'])) {
        return cal_error(422, 'validation_failed', 'The fields start_at and end_at are required.');
    }
    if ($start === null || $end === null) {
        return cal_error(422, 'datetime_offset_required', 'start_at and end_at need an explicit offset or Z.');
    }
    if ($end <= $start) {
        return cal_error(422, 'invalid_range', 'end_at must be after start_at.');
    }
    $timezone = (string) ($body['timezone'] ?? 'UTC');
    if (!in_array($timezone, timezone_identifiers_list(), true) && $timezone !== 'UTC') {
        return cal_error(422, 'invalid_timezone', 'timezone must be an IANA zone.');
    }
    $ref = $body['source_ref'] ?? null;
    if ($ref !== null && (!is_string($ref) || $ref === '' || strlen($ref) > 128)) {
        return cal_error(422, 'validation_failed', 'source_ref must be a string of at most 128 characters.');
    }

    // Natural key (§5.2): never a duplicate, never an overwrite; cancelled rows count.
    if ($ref !== null) {
        foreach ($db['events'] as $event) {
            if ($event['_subscriber'] === $actor && ($event['source_app'] ?? null) === $label && ($event['source_ref'] ?? null) === $ref) {
                return cal_error(409, 'source_ref_exists', 'An event with this source_ref exists.', ['event' => cal_public($event)]);
            }
        }
    }

    if (($body['conflict_policy'] ?? 'allow') === 'reject') {
        if (in_array($actor, $db['stale'], true)) {
            return cal_error(409, 'availability_unverified', 'An external calendar has not synced recently; availability is unknown.');
        }
        $ignore = array_values(array_filter((array) ($body['ignore_event_ids'] ?? []), 'is_string'));
        $conflicts = cal_overlaps($db, $actor, $start, $end, $label, $ignore);
        if ($conflicts !== []) {
            return cal_error(409, 'slot_taken', 'That time is taken.', ['conflicts' => $conflicts]);
        }
    }

    $now = cal_utc(time());
    $id = cal_uuid();
    $event = [
        'id'                      => $id,
        'title'                   => (string) $body['title'],
        'description'             => (string) ($body['description'] ?? ''),
        'start_at'                => cal_utc($start),
        'end_at'                  => cal_utc($end),
        'all_day'                 => false,
        'timezone'                => $timezone,
        'category'                => (string) ($body['category'] ?? 'general'),
        'priority'                => (string) ($body['priority'] ?? 'normal'),
        'status'                  => (string) ($body['status'] ?? 'confirmed'),
        'busy_status'             => (string) ($body['busy_status'] ?? 'busy'),
        'visibility'              => (string) ($body['visibility'] ?? 'busy_only'),
        'reminder_offset_minutes' => null,
        'recurrence_rule'         => null,
        'source'                  => 'aicountly_native',
        'source_app'              => $label,
        'source_app_verified'     => true,
        'source_ref'              => $ref,
        'created_by_kind'         => 'service',
        'created_by_app'          => $label,
        'updated_by_app'          => $label,
        'managed_by_app'          => true,
        'version'                 => 1,
        'created_at'              => $now,
        'updated_at'              => $now,
        'is_compliance'           => false,
        '_subscriber'             => $actor,
    ];
    $db['events'][$id] = $event;

    $out = cal_ok(201, ['event' => cal_public($event)], 'Created');
    if ($unknown !== []) {
        $out[1]['_ignored'] = ['X-Calendar-Ignored-Fields' => implode(',', $unknown)];
    }

    return $out;
}

/**
 * @param array<string, mixed> $body
 * @param array<string, mixed> $db
 * @return array{0:int, 1:array<string, mixed>}
 */
function cal_patch(string $id, array $body, string $label, bool $strict, array &$db): array
{
    $event = $db['events'][$id];
    $immutable = array_values(array_intersect(array_keys($body), CAL_IMMUTABLE));
    if ($immutable !== [] || (isset($body['source_app']) && $body['source_app'] !== $event['source_app'])) {
        return cal_error(422, 'immutable_field', 'Immutable field(s): ' . implode(', ', $immutable ?: ['source_app']) . '.');
    }
    $unknown = array_values(array_diff(array_keys($body), array_merge(CAL_EVENT_FIELDS, CAL_PATCH_EXTRA)));
    if ($unknown !== [] && $strict) {
        return cal_error(422, 'unsupported_field', 'Unsupported field(s): ' . implode(', ', $unknown) . '.', ['fields' => $unknown]);
    }

    // Cancel: unconditional, idempotent, dominant (§5.3).
    if (($body['status'] ?? null) === 'cancelled') {
        if ($event['status'] !== 'cancelled') {
            $event['status'] = 'cancelled';
            $event['version']++;
            $event['updated_at'] = cal_utc(time());
            $db['events'][$id] = $event;
        }

        return cal_ok(200, ['event' => cal_public($event)]);
    }

    $retimes = array_intersect(array_keys($body), ['start_at', 'end_at', 'all_day', 'start_date', 'end_date', 'timezone']) !== [];
    if ($event['status'] === 'cancelled' && ($retimes || isset($body['status']))) {
        // Cancelled is terminal for a product-owned event.
        return cal_error(409, 'event_cancelled', 'The event is cancelled.', ['event' => cal_public($event)]);
    }

    $ifMatch = cal_header('If-Match');
    $expected = $ifMatch !== '' ? (int) trim($ifMatch, '"') : (isset($body['expected_version']) ? (int) $body['expected_version'] : null);
    if ($retimes && $expected === null) {
        return cal_error(428, 'precondition_required', 'A time change needs If-Match.');
    }
    if ($expected !== null && $expected !== $event['version']) {
        return cal_error(409, 'version_conflict', 'The event changed since that version.', ['event' => cal_public($event)]);
    }

    $start = isset($body['start_at']) ? cal_instant($body['start_at']) : strtotime($event['start_at']);
    $end = isset($body['end_at']) ? cal_instant($body['end_at']) : strtotime($event['end_at']);
    if ($start === null || $end === null) {
        return cal_error(422, 'datetime_offset_required', 'start_at and end_at need an explicit offset or Z.');
    }
    if ($end <= $start) {
        return cal_error(422, 'invalid_range', 'end_at must be after start_at.');
    }
    if ($retimes && ($body['conflict_policy'] ?? 'allow') === 'reject') {
        if (in_array($event['_subscriber'], $db['stale'], true)) {
            return cal_error(409, 'availability_unverified', 'An external calendar has not synced recently; availability is unknown.');
        }
        // The event itself is always ignored.
        $conflicts = cal_overlaps($db, $event['_subscriber'], $start, $end, $label, [$id]);
        if ($conflicts !== []) {
            return cal_error(409, 'slot_taken', 'That time is taken.', ['conflicts' => $conflicts]);
        }
    }

    foreach (CAL_EVENT_FIELDS as $field) {
        if (array_key_exists($field, $body) && !in_array($field, ['start_at', 'end_at', 'source_app'], true)) {
            $event[$field] = $body[$field];
        }
    }
    $event['start_at'] = cal_utc($start);
    $event['end_at'] = cal_utc($end);
    $event['version']++;
    $event['updated_by_app'] = $label;
    $event['updated_at'] = cal_utc(time());
    $db['events'][$id] = $event;

    return cal_ok(200, ['event' => cal_public($event)]);
}

/**
 * @param array<string, mixed> $db
 * @return array{0:int, 1:array<string, mixed>}
 */
function cal_lookup(string $label, string $actor, bool $strict, array $db): array
{
    $params = $_GET;
    if (!isset($params['source_app'], $params['source_ref'])) {
        // Voice only ever looks up; a window list is not part of what it uses.
        if (!isset($params['start'], $params['end'])) {
            return cal_error(422, 'validation_failed', 'start and end are required.');
        }

        return cal_error(422, 'validation_failed', 'The window list is not implemented by this stub.');
    }
    $unknown = array_values(array_diff(array_keys($params), ['source_app', 'source_ref']));
    if ($unknown !== [] && $strict) {
        return cal_error(422, 'unsupported_filter', 'Unsupported filter(s): ' . implode(', ', $unknown) . '.');
    }
    if ($params['source_app'] !== $label) {
        return cal_error(403, 'source_app_mismatch', 'A key may look up only its own events.');
    }

    $found = [];
    foreach ($db['events'] as $event) {
        if ($event['_subscriber'] === $actor && ($event['source_app'] ?? null) === $label
            && ($event['source_ref'] ?? null) === (string) $params['source_ref']) {
            $found[] = cal_public($event);
        }
    }

    return cal_ok(200, ['events' => $found]);
}

/**
 * @param array<string, mixed> $db
 * @return array{0:int, 1:array<string, mixed>}
 */
function cal_free_busy(string $label, bool $strict, array $db): array
{
    $start = cal_instant($_GET['start'] ?? null);
    $end = cal_instant($_GET['end'] ?? null);
    if ($start === null || $end === null) {
        return cal_error(422, 'datetime_offset_required', 'start and end need an explicit offset or Z.');
    }
    if ($end <= $start) {
        return cal_error(422, 'invalid_range', 'end must be after start.');
    }
    $unknown = array_values(array_diff(array_keys($_GET), ['subscribers', 'start', 'end']));
    if ($unknown !== [] && $strict) {
        return cal_error(422, 'unsupported_filter', 'Unsupported filter(s): ' . implode(', ', $unknown) . '.');
    }
    $subscribers = array_values(array_filter(array_map('trim', explode(',', (string) ($_GET['subscribers'] ?? ''))), 'strlen'));
    if ($subscribers === []) {
        return cal_error(422, 'validation_failed', 'At least one subscriber is required.');
    }

    $rows = [];
    foreach ($subscribers as $subscriber) {
        if (in_array($subscriber, $db['denied'], true)) {
            $rows[] = ['subscriber_uuid' => $subscriber, 'busy' => [], 'available' => false, 'verified' => false,
                'error' => 'not_authorized', 'external' => ['connected' => false, 'last_success_at' => null, 'stale' => false]];
            continue;
        }
        $stale = in_array($subscriber, $db['stale'], true);
        $busy = array_map(static fn (array $b): array => $b + ['status' => 'confirmed'], cal_overlaps($db, $subscriber, $start, $end, $label));
        $rows[] = ['subscriber_uuid' => $subscriber, 'busy' => $busy, 'available' => true, 'verified' => !$stale, 'error' => null,
            'external' => ['connected' => $stale, 'last_success_at' => null, 'stale' => $stale]];
    }

    return cal_ok(200, ['range' => ['start' => cal_utc($start), 'end' => cal_utc($end)], 'subscribers' => $rows, 'generated_at' => cal_utc(time())]);
}

/**
 * @param array<string, mixed> $body
 * @param array<string, mixed> $db
 * @return array{0:int, 1:array<string, mixed>}
 */
function cal_conflict_check(array $body, string $label, array $db): array
{
    $start = cal_instant($body['start_at'] ?? null);
    $end = cal_instant($body['end_at'] ?? null);
    if ($start === null || $end === null) {
        return cal_error(422, 'datetime_offset_required', 'start_at and end_at need an explicit offset or Z.');
    }
    if ($end <= $start) {
        return cal_error(422, 'invalid_range', 'end_at must be after start_at.');
    }
    $subscribers = array_values(array_filter(array_map('strval', (array) ($body['subscribers'] ?? [])), 'strlen'));
    if ($subscribers === []) {
        return cal_error(422, 'validation_failed', 'subscribers is required.');
    }
    $ignore = array_values(array_filter((array) ($body['ignore_event_ids'] ?? []), 'is_string'));

    $free = true;
    $checked = true;
    $rows = [];
    foreach ($subscribers as $subscriber) {
        if (in_array($subscriber, $db['denied'], true) || in_array($subscriber, $db['stale'], true)) {
            // Unknown is not free.
            $free = false;
            $checked = false;
            $rows[] = ['subscriber_uuid' => $subscriber, 'free' => false, 'checked' => false,
                'reason' => in_array($subscriber, $db['denied'], true) ? 'not_authorized' : 'external_stale', 'conflicts' => []];
            continue;
        }
        $conflicts = cal_overlaps($db, $subscriber, $start, $end, $label, $ignore);
        $free = $free && $conflicts === [];
        $rows[] = ['subscriber_uuid' => $subscriber, 'free' => $conflicts === [], 'checked' => true, 'reason' => null, 'conflicts' => $conflicts];
    }

    return cal_ok(200, ['free' => $free, 'checked' => $checked, 'range' => ['start' => cal_utc($start), 'end' => cal_utc($end)], 'subscribers' => $rows]);
}

/**
 * Calendar before v1, as it actually behaved: unknown fields dropped with a
 * 201, no version, no source_ref, a fresh id on every POST, and an events list
 * that demands a window.
 *
 * @param array<string, mixed> $body
 * @param array<string, mixed> $db
 */
function cal_pre_v1(string $method, string $path, array $body, string $actor, array $db): never
{
    if ($path === 'calendar/events' && $method === 'POST') {
        $id = cal_uuid();
        $db['events'][$id] = [
            'id' => $id, 'title' => (string) ($body['title'] ?? ''), 'start_at' => (string) ($body['start_at'] ?? ''),
            'end_at' => (string) ($body['end_at'] ?? ''), 'all_day' => false, 'status' => 'confirmed', 'busy_status' => 'busy',
            'source' => 'aicountly_native', '_subscriber' => $actor,
        ];
        cal_save($db);
        $event = $db['events'][$id];
        unset($event['_subscriber']);
        http_response_code(201);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['success' => true, 'message' => 'Created', 'data' => ['event' => $event], 'errors' => []]);
        exit;
    }
    if ($path === 'calendar/events' && $method === 'GET' && !isset($_GET['start'], $_GET['end'])) {
        http_response_code(422);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['success' => false, 'message' => 'start and end are required.', 'data' => null, 'errors' => []]);
        exit;
    }
    http_response_code(404);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['success' => false, 'message' => 'Route not found.', 'data' => null, 'errors' => []]);
    exit;
}
