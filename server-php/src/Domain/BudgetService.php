<?php

declare(strict_types=1);

namespace Aicountly\Api\Domain;

use Aicountly\Api\Context;
use Aicountly\Api\Db;
use Aicountly\Api\Settings;

/**
 * Spending and capacity limits, enforced where they cannot be bypassed.
 *
 * THE BROWSER SHOWS THE BAR; THIS REFUSES THE CALL. A budget enforced in React
 * is a budget enforced until somebody opens the network tab. Every path that
 * places a call — the agent console, the campaign worker, an AI agent's dial
 * action — passes through `check()` here first.
 *
 * ## Money
 *
 * Integer minor units throughout (paise for INR). Floating point money summed
 * across ten thousand calls is money that stops agreeing with the invoice.
 *
 * ## Capacity
 *
 * Separate from budget and enforced separately. A company can be well within
 * its spend and still have no free channel on the trunk, and the two failures
 * need different words: "you have reached your spending limit" and "all lines
 * are busy" send an administrator to different screens.
 */
final class BudgetService
{
    /**
     * May one more call start?
     *
     * @return array{allowed: bool, reason: ?string, message: ?string, detail: array<string, mixed>}
     */
    public static function check(Context $ctx, ?int $campaignId = null): array
    {
        $capacity = self::capacityCheck($ctx);
        if (!$capacity['allowed']) {
            return $capacity;
        }

        foreach (self::policies($ctx, $campaignId) as $policy) {
            $spent = self::spent($ctx, $policy);
            $limit = (int) $policy['limit_minor'];

            if ($limit <= 0) {
                continue;
            }

            if ($spent >= $limit && $policy['on_exceed'] === 'block') {
                return [
                    'allowed' => false,
                    'reason'  => 'budget_exceeded',
                    'message' => sprintf(
                        'The %s %s budget has been reached (%s of %s).',
                        $policy['scope'],
                        $policy['period'],
                        self::money($spent, (string) $policy['currency']),
                        self::money($limit, (string) $policy['currency']),
                    ),
                    'detail'  => ['spent_minor' => $spent, 'limit_minor' => $limit],
                ];
            }
        }

        return ['allowed' => true, 'reason' => null, 'message' => null, 'detail' => []];
    }

    /**
     * Concurrency, from two ceilings: what the company configured and what the
     * provider connection can carry. The lower one wins, and the caller is told
     * which.
     *
     * @return array{allowed: bool, reason: ?string, message: ?string, detail: array<string, mixed>}
     */
    public static function capacityCheck(Context $ctx): array
    {
        $usage = self::concurrency($ctx);

        if ($usage['limit'] > 0 && $usage['active'] >= $usage['limit']) {
            return [
                'allowed' => false,
                'reason'  => 'capacity_reached',
                'message' => sprintf(
                    'All %d channels are in use (%s limit).',
                    $usage['limit'],
                    $usage['source'],
                ),
                'detail'  => $usage,
            ];
        }

        return ['allowed' => true, 'reason' => null, 'message' => null, 'detail' => $usage];
    }

    /**
     * Live concurrency and the ceiling that applies.
     *
     * @return array{active: int, limit: int, source: string, percent: float}
     */
    public static function concurrency(Context $ctx): array
    {
        [$scope, $params] = $ctx->scopeClause();

        $active = (int) (Db::scalar(
            'SELECT COUNT(*) FROM voice_calls
              WHERE ' . $scope . ' AND ended_at IS NULL
                AND state IN (\'initiated\', \'queued\', \'ringing\', \'answered\', \'held\', \'transferring\')',
            $params,
        ) ?? 0);

        $companyLimit = (int) Settings::forCompany($ctx->cmpId)['max_concurrent_calls'];

        $providerLimit = (int) (Db::scalar(
            'SELECT COALESCE(SUM(max_concurrent), 0) FROM voice_provider_connections
              WHERE ' . $scope . ' AND is_active = TRUE AND role = \'primary\'',
            $params,
        ) ?? 0);

        $limit = 0;
        $source = 'no';
        if ($companyLimit > 0 && $providerLimit > 0) {
            $limit = min($companyLimit, $providerLimit);
            $source = $limit === $companyLimit ? 'company' : 'provider';
        } elseif ($companyLimit > 0) {
            $limit = $companyLimit;
            $source = 'company';
        } elseif ($providerLimit > 0) {
            $limit = $providerLimit;
            $source = 'provider';
        }

        return [
            'active'  => $active,
            'limit'   => $limit,
            'source'  => $source,
            'percent' => $limit > 0 ? round(($active / $limit) * 100, 1) : 0.0,
        ];
    }

    /**
     * Budget state for the Network dashboard.
     *
     * @return list<array<string, mixed>>
     */
    public static function status(Context $ctx): array
    {
        $out = [];
        foreach (self::policies($ctx, null) as $policy) {
            $spent = self::spent($ctx, $policy);
            $limit = (int) $policy['limit_minor'];
            $percent = $limit > 0 ? round(($spent / $limit) * 100, 1) : 0.0;

            $out[] = [
                'policy_id'    => (int) $policy['policy_id'],
                'scope'        => (string) $policy['scope'],
                'period'       => (string) $policy['period'],
                'limit_minor'  => $limit,
                'spent_minor'  => $spent,
                'currency'     => (string) $policy['currency'],
                'percent'      => $percent,
                'warn_percent' => (int) $policy['warn_percent'],
                'on_exceed'    => (string) $policy['on_exceed'],
                'state'        => match (true) {
                    $limit <= 0                             => 'no_limit',
                    $percent >= 100                         => 'exceeded',
                    $percent >= (float) $policy['warn_percent'] => 'warning',
                    default                                 => 'ok',
                },
            ];
        }

        return $out;
    }

    /**
     * Spend so far in the policy's period.
     *
     * Estimated and provider-confirmed entries are summed together here because
     * a ceiling has to act on the best current figure — but the dashboard shows
     * them apart, so nobody mistakes an estimate for a bill.
     *
     * @param array<string, mixed> $policy
     */
    private static function spent(Context $ctx, array $policy): int
    {
        $since = $policy['period'] === 'daily' ? "date_trunc('day', NOW())" : "date_trunc('month', NOW())";

        $params = ['cmp' => $ctx->cmpId];
        $extra = '';
        if ($policy['scope'] === 'campaign' && !empty($policy['scope_ref'])) {
            $extra = ' AND campaign_id = :campaign';
            $params['campaign'] = (int) $policy['scope_ref'];
        }

        return (int) (Db::scalar(
            'SELECT COALESCE(SUM(amount_minor), 0) FROM voice_usage_entries
              WHERE cmp_id = :cmp AND occurred_at >= ' . $since . $extra,
            $params,
        ) ?? 0);
    }

    /** @return list<array<string, mixed>> */
    private static function policies(Context $ctx, ?int $campaignId): array
    {
        $rows = Db::all(
            'SELECT * FROM voice_budget_policies
              WHERE cmp_id = :cmp AND is_active = TRUE
                AND (scope = \'company\' OR (scope = \'campaign\' AND scope_ref = :ref))',
            ['cmp' => $ctx->cmpId, 'ref' => $campaignId === null ? '' : (string) $campaignId],
        );

        return $rows;
    }

    /** Minor units to a readable amount. Never used for arithmetic. */
    public static function money(int $minor, string $currency = 'INR'): string
    {
        $symbol = match (strtoupper($currency)) {
            'INR'   => '₹',
            'USD'   => '$',
            'EUR'   => '€',
            'GBP'   => '£',
            default => $currency . ' ',
        };

        return $symbol . number_format($minor / 100, 2);
    }
}
