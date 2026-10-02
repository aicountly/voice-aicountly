<?php

declare(strict_types=1);

namespace Aicountly\Api\Domain;

use Aicountly\Api\Auth;
use Aicountly\Api\Context;
use Aicountly\Api\Db;
use Aicountly\Api\Settings;
use Aicountly\Api\Support\Clock;

/**
 * Callbacks — Voice's own plan to ring somebody back.
 *
 * ## Why this is allowed to be a Voice table
 *
 * A callback is a CALL ATTEMPT PLAN: this number, this reason, by this time,
 * this many tries. That is calling-domain work and nothing else owns it — the
 * due time included. Every list, the overdue filter and the Command Centre
 * read `due_at` here, and it is the time.
 *
 * ## Where it touches Calendar
 *
 * When asked (`create_calendar_event`), the time also goes into a diary: a
 * busy entry in the assigned agent's Aicountly Calendar, written and kept true
 * by CallbackDiary — moved when the callback is rescheduled, cancelled when it
 * is cancelled, moved to the new agent's diary when it is reassigned. Calendar
 * holds that entry; Voice holds its reference. The entry follows the callback,
 * never the other way round: it cannot be moved from Calendar's side.
 *
 * If Calendar cannot take the entry the callback is still created — it is
 * Voice's own record and perfectly useful without one — and the answer says
 * exactly what happened to the diary entry. Nothing is fabricated locally.
 */
final class CallbackService
{
    /**
     * @param array<string, mixed> $input
     * @return array{ok: bool, code: ?string, message: ?string, callback: ?array<string, mixed>}
     */
    public static function create(Context $ctx, Auth $auth, array $input): array
    {
        $e164 = CallingPolicy::normaliseFor($ctx, (string) ($input['e164'] ?? ''));
        if ($e164 === null) {
            return ['ok' => false, 'code' => 'invalid_number', 'message' => 'That is not a number this system can dial.', 'callback' => null];
        }

        $dueAt = self::dueAt($ctx, $input['due_at'] ?? null);
        $wantsDiary = !empty($input['create_calendar_event']);
        // Before migration 010 a callback is saved exactly as it always was;
        // only the diary entry is refused, and says why.
        $diaryColumns = CallbackDiary::schemaReady() ? [
            'exact_time'         => $dueAt !== null && !empty($input['exact_time']),
            'calendar_requested' => $wantsDiary,
        ] : [];

        $callbackId = (int) Db::insert('voice_callbacks', $diaryColumns + [
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
        if ($wantsDiary) {
            $diary = CallbackDiary::sync($ctx, $callbackId, ['create'], $auth->uuid);
            $message = $diary['message'] === null ? null : 'The callback was saved. ' . $diary['message'];
        }

        return [
            'ok'      => true,
            'code'    => null,
            'message' => $message,
            'callback' => self::find($ctx, $callbackId),
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
        // What changed that the diary entry follows. Reason and priority do
        // not: neither is in the entry.
        $changed = [];

        if (isset($input['status'])) {
            $status = (string) $input['status'];
            if (!in_array($status, ['open', 'scheduled', 'in_progress', 'completed', 'cancelled', 'failed'], true)) {
                return ['ok' => false, 'code' => 'unknown_status', 'message' => 'That is not a callback status.', 'callback' => null];
            }
            $values['status'] = $status;
            if ($status !== (string) $row['status']) {
                $changed[] = 'status';
            }
        }
        foreach (['reason', 'priority'] as $field) {
            if (isset($input[$field]) && is_string($input[$field])) {
                $values[$field] = trim($input[$field]);
            }
        }
        if (array_key_exists('due_at', $input)) {
            $dueAt = self::dueAt($ctx, $input['due_at']);
            $values['due_at'] = $dueAt === null ? null : Clock::sql($dueAt);
            $before = Clock::parse($row['due_at'] === null ? null : (string) $row['due_at']);
            if (($before === null) !== ($dueAt === null)
                || ($before !== null && $dueAt !== null && $before->getTimestamp() !== $dueAt->getTimestamp())) {
                $changed[] = 'due_at';
            }
        }
        if (array_key_exists('assigned_agent_id', $input)) {
            $values['assigned_agent_id'] = $input['assigned_agent_id'] === null ? null : (int) $input['assigned_agent_id'];
            if ($values['assigned_agent_id'] !== ($row['assigned_agent_id'] === null ? null : (int) $row['assigned_agent_id'])) {
                $changed[] = 'assigned_agent_id';
            }
        }
        $diaryReady = CallbackDiary::schemaReady();
        if (array_key_exists('exact_time', $input) && $diaryReady) {
            $values['exact_time'] = (bool) $input['exact_time'];
            if ($values['exact_time'] !== self::flag($row['exact_time'])) {
                $changed[] = 'exact_time';
            }
        }
        if (array_key_exists('create_calendar_event', $input) && $diaryReady) {
            $values['calendar_requested'] = (bool) $input['create_calendar_event'];
            if ($values['calendar_requested'] !== self::flag($row['calendar_requested'])) {
                $changed[] = 'create_calendar_event';
            }
        }

        Db::update('voice_callbacks', $values, ['callback_id' => $callbackId, 'cmp_id' => $ctx->cmpId]);

        $message = null;
        if (!$diaryReady) {
            if (!empty($input['create_calendar_event']) || $row['calendar_event_ref'] !== null) {
                $message = CallbackDiary::SCHEMA_MISSING;
            }
        } elseif ($changed !== [] && (self::flag($values['calendar_requested'] ?? $row['calendar_requested'])
            || $row['calendar_event_ref'] !== null)) {
            $message = CallbackDiary::sync($ctx, $callbackId, $changed, $auth->uuid)['message'];
        }

        return ['ok' => true, 'code' => null, 'message' => $message, 'callback' => self::find($ctx, $callbackId)];
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
            // The time. Voice's own: a diary entry follows it, never the reverse.
            'due_at'         => $row['due_at'],
            'exact_time'     => self::flag($row['exact_time'] ?? false),
            'assigned_agent_id' => $row['assigned_agent_id'] === null ? null : (int) $row['assigned_agent_id'],
            'queue_id'       => $row['queue_id'] === null ? null : (int) $row['queue_id'],
            'status'         => (string) $row['status'],
            'attempts'       => (int) $row['attempts'],
            'max_attempts'   => (int) $row['max_attempts'],
            'last_attempt_at' => $row['last_attempt_at'],
            // Calendar's id for the diary entry, when there is one. Nothing
            // about the event itself is kept or shown from here.
            'calendar_event_ref' => $row['calendar_event_ref'],
            'calendar'       => CallbackDiary::present($row),
            'created_at'     => $row['created_at'],
        ];
    }

    private static function stringOrNull(mixed $value): ?string
    {
        $value = is_scalar($value) ? trim((string) $value) : '';

        return $value === '' ? null : $value;
    }

    /**
     * A due time as somebody gave it. One without an offset is read in the
     * company's own timezone (Settings), never the server's: "15:00" from a
     * company in Kolkata is 09:30Z.
     */
    private static function dueAt(Context $ctx, mixed $value): ?\DateTimeImmutable
    {
        if (!is_scalar($value)) {
            return null;
        }

        return Clock::parseIn((string) $value, Clock::zone((string) (Settings::forCompany($ctx->cmpId)['timezone'] ?? '')));
    }

    private static function flag(mixed $value): bool
    {
        if (is_bool($value)) {
            return $value;
        }

        return in_array(strtolower(trim((string) $value)), ['1', 't', 'true', 'yes'], true);
    }
}
