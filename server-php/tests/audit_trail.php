<?php

declare(strict_types=1);

/**
 * The audit trail: writer, immutability, coverage, and the read API.
 *
 * Run with tests/run.sh against the throwaway database (and the stub). It uses
 * its own companies and resets the table the only way the table allows, with
 * TRUNCATE (the append-only trigger, migration 014, refuses UPDATE and DELETE).
 */

namespace Aicountly\Api\Tests;

use Aicountly\Api\Audit;
use Aicountly\Api\Auth;
use Aicountly\Api\Context;
use Aicountly\Api\Controllers\AuditController;
use Aicountly\Api\Db;
use Aicountly\Api\Env;
use Aicountly\Api\Permissions;

require __DIR__ . '/../src/Autoload.php';
require __DIR__ . '/support.php';

Env::load(__DIR__ . '/../.env');

const CMP = 4701;
const OTHER_CMP = 4702;
const BIG_CMP = 4703;
const ADMIN = 'user-audit-admin';
const READER = 'user-audit-reader';
const EXPORTER = 'user-audit-exporter';

Db::run('TRUNCATE voice_audit_events RESTART IDENTITY');
foreach ([CMP, OTHER_CMP] as $cmp) {
    foreach (['voice_permission_assignments', 'voice_permission_profiles', 'voice_numbers', 'voice_queues', 'voice_agents',
             'voice_provider_connections', 'voice_settings', 'voice_callbacks', 'voice_campaigns'] as $table) {
        Db::run('DELETE FROM ' . $table . ' WHERE cmp_id = :c', ['c' => $cmp]);
    }
}

$admin = markOwner(Auth::forTesting(ADMIN));
$reader = Auth::forTesting(READER);
$exporter = Auth::forTesting(EXPORTER);

echo "Voice audit trail\n" . str_repeat('=', 62) . "\n";

function trail(string $where = 'TRUE', array $params = []): array
{
    return Db::all('SELECT * FROM voice_audit_events WHERE ' . $where . ' ORDER BY audit_id', $params);
}

function as_user(Auth $auth, int $cmp = CMP): void
{
    Auth::adopt($auth);
    Context::resetForTesting();
    Context::trustForTesting($cmp, $auth, isTestOwner($auth));
}

// ===========================================================================
T::group('1. Writer: context on the row');
// ===========================================================================
{
    $_SERVER['HTTP_USER_AGENT'] = 'AuditTest/1.0';
    $_SERVER['REMOTE_ADDR'] = '203.0.113.9';
    Audit::resetRequest();
    $ctx = scope(CMP, $admin);

    Audit::record($ctx, $admin, 'voice.number.updated', 'number', '7', ['k' => 'v'], label: 'Front desk');
    $row = trail("entity_id = '7'")[0];
    T::same(ADMIN, $row['actor_uuid'], 'the actor is the session user');
    T::same('Front desk', $row['entity_label'], 'the record label is stored');
    T::same('Number Front desk: number updated', $row['summary'], 'a readable one-line summary is built');
    T::same('info', $row['severity'], 'severity defaults to info for a best-effort row');
    T::same('AuditTest/1.0', $row['user_agent'], 'the user agent is stored');
    T::ok(preg_match('/^[a-f0-9]{24}$/', (string) $row['request_id']) === 1, 'a request id is generated');
    T::ok($row['ip_hash'] !== null && !str_contains((string) $row['ip_hash'], '203.0.113.9'), 'the client address is stored only as a hash');

    Audit::material($ctx, $admin, 'voice.access.changed', 'permission_profile', '9', [], label: 'Supervisors');
    $row = trail("entity_id = '9'")[0];
    T::same('security', $row['severity'], 'an access change is a security row');
    T::same(trail("entity_id = '7'")[0]['request_id'], $row['request_id'], 'rows of one request share the request id');

    Audit::record($ctx, null, 'voice.campaign.paused', 'campaign', '3', ['by' => 'worker'], actor: 'system:campaign-worker');
    $row = trail("entity_id = '3'")[0];
    T::same('system:campaign-worker', $row['actor_uuid'], 'a system write names its job');
    T::same('system', $row['actor_kind'], 'and is marked as system');
}

// ===========================================================================
T::group('2. Writer: diff helper');
// ===========================================================================
{
    $changes = Audit::changes(
        ['name' => 'A', 'priority' => 'normal', 'password' => 'x', 'api_key' => 'k1', 'credentials_enc' => 'blob', 'e164' => '+919876500123', 'reason' => 'call him back'],
        ['name' => 'B', 'priority' => 'normal', 'password' => 'y', 'api_key' => 'k2', 'credentials_enc' => 'blob2', 'e164' => '+919876500124', 'reason' => 'something else'],
    );
    $byField = [];
    foreach ($changes as $c) {
        $byField[$c['field']] = $c;
    }
    T::same(['name', 'e164', 'reason'], array_keys($byField), 'unchanged fields and secrets are skipped');
    T::same(['field' => 'name', 'label' => 'Name', 'old' => 'A', 'new' => 'B'], $byField['name'], 'a change carries field, label, old and new');
    T::ok(!str_contains((string) $byField['e164']['old'], '9876500123') && !str_contains((string) $byField['e164']['new'], '9876500124'),
        'telephone numbers are masked in a diff');
    T::same('[text not logged]', $byField['reason']['new'], 'free text is recorded as changed, never as its words');

    $ctx = scope(CMP, $admin);
    Audit::record($ctx, $admin, 'voice.test.snapshot', 'settings', '1', ['api_key' => 'SECRET-1', 'nested' => ['token' => 'SECRET-2', 'url' => 'https://h.example/p?signature=SECRET-3']],
        before: ['label' => 'L', 'secret' => 'SECRET-4'], after: ['label' => 'M', 'secret' => 'SECRET-5', 'greeting' => 'Hello caller']);
    $json = (string) json_encode(trail("action = 'voice.test.snapshot'")[0]);
    T::ok(!str_contains($json, 'SECRET-') && !str_contains($json, 'Hello caller'), 'no secret, signed URL or free text comes to rest in the row');
}

// ===========================================================================
T::group('3. Immutability and fail-closed');
// ===========================================================================
{
    $id = (int) Db::scalar('SELECT MIN(audit_id) FROM voice_audit_events');
    $blocked = static function (string $sql, array $p = []): bool {
        try {
            Db::run($sql, $p);
        } catch (\PDOException $e) {
            return str_contains($e->getMessage(), 'append-only');
        }

        return false;
    };
    T::ok($blocked('UPDATE voice_audit_events SET summary = \'edited\' WHERE audit_id = :i', ['i' => $id]), 'UPDATE of an audit row is refused');
    T::ok($blocked('DELETE FROM voice_audit_events WHERE audit_id = :i', ['i' => $id]), 'DELETE of an audit row is refused');
    T::ok((int) Db::scalar('SELECT COUNT(*) FROM voice_audit_events WHERE audit_id = :i', ['i' => $id]) === 1, 'and the row is still there');

    // A material row rides the business transaction: roll the change back and the row goes with it.
    $ctx = scope(CMP, $admin);
    try {
        Db::transaction(static function () use ($ctx, $admin): void {
            Audit::material($ctx, $admin, 'voice.test.rolled_back', 'settings', '1');
            throw new \RuntimeException('business failure');
        });
    } catch (\RuntimeException) {
    }
    T::same([], trail("action = 'voice.test.rolled_back'"), 'a material row rolls back with its transaction');

    // And when the row cannot be written, the change is refused rather than left unrecorded.
    Db::run('ALTER TABLE voice_audit_events ADD CONSTRAINT ck_test_refuse CHECK (action <> \'voice.test.refused\') NOT VALID');
    try {
        $threw = false;
        try {
            Audit::material($ctx, $admin, 'voice.test.refused', 'settings', '1');
        } catch (\RuntimeException) {
            $threw = true;
        }
        T::ok($threw, 'material() throws when the audit row cannot be written');
        Audit::record($ctx, $admin, 'voice.test.refused', 'settings', '1');
        T::ok(true, 'while record() swallows the same failure');
        Db::transaction(static function () use ($ctx, $admin): void {
            Db::run('INSERT INTO voice_settings (cmp_id) VALUES (:c) ON CONFLICT DO NOTHING', ['c' => OTHER_CMP]);
            Audit::record($ctx, $admin, 'voice.test.refused', 'settings', '1');
            Db::run('UPDATE voice_settings SET timezone = \'UTC\' WHERE cmp_id = :c', ['c' => OTHER_CMP]);
        });
        T::ok(true, 'a failed best-effort row inside a transaction does not poison it (savepoint)');
    } finally {
        Db::run('ALTER TABLE voice_audit_events DROP CONSTRAINT ck_test_refuse');
        Db::run('DELETE FROM voice_settings WHERE cmp_id = :c', ['c' => OTHER_CMP]);
    }
}

// ===========================================================================
T::group('4. Coverage through the real endpoints');
// ===========================================================================
{
    $connectionId = seedConnection(CMP);
    seedSettings(CMP);
    as_user($admin);
    $q = ['cmp_id' => (string) CMP];

    $r = request('POST', '/v1/numbers', $q, ['e164' => '+918066000333', 'label' => 'Front desk', 'connection_id' => $connectionId]);
    T::same(200, $r['status'], 'a number is saved');
    $numberId = (int) $r['body']['data']['number_id'];
    $row = trail("action = 'voice.number.created'")[0];
    T::same((string) $numberId, $row['entity_id'], 'number creation writes a row');
    T::ok(!str_contains((string) json_encode($row), '8066000333'), 'with the number masked');

    request('POST', '/v1/numbers', $q, ['number_id' => $numberId, 'e164' => '+918066000333', 'label' => 'Reception', 'connection_id' => $connectionId]);
    $row = trail("action = 'voice.number.updated' AND entity_id = '" . $numberId . "'")[0];
    $changes = Db::jsonColumn($row['changes']);
    T::same(['label'], array_column($changes, 'field'), 'a number update records only the field that changed');
    T::same(['Front desk', 'Reception'], [$changes[0]['old'], $changes[0]['new']], 'with old and new values');
    $before = count(trail("action = 'voice.number.updated'"));
    request('POST', '/v1/numbers', $q, ['number_id' => $numberId, 'e164' => '+918066000333', 'label' => 'Reception', 'connection_id' => $connectionId]);
    T::same($before, count(trail("action = 'voice.number.updated'")), 'saving with no change writes no row');

    $r = request('POST', '/v1/queues', $q, ['name' => 'Support']);
    T::ok(count(trail("action = 'voice.queue.created' AND entity_label = 'Support'")) === 1, 'queue creation is trailed');

    // Settings: material, with old and new.
    $r = request('POST', '/v1/settings', $q, ['wrap_up_seconds' => 45]);
    T::same(200, $r['status'], 'settings are saved');
    $row = trail("action = 'voice.settings.changed'")[0];
    T::same('material', $row['severity'], 'a settings change is a material row');
    T::ok(in_array('wrap_up_seconds', array_column(Db::jsonColumn($row['changes']), 'field'), true), 'and carries the field change');
    $r = request('POST', '/v1/settings', $q, ['recording_retention_days' => 30]);
    T::ok(count(trail("action = 'voice.retention.changed'")) === 1, 'a retention change is its own action');

    // Access: profile, assignment, revoke.
    $r = request('POST', '/v1/access/profiles', $q, ['name' => 'Auditors', 'permissions' => ['voice.audit.view']]);
    T::same(201, $r['status'], 'a permission profile is saved');
    $profileId = (int) $r['body']['data']['profile_id'];
    $row = trail("action = 'voice.access.changed' AND entity_type = 'permission_profile' AND entity_id = '" . $profileId . "'")[0];
    T::same('Auditors', $row['entity_label'], 'the profile is trailed by name');
    T::same('security', $row['severity'], 'as a security row');
    request('POST', '/v1/access/profiles', $q, ['profile_id' => $profileId, 'name' => 'Auditors', 'permissions' => ['voice.audit.view', 'voice.audit.export']]);
    $rows = trail("action = 'voice.access.changed' AND entity_type = 'permission_profile' AND entity_id = '" . $profileId . "'");
    T::ok(count($rows) === 2 && in_array('permissions', array_column(Db::jsonColumn($rows[1]['changes']), 'field'), true), 'editing the profile records the permission change');
    request('POST', '/v1/access/assignments', $q, ['user_uuid' => READER, 'profile_id' => $profileId]);
    T::ok(count(trail("entity_type = 'permission_assignment' AND detail->>'change' = 'profile_assigned'")) === 1, 'assigning a profile is trailed');
    request('DELETE', '/v1/access/assignments', $q + ['user_uuid' => READER, 'profile_id' => (string) $profileId]);
    T::ok(count(trail("entity_type = 'permission_assignment' AND detail->>'change' = 'profile_revoked'")) === 1, 'revoking it is trailed');

    // A role change on an agent is an access change.
    $r = request('POST', '/v1/agents', $q, ['user_uuid' => '8801', 'voice_role' => 'agent']);
    $agentId = (int) $r['body']['data']['agent_id'];
    request('POST', '/v1/agents', $q, ['agent_id' => $agentId, 'user_uuid' => '8801', 'voice_role' => 'supervisor']);
    T::ok(count(trail("action = 'voice.access.agent_role_changed'")) === 1, 'an agent role change is an access row');

    // A provider connection: the credential is never in the trail.
    $r = request('POST', '/v1/provider-connections', $q, [
        'provider' => 'gateway', 'label' => 'Carrier A', 'credentials' => ['signing_secret' => 'TOP-SECRET-VALUE', 'api_key' => 'KEY-VALUE'],
    ]);
    T::ok(in_array($r['status'], [200, 201], true), 'a provider connection is saved');
    $row = trail("action = 'voice.provider.configured' AND entity_label = 'Carrier A'")[0];
    T::ok(str_contains((string) $row['summary'], 'credentials replaced'), 'the row says the credentials were replaced');
    T::ok(!str_contains((string) json_encode($row), 'TOP-SECRET-VALUE') && !str_contains((string) json_encode($row), 'KEY-VALUE'), 'and never holds a credential value');

    // Callbacks: a reason is free text and stays out.
    $r = request('POST', '/v1/callbacks', $q, ['e164' => '+919876500123', 'reason' => 'Wants to discuss the overdue invoice']);
    T::ok(in_array($r['status'], [200, 201], true), 'a callback is created');
    $callbackId = (int) ($r['body']['data']['callback_id'] ?? 0);
    request('PUT', '/v1/callbacks/' . $callbackId, $q, ['status' => 'completed', 'reason' => 'Spoke to him about the overdue invoice']);
    $json = (string) json_encode(trail("entity_type = 'callback'"));
    T::ok(count(trail("action = 'voice.callback.updated'")) === 1, 'a callback update is trailed with its change');
    T::ok(!str_contains($json, 'overdue invoice') && !str_contains($json, '9876500123'), 'without the reason text or the full number');

    // Campaigns: created, and a launch is material.
    $r = request('POST', '/v1/campaigns', $q, ['name' => 'Autumn renewals', 'mode' => 'announcement']);
    T::same(201, $r['status'], 'a campaign is created');
    T::ok(count(trail("action = 'voice.campaign.created' AND entity_label = 'Autumn renewals'")) === 1, 'campaign creation is trailed');
    $campaignId = (int) $r['body']['data']['campaign_id'];
    Db::run('UPDATE voice_campaigns SET status = \'running\' WHERE campaign_id = :i', ['i' => $campaignId]);
    request('POST', '/v1/campaigns/' . $campaignId . '/actions', $q, ['action' => 'pause']);
    $row = trail("action = 'voice.campaign.paused' AND entity_id = '" . $campaignId . "'")[0] ?? null;
    T::ok($row !== null && $row['severity'] === 'material' && $row['entity_label'] === 'Autumn renewals', 'pausing a campaign is a material row labelled with its name');
    request('POST', '/v1/campaigns/' . $campaignId . '/actions', $q, ['action' => 'cancel']);
    T::ok(count(trail("action = 'voice.campaign.cancelled'")) === 1, 'cancelling a campaign is trailed');

    // Do-not-call.
    request('POST', '/v1/suppressions', $q, ['e164' => '+919876500555']);
    $row = trail("action = 'voice.suppression.added'")[0] ?? null;
    T::ok($row !== null && !str_contains((string) json_encode($row), '9876500555'), 'a do-not-call entry is trailed with the number masked');
}

// ===========================================================================
T::group('5. Read API: permission, scoping, filters, entity history');
// ===========================================================================
{
    // Another company's rows must never appear.
    $otherCtx = scope(OTHER_CMP, $admin);
    // An id no number of CMP can have: lifecycle.php (run before this file) ends with truncateAll(), which
    // restarts the sequences, so CMP's own number above may well be number 1.
    Audit::record($otherCtx, $admin, 'voice.number.updated', 'number', '987654321', [], label: 'OTHER COMPANY ROW');

    grant(CMP, READER, ['voice.audit.view', 'voice.dashboard.view']);
    grant(CMP, EXPORTER, ['voice.audit.view', 'voice.audit.export']);
    $q = ['cmp_id' => (string) CMP];

    as_user(Auth::forTesting('user-nobody'));
    T::same(403, request('GET', '/v1/audit-log', $q)['status'], 'the trail needs voice.audit.view');
    T::same(403, request('GET', '/v1/audit-log/export', $q)['status'], 'the export needs voice.audit.export');
    T::same(403, request('GET', '/v1/audit-log/entity/number/1', $q)['status'], 'entity history needs voice.audit.view');

    as_user($reader);
    T::same(200, request('GET', '/v1/audit-log', $q)['status'], 'voice.audit.view reads the trail');
    T::same(403, request('GET', '/v1/audit-log/export', $q)['status'], 'but cannot export it');

    $list = request('GET', '/v1/audit-log', $q + ['limit' => '200']);
    $body = $list['body'];
    T::ok(($body['meta']['total'] ?? 0) > 10, 'the list carries meta.total');
    T::ok(!str_contains((string) json_encode($body), 'OTHER COMPANY ROW'), 'another company’s rows are not in it');
    $first = $body['data'][0];
    T::ok(array_key_exists('changes', $first) && array_key_exists('before', $first) && array_key_exists('after', $first)
        && array_key_exists('summary', $first) && array_key_exists('request_id', $first), 'each row carries summary, changes, before/after and request id');
    T::ok(!array_key_exists('ip_hash', $first), 'the address hash is not served');

    $ids = static fn (array $r): array => array_column($r['body']['data'] ?? [], 'action');
    T::same(['voice.queue.created'], array_values(array_unique($ids(request('GET', '/v1/audit-log', $q + ['action' => 'voice.queue.created'])))), 'filter: action');
    $prefix = array_unique($ids(request('GET', '/v1/audit-log', $q + ['action' => 'voice.campaign.*'])));
    T::ok($prefix !== [] && count(array_filter($prefix, static fn (string $a): bool => !str_starts_with($a, 'voice.campaign.'))) === 0, 'filter: action prefix voice.campaign.*');
    $byType = request('GET', '/v1/audit-log', $q + ['entity_type' => 'queue']);
    T::ok(count($byType['body']['data']) === 1, 'filter: entity_type');
    T::ok(count(request('GET', '/v1/audit-log', $q + ['entity_label' => 'autumn'])['body']['data']) >= 1, 'filter: entity_label (search)');
    T::ok(count(request('GET', '/v1/audit-log', $q + ['actor_uuid' => 'nobody-such'])['body']['data']) === 0, 'filter: actor_uuid');
    T::ok(count(request('GET', '/v1/audit-log', $q + ['actor_uuid' => ADMIN, 'limit' => '1'])['body']['data']) === 1, 'filter: actor_uuid matches the admin; limit pages');
    T::ok(count(request('GET', '/v1/audit-log', $q + ['q' => 'Autumn'])['body']['data']) >= 1, 'filter: q searches the summary / label');
    T::ok(count(request('GET', '/v1/audit-log', $q + ['severity' => 'security'])['body']['data']) >= 1, 'filter: severity');
    $today = gmdate('Y-m-d');
    T::ok(count(request('GET', '/v1/audit-log', $q + ['from' => $today, 'to' => $today])['body']['data']) > 0, 'filter: from/to are inclusive of the day');
    T::same(0, count(request('GET', '/v1/audit-log', $q + ['to' => '2000-01-01'])['body']['data']), 'filter: to in the past is empty');
    T::same(422, request('GET', '/v1/audit-log', $q + ['from' => 'yesterday'])['status'], 'a bad date is 422');
    T::ok(count(request('GET', '/v1/audit-log', $q + ['q' => '100%'])['body']['data']) === 0, 'LIKE wildcards in a search are literal');
    T::same(2, (int) request('GET', '/v1/audit-log', $q + ['limit' => '1', 'offset' => '1'])['body']['meta']['offset'] + 1, 'offset is echoed');

    $entity = request('GET', '/v1/audit-log/entity/number/' . (int) Db::scalar("SELECT entity_id FROM voice_audit_events WHERE action = 'voice.number.created' AND cmp_id = " . CMP), $q);
    T::same(['voice.number.updated', 'voice.number.created'], array_column($entity['body']['data'], 'action'), 'entity history lists one record newest first');
    T::same(0, count(request('GET', '/v1/audit-log/entity/number/987654321', $q)['body']['data']), 'entity history is scoped to the company (the other company’s number is not here)');
}

// ===========================================================================
T::group('6. Export');
// ===========================================================================
{
    as_user($exporter);
    $q = ['cmp_id' => (string) CMP];
    $ctx = scope(CMP, $admin);
    Audit::record($ctx, $admin, 'voice.test.formula', 'settings', '1', [], label: '=HYPERLINK("http://evil")', summary: '+SUM(1,1) and @cmd');

    $before = count(trail("action = 'voice.export.taken'"));
    $r = request('GET', '/v1/audit-log/export', $q + ['q' => 'HYPERLINK']);
    T::same(200, $r['status'], 'voice.audit.export exports');
    $csv = (string) $r['body']['data']['csv'];
    T::ok(str_contains($csv, '"\'=HYPERLINK(""http://evil"")"'), 'a leading = is neutralised with an apostrophe');
    T::ok(str_contains($csv, '"\'+SUM(1,1) and @cmd"'), 'a leading + is neutralised too');
    T::same(false, $r['body']['data']['truncated'], 'a small export is not marked partial');
    T::same($before + 1, count(trail("action = 'voice.export.taken'")), 'the export is itself trailed');

    // The cap, and the partial flag.
    Db::run("INSERT INTO voice_audit_events (cmp_id, action, entity_type, entity_id, summary, actor_uuid)
             SELECT :c, 'voice.test.bulk', 'settings', g::text, 'bulk row ' || g, 'system:test' FROM generate_series(1, :n) g",
        ['c' => BIG_CMP, 'n' => AuditController::EXPORT_CAP + 7]);
    as_user($exporter, BIG_CMP);
    grant(BIG_CMP, EXPORTER, ['voice.audit.view', 'voice.audit.export']);
    $r = request('GET', '/v1/audit-log/export', ['cmp_id' => (string) BIG_CMP]);
    T::same(200, $r['status'], 'a large export is served');
    T::same(AuditController::EXPORT_CAP, $r['body']['data']['rows'], 'at the cap');
    T::same(AuditController::EXPORT_CAP + 7, $r['body']['data']['total'], 'and says what the total is');
    T::same(true, $r['body']['data']['truncated'], 'flagged truncated');
    T::ok(str_contains((string) $r['body']['data']['csv'], 'PARTIAL: the first ' . AuditController::EXPORT_CAP), 'with a PARTIAL line in the file');
}

// ===========================================================================
T::group('7. Catalogue');
// ===========================================================================
{
    T::ok(Permissions::exists('voice.audit.view') && Permissions::exists('voice.audit.export'), 'both audit permissions are in the catalogue');
    T::ok(!in_array('voice.audit.export', Permissions::DEFAULT_MEMBER_GRANTS, true), 'neither is a day-one default');
}

Db::run('DELETE FROM voice_settings WHERE cmp_id IN (:a, :b)', ['a' => CMP, 'b' => OTHER_CMP]);
exit(T::summary());
