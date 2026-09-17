<?php

declare(strict_types=1);

namespace Aicountly\Api;

use Aicountly\Api\Controllers\AiAgentsController;
use Aicountly\Api\Controllers\CallbacksController;
use Aicountly\Api\Controllers\CallFlowsController;
use Aicountly\Api\Controllers\CallsController;
use Aicountly\Api\Controllers\CampaignsController;
use Aicountly\Api\Controllers\ContactsController;
use Aicountly\Api\Controllers\DashboardsController;
use Aicountly\Api\Controllers\EventsController;
use Aicountly\Api\Controllers\IntelligenceController;
use Aicountly\Api\Controllers\NetworkController;
use Aicountly\Api\Controllers\WebhooksController;
use Aicountly\Api\Controllers\WorkspaceController;

/**
 * The route table.
 *
 * Everything under /v1 is company-scoped and needs a session; the three
 * exceptions are marked below and are the only ones.
 *
 * Reading this file should tell you the whole API surface, which is why it is
 * one flat list rather than spread across the controllers.
 */
final class Routes
{
    public static function register(Router $router): void
    {
        // -------------------------------------------------------------------
        // Unauthenticated
        // -------------------------------------------------------------------
        // Liveness. Says which environment answered and what is configured —
        // never a credential and never a tenant's data.
        $router->get('/health', [Health::class, 'show']);

        // Telephony callbacks. NO Aicountly identity; authenticated by the
        // provider's own signature against the connection named in the path.
        // See WebhooksController.
        $router->post('/webhooks/telephony/{connectionId}', [WebhooksController::class, 'telephony']);

        // -------------------------------------------------------------------
        // Dashboards
        // -------------------------------------------------------------------
        $router->get('/v1/dashboards', [DashboardsController::class, 'index']);
        $router->get('/v1/dashboards/{view}', [DashboardsController::class, 'show']);

        // -------------------------------------------------------------------
        // Calls
        // -------------------------------------------------------------------
        $router->get('/v1/calls', [CallsController::class, 'index']);
        $router->post('/v1/calls', [CallsController::class, 'create']);
        $router->get('/v1/calls/dispositions', [CallsController::class, 'dispositions']);
        $router->get('/v1/calls/{id}', [CallsController::class, 'show']);
        $router->post('/v1/calls/{id}/actions', [CallsController::class, 'actions']);
        $router->get('/v1/calls/{id}/transcript', [CallsController::class, 'transcript']);
        $router->post('/v1/calls/{id}/disposition', [CallsController::class, 'disposition']);
        $router->get('/v1/calls/{id}/summary', [IntelligenceController::class, 'summary']);
        $router->post('/v1/calls/{id}/summary', [IntelligenceController::class, 'editSummary']);
        $router->post('/v1/calls/{id}/review', [IntelligenceController::class, 'review']);

        // -------------------------------------------------------------------
        // Callbacks
        // -------------------------------------------------------------------
        $router->get('/v1/callbacks', [CallbacksController::class, 'index']);
        $router->post('/v1/callbacks', [CallbacksController::class, 'create']);
        $router->put('/v1/callbacks/{id}', [CallbacksController::class, 'update']);

        // -------------------------------------------------------------------
        // AI voice agents
        // -------------------------------------------------------------------
        $router->get('/v1/ai-agents', [AiAgentsController::class, 'index']);
        $router->post('/v1/ai-agents', [AiAgentsController::class, 'create']);
        $router->get('/v1/ai-agents/{id}', [AiAgentsController::class, 'show']);
        $router->post('/v1/ai-agents/{id}/versions', [AiAgentsController::class, 'saveVersion']);
        $router->post('/v1/ai-agents/{id}/tests', [AiAgentsController::class, 'test']);
        $router->post('/v1/ai-agents/{id}/publish', [AiAgentsController::class, 'publish']);
        $router->post('/v1/ai-agents/{id}/rollback', [AiAgentsController::class, 'rollback']);

        // -------------------------------------------------------------------
        // Call flows
        // -------------------------------------------------------------------
        $router->get('/v1/call-flows', [CallFlowsController::class, 'index']);
        $router->post('/v1/call-flows', [CallFlowsController::class, 'create']);
        $router->post('/v1/call-flows/validate', [CallFlowsController::class, 'validate']);
        $router->get('/v1/call-flows/{id}', [CallFlowsController::class, 'show']);
        $router->post('/v1/call-flows/{id}/versions', [CallFlowsController::class, 'save']);
        $router->post('/v1/call-flows/{id}/publish', [CallFlowsController::class, 'publish']);

        // -------------------------------------------------------------------
        // Campaigns
        // -------------------------------------------------------------------
        $router->get('/v1/campaigns', [CampaignsController::class, 'index']);
        $router->post('/v1/campaigns', [CampaignsController::class, 'create']);
        $router->get('/v1/campaigns/{id}', [CampaignsController::class, 'show']);
        $router->post('/v1/campaigns/{id}/audience', [CampaignsController::class, 'audience']);
        $router->post('/v1/campaigns/{id}/validate', [CampaignsController::class, 'validate']);
        $router->post('/v1/campaigns/{id}/actions', [CampaignsController::class, 'actions']);

        // -------------------------------------------------------------------
        // Conversation intelligence
        // -------------------------------------------------------------------
        $router->post('/v1/intelligence/search', [IntelligenceController::class, 'search']);
        $router->get('/v1/recordings', [IntelligenceController::class, 'recordings']);
        $router->get('/v1/recordings/{uuid}/playback', [IntelligenceController::class, 'playback']);
        $router->post('/v1/commitments/{id}/confirm', [IntelligenceController::class, 'confirmCommitment']);
        $router->post('/v1/commitments/{id}/reject', [IntelligenceController::class, 'rejectCommitment']);

        // -------------------------------------------------------------------
        // Network, usage and integrations
        // -------------------------------------------------------------------
        $router->get('/v1/network/health', [NetworkController::class, 'health']);
        $router->get('/v1/usage', [NetworkController::class, 'usage']);
        $router->get('/v1/integrations', [NetworkController::class, 'integrations']);
        $router->post('/v1/provider-connections', [NetworkController::class, 'saveConnection']);
        $router->post('/v1/provider-connections/{id}/test', [NetworkController::class, 'testConnection']);

        // -------------------------------------------------------------------
        // Contacts — a live relay to Aicountly Contacts, never a local store
        // -------------------------------------------------------------------
        $router->get('/v1/contacts', [ContactsController::class, 'search']);
        $router->post('/v1/contacts', [ContactsController::class, 'create']);
        $router->get('/v1/contacts/{ref}', [ContactsController::class, 'show']);

        // -------------------------------------------------------------------
        // Workspace
        // -------------------------------------------------------------------
        $router->get('/v1/numbers', [WorkspaceController::class, 'numbers']);
        $router->post('/v1/numbers', [WorkspaceController::class, 'saveNumber']);
        $router->get('/v1/queues', [WorkspaceController::class, 'queues']);
        $router->post('/v1/queues', [WorkspaceController::class, 'saveQueue']);
        $router->get('/v1/agents', [WorkspaceController::class, 'agents']);
        $router->post('/v1/agents', [WorkspaceController::class, 'saveAgent']);
        $router->post('/v1/agents/presence', [WorkspaceController::class, 'presence']);
        $router->get('/v1/voicemail', [WorkspaceController::class, 'voicemail']);
        $router->get('/v1/settings', [WorkspaceController::class, 'settings']);
        $router->post('/v1/settings', [WorkspaceController::class, 'saveSettings']);
        $router->get('/v1/suppressions', [WorkspaceController::class, 'suppressions']);
        $router->post('/v1/suppressions', [WorkspaceController::class, 'suppress']);
        $router->get('/v1/audit', [WorkspaceController::class, 'audit']);
        $router->get('/v1/access', [WorkspaceController::class, 'access']);

        // -------------------------------------------------------------------
        // Real-time
        // -------------------------------------------------------------------
        $router->get('/v1/events', [EventsController::class, 'stream']);
    }
}
