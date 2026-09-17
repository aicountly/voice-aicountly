<?php

declare(strict_types=1);

namespace Aicountly\Api\Dashboards;

use Aicountly\Api\Db;
use Aicountly\Api\Domain\BudgetService;
use Aicountly\Api\Domain\CallingPolicy;
use Aicountly\Api\Domain\UsageService;
use Aicountly\Api\Features;
use Aicountly\Api\Settings;
use Aicountly\Api\Support\Clock;
use Aicountly\Api\Telephony\Capability;
use Aicountly\Api\Telephony\ProviderRegistry;

/**
 * Dashboard 6 — Network & Usage.
 *
 * ## Latency is four different things
 *
 * Media transport, speech recognition, model and tool time, and time to first
 * audio back to the caller. They have different causes and different fixes, and
 * a single "latency: 148 ms" that is secretly a mixture of them sends somebody
 * to tune the wrong thing. Each is reported separately, and any we cannot
 * measure is reported as not measured rather than folded into the others.
 *
 * ## Cost
 *
 * Estimated and provider-confirmed, apart, with the currency and the period on
 * the panel. See UsageService.
 *
 * ## Failover
 *
 * A backup route is reported as standby, with when it was last actually
 * TESTED. An untested backup is not a backup, and this screen says so rather
 * than showing a reassuring green dot. Failover is claimed only before a call
 * connects, and only where the adapter reports `preconnect_failover` — moving
 * an established call between carriers needs infrastructure this product does
 * not assume.
 */
final class NetworkDashboard extends Dashboard
{
    public function id(): string
    {
        return 'network';
    }

    public function build(): array
    {
        $from = Clock::iso($this->period->from);
        $to = Clock::iso($this->period->to);

        // A live probe, because a stored status is what was true when somebody
        // last saved the form.
        $connections = ProviderRegistry::inventory($this->ctx, true);
        $capacity = BudgetService::concurrency($this->ctx);
        $usage = UsageService::breakdown($this->ctx, $from, $to);
        $success = $this->connectionSuccess($from, $to);
        $previousSuccess = $this->connectionSuccess(Clock::iso($this->period->previousFrom), Clock::iso($this->period->previousTo));
        $latency = $this->latency($from, $to);

        $totalMinor = $usage['totals']['confirmed_minor'] > 0
            ? $usage['totals']['confirmed_minor']
            : $usage['totals']['estimated_minor'];

        $metrics = [
            $success['eligible'] === 0
                ? Metric::unavailable('connection_success', 'Connection success', 'No call attempts in this period.', 'percent')
                : Metric::make('connection_success', 'Connection success', $success['rate'], 'percent',
                    $previousSuccess['rate'], 'up_is_good',
                    'Attempts that reached the far end, of ' . $success['eligible'] . ' eligible.'),
            Metric::make('concurrent', 'Concurrent calls', $capacity['active'], 'count', null, 'neutral',
                $capacity['limit'] === 0
                    ? 'No concurrency limit configured.'
                    : 'Of ' . $capacity['limit'] . ' (' . $capacity['source'] . ' limit).'),
            $latency['media_ms'] === null
                ? Metric::unavailable('media_latency', 'Media latency',
                    'The gateway does not report media latency for this connection.', 'ms')
                : Metric::make('media_latency', 'Media latency', $latency['media_ms'], 'ms', null, 'down_is_good',
                    'Transport only. Speech and model time are shown separately.'),
            $totalMinor === 0
                ? Metric::unavailable('usage', 'Usage', 'No usage has been priced for this period.', 'currency')
                : Metric::make('usage', 'Usage', $totalMinor / 100, 'currency', null, 'down_is_good',
                    $usage['totals']['confirmed_minor'] > 0 ? 'Provider-confirmed charges.' : 'Estimated from the rate card.'),
        ];

        return $this->envelope($metrics, [
            'connections' => $connections,
            'routes'      => $this->routes($connections),
            'numbers'     => $this->numbers(),
            'capacity'    => $capacity,
            'usage'       => $usage,
            'budget'      => BudgetService::status($this->ctx),
            'latency'     => $latency,
            'integrations' => $this->integrations(),
            'errors'      => $this->providerErrors($from, $to),
        ], [
            'definitions' => [
                'connection_success' => 'Attempts that reached the far end ÷ eligible attempts. A call refused before dialling is not eligible.',
                'media_latency'      => 'Transport only, as reported by the gateway.',
                'usage'              => 'Provider-confirmed where available, otherwise estimated. The two are never added together.',
                'standby'            => 'A configured backup route. "Standby" says it exists, not that it works — see when it was last tested.',
            ],
            'currency' => (string) Settings::forCompany($this->ctx->cmpId)['currency'],
        ]);
    }

    /**
     * The four latency figures, kept apart.
     *
     * @return array<string, mixed>
     */
    private function latency(string $fromIso, string $toIso): array
    {
        // Measured from provider events where the gateway reports them. Where
        // it does not, the answer is null and the screen says "not measured" —
        // never an average of whatever else happens to be available.
        $row = Db::first(
            'SELECT
                AVG((payload->>\'media_latency_ms\')::numeric)     AS media_ms,
                AVG((payload->>\'stt_latency_ms\')::numeric)       AS stt_ms,
                AVG((payload->>\'model_latency_ms\')::numeric)     AS model_ms,
                AVG((payload->>\'first_audio_ms\')::numeric)       AS first_audio_ms,
                COUNT(*)                                           AS samples
               FROM voice_call_events
              WHERE cmp_id = :cmp AND received_at >= :from AND received_at < :to
                AND payload ? \'media_latency_ms\'',
            ['cmp' => $this->ctx->cmpId, 'from' => $fromIso, 'to' => $toIso],
        ) ?? [];

        $round = static fn (mixed $value): ?int => $value === null ? null : (int) round((float) $value);

        return [
            'media_ms'       => $round($row['media_ms'] ?? null),
            'stt_ms'         => $round($row['stt_ms'] ?? null),
            'model_ms'       => $round($row['model_ms'] ?? null),
            'first_audio_ms' => $round($row['first_audio_ms'] ?? null),
            'samples'        => (int) ($row['samples'] ?? 0),
            'labels' => [
                'media_ms'       => 'Media transport',
                'stt_ms'         => 'Speech recognition',
                'model_ms'       => 'Model and tools',
                'first_audio_ms' => 'Time to first response audio',
            ],
            'note' => 'Measured separately. Any figure shown as not measured is one the gateway does not report for this connection — it is not folded into the others.',
        ];
    }

    /** @param list<array<string, mixed>> $connections @return list<array<string, mixed>> */
    private function routes(array $connections): array
    {
        $out = [];
        foreach ($connections as $connection) {
            $activeCalls = (int) (Db::scalar(
                'SELECT COUNT(*) FROM voice_calls
                  WHERE cmp_id = :cmp AND connection_id = :conn AND ended_at IS NULL',
                ['cmp' => $this->ctx->cmpId, 'conn' => (int) $connection['connection_id']],
            ) ?? 0);

            $isBackup = $connection['role'] === 'backup';
            $lastTested = $connection['last_tested_at'] ?? null;

            $out[] = [
                'connection_id' => (int) $connection['connection_id'],
                'label'         => (string) $connection['label'],
                'role'          => (string) $connection['role'],
                'status'        => $isBackup && $activeCalls === 0 ? 'standby' : (string) $connection['status'],
                'status_detail' => $connection['status_detail'] ?? null,
                'active_calls'  => $activeCalls,
                'latency_ms'    => $connection['latency_ms'] ?? null,
                'last_tested_at' => $lastTested,
                // An untested backup is not a backup.
                'test_due'      => $isBackup && ($lastTested === null
                    || (Clock::parse((string) $lastTested)?->getTimestamp() ?? 0) < Clock::now()->modify('-30 days')->getTimestamp()),
                'failover'      => (bool) ($connection['capabilities'][Capability::PRECONNECT_FAILOVER] ?? false),
                'failover_note' => ($connection['capabilities'][Capability::PRECONNECT_FAILOVER] ?? false)
                    ? 'Failover applies before a call connects. An established call is not migrated.'
                    : 'This connection does not support failover.',
            ];
        }

        return $out;
    }

    /** @return list<array<string, mixed>> */
    private function numbers(): array
    {
        [$scope, $params] = $this->ctx->scopeClause('n');

        $rows = Db::all(
            'SELECT n.*, t.name AS team_name, f.name AS flow_name
               FROM voice_numbers n
               LEFT JOIN voice_teams t      ON t.team_id = n.team_id
               LEFT JOIN voice_call_flows f ON f.flow_id = n.inbound_flow_id
              WHERE ' . $scope . '
              ORDER BY n.is_active DESC, n.e164
              LIMIT 100',
            $params,
        );

        $out = [];
        foreach ($rows as $row) {
            $out[] = [
                'number_id' => (int) $row['number_id'],
                'e164'      => (string) $row['e164'],
                'masked'    => CallingPolicy::mask((string) $row['e164']),
                'label'     => (string) $row['label'],
                'number_type' => (string) $row['number_type'],
                'team_name' => $row['team_name'],
                'flow_name' => $row['flow_name'],
                'recording_policy' => (string) $row['recording_policy'],
                'routing_status' => (string) $row['routing_status'],
                'status_detail' => $row['status_detail'],
                'is_active' => (bool) $row['is_active'],
            ];
        }

        return $out;
    }

    /** @return array<string, int|float> */
    private function connectionSuccess(string $fromIso, string $toIso): array
    {
        [$scope, $params] = $this->ctx->scopeClause();
        $params['from'] = $fromIso;
        $params['to'] = $toIso;

        $row = Db::first(
            'SELECT
                COUNT(*) FILTER (WHERE state <> \'failed\')  AS connected,
                COUNT(*)                                     AS eligible
               FROM voice_calls
              WHERE ' . $scope . ' AND initiated_at >= :from AND initiated_at < :to
                AND state <> \'cancelled\'',
            $params,
        ) ?? [];

        $eligible = (int) ($row['eligible'] ?? 0);

        return [
            'connected' => (int) ($row['connected'] ?? 0),
            'eligible'  => $eligible,
            'rate'      => $eligible === 0 ? 0.0 : (self::rate((int) $row['connected'], $eligible) ?? 0.0),
        ];
    }

    /** @return list<array<string, mixed>> */
    private function providerErrors(string $fromIso, string $toIso): array
    {
        return Db::all(
            'SELECT event_type, COUNT(*) AS occurrences, MAX(received_at) AS last_seen
               FROM voice_call_events
              WHERE cmp_id = :cmp AND received_at >= :from AND received_at < :to
                AND (event_type LIKE \'%failed%\' OR event_type LIKE \'%error%\' OR skip_reason IS NOT NULL)
              GROUP BY event_type ORDER BY occurrences DESC LIMIT 10',
            ['cmp' => $this->ctx->cmpId, 'from' => $fromIso, 'to' => $toIso],
        );
    }

    /**
     * Integration health.
     *
     * Every state is explicit — configured, connected, degraded, unavailable,
     * forbidden, not configured — and a product that is not connected says why.
     * Nothing here shows "connected" without evidence.
     *
     * @return list<array<string, mixed>>
     */
    private function integrations(): array
    {
        $stored = [];
        foreach (Db::all('SELECT * FROM voice_integrations WHERE cmp_id = :cmp', ['cmp' => $this->ctx->cmpId]) as $row) {
            $stored[(string) $row['app']] = $row;
        }

        $apps = [
            'calendar'  => 'Aicountly Calendar',
            'contacts'  => 'Aicountly Contacts',
            'crm'       => 'Aicountly CRM',
            'pay'       => 'Aicountly Pay',
            'lobby'     => 'Aicountly Lobby',
            'messaging' => 'Aicountly Messaging',
            'billing'   => 'Aicountly Billing',
        ];

        $out = [];
        foreach ($apps as $app => $label) {
            $flag = strtoupper($app);
            $enabled = Features::enabled($flag);
            $row = $stored[$app] ?? null;

            $status = match (true) {
                !$enabled                              => 'not_configured',
                $row === null                          => 'configured',
                default                                => (string) $row['status'],
            };

            $out[] = [
                'app'    => $app,
                'label'  => $label,
                'status' => $status,
                'reason' => $enabled ? ($row['status_detail'] ?? null) : Features::explain($flag),
                'checked_at' => $row['checked_at'] ?? null,
                'last_ok_at' => $row['last_ok_at'] ?? null,
                'can_test'   => $enabled,
            ];
        }

        return $out;
    }
}
