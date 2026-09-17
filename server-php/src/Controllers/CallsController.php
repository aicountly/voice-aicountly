<?php

declare(strict_types=1);

namespace Aicountly\Api\Controllers;

use Aicountly\Api\Audit;
use Aicountly\Api\Db;
use Aicountly\Api\Domain\CallingPolicy;
use Aicountly\Api\Domain\CallService;
use Aicountly\Api\Http;
use Aicountly\Api\Idempotency;
use Aicountly\Api\Permissions;

/**
 * Calls: history, detail, placing, controlling, disposition and transcripts.
 *
 * ## Placing a call is idempotent
 *
 * The one mutation in this product where a retry does not create a duplicate
 * ROW, it creates a duplicate PHONE CALL to a member of the public. The replay
 * check is the first thing `create()` does.
 *
 * ## Transcripts are permission-gated separately from calls
 *
 * Seeing that a call happened and reading what was said in it are different
 * things, and most people who need the first do not need the second.
 */
final class CallsController extends Controller
{
    private const SORTABLE = ['initiated_at', 'talk_seconds', 'total_seconds'];

    public static function index(): never
    {
        [, $ctx] = self::enter('voice.call.view');
        $params = Http::listParams(self::SORTABLE, 'initiated_at');

        [$scope, $bindings] = $ctx->scopeClause('c');
        $where = [$scope];

        foreach ([
            'direction' => 'c.direction', 'state' => 'c.state', 'outcome' => 'c.outcome',
            'handled_by' => 'c.handled_by',
        ] as $param => $column) {
            $value = Http::param($param);
            if ($value !== null && $value !== '') {
                $where[] = $column . ' = :' . $param;
                $bindings[$param] = $value;
            }
        }
        foreach (['campaign_id' => 'c.campaign_id', 'queue_id' => 'c.queue_id', 'owner_agent_id' => 'c.owner_agent_id'] as $param => $column) {
            $value = Http::intParam($param);
            if ($value !== null && $value > 0) {
                $where[] = $column . ' = :' . $param;
                $bindings[$param] = $value;
            }
        }
        if (Http::param('abandoned') === '1') {
            $where[] = 'c.abandoned = TRUE';
        }
        if (Http::param('contact_ref') !== null && Http::param('contact_ref') !== '') {
            $where[] = 'c.contact_ref = :contact_ref';
            $bindings['contact_ref'] = Http::param('contact_ref');
        }
        foreach (['from' => '>=', 'to' => '<'] as $param => $operator) {
            $value = Http::param($param);
            if ($value !== null && $value !== '') {
                $where[] = 'c.initiated_at ' . $operator . ' :' . $param;
                $bindings[$param] = $value;
            }
        }
        // Search matches the DIALLED NUMBER, which is call evidence. It does not
        // search names: names live in Contacts and are searched there.
        if ($params['q'] !== '') {
            $where[] = '(c.remote_e164 ILIKE :q OR c.local_e164 ILIKE :q)';
            $bindings['q'] = '%' . $params['q'] . '%';
        }

        $whereSql = implode(' AND ', $where);

        $total = (int) (Db::scalar('SELECT COUNT(*) FROM voice_calls c WHERE ' . $whereSql, $bindings) ?? 0);

        $rows = Db::all(
            'SELECT c.*, d.label AS disposition_label, d.category AS disposition_category,
                    q.name AS queue_name, a.user_uuid AS agent_uuid, a.extension
               FROM voice_calls c
               LEFT JOIN voice_dispositions d ON d.disposition_id = c.disposition_id
               LEFT JOIN voice_queues q       ON q.queue_id = c.queue_id
               LEFT JOIN voice_agents a       ON a.agent_id = c.owner_agent_id
              WHERE ' . $whereSql . '
              ORDER BY c.' . $params['sort'] . ' ' . $params['order'] . '
              LIMIT ' . $params['limit'] . ' OFFSET ' . $params['offset'],
            $bindings,
        );

        $data = [];
        foreach ($rows as $row) {
            $data[] = CallService::present($row) + [
                'disposition'          => $row['disposition_label'],
                'disposition_category' => $row['disposition_category'],
                'queue_name'           => $row['queue_name'],
                'agent_uuid'           => $row['agent_uuid'],
                'agent_extension'      => $row['extension'],
            ];
        }

        Http::list($data, $total, $params['limit'], $params['offset']);
    }

    public static function show(string $id): never
    {
        [$auth, $ctx] = self::enter('voice.call.view');
        $call = CallService::find($ctx, self::id($id));
        if ($call === null) {
            Http::notFound('That call is not in this company.');
        }

        $callId = (int) $call['call_id'];

        Http::data($call + [
            'legs'         => Db::all(
                'SELECT leg_id, leg_role, endpoint, state, started_at, answered_at, ended_at, end_reason, talk_seconds
                   FROM voice_call_legs WHERE call_id = :id ORDER BY leg_id',
                ['id' => $callId],
            ),
            'participants' => Db::all(
                'SELECT participant_id, party, monitor_mode, joined_at, left_at
                   FROM voice_call_participants WHERE call_id = :id ORDER BY participant_id',
                ['id' => $callId],
            ),
            'recordings'   => Permissions::allows($ctx, $auth, 'voice.recordings.listen')
                ? array_map(
                    static fn (array $r): array => \Aicountly\Api\Domain\RecordingService::present($r),
                    Db::all('SELECT * FROM voice_recordings WHERE call_id = :id AND deleted_at IS NULL', ['id' => $callId]),
                )
                : [],
            'commitments'  => array_map(
                static fn (array $r): array => \Aicountly\Api\Domain\CommitmentService::present($r),
                Db::all('SELECT * FROM voice_commitments WHERE call_id = :id ORDER BY commitment_id', ['id' => $callId]),
            ),
            'external_operations' => array_map(
                static fn (array $r): array => \Aicountly\Api\ExternalOperations::present($r),
                Db::all('SELECT * FROM voice_external_operations WHERE call_id = :id ORDER BY operation_id', ['id' => $callId]),
            ),
            'permissions' => [
                'can_listen'     => Permissions::allows($ctx, $auth, 'voice.recordings.listen'),
                'can_download'   => Permissions::allows($ctx, $auth, 'voice.recordings.download'),
                'can_transcript' => Permissions::allows($ctx, $auth, 'voice.transcripts.view'),
            ],
        ]);
    }

    public static function create(): never
    {
        [$auth, $ctx] = self::enter('voice.call.place');

        // Before anything else. A repeat of this request must not ring the
        // customer a second time.
        $key = Idempotency::fromRequest();
        $replay = Idempotency::replay($ctx, 'call.create', $key);
        if ($replay !== null) {
            Http::json($replay['status'], $replay['body']);
        }

        $result = CallService::place($ctx, $auth, Http::body());

        if (!$result['ok']) {
            // An unknown outcome is remembered too, so a retry of the same
            // request gets the same "we are not sure" rather than dialling again.
            if ($result['code'] === 'outcome_unknown') {
                Idempotency::remember($ctx, 'call.create', $key, 202, [
                    'data'    => $result['detail'],
                    'message' => $result['message'],
                ]);
            }
            self::fail($result['code'], $result['message'], $result['detail']);
        }

        $body = ['data' => $result['call']];
        Idempotency::remember($ctx, 'call.create', $key, 201, $body);
        Http::json(201, $body);
    }

    public static function actions(string $id): never
    {
        $body = Http::body();
        $action = (string) ($body['action'] ?? '');

        // Monitoring a colleague is its own permission, never implied by
        // seniority or by being allowed to handle calls.
        $permission = match ($action) {
            'transfer' => 'voice.call.transfer',
            'monitor'  => 'voice.supervisor.monitor',
            default    => 'voice.call.handle',
        };

        [$auth, $ctx] = self::enter($permission);

        $result = CallService::control($ctx, $auth, self::id($id), $action, $body);
        if (!$result['ok']) {
            self::fail($result['code'], $result['message'], $result['detail']);
        }

        Http::data($result['detail'] + ['action' => $action]);
    }

    public static function disposition(string $id): never
    {
        [$auth, $ctx] = self::enter('voice.call.disposition');

        $result = CallService::disposition($ctx, $auth, self::id($id), Http::body());
        if (!$result['ok']) {
            self::fail($result['code'], $result['message']);
        }

        Http::data(CallService::find($ctx, self::id($id)) ?? []);
    }

    public static function transcript(string $id): never
    {
        [$auth, $ctx] = self::enter('voice.transcripts.view');

        $callId = self::id($id);
        if (CallService::row($ctx, $callId) === null) {
            Http::notFound('That call is not in this company.');
        }

        $rows = Db::all(
            'SELECT segment_id, sequence_no, speaker, speaker_label, started_ms, ended_ms,
                    is_final, language, text, translated_text, translated_to, confidence, redacted
               FROM voice_transcript_segments
              WHERE call_id = :id AND cmp_id = :cmp
              ORDER BY sequence_no
              LIMIT 2000',
            ['id' => $callId, 'cmp' => $ctx->cmpId],
        );

        Audit::record($ctx, $auth, Audit::TRANSCRIPT_VIEWED, 'call', (string) $callId, ['segments' => count($rows)]);

        Http::json(200, [
            'data' => array_map(static fn (array $r): array => [
                'segment_id'  => (int) $r['segment_id'],
                'sequence_no' => (int) $r['sequence_no'],
                'speaker'     => (string) $r['speaker'],
                'speaker_label' => $r['speaker_label'],
                'started_ms'  => (int) $r['started_ms'],
                'ended_ms'    => $r['ended_ms'] === null ? null : (int) $r['ended_ms'],
                // Partial text is marked, so a UI never quotes somebody saying
                // something the recogniser was still revising.
                'is_final'    => (bool) $r['is_final'],
                'language'    => $r['language'],
                'text'        => (string) $r['text'],
                'translated_text' => $r['translated_text'],
                'translated_to'   => $r['translated_to'],
                // NULL means the recogniser did not report a confidence. That
                // is not the same as being certain.
                'confidence'  => $r['confidence'] === null ? null : (float) $r['confidence'],
                'redacted'    => (bool) $r['redacted'],
            ], $rows),
            'meta' => ['total' => count($rows), 'call_id' => $callId],
        ]);
    }

    /** The outcome list, company rows falling back to the fleet defaults. */
    public static function dispositions(): never
    {
        [, $ctx] = self::enter('voice.call.view');

        Http::data(Db::all(
            'SELECT DISTINCT ON (code) code, label, category, requires_note, sort_order
               FROM voice_dispositions
              WHERE cmp_id IN (0, :cmp) AND is_active = TRUE
              ORDER BY code, cmp_id DESC',
            ['cmp' => $ctx->cmpId],
        ));
    }
}
