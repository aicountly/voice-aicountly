<?php

declare(strict_types=1);

namespace Aicountly\Api\Controllers;

use Aicountly\Api\Audit;
use Aicountly\Api\Db;
use Aicountly\Api\Domain\CallingPolicy;
use Aicountly\Api\Domain\PresenceService;
use Aicountly\Api\Http;
use Aicountly\Api\Permissions;
use Aicountly\Api\Settings;
use Aicountly\Api\Support\Clock;

/**
 * The supporting management screens: numbers, queues, agents, teams,
 * voicemail, settings, suppression and the audit trail.
 *
 * Each is a small CRUD surface over one Voice-owned table, gated by its own
 * permission. They are grouped here rather than split into eight one-method
 * controllers because they share their shape entirely.
 */
final class WorkspaceController extends Controller
{
    // -----------------------------------------------------------------------
    // Numbers
    // -----------------------------------------------------------------------

    public static function numbers(): never
    {
        [, $ctx] = self::enter('voice.call.view');
        [$scope, $params] = $ctx->scopeClause('n');

        $rows = Db::all(
            'SELECT n.*, t.name AS team_name, f.name AS flow_name, c.label AS connection_label
               FROM voice_numbers n
               LEFT JOIN voice_teams t                ON t.team_id = n.team_id
               LEFT JOIN voice_call_flows f           ON f.flow_id = n.inbound_flow_id
               LEFT JOIN voice_provider_connections c ON c.connection_id = n.connection_id
              WHERE ' . $scope . ' ORDER BY n.is_active DESC, n.e164
              LIMIT 200',
            $params,
        );

        Http::data(array_map(static fn (array $r): array => [
            'number_id'   => (int) $r['number_id'],
            'e164'        => (string) $r['e164'],
            'masked'      => CallingPolicy::mask((string) $r['e164']),
            'label'       => (string) $r['label'],
            'country'     => (string) $r['country'],
            'number_type' => (string) $r['number_type'],
            'team_id'     => $r['team_id'] === null ? null : (int) $r['team_id'],
            'team_name'   => $r['team_name'],
            'inbound_flow_id' => $r['inbound_flow_id'] === null ? null : (int) $r['inbound_flow_id'],
            'flow_name'   => $r['flow_name'],
            'connection_id' => $r['connection_id'] === null ? null : (int) $r['connection_id'],
            'connection_label' => $r['connection_label'],
            'recording_policy' => (string) $r['recording_policy'],
            'business_hours'   => Db::jsonColumn($r['business_hours'] ?? null),
            'routing_status'   => (string) $r['routing_status'],
            'status_detail'    => $r['status_detail'],
            'is_active'   => (bool) $r['is_active'],
        ], $rows));
    }

    public static function saveNumber(): never
    {
        [, $ctx] = self::enter('voice.numbers.manage');
        $body = Http::body();

        $e164 = CallingPolicy::normalise((string) ($body['e164'] ?? ''));
        if ($e164 === null) {
            Http::validationFailed('That is not a number in E.164 form.', ['e164' => 'Use +<country><number>.']);
        }

        $values = [
            'e164'             => $e164,
            'label'            => trim((string) ($body['label'] ?? '')),
            'country'          => strtoupper(trim((string) ($body['country'] ?? 'IN'))),
            'number_type'      => (string) ($body['number_type'] ?? 'landline'),
            'connection_id'    => isset($body['connection_id']) ? (int) $body['connection_id'] : null,
            'team_id'          => isset($body['team_id']) ? (int) $body['team_id'] : null,
            'inbound_flow_id'  => isset($body['inbound_flow_id']) ? (int) $body['inbound_flow_id'] : null,
            'recording_policy' => in_array($body['recording_policy'] ?? '', ['inherit', 'always', 'never', 'on_consent'], true)
                ? (string) $body['recording_policy'] : 'inherit',
            'business_hours'   => is_array($body['business_hours'] ?? null) ? $body['business_hours'] : [],
            'routing_status'   => (string) ($body['routing_status'] ?? 'active'),
            'is_active'        => (bool) ($body['is_active'] ?? true),
            'updated_at'       => Clock::sql(Clock::now()),
        ];

        $numberId = isset($body['number_id']) ? (int) $body['number_id'] : 0;
        if ($numberId > 0) {
            $updated = Db::update('voice_numbers', $values, ['number_id' => $numberId, 'cmp_id' => $ctx->cmpId]);
            if ($updated === 0) {
                Http::notFound('That number is not in this company.');
            }
        } else {
            $numberId = (int) Db::insert('voice_numbers', $values + ['cmp_id' => $ctx->cmpId, 'bo_id' => $ctx->boId], 'number_id');
        }

        Http::data(['number_id' => $numberId]);
    }

    // -----------------------------------------------------------------------
    // Queues and ring groups
    // -----------------------------------------------------------------------

    public static function queues(): never
    {
        [, $ctx] = self::enter('voice.call.view');
        [$scope, $params] = $ctx->scopeClause('q');

        $rows = Db::all('SELECT q.* FROM voice_queues q WHERE ' . $scope . ' ORDER BY q.name', $params);

        $out = [];
        foreach ($rows as $row) {
            $queueId = (int) $row['queue_id'];
            $out[] = [
                'queue_id'    => $queueId,
                'name'        => (string) $row['name'],
                'kind'        => (string) $row['kind'],
                'strategy'    => (string) $row['strategy'],
                'required_skills' => Db::jsonColumn($row['required_skills'] ?? null),
                'languages'   => Db::jsonColumn($row['languages'] ?? null),
                'ring_seconds' => (int) $row['ring_seconds'],
                'wrap_up_seconds' => (int) $row['wrap_up_seconds'],
                'timeout_seconds' => (int) $row['timeout_seconds'],
                'overflow_type' => $row['overflow_type'],
                'overflow_ref'  => $row['overflow_ref'],
                'max_waiting'   => (int) $row['max_waiting'],
                'announcements' => Db::jsonColumn($row['announcements'] ?? null),
                'business_hours' => Db::jsonColumn($row['business_hours'] ?? null),
                'holiday_routing' => Db::jsonColumn($row['holiday_routing'] ?? null),
                'ai_agent_id' => $row['ai_agent_id'] === null ? null : (int) $row['ai_agent_id'],
                'escalation_queue_id' => $row['escalation_queue_id'] === null ? null : (int) $row['escalation_queue_id'],
                'is_active'   => (bool) $row['is_active'],
                'members'     => Db::all(
                    'SELECT m.queue_member_id, m.agent_id, m.team_id, m.priority, m.is_active,
                            a.extension, a.user_uuid, t.name AS team_name
                       FROM voice_queue_members m
                       LEFT JOIN voice_agents a ON a.agent_id = m.agent_id
                       LEFT JOIN voice_teams t  ON t.team_id = m.team_id
                      WHERE m.queue_id = :id ORDER BY m.priority, m.queue_member_id',
                    ['id' => $queueId],
                ),
                // A queue with no overflow drops callers. Said here rather than
                // discovered by a caller.
                'warnings' => $row['overflow_type'] === null
                    ? ['No overflow destination: a caller who is not answered has nowhere to go.']
                    : [],
            ];
        }

        Http::data($out);
    }

    public static function saveQueue(): never
    {
        [, $ctx] = self::enter('voice.queues.manage');
        $body = Http::body();

        $name = trim((string) ($body['name'] ?? ''));
        if ($name === '') {
            Http::validationFailed('Give the queue a name.');
        }

        $values = [
            'name'        => $name,
            'kind'        => in_array($body['kind'] ?? '', ['queue', 'ring_group'], true) ? (string) $body['kind'] : 'queue',
            'strategy'    => in_array($body['strategy'] ?? '', ['longest_idle', 'round_robin', 'simultaneous', 'skill_based', 'linear'], true)
                ? (string) $body['strategy'] : 'longest_idle',
            'required_skills' => is_array($body['required_skills'] ?? null) ? $body['required_skills'] : [],
            'languages'   => is_array($body['languages'] ?? null) ? $body['languages'] : [],
            'ring_seconds' => max(5, min(120, (int) ($body['ring_seconds'] ?? 20))),
            'wrap_up_seconds' => max(0, min(600, (int) ($body['wrap_up_seconds'] ?? 30))),
            'timeout_seconds' => max(10, min(3600, (int) ($body['timeout_seconds'] ?? 120))),
            'overflow_type' => in_array($body['overflow_type'] ?? '', ['queue', 'voicemail', 'number', 'ai_agent', 'hangup'], true)
                ? (string) $body['overflow_type'] : null,
            'overflow_ref' => isset($body['overflow_ref']) ? (string) $body['overflow_ref'] : null,
            'max_waiting' => max(0, (int) ($body['max_waiting'] ?? 0)),
            'announcements' => is_array($body['announcements'] ?? null) ? $body['announcements'] : [],
            'business_hours' => is_array($body['business_hours'] ?? null) ? $body['business_hours'] : [],
            'holiday_routing' => is_array($body['holiday_routing'] ?? null) ? $body['holiday_routing'] : [],
            'ai_agent_id' => isset($body['ai_agent_id']) ? (int) $body['ai_agent_id'] : null,
            'escalation_queue_id' => isset($body['escalation_queue_id']) ? (int) $body['escalation_queue_id'] : null,
            'is_active'   => (bool) ($body['is_active'] ?? true),
            'updated_at'  => Clock::sql(Clock::now()),
        ];

        $queueId = isset($body['queue_id']) ? (int) $body['queue_id'] : 0;
        if ($queueId > 0) {
            if (Db::update('voice_queues', $values, ['queue_id' => $queueId, 'cmp_id' => $ctx->cmpId]) === 0) {
                Http::notFound('That queue is not in this company.');
            }
        } else {
            $queueId = (int) Db::insert('voice_queues', $values + ['cmp_id' => $ctx->cmpId, 'bo_id' => $ctx->boId], 'queue_id');
        }

        Http::data(['queue_id' => $queueId]);
    }

    // -----------------------------------------------------------------------
    // Agents and teams
    // -----------------------------------------------------------------------

    public static function agents(): never
    {
        [, $ctx] = self::enter('voice.call.view');

        Http::data([
            'agents'  => PresenceService::roster($ctx),
            'summary' => PresenceService::summary($ctx),
            'teams'   => Db::all(
                'SELECT t.team_id, t.name, t.description, t.is_active,
                        (SELECT COUNT(*) FROM voice_team_members m WHERE m.team_id = t.team_id) AS members
                   FROM voice_teams t WHERE t.cmp_id = :cmp ORDER BY t.name',
                ['cmp' => $ctx->cmpId],
            ),
            // The portal owns names and photos; the client resolves them from
            // user_uuid rather than Voice storing a copy.
            'note' => 'Agent names come from the AICOUNTLY portal. Voice stores only the extension, skills and availability.',
        ]);
    }

    public static function saveAgent(): never
    {
        [, $ctx] = self::enter('voice.team.manage');
        $body = Http::body();

        $userUuid = trim((string) ($body['user_uuid'] ?? ''));
        if ($userUuid === '') {
            Http::validationFailed('Which AICOUNTLY user is this agent?');
        }

        $values = [
            'extension'  => isset($body['extension']) && $body['extension'] !== '' ? (string) $body['extension'] : null,
            'voice_role' => in_array($body['voice_role'] ?? '', ['agent', 'supervisor', 'admin'], true)
                ? (string) $body['voice_role'] : 'agent',
            'skills'     => is_array($body['skills'] ?? null) ? $body['skills'] : [],
            'languages'  => is_array($body['languages'] ?? null) ? $body['languages'] : [],
            'capabilities' => is_array($body['capabilities'] ?? null) ? $body['capabilities'] : [],
            'is_active'  => (bool) ($body['is_active'] ?? true),
            'updated_at' => Clock::sql(Clock::now()),
        ];

        $agentId = isset($body['agent_id']) ? (int) $body['agent_id'] : 0;
        if ($agentId > 0) {
            if (Db::update('voice_agents', $values, ['agent_id' => $agentId, 'cmp_id' => $ctx->cmpId]) === 0) {
                Http::notFound('That agent is not in this company.');
            }
        } else {
            $agentId = (int) Db::insert('voice_agents', $values + [
                'cmp_id'    => $ctx->cmpId,
                'bo_id'     => $ctx->boId,
                'user_uuid' => $userUuid,
            ], 'agent_id');
        }

        Http::data(['agent_id' => $agentId]);
    }

    /**
     * Change availability.
     *
     * Changing your OWN is part of handling calls. Changing somebody else's is
     * a different permission — an agent must not be able to mark a colleague
     * unavailable.
     */
    public static function presence(): never
    {
        $body = Http::body();
        $agentId = (int) ($body['agent_id'] ?? 0);

        [$auth, $ctx] = self::enter('voice.call.handle');

        $owner = (string) (Db::scalar(
            'SELECT user_uuid FROM voice_agents WHERE agent_id = :id AND cmp_id = :cmp',
            ['id' => $agentId, 'cmp' => $ctx->cmpId],
        ) ?? '');

        if ($owner === '') {
            Http::notFound('That agent is not in this company.');
        }
        if ($owner !== $auth->uuid) {
            Permissions::assert($ctx, $auth, 'voice.presence.manage');
        }

        if (($body['heartbeat'] ?? false) === true) {
            Http::data(['heartbeat' => PresenceService::heartbeat($ctx, $agentId), 'interval_seconds' => PresenceService::HEARTBEAT_SECONDS]);
        }

        $result = PresenceService::set($ctx, $agentId, (string) ($body['presence'] ?? ''), $body['reason'] ?? null);
        if (!$result['ok']) {
            self::fail($result['code'], $result['message']);
        }

        Http::data(['presence' => $body['presence'], 'heartbeat_interval_seconds' => PresenceService::HEARTBEAT_SECONDS]);
    }

    // -----------------------------------------------------------------------
    // Voicemail
    // -----------------------------------------------------------------------

    public static function voicemail(): never
    {
        [, $ctx] = self::enter('voice.voicemail.view');
        $params = Http::listParams(['received_at'], 'received_at');

        [$scope, $bindings] = $ctx->scopeClause('v');
        $where = [$scope];
        $status = Http::param('status');
        if ($status !== null && $status !== '') {
            $where[] = 'v.status = :status';
            $bindings['status'] = $status;
        }

        $whereSql = implode(' AND ', $where);
        $total = (int) (Db::scalar('SELECT COUNT(*) FROM voice_voicemails v WHERE ' . $whereSql, $bindings) ?? 0);

        $rows = Db::all(
            'SELECT v.*, r.recording_uuid, r.duration_seconds AS recording_seconds
               FROM voice_voicemails v
               LEFT JOIN voice_recordings r ON r.recording_id = v.recording_id AND r.deleted_at IS NULL
              WHERE ' . $whereSql . '
              ORDER BY v.received_at ' . $params['order'] . '
              LIMIT ' . $params['limit'] . ' OFFSET ' . $params['offset'],
            $bindings,
        );

        Http::list(array_map(static fn (array $r): array => [
            'voicemail_id' => (int) $r['voicemail_id'],
            'call_id'      => $r['call_id'] === null ? null : (int) $r['call_id'],
            'from_masked'  => $r['from_e164'] === null ? null : CallingPolicy::mask((string) $r['from_e164']),
            'contact_ref'  => $r['contact_ref'],
            'duration_seconds' => (int) $r['duration_seconds'],
            'status'       => (string) $r['status'],
            'received_at'  => $r['received_at'],
            'recording_uuid' => $r['recording_uuid'],
            'callback_id'  => $r['callback_id'] === null ? null : (int) $r['callback_id'],
        ], $rows), $total, $params['limit'], $params['offset']);
    }

    // -----------------------------------------------------------------------
    // Settings, suppression, audit
    // -----------------------------------------------------------------------

    public static function settings(): never
    {
        [$auth, $ctx] = self::enter('voice.dashboard.view');

        Http::data(Settings::forCompany($ctx->cmpId) + [
            'can_edit' => Permissions::allows($ctx, $auth, 'voice.settings.manage'),
            'policy_note' => 'These are settings this business chooses. Voice enforces what it is told; it does not certify that any combination meets a legal obligation.',
        ]);
    }

    public static function saveSettings(): never
    {
        [$auth, $ctx] = self::enter('voice.settings.manage');
        $body = Http::body();

        // Retention is separately permissioned: shortening it destroys evidence.
        $touchesRetention = isset($body['recording_retention_days']) || isset($body['transcript_retention_days']);
        if ($touchesRetention) {
            Permissions::assert($ctx, $auth, 'voice.retention.manage');
        }

        $before = Settings::forCompany($ctx->cmpId);
        $after = Settings::save($ctx, $body, $auth->uuid);

        $changed = [];
        foreach ($after as $key => $value) {
            if (($before[$key] ?? null) !== $value) {
                $changed[] = $key;
            }
        }

        Audit::record(
            $ctx,
            $auth,
            $touchesRetention ? Audit::RETENTION_CHANGED : Audit::SETTINGS_CHANGED,
            'settings',
            (string) $ctx->cmpId,
            ['changed' => $changed],
        );

        Http::data($after);
    }

    public static function suppressions(): never
    {
        [, $ctx] = self::enter('voice.campaigns.view');
        $params = Http::listParams(['created_at'], 'created_at');

        $total = (int) (Db::scalar(
            'SELECT COUNT(*) FROM voice_suppressions WHERE cmp_id = :cmp',
            ['cmp' => $ctx->cmpId],
        ) ?? 0);

        $rows = Db::all(
            'SELECT * FROM voice_suppressions WHERE cmp_id = :cmp
              ORDER BY created_at ' . $params['order'] . '
              LIMIT ' . $params['limit'] . ' OFFSET ' . $params['offset'],
            ['cmp' => $ctx->cmpId],
        );

        Http::list(array_map(static fn (array $r): array => [
            'suppression_id' => (int) $r['suppression_id'],
            'e164'   => (string) $r['e164'],
            'masked' => CallingPolicy::mask((string) $r['e164']),
            'reason' => (string) $r['reason'],
            'source' => (string) $r['source'],
            'note'   => (string) $r['note'],
            'created_at' => $r['created_at'],
            'expires_at' => $r['expires_at'],
        ], $rows), $total, $params['limit'], $params['offset']);
    }

    public static function suppress(): never
    {
        [$auth, $ctx] = self::enter('voice.campaigns.manage');
        $body = Http::body();

        $e164 = CallingPolicy::normalise((string) ($body['e164'] ?? ''));
        if ($e164 === null) {
            Http::validationFailed('That is not a number in E.164 form.');
        }

        CallingPolicy::suppress(
            $ctx,
            $e164,
            (string) ($body['reason'] ?? 'opt_out'),
            (string) ($body['source'] ?? 'manual'),
            $auth->uuid,
        );

        Http::data(['suppressed' => true, 'masked' => CallingPolicy::mask($e164)]);
    }

    public static function audit(): never
    {
        [, $ctx] = self::enter('voice.audit.view');
        $params = Http::listParams(['occurred_at'], 'occurred_at');

        [$scope, $bindings] = $ctx->scopeClause();
        $where = [$scope];

        foreach (['action' => 'action', 'entity_type' => 'entity_type', 'actor_uuid' => 'actor_uuid'] as $param => $column) {
            $value = Http::param($param);
            if ($value !== null && $value !== '') {
                $where[] = $column . ' = :' . $param;
                $bindings[$param] = $value;
            }
        }

        $whereSql = implode(' AND ', $where);
        $total = (int) (Db::scalar('SELECT COUNT(*) FROM voice_audit_events WHERE ' . $whereSql, $bindings) ?? 0);

        $rows = Db::all(
            'SELECT audit_id, occurred_at, actor_uuid, actor_kind, actor_app, action,
                    entity_type, entity_id, detail, correlation_id
               FROM voice_audit_events WHERE ' . $whereSql . '
              ORDER BY occurred_at ' . $params['order'] . '
              LIMIT ' . $params['limit'] . ' OFFSET ' . $params['offset'],
            $bindings,
        );

        Http::list(array_map(static fn (array $r): array => [
            'audit_id'    => (int) $r['audit_id'],
            'occurred_at' => $r['occurred_at'],
            'actor_uuid'  => $r['actor_uuid'],
            'actor_kind'  => (string) $r['actor_kind'],
            'actor_app'   => $r['actor_app'],
            'action'      => (string) $r['action'],
            'entity_type' => $r['entity_type'],
            'entity_id'   => $r['entity_id'],
            'detail'      => Db::jsonColumn($r['detail'] ?? null),
        ], $rows), $total, $params['limit'], $params['offset']);
    }

    /** The permission catalogue and what this user holds — what the UI hides links with. */
    public static function access(): never
    {
        [$auth, $ctx] = self::enter(null);

        Http::data([
            'catalog'   => Permissions::CATALOG,
            'granted'   => Permissions::granted($ctx, $auth),
            'grantable' => Permissions::allows($ctx, $auth, 'voice.access.manage')
                ? Permissions::grantable($ctx, $auth)
                : [],
            'profiles'  => Permissions::allows($ctx, $auth, 'voice.access.manage')
                ? Db::all(
                    'SELECT profile_id, name, description, permissions, is_active
                       FROM voice_permission_profiles WHERE cmp_id = :cmp ORDER BY name',
                    ['cmp' => $ctx->cmpId],
                )
                : [],
            'note' => 'Hiding a control in the browser is a courtesy. Every one of these is asserted again in the backend.',
        ]);
    }
}
