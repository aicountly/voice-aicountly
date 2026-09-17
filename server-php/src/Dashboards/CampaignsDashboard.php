<?php

declare(strict_types=1);

namespace Aicountly\Api\Dashboards;

use Aicountly\Api\Db;
use Aicountly\Api\Domain\BudgetService;
use Aicountly\Api\Domain\CampaignService;
use Aicountly\Api\Settings;
use Aicountly\Api\Support\Clock;
use Aicountly\Api\Telephony\Capability;
use Aicountly\Api\Telephony\ProviderRegistry;

/**
 * Dashboard 4 — Campaigns & Growth.
 *
 * ## The funnel has one denominator
 *
 * Attempted, connected, qualified, confirmed — each as a percentage of
 * ATTEMPTS. Quoting "70% connected" against attempts and "43% qualified"
 * against connections puts two different denominators on one chart, and the
 * reader adds them up wrongly every time.
 *
 * ## A confirmed outcome is confirmed by the product that owns it
 *
 * `confirmed` counts external operations that SUCCEEDED — Calendar
 * acknowledged the booking, Pay acknowledged the payment. Voice's own belief
 * that something happened is not a business outcome.
 *
 * ## Cost per booking
 *
 * Only shown when there are confirmed outcomes AND usage for the campaign's own
 * period. Dividing this month's spend by last month's bookings is arithmetic
 * that produces a number and means nothing.
 */
final class CampaignsDashboard extends Dashboard
{
    public function id(): string
    {
        return 'campaigns';
    }

    public function build(): array
    {
        $from = Clock::iso($this->period->from);
        $to = Clock::iso($this->period->to);

        $totals = $this->totals($from, $to);
        $previous = $this->totals(Clock::iso($this->period->previousFrom), Clock::iso($this->period->previousTo));
        $currency = (string) Settings::forCompany($this->ctx->cmpId)['currency'];

        $spendMinor = $this->campaignSpend($from, $to);
        $costPerConfirmed = $totals['confirmed'] > 0 && $spendMinor > 0
            ? (int) round($spendMinor / $totals['confirmed'])
            : null;

        $metrics = [
            Metric::make('connected', 'Connected', $totals['connected'], 'count', $previous['connected'], 'up_is_good',
                'Attempts where the call was answered, of ' . $totals['attempted'] . ' attempted.'),
            Metric::make('qualified', 'Qualified', $totals['qualified'], 'count', $previous['qualified'], 'up_is_good',
                'Given a disposition in the "qualified" category.'),
            Metric::make('confirmed', 'Confirmed outcomes', $totals['confirmed'], 'count', $previous['confirmed'], 'up_is_good',
                'Acknowledged by the owning product’s API.'),
            $costPerConfirmed === null
                ? Metric::unavailable('cost_per_outcome', 'Cost per outcome',
                    $totals['confirmed'] === 0
                        ? 'No confirmed outcomes in this period.'
                        : 'No campaign usage has been priced for this period.',
                    'currency')
                : Metric::make('cost_per_outcome', 'Cost per outcome', $costPerConfirmed / 100, 'currency', null, 'down_is_good',
                    'Campaign spend in this period ÷ confirmed outcomes in this period.'),
        ];

        return $this->envelope($metrics, [
            'campaigns' => $this->campaigns($from, $to),
            'funnel'    => $this->funnel($totals),
            'modes'     => $this->availableModes(),
            'budget'    => BudgetService::status($this->ctx),
            'callback_planner' => $this->callbackPlanner(),
            'planner'   => [
                // The AI planner writes a DRAFT. It has no route to launch.
                'drafts_only' => true,
                'note' => 'The planner proposes an audience, a script and a schedule. It never starts a campaign; a person reviews and launches.',
            ],
        ], [
            'definitions' => [
                'denominator' => 'Every funnel figure is a percentage of attempts in the selected period.',
                'connected'   => 'The call was answered.',
                'qualified'   => 'An answered call given a disposition in the "qualified" category.',
                'confirmed'   => 'An outcome the owning product acknowledged through its API.',
                'cost_per_outcome' => 'Campaign usage priced in this period ÷ confirmed outcomes in this period. Periods are never mixed.',
            ],
            'currency' => $currency,
        ]);
    }

    /** @param array<string, int> $totals @return list<array<string, mixed>> */
    private function funnel(array $totals): array
    {
        $attempted = $totals['attempted'];

        return [
            ['key' => 'attempted', 'label' => 'Attempted', 'count' => $attempted, 'percent' => $attempted === 0 ? null : 100.0],
            ['key' => 'connected', 'label' => 'Connected', 'count' => $totals['connected'], 'percent' => self::rate($totals['connected'], $attempted)],
            ['key' => 'qualified', 'label' => 'Qualified', 'count' => $totals['qualified'], 'percent' => self::rate($totals['qualified'], $attempted)],
            ['key' => 'confirmed', 'label' => 'Confirmed', 'count' => $totals['confirmed'], 'percent' => self::rate($totals['confirmed'], $attempted)],
        ];
    }

    /** @return array<string, int> */
    private function totals(string $fromIso, string $toIso): array
    {
        $row = Db::first(
            'SELECT
                COUNT(*)                                                            AS attempted,
                COUNT(*) FILTER (WHERE status IN (\'connected\', \'completed\'))    AS connected,
                COUNT(*) FILTER (WHERE skip_reason IS NOT NULL)                     AS skipped
               FROM voice_campaign_attempts
              WHERE cmp_id = :cmp AND created_at >= :from AND created_at < :to',
            ['cmp' => $this->ctx->cmpId, 'from' => $fromIso, 'to' => $toIso],
        ) ?? [];

        $qualified = (int) (Db::scalar(
            'SELECT COUNT(*) FROM voice_calls c
               JOIN voice_dispositions d ON d.disposition_id = c.disposition_id
              WHERE c.cmp_id = :cmp AND c.campaign_id IS NOT NULL
                AND d.category = \'qualified\'
                AND c.initiated_at >= :from AND c.initiated_at < :to',
            ['cmp' => $this->ctx->cmpId, 'from' => $fromIso, 'to' => $toIso],
        ) ?? 0);

        $confirmed = (int) (Db::scalar(
            'SELECT COUNT(*) FROM voice_external_operations o
               JOIN voice_calls c ON c.call_id = o.call_id
              WHERE o.cmp_id = :cmp AND o.status = \'succeeded\'
                AND c.campaign_id IS NOT NULL
                AND o.created_at >= :from AND o.created_at < :to',
            ['cmp' => $this->ctx->cmpId, 'from' => $fromIso, 'to' => $toIso],
        ) ?? 0);

        return [
            'attempted' => (int) ($row['attempted'] ?? 0),
            'connected' => (int) ($row['connected'] ?? 0),
            'skipped'   => (int) ($row['skipped'] ?? 0),
            'qualified' => $qualified,
            'confirmed' => $confirmed,
        ];
    }

    /** @return list<array<string, mixed>> */
    private function campaigns(string $fromIso, string $toIso): array
    {
        [$scope, $params] = $this->ctx->scopeClause('c');
        $params['from'] = $fromIso;
        $params['to'] = $toIso;

        $rows = Db::all(
            'SELECT c.campaign_id, c.name, c.mode, c.status, c.status_reason, c.created_at,
                    c.started_at, c.readiness,
                    (SELECT COUNT(*) FROM voice_campaign_audience_refs r WHERE r.campaign_id = c.campaign_id) AS audience,
                    (SELECT COUNT(*) FROM voice_campaign_attempts a WHERE a.campaign_id = c.campaign_id) AS attempted,
                    (SELECT COUNT(*) FROM voice_campaign_attempts a
                      WHERE a.campaign_id = c.campaign_id AND a.status IN (\'connected\', \'completed\')) AS connected
               FROM voice_campaigns c
              WHERE ' . $scope . ' AND (c.updated_at >= :from OR c.status IN (\'running\', \'paused\', \'scheduled\'))
                AND c.created_at < :to
              ORDER BY
                CASE c.status WHEN \'running\' THEN 0 WHEN \'paused\' THEN 1 WHEN \'scheduled\' THEN 2 ELSE 3 END,
                c.updated_at DESC
              LIMIT 50',
            $params,
        );

        $out = [];
        foreach ($rows as $row) {
            $campaignId = (int) $row['campaign_id'];
            $attempted = (int) $row['attempted'];
            $readiness = Db::jsonColumn($row['readiness'] ?? null);

            $out[] = [
                'campaign_id' => $campaignId,
                'name'        => (string) $row['name'],
                'mode'        => (string) $row['mode'],
                'status'      => (string) $row['status'],
                'status_reason' => $row['status_reason'],
                'audience'    => (int) $row['audience'],
                'attempted'   => $attempted,
                'connected'   => (int) $row['connected'],
                'progress'    => (int) $row['audience'] === 0 ? null : self::rate($attempted, (int) $row['audience']),
                'ready'       => (bool) ($readiness['ready'] ?? false),
                'blocking'    => $this->blockingChecks($readiness),
                'created_at'  => $row['created_at'],
                'started_at'  => $row['started_at'],
                'outcomes'    => CampaignService::funnel($this->ctx, $campaignId),
            ];
        }

        return $out;
    }

    /** @param array<string, mixed> $readiness @return list<string> */
    private function blockingChecks(array $readiness): array
    {
        $out = [];
        foreach ($readiness['checks'] ?? [] as $check) {
            if (($check['status'] ?? '') === 'error') {
                $out[] = (string) ($check['message'] ?? $check['key'] ?? 'Not ready');
            }
        }

        return $out;
    }

    /**
     * Which campaign modes this company can actually run.
     *
     * A mode the provider cannot serve is returned as unavailable WITH the
     * reason, so the builder can grey it out and say why instead of offering a
     * control that fails at launch.
     *
     * @return list<array<string, mixed>>
     */
    private function availableModes(): array
    {
        $capabilities = ProviderRegistry::forCompany($this->ctx)->capabilities();

        $modes = [
            'preview'              => ['label' => 'Human-assisted preview dialling', 'needs' => Capability::PLACE_CALL],
            'power'                => ['label' => 'Power dialling',                  'needs' => Capability::PLACE_CALL],
            'announcement'         => ['label' => 'Recorded announcement',           'needs' => Capability::TTS_PLAYBACK],
            'tts'                  => ['label' => 'Text to speech',                  'needs' => Capability::TTS_PLAYBACK],
            'ivr'                  => ['label' => 'Interactive IVR',                 'needs' => Capability::DTMF],
            'ai_conversation'      => ['label' => 'AI conversation',                 'needs' => Capability::LIVE_TRANSCRIPT],
            'appointment_reminder' => ['label' => 'Appointment reminders',           'needs' => Capability::TTS_PLAYBACK],
            'requested_callback'   => ['label' => 'Requested callbacks',             'needs' => Capability::PLACE_CALL],
            'renewal_followup'     => ['label' => 'Renewal follow-up',               'needs' => Capability::PLACE_CALL],
        ];

        $out = [];
        foreach ($modes as $key => $mode) {
            $supported = (bool) ($capabilities[$mode['needs']] ?? false);
            $out[] = [
                'key'       => $key,
                'label'     => $mode['label'],
                'available' => $supported,
                'reason'    => $supported
                    ? null
                    : 'This connection does not support ' . Capability::describe($mode['needs']) . '.',
            ];
        }

        return $out;
    }

    private function campaignSpend(string $fromIso, string $toIso): int
    {
        return (int) (Db::scalar(
            'SELECT COALESCE(SUM(amount_minor), 0) FROM voice_usage_entries
              WHERE cmp_id = :cmp AND campaign_id IS NOT NULL
                AND occurred_at >= :from AND occurred_at < :to',
            ['cmp' => $this->ctx->cmpId, 'from' => $fromIso, 'to' => $toIso],
        ) ?? 0);
    }

    /** @return list<array<string, mixed>> */
    private function callbackPlanner(): array
    {
        return Db::all(
            'SELECT date_trunc(\'hour\', due_at) AS slot, COUNT(*) AS due, priority
               FROM voice_callbacks
              WHERE cmp_id = :cmp AND status IN (\'open\', \'scheduled\') AND due_at IS NOT NULL
                AND due_at >= NOW() - INTERVAL \'1 day\' AND due_at < NOW() + INTERVAL \'7 days\'
              GROUP BY 1, priority ORDER BY 1',
            ['cmp' => $this->ctx->cmpId],
        );
    }
}
