<?php

declare(strict_types=1);

/**
 * The test harness: assertions, fixtures and a clean database per run.
 *
 * Tests call the REAL controllers through the router, with an adopted identity
 * (Auth::forTesting, CLI only). That is deliberate: reaching past the
 * controllers into the services would skip every permission check, and the
 * permission checks are most of what these tests are for.
 */

namespace Aicountly\Api\Tests;

use Aicountly\Api\Ai\AiClient;
use Aicountly\Api\Auth;
use Aicountly\Api\Context;
use Aicountly\Api\Db;
use Aicountly\Api\Http;
use Aicountly\Api\Permissions;
use Aicountly\Api\ResponseSent;
use Aicountly\Api\Router;
use Aicountly\Api\Routes;
use Aicountly\Api\Settings;
use Aicountly\Api\Support\Uuid;
use Aicountly\Api\Telephony\ProviderRegistry;

final class T
{
    public static int $passed = 0;
    public static int $failed = 0;
    /** @var list<string> */
    public static array $failures = [];
    private static string $group = '';

    public static function group(string $name): void
    {
        self::$group = $name;
        echo "\n  " . $name . "\n";
    }

    public static function ok(bool $condition, string $what): void
    {
        if ($condition) {
            self::$passed++;
            echo "    ok   " . $what . "\n";

            return;
        }
        self::$failed++;
        self::$failures[] = self::$group . ' / ' . $what;
        echo "    FAIL " . $what . "\n";
    }

    public static function same(mixed $expected, mixed $actual, string $what): void
    {
        if ($expected === $actual) {
            self::ok(true, $what);

            return;
        }
        self::ok(false, $what . ' (expected ' . json_encode($expected) . ', got ' . json_encode($actual) . ')');
    }

    public static function summary(): int
    {
        echo "\n" . str_repeat('-', 62) . "\n";
        echo sprintf("  %d passed, %d failed\n", self::$passed, self::$failed);
        foreach (self::$failures as $failure) {
            echo '    - ' . $failure . "\n";
        }

        return self::$failed === 0 ? 0 : 1;
    }
}

/**
 * Call a real endpoint and capture what it answered.
 *
 * @param array<string, mixed> $query
 * @param array<string, mixed> $body
 * @param array<string, string> $headers
 * @return array{status: int, body: array<string, mixed>}
 */
function request(
    string $method,
    string $path,
    array $query = [],
    array $body = [],
    array $headers = [],
): array {
    // A fresh request: superglobals, the parsed-body memo and the per-request
    // caches all have to be cleared, or one test answers from the last one.
    $_GET = $query;
    $_SERVER['REQUEST_METHOD'] = $method;
    $_SERVER['REQUEST_URI'] = '/' . ltrim($path, '/');
    $_SERVER['SCRIPT_NAME'] = '/index.php';
    foreach ($headers as $name => $value) {
        $_SERVER['HTTP_' . strtoupper(str_replace('-', '_', $name))] = $value;
    }

    resetRequestState($body);

    $router = new Router();
    Routes::register($router);

    try {
        if (!$router->dispatch($method, trim($path, '/'))) {
            return ['status' => 404, 'body' => ['error' => ['code' => 'not_found']]];
        }
    } catch (ResponseSent $sent) {
        return ['status' => $sent->status, 'body' => $sent->payload];
    } catch (\Throwable $e) {
        return ['status' => 500, 'body' => ['error' => [
            'code' => 'exception',
            'message' => $e::class . ': ' . $e->getMessage() . ' @ ' . basename($e->getFile()) . ':' . $e->getLine(),
        ]]];
    }

    return ['status' => 204, 'body' => []];
}

/** @param array<string, mixed> $body */
function resetRequestState(array $body = []): void
{
    // Http::body() memoises php://input, which a CLI test cannot write to.
    $reflection = new \ReflectionClass(Http::class);
    $property = $reflection->getProperty('body');
    $property->setAccessible(true);
    $property->setValue(null, $body);

    Permissions::forget();
    Settings::resetForTesting();
    ProviderRegistry::resetForTesting();
    // A real request starts without AI Pulse's status answer from the last one.
    AiClient::resetForTesting();
}

/** Clear every header a previous request set, so one test cannot leak into the next. */
function clearHeaders(): void
{
    foreach (array_keys($_SERVER) as $key) {
        if (is_string($key) && str_starts_with($key, 'HTTP_')) {
            unset($_SERVER[$key]);
        }
    }
}

/**
 * A company scope this identity is allowed to open.
 *
 * `trustForTesting` stands in for the Manage call, except in the tenant tests,
 * which deliberately do not call it so the real check runs.
 */
function scope(int $cmpId, Auth $auth, int $boId = 0): Context
{
    $ctx = Context::forCompany($cmpId, $boId);
    Context::trustForTesting($cmpId, $auth);

    return $ctx;
}

/** Every Voice table emptied, so each run starts from the same place. */
function truncateAll(): void
{
    $tables = [
        'voice_call_events', 'voice_transcript_segments', 'voice_call_summaries',
        'voice_commitments', 'voice_quality_reviews', 'voice_recordings',
        'voice_voicemails', 'voice_call_participants', 'voice_call_legs',
        'voice_campaign_attempts', 'voice_campaign_audience_refs', 'voice_calls',
        'voice_campaigns', 'voice_callbacks', 'voice_external_operations',
        'voice_idempotency_keys', 'voice_usage_entries', 'voice_budget_policies',
        'voice_ai_test_runs', 'voice_ai_agent_versions', 'voice_ai_agents',
        'voice_call_flow_versions', 'voice_call_flows', 'voice_queue_members',
        'voice_queues', 'voice_team_members', 'voice_teams', 'voice_agents',
        'voice_numbers', 'voice_provider_connections', 'voice_suppressions',
        'voice_integrations', 'voice_permission_assignments', 'voice_permission_profiles',
        'voice_settings', 'voice_audit_events', 'voice_saved_searches',
    ];

    Db::run('TRUNCATE ' . implode(', ', $tables) . ' RESTART IDENTITY CASCADE');
    // The fleet-default dispositions are seeded with cmp_id 0 and must survive.
    Db::run('DELETE FROM voice_dispositions WHERE cmp_id <> 0');
}

/**
 * A working provider connection for a company.
 *
 * Points at the stub gateway, so capability discovery and call placement are
 * exercised for real rather than mocked.
 */
function seedConnection(int $cmpId, string $role = 'primary'): int
{
    return (int) Db::insert('voice_provider_connections', [
        'cmp_id'   => $cmpId,
        'provider' => 'gateway',
        'label'    => 'Stub gateway',
        'role'     => $role,
        'credentials_enc' => \Aicountly\Api\Crypto::sealCredentials(['signing_secret' => 'stub-signing-secret']),
        'config'   => [],
        'max_concurrent' => 10,
        'is_active' => true,
    ], 'connection_id');
}

function seedNumber(int $cmpId, int $connectionId, string $e164 = '+918066000001'): int
{
    return (int) Db::insert('voice_numbers', [
        'cmp_id'        => $cmpId,
        'connection_id' => $connectionId,
        'e164'          => $e164,
        'label'         => 'Stub number',
        'is_active'     => true,
        'routing_status' => 'active',
    ], 'number_id');
}

function seedAgent(int $cmpId, string $userUuid, string $extension = '1001'): int
{
    return (int) Db::insert('voice_agents', [
        'cmp_id'    => $cmpId,
        'user_uuid' => $userUuid,
        'extension' => $extension,
        'is_active' => true,
        'presence'  => 'available',
    ], 'agent_id');
}

/** Wide-open calling window, so a test is not refused for running at 3am. */
function seedSettings(int $cmpId, array $overrides = []): void
{
    $ctx = Context::forCompany($cmpId);
    Settings::save($ctx, array_merge([
        'timezone' => 'UTC',
        'calling_window_start_min' => 0,
        'calling_window_end_min'   => 1440,
        'calling_window_days'      => [0, 1, 2, 3, 4, 5, 6],
        'campaign_approval_required' => false,
        'max_concurrent_calls'     => 10,
    ], $overrides), 'test');
    Settings::resetForTesting();
}

/**
 * Grant a profile holding exactly these permissions.
 *
 * Used for the permission tests, where acs_type must NOT be 1 — an owner holds
 * everything and would pass every check without proving anything.
 */
function grant(int $cmpId, string $userUuid, array $permissions): void
{
    $profileId = (int) Db::insert('voice_permission_profiles', [
        'cmp_id'      => $cmpId,
        'name'        => 'test-' . substr(Uuid::v4(), 0, 8),
        'permissions' => $permissions,
        'is_active'   => true,
    ], 'profile_id');

    Db::insert('voice_permission_assignments', [
        'cmp_id'     => $cmpId,
        'profile_id' => $profileId,
        'user_uuid'  => $userUuid,
    ], 'assignment_id');

    Permissions::forget();
}

/** Tell the stub how a service should behave for the next call. */
function stubMode(string $service, string $mode): void
{
    $dir = __DIR__ . '/stub/state';
    @mkdir($dir, 0777, true);
    file_put_contents($dir . '/' . $service, $mode);
}

function stubReset(): void
{
    foreach (glob(__DIR__ . '/stub/state/*') ?: [] as $file) {
        @unlink($file);
    }
}

// ---------------------------------------------------------------------------
// The Calendar stub (tests/stub/calendar_v1.php) — its database is a file the
// stub server and the tests share, so a test can seed somebody's diary and
// read back exactly what Voice sent.
// ---------------------------------------------------------------------------

/** @return array<string, mixed> events, idempotency, log, denied, stale */
function calendarStub(): array
{
    $raw = @file_get_contents(__DIR__ . '/stub/state/calendar.json');
    $db = is_string($raw) ? json_decode($raw, true) : null;

    return (is_array($db) ? $db : []) + ['events' => [], 'idempotency' => [], 'log' => [], 'denied' => [], 'stale' => []];
}

/** @param array<string, mixed> $db */
function calendarStubSave(array $db): void
{
    @mkdir(__DIR__ . '/stub/state', 0777, true);
    file_put_contents(__DIR__ . '/stub/state/calendar.json', json_encode($db, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), LOCK_EX);
}

/**
 * What Voice sent Calendar, oldest first.
 *
 * @return list<array<string, mixed>>
 */
function calendarRequests(?string $method = null, ?string $pathPrefix = null): array
{
    return array_values(array_filter(
        calendarStub()['log'],
        static fn (array $r): bool => ($method === null || $r['method'] === $method)
            && ($pathPrefix === null || str_starts_with((string) $r['path'], $pathPrefix)),
    ));
}

/**
 * Something already in a person's diary — written by another product, or by
 * them. Returns the event id.
 *
 * @param array<string, mixed> $extra
 */
function calendarSeedEvent(string $subscriber, string $startIso, string $endIso, array $extra = []): string
{
    $db = calendarStub();
    $id = $extra['id'] ?? Uuid::v4();
    $db['events'][$id] = $extra + [
        'id' => $id, 'title' => 'Busy', 'description' => '', 'start_at' => $startIso, 'end_at' => $endIso,
        'all_day' => false, 'timezone' => 'UTC', 'category' => 'meeting', 'priority' => 'normal',
        'status' => 'confirmed', 'busy_status' => 'busy', 'visibility' => 'default',
        'reminder_offset_minutes' => null, 'recurrence_rule' => null, 'source' => 'aicountly_native',
        'source_app' => 'appointments', 'source_app_verified' => true, 'source_ref' => 'booking-' . substr($id, 0, 8),
        'created_by_kind' => 'service', 'created_by_app' => 'appointments', 'updated_by_app' => 'appointments',
        'managed_by_app' => true, 'version' => 1, 'created_at' => gmdate('Y-m-d\TH:i:s\Z'),
        'updated_at' => gmdate('Y-m-d\TH:i:s\Z'), 'is_compliance' => false, '_subscriber' => $subscriber,
    ];
    calendarStubSave($db);

    return $id;
}

/** Change one event in the stub, as somebody else would have in Calendar. */
function calendarStubEdit(string $eventId, array $changes): void
{
    $db = calendarStub();
    $db['events'][$eventId] = $changes + $db['events'][$eventId];
    calendarStubSave($db);
}

/** Mark people the stub treats as not members of the company (denied) or with a stale external calendar. */
function calendarStubFlag(string $list, array $subscribers): void
{
    $db = calendarStub();
    $db[$list] = array_values($subscribers);
    calendarStubSave($db);
}

/**
 * Run the recovery worker the way cron does — a separate process — after
 * making every open operation due now.
 */
function runRecovery(): string
{
    Db::run("UPDATE voice_external_operations SET next_check_at = NOW() - INTERVAL '1 minute'
              WHERE status IN ('pending', 'unknown', 'deferred')");

    return (string) shell_exec(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(__DIR__ . '/../bin/call-recovery.php') . ' 2>&1');
}

/** The newest diary operation for a callback. @return array<string, mixed>|null */
function lastDiaryOperation(int $callbackId): ?array
{
    return Db::first(
        "SELECT * FROM voice_external_operations WHERE callback_id = :id AND target_app = 'calendar'
          ORDER BY operation_id DESC LIMIT 1",
        ['id' => $callbackId],
    );
}

// ---------------------------------------------------------------------------
// The Appointments stub (tests/stub/appointments_v1.php) — a file database the
// stub server and the tests share, like Calendar's.
// ---------------------------------------------------------------------------

/** @return array<string, mixed> services, offers, bookings, idempotency, log */
function appointmentsStub(): array
{
    $raw = @file_get_contents(__DIR__ . '/stub/state/appointments.json');
    $db = is_string($raw) ? json_decode($raw, true) : null;

    return (is_array($db) ? $db : []) + ['services' => [], 'offers' => [], 'bookings' => [], 'idempotency' => [], 'log' => [], 'seq' => 1000];
}

/** @param array<string, mixed> $db */
function appointmentsStubSave(array $db): void
{
    @mkdir(__DIR__ . '/stub/state', 0777, true);
    file_put_contents(__DIR__ . '/stub/state/appointments.json', json_encode($db, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), LOCK_EX);
}

/**
 * What Voice sent Appointments, oldest first.
 *
 * @return list<array<string, mixed>>
 */
function appointmentsRequests(?string $method = null, ?string $path = null): array
{
    return array_values(array_filter(
        appointmentsStub()['log'],
        static fn (array $r): bool => ($method === null || $r['method'] === $method) && ($path === null || $r['path'] === $path),
    ));
}

/** A service Appointments offers in a company. @param array<string, mixed> $extra */
function appointmentsSeedService(int $cmpId, array $extra = []): string
{
    $db = appointmentsStub();
    $uuid = $extra['service_uuid'] ?? Uuid::v4();
    $db['services'][$uuid] = $extra + [
        'service_uuid' => $uuid, 'cmp_id' => $cmpId, 'name' => 'Consultation', 'duration_minutes' => 30,
        'is_active' => true, 'is_bookable_online' => true, 'deposit_required' => false, 'deposit_minor' => null,
        'requires_confirmation' => false,
    ];
    appointmentsStubSave($db);

    return $uuid;
}

/** A time Appointments would offer for a service with a practitioner. */
function appointmentsSeedOffer(string $serviceUuid, string $memberUuid, string $startIso): void
{
    $db = appointmentsStub();
    $db['offers'][] = ['service_uuid' => $serviceUuid, 'member_uuid' => $memberUuid, 'starts_at' => $startIso];
    appointmentsStubSave($db);
}

/** A booking somebody else already holds. */
function appointmentsSeedBooking(int $cmpId, string $serviceUuid, string $memberUuid, string $startIso, string $phone = '+919811100000'): string
{
    $db = appointmentsStub();
    $uuid = Uuid::v4();
    $db['seq']++;
    $db['bookings'][$uuid] = [
        'booking_uuid' => $uuid, 'cmp_id' => $cmpId, 'reference' => 'APT-' . $db['seq'], 'status' => 'CONFIRMED',
        'service_uuid' => $serviceUuid, 'member_uuid' => $memberUuid, 'starts_at' => $startIso,
        'ends_at' => gmdate('Y-m-d\\TH:i:s\\Z', (int) strtotime($startIso) + 1800), 'timezone' => 'Asia/Kolkata',
        'booking_source' => 'STAFF_BOOKING', 'client_name' => 'Someone Else', 'client_phone' => $phone,
        'client_email' => '', 'contact_uuid' => null, 'internal_notes' => '', 'calendar_state' => 'synced',
    ];
    appointmentsStubSave($db);

    return $uuid;
}
