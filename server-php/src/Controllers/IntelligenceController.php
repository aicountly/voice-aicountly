<?php

declare(strict_types=1);

namespace Aicountly\Api\Controllers;

use Aicountly\Api\Ai\AiClient;
use Aicountly\Api\Audit;
use Aicountly\Api\Db;
use Aicountly\Api\Domain\CallingPolicy;
use Aicountly\Api\Domain\CommitmentService;
use Aicountly\Api\Domain\RecordingService;
use Aicountly\Api\Http;
use Aicountly\Api\Permissions;
use Aicountly\Api\Support\Clock;

/**
 * Conversation intelligence: search, recordings, summaries, commitments and
 * quality.
 *
 * ## Search reaches Voice's own records and nothing else
 *
 * The index is PostgreSQL's own full-text index over voice_transcript_segments.
 * It contains Voice transcripts and Voice call rows. It does not index a copy
 * of Contacts or CRM, so it cannot become a back door into another product's
 * data, and deleting a transcript removes its index entries with it.
 *
 * Every query carries the tenant scope in SQL. Not in the rendering.
 */
final class IntelligenceController extends Controller
{
    public static function search(): never
    {
        [$auth, $ctx] = self::enter('voice.call.view');

        $body = Http::body();
        $query = trim((string) ($body['q'] ?? ''));
        $limit = max(1, min(100, (int) ($body['limit'] ?? 25)));
        $offset = max(0, (int) ($body['offset'] ?? 0));

        $canTranscript = Permissions::allows($ctx, $auth, 'voice.transcripts.view');

        [$scope, $bindings] = $ctx->scopeClause('c');
        $where = [$scope, 'c.ended_at IS NOT NULL'];

        foreach ([
            'direction' => 'c.direction', 'language' => 'c.language',
            'outcome' => 'c.outcome', 'handled_by' => 'c.handled_by',
        ] as $param => $column) {
            $value = $body[$param] ?? null;
            if (is_string($value) && $value !== '') {
                $where[] = $column . ' = :' . $param;
                $bindings[$param] = $value;
            }
        }
        foreach (['owner_agent_id' => 'c.owner_agent_id', 'queue_id' => 'c.queue_id'] as $param => $column) {
            if (isset($body[$param]) && (int) $body[$param] > 0) {
                $where[] = $column . ' = :' . $param;
                $bindings[$param] = (int) $body[$param];
            }
        }
        if (isset($body['team_id']) && (int) $body['team_id'] > 0) {
            $where[] = 'c.owner_agent_id IN (SELECT agent_id FROM voice_team_members WHERE team_id = :team_id)';
            $bindings['team_id'] = (int) $body['team_id'];
        }
        if (isset($body['disposition']) && is_string($body['disposition']) && $body['disposition'] !== '') {
            $where[] = 'c.disposition_id IN (SELECT disposition_id FROM voice_dispositions WHERE code = :disposition)';
            $bindings['disposition'] = $body['disposition'];
        }
        foreach (['from' => '>=', 'to' => '<'] as $param => $operator) {
            if (isset($body[$param]) && is_string($body[$param]) && $body[$param] !== '') {
                $where[] = 'c.initiated_at ' . $operator . ' :' . $param;
                $bindings[$param] = $body[$param];
            }
        }

        // Full-text over transcripts, but only for somebody who may read them.
        // Without the permission the words are not searched at all, rather than
        // searched and then hidden.
        if ($query !== '') {
            if ($canTranscript) {
                $where[] = '(c.remote_e164 ILIKE :like OR EXISTS (
                    SELECT 1 FROM voice_transcript_segments t
                     WHERE t.call_id = c.call_id
                       AND to_tsvector(\'simple\', t.text) @@ plainto_tsquery(\'simple\', :query)
                ))';
                $bindings['query'] = $query;
            } else {
                $where[] = 'c.remote_e164 ILIKE :like';
            }
            $bindings['like'] = '%' . $query . '%';
        }

        $whereSql = implode(' AND ', $where);
        $total = (int) (Db::scalar('SELECT COUNT(*) FROM voice_calls c WHERE ' . $whereSql, $bindings) ?? 0);

        $rows = Db::all(
            'SELECT c.call_id, c.call_uuid, c.remote_e164, c.contact_ref, c.direction, c.language,
                    c.initiated_at, c.talk_seconds, c.outcome, c.handled_by,
                    d.label AS disposition_label, d.category AS disposition_category,
                    a.user_uuid AS agent_uuid,
                    r.recording_uuid, r.duration_seconds,
                    (SELECT COUNT(*) FROM voice_commitments m WHERE m.call_id = c.call_id) AS commitments
               FROM voice_calls c
               LEFT JOIN voice_dispositions d ON d.disposition_id = c.disposition_id
               LEFT JOIN voice_agents a       ON a.agent_id = c.owner_agent_id
               LEFT JOIN voice_recordings r   ON r.call_id = c.call_id AND r.deleted_at IS NULL
              WHERE ' . $whereSql . '
              ORDER BY c.initiated_at DESC
              LIMIT ' . $limit . ' OFFSET ' . $offset,
            $bindings,
        );

        $canListen = Permissions::allows($ctx, $auth, 'voice.recordings.listen');

        Http::list(array_map(static fn (array $r): array => [
            'call_id'    => (int) $r['call_id'],
            'call_uuid'  => (string) $r['call_uuid'],
            'direction'  => (string) $r['direction'],
            'remote_masked' => $r['remote_e164'] === null ? null : CallingPolicy::mask((string) $r['remote_e164']),
            'contact_ref' => $r['contact_ref'],
            'agent_uuid' => $r['agent_uuid'],
            'language'   => $r['language'],
            'initiated_at' => $r['initiated_at'],
            'talk_seconds' => (int) $r['talk_seconds'],
            'outcome'    => $r['outcome'],
            'handled_by' => (string) $r['handled_by'],
            'disposition' => $r['disposition_label'],
            'disposition_category' => $r['disposition_category'],
            'commitments' => (int) $r['commitments'],
            'recording'  => ($canListen && $r['recording_uuid'] !== null) ? [
                'recording_uuid'   => (string) $r['recording_uuid'],
                'duration_seconds' => (int) $r['duration_seconds'],
            ] : null,
        ], $rows), $total, $limit, $offset, [
            'searched_transcripts' => $canTranscript && $query !== '',
            'scope' => 'Voice transcripts and call records for this company.',
        ]);
    }

    /** A short-lived playback grant. The URL is never logged or stored. */
    public static function playback(string $recordingUuid): never
    {
        $download = Http::param('download') === '1';

        // Downloading takes the recording out of this product's controls, so it
        // is a different permission from listening to it here.
        [$auth, $ctx] = self::enter($download ? 'voice.recordings.download' : 'voice.recordings.listen');

        $result = RecordingService::playback($ctx, $auth, $recordingUuid, $download);
        if (!$result['ok']) {
            self::fail($result['code'], $result['message']);
        }

        Http::data($result['playback'] ?? []);
    }

    public static function recordings(): never
    {
        [, $ctx] = self::enter('voice.recordings.listen');
        $params = Http::listParams(['created_at', 'duration_seconds'], 'created_at');

        [$scope, $bindings] = $ctx->scopeClause('r');
        $where = [$scope, 'r.deleted_at IS NULL'];

        $kind = Http::param('kind');
        if ($kind !== null && $kind !== '') {
            $where[] = 'r.kind = :kind';
            $bindings['kind'] = $kind;
        }

        $whereSql = implode(' AND ', $where);
        $total = (int) (Db::scalar('SELECT COUNT(*) FROM voice_recordings r WHERE ' . $whereSql, $bindings) ?? 0);

        $rows = Db::all(
            'SELECT r.* FROM voice_recordings r WHERE ' . $whereSql . '
              ORDER BY r.' . $params['sort'] . ' ' . $params['order'] . '
              LIMIT ' . $params['limit'] . ' OFFSET ' . $params['offset'],
            $bindings,
        );

        Http::list(array_map(static fn (array $r): array => RecordingService::present($r), $rows),
            $total, $params['limit'], $params['offset']);
    }

    /**
     * Generate or fetch a call summary.
     *
     * With no model configured this produces a DETERMINISTIC summary from the
     * call record and says `engine: rules`. The screen reports which produced
     * it rather than implying a model ran.
     */
    public static function summary(string $id): never
    {
        [$auth, $ctx] = self::enter('voice.transcripts.view');
        $callId = self::id($id);

        $existing = Db::first(
            'SELECT * FROM voice_call_summaries WHERE call_id = :id AND cmp_id = :cmp
              ORDER BY version_no DESC LIMIT 1',
            ['id' => $callId, 'cmp' => $ctx->cmpId],
        );

        if ($existing !== null && Http::param('regenerate') !== '1') {
            Http::data(self::presentSummary($existing));
        }

        $segments = Db::all(
            'SELECT segment_id, speaker, started_ms, text FROM voice_transcript_segments
              WHERE call_id = :id AND cmp_id = :cmp AND is_final = TRUE
              ORDER BY sequence_no LIMIT 500',
            ['id' => $callId, 'cmp' => $ctx->cmpId],
        );

        if ($segments === []) {
            Http::data([
                'body'   => null,
                'engine' => 'none',
                'note'   => 'This call has no transcript, so there is nothing to summarise.',
            ]);
        }

        $result = AiClient::summariseCall($segments);
        $engine = $result['ok'] ? 'model' : 'rules';
        $body = $result['ok'] && $result['text'] !== null
            ? $result['text']
            : self::ruleBasedSummary($ctx->cmpId, $callId, $segments);

        $nextNo = (int) (Db::scalar(
            'SELECT COALESCE(MAX(version_no), 0) + 1 FROM voice_call_summaries WHERE call_id = :id',
            ['id' => $callId],
        ) ?? 1);

        $summaryId = (int) Db::insert('voice_call_summaries', [
            'call_id'    => $callId,
            'cmp_id'     => $ctx->cmpId,
            'version_no' => $nextNo,
            'kind'       => 'generated',
            'body'       => $body,
            // Segment ids, so every line can be checked against what was said.
            'evidence'   => array_map(static fn (array $s): int => (int) $s['segment_id'], array_slice($segments, 0, 20)),
            'engine'     => $engine,
            'created_by' => $auth->uuid,
        ], 'summary_id');

        Http::data(self::presentSummary(Db::first(
            'SELECT * FROM voice_call_summaries WHERE summary_id = :id',
            ['id' => $summaryId],
        ) ?? []));
    }

    /** An authorised user's correction. The generated version is kept alongside. */
    public static function editSummary(string $id): never
    {
        [$auth, $ctx] = self::enter('voice.transcripts.view');
        $callId = self::id($id);

        $body = trim((string) (Http::body()['body'] ?? ''));
        if ($body === '') {
            Http::validationFailed('The summary cannot be empty.');
        }

        $nextNo = (int) (Db::scalar(
            'SELECT COALESCE(MAX(version_no), 0) + 1 FROM voice_call_summaries WHERE call_id = :id',
            ['id' => $callId],
        ) ?? 1);

        $summaryId = (int) Db::insert('voice_call_summaries', [
            'call_id'    => $callId,
            'cmp_id'     => $ctx->cmpId,
            'version_no' => $nextNo,
            'kind'       => 'edited',
            'body'       => $body,
            'engine'     => 'human',
            'created_by' => $auth->uuid,
        ], 'summary_id');

        Http::data(self::presentSummary(Db::first(
            'SELECT * FROM voice_call_summaries WHERE summary_id = :id',
            ['id' => $summaryId],
        ) ?? []));
    }

    public static function confirmCommitment(string $id): never
    {
        [$auth, $ctx] = self::enter('voice.commitments.confirm');

        $result = CommitmentService::confirm($ctx, $auth, self::id($id), Http::body());

        // Not an error: the commitment IS confirmed here. What is unresolved is
        // whether CRM created the task, and that is reported as its own state.
        Http::json($result['ok'] ? 200 : 202, [
            'data'    => [
                'commitment' => $result['commitment'],
                'operation'  => $result['operation'],
            ],
            'message' => $result['message'],
        ]);
    }

    public static function rejectCommitment(string $id): never
    {
        [$auth, $ctx] = self::enter('voice.commitments.confirm');

        $result = CommitmentService::reject($ctx, $auth, self::id($id));
        if (!$result['ok']) {
            self::fail($result['code'], $result['message']);
        }

        Http::data(['rejected' => true]);
    }

    /** Record a quality review. Deterministic checks and opinions stay apart. */
    public static function review(string $id): never
    {
        [$auth, $ctx] = self::enter('voice.quality.review');
        $callId = self::id($id);

        $body = Http::body();
        $verdict = (string) ($body['verdict'] ?? '');
        if (!in_array($verdict, ['pass', 'fail', 'needs_coaching'], true)) {
            Http::validationFailed('Give a verdict of pass, fail or needs_coaching.');
        }

        $override = trim((string) ($body['override_reason'] ?? ''));
        $deterministic = self::deterministicChecks($ctx->cmpId, $callId);

        Db::run(
            'INSERT INTO voice_quality_reviews
                (call_id, cmp_id, rubric_key, deterministic, model_assessment, score,
                 reviewer_uuid, reviewer_verdict, reviewer_note, override_reason, status, reviewed_at)
             VALUES (:call, :cmp, :rubric, :det, :model, :score, :uuid, :verdict, :note, :override, \'reviewed\', NOW())
             ON CONFLICT (call_id, rubric_key) DO UPDATE SET
                deterministic = EXCLUDED.deterministic,
                reviewer_uuid = EXCLUDED.reviewer_uuid,
                reviewer_verdict = EXCLUDED.reviewer_verdict,
                reviewer_note = EXCLUDED.reviewer_note,
                override_reason = EXCLUDED.override_reason,
                status = \'reviewed\',
                reviewed_at = NOW()',
            [
                'call'   => $callId,
                'cmp'    => $ctx->cmpId,
                'rubric' => (string) ($body['rubric_key'] ?? 'default'),
                'det'    => json_encode($deterministic),
                'model'  => json_encode([]),
                'score'  => isset($body['score']) ? (float) $body['score'] : null,
                'uuid'   => $auth->uuid,
                'verdict' => $verdict,
                'note'   => trim((string) ($body['note'] ?? '')),
                'override' => $override === '' ? null : $override,
            ],
        );

        Http::data([
            'reviewed' => true,
            'deterministic' => $deterministic,
            'note' => 'Deterministic checks are facts about the call record. Model assessments, where present, are shown separately and are not merged into them.',
        ]);
    }

    /**
     * Checks the code can PROVE from the record.
     *
     * Kept apart from anything a model says, because "the disclosure line was
     * not played" is checkable and "the agent sounded impatient" is an opinion.
     *
     * @return list<array<string, mixed>>
     */
    private static function deterministicChecks(int $cmpId, int $callId): array
    {
        $call = Db::first(
            'SELECT answered_at, consent_state, recording_state, outcome, handled_by
               FROM voice_calls WHERE call_id = :id AND cmp_id = :cmp',
            ['id' => $callId, 'cmp' => $cmpId],
        ) ?? [];

        $firstAgentSegment = Db::first(
            'SELECT started_ms FROM voice_transcript_segments
              WHERE call_id = :id AND cmp_id = :cmp AND speaker IN (\'agent\', \'ai\')
              ORDER BY sequence_no LIMIT 1',
            ['id' => $callId, 'cmp' => $cmpId],
        );

        return [
            [
                'key'    => 'greeting_present',
                'label'  => 'Greeting present',
                'status' => $firstAgentSegment !== null && (int) $firstAgentSegment['started_ms'] < 15000 ? 'passed' : 'failed',
                'detail' => $firstAgentSegment === null
                    ? 'No agent speech found in the transcript.'
                    : 'First agent speech at ' . gmdate('i:s', (int) ((int) $firstAgentSegment['started_ms'] / 1000)) . '.',
            ],
            [
                'key'    => 'disclosure_played',
                'label'  => 'Recording/AI disclosure',
                'status' => in_array((string) ($call['consent_state'] ?? ''), ['announced', 'granted'], true) ? 'passed'
                    : ((string) ($call['consent_state'] ?? '') === 'not_applicable' ? 'not_applicable' : 'failed'),
                'detail' => 'Consent state: ' . (string) ($call['consent_state'] ?? 'unknown') . '.',
            ],
            [
                'key'    => 'handover_completed',
                'label'  => 'Handover completed',
                'status' => (string) ($call['outcome'] ?? '') === 'handover_completed' ? 'passed' : 'not_applicable',
                'detail' => 'Outcome: ' . (string) ($call['outcome'] ?? 'unknown') . '.',
            ],
            [
                'key'    => 'confirmation_requested',
                'label'  => 'Caller confirmation before a consequential action',
                'status' => self::hadConfirmedExternalWrite($cmpId, $callId) ? 'passed' : 'not_applicable',
                'detail' => 'Checked against external operations recorded for this call.',
            ],
        ];
    }

    private static function hadConfirmedExternalWrite(int $cmpId, int $callId): bool
    {
        return Db::first(
            'SELECT 1 FROM voice_external_operations
              WHERE call_id = :id AND cmp_id = :cmp AND status = \'succeeded\' LIMIT 1',
            ['id' => $callId, 'cmp' => $cmpId],
        ) !== null;
    }

    /**
     * The deterministic summary, used when no model is configured.
     *
     * Deliberately dull and entirely derived from the record: who spoke, how
     * long, what the outcome was. It invents nothing.
     *
     * @param list<array<string, mixed>> $segments
     */
    private static function ruleBasedSummary(int $cmpId, int $callId, array $segments): string
    {
        $call = Db::first(
            'SELECT direction, talk_seconds, outcome, handled_by FROM voice_calls
              WHERE call_id = :id AND cmp_id = :cmp',
            ['id' => $callId, 'cmp' => $cmpId],
        ) ?? [];

        $bySpeaker = [];
        foreach ($segments as $segment) {
            $speaker = (string) $segment['speaker'];
            $bySpeaker[$speaker] = ($bySpeaker[$speaker] ?? 0) + 1;
        }

        $parts = [];
        $parts[] = ucfirst((string) ($call['direction'] ?? 'unknown')) . ' call lasting '
            . gmdate('i:s', (int) ($call['talk_seconds'] ?? 0)) . '.';
        $parts[] = count($segments) . ' transcript segments ('
            . implode(', ', array_map(
                static fn (string $s, int $n): string => $n . ' from the ' . $s,
                array_keys($bySpeaker),
                array_values($bySpeaker),
            )) . ').';
        if (!empty($call['outcome'])) {
            $parts[] = 'Recorded outcome: ' . str_replace('_', ' ', (string) $call['outcome']) . '.';
        }

        return implode(' ', $parts);
    }

    /** @param array<string, mixed> $row @return array<string, mixed> */
    private static function presentSummary(array $row): array
    {
        if ($row === []) {
            return [];
        }

        return [
            'summary_id' => (int) $row['summary_id'],
            'version_no' => (int) $row['version_no'],
            'kind'       => (string) $row['kind'],
            'body'       => (string) $row['body'],
            'evidence'   => Db::jsonColumn($row['evidence'] ?? null),
            // 'model', 'rules' or 'human'. The screen says which.
            'engine'     => (string) $row['engine'],
            'created_at' => $row['created_at'],
        ];
    }
}
