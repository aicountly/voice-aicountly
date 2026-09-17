<?php

declare(strict_types=1);

namespace Aicountly\Api\Dashboards;

use Aicountly\Api\Auth;
use Aicountly\Api\Context;
use Aicountly\Api\Db;
use Aicountly\Api\Settings;
use Aicountly\Api\Support\Clock;

/**
 * What all six dashboards share.
 *
 * ## The six are genuinely different questions
 *
 * Command Centre  — what is happening across the business today.
 * Live Operations — what needs somebody RIGHT NOW, on this call, in this queue.
 * AI Voice Studio — is this agent safe to put in front of a customer.
 * Campaigns       — is outreach producing outcomes worth its cost.
 * Intelligence    — what was actually said, and what did we promise.
 * Network & Usage — is the plumbing healthy and what is it costing.
 *
 * Different people, different times of day, different widgets. They share this
 * base and nothing else.
 *
 * ## Each dashboard is ONE request
 *
 * A dashboard that fires fourteen requests paints in fourteen stages and
 * hammers whatever is behind it. Each view aggregates in SQL here, makes at
 * most one cross-product call, and answers once.
 *
 * ## Every figure carries its definition
 *
 * "Answer rate 94.2%" is meaningless without its denominator, and two screens
 * quoting different denominators for the same label is how a business argues
 * about its own numbers. So metric notes state them, and `definitions` in the
 * envelope spells out the ones that matter.
 */
abstract class Dashboard
{
    public function __construct(
        protected readonly Context $ctx,
        protected readonly Auth $auth,
        protected readonly Period $period,
    ) {
    }

    /** @return array<string, mixed> */
    abstract public function build(): array;

    /** command_centre | live | studio | campaigns | intelligence | network */
    abstract public function id(): string;

    /**
     * @param list<Metric>         $metrics
     * @param array<string, mixed> $panels
     * @param array<string, mixed> $extra definitions, sources, notes
     * @return array<string, mixed>
     */
    protected function envelope(array $metrics, array $panels, array $extra = []): array
    {
        return [
            'view'     => $this->id(),
            'period'   => $this->period->toArray(),
            'currency' => (string) Settings::forCompany($this->ctx->cmpId)['currency'],
            'timezone' => (string) Settings::forCompany($this->ctx->cmpId)['timezone'],
            'metrics'  => array_map(static fn (Metric $m) => $m->toArray(), $metrics),
            'panels'   => $panels,
            // Every operational figure says when it was computed. A dashboard
            // left open on a wall for two hours must not read as live.
            'freshness' => [
                'generated_at' => Clock::iso(Clock::now()),
                'sources'      => $extra['sources'] ?? [],
            ],
            'definitions' => $extra['definitions'] ?? [],
        ] + array_diff_key($extra, ['sources' => true, 'definitions' => true]);
    }

    /**
     * Call counts for a window, split the way this product insists on.
     *
     * The important lines:
     *
     *   `answered`   — the call was picked up. THE DENOMINATOR for answer rate
     *                  is `eligible`, not `total`: a call that was cancelled
     *                  before it could ring was never a chance to answer, and
     *                  including it makes the rate look worse than the business
     *                  performed.
     *   `ai_completed` — the AI handled it end to end. NOT "the AI answered".
     *                  A call the AI took and then passed to a person is
     *                  `handover_completed`, however well it did first.
     *   `abandoned`  — the caller hung up while waiting.
     *
     * @return array<string, int>
     */
    protected function callCounts(string $fromIso, string $toIso): array
    {
        [$scope, $params] = $this->ctx->scopeClause();
        $params['from'] = $fromIso;
        $params['to'] = $toIso;

        $row = Db::first(
            'SELECT
                COUNT(*)                                                   AS total,
                COUNT(*) FILTER (WHERE direction = \'inbound\')            AS inbound,
                COUNT(*) FILTER (WHERE direction = \'outbound\')           AS outbound,
                COUNT(*) FILTER (WHERE answered_at IS NOT NULL)            AS answered,
                COUNT(*) FILTER (WHERE state NOT IN (\'cancelled\'))       AS eligible,
                COUNT(*) FILTER (WHERE abandoned)                          AS abandoned,
                COUNT(*) FILTER (WHERE outcome = \'ai_completed\')         AS ai_completed,
                COUNT(*) FILTER (WHERE outcome = \'handover_completed\')   AS handover_completed,
                COUNT(*) FILTER (WHERE outcome = \'human_completed\')      AS human_completed,
                COUNT(*) FILTER (WHERE outcome = \'voicemail\')            AS voicemail,
                COUNT(*) FILTER (WHERE outcome = \'no_answer\')            AS no_answer,
                COUNT(*) FILTER (WHERE outcome = \'failed\')               AS failed,
                COALESCE(SUM(talk_seconds), 0)                             AS talk_seconds
               FROM voice_calls
              WHERE ' . $scope . ' AND initiated_at >= :from AND initiated_at < :to',
            $params,
        ) ?? [];

        $out = [];
        foreach ([
            'total', 'inbound', 'outbound', 'answered', 'eligible', 'abandoned',
            'ai_completed', 'handover_completed', 'human_completed',
            'voicemail', 'no_answer', 'failed', 'talk_seconds',
        ] as $key) {
            $out[$key] = (int) ($row[$key] ?? 0);
        }

        return $out;
    }

    /** A percentage, or null when there is nothing to divide by. */
    protected static function rate(int $numerator, int $denominator): ?float
    {
        return $denominator === 0 ? null : round(($numerator / $denominator) * 100, 1);
    }

    /**
     * A per-hour histogram of call volume, in the company's timezone.
     *
     * Bucketed by local hour because "our busy hour is 2pm" is a statement
     * about the office clock, not about UTC.
     *
     * @return list<array<string, mixed>>
     */
    protected function volumeByHour(string $fromIso, string $toIso): array
    {
        [$scope, $params] = $this->ctx->scopeClause();
        $params['from'] = $fromIso;
        $params['to'] = $toIso;
        $params['tz'] = (string) Settings::forCompany($this->ctx->cmpId)['timezone'];

        $rows = Db::all(
            'SELECT EXTRACT(HOUR FROM initiated_at AT TIME ZONE :tz)::int AS hour,
                    COUNT(*)                                        AS total,
                    COUNT(*) FILTER (WHERE direction = \'inbound\')  AS inbound,
                    COUNT(*) FILTER (WHERE direction = \'outbound\') AS outbound
               FROM voice_calls
              WHERE ' . $scope . ' AND initiated_at >= :from AND initiated_at < :to
              GROUP BY 1 ORDER BY 1',
            $params,
        );

        $byHour = [];
        foreach ($rows as $row) {
            $byHour[(int) $row['hour']] = $row;
        }

        // Every hour present, so the chart has a stable width and does not
        // redraw its axis as the day fills up.
        $out = [];
        for ($hour = 0; $hour < 24; $hour++) {
            $row = $byHour[$hour] ?? null;
            $out[] = [
                'hour'     => $hour,
                'total'    => (int) ($row['total'] ?? 0),
                'inbound'  => (int) ($row['inbound'] ?? 0),
                'outbound' => (int) ($row['outbound'] ?? 0),
            ];
        }

        return $out;
    }

    /** @return array<string, mixed> */
    protected function settings(): array
    {
        return Settings::forCompany($this->ctx->cmpId);
    }
}
