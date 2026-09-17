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
