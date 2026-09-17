<?php

declare(strict_types=1);

namespace Aicountly\Api\Controllers;

use Aicountly\Api\Audit;
use Aicountly\Api\Clients\CalendarClient;
use Aicountly\Api\Clients\ContactsClient;
use Aicountly\Api\Clients\CrmClient;
use Aicountly\Api\Clients\LobbyClient;
use Aicountly\Api\Clients\MessagingClient;
use Aicountly\Api\Clients\PayClient;
use Aicountly\Api\Crypto;
use Aicountly\Api\Db;
use Aicountly\Api\Domain\BudgetService;
use Aicountly\Api\Domain\UsageService;
use Aicountly\Api\Features;
use Aicountly\Api\Http;
use Aicountly\Api\Support\Clock;
use Aicountly\Api\Telephony\ProviderRegistry;

/** Provider health, usage, budgets and integration status. */
final class NetworkController extends Controller
{
    public static function health(): never
    {
        [, $ctx] = self::enter('voice.dashboard.view');

        Http::data([
            'connections' => ProviderRegistry::inventory($ctx, Http::param('probe') === '1'),
            'capacity'    => BudgetService::concurrency($ctx),
            'budget'      => BudgetService::status($ctx),
            'checked_at'  => Clock::iso(Clock::now()),
        ]);
    }

    public static function usage(): never
    {
        [, $ctx] = self::enter('voice.reports.view');

        $period = \Aicountly\Api\Dashboards\Period::fromRequest();

        Http::data(UsageService::breakdown(
            $ctx,
            Clock::iso($period->from),
            Clock::iso($period->to),
        ) + ['budget' => BudgetService::status($ctx)]);
    }

    /**
     * Test a provider connection for real.
     *
     * An administrator pressing Test wants to know whether it works NOW, not
     * what a stored column says. This calls the provider.
     */
    public static function testConnection(string $id): never
    {
        [$auth, $ctx] = self::enter('voice.providers.manage');
        $connectionId = self::id($id);

        $row = ProviderRegistry::connectionRow($ctx, $connectionId);
        if ($row === null) {
            Http::notFound('That connection is not in this company.');
        }

        $adapter = ProviderRegistry::build($row);
        $health = $adapter->healthCheck();

        Db::update('voice_provider_connections', [
            'status'           => (string) $health['status'],
            'status_detail'    => (string) $health['detail'],
            'status_checked_at' => Clock::sql(Clock::now()),
            'last_tested_at'   => Clock::sql(Clock::now()),
            'last_test_result' => $health,
        ], ['connection_id' => $connectionId, 'cmp_id' => $ctx->cmpId]);

        Audit::record($ctx, $auth, 'voice.provider.tested', 'connection', (string) $connectionId, [
            'status' => $health['status'],
        ]);

        Http::data($health + ['capabilities' => $adapter->capabilities()]);
    }

    /**
     * Save a provider connection.
     *
     * Credentials are encrypted before they touch the database and are NEVER
     * returned by any endpoint. With no encryption key configured the save is
     * refused rather than storing them in the clear.
     */
    public static function saveConnection(): never
    {
        [$auth, $ctx] = self::enter('voice.providers.manage');
        $body = Http::body();

        $provider = strtolower(trim((string) ($body['provider'] ?? '')));
        $label = trim((string) ($body['label'] ?? ''));
        if ($provider === '' || $label === '') {
            Http::validationFailed('A provider and a label are required.');
        }

        $credentials = is_array($body['credentials'] ?? null) ? $body['credentials'] : null;
        $sealed = null;
        if ($credentials !== null && $credentials !== []) {
            if (!Crypto::isConfigured()) {
                self::fail(
                    'storage_not_configured',
                    'Provider credentials cannot be stored until CREDENTIAL_ENCRYPTION_KEY is set on the server.',
                );
            }
            $sealed = Crypto::sealCredentials($credentials);
        }

        $connectionId = isset($body['connection_id']) ? (int) $body['connection_id'] : 0;

        $values = [
            'provider'   => $provider,
            'label'      => $label,
            'role'       => in_array($body['role'] ?? '', ['primary', 'backup'], true) ? (string) $body['role'] : 'primary',
            'config'     => is_array($body['config'] ?? null) ? $body['config'] : [],
            'max_concurrent' => max(0, (int) ($body['max_concurrent'] ?? 0)),
            'is_active'  => (bool) ($body['is_active'] ?? true),
            'updated_at' => Clock::sql(Clock::now()),
        ];
        if ($sealed !== null) {
            $values['credentials_enc'] = $sealed;
        }

        if ($connectionId > 0) {
            if (ProviderRegistry::connectionRow($ctx, $connectionId) === null) {
                Http::notFound('That connection is not in this company.');
            }
            Db::update('voice_provider_connections', $values, ['connection_id' => $connectionId, 'cmp_id' => $ctx->cmpId]);
        } else {
            $connectionId = (int) Db::insert('voice_provider_connections', $values + [
                'cmp_id'     => $ctx->cmpId,
                'bo_id'      => $ctx->boId,
                'created_by' => $auth->uuid,
            ], 'connection_id');
        }

        ProviderRegistry::resetForTesting();

        Audit::record($ctx, $auth, Audit::PROVIDER_CONFIGURED, 'connection', (string) $connectionId, [
            'provider' => $provider,
            'credentials_changed' => $sealed !== null,
        ]);

        $row = ProviderRegistry::connectionRow($ctx, $connectionId);
        $adapter = $row === null ? null : ProviderRegistry::build($row);

        Http::data([
            'connection_id' => $connectionId,
            // What is configured, never any part of a value.
            'credentials'   => Crypto::describe($sealed),
            'capabilities'  => $adapter?->capabilities() ?? [],
        ]);
    }

    /**
     * Integration status.
     *
     * With `probe=1` each enabled integration is asked whether it is alive,
     * server to server. Every state is explicit; nothing is reported connected
     * without evidence, and an app still under development says so.
     */
    public static function integrations(): never
    {
        [, $ctx] = self::enter('voice.dashboard.view');
        $probe = Http::param('probe') === '1';

        $stored = [];
        foreach (Db::all('SELECT * FROM voice_integrations WHERE cmp_id = :cmp', ['cmp' => $ctx->cmpId]) as $row) {
            $stored[(string) $row['app']] = $row;
        }

        $definitions = [
            'contacts'  => ['label' => 'Aicountly Contacts', 'client' => null, 'purpose' => 'Caller identification and the contact directory.'],
            'calendar'  => ['label' => 'Aicountly Calendar', 'client' => CalendarClient::class, 'purpose' => 'Availability and bookings made from a call.'],
            'crm'       => ['label' => 'Aicountly CRM',      'client' => CrmClient::class,      'purpose' => 'Leads and follow-up tasks from confirmed commitments.'],
            'pay'       => ['label' => 'Aicountly Pay',      'client' => PayClient::class,      'purpose' => 'Payment links sent during a call.'],
            'lobby'     => ['label' => 'Aicountly Lobby',    'client' => LobbyClient::class,    'purpose' => 'Reception desk coverage and visitor callbacks.'],
            'messaging' => ['label' => 'Aicountly Messaging', 'client' => MessagingClient::class, 'purpose' => 'Follow-up messages after a call.'],
        ];

        $out = [];
        foreach ($definitions as $app => $definition) {
            $flag = strtoupper($app);
            $enabled = Features::enabled($flag);
            $row = $stored[$app] ?? null;

            $entry = [
                'app'     => $app,
                'label'   => $definition['label'],
                'purpose' => $definition['purpose'],
                'status'  => $enabled ? ((string) ($row['status'] ?? 'configured')) : 'not_configured',
                'reason'  => $enabled ? ($row['status_detail'] ?? null) : Features::explain($flag),
                'checked_at' => $row['checked_at'] ?? null,
                'last_ok_at' => $row['last_ok_at'] ?? null,
                'can_test'   => $enabled && $definition['client'] !== null,
            ];

            if ($probe && $enabled && $definition['client'] !== null) {
                /** @var object $client */
                $client = new $definition['client']();
                $result = $client->health();
                $entry['status'] = $result['ok'] ? 'connected' : ($result['status'] === 0 ? 'unavailable' : 'degraded');
                $entry['reason'] = $result['ok'] ? null : ($result['error'] ?? 'Did not answer.');
                $entry['checked_at'] = Clock::iso(Clock::now());

                Db::run(
                    'INSERT INTO voice_integrations (cmp_id, app, enabled, status, status_detail, checked_at, last_ok_at)
                     VALUES (:cmp, :app, TRUE, :status, :detail, NOW(), CASE WHEN :ok THEN NOW() ELSE NULL END)
                     ON CONFLICT (cmp_id, app) DO UPDATE SET
                        status = EXCLUDED.status,
                        status_detail = EXCLUDED.status_detail,
                        checked_at = NOW(),
                        last_ok_at = CASE WHEN :ok THEN NOW() ELSE voice_integrations.last_ok_at END,
                        updated_at = NOW()',
                    [
                        'cmp' => $ctx->cmpId, 'app' => $app,
                        'status' => $entry['status'], 'detail' => $entry['reason'],
                        'ok' => $result['ok'] ? 'true' : 'false',
                    ],
                );
            }

            $out[] = $entry;
        }

        Http::data([
            'integrations' => $out,
            'note' => 'Every cross-product read and write goes through the owning product’s live API. Voice stores references, never copies of their records.',
        ]);
    }
}
