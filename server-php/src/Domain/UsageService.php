<?php

declare(strict_types=1);

namespace Aicountly\Api\Domain;

use Aicountly\Api\Context;
use Aicountly\Api\Db;
use Aicountly\Api\Settings;
use Aicountly\Api\Support\Clock;

/**
 * What calls cost, and the difference between what we think and what we know.
 *
 * ## Estimated is not confirmed
 *
 * Voice can price a call the moment it ends: duration times the rate on the
 * card. That is an ESTIMATE. What the carrier eventually bills may differ —
 * their rounding, their minimums, their surcharges, a rate that changed. When
 * the provider's own usage arrives it is stored as `provider_confirmed` and
 * shown separately.
 *
 * The dashboard shows both, labelled, with a total for each. It never adds them
 * into one number, because an estimate plus a confirmation for the same minute
 * is that minute counted twice.
 *
 * ## Minor units, always
 *
 * Every amount is an integer of the currency's minor unit. The only place a
 * decimal appears is in formatting for display.
 *
 * ## Rate versions
 *
 * Each entry records the rate card version it was priced against, so restating
 * last month after a rate change is impossible by construction.
 */
final class UsageService
{
    public const ESTIMATED = 'estimated';
    public const CONFIRMED = 'provider_confirmed';

    /**
     * Price a completed call and record the estimate.
     *
     * Called when a call reaches a terminal state. If no rate is configured,
     * nothing is written — a usage line of zero would read as a free call
     * rather than as an unpriced one.
     */
    public static function recordCallEstimate(Context $ctx, int $callId): ?int
    {
        $call = Db::first(
            'SELECT call_id, connection_id, campaign_id, total_seconds, talk_seconds, direction, ended_at
               FROM voice_calls WHERE call_id = :id AND cmp_id = :cmp',
            ['id' => $callId, 'cmp' => $ctx->cmpId],
        );
        if ($call === null || $call['ended_at'] === null) {
            return null;
        }

        $rate = self::rateFor($ctx, (string) $call['direction']);
        if ($rate === null) {
            return null;
        }

        // Billed on connected time, rounded up to the whole minute — the
        // ordinary carrier convention, and stated here rather than assumed.
        $seconds = max(0, (int) $call['talk_seconds']);
        $minutes = (int) ceil($seconds / 60);
        if ($minutes === 0) {
            return null;
        }

        $currency = (string) Settings::forCompany($ctx->cmpId)['currency'];

        return (int) Db::insert('voice_usage_entries', [
            'cmp_id'        => $ctx->cmpId,
            'bo_id'         => $ctx->boId,
            'call_id'       => $callId,
            'connection_id' => $call['connection_id'] === null ? null : (int) $call['connection_id'],
            'campaign_id'   => $call['campaign_id'] === null ? null : (int) $call['campaign_id'],
            'category'      => 'voice_minutes',
            'quantity'      => $minutes,
            'unit'          => 'minute',
            'rate_version'  => $rate['version'],
            'rate_minor'    => $rate['minor'],
            'amount_minor'  => $minutes * $rate['minor'],
            'currency'      => $currency,
            'basis'         => self::ESTIMATED,
            'occurred_at'   => (string) $call['ended_at'],
        ], 'usage_id');
    }

    /**
     * Usage for a period, split by category and by basis.
     *
     * @return array<string, mixed>
     */
    public static function breakdown(Context $ctx, string $startIso, string $endIso): array
    {
        [$scope, $params] = $ctx->scopeClause();
        $params['start'] = $startIso;
        $params['end'] = $endIso;

        $rows = Db::all(
            'SELECT category, basis,
                    SUM(amount_minor)::bigint AS amount_minor,
                    SUM(quantity)::numeric    AS quantity,
                    MIN(currency)             AS currency
               FROM voice_usage_entries
              WHERE ' . $scope . ' AND occurred_at >= :start AND occurred_at < :end
              GROUP BY category, basis
              ORDER BY category',
            $params,
        );

        $categories = [];
        $estimated = 0;
        $confirmed = 0;
        $currency = (string) Settings::forCompany($ctx->cmpId)['currency'];

        foreach ($rows as $row) {
            $category = (string) $row['category'];
            $amount = (int) $row['amount_minor'];
            $basis = (string) $row['basis'];

            $categories[$category] ??= [
                'category' => $category,
                'estimated_minor' => 0,
                'confirmed_minor' => 0,
                'quantity' => 0.0,
            ];
            $categories[$category][$basis === self::CONFIRMED ? 'confirmed_minor' : 'estimated_minor'] += $amount;
            $categories[$category]['quantity'] += (float) $row['quantity'];

            if ($basis === self::CONFIRMED) {
                $confirmed += $amount;
            } else {
                $estimated += $amount;
            }
            $currency = (string) ($row['currency'] ?? $currency);
        }

        return [
            'period'    => ['start' => $startIso, 'end' => $endIso],
            'currency'  => $currency,
            'categories' => array_values($categories),
            // Two totals, never summed into one. See the class comment.
            'totals'    => [
                'estimated_minor' => $estimated,
                'confirmed_minor' => $confirmed,
            ],
            'note' => $confirmed > 0 && $estimated > 0
                ? 'Estimated and provider-confirmed charges are shown separately. They are not added together — some minutes appear in both until the provider confirms them.'
                : null,
        ];
    }

    /**
     * Store usage the provider has confirmed.
     *
     * The unique index on (connection_id, provider_ref) means re-importing a
     * statement does not double the bill.
     *
     * @param list<array<string, mixed>> $entries
     * @return int rows inserted
     */
    public static function importConfirmed(Context $ctx, int $connectionId, array $entries): int
    {
        $inserted = 0;

        foreach ($entries as $entry) {
            $providerRef = trim((string) ($entry['provider_ref'] ?? $entry['id'] ?? ''));
            if ($providerRef === '') {
                continue;
            }

            $id = Db::scalar(
                'INSERT INTO voice_usage_entries
                    (cmp_id, connection_id, category, quantity, unit, amount_minor, currency, basis, provider_ref, occurred_at)
                 VALUES (:cmp, :conn, :cat, :qty, :unit, :amount, :cur, :basis, :ref, :at)
                 ON CONFLICT (connection_id, provider_ref) WHERE provider_ref IS NOT NULL DO NOTHING
                 RETURNING usage_id',
                [
                    'cmp'    => $ctx->cmpId,
                    'conn'   => $connectionId,
                    'cat'    => (string) ($entry['category'] ?? 'voice_minutes'),
                    'qty'    => (float) ($entry['quantity'] ?? 0),
                    'unit'   => (string) ($entry['unit'] ?? 'minute'),
                    'amount' => (int) ($entry['amount_minor'] ?? 0),
                    'cur'    => (string) ($entry['currency'] ?? 'INR'),
                    'basis'  => self::CONFIRMED,
                    'ref'    => $providerRef,
                    'at'     => (string) ($entry['occurred_at'] ?? Clock::iso(Clock::now())),
                ],
            );

            if ($id !== null) {
                $inserted++;
            }
        }

        return $inserted;
    }

    /**
     * The configured rate, or null.
     *
     * Rates are deployment configuration rather than a hardcoded table, because
     * a hardcoded rate is a number that is wrong for every customer but one.
     *
     * @return array{minor: int, version: string}|null
     */
    private static function rateFor(Context $ctx, string $direction): ?array
    {
        $key = $direction === 'inbound' ? 'RATE_INBOUND_MINOR_PER_MINUTE' : 'RATE_OUTBOUND_MINOR_PER_MINUTE';
        $raw = \Aicountly\Api\Env::get($key);
        if ($raw === '' || !ctype_digit($raw)) {
            return null;
        }

        return [
            'minor'   => (int) $raw,
            'version' => \Aicountly\Api\Env::get('RATE_CARD_VERSION', 'unversioned'),
        ];
    }
}
