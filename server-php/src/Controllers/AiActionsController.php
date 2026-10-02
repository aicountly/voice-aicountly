<?php

declare(strict_types=1);

namespace Aicountly\Api\Controllers;

use Aicountly\Api\Domain\AiActionRunner;
use Aicountly\Api\Http;

/**
 * An AI agent's action steps, run for the Voice Gateway.
 *
 * Only the Gateway may call this — with its own key, registered here as
 * `gateway:<key>` in SERVICE_KEYS — because only the Gateway is holding the
 * conversation the action belongs to. A browser session, or any other
 * product's key, is refused: no person and no other product makes an AI agent
 * act on a call. See Domain\AiActionRunner for what is checked and answered.
 */
final class AiActionsController extends Controller
{
    /** POST /v1/calls/{id}/ai-actions */
    public static function run(string $id): never
    {
        [$auth, $ctx] = self::enter();
        if (!$auth->isService() || $auth->sourceApp !== 'gateway') {
            Http::forbidden('Only the Aicountly Voice Gateway runs an AI agent’s actions.');
        }

        $result = AiActionRunner::run($ctx, $auth, self::id($id), Http::body());

        if (isset($result['error'])) {
            Http::error($result['status'], $result['error']['code'], $result['error']['message'], [
                // What the agent may say when it cannot go on, so a refusal
                // never leaves a caller with silence or a guess.
                'say' => $result['error']['say'],
            ]);
        }

        Http::data($result['data'] ?? []);
    }
}
