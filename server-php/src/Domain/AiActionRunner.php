<?php

declare(strict_types=1);

namespace Aicountly\Api\Domain;

use Aicountly\Api\Ai\AiClient;
use Aicountly\Api\Audit;
use Aicountly\Api\Auth;
use Aicountly\Api\Context;
use Aicountly\Api\Db;
use Aicountly\Api\Idempotency;

/**
 * Runs one `api_action` step of an AI agent's call, for the Voice Gateway.
 *
 * The Gateway holds the conversation; it reaches an action step and asks Voice
 * to carry it out. Voice answers with what happened and, in `say`, the exact
 * sentence the agent may speak about it. The Gateway speaks that sentence and
 * nothing stronger: "you're booked" exists only in an answer whose
 * `confirmed` is true, which only Appointments' own answer can produce.
 *
 * Checked here, server-side, every time:
 *   - the call belongs to this company, is still live and is pinned to an AI
 *     agent version — whose stored permissions decide, not the request;
 *   - a consequential action has the caller's confirmation;
 *   - something in this deployment carries the action out (AiActions). When
 *     nothing does, the caller is handed to a person: a callback is created
 *     for the team FIRST, and only then does the agent say it will pass the
 *     request on.
 *
 * A repeated tool call (same `tool_call_id`) is the same intent: a booking is
 * continued from its operation, a hand-off returns the callback it already
 * made.
 */
final class AiActionRunner
{
    private const TOOL_CALL_ID = '/^[A-Za-z0-9._-]{1,100}$/';

    /**
     * @param array<string, mixed> $input action, tool_call_id, arguments, caller_confirmed
     * @return array{status: int, data?: array<string, mixed>, error?: array{code: string, message: string, say: ?string}}
     */
    public static function run(Context $ctx, Auth $auth, int $callId, array $input): array
    {
        $action = trim((string) ($input['action'] ?? ''));
        $toolCallId = trim((string) ($input['tool_call_id'] ?? ''));
        $args = is_array($input['arguments'] ?? null) ? $input['arguments'] : [];

        if (!AiActions::isGated($action)) {
            return self::refusal(422, 'unknown_action', '"' . $action . '" is not an action Voice runs. Call control and answers are the Gateway\'s own.');
        }
        if (preg_match(self::TOOL_CALL_ID, $toolCallId) !== 1) {
            return self::refusal(422, 'tool_call_id_required', 'tool_call_id is required (letters, digits, dot, dash, underscore; at most 100), and must be the same on every retry of one action.');
        }

        [$scope, $params] = $ctx->scopeClause();
        $params['id'] = $callId;
        $call = Db::first('SELECT * FROM voice_calls WHERE ' . $scope . ' AND call_id = :id', $params);
        if ($call === null) {
            return self::refusal(404, 'not_found', 'That call is not in this company.');
        }
        if ($call['ended_at'] !== null) {
            return self::refusal(409, 'call_ended', 'That call has ended.');
        }
        if ($call['ai_version_id'] === null) {
            return self::refusal(409, 'not_an_ai_call', 'That call is not pinned to an AI agent version, so no agent may act on it.');
        }

        $version = Db::first(
            'SELECT action_permissions FROM voice_ai_agent_versions WHERE version_id = :id AND cmp_id = :cmp',
            ['id' => (int) $call['ai_version_id'], 'cmp' => $ctx->cmpId],
        );
        $mode = AiClient::resolveActionMode($action, $version === null ? [] : array_map('strval', Db::jsonColumn($version['action_permissions'] ?? null)));

        if ($mode === 'denied') {
            return self::refusal(403, 'not_permitted', 'The agent version on this call is not permitted to "' . $action . '".', AiActions::handoffSay($action, false));
        }
        if ($mode === 'confirm_with_caller' && ($input['caller_confirmed'] ?? false) !== true) {
            return self::refusal(409, 'confirmation_required', 'The caller has to confirm before "' . $action . '" runs. Send caller_confirmed: true once they have.');
        }

        $unavailable = AiActions::unavailableReason($action);
        if ($mode === 'handoff' || $unavailable !== null) {
            $result = [
                'outcome' => 'handoff',
                'confirmed' => false,
                'say' => null,
                'handoff' => AiActions::GATED[$action]['cannot'],
                'detail' => $unavailable ?? 'This agent hands "' . $action . '" to a person.',
            ];
        } else {
            $booking = new AppointmentsBooking($ctx);
            $result = $action === 'check_availability'
                ? $booking->availability($args)
                : self::book($booking, $call, $toolCallId, $args, $auth);
        }

        $callback = null;
        if (($result['handoff'] ?? null) !== null) {
            $callback = self::handoff($ctx, $auth, $call, $action, $toolCallId, $args, (string) ($result['detail'] ?? ''));
            $result['say'] = AiActions::finishHandoff((string) $result['handoff'], $callback !== null);
        }

        if (($result['outcome'] ?? '') === 'invalid') {
            return self::refusal(422, (string) ($result['code'] ?? 'invalid_arguments'), (string) ($result['detail'] ?? 'The action\'s arguments are incomplete.'));
        }

        Audit::record($ctx, $auth, Audit::AI_ACTION, 'call', (string) $callId, [
            'action'       => $action,
            'outcome'      => $result['outcome'],
            'confirmed'    => (bool) $result['confirmed'],
            'operation_id' => $result['operation']['operation_id'] ?? null,
            'callback_id'  => $callback['callback_id'] ?? null,
        ]);

        return ['status' => 200, 'data' => [
            'action'       => $action,
            'tool_call_id' => $toolCallId,
            'outcome'      => (string) $result['outcome'],
            // True only with the owning product's answer in hand.
            'confirmed'    => (bool) $result['confirmed'],
            // The sentence the agent may speak. Nothing stronger.
            'say'          => $result['say'],
            'booking'      => $result['booking'] ?? null,
            'slots'        => $result['slots'] ?? [],
            'alternatives' => $result['alternatives'] ?? [],
            'callback'     => $callback,
            'operation'    => $result['operation'] ?? null,
            // For the people running the deployment; never spoken.
            'detail'       => $result['detail'] ?? null,
        ]];
    }

    /**
     * @param array<string, mixed> $call
     * @param array<string, mixed> $args
     * @return array<string, mixed>
     */
    private static function book(AppointmentsBooking $booking, array $call, string $toolCallId, array $args, Auth $auth): array
    {
        $phone = CallingPolicy::normalise((string) ($call['remote_e164'] ?? ''))
            ?? CallingPolicy::normalise((string) ($args['client_phone'] ?? ''));
        $startsAt = self::text($args['starts_at'] ?? null, 40);
        $endsAt = self::text($args['ends_at'] ?? null, 40);
        $timezone = self::text($args['timezone'] ?? null, 64);
        $mode = self::text($args['mode'] ?? null, 40);

        $missing = [];
        foreach (['service_uuid', 'member_uuid', 'starts_at'] as $required) {
            if (self::text($args[$required] ?? null, 64) === null) {
                $missing[] = $required;
            }
        }
        if ($missing !== []) {
            // member_uuid too: the practitioner from the offered slot, so a
            // resend can never land with somebody else.
            return ['outcome' => 'invalid', 'code' => 'invalid_arguments',
                'detail' => 'create_booking needs ' . implode(', ', $missing) . ' (take them from a check_availability slot).'];
        }
        foreach (['starts_at' => $startsAt, 'ends_at' => $endsAt] as $field => $value) {
            if ($value !== null && preg_match('/(Z|[+-]\d{2}:?\d{2})$/i', $value) !== 1) {
                return ['outcome' => 'invalid', 'code' => 'datetime_offset_required',
                    'detail' => $field . ' must carry its timezone offset (or Z): a spoken "ten thirty" is only a time once its zone is known.'];
            }
        }
        if ($timezone !== null && !in_array($timezone, \DateTimeZone::listIdentifiers(), true)) {
            return ['outcome' => 'invalid', 'code' => 'invalid_timezone', 'detail' => 'timezone must be an IANA zone, for example Asia/Kolkata.'];
        }
        if ($phone === null) {
            return [
                'outcome' => 'unavailable', 'confirmed' => false, 'say' => null,
                'handoff' => "I can't book that from this call without a number to reach you on",
                'detail'  => 'The call has no caller number and none was given, so the booking would have no way to reach the client.',
            ];
        }

        return $booking->book([
            'correlation_id' => 'voice:ai:' . (int) $call['call_id'] . ':' . $toolCallId,
            'call_id'        => (int) $call['call_id'],
            'actor'          => $auth->uuid,
            'request'        => [
                'service_uuid'   => self::text($args['service_uuid'], 64),
                'member_uuid'    => self::text($args['member_uuid'], 64),
                'starts_at'      => $startsAt,
                'ends_at'        => $endsAt,
                'timezone'       => $timezone,
                'mode'           => $mode === null ? null : strtoupper($mode),
                'client_name'    => self::text($args['client_name'] ?? null, 200),
                'client_email'   => self::text($args['client_email'] ?? null, 200),
                'client_phone'   => $phone,
                'contact_uuid'   => self::text($call['contact_ref'] ?? null, 64),
                'internal_notes' => 'Booked by a Voice AI agent on call ' . (string) $call['call_uuid'] . '.',
            ],
        ]);
    }

    /**
     * A callback for the team, made once per tool call.
     *
     * @param array<string, mixed> $call
     * @param array<string, mixed> $args
     * @return array{callback_id: int, status: string}|null null when none could be made
     */
    private static function handoff(Context $ctx, Auth $auth, array $call, string $action, string $toolCallId, array $args, string $detail): ?array
    {
        $scope = 'ai-action.handoff:' . (int) $call['call_id'];
        $key = 'handoff-' . $toolCallId;
        $claim = Idempotency::claim($ctx, $scope, $key);
        if ($claim['status'] === 'replay') {
            $stored = $claim['replay']['body'] ?? [];

            return isset($stored['callback_id']) ? ['callback_id' => (int) $stored['callback_id'], 'status' => (string) ($stored['status'] ?? 'open')] : null;
        }
        if ($claim['status'] === 'in_progress') {
            // The same hand-off is being made by a retry right now; promise
            // nothing this answer cannot point at.
            return null;
        }

        $phone = CallingPolicy::normalise((string) ($call['remote_e164'] ?? ''))
            ?? CallingPolicy::normalise((string) ($args['client_phone'] ?? ''));
        $asked = self::text($args['starts_at'] ?? null, 40);
        $created = $phone === null ? null : CallbackService::create($ctx, $auth, [
            'e164'           => $phone,
            'source_call_id' => (int) $call['call_id'],
            'contact_ref'    => $call['contact_ref'] ?? null,
            'queue_id'       => $call['queue_id'] === null ? null : (int) $call['queue_id'],
            'priority'       => 'high',
            'reason'         => mb_substr('AI agent hand-off: the caller asked to "' . (AiClient::TOOLS[$action]['label'] ?? $action) . '"'
                . ($asked !== null ? ' for ' . $asked : '') . '. ' . $detail, 0, 1000),
        ]);

        if ($created === null || !$created['ok'] || !is_array($created['callback'])) {
            Idempotency::release($ctx, $scope, $key);

            return null;
        }

        $made = ['callback_id' => (int) $created['callback']['callback_id'], 'status' => (string) $created['callback']['status']];
        Idempotency::remember($ctx, $scope, $key, 201, $made);

        return $made;
    }

    /** @return array{status: int, error: array{code: string, message: string, say: ?string}} */
    private static function refusal(int $status, string $code, string $message, ?string $say = null): array
    {
        return ['status' => $status, 'error' => ['code' => $code, 'message' => $message, 'say' => $say]];
    }

    private static function text(mixed $value, int $max): ?string
    {
        if (!is_scalar($value)) {
            return null;
        }
        $value = trim((string) $value);

        return $value === '' ? null : mb_substr($value, 0, $max);
    }
}
