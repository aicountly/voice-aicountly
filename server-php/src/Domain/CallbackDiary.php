<?php

declare(strict_types=1);

namespace Aicountly\Api\Domain;

use Aicountly\Api\Auth;
use Aicountly\Api\Clients\CalendarClient;
use Aicountly\Api\Context;
use Aicountly\Api\Db;
use Aicountly\Api\ExternalOperations;
use Aicountly\Api\Settings;
use Aicountly\Api\Support\Clock;

/**
 * A callback's diary entry in Aicountly Calendar — a projection Voice keeps
 * true, under the Calendar Events API v1 contract.
 *
 * ## Who owns what
 *
 * The callback is Voice's: its number, reason, due time and status. The entry
 * is Calendar's: one event in ONE person's diary that says "busy" for fifteen
 * minutes from the due time. Calendar marks it product-owned, so nobody can
 * move or cancel it from Calendar's side — it changes when, and only when,
 * Voice changes it. That is what makes it Voice's job to keep it true: create
 * it when a callback asks for it, move it when the callback is rescheduled,
 * cancel it when the callback no longer needs the time, and move it to another
 * diary (cancel there, create here) when the callback is reassigned. A
 * cancelled entry is never revived; a new one gets a new source_ref.
 *
 * ## Whose diary
 *
 * The person who will make the call: the ASSIGNED agent. Only when nobody is
 * assigned does it go to whoever created the callback — and only if that is a
 * person. A service caller that named nobody gets no entry and is told so;
 * Calendar would otherwise be asked to fill a diary no one can open.
 *
 * ## What goes in it
 *
 * Nothing about the customer: "Callback · #123", a pointer back to Voice, the
 * time, busy, busy-only. The number, the name and the reason stay in Voice — a
 * diary is shown on phones and shared screens, and none of that is the
 * customer's choice. No reminder either: Calendar's reminders are the owner's
 * own, and Voice does not pretend to send one.
 *
 * ## When the outcome is not known (contract §12)
 *
 * Every write is one attempt of one change: an operation row with its own
 * Idempotency-Key, the exact request, the owner and the source_ref, saved
 * BEFORE it is sent. The answer is read by the contract, not by its status code
 * (classify()). A timeout, a 5xx, or a 2xx without an event id and version is
 * UNKNOWN: never failed, never given a new key — settled by asking Calendar
 * (the source_ref lookup, as the stored owner) and, if Calendar holds nothing,
 * by sending the SAME attempt again (recover()). While one change for a
 * callback is unresolved the next one waits, because two writes in flight for
 * one entry is how an entry ends up wrong.
 */
final class CallbackDiary
{
    /** How long an entry holds the diary. A callback is a call, not a meeting. */
    public const SLOT_MINUTES = 15;

    // voice_callbacks.calendar_state
    public const NOT_CONNECTED = 'not_connected';
    public const PENDING       = 'pending';
    public const LINKED        = 'linked';
    public const UNKNOWN       = 'unknown';
    public const DEFERRED      = 'deferred';
    public const REFUSED       = 'refused';
    public const FAILED        = 'failed';
    public const CANCELLED     = 'cancelled';

    /** Callback statuses that still need the time held. */
    private const ACTIVE = ['open', 'scheduled', 'in_progress'];

    /** Changes that justify another attempt after Calendar refused or rejected one. */
    private const RELEVANT = ['create', 'due_at', 'assigned_agent_id', 'exact_time', 'create_calendar_event', 'status'];

    /** At most this many writes per sync: a reassign is two, a stale version one more. */
    private const MAX_STEPS = 3;

    /** 503 codes that mean "Calendar refused before acting", not "it may have acted". */
    private const NOT_READY = ['schema_not_ready', 'actor_verification_unavailable', 'auth_unavailable'];

    private const OPEN = [ExternalOperations::PENDING, ExternalOperations::UNKNOWN, ExternalOperations::DEFERRED];

    // -----------------------------------------------------------------------
    // Keeping the entry true
    // -----------------------------------------------------------------------

    /**
     * Bring the callback's diary entry in line with the callback as it is now.
     *
     * Called after a callback is created or changed (with the fields that
     * changed) and by the reconciler after it settles an earlier write (with
     * none). Never throws for a Calendar problem: the callback is Voice's own
     * record and stands whatever Calendar says.
     *
     * @param list<string> $changed fields the caller just changed; `create` for a new callback
     * @return array{state: string, message: ?string} message: what the person who made the change should read, null when the entry is as asked
     */
    public static function sync(Context $ctx, int $callbackId, array $changed = [], ?string $actor = null): array
    {
        for ($step = 0; $step < self::MAX_STEPS; $step++) {
            $row = CallbackService::row($ctx, $callbackId);
            if ($row === null) {
                return ['state' => 'none', 'message' => null];
            }

            if (self::openOperation($callbackId) !== null) {
                if ($step > 0) {
                    break;
                }

                return [
                    'state'   => self::describe($row)['state'],
                    'message' => 'An earlier change to this callback’s diary entry is still being confirmed with Aicountly Calendar. Voice makes this change once it is.',
                ];
            }

            $plan = self::plan($ctx, $row, $changed);
            if ($plan['action'] === 'none') {
                break;
            }
            self::perform($ctx, $row, $plan, $actor);
            // Only the first step answers to what the person changed. Later steps
            // finish what it started (the create after a reassign's cancel, the
            // retry after a stale version) and never repeat a refusal.
            $changed = [];
        }

        $row = CallbackService::row($ctx, $callbackId);

        return $row === null ? ['state' => 'none', 'message' => null] : self::outcome($row);
    }

    /**
     * Settle one diary write that the request which started it could not.
     *
     * Run by bin/call-recovery.php for each Calendar operation that is pending,
     * unknown or deferred and due. Ask first (the lookup, as the stored owner),
     * adopt what Calendar holds, and only when Calendar holds nothing send the
     * SAME attempt again under the same key. A lookup that fails leaves the
     * operation unknown, asked again later — never failed, never re-keyed.
     *
     * @param array<string, mixed> $op a voice_external_operations row
     * @return string settled | resent | waiting | superseded | abandoned
     */
    public static function recover(array $op): string
    {
        $operationId = (int) $op['operation_id'];
        $callbackId = (int) ($op['callback_id'] ?? 0);

        if (self::str($op['owner_uuid'] ?? null) === null || self::str($op['source_ref'] ?? null) === null
            || self::str($op['request_method'] ?? null) === null || $callbackId <= 0) {
            // Written before Voice kept the owner and the source_ref: there is
            // no question Calendar can answer about it.
            ExternalOperations::mark($operationId, ExternalOperations::ABANDONED, [
                'error_code'    => 'legacy_unaddressable',
                'error_message' => 'Recorded before Voice kept the diary owner and source_ref, so Calendar cannot be asked about it. A person needs to check the diary.',
                'next_check_at' => null,
            ]);

            return 'abandoned';
        }

        $ctx = Context::forCompany((int) $op['cmp_id']);
        if (!(new CalendarClient())->configured()) {
            ExternalOperations::retryLater($operationId, (int) $op['attempts'] + 1, 'Aicountly Calendar is not connected in this deployment right now.');

            return 'waiting';
        }

        if ((string) $op['status'] === ExternalOperations::DEFERRED) {
            // Calendar refused this attempt before acting on it, so nothing
            // happened there. If the callback has moved on, the attempt is
            // dropped unsent and the current state is written instead.
            $row = CallbackService::row($ctx, $callbackId);
            if ($row === null || !self::stillWanted($ctx, $row, $op)) {
                Db::transaction(static function () use ($op, $row, $operationId): void {
                    ExternalOperations::mark($operationId, ExternalOperations::FAILED, [
                        'error_code'    => 'superseded',
                        'error_message' => 'Never applied: the callback changed before Calendar accepted it.',
                        'next_check_at' => null,
                    ]);
                    if ($row !== null) {
                        $live = self::str($row['calendar_event_ref'] ?? null) !== null;
                        self::save((int) $op['cmp_id'], (int) $row['callback_id'], [
                            'calendar_state'  => $live ? self::LINKED : null,
                            'calendar_detail' => $live ? self::linkedText($row) : null,
                        ]);
                    }
                });
                self::sync($ctx, $callbackId);

                return 'superseded';
            }

            self::resend($op);
            self::sync($ctx, $callbackId);

            return 'resent';
        }

        $found = self::lookupEvent($op);
        if (!$found['ok']) {
            ExternalOperations::retryLater($operationId, (int) $op['attempts'] + 1, 'Calendar could not be asked: ' . $found['error']);

            return 'waiting';
        }

        $event = $found['event'];
        $settled = match ((string) $op['operation']) {
            'create_event' => $event !== null,
            'cancel_event' => $event === null || $event['status'] === 'cancelled',
            default        => $event === null || $event['status'] === 'cancelled' || self::matchesRequest($op, $event),
        };

        if ($settled) {
            self::apply($op, $event === null
                ? ['outcome' => 'gone', 'code' => 'event_not_found', 'event' => null, 'detail' => 'Calendar holds no such entry for this person.']
                : ['outcome' => (string) $op['operation'] === 'create_event' ? 'adopt' : 'settled', 'code' => '', 'event' => $event, 'detail' => ''],
                200);
            self::sync($ctx, $callbackId);

            return 'settled';
        }

        self::resend($op);
        self::sync($ctx, $callbackId);

        return 'resent';
    }

    // -----------------------------------------------------------------------
    // Reads for screens
    // -----------------------------------------------------------------------

    /**
     * Would this time clash in the diary the callback would go to?
     *
     * ADVISORY, and said to be: it is asked before a time is promised to a
     * caller, and Calendar's conflict-check is not a hold. The write itself,
     * sent with conflict_policy "reject" when the time was promised, is what
     * decides. `checked:false` from Calendar is "unverified", never "free".
     *
     * @return array{state: string, message: string, conflicts: list<array{start_at: string, end_at: string, all_day: bool}>, checked_at: string}
     */
    public static function check(Context $ctx, Auth $auth, ?string $dueAt, ?int $assignedAgentId, ?int $callbackId): array
    {
        $answer = static fn (string $state, string $message, array $conflicts = []): array => [
            'state'      => $state,
            'message'    => $message,
            'conflicts'  => $conflicts,
            'checked_at' => Clock::iso(Clock::now()),
        ];

        $client = new CalendarClient();
        if (!$client->configured()) {
            return $answer(self::NOT_CONNECTED, 'Aicountly Calendar is not connected to Voice in this deployment, so no diary can be checked.');
        }

        $due = Clock::parseIn($dueAt, self::companyZone($ctx));
        if ($due === null) {
            return $answer('invalid', 'Give a due time to check.');
        }

        $row = $callbackId === null ? null : CallbackService::row($ctx, $callbackId);
        $probe = [
            'assigned_agent_id' => $assignedAgentId ?? ($row['assigned_agent_id'] ?? null),
            'created_by'        => $row['created_by'] ?? $auth->uuid,
        ];
        $owner = self::owner($ctx, $probe);
        if ($owner['uuid'] === null) {
            return $answer('no_owner', (string) $owner['problem']);
        }
        $who = match (true) {
            $owner['uuid'] === $auth->uuid        => 'you are',
            $probe['assigned_agent_id'] !== null  => 'the assigned agent is',
            default                               => 'the person who created this callback is',
        };

        // A callback being moved does not clash with its own entry.
        $ignore = [];
        if ($row !== null && self::str($row['calendar_event_ref'] ?? null) !== null
            && self::str($row['calendar_owner_uuid'] ?? null) === $owner['uuid']
            && $row['calendar_state'] !== self::CANCELLED) {
            $ignore[] = (string) $row['calendar_event_ref'];
        }

        $result = $client->forSubscriber($owner['uuid'])->forCompany($ctx->cmpId)->conflictCheck(
            [$owner['uuid']],
            Clock::iso($due),
            Clock::iso($due->modify('+' . self::SLOT_MINUTES . ' minutes')),
            $ignore,
        );
        $data = $result['body']['data'] ?? null;
        if (!$result['ok'] || ($result['body']['success'] ?? null) !== true || !is_array($data)) {
            return $answer('unavailable', 'Aicountly Calendar could not be asked just now ('
                . ($result['error'] ?? 'HTTP ' . $result['status']) . '), so nothing is known about that time.');
        }

        $subject = null;
        foreach ((array) ($data['subscribers'] ?? []) as $candidate) {
            if (is_array($candidate) && (string) ($candidate['subscriber_uuid'] ?? '') === $owner['uuid']) {
                $subject = $candidate;
            }
        }

        if (($data['checked'] ?? null) !== true || ($subject['checked'] ?? null) !== true) {
            $reason = is_string($subject['reason'] ?? null) && $subject['reason'] !== '' ? ' (' . $subject['reason'] . ')' : '';

            return $answer('unverified', 'Aicountly Calendar could not confirm whether ' . $who . ' free then' . $reason
                . '. That is not the same as free.');
        }

        if (($data['free'] ?? null) === true && ($subject['free'] ?? null) === true) {
            return $answer('free', 'Per Aicountly Calendar, ' . $who . ' free then. This is a check, not a hold: Calendar decides when the entry is written.');
        }

        $conflicts = [];
        foreach ((array) ($subject['conflicts'] ?? []) as $conflict) {
            if (is_array($conflict)) {
                $conflicts[] = [
                    'start_at' => (string) ($conflict['start_at'] ?? ''),
                    'end_at'   => (string) ($conflict['end_at'] ?? ''),
                    'all_day'  => (bool) ($conflict['all_day'] ?? false),
                ];
            }
        }

        return $answer('busy', 'Per Aicountly Calendar, ' . $who . ' busy then.', $conflicts);
    }

    /**
     * The diary part of a callback, for the API.
     *
     * @param array<string, mixed> $row
     * @return array{requested: bool, state: string, detail: ?string, checked_at: mixed}
     */
    public static function present(array $row): array
    {
        $described = self::describe($row);

        return [
            'requested'  => self::bool($row['calendar_requested'] ?? false),
            'state'      => $described['state'],
            'detail'     => $described['detail'],
            'checked_at' => $row['calendar_checked_at'] ?? null,
        ];
    }

    /**
     * Calendar's answer, read by the contract (§12.3) — never by its status
     * code alone.
     *
     *   settled           2xx, success:true, data.event with an id and an
     *                     integer version, echoing OUR source_ref (and, for a
     *                     cancel, saying cancelled). The only road to success.
     *   adopt             409 source_ref_exists: it already holds this entry.
     *   refused           409 slot_taken / availability_unverified. Final for
     *                     this attempt; not retried.
     *   version_conflict  409: someone changed it since; re-read and decide.
     *   cancelled         409 event_cancelled: it is cancelled, terminally.
     *   not_found         404 event_not_found: gone, once a lookup agrees.
     *   in_progress       409 request_in_progress: same key, ask again.
     *   config            401/403/404 route/428, or a 503 that says Calendar is
     *                     not ready: it did not act. Retried, same attempt.
     *   bug               a 422 or another refusal of the request itself.
     *   unknown           no answer, another 5xx, an unreadable body, or a 2xx
     *                     Calendar cannot back with an id and a version.
     *
     * @param array<string, mixed> $op
     * @param array{ok:bool, status:int, body:?array, error:?string} $result
     * @return array{outcome: string, code: string, event: ?array<string, mixed>, detail: string}
     */
    public static function classify(array $op, array $result): array
    {
        $status = (int) $result['status'];
        $body = is_array($result['body'] ?? null) ? $result['body'] : null;
        $code = is_string($body['code'] ?? null) ? (string) $body['code'] : '';
        $event = self::eventIn($body);
        $said = trim((string) ($result['error'] ?? ''));
        $verdict = static fn (string $outcome, string $code, string $detail, ?array $event = null): array => [
            'outcome' => $outcome, 'code' => $code, 'event' => $event, 'detail' => $detail,
        ];

        if ($result['ok']) {
            if (($body['success'] ?? null) !== true || $event === null) {
                return $verdict('unknown', 'contract_unsupported', 'Calendar answered HTTP ' . $status
                    . ' without an event id and version, which is not the v1 contract, so the outcome cannot be confirmed.');
            }
            $expectedId = self::eventIdOf($op);
            if ($event['source_ref'] !== (string) $op['source_ref']
                || ($expectedId !== null && $event['id'] !== $expectedId)
                || ((string) $op['operation'] === 'cancel_event' && $event['status'] !== 'cancelled')) {
                return $verdict('unknown', 'unexpected_event', 'Calendar answered with an event that is not the change Voice sent, so the outcome cannot be confirmed.');
            }

            return $verdict('settled', '', '', $event);
        }

        if ($status === 0) {
            return $verdict('unknown', 'no_response', 'Calendar did not answer (' . ($said !== '' ? $said : 'no response') . ').');
        }

        if ($status === 409) {
            return match ($code) {
                'slot_taken', 'availability_unverified' => $verdict('refused', $code, $said),
                'source_ref_exists' => $event !== null
                    ? $verdict('adopt', $code, '', $event)
                    : $verdict('unknown', $code, 'Calendar says it already holds this entry but did not return it.'),
                'version_conflict'    => $verdict('version_conflict', $code, $said, $event),
                'event_cancelled'     => $verdict('cancelled', $code, $said, $event),
                'request_in_progress' => $verdict('in_progress', $code, 'Calendar is still working on this same request.'),
                default               => $verdict('bug', $code !== '' ? $code : 'conflict', $said),
            };
        }

        if ($status === 404 && $code === 'event_not_found') {
            return $verdict('not_found', $code, $said);
        }

        if (in_array($status, [401, 403, 404, 428], true) || ($status === 503 && in_array($code, self::NOT_READY, true))) {
            return $verdict('config', $code !== '' ? $code : 'http_' . $status, $said !== '' ? $said : 'HTTP ' . $status);
        }

        if ($status >= 500 || $status === 408 || $status === 429) {
            return $verdict('unknown', $code !== '' ? $code : 'http_' . $status, 'Calendar answered HTTP ' . $status . ', so it may or may not have acted.');
        }

        return $verdict('bug', $code !== '' ? $code : 'http_' . $status, $said !== '' ? $said : 'HTTP ' . $status);
    }

    // -----------------------------------------------------------------------
    // Planning
    // -----------------------------------------------------------------------

    /**
     * What to do next, decided from the callback as it is now.
     *
     * @param array<string, mixed> $row
     * @param list<string> $changed
     * @return array{action: string, state?: string, detail?: ?string, owner?: string, due?: \DateTimeImmutable}
     */
    private static function plan(Context $ctx, array $row, array $changed): array
    {
        $state = self::str($row['calendar_state'] ?? null);
        $eventId = self::str($row['calendar_event_ref'] ?? null);
        $entryOwner = self::str($row['calendar_owner_uuid'] ?? null);

        // Written before Voice recorded whose diary it is in: Calendar answers
        // "not found" to anybody but the owner, so it cannot be addressed.
        if ($eventId !== null && $entryOwner === null) {
            return ['action' => 'none'];
        }

        $live = $eventId !== null && $state !== self::CANCELLED;
        $due = Clock::parse(self::str($row['due_at'] ?? null));
        $wants = self::bool($row['calendar_requested'] ?? false) && $due !== null
            && in_array((string) $row['status'], self::ACTIVE, true);

        if (!$live && !$wants) {
            return ['action' => 'none'];
        }

        $relevant = array_intersect($changed, self::RELEVANT) !== [];
        if (in_array($state, [self::REFUSED, self::FAILED], true) && !$relevant) {
            return ['action' => 'none'];
        }

        if (!(new CalendarClient())->configured()) {
            $detail = $live
                ? 'Aicountly Calendar is not connected to Voice in this deployment, so the diary entry was not changed.'
                : 'Aicountly Calendar is not connected to Voice in this deployment, so nothing was added to a diary.';

            return $state === self::NOT_CONNECTED && ($row['calendar_detail'] ?? null) === $detail
                ? ['action' => 'none']
                : ['action' => 'note', 'state' => self::NOT_CONNECTED, 'detail' => $detail];
        }

        $owner = self::owner($ctx, $row);

        if ($live) {
            if (!$wants) {
                return self::shouldRelease($row) ? ['action' => 'cancel'] : ['action' => 'none'];
            }
            if ($owner['uuid'] !== $entryOwner) {
                // Reassigned, or nobody's any more: out of the old diary first.
                return ['action' => 'cancel'];
            }
            $confirmed = Clock::parse(self::str($row['calendar_due_at'] ?? null));
            if ($confirmed === null || $confirmed->getTimestamp() !== $due->getTimestamp()) {
                return ['action' => 'update', 'due' => $due];
            }

            return ['action' => 'none'];
        }

        // Nothing holds the time.
        if ($owner['uuid'] === null) {
            return $state === self::FAILED && ($row['calendar_detail'] ?? null) === $owner['problem']
                ? ['action' => 'none']
                : ['action' => 'note', 'state' => self::FAILED, 'detail' => $owner['problem']];
        }

        // A first entry; one the person just asked for again; or the second
        // half of a reassign, whose old entry is now cancelled in the old diary.
        // NOT an entry Calendar told us was cancelled, with nothing changed: a
        // cancelled entry is not brought back behind anybody's back.
        $fresh = $state === null || $state === self::NOT_CONNECTED || $relevant
            || ($state === self::CANCELLED && $entryOwner !== $owner['uuid']);

        return $fresh ? ['action' => 'create', 'owner' => $owner['uuid'], 'due' => $due] : ['action' => 'none'];
    }

    /**
     * Whose diary: the assigned agent's, else the creator's when the creator
     * is a person Calendar knows.
     *
     * @param array<string, mixed> $row needs assigned_agent_id and created_by
     * @return array{uuid: ?string, problem: ?string}
     */
    private static function owner(Context $ctx, array $row): array
    {
        if (($row['assigned_agent_id'] ?? null) !== null) {
            $agent = Db::first(
                'SELECT user_uuid FROM voice_agents WHERE agent_id = :id AND cmp_id = :cmp',
                ['id' => (int) $row['assigned_agent_id'], 'cmp' => $ctx->cmpId],
            );
            if ($agent === null) {
                return ['uuid' => null, 'problem' => 'No diary entry: the assigned agent is not set up in Voice for this company.'];
            }
            $uuid = trim((string) $agent['user_uuid']);

            return CalendarClient::isSubscriberId($uuid)
                ? ['uuid' => $uuid, 'problem' => null]
                : ['uuid' => null, 'problem' => 'No diary entry: the assigned agent is not linked to an AICOUNTLY account that Calendar recognises.'];
        }

        $creator = trim((string) ($row['created_by'] ?? ''));

        return CalendarClient::isSubscriberId($creator)
            ? ['uuid' => $creator, 'problem' => null]
            : ['uuid' => null, 'problem' => 'No diary entry: nobody to put it in. Assign an agent — this callback was not created by a person, so it has no diary of its own.'];
    }

    /**
     * A callback that no longer needs its entry: cancel it while it still
     * holds future time. A completed or abandoned callback's PAST entry is
     * left as the record of time the agent held; a cancelled callback's entry
     * is always cancelled, because it never happened.
     *
     * @param array<string, mixed> $row
     */
    private static function shouldRelease(array $row): bool
    {
        if ((string) $row['status'] === 'cancelled' || !self::bool($row['calendar_requested'] ?? false)) {
            return true;
        }

        $slot = Clock::parse(self::str($row['calendar_due_at'] ?? null)) ?? Clock::parse(self::str($row['due_at'] ?? null));

        return $slot === null || $slot->modify('+' . self::SLOT_MINUTES . ' minutes') > Clock::now();
    }

    /**
     * Is a deferred attempt still what the callback needs?
     *
     * @param array<string, mixed> $row
     * @param array<string, mixed> $op
     */
    private static function stillWanted(Context $ctx, array $row, array $op): bool
    {
        $due = Clock::parse(self::str($row['due_at'] ?? null));
        $wants = self::bool($row['calendar_requested'] ?? false) && $due !== null
            && in_array((string) $row['status'], self::ACTIVE, true);
        $owner = self::owner($ctx, $row)['uuid'];
        $body = Db::jsonColumn($op['request_body'] ?? null);
        $sameTime = $due !== null && Clock::parse((string) ($body['start_at'] ?? '')) == $due;

        return match ((string) $op['operation']) {
            'create_event' => $wants && $owner === $op['owner_uuid'] && $sameTime
                && isset($body['conflict_policy']) === self::bool($row['exact_time'] ?? false),
            'update_event' => $wants && $owner === $op['owner_uuid'] && $sameTime
                && self::str($row['calendar_event_ref'] ?? null) === self::eventIdOf($op),
            default        => !($wants && $owner === $op['owner_uuid']),
        };
    }

    // -----------------------------------------------------------------------
    // Writing
    // -----------------------------------------------------------------------

    /**
     * @param array<string, mixed> $row
     * @param array<string, mixed> $plan
     */
    private static function perform(Context $ctx, array $row, array $plan, ?string $actor): void
    {
        $callbackId = (int) $row['callback_id'];

        switch ($plan['action']) {
            case 'note':
                self::save($ctx->cmpId, $callbackId, [
                    'calendar_state'  => $plan['state'],
                    'calendar_detail' => $plan['detail'],
                ]);

                return;

            case 'create':
                $seq = (int) $row['calendar_ref_seq'] + 1;
                $ref = $seq === 1 ? (string) $callbackId : $callbackId . ':' . $seq;
                // A new entry takes over the reference. The one before it is
                // cancelled history, kept in the operation log, never addressed
                // again — and its source_ref is never reused.
                self::save($ctx->cmpId, $callbackId, [
                    'calendar_ref_seq'    => $seq,
                    'calendar_source_ref' => $ref,
                    'calendar_owner_uuid' => $plan['owner'],
                    'calendar_event_ref'  => null,
                    'calendar_version'    => null,
                    'calendar_due_at'     => null,
                ]);
                self::attempt($ctx, $callbackId, 'create_event', (string) $plan['owner'], 'POST',
                    CalendarClient::eventsPath(), self::createBody($ctx, $row, $plan['due'], $ref), null, $ref, $actor);

                return;

            case 'update':
                $body = [
                    'start_at' => Clock::iso($plan['due']),
                    'end_at'   => Clock::iso($plan['due']->modify('+' . self::SLOT_MINUTES . ' minutes')),
                ];
                if (self::bool($row['exact_time'] ?? false)) {
                    $body['conflict_policy'] = 'reject';
                }
                ksort($body);
                $version = $row['calendar_version'] === null ? null : (int) $row['calendar_version'];
                self::attempt($ctx, $callbackId, 'update_event', (string) $row['calendar_owner_uuid'], 'PATCH',
                    CalendarClient::eventPath((string) $row['calendar_event_ref']), $body,
                    CalendarClient::ifMatch($version), (string) $row['calendar_source_ref'], $actor);

                return;

            case 'cancel':
                self::attempt($ctx, $callbackId, 'cancel_event', (string) $row['calendar_owner_uuid'], 'PATCH',
                    CalendarClient::eventPath((string) $row['calendar_event_ref']), ['status' => 'cancelled'],
                    null, (string) $row['calendar_source_ref'], $actor);

                return;
        }
    }

    /**
     * The entry's body: a time and nothing about the customer.
     *
     * Keys are sorted so the stored request, read back from jsonb, is the same
     * request byte for byte when the attempt is resent under its key.
     *
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    private static function createBody(Context $ctx, array $row, \DateTimeImmutable $due, string $sourceRef): array
    {
        $id = (int) $row['callback_id'];
        $body = [
            'title'       => 'Callback · #' . $id,
            'description' => 'Voice callback #' . $id . '. The details are in Aicountly Voice, under Callbacks.',
            'start_at'    => Clock::iso($due),
            'end_at'      => Clock::iso($due->modify('+' . self::SLOT_MINUTES . ' minutes')),
            'timezone'    => self::companyZone($ctx)->getName(),
            'category'    => 'meeting',
            'priority'    => in_array($row['priority'] ?? '', ['high', 'low'], true) ? (string) $row['priority'] : 'normal',
            'status'      => 'confirmed',
            'busy_status' => 'busy',
            'visibility'  => 'busy_only',
            'source_app'  => CalendarClient::SOURCE_APP,
            'source_ref'  => $sourceRef,
        ];
        // The caller was promised this time: Calendar refuses the entry rather
        // than double-book the person (409 slot_taken), and the person is told.
        if (self::bool($row['exact_time'] ?? false)) {
            $body['conflict_policy'] = 'reject';
        }
        ksort($body);

        return $body;
    }

    /**
     * One attempt of one change: recorded, then sent, then settled.
     *
     * @param array<string, mixed> $body
     */
    private static function attempt(
        Context $ctx,
        int $callbackId,
        string $operation,
        string $owner,
        string $method,
        string $path,
        array $body,
        ?string $ifMatch,
        string $sourceRef,
        ?string $actor,
    ): void {
        $opened = ExternalOperations::begin(
            $ctx,
            'calendar',
            $operation,
            ['kind' => 'callback', 'starts_at' => $body['start_at'] ?? null],
            ['callback_id' => $callbackId],
            $actor,
            null,
            [
                'owner_uuid'     => $owner,
                'source_ref'     => $sourceRef,
                'request_method' => $method,
                'request_path'   => $path,
                'request_body'   => $body,
                'if_match'       => $ifMatch,
                // Not before this request could have finished: the reconciler
                // must never race a write that is still in flight.
                'next_check_at'  => Clock::sql(Clock::now()->modify('+2 minutes')),
            ],
        );
        self::save($ctx->cmpId, $callbackId, [
            'calendar_state'  => self::PENDING,
            'calendar_detail' => 'Sent to Aicountly Calendar; waiting for its answer.',
        ]);

        $op = (array) Db::first('SELECT * FROM ' . ExternalOperations::TABLE . ' WHERE operation_id = :id', [
            'id' => $opened['operation_id'],
        ]);
        $result = (new CalendarClient())
            ->forSubscriber($owner)
            ->forCompany($ctx->cmpId)
            ->write($method, $path, $body, $opened['correlation_id'], $ifMatch);

        self::settle($op, $result);
    }

    /**
     * The same attempt again: same key, same body, same precondition.
     *
     * @param array<string, mixed> $op
     */
    private static function resend(array $op): void
    {
        Db::update(ExternalOperations::TABLE, [
            'attempts'        => (int) $op['attempts'] + 1,
            'last_attempt_at' => Clock::sql(Clock::now()),
        ], ['operation_id' => (int) $op['operation_id']]);

        $body = Db::jsonColumn($op['request_body'] ?? null);
        ksort($body);
        $result = (new CalendarClient())
            ->forSubscriber((string) $op['owner_uuid'])
            ->forCompany((int) $op['cmp_id'])
            ->write((string) $op['request_method'], (string) $op['request_path'], $body,
                (string) $op['idempotency_key'], self::str($op['if_match'] ?? null));

        $op['attempts'] = (int) $op['attempts'] + 1;
        self::settle($op, $result);
    }

    /**
     * Read the answer, ask Calendar when the answer alone cannot settle it,
     * and record what is now known.
     *
     * @param array<string, mixed> $op
     * @param array{ok:bool, status:int, body:?array, error:?string} $result
     */
    private static function settle(array $op, array $result): void
    {
        $verdict = self::classify($op, $result);

        // "Not found" counts as gone only once the lookup — as the owner —
        // agrees (contract §12.5); a version conflict that did not say what the
        // event now is gets read.
        if ($verdict['outcome'] === 'not_found'
            || ($verdict['outcome'] === 'version_conflict' && $verdict['event'] === null)) {
            $found = self::lookupEvent($op);
            if (!$found['ok']) {
                $verdict = ['outcome' => 'unknown', 'code' => $verdict['code'], 'event' => null,
                    'detail' => 'Calendar could not be asked what it holds: ' . $found['error']];
            } elseif ($found['event'] === null) {
                $verdict = ['outcome' => 'gone', 'code' => 'event_not_found', 'event' => null, 'detail' => 'Calendar holds no such entry for this person.'];
            } elseif ($verdict['outcome'] === 'not_found') {
                $verdict = $found['event']['status'] === 'cancelled'
                    ? ['outcome' => 'gone', 'code' => 'event_not_found', 'event' => $found['event'], 'detail' => 'Calendar holds this entry as cancelled.']
                    : ['outcome' => 'unknown', 'code' => 'event_not_found', 'event' => null,
                        'detail' => 'Calendar answered "not found" for an entry its own lookup still lists.'];
            } else {
                $verdict['event'] = $found['event'];
            }
        }

        self::apply($op, $verdict, (int) $result['status']);
    }

    /**
     * Record what an answer established — on the operation and on the
     * callback, together.
     *
     * @param array<string, mixed> $op
     * @param array{outcome: string, code: string, event: ?array<string, mixed>, detail: string} $verdict
     */
    private static function apply(array $op, array $verdict, int $httpStatus): void
    {
        $operationId = (int) $op['operation_id'];
        $cmpId = (int) $op['cmp_id'];
        $callbackId = (int) $op['callback_id'];
        $kind = (string) $op['operation'];
        $event = $verdict['event'];
        $http = $httpStatus > 0 ? $httpStatus : null;
        $row = Db::first('SELECT * FROM voice_callbacks WHERE callback_id = :id AND cmp_id = :cmp', ['id' => $callbackId, 'cmp' => $cmpId]) ?? [];
        $ctx = Context::forCompany($cmpId);

        $outcome = $verdict['outcome'];
        // Answers that do not belong to this kind of write are faults, not news.
        if (($kind === 'create_event' && in_array($outcome, ['version_conflict', 'cancelled', 'not_found', 'gone'], true))
            || ($kind === 'cancel_event' && in_array($outcome, ['refused', 'adopt', 'version_conflict'], true))) {
            $outcome = 'bug';
        }
        if ($kind === 'cancel_event' && $outcome === 'cancelled') {
            $outcome = 'settled';
        }

        $done = static fn (array $values): array => $values + ['http_status' => $http, 'next_check_at' => null];
        $failure = static fn (string $code, string $message): array => ['error_code' => $code, 'error_message' => mb_substr($message, 0, 300)];

        Db::transaction(static function () use ($op, $verdict, $outcome, $kind, $event, $operationId, $cmpId, $callbackId, $row, $ctx, $http, $done, $failure): void {
            switch ($outcome) {
                case 'settled':
                case 'adopt':
                    if ($event === null) {
                        break;
                    }
                    if ($event['status'] === 'cancelled' && $kind !== 'cancel_event') {
                        ExternalOperations::mark($operationId, ExternalOperations::FAILED, $done($failure('source_ref_cancelled',
                            'Calendar holds this entry as cancelled.') + ['external_ref' => $event['id'], 'external_version' => $event['version']]));
                        self::save($cmpId, $callbackId, self::entryColumns($op, $event) + [
                            'calendar_state'  => self::CANCELLED,
                            'calendar_detail' => 'This callback’s diary entry had been cancelled in Aicountly Calendar. Voice does not bring a cancelled entry back; change the callback’s time or agent to add a new one.',
                        ]);
                        break;
                    }
                    ExternalOperations::mark($operationId, ExternalOperations::SUCCEEDED, $done([
                        'external_ref'     => $event['id'],
                        'external_version' => $event['version'],
                        'error_code'       => null,
                        'error_message'    => null,
                    ]));
                    if ($kind === 'cancel_event') {
                        self::save($cmpId, $callbackId, [
                            'calendar_version' => $event['version'],
                            'calendar_state'   => self::CANCELLED,
                            'calendar_detail'  => 'Voice cancelled this callback’s diary entry in Aicountly Calendar.',
                        ]);
                        break;
                    }
                    $linked = self::entryColumns($op, $event) + ['calendar_state' => self::LINKED];
                    self::save($cmpId, $callbackId, $linked + ['calendar_detail' => self::linkedText(array_merge($row, $linked), $ctx)]);
                    break;

                case 'gone':
                    if ($kind === 'cancel_event') {
                        // Released: the lookup, as the owner, confirms it is
                        // cancelled or absent (contract §12.5).
                        ExternalOperations::mark($operationId, ExternalOperations::SUCCEEDED, $done([
                            'external_ref'     => $event['id'] ?? self::eventIdOf($op),
                            'external_version' => $event['version'] ?? null,
                            'error_code'       => null,
                            'error_message'    => null,
                        ]));
                        self::save($cmpId, $callbackId, [
                            'calendar_state'  => self::CANCELLED,
                            'calendar_detail' => 'Voice cancelled this callback’s diary entry in Aicountly Calendar.',
                        ]);
                        break;
                    }
                    ExternalOperations::mark($operationId, ExternalOperations::FAILED, $done($failure('event_gone', $verdict['detail'])));
                    self::save($cmpId, $callbackId, [
                        'calendar_state'  => self::CANCELLED,
                        'calendar_detail' => 'This callback’s diary entry is no longer in Aicountly Calendar. Change the callback’s time or agent to add a new one.',
                    ]);
                    break;

                case 'cancelled':
                    ExternalOperations::mark($operationId, ExternalOperations::FAILED, $done($failure('event_cancelled', $verdict['detail'])));
                    self::save($cmpId, $callbackId, ($event === null ? [] : ['calendar_version' => $event['version']]) + [
                        'calendar_state'  => self::CANCELLED,
                        'calendar_detail' => 'This callback’s diary entry had been cancelled in Aicountly Calendar, so it could not be changed. Voice does not bring a cancelled entry back.',
                    ]);
                    break;

                case 'version_conflict':
                    // Someone changed the entry since Voice last saw it. Take
                    // Calendar's current version and time; the next step of the
                    // sync decides again from there, under a new key.
                    ExternalOperations::mark($operationId, ExternalOperations::FAILED, $done($failure('version_conflict', $verdict['detail'])));
                    if ($event !== null && $event['status'] === 'cancelled') {
                        self::save($cmpId, $callbackId, ['calendar_version' => $event['version'], 'calendar_state' => self::CANCELLED,
                            'calendar_detail' => 'This callback’s diary entry had been cancelled in Aicountly Calendar. Voice does not bring a cancelled entry back.']);
                    } elseif ($event !== null) {
                        $linked = self::entryColumns($op, $event) + ['calendar_state' => self::LINKED];
                        self::save($cmpId, $callbackId, $linked + ['calendar_detail' => self::linkedText(array_merge($row, $linked), $ctx)]);
                    }
                    break;

                case 'refused':
                    ExternalOperations::mark($operationId, ExternalOperations::FAILED, $done($failure($verdict['code'], $verdict['detail'])));
                    $whose = self::whose($ctx, $row, (string) $op['owner_uuid']);
                    $why = $verdict['code'] === 'availability_unverified'
                        ? 'Aicountly Calendar could not confirm that ' . $whose . ' is free then (a connected external calendar has not synced recently), and unknown is not free.'
                        : 'Aicountly Calendar shows ' . $whose . ' as busy then.';
                    self::save($cmpId, $callbackId, [
                        'calendar_state'  => self::REFUSED,
                        'calendar_detail' => $kind === 'update_event'
                            ? 'The diary entry was not moved: ' . $why . ' It still shows the previous time.'
                            : 'No diary entry was made: ' . $why,
                    ]);
                    break;

                case 'in_progress':
                    ExternalOperations::mark($operationId, ExternalOperations::PENDING, [
                        'http_status'   => $http,
                        'error_code'    => $verdict['code'],
                        'error_message' => $verdict['detail'],
                        'next_check_at' => Clock::sql(Clock::now()->modify('+1 minute')),
                    ]);
                    self::save($cmpId, $callbackId, [
                        'calendar_state'  => self::PENDING,
                        'calendar_detail' => 'Aicountly Calendar is still working on this change. Voice asks again shortly.',
                    ]);
                    break;

                case 'config':
                    error_log('[calendar] diary write refused before it ran: ' . $verdict['code'] . ' (operation ' . $operationId . ')');
                    ExternalOperations::mark($operationId, ExternalOperations::DEFERRED, [
                        'http_status'   => $http,
                        'error_code'    => $verdict['code'],
                        'error_message' => mb_substr($verdict['detail'], 0, 300),
                        'next_check_at' => Clock::sql(Clock::now()->modify('+' . min(240, 5 * max(1, (int) $op['attempts'])) . ' minutes')),
                    ]);
                    self::save($cmpId, $callbackId, [
                        'calendar_state'  => self::DEFERRED,
                        'calendar_detail' => 'Aicountly Calendar has not accepted Voice’s request yet (' . $verdict['code']
                            . '), so nothing was written there. Voice sends the same request again later; an administrator may need to check the Calendar connection.',
                    ]);
                    break;

                case 'unknown':
                    ExternalOperations::mark($operationId, ExternalOperations::UNKNOWN, [
                        'http_status'   => $http,
                        'error_code'    => $verdict['code'],
                        'error_message' => mb_substr($verdict['detail'], 0, 300),
                        'next_check_at' => Clock::sql(Clock::now()->modify('+2 minutes')),
                    ]);
                    self::save($cmpId, $callbackId, [
                        'calendar_state'  => self::UNKNOWN,
                        'calendar_detail' => 'Sent to Aicountly Calendar, which has not confirmed it yet. Voice is checking with Calendar — do not add it to the diary by hand.',
                    ]);
                    break;

                default: // bug
                    error_log('[calendar] diary write rejected: ' . $verdict['code'] . ' (operation ' . $operationId . ')');
                    ExternalOperations::mark($operationId, ExternalOperations::FAILED, $done($failure($verdict['code'], $verdict['detail'])));
                    self::save($cmpId, $callbackId, [
                        'calendar_state'  => self::FAILED,
                        'calendar_detail' => ($kind === 'cancel_event'
                            ? 'Aicountly Calendar refused to cancel the diary entry (' . $verdict['code'] . '), so it still holds the time.'
                            : 'Aicountly Calendar rejected the diary entry (' . $verdict['code'] . ').')
                            . ' This is a fault in Voice’s request and it has been logged.',
                    ]);
                    break;
            }
        });
    }

    /**
     * The reference columns for an event Calendar returned.
     *
     * @param array<string, mixed> $op
     * @param array<string, mixed> $event
     * @return array<string, mixed>
     */
    private static function entryColumns(array $op, array $event): array
    {
        $start = Clock::parse($event['start_at']);

        return [
            'calendar_event_ref'  => $event['id'],
            'calendar_owner_uuid' => (string) $op['owner_uuid'],
            'calendar_source_ref' => $event['source_ref'],
            'calendar_version'    => $event['version'],
            'calendar_due_at'     => $start === null ? null : Clock::sql($start),
        ];
    }

    // -----------------------------------------------------------------------
    // Asking Calendar
    // -----------------------------------------------------------------------

    /**
     * What Calendar holds under the operation's source_ref, asked as the owner
     * it was written for.
     *
     * @param array<string, mixed> $op
     * @return array{ok: bool, event: ?array<string, mixed>, error: string}
     */
    private static function lookupEvent(array $op): array
    {
        $ref = (string) $op['source_ref'];
        $result = (new CalendarClient())
            ->forSubscriber((string) $op['owner_uuid'])
            ->forCompany((int) $op['cmp_id'])
            ->lookup($ref);

        if (!$result['ok'] || ($result['body']['success'] ?? null) !== true) {
            $code = (string) ($result['body']['code'] ?? '');

            return ['ok' => false, 'event' => null, 'error' => $result['status'] === 0
                ? 'no answer'
                : 'HTTP ' . $result['status'] . ($code !== '' ? ' ' . $code : '')];
        }

        $events = $result['body']['data']['events'] ?? null;
        if (!is_array($events) || !array_is_list($events) || count($events) > 1) {
            return ['ok' => false, 'event' => null, 'error' => 'the lookup did not answer with 0 or 1 events'];
        }
        if ($events === []) {
            return ['ok' => true, 'event' => null, 'error' => ''];
        }

        $event = self::eventIn(['data' => ['event' => $events[0]]]);
        if ($event === null || $event['source_ref'] !== $ref) {
            return ['ok' => false, 'event' => null, 'error' => 'the lookup returned an event Voice cannot match'];
        }

        return ['ok' => true, 'event' => $event, 'error' => ''];
    }

    /**
     * `data.event`, if it is one: a non-empty id and an integer version.
     *
     * @param array<string, mixed>|null $body
     * @return array{id: string, version: int, source_ref: string, status: string, start_at: string, end_at: string}|null
     */
    private static function eventIn(?array $body): ?array
    {
        $event = $body['data']['event'] ?? null;
        if (!is_array($event)) {
            return null;
        }
        $id = $event['id'] ?? null;
        $version = $event['version'] ?? null;
        if (!is_string($id) || trim($id) === '' || !is_int($version) || $version < 1) {
            return null;
        }

        return [
            'id'         => $id,
            'version'    => $version,
            'source_ref' => (string) ($event['source_ref'] ?? ''),
            'status'     => (string) ($event['status'] ?? ''),
            'start_at'   => (string) ($event['start_at'] ?? ''),
            'end_at'     => (string) ($event['end_at'] ?? ''),
        ];
    }

    /**
     * Does the event already say what this update asked for?
     *
     * @param array<string, mixed> $op
     * @param array<string, mixed> $event
     */
    private static function matchesRequest(array $op, array $event): bool
    {
        $body = Db::jsonColumn($op['request_body'] ?? null);
        foreach (['start_at', 'end_at'] as $field) {
            if (!isset($body[$field])) {
                continue;
            }
            $wanted = Clock::parse((string) $body[$field]);
            $held = Clock::parse($event[$field]);
            if ($wanted === null || $held === null || $wanted->getTimestamp() !== $held->getTimestamp()) {
                return false;
            }
        }

        return $event['id'] === self::eventIdOf($op);
    }

    /** @param array<string, mixed> $op */
    private static function eventIdOf(array $op): ?string
    {
        $path = (string) ($op['request_path'] ?? '');
        $prefix = CalendarClient::eventsPath() . '/';

        return str_starts_with($path, $prefix) ? rawurldecode(substr($path, strlen($prefix))) : null;
    }

    // -----------------------------------------------------------------------
    // Bookkeeping and words
    // -----------------------------------------------------------------------

    /** @return array<string, mixed>|null */
    private static function openOperation(int $callbackId): ?array
    {
        return Db::first(
            'SELECT operation_id FROM ' . ExternalOperations::TABLE . '
              WHERE callback_id = :id AND target_app = :app
                AND status IN (:pending, :unknown, :deferred)
              LIMIT 1',
            ['id' => $callbackId, 'app' => 'calendar', 'pending' => self::OPEN[0], 'unknown' => self::OPEN[1], 'deferred' => self::OPEN[2]],
        );
    }

    /** @param array<string, mixed> $values */
    private static function save(int $cmpId, int $callbackId, array $values): void
    {
        Db::update('voice_callbacks', $values + [
            'calendar_checked_at' => Clock::sql(Clock::now()),
            'updated_at'          => Clock::sql(Clock::now()),
        ], ['callback_id' => $callbackId, 'cmp_id' => $cmpId]);
    }

    /**
     * @param array<string, mixed> $row
     * @return array{state: string, detail: ?string}
     */
    private static function describe(array $row): array
    {
        $state = self::str($row['calendar_state'] ?? null);

        if (self::str($row['calendar_event_ref'] ?? null) !== null && self::str($row['calendar_owner_uuid'] ?? null) === null) {
            return ['state' => 'legacy', 'detail' => 'This diary entry was written before Voice recorded whose diary it is in, so Voice cannot check, move or cancel it. Look for it in Aicountly Calendar.'];
        }
        if ($state === null) {
            return self::bool($row['calendar_requested'] ?? false) && self::str($row['due_at'] ?? null) === null
                ? ['state' => 'none', 'detail' => 'It has no due time, so nothing was put in a diary.']
                : ['state' => 'none', 'detail' => null];
        }

        return ['state' => $state, 'detail' => self::str($row['calendar_detail'] ?? null)];
    }

    /**
     * What the person who just made a change should be told: nothing when the
     * entry is as they asked, the plain state otherwise.
     *
     * @param array<string, mixed> $row
     * @return array{state: string, message: ?string}
     */
    private static function outcome(array $row): array
    {
        $described = self::describe($row);

        return [
            'state'   => $described['state'],
            'message' => $described['state'] === self::LINKED ? null : $described['detail'],
        ];
    }

    /** @param array<string, mixed> $row */
    private static function linkedText(array $row, ?Context $ctx = null): string
    {
        $whose = $ctx === null ? 'the diary it was written to' : self::whose($ctx, $row, self::str($row['calendar_owner_uuid'] ?? null));

        return 'In ' . $whose . ' in Aicountly Calendar as a ' . self::SLOT_MINUTES . '-minute busy entry, “Callback · #'
            . (int) ($row['callback_id'] ?? 0) . '”. Voice moves or cancels it when this callback changes.';
    }

    /** @param array<string, mixed> $row */
    private static function whose(Context $ctx, array $row, ?string $owner): string
    {
        if ($owner !== null && $row !== [] && self::owner($ctx, $row)['uuid'] === $owner) {
            return ($row['assigned_agent_id'] ?? null) !== null
                ? 'the assigned agent’s diary'
                : 'the diary of the person who created this callback';
        }

        return 'the diary it was written to';
    }

    private static function companyZone(Context $ctx): \DateTimeZone
    {
        return Clock::zone((string) (Settings::forCompany($ctx->cmpId)['timezone'] ?? ''));
    }

    private static function str(mixed $value): ?string
    {
        if (!is_scalar($value)) {
            return null;
        }
        $value = trim((string) $value);

        return $value === '' ? null : $value;
    }

    private static function bool(mixed $value): bool
    {
        if (is_bool($value)) {
            return $value;
        }

        return in_array(strtolower(trim((string) $value)), ['1', 't', 'true', 'yes'], true);
    }
}
