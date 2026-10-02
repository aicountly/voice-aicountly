<?php

declare(strict_types=1);

namespace Aicountly\Api\Domain;

use Aicountly\Api\Ai\AiClient;
use Aicountly\Api\Features;

/**
 * Which `api_action` steps Voice can actually carry out, here, now.
 *
 * An AI agent may only tell a caller something was done when the product that
 * owns it answered that it was done. A step naming an action nothing executes
 * is a step that ends with the agent claiming a booking, a cancellation or a
 * payment link that does not exist — so such a step is refused when the flow
 * is published (FlowValidator), and if a call reaches it anyway the agent hands
 * the caller to a person instead (AiActionRunner).
 *
 * Only actions that change something in, or read from, another product are
 * listed. Call control (transfer, end call), knowledge answers and contact
 * look-ups are the Gateway's and are not gated here.
 */
final class AiActions
{
    /**
     * `executor`: the product that runs the action for Voice, or null when no
     * supported path exists. `cannot`: how the agent says it cannot, on a call.
     *
     * @var array<string, array{executor: ?string, cannot: string, why?: string}>
     */
    public const GATED = [
        'check_availability' => [
            'executor' => 'appointments',
            'cannot'   => "I can't check availability from this call",
        ],
        'create_booking' => [
            'executor' => 'appointments',
            'cannot'   => "I can't book that from this call",
        ],
        // Appointments moves and cancels a booking for a partner backend only
        // with staff authority (v1, service key) — the service's own client
        // rules do not apply and nothing proves the caller is the client — and
        // its public routes have no management credential or reschedule yet
        // (contract §14). Until one exists, these are a person's job.
        'reschedule_booking' => [
            'executor' => null,
            'cannot'   => "I can't move that booking from this call",
            'why'      => 'Aicountly Appointments has no way yet for Voice to move a booking on a caller’s behalf under the booking’s own client rules, so a caller would be told it was moved when nothing moved it.',
        ],
        'cancel_booking' => [
            'executor' => null,
            'cannot'   => "I can't cancel that booking from this call",
            'why'      => 'Aicountly Appointments has no way yet for Voice to cancel a booking on a caller’s behalf under the booking’s own client rules, so a caller would be told it was cancelled when nothing cancelled it.',
        ],
        'create_payment_link' => [
            'executor' => null,
            'cannot'   => "I can't send a payment link from this call",
            'why'      => 'Voice has no executor for it, so a caller would be told a link was sent when nothing sent one.',
        ],
        'create_task' => [
            'executor' => null,
            'cannot'   => "I can't arrange that from this call",
            'why'      => 'Voice has no executor for it, so a caller would be told a task was made when nothing made one.',
        ],
    ];

    /** The steps that change something in another product. */
    public const WRITES = ['create_booking', 'reschedule_booking', 'cancel_booking', 'create_payment_link', 'create_task'];

    public static function isGated(string $action): bool
    {
        return isset(self::GATED[$action]);
    }

    /**
     * Why this action cannot run in this deployment right now, in words an
     * administrator can act on — or null when it can.
     */
    public static function unavailableReason(string $action): ?string
    {
        $entry = self::GATED[$action] ?? null;
        if ($entry === null) {
            return null;
        }

        $label = '"' . (AiClient::TOOLS[$action]['label'] ?? $action) . '"';

        if ($entry['executor'] === null) {
            return $label . ' is not available in this deployment: ' . ($entry['why'] ?? 'nothing in Voice can carry it out.')
                . ' Remove this step and hand the caller to a person.';
        }

        if ($entry['executor'] === 'appointments' && !Features::enabled('APPOINTMENTS')) {
            return $label . ' is not available in this deployment: Voice books through Aicountly Appointments, which is not connected here. '
                . (Features::explain('APPOINTMENTS') ?? '');
        }

        return null;
    }

    /**
     * What the agent says when it cannot do this. "I'll pass your request to
     * the team" is said only when a callback for the team actually exists.
     */
    public static function handoffSay(string $action, bool $callbackArranged): string
    {
        return self::finishHandoff(self::GATED[$action]['cannot'] ?? "I can't do that from this call", $callbackArranged);
    }

    /** The end of a hand-off sentence, true to whether the callback exists. */
    public static function finishHandoff(string $lead, bool $callbackArranged): string
    {
        return $callbackArranged
            ? $lead . "; I'll pass your request to the team, and someone will call you back."
            : $lead . ", and I couldn't arrange a call back just now. Please ask for a person, or call us again.";
    }

    /**
     * Every gated action and whether it can run here, for the screens.
     *
     * @return array<string, array{label: string, available: bool, reason: ?string}>
     */
    public static function describe(): array
    {
        $out = [];
        foreach (array_keys(self::GATED) as $action) {
            $reason = self::unavailableReason($action);
            $out[$action] = [
                'label'     => (string) (AiClient::TOOLS[$action]['label'] ?? $action),
                'available' => $reason === null,
                'reason'    => $reason,
            ];
        }

        return $out;
    }
}
