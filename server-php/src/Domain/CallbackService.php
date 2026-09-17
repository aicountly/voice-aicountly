<?php

declare(strict_types=1);

namespace Aicountly\Api\Domain;

use Aicountly\Api\Auth;
use Aicountly\Api\Clients\CalendarClient;
use Aicountly\Api\Context;
use Aicountly\Api\Db;
use Aicountly\Api\ExternalOperations;
use Aicountly\Api\Features;
use Aicountly\Api\Support\Clock;

/**
 * Callbacks — Voice's own plan to ring somebody back.
 *
 * ## Why this is allowed to be a Voice table
 *
 * A callback is a CALL ATTEMPT PLAN: this number, this reason, by this time,
 * this many tries. That is calling-domain work and nothing else owns it.
 *
 * ## Where it stops being one
 *
 * The moment a callback needs to occupy time in somebody's diary, it is a
 * calendar event — and Calendar owns those. So `schedule()` creates the event
 * THROUGH Calendar's API and stores `calendar_event_ref`. The time shown next
 * to a callback comes from Calendar when it is displayed. Voice does not keep a
 * second copy of the start time, because a copy is what stays wrong after
 * somebody moves it.
 *
 * If Calendar is unreachable the callback is still created — it is Voice's own
 * record and perfectly useful without a diary entry — and the response says the
 * diary entry could not be made. It does not fabricate one locally.
 */
final class CallbackService
{
    /**
     * @param array<string, mixed> $input
     * @return array{ok: bool, code: ?string, message: ?string, callback: ?array<string, mixed>}
     */
    public static function create(Context $ctx, Auth $auth, array $input): array
    {
        $e164 = CallingPolicy::normalise((string) ($input['e164'] ?? ''));
        if ($e164 === null) {
            return ['ok' => false, 'code' => 'invalid_number', 'message' => 'That is not a number this system can dial.', 'callback' => null];
        }

        $dueAt = Clock::parse((string) ($input['due_at'] ?? ''));

        $callbackId = (int) Db::insert('voice_callbacks', [
            'cmp_id'         => $ctx->cmpId,
            'bo_id'          => $ctx->boId,
            'source_call_id' => isset($input['source_call_id']) ? (int) $input['source_call_id'] : null,
            'campaign_id'    => isset($input['campaign_id']) ? (int) $input['campaign_id'] : null,
            'contact_ref'    => self::stringOrNull($input['contact_ref'] ?? null),
            'e164'           => $e164,
            'reason'         => trim((string) ($input['reason'] ?? '')),
            'priority'       => in_array($input['priority'] ?? '', ['high', 'normal', 'low'], true)
                ? (string) $input['priority'] : 'normal',
            'due_at'         => $dueAt === null ? null : Clock::sql($dueAt),
            'assigned_agent_id' => isset($input['assigned_agent_id']) ? (int) $input['assigned_agent_id'] : null,
            'queue_id'       => isset($input['queue_id']) ? (int) $input['queue_id'] : null,
            'max_attempts'   => max(1, min(10, (int) ($input['max_attempts'] ?? 3))),
            'status'         => $dueAt === null ? 'open' : 'scheduled',
            'created_by'     => $auth->uuid,
        ], 'callback_id');

        $message = null;
        if (!empty($input['create_calendar_event']) && $dueAt !== null) {
            $calendar = self::addToCalendar($ctx, $auth, $callbackId, $e164, $dueAt, (string) ($input['reason'] ?? ''));
            $message = $calendar['message'];
        }

        return [
            'ok'      => true,
            'code'    => null,
            'message' => $message,
            'callback' => self::find($ctx, $callbackId),
        ];
    }

    /**
     * Put the callback in somebody's diary, through Calendar.
     *
     * @return array{ok: bool, message: ?string}
     */
    public static function addToCalendar(
        Context $ctx,
        Auth $auth,
        int $callbackId,
        string $e164,
        \DateTimeImmutable $dueAt,
        string $reason,
    ): array {
        $client = new CalendarClient();
        if (!Features::enabled('CALENDAR') || !$client->configured()) {
            return [
                'ok' => false,
                'message' => 'The callback was saved. Aicountly Calendar is not connected, so nothing was added to a diary.',
            ];
        }

        $opened = ExternalOperations::begin(
            $ctx,
            'calendar',
            'create_event',
            ['starts_at' => Clock::iso($dueAt), 'kind' => 'callback'],
            ['callback_id' => $callbackId],
            $auth->uuid,
        );

        $result = $client
            ->forSubscriber($auth->uuid)
            ->withSession($auth->sesKey())
            ->createEvent([
                'cmp_id'     => $ctx->cmpId,
                'bo_id'      => $ctx->boId,
                'title'      => 'Callback: ' . CallingPolicy::mask($e164),
                'description' => $reason,
                'start'      => Clock::iso($dueAt),
                'end'        => Clock::iso($dueAt->modify('+15 minutes')),
                'source'     => 'voice',
                'source_ref' => (string) $callbackId,
                'correlation_id' => $opened['correlation_id'],
            ], $opened['correlation_id']);

        $eventRef = self::extractRef($result['body'] ?? null);
        $status = ExternalOperations::settle($opened['operation_id'], $result, $eventRef);

        if ($status === ExternalOperations::SUCCEEDED) {
            // The id, and only the id.
            Db::update('voice_callbacks', [
                'calendar_event_ref' => $eventRef,
                'updated_at'         => Clock::sql(Clock::now()),
            ], ['callback_id' => $callbackId, 'cmp_id' => $ctx->cmpId]);

            return ['ok' => true, 'message' => null];
        }

        $operation = ExternalOperations::find($ctx, $opened['operation_id']);

        return [
            'ok' => false,
            'message' => 'The callback was saved. ' . ($operation === null
                ? 'The diary entry could not be created.'
                : ExternalOperations::present($operation)['message']),
        ];
    }

    /**
     * @param array<string, mixed> $input
     * @return array{ok: bool, code: ?string, message: ?string, callback: ?array<string, mixed>}
     */
    public static function update(Context $ctx, Auth $auth, int $callbackId, array $input): array
    {
        $row = self::row($ctx, $callbackId);
        if ($row === null) {
            return ['ok' => false, 'code' => 'not_found', 'message' => 'That callback is not in this company.', 'callback' => null];
        }

        $values = ['updated_at' => Clock::sql(Clock::now())];

        if (isset($input['status'])) {
            $status = (string) $input['status'];
            if (!in_array($status, ['open', 'scheduled', 'in_progress', 'completed', 'cancelled', 'failed'], true)) {
                return ['ok' => false, 'code' => 'unknown_status', 'message' => 'That is not a callback status.', 'callback' => null];
            }
            $values['status'] = $status;
        }
        foreach (['reason', 'priority'] as $field) {
            if (isset($input[$field]) && is_string($input[$field])) {
                $values[$field] = trim($input[$field]);
            }
        }
        if (array_key_exists('due_at', $input)) {
            $dueAt = Clock::parse((string) $input['due_at']);
            $values['due_at'] = $dueAt === null ? null : Clock::sql($dueAt);
        }
        if (array_key_exists('assigned_agent_id', $input)) {
            $values['assigned_agent_id'] = $input['assigned_agent_id'] === null ? null : (int) $input['assigned_agent_id'];
        }

        Db::update('voice_callbacks', $values, ['callback_id' => $callbackId, 'cmp_id' => $ctx->cmpId]);

        return ['ok' => true, 'code' => null, 'message' => null, 'callback' => self::find($ctx, $callbackId)];
    }

    /** @return array<string, mixed>|null */
    public static function find(Context $ctx, int $callbackId): ?array
    {
        $row = self::row($ctx, $callbackId);

        return $row === null ? null : self::present($row);
    }

    /** @return array<string, mixed>|null */
    public static function row(Context $ctx, int $callbackId): ?array
    {
        [$scope, $params] = $ctx->scopeClause();
        $params['id'] = $callbackId;

        return Db::first('SELECT * FROM voice_callbacks WHERE ' . $scope . ' AND callback_id = :id', $params);
    }

    /** @param array<string, mixed> $row @return array<string, mixed> */
    public static function present(array $row): array
    {
        return [
            'callback_id'    => (int) $row['callback_id'],
            'source_call_id' => $row['source_call_id'] === null ? null : (int) $row['source_call_id'],
            'campaign_id'    => $row['campaign_id'] === null ? null : (int) $row['campaign_id'],
            'contact_ref'    => $row['contact_ref'],
            'e164'           => (string) $row['e164'],
            'e164_masked'    => CallingPolicy::mask((string) $row['e164']),
            'reason'         => (string) $row['reason'],
            'priority'       => (string) $row['priority'],
            'due_at'         => $row['due_at'],
            'assigned_agent_id' => $row['assigned_agent_id'] === null ? null : (int) $row['assigned_agent_id'],
            'queue_id'       => $row['queue_id'] === null ? null : (int) $row['queue_id'],
            'status'         => (string) $row['status'],
            'attempts'       => (int) $row['attempts'],
            'max_attempts'   => (int) $row['max_attempts'],
            'last_attempt_at' => $row['last_attempt_at'],
            // The reference only. The event's time is read from Calendar.
            'calendar_event_ref' => $row['calendar_event_ref'],
            'created_at'     => $row['created_at'],
        ];
    }

    private static function stringOrNull(mixed $value): ?string
    {
        $value = is_scalar($value) ? trim((string) $value) : '';

        return $value === '' ? null : $value;
    }

    /** @param array<string, mixed>|null $body */
    private static function extractRef(?array $body): ?string
    {
        if ($body === null) {
            return null;
        }
        foreach ([$body, $body['data'] ?? []] as $candidate) {
            if (!is_array($candidate)) {
                continue;
            }
            foreach (['event_uuid', 'event_id', 'uuid', 'id'] as $key) {
                if (isset($candidate[$key]) && is_scalar($candidate[$key]) && (string) $candidate[$key] !== '') {
                    return (string) $candidate[$key];
                }
            }
        }

        return null;
    }
}
