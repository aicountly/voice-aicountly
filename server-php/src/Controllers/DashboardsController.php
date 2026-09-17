<?php

declare(strict_types=1);

namespace Aicountly\Api\Controllers;

use Aicountly\Api\Dashboards\CampaignsDashboard;
use Aicountly\Api\Dashboards\CommandCentreDashboard;
use Aicountly\Api\Dashboards\IntelligenceDashboard;
use Aicountly\Api\Dashboards\LiveOperationsDashboard;
use Aicountly\Api\Dashboards\NetworkDashboard;
use Aicountly\Api\Dashboards\Period;
use Aicountly\Api\Dashboards\StudioDashboard;
use Aicountly\Api\Http;

/**
 * The six dashboards, one endpoint each.
 *
 * Each answers in a single request with its own metrics, panels and
 * definitions, so a screen paints once rather than in fourteen stages.
 *
 * Some views carry their own permission beyond `dashboard.view`: Network
 * exposes provider configuration and spending, and Studio exposes AI agent
 * configuration. Those are different audiences from an agent looking at a
 * queue.
 */
final class DashboardsController extends Controller
{
    private const VIEWS = [
        'command-centre' => [CommandCentreDashboard::class, 'voice.dashboard.view'],
        'live'           => [LiveOperationsDashboard::class, 'voice.dashboard.view'],
        'studio'         => [StudioDashboard::class,         'voice.ai.view'],
        'campaigns'      => [CampaignsDashboard::class,      'voice.campaigns.view'],
        'intelligence'   => [IntelligenceDashboard::class,   'voice.dashboard.view'],
        'network'        => [NetworkDashboard::class,        'voice.dashboard.view'],
    ];

    public static function show(string $view): never
    {
        $definition = self::VIEWS[$view] ?? null;
        if ($definition === null) {
            Http::notFound('There is no dashboard called "' . $view . '".');
        }

        [$class, $permission] = $definition;
        [$auth, $ctx] = self::enter($permission);

        $period = Period::fromRequest();
        /** @var \Aicountly\Api\Dashboards\Dashboard $dashboard */
        $dashboard = new $class($ctx, $auth, $period);

        Http::data($dashboard->build());
    }

    /** Which dashboards this user may open — what the navigation is built from. */
    public static function index(): never
    {
        [$auth, $ctx] = self::enter('voice.dashboard.view');

        $out = [];
        foreach (self::VIEWS as $key => [$class, $permission]) {
            $out[] = [
                'view'    => $key,
                'allowed' => \Aicountly\Api\Permissions::allows($ctx, $auth, $permission),
                'permission' => $permission,
            ];
        }

        Http::data($out);
    }
}
