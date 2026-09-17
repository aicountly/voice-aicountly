<?php

declare(strict_types=1);

namespace Aicountly\Api\Dashboards;

use Aicountly\Api\Ai\AiClient;
use Aicountly\Api\Db;
use Aicountly\Api\Domain\CallingPolicy;
use Aicountly\Api\Permissions;
use Aicountly\Api\Support\Clock;

/**
 * Dashboard 5 — Conversation Intelligence.
 *
 * ## What is counted, and what is not
 *
 * "Commitments found" INCLUDES suggestions, and says so — most of them are not
 * tasks and may never become one. "Follow-ups overdue" counts only CONFIRMED
 * commitments whose date has passed, because chasing somebody over a promise
 * nobody accepted is worse than not chasing at all.
 *
 * ## Permission shapes the list, in SQL
 *
 * A user without `voice.recordings.listen` gets calls without recordings
 * attached; without `voice.transcripts.view`, no transcript text. The filter is
 * in the query, not in the rendering — a list that fetches everything and hides
 * some of it is one curl away from not hiding it.
 */
final class IntelligenceDashboard extends Dashboard
{
    public function id(): string
    {
        return 'intelligence';
    }

    public function build(): array
    {
        $from = Clock::iso($this->period->from);
        $to = Clock::iso($this->period->to);

        $reviewed = (int) (Db::scalar(
            'SELECT COUNT(*) FROM voice_calls
              WHERE cmp_id = :cmp AND ended_at IS NOT NULL
                AND initiated_at >= :from AND initiated_at < :to',
            ['cmp' => $this->ctx->cmpId, 'from' => $from, 'to' => $to],
        ) ?? 0);

        $commitments = Db::first(
            'SELECT
                COUNT(*)                                                   AS found,
                COUNT(*) FILTER (WHERE status = \'suggested\')             AS suggested,
                COUNT(*) FILTER (WHERE status = \'confirmed\')             AS confirmed,
                COUNT(*) FILTER (WHERE status = \'confirmed\' AND due_at IS NOT NULL AND due_at < NOW()) AS overdue,
                COUNT(*) FILTER (WHERE status = \'confirmed\' AND due_at IS NULL)                        AS needs_date
               FROM voice_commitments
              WHERE cmp_id = :cmp AND created_at >= :from AND created_at < :to',
            ['cmp' => $this->ctx->cmpId, 'from' => $from, 'to' => $to],
        ) ?? [];

        $needsReview = (int) (Db::scalar(
            'SELECT COUNT(*) FROM voice_quality_reviews
              WHERE cmp_id = :cmp AND status = \'pending\' AND created_at >= :from',
            ['cmp' => $this->ctx->cmpId, 'from' => $from],
        ) ?? 0);

        $metrics = [
            Metric::make('calls_reviewed', 'Calls in period', $reviewed, 'count', null, 'neutral', 'Completed calls available to search.'),
            Metric::make('commitments_found', 'Commitments found', (int) ($commitments['found'] ?? 0), 'count', null, 'neutral',
                (int) ($commitments['suggested'] ?? 0) . ' still suggestions awaiting a person.'),
            Metric::make('followups_overdue', 'Follow-ups overdue', (int) ($commitments['overdue'] ?? 0), 'count', null, 'down_is_good',
                'Confirmed commitments past their date. Suggestions are not counted.'),
            Metric::make('needs_review', 'Needs review', $needsReview, 'count', null, 'down_is_good', 'Calls in the quality review queue.'),
        ];

        return $this->envelope($metrics, [
            'recent_calls' => $this->recentCalls($from, $to),
            'commitments'  => $this->commitments($from, $to),
            'commitment_summary' => array_map('intval', [
                'found'      => $commitments['found'] ?? 0,
                'suggested'  => $commitments['suggested'] ?? 0,
                'confirmed'  => $commitments['confirmed'] ?? 0,
                'overdue'    => $commitments['overdue'] ?? 0,
                'needs_date' => $commitments['needs_date'] ?? 0,
            ]),
            'quality'      => $this->qualitySummary($from, $to),
            'search'       => [
                // Says plainly what search can reach, so nobody assumes it
                // searches Contacts or CRM. It does not, and must not.
                'scope' => 'Voice transcripts and call records for this company only.',
                'natural_language' => AiClient::isAvailable(),
                'natural_language_reason' => AiClient::isAvailable() ? null : \Aicountly\Api\Features::explain('AI'),
            ],
            'permissions'  => [
                'can_listen'    => Permissions::allows($this->ctx, $this->auth, 'voice.recordings.listen'),
                'can_download'  => Permissions::allows($this->ctx, $this->auth, 'voice.recordings.download'),
                'can_transcript' => Permissions::allows($this->ctx, $this->auth, 'voice.transcripts.view'),
                'can_confirm'   => Permissions::allows($this->ctx, $this->auth, 'voice.commitments.confirm'),
                'can_review'    => Permissions::allows($this->ctx, $this->auth, 'voice.quality.review'),
            ],
        ], [
            'definitions' => [
                'commitments_found' => 'Everything the ledger has found, including suggestions nobody has confirmed.',
                'followups_overdue' => 'Confirmed commitments whose date has passed. Suggestions are excluded.',
                'calls_in_period'   => 'Completed calls. Live calls are on Live Operations.',
            ],
        ]);
    }

    /**
     * The call list, shaped by what this user may see.
     *
     * @return list<array<string, mixed>>
     */
    private function recentCalls(string $fromIso, string $toIso): array
    {
        $canListen = Permissions::allows($this->ctx, $this->auth, 'voice.recordings.listen');
        [$scope, $params] = $this->ctx->scopeClause('c');
        $params['from'] = $fromIso;
        $params['to'] = $toIso;

        $rows = Db::all(
            'SELECT c.call_id, c.call_uuid, c.remote_e164, c.contact_ref, c.direction,
                    c.initiated_at, c.talk_seconds, c.language, c.outcome, c.handled_by,
                    d.label AS disposition_label, d.category AS disposition_category,
                    a.user_uuid AS agent_uuid,
                    r.recording_uuid, r.duration_seconds, r.status AS recording_status,
                    (SELECT COUNT(*) FROM voice_commitments m WHERE m.call_id = c.call_id) AS commitments,
                    (SELECT COUNT(*) FROM voice_transcript_segments t WHERE t.call_id = c.call_id) AS segments
               FROM voice_calls c
               LEFT JOIN voice_dispositions d ON d.disposition_id = c.disposition_id
               LEFT JOIN voice_agents a       ON a.agent_id = c.owner_agent_id
               LEFT JOIN voice_recordings r   ON r.call_id = c.call_id AND r.deleted_at IS NULL
              WHERE ' . $scope . ' AND c.ended_at IS NOT NULL
                AND c.initiated_at >= :from AND c.initiated_at < :to
              ORDER BY c.initiated_at DESC
              LIMIT 50',
            $params,
        );

        $out = [];
        foreach ($rows as $row) {
            $out[] = [
                'call_id'    => (int) $row['call_id'],
                'call_uuid'  => (string) $row['call_uuid'],
                'direction'  => (string) $row['direction'],
                // The number, masked. The contact's NAME comes from Contacts,
                // resolved live by whoever displays this row.
                'remote_masked' => $row['remote_e164'] === null ? null : CallingPolicy::mask((string) $row['remote_e164']),
                'contact_ref' => $row['contact_ref'],
                'agent_uuid' => $row['agent_uuid'],
                'initiated_at' => $row['initiated_at'],
                'talk_seconds' => (int) $row['talk_seconds'],
                'language'   => $row['language'],
                'outcome'    => $row['outcome'],
                'handled_by' => (string) $row['handled_by'],
                'disposition' => $row['disposition_label'],
                'disposition_category' => $row['disposition_category'],
                'commitments' => (int) $row['commitments'],
                'has_transcript' => (int) $row['segments'] > 0,
                // Absent entirely without the permission, rather than present
                // and disabled.
                'recording'  => ($canListen && $row['recording_uuid'] !== null) ? [
                    'recording_uuid'   => (string) $row['recording_uuid'],
                    'duration_seconds' => (int) $row['duration_seconds'],
                    'status'           => (string) $row['recording_status'],
                ] : null,
            ];
        }

        return $out;
    }

    /** @return list<array<string, mixed>> */
    private function commitments(string $fromIso, string $toIso): array
    {
        $rows = Db::all(
            'SELECT m.*, a.user_uuid AS owner_uuid
               FROM voice_commitments m
               LEFT JOIN voice_agents a ON a.agent_id = m.owner_agent_id
              WHERE m.cmp_id = :cmp AND m.created_at >= :from AND m.created_at < :to
                AND m.status <> \'rejected\'
              ORDER BY
                CASE m.status WHEN \'confirmed\' THEN 0 ELSE 1 END,
                m.due_at NULLS LAST
              LIMIT 50',
            ['cmp' => $this->ctx->cmpId, 'from' => $fromIso, 'to' => $toIso],
        );

        return array_map(
            static fn (array $row): array =>
                \Aicountly\Api\Domain\CommitmentService::present($row) + ['owner_uuid' => $row['owner_uuid']],
            $rows,
        );
    }

    /** @return array<string, mixed> */
    private function qualitySummary(string $fromIso, string $toIso): array
    {
        $row = Db::first(
            'SELECT
                COUNT(*)                                          AS total,
                COUNT(*) FILTER (WHERE status = \'reviewed\')      AS reviewed,
                COUNT(*) FILTER (WHERE reviewer_verdict = \'pass\') AS passed,
                COUNT(*) FILTER (WHERE override_reason IS NOT NULL) AS overridden,
                AVG(score) FILTER (WHERE score IS NOT NULL)        AS average_score
               FROM voice_quality_reviews
              WHERE cmp_id = :cmp AND created_at >= :from AND created_at < :to',
            ['cmp' => $this->ctx->cmpId, 'from' => $fromIso, 'to' => $toIso],
        ) ?? [];

        return [
            'total'      => (int) ($row['total'] ?? 0),
            'reviewed'   => (int) ($row['reviewed'] ?? 0),
            'passed'     => (int) ($row['passed'] ?? 0),
            'overridden' => (int) ($row['overridden'] ?? 0),
            'average_score' => $row['average_score'] === null ? null : round((float) $row['average_score'], 1),
            'note' => 'Deterministic checks and model assessments are scored separately and shown apart.',
        ];
    }
}
