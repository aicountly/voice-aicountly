<?php

declare(strict_types=1);

namespace Aicountly\Api\Controllers;

use Aicountly\Api\Audit;
use Aicountly\Api\Db;
use Aicountly\Api\Domain\CampaignService;
use Aicountly\Api\Http;
use Aicountly\Api\Idempotency;
use Aicountly\Api\Settings;
use Aicountly\Api\Support\Clock;

/**
 * Campaigns.
 *
 * ## Building and launching are different permissions
 *
 * Writing a campaign dials nobody. Starting one dials hundreds of people.
 * `voice.campaigns.manage` covers the first and `voice.campaigns.launch` the
 * second, and the launch endpoint checks the second regardless of the first.
 *
 * ## The audience is stored as references
 *
 * `audience()` accepts external IDs or a saved filter. It does not accept, and
 * has nowhere to put, a name or a phone number: those are read from the owning
 * product at dispatch.
 */
final class CampaignsController extends Controller
{
    public static function index(): never
    {
        [, $ctx] = self::enter('voice.campaigns.view');
        $params = Http::listParams(['updated_at', 'created_at', 'name'], 'updated_at');

        [$scope, $bindings] = $ctx->scopeClause();
        $where = [$scope];

        $status = Http::param('status');
        if ($status !== null && $status !== '') {
            $where[] = 'status = :status';
            $bindings['status'] = $status;
        }
        if ($params['q'] !== '') {
            $where[] = 'name ILIKE :q';
            $bindings['q'] = '%' . $params['q'] . '%';
        }

        $whereSql = implode(' AND ', $where);
        $total = (int) (Db::scalar('SELECT COUNT(*) FROM voice_campaigns WHERE ' . $whereSql, $bindings) ?? 0);

        $rows = Db::all(
            'SELECT * FROM voice_campaigns WHERE ' . $whereSql . '
              ORDER BY ' . $params['sort'] . ' ' . $params['order'] . '
              LIMIT ' . $params['limit'] . ' OFFSET ' . $params['offset'],
            $bindings,
        );

        Http::list(array_map(static fn (array $r): array => self::present($r), $rows), $total, $params['limit'], $params['offset']);
    }

    public static function show(string $id): never
    {
        [, $ctx] = self::enter('voice.campaigns.view');
        $campaignId = self::id($id);

        $row = CampaignService::row($ctx, $campaignId);
        if ($row === null) {
            Http::notFound('That campaign is not in this company.');
        }

        Http::data(self::present($row) + [
            'funnel'   => CampaignService::funnel($ctx, $campaignId),
            'audience' => Db::all(
                'SELECT audience_ref_id, source, external_ref, resolution, resolution_detail, resolved_at
                   FROM voice_campaign_audience_refs WHERE campaign_id = :id ORDER BY audience_ref_id LIMIT 500',
                ['id' => $campaignId],
            ),
            'attempts' => Db::all(
                'SELECT attempt_id, audience_ref_id, attempt_no, status, outcome, skip_reason,
                        dialled_e164, scheduled_for, dispatched_at, completed_at
                   FROM voice_campaign_attempts WHERE campaign_id = :id
                  ORDER BY attempt_id DESC LIMIT 200',
                ['id' => $campaignId],
            ),
        ]);
    }

    public static function create(): never
    {
        [$auth, $ctx] = self::enter('voice.campaigns.manage');

        $key = Idempotency::fromRequest();
        $replay = Idempotency::replay($ctx, 'campaign.create', $key);
        if ($replay !== null) {
            Http::json($replay['status'], $replay['body']);
        }

        $body = Http::body();
        $name = trim((string) ($body['name'] ?? ''));
        $mode = (string) ($body['mode'] ?? '');

        if ($name === '') {
            Http::validationFailed('Give the campaign a name.', ['name' => 'A name is required.']);
        }
        if (!in_array($mode, [
            'preview', 'power', 'announcement', 'tts', 'ivr', 'ai_conversation',
            'appointment_reminder', 'requested_callback', 'renewal_followup',
        ], true)) {
            Http::validationFailed('That is not a campaign mode.', ['mode' => 'Unknown mode.']);
        }

        $settings = Settings::forCompany($ctx->cmpId);

        $campaignId = (int) Db::insert('voice_campaigns', [
            'cmp_id'      => $ctx->cmpId,
            'bo_id'       => $ctx->boId,
            'name'        => $name,
            'description' => trim((string) ($body['description'] ?? '')),
            'mode'        => $mode,
            'status'      => 'draft',
            'connection_id' => isset($body['connection_id']) ? (int) $body['connection_id'] : null,
            'number_id'   => isset($body['number_id']) ? (int) $body['number_id'] : null,
            'ai_agent_id' => isset($body['ai_agent_id']) ? (int) $body['ai_agent_id'] : null,
            'flow_id'     => isset($body['flow_id']) ? (int) $body['flow_id'] : null,
            'team_id'     => isset($body['team_id']) ? (int) $body['team_id'] : null,
            'script'      => is_array($body['script'] ?? null) ? $body['script'] : [],
            'timezone'    => (string) ($body['timezone'] ?? $settings['timezone']),
            'window_start_min' => (int) ($body['window_start_min'] ?? $settings['calling_window_start_min']),
            'window_end_min'   => (int) ($body['window_end_min'] ?? $settings['calling_window_end_min']),
            'window_days'      => $body['window_days'] ?? $settings['calling_window_days'],
            'max_concurrent'   => max(1, min(50, (int) ($body['max_concurrent'] ?? 1))),
            'calls_per_minute' => max(1, min(120, (int) ($body['calls_per_minute'] ?? 10))),
            'max_attempts'     => max(1, min(5, (int) ($body['max_attempts'] ?? 2))),
            'retry_after_minutes' => max(5, (int) ($body['retry_after_minutes'] ?? 240)),
            'budget_minor'     => max(0, (int) ($body['budget_minor'] ?? 0)),
            'created_by'       => $auth->uuid,
        ], 'campaign_id');

        $responseBody = ['data' => self::present(CampaignService::row($ctx, $campaignId) ?? [])];
        Idempotency::remember($ctx, 'campaign.create', $key, 201, $responseBody);
        Http::json(201, $responseBody);
    }

    /**
     * Replace the audience with a set of references.
     *
     * References only. There is deliberately no field here for a name or a
     * number — those belong to Contacts and CRM and are read from them.
     */
    public static function audience(string $id): never
    {
        [, $ctx] = self::enter('voice.campaigns.manage');
        $campaignId = self::id($id);

        if (CampaignService::row($ctx, $campaignId) === null) {
            Http::notFound('That campaign is not in this company.');
        }

        $body = Http::body();
        $source = (string) ($body['source'] ?? 'contacts');
        if (!in_array($source, ['contacts', 'crm', 'filter'], true)) {
            Http::validationFailed('Unknown audience source.', ['source' => 'Use contacts, crm or filter.']);
        }

        $refs = is_array($body['refs'] ?? null) ? $body['refs'] : [];
        $filter = is_array($body['filter'] ?? null) ? $body['filter'] : null;

        if ($source === 'filter' && $filter === null) {
            Http::validationFailed('A filter audience needs a filter definition.');
        }
        if ($source !== 'filter' && $refs === []) {
            Http::validationFailed('Select at least one audience member.');
        }

        $inserted = Db::transaction(static function () use ($ctx, $campaignId, $source, $refs, $filter): int {
            Db::run('DELETE FROM voice_campaign_audience_refs WHERE campaign_id = :id', ['id' => $campaignId]);

            if ($source === 'filter') {
                Db::insert('voice_campaign_audience_refs', [
                    'campaign_id'       => $campaignId,
                    'cmp_id'            => $ctx->cmpId,
                    'source'            => 'filter',
                    'filter_definition' => $filter,
                ], 'audience_ref_id');

                return 1;
            }

            $count = 0;
            foreach ($refs as $ref) {
                $ref = is_scalar($ref) ? trim((string) $ref) : '';
                if ($ref === '') {
                    continue;
                }
                Db::run(
                    'INSERT INTO voice_campaign_audience_refs (campaign_id, cmp_id, source, external_ref)
                     VALUES (:campaign, :cmp, :source, :ref)
                     ON CONFLICT (campaign_id, source, external_ref) WHERE external_ref IS NOT NULL DO NOTHING',
                    ['campaign' => $campaignId, 'cmp' => $ctx->cmpId, 'source' => $source, 'ref' => $ref],
                );
                $count++;
            }

            return $count;
        });

        // The readiness state is now stale; recompute it.
        $validation = CampaignService::validate($ctx, $campaignId);

        Http::data([
            'audience_size' => $inserted,
            'source'        => $source,
            'note'          => 'Stored as references. Numbers and eligibility are read from the owning product when each call is dialled.',
            'readiness'     => $validation,
        ]);
    }

    public static function validate(string $id): never
    {
        [, $ctx] = self::enter('voice.campaigns.view');
        $campaignId = self::id($id);

        if (CampaignService::row($ctx, $campaignId) === null) {
            Http::notFound('That campaign is not in this company.');
        }

        Http::data(CampaignService::validate($ctx, $campaignId));
    }

    public static function actions(string $id): never
    {
        $action = (string) (Http::body()['action'] ?? '');

        // Approving is not launching. A company may separate the two people.
        $permission = match ($action) {
            'approve' => 'voice.campaigns.approve',
            default   => 'voice.campaigns.launch',
        };

        [$auth, $ctx] = self::enter($permission);
        $campaignId = self::id($id);

        if ($action === 'approve') {
            if (CampaignService::row($ctx, $campaignId) === null) {
                Http::notFound('That campaign is not in this company.');
            }
            Db::update('voice_campaigns', [
                'approved_at' => Clock::sql(Clock::now()),
                'approved_by' => $auth->uuid,
                'updated_at'  => Clock::sql(Clock::now()),
            ], ['campaign_id' => $campaignId, 'cmp_id' => $ctx->cmpId]);

            Audit::record($ctx, $auth, 'voice.campaign.approved', 'campaign', (string) $campaignId);

            Http::data(CampaignService::validate($ctx, $campaignId) + ['status' => 'approved']);
        }

        $result = CampaignService::act($ctx, $auth, $campaignId, $action);
        if (!$result['ok']) {
            self::fail($result['code'], $result['message'], ['status' => $result['status']]);
        }

        Http::data([
            'status'  => $result['status'],
            'message' => $result['message'],
        ]);
    }

    /** @param array<string, mixed> $row @return array<string, mixed> */
    private static function present(array $row): array
    {
        if ($row === []) {
            return [];
        }

        return [
            'campaign_id' => (int) $row['campaign_id'],
            'name'        => (string) $row['name'],
            'description' => (string) $row['description'],
            'mode'        => (string) $row['mode'],
            'status'      => (string) $row['status'],
            'status_reason' => $row['status_reason'],
            'connection_id' => $row['connection_id'] === null ? null : (int) $row['connection_id'],
            'number_id'   => $row['number_id'] === null ? null : (int) $row['number_id'],
            'ai_agent_id' => $row['ai_agent_id'] === null ? null : (int) $row['ai_agent_id'],
            'flow_id'     => $row['flow_id'] === null ? null : (int) $row['flow_id'],
            'team_id'     => $row['team_id'] === null ? null : (int) $row['team_id'],
            'script'      => Db::jsonColumn($row['script'] ?? null),
            'timezone'    => (string) $row['timezone'],
            'window_start_min' => (int) $row['window_start_min'],
            'window_end_min'   => (int) $row['window_end_min'],
            'window_days'      => array_map('intval', Db::jsonColumn($row['window_days'] ?? null)),
            'max_concurrent'   => (int) $row['max_concurrent'],
            'calls_per_minute' => (int) $row['calls_per_minute'],
            'max_attempts'     => (int) $row['max_attempts'],
            'budget_minor'     => (int) $row['budget_minor'],
            'spent_minor'      => (int) $row['spent_minor'],
            'readiness'   => Db::jsonColumn($row['readiness'] ?? null),
            'approved_at' => $row['approved_at'],
            'started_at'  => $row['started_at'],
            'paused_at'   => $row['paused_at'],
            'completed_at' => $row['completed_at'],
            'created_at'  => $row['created_at'],
            'updated_at'  => $row['updated_at'],
        ];
    }
}
