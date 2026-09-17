<?php

declare(strict_types=1);

namespace Aicountly\Api\Dashboards;

use Aicountly\Api\Db;
use Aicountly\Api\Domain\BudgetService;
use Aicountly\Api\Domain\PresenceService;
use Aicountly\Api\Features;
use Aicountly\Api\Support\Clock;

/**
 * Dashboard 1 — Command Centre.
 *
 * For the owner, the administrator, the operations manager: what is happening
 * across the business, and what needs somebody.
 *
 * ## The two numbers this screen has to get right
 *
 * ANSWER RATE. Answered divided by ELIGIBLE attempts — calls that got far
 * enough to be answerable. A call cancelled before it rang was never a chance
 * to answer. The denominator is on the card, because a rate without one is a
 * number two people will read differently.
 *
 * AI RESOLUTION. Calls the AI handled END TO END, divided by calls the AI took.
 * A call the AI answered and then passed to a person is a HANDOVER and is
 * counted as one. Counting handovers as resolutions is how a business is told
 * its AI resolves 68% of calls while its agents are busier than ever.
 *
 * ## The briefing
 *
 * Each item says what, why, from which window, and links to the filtered screen
 * where it can be dealt with. It is generated from thresholds over figures
 * computed here — no model writes a number on this screen.
 */
final class CommandCentreDashboard extends Dashboard
{
    public function id(): string
    {
        return 'command_centre';
    }

    public function build(): array
    {
        $from = Clock::iso($this->period->from);
        $to = Clock::iso($this->period->to);
        $counts = $this->callCounts($from, $to);
        $previous = $this->callCounts(Clock::iso($this->period->previousFrom), Clock::iso($this->period->previousTo));

        $live = $this->liveCounts();
        $presence = PresenceService::summary($this->ctx);
        $callbacks = $this->callbackCounts();

        // AI resolution is measured against calls the AI actually took, not
        // against every call in the business.
        $aiHandled = $counts['ai_completed'] + $counts['handover_completed'];
        $aiPrevious = $previous['ai_completed'] + $previous['handover_completed'];

        $metrics = [
            Metric::make(
                'calls_today',
                'Calls',
                $counts['total'],
                'count',
                $previous['total'],
                'neutral',
                'Logical calls, not legs — a transferred call counts once.',
                '/calls',
                ['from' => $from, 'to' => $to],
            ),
            Metric::make(
                'answer_rate',
                'Answer rate',
                self::rate($counts['answered'], $counts['eligible']),
                'percent',
                self::rate($previous['answered'], $previous['eligible']),
                'up_is_good',
                $counts['eligible'] . ' eligible attempts (cancelled calls excluded).',
                '/calls',
                ['state' => 'unanswered'],
            ),
            $aiHandled === 0
                ? Metric::make('ai_resolution', 'AI resolved', null, 'percent', null, 'up_is_good', 'No AI calls in this period.')
                : Metric::make(
                    'ai_resolution',
                    'AI resolved',
                    self::rate($counts['ai_completed'], $aiHandled),
                    'percent',
                    self::rate($previous['ai_completed'], $aiPrevious),
                    'up_is_good',
                    'Completed by AI without a handover, of ' . $aiHandled . ' AI calls.',
                    '/calls',
                    ['handled_by' => 'ai'],
                ),
            Metric::make(
                'callbacks_due',
                'Callbacks due',
                $callbacks['due'],
                'count',
                null,
                'down_is_good',
                $callbacks['overdue'] > 0 ? $callbacks['overdue'] . ' already overdue.' : 'Nothing overdue.',
                '/callbacks',
                ['status' => 'open'],
            ),
        ];

        return $this->envelope($metrics, [
            'live' => [
                'active_calls'     => $live['active'],
                'ai_sessions'      => $live['ai'],
                'available_agents' => $presence['available'],
                // Never folded into "available". An agent we cannot reach is
                // not an agent who can take a call.
                'unknown_agents'   => $presence['unknown'],
                'queue_waiting'    => $live['waiting'],
                'longest_wait_seconds' => $live['longest_wait'],
                // The waveform on this card is decoration and says so. Nothing
                // on this screen listens to a call.
                'waveform_is_decorative' => true,
            ],
            'attention'  => $this->attention($counts, $live, $callbacks),
            'outcomes'   => $this->outcomes($counts),
            'volume_by_hour' => $this->volumeByHour($from, $to),
            'queues'     => $this->queuePressure(),
            'workflows'  => $this->connectedWorkflows($from, $to),
            'capacity'   => BudgetService::concurrency($this->ctx),
        ], [
            'definitions' => [
                'answer_rate'    => 'Answered calls ÷ eligible attempts. An attempt cancelled before ringing is not eligible.',
                'ai_resolution'  => 'Calls completed by AI with no handover ÷ calls the AI took. Handovers are counted separately.',
                'calls'          => 'Logical interactions. A call transferred between agents counts once.',
                'abandoned'      => 'The caller hung up before being answered.',
            ],
            'sources' => [
                ['name' => 'Voice', 'kind' => 'own'],
            ],
        ]);
    }

    /**
     * The briefing. Threshold rules over figures computed above.
     *
     * Each entry carries the window it was measured over and a link to the
     * filtered screen, so "12 unanswered calls" is one click from those twelve
     * calls rather than a number to go hunting for.
     *
     * @param array<string, int> $counts
     * @param array<string, int> $live
     * @param array<string, int> $callbacks
     * @return list<array<string, mixed>>
     */
    private function attention(array $counts, array $live, array $callbacks): array
    {
        $items = [];
        $window = $this->period->label;

        // The example from the specification, computed rather than written.
        $unansweredWithoutCallback = (int) (Db::scalar(
            'SELECT COUNT(*) FROM voice_calls c
              WHERE c.cmp_id = :cmp
                AND c.direction = \'inbound\'
                AND c.answered_at IS NULL
                AND c.initiated_at >= :from
                AND NOT EXISTS (
                      SELECT 1 FROM voice_callbacks b
                       WHERE b.source_call_id = c.call_id AND b.status = \'completed\'
                )',
            ['cmp' => $this->ctx->cmpId, 'from' => Clock::iso($this->period->from)],
        ) ?? 0);

        if ($unansweredWithoutCallback > 0) {
            $items[] = [
                'id'       => 'unanswered_without_callback',
                'priority' => $unansweredWithoutCallback >= 10 ? 'high' : 'medium',
                'title'    => $unansweredWithoutCallback . ' unanswered '
                    . ($unansweredWithoutCallback === 1 ? 'call has' : 'calls have') . ' no completed callback',
                'why'      => 'These callers have not been reached since they rang.',
                'source'   => 'Voice calls and callbacks, ' . $window,
                'action'   => 'Review the callback queue',
                'link'     => ['route' => '/callbacks', 'params' => ['status' => 'open']],
            ];
        }

        if ($callbacks['overdue'] > 0) {
            $items[] = [
                'id'       => 'callbacks_overdue',
                'priority' => 'high',
                'title'    => $callbacks['overdue'] . ' overdue ' . ($callbacks['overdue'] === 1 ? 'callback' : 'callbacks'),
                'why'      => 'These were promised for a time that has passed.',
                'source'   => 'Voice callbacks, due before now',
                'action'   => 'Open overdue callbacks',
                'link'     => ['route' => '/callbacks', 'params' => ['overdue' => '1']],
            ];
        }

        if ($live['longest_wait'] >= 120) {
            $items[] = [
                'id'       => 'queue_pressure',
                'priority' => 'medium',
                'title'    => 'A caller has been waiting ' . gmdate('i:s', $live['longest_wait']),
                'why'      => $live['waiting'] . ' ' . ($live['waiting'] === 1 ? 'caller is' : 'callers are') . ' in a queue now.',
                'source'   => 'Live queue state',
                'action'   => 'Open Live Operations',
                'link'     => ['route' => '/live', 'params' => []],
            ];
        }

        if ($counts['abandoned'] > 0 && $counts['eligible'] > 0) {
            $rate = self::rate($counts['abandoned'], $counts['eligible']) ?? 0.0;
            if ($rate >= 5.0) {
                $items[] = [
                    'id'       => 'abandon_rate',
                    'priority' => $rate >= 15.0 ? 'high' : 'medium',
                    'title'    => $rate . '% of callers hung up before being answered',
                    'why'      => $counts['abandoned'] . ' of ' . $counts['eligible'] . ' eligible attempts.',
                    'source'   => 'Voice calls, ' . $window,
                    'action'   => 'Review abandoned calls',
                    'link'     => ['route' => '/calls', 'params' => ['abandoned' => '1']],
                ];
            }
        }

        $needsReview = (int) (Db::scalar(
            'SELECT COUNT(*) FROM voice_ai_agents
              WHERE cmp_id = :cmp AND status IN (\'draft\', \'tested\')',
            ['cmp' => $this->ctx->cmpId],
        ) ?? 0);
        if ($needsReview > 0) {
            $items[] = [
                'id'       => 'ai_agents_unpublished',
                'priority' => 'low',
                'title'    => $needsReview . ' AI ' . ($needsReview === 1 ? 'agent is' : 'agents are') . ' not published',
                'why'      => 'They are configured but not taking calls.',
                'source'   => 'AI Voice Studio',
                'action'   => 'Open AI Voice Studio',
                'link'     => ['route' => '/studio', 'params' => []],
            ];
        }

        usort($items, static function (array $a, array $b): int {
            $rank = ['high' => 0, 'medium' => 1, 'low' => 2];

            return ($rank[$a['priority']] ?? 3) <=> ($rank[$b['priority']] ?? 3);
        });

        return $items;
    }

    /**
     * Outcome mix. The categories do not overlap, so they sum to the answered
     * calls and no more.
     *
     * @param array<string, int> $counts
     * @return list<array<string, mixed>>
     */
    private function outcomes(array $counts): array
    {
        $total = $counts['ai_completed'] + $counts['handover_completed'] + $counts['human_completed']
            + $counts['voicemail'] + $counts['no_answer'] + $counts['failed'];

        $rows = [
            ['key' => 'ai_completed',       'label' => 'Completed by AI',     'count' => $counts['ai_completed']],
            ['key' => 'handover_completed', 'label' => 'Handed to a person',  'count' => $counts['handover_completed']],
            ['key' => 'human_completed',    'label' => 'Handled by a person', 'count' => $counts['human_completed']],
            ['key' => 'voicemail',          'label' => 'Voicemail',           'count' => $counts['voicemail']],
            ['key' => 'no_answer',          'label' => 'No answer',           'count' => $counts['no_answer']],
            ['key' => 'failed',             'label' => 'Failed',              'count' => $counts['failed']],
        ];

        foreach ($rows as &$row) {
            $row['percent'] = self::rate($row['count'], $total);
        }

        return $rows;
    }

    /** @return array<string, int> */
    private function liveCounts(): array
    {
        [$scope, $params] = $this->ctx->scopeClause();

        $row = Db::first(
            'SELECT
                COUNT(*) FILTER (WHERE ended_at IS NULL AND state IN (\'ringing\', \'answered\', \'held\', \'transferring\')) AS active,
                COUNT(*) FILTER (WHERE ended_at IS NULL AND handled_by = \'ai\')  AS ai,
                COUNT(*) FILTER (WHERE ended_at IS NULL AND state = \'queued\')   AS waiting,
                COALESCE(MAX(EXTRACT(EPOCH FROM (NOW() - initiated_at))) FILTER (WHERE ended_at IS NULL AND state = \'queued\'), 0) AS longest_wait
               FROM voice_calls WHERE ' . $scope,
            $params,
        ) ?? [];

        return [
            'active'       => (int) ($row['active'] ?? 0),
            'ai'           => (int) ($row['ai'] ?? 0),
            'waiting'      => (int) ($row['waiting'] ?? 0),
            'longest_wait' => (int) ($row['longest_wait'] ?? 0),
        ];
    }

    /** @return array<string, int> */
    private function callbackCounts(): array
    {
        [$scope, $params] = $this->ctx->scopeClause();

        $row = Db::first(
            'SELECT
                COUNT(*) FILTER (WHERE status IN (\'open\', \'scheduled\'))                       AS due,
                COUNT(*) FILTER (WHERE status IN (\'open\', \'scheduled\') AND due_at < NOW())    AS overdue
               FROM voice_callbacks WHERE ' . $scope,
            $params,
        ) ?? [];

        return ['due' => (int) ($row['due'] ?? 0), 'overdue' => (int) ($row['overdue'] ?? 0)];
    }

    /** @return list<array<string, mixed>> */
    private function queuePressure(): array
    {
        [$scope, $params] = $this->ctx->scopeClause('q');

        return Db::all(
            'SELECT q.queue_id, q.name,
                    COUNT(c.call_id) FILTER (WHERE c.ended_at IS NULL AND c.state = \'queued\')   AS waiting,
                    COUNT(c.call_id) FILTER (WHERE c.ended_at IS NULL AND c.state = \'answered\') AS in_call,
                    COALESCE(MAX(EXTRACT(EPOCH FROM (NOW() - c.initiated_at)))
                             FILTER (WHERE c.ended_at IS NULL AND c.state = \'queued\'), 0)::int  AS longest_wait
               FROM voice_queues q
               LEFT JOIN voice_calls c ON c.queue_id = q.queue_id
              WHERE ' . $scope . ' AND q.is_active = TRUE
              GROUP BY q.queue_id, q.name
              ORDER BY waiting DESC, q.name',
            $params,
        );
    }

    /**
     * What connected products actually acknowledged.
     *
     * Counted from voice_external_operations with status 'succeeded' — that is,
     * outcomes the OWNING product confirmed. A booking Voice believes it made
     * is not a booking. A product that is not connected says so rather than
     * showing a zero that looks like failure.
     *
     * @return list<array<string, mixed>>
     */
    private function connectedWorkflows(string $fromIso, string $toIso): array
    {
        $rows = Db::all(
            'SELECT target_app, operation, COUNT(*) AS confirmed
               FROM voice_external_operations
              WHERE cmp_id = :cmp AND status = \'succeeded\'
                AND created_at >= :from AND created_at < :to
              GROUP BY target_app, operation',
            ['cmp' => $this->ctx->cmpId, 'from' => $fromIso, 'to' => $toIso],
        );

        $byApp = [];
        foreach ($rows as $row) {
            $app = (string) $row['target_app'];
            $byApp[$app] ??= ['confirmed' => 0, 'operations' => []];
            $byApp[$app]['confirmed'] += (int) $row['confirmed'];
            $byApp[$app]['operations'][(string) $row['operation']] = (int) $row['confirmed'];
        }

        $out = [];
        foreach (['calendar' => 'Aicountly Calendar', 'crm' => 'Aicountly CRM', 'pay' => 'Aicountly Pay', 'lobby' => 'Aicountly Lobby'] as $app => $label) {
            $enabled = Features::enabled(strtoupper($app));
            $out[] = [
                'app'       => $app,
                'label'     => $label,
                'status'    => $enabled ? 'connected' : 'not_configured',
                'reason'    => $enabled ? null : Features::explain(strtoupper($app)),
                'confirmed' => $enabled ? ($byApp[$app]['confirmed'] ?? 0) : null,
                'operations' => $enabled ? ($byApp[$app]['operations'] ?? []) : [],
                'note'      => $enabled ? 'Counted from outcomes this product acknowledged.' : null,
            ];
        }

        return $out;
    }
}
