<?php

declare(strict_types=1);

namespace Aicountly\Api\Domain;

use Aicountly\Api\Audit;
use Aicountly\Api\Auth;
use Aicountly\Api\Context;
use Aicountly\Api\Db;
use Aicountly\Api\Settings;
use Aicountly\Api\Support\Clock;
use Aicountly\Api\Support\Uuid;
use Aicountly\Api\Telephony\CallRequest;
use Aicountly\Api\Telephony\CallResult;
use Aicountly\Api\Telephony\Capability;
use Aicountly\Api\Telephony\ProviderRegistry;

/**
 * Placing, controlling and ending calls.
 *
 * ## The order of the checks, and why it is that order
 *
 * Every outbound call passes the same gate, cheapest and most consequential
 * first:
 *
 *   1. Is the number dialable, and has this person asked not to be called?
 *      Policy first, because no amount of capacity makes it right to ring
 *      somebody who opted out.
 *   2. Is there budget and a free channel?
 *   3. Does the provider support placing calls at all?
 *
 * ## The row exists before the dial
 *
 * voice_calls is written BEFORE the adapter is asked to dial, inside a
 * transaction. If this process dies between the two, there is a call row in
 * 'initiated' with a correlation id — which the recovery worker can resolve —
 * rather than a call that rang somebody with no record anywhere that it
 * happened.
 *
 * ## An unknown outcome is not a failure
 *
 * If the provider does not confirm, the call stays in 'initiated' and the agent
 * is told the outcome is not yet known. It is not marked failed, because it may
 * be ringing, and a failed-looking call is a call somebody dials again.
 */
final class CallService
{
    /**
     * Place an outbound call.
     *
     * @param array<string, mixed> $input
     * @return array{ok: bool, code: ?string, message: ?string, call: ?array<string, mixed>, detail: array<string, mixed>}
     */
    public static function place(Context $ctx, Auth $auth, array $input): array
    {
        $to = CallingPolicy::normalise((string) ($input['to'] ?? ''));
        if ($to === null) {
            return self::fail('invalid_number', 'That is not a number this system can dial.');
        }

        $policy = CallingPolicy::check($ctx, $to);
        if (!$policy['allowed']) {
            return self::fail((string) $policy['reason'], (string) $policy['message']);
        }

        $budget = BudgetService::check($ctx, isset($input['campaign_id']) ? (int) $input['campaign_id'] : null);
        if (!$budget['allowed']) {
            return self::fail((string) $budget['reason'], (string) $budget['message'], $budget['detail']);
        }

        $connectionRow = ProviderRegistry::connectionRow($ctx, isset($input['connection_id']) ? (int) $input['connection_id'] : null);
        $adapter = $connectionRow === null
            ? ProviderRegistry::forCompany($ctx)
            : ProviderRegistry::build($connectionRow);

        if (!($adapter->capabilities()[Capability::PLACE_CALL] ?? false)) {
            return self::fail(
                'provider_not_configured',
                $adapter->key() === 'null'
                    ? 'No telephony provider is connected for this company.'
                    : 'This connection cannot place outbound calls.',
            );
        }

        $number = self::outboundNumber($ctx, $input, $connectionRow);
        if ($number === null) {
            return self::fail(
                'no_caller_id',
                'No business number is configured to call from. Add one in Numbers.',
            );
        }

        $settings = Settings::forCompany($ctx->cmpId);
        $record = self::shouldRecord($settings, $number);
        $callUuid = Uuid::v4();

        // Written before the dial. See the class comment.
        $callId = (int) Db::insert('voice_calls', [
            'call_uuid'      => $callUuid,
            'cmp_id'         => $ctx->cmpId,
            'bo_id'          => $ctx->boId,
            'connection_id'  => (int) $connectionRow['connection_id'],
            'number_id'      => (int) $number['number_id'],
            'direction'      => 'outbound',
            'origin'         => $auth->provenOrigin(),
            'remote_e164'    => $to,
            'local_e164'     => (string) $number['e164'],
            'contact_ref'    => self::stringOrNull($input['contact_ref'] ?? null),
            'crm_lead_ref'   => self::stringOrNull($input['crm_lead_ref'] ?? null),
            'campaign_id'    => isset($input['campaign_id']) ? (int) $input['campaign_id'] : null,
            'ai_agent_id'    => isset($input['ai_agent_id']) ? (int) $input['ai_agent_id'] : null,
            'ai_version_id'  => isset($input['ai_version_id']) ? (int) $input['ai_version_id'] : null,
            'owner_agent_id' => isset($input['agent_id']) ? (int) $input['agent_id'] : null,
            'handled_by'     => isset($input['ai_agent_id']) ? 'ai' : 'human',
            'state'          => 'initiated',
            'recording_state' => $record ? 'recording' : 'none',
            'consent_state'  => self::initialConsentState($settings, $record),
            'correlation_id' => $callUuid,
            'initiated_at'   => Clock::sql(Clock::now()),
        ], 'call_id');

        $result = $adapter->placeCall(new CallRequest(
            toE164: $to,
            fromE164: (string) $number['e164'],
            correlationId: $callUuid,
            agentEndpoint: (string) ($input['agent_endpoint'] ?? ''),
            record: $record,
            aiAgentRef: isset($input['ai_agent_id']) ? (string) $input['ai_agent_id'] : null,
            flowRef: isset($input['flow_id']) ? (string) $input['flow_id'] : null,
            metadata: ['cmp_id' => (string) $ctx->cmpId, 'call_uuid' => $callUuid],
        ));

        self::recordLeg($ctx, $callId, $result, $to);

        Audit::record($ctx, $auth, Audit::CALL_PLACED, 'call', (string) $callId, [
            'direction'  => 'outbound',
            'to'         => CallingPolicy::mask($to),
            'connection' => (int) $connectionRow['connection_id'],
            'recorded'   => $record,
            'outcome'    => $result->outcome,
        ]);

        if ($result->isUnknown()) {
            // Left in 'initiated' on purpose. A provider that did not answer may
            // still be ringing this number.
            Db::update('voice_calls', [
                'state_reason' => 'provider_unconfirmed',
                'state_stale_at' => Clock::sql(Clock::now()),
            ], ['call_id' => $callId]);

            return [
                'ok'      => false,
                'code'    => 'outcome_unknown',
                'message' => 'The provider did not confirm the call. Check the call list before dialling again.',
                'call'    => self::find($ctx, $callId),
                'detail'  => ['call_id' => $callId, 'retryable' => false],
            ];
        }

        if (!$result->isOk()) {
            CallStateMachine::transition($ctx->cmpId, $callId, 'failed', (string) $result->code);

            return [
                'ok'      => false,
                'code'    => (string) $result->code,
                'message' => (string) $result->message,
                'call'    => self::find($ctx, $callId),
                'detail'  => $result->detail,
            ];
        }

        return [
            'ok'      => true,
            'code'    => null,
            'message' => null,
            'call'    => self::find($ctx, $callId),
            'detail'  => ['provider_ref' => $result->providerRef],
        ];
    }

    /**
     * An in-call control: mute, hold, dtmf, transfer, record, monitor, hangup.
     *
     * Each maps to its OWN adapter method. Mute is not hold; a blind transfer is
     * not an attended one. The adapter refuses anything the provider cannot do,
     * by name, and this returns that refusal unchanged.
     *
     * @param array<string, mixed> $input
     * @return array{ok: bool, code: ?string, message: ?string, detail: array<string, mixed>}
     */
    public static function control(Context $ctx, Auth $auth, int $callId, string $action, array $input = []): array
    {
        $call = self::row($ctx, $callId);
        if ($call === null) {
            return ['ok' => false, 'code' => 'not_found', 'message' => 'That call is not in this company.', 'detail' => []];
        }

        if (CallStateMachine::isTerminal((string) $call['state'])) {
            return [
                'ok'      => false,
                'code'    => 'call_ended',
                'message' => 'That call has already ended.',
                'detail'  => ['state' => $call['state']],
            ];
        }

        $providerRef = self::providerRef($callId);
        if ($providerRef === null) {
            return [
                'ok'      => false,
                'code'    => 'no_provider_ref',
                'message' => 'This call has no provider reference yet, so it cannot be controlled.',
                'detail'  => [],
            ];
        }

        $adapter = ProviderRegistry::forCompany($ctx, isset($call['connection_id']) ? (int) $call['connection_id'] : null);

        $result = match ($action) {
            'hangup'   => $adapter->endCall($providerRef, 'agent_ended'),
            'mute'     => $adapter->setMute($providerRef, true),
            'unmute'   => $adapter->setMute($providerRef, false),
            'hold'     => $adapter->setHold($providerRef, true),
            'resume'   => $adapter->setHold($providerRef, false),
            'dtmf'     => $adapter->sendDtmf($providerRef, (string) ($input['digits'] ?? '')),
            'transfer' => $adapter->transfer(
                $providerRef,
                (string) ($input['destination'] ?? ''),
                (string) ($input['mode'] ?? 'blind'),
            ),
            'record_start' => $adapter->setRecording($providerRef, true),
            'record_stop'  => $adapter->setRecording($providerRef, false),
            'monitor'  => $adapter->monitor(
                $providerRef,
                (string) ($input['endpoint'] ?? ''),
                (string) ($input['mode'] ?? 'listen'),
            ),
            default    => CallResult::refused('unknown_action', 'That is not a call control.'),
        };

        if ($action === 'transfer' && $result->isOk()) {
            CallStateMachine::transition($ctx->cmpId, $callId, 'transferring', 'agent_transfer');
            Audit::record($ctx, $auth, Audit::CALL_TRANSFERRED, 'call', (string) $callId, [
                'mode' => (string) ($input['mode'] ?? 'blind'),
            ]);
        }

        if ($action === 'hangup' && $result->isOk()) {
            CallStateMachine::transition($ctx->cmpId, $callId, 'completed', 'agent_ended');
        }

        if ($action === 'monitor' && $result->isOk()) {
            // Listening to a colleague's call is recorded as a participation,
            // not just an action, so the call's own record shows who was on it.
            Db::insert('voice_call_participants', [
                'call_id'      => $callId,
                'cmp_id'       => $ctx->cmpId,
                'party'        => 'supervisor',
                'monitor_mode' => (string) ($input['mode'] ?? 'listen'),
            ], 'participant_id');
            Audit::record($ctx, $auth, Audit::CALL_MONITORED, 'call', (string) $callId, [
                'mode' => (string) ($input['mode'] ?? 'listen'),
            ]);
        }

        return [
            'ok'      => $result->isOk(),
            'code'    => $result->code,
            'message' => $result->message,
            'detail'  => $result->detail + ['outcome' => $result->outcome],
        ];
    }

    /**
     * Set the outcome of a call.
     *
     * @param array<string, mixed> $input
     * @return array{ok: bool, code: ?string, message: ?string}
     */
    public static function disposition(Context $ctx, Auth $auth, int $callId, array $input): array
    {
        $call = self::row($ctx, $callId);
        if ($call === null) {
            return ['ok' => false, 'code' => 'not_found', 'message' => 'That call is not in this company.'];
        }

        $code = trim((string) ($input['disposition'] ?? ''));
        $note = trim((string) ($input['note'] ?? ''));

        $disposition = Db::first(
            'SELECT * FROM voice_dispositions
              WHERE code = :code AND cmp_id IN (0, :cmp) AND is_active = TRUE
              ORDER BY cmp_id DESC LIMIT 1',
            ['code' => $code, 'cmp' => $ctx->cmpId],
        );

        if ($disposition === null) {
            return ['ok' => false, 'code' => 'unknown_disposition', 'message' => 'That outcome is not configured.'];
        }

        if ((bool) $disposition['requires_note'] && $note === '') {
            return ['ok' => false, 'code' => 'note_required', 'message' => 'This outcome needs a note.'];
        }

        Db::update('voice_calls', [
            'disposition_id'   => (int) $disposition['disposition_id'],
            'disposition_note' => $note,
            'updated_at'       => Clock::sql(Clock::now()),
        ], ['call_id' => $callId, 'cmp_id' => $ctx->cmpId]);

        // "Asked not to be called" is not just a label on one call — it is an
        // instruction about every future one, so it goes to the suppression
        // list here rather than relying on somebody to add it by hand.
        if ($code === 'do_not_call' && !empty($call['remote_e164'])) {
            CallingPolicy::suppress($ctx, (string) $call['remote_e164'], 'opt_out', 'disposition', $auth->uuid);
        }

        return ['ok' => true, 'code' => null, 'message' => null];
    }

    /** @return array<string, mixed>|null */
    public static function find(Context $ctx, int $callId): ?array
    {
        $row = self::row($ctx, $callId);

        return $row === null ? null : self::present($row);
    }

    /** @return array<string, mixed>|null */
    public static function row(Context $ctx, int $callId): ?array
    {
        [$scope, $params] = $ctx->scopeClause();
        $params['id'] = $callId;

        return Db::first('SELECT * FROM voice_calls WHERE ' . $scope . ' AND call_id = :id', $params);
    }

    /**
     * The shape every screen reads a call in.
     *
     * NO CONTACT NAME. The number is here because it is evidence of what was
     * dialled; the name belongs to Contacts and is resolved live by whoever
     * displays it. `contact_ref` is the handle for doing that.
     *
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    public static function present(array $row): array
    {
        $state = (string) $row['state'];
        $stale = $row['state_stale_at'] !== null && $row['ended_at'] === null;

        return [
            'call_id'        => (int) $row['call_id'],
            'call_uuid'      => (string) $row['call_uuid'],
            'direction'      => (string) $row['direction'],
            'origin'         => (string) $row['origin'],
            'remote_e164'    => $row['remote_e164'],
            'remote_masked'  => $row['remote_e164'] === null ? null : CallingPolicy::mask((string) $row['remote_e164']),
            'local_e164'     => $row['local_e164'],
            'contact_ref'    => $row['contact_ref'],
            'crm_lead_ref'   => $row['crm_lead_ref'],
            'campaign_id'    => $row['campaign_id'] === null ? null : (int) $row['campaign_id'],
            'queue_id'       => $row['queue_id'] === null ? null : (int) $row['queue_id'],
            'owner_agent_id' => $row['owner_agent_id'] === null ? null : (int) $row['owner_agent_id'],
            'handled_by'     => (string) $row['handled_by'],
            'state'          => $stale ? 'unknown' : $state,
            // The real last-known state, alongside the honest "we cannot say".
            'last_known_state' => $state,
            'state_is_stale' => $stale,
            'state_reason'   => $row['state_reason'],
            'outcome'        => $row['outcome'],
            'abandoned'      => (bool) $row['abandoned'],
            'recording_state' => (string) $row['recording_state'],
            'consent_state'  => (string) $row['consent_state'],
            'language'       => $row['language'],
            'initiated_at'   => $row['initiated_at'],
            'answered_at'    => $row['answered_at'],
            'ended_at'       => $row['ended_at'],
            'queued_seconds' => (int) $row['queued_seconds'],
            'talk_seconds'   => (int) $row['talk_seconds'],
            'total_seconds'  => (int) $row['total_seconds'],
            'disposition_id' => $row['disposition_id'] === null ? null : (int) $row['disposition_id'],
            'disposition_note' => $row['disposition_note'],
        ];
    }

    /**
     * Should this call be recorded?
     *
     * Defaults to NOT recording. A company that has configured nothing is not
     * recording its customers' conversations because nobody opened the settings
     * screen. 'on_consent' starts recording only after consent is captured, so
     * it also returns false here.
     *
     * @param array<string, mixed> $settings
     * @param array<string, mixed> $number
     */
    private static function shouldRecord(array $settings, array $number): bool
    {
        $numberPolicy = (string) ($number['recording_policy'] ?? 'inherit');
        $policy = $numberPolicy === 'inherit' ? (string) $settings['recording_policy'] : $numberPolicy;

        return $policy === 'always';
    }

    /** @param array<string, mixed> $settings */
    private static function initialConsentState(array $settings, bool $recording): string
    {
        if (!$recording) {
            return 'not_applicable';
        }

        return $settings['recording_disclosure'] ? 'announced' : 'not_applicable';
    }

    /**
     * The business number to call from.
     *
     * @param array<string, mixed> $input
     * @param array<string, mixed>|null $connection
     * @return array<string, mixed>|null
     */
    private static function outboundNumber(Context $ctx, array $input, ?array $connection): ?array
    {
        [$scope, $params] = $ctx->scopeClause();

        if (!empty($input['number_id'])) {
            $params['id'] = (int) $input['number_id'];

            return Db::first(
                'SELECT * FROM voice_numbers WHERE ' . $scope . ' AND number_id = :id AND is_active = TRUE',
                $params,
            );
        }

        if ($connection !== null) {
            $params['conn'] = (int) $connection['connection_id'];
            $row = Db::first(
                'SELECT * FROM voice_numbers
                  WHERE ' . $scope . ' AND connection_id = :conn AND is_active = TRUE
                    AND routing_status = \'active\'
                  ORDER BY number_id LIMIT 1',
                $params,
            );
            if ($row !== null) {
                return $row;
            }
            unset($params['conn']);
        }

        return Db::first(
            'SELECT * FROM voice_numbers
              WHERE ' . $scope . ' AND is_active = TRUE AND routing_status = \'active\'
              ORDER BY number_id LIMIT 1',
            $params,
        );
    }

    private static function recordLeg(Context $ctx, int $callId, CallResult $result, string $endpoint): void
    {
        Db::insert('voice_call_legs', [
            'call_id'          => $callId,
            'cmp_id'           => $ctx->cmpId,
            'provider_leg_ref' => $result->providerRef,
            'leg_role'         => 'customer',
            'endpoint'         => $endpoint,
            'state'            => $result->isOk() ? 'initiated' : 'failed',
        ], 'leg_id');
    }

    private static function providerRef(int $callId): ?string
    {
        $ref = Db::scalar(
            'SELECT provider_leg_ref FROM voice_call_legs
              WHERE call_id = :id AND provider_leg_ref IS NOT NULL
              ORDER BY leg_id LIMIT 1',
            ['id' => $callId],
        );

        return $ref === null ? null : (string) $ref;
    }

    /** @param array<string, mixed> $detail */
    private static function fail(string $code, string $message, array $detail = []): array
    {
        return ['ok' => false, 'code' => $code, 'message' => $message, 'call' => null, 'detail' => $detail];
    }

    private static function stringOrNull(mixed $value): ?string
    {
        $value = is_scalar($value) ? trim((string) $value) : '';

        return $value === '' ? null : $value;
    }
}
