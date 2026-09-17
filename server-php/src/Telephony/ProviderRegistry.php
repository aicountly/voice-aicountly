<?php

declare(strict_types=1);

namespace Aicountly\Api\Telephony;

use Aicountly\Api\Context;
use Aicountly\Api\Crypto;
use Aicountly\Api\Db;
use Aicountly\Api\Support\Clock;

/**
 * Which adapter serves a given connection, and what this company can do.
 *
 * Two jobs:
 *
 *  1. Turn a voice_provider_connections row into a live ProviderAdapter,
 *     decrypting its credentials in this process and nowhere else.
 *  2. Answer "can this company place a call at all?" — which is a per-company
 *     database fact, not a deployment flag. A company with no active connection
 *     gets a NullAdapter and a sentence explaining what is missing.
 *
 * ## Adding a carrier
 *
 * One new class implementing ProviderAdapter, written against that carrier's
 * real documentation, plus a line in `build()`. Nothing else in the product
 * changes: the console reads capabilities, so a carrier without attended
 * transfer simply renders no attended-transfer control.
 *
 * `null` and `gateway` are the only keys this repository can honestly serve.
 * Exotel, Airtel IQ, Tata and the rest each need their own file and their own
 * credentials to test against, and inventing their endpoints here would ship a
 * product that fails on its first real call.
 */
final class ProviderRegistry
{
    /** @var array<int, ProviderAdapter> */
    private static array $memo = [];

    /**
     * The adapter for one connection row.
     *
     * @param array<string, mixed> $connection
     */
    public static function build(array $connection): ProviderAdapter
    {
        $connectionId = (int) ($connection['connection_id'] ?? 0);
        if ($connectionId > 0 && isset(self::$memo[$connectionId])) {
            return self::$memo[$connectionId];
        }

        $provider = strtolower((string) ($connection['provider'] ?? 'null'));

        if (!(bool) ($connection['is_active'] ?? false)) {
            return new NullAdapter('This connection is switched off.');
        }

        $adapter = match ($provider) {
            'gateway', 'sip_gateway' => self::gateway($connection),
            default => new NullAdapter(
                'No adapter is installed for "' . $provider . '". '
                . 'A carrier adapter has to be written against that carrier’s own API.',
            ),
        };

        if ($connectionId > 0) {
            self::$memo[$connectionId] = $adapter;
        }

        return $adapter;
    }

    /**
     * The connection this company places calls on, or a NullAdapter saying why
     * it cannot.
     *
     * Prefers the primary; falls back to the backup only where the adapter
     * reports `preconnect_failover`, because switching routes before a call
     * connects is an ordinary thing and switching an ESTABLISHED call between
     * carriers is not — that needs infrastructure this product does not
     * assume it has.
     */
    public static function forCompany(Context $ctx, ?int $connectionId = null): ProviderAdapter
    {
        $row = self::connectionRow($ctx, $connectionId);

        if ($row === null) {
            return new NullAdapter(
                'No telephony provider is connected for this company. '
                . 'Add one in Settings → Provider connections.',
            );
        }

        return self::build($row);
    }

    /**
     * The chosen connection row, or null.
     *
     * @return array<string, mixed>|null
     */
    public static function connectionRow(Context $ctx, ?int $connectionId = null): ?array
    {
        [$scope, $params] = $ctx->scopeClause();

        if ($connectionId !== null && $connectionId > 0) {
            $params['id'] = $connectionId;

            return Db::first(
                'SELECT * FROM voice_provider_connections
                  WHERE ' . $scope . ' AND connection_id = :id AND is_active = TRUE',
                $params,
            );
        }

        return Db::first(
            'SELECT * FROM voice_provider_connections
              WHERE ' . $scope . ' AND is_active = TRUE
              ORDER BY CASE WHEN role = :primary THEN 0 ELSE 1 END, connection_id
              LIMIT 1',
            $params + ['primary' => 'primary'],
        );
    }

    /**
     * Every connection for a company, with its live capabilities and health.
     *
     * Feeds the Network dashboard. Capability and health come from the adapter,
     * never from the stored columns alone — a stored capability map is what the
     * provider could do when somebody last saved the form.
     *
     * @return list<array<string, mixed>>
     */
    public static function inventory(Context $ctx, bool $probe = false): array
    {
        [$scope, $params] = $ctx->scopeClause();
        $rows = Db::all(
            'SELECT * FROM voice_provider_connections WHERE ' . $scope . ' ORDER BY
              CASE WHEN role = \'primary\' THEN 0 ELSE 1 END, connection_id',
            $params,
        );

        $out = [];
        foreach ($rows as $row) {
            $adapter = self::build($row);
            $entry = [
                'connection_id' => (int) $row['connection_id'],
                'provider'      => (string) $row['provider'],
                'adapter'       => $adapter->key(),
                'label'         => (string) $row['label'],
                'adapter_label' => $adapter->label(),
                'role'          => (string) $row['role'],
                'is_active'     => (bool) $row['is_active'],
                'max_concurrent' => (int) $row['max_concurrent'],
                'capabilities'  => $adapter->capabilities(),
                // What is configured, never any part of the value.
                'credentials'   => Crypto::describe($row['credentials_enc'] ?? null),
                'status'        => (string) $row['status'],
                'status_detail' => $row['status_detail'] ?? null,
                'status_checked_at' => $row['status_checked_at'] ?? null,
                'last_tested_at' => $row['last_tested_at'] ?? null,
            ];

            if ($probe) {
                $health = $adapter->healthCheck();
                $entry['status'] = $health['status'];
                $entry['status_detail'] = $health['detail'];
                $entry['latency_ms'] = $health['latency_ms'];
                $entry['status_checked_at'] = Clock::iso(Clock::now());
                self::recordHealth((int) $row['connection_id'], $health);
            }

            $out[] = $entry;
        }

        return $out;
    }

    /** Persist the last observed health, so a screen can say when it last worked. */
    public static function recordHealth(int $connectionId, array $health): void
    {
        try {
            Db::update('voice_provider_connections', [
                'status'            => (string) $health['status'],
                'status_detail'     => (string) $health['detail'],
                'status_checked_at' => Clock::sql(Clock::now()),
            ], ['connection_id' => $connectionId]);
        } catch (\Throwable $e) {
            error_log('[telephony] could not record health: ' . $e->getMessage());
        }
    }

    /** @param array<string, mixed> $connection */
    private static function gateway(array $connection): ProviderAdapter
    {
        $credentials = Crypto::openCredentials($connection['credentials_enc'] ?? null);

        return new GatewayAdapter(
            (int) ($connection['connection_id'] ?? 0),
            Db::jsonColumn($connection['config'] ?? null),
            (string) ($credentials['signing_secret'] ?? ''),
        );
    }

    /** CLI only, so one test's adapter does not serve the next. */
    public static function resetForTesting(): void
    {
        if (PHP_SAPI === 'cli') {
            self::$memo = [];
        }
    }
}
