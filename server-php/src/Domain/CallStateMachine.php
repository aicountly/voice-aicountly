<?php

declare(strict_types=1);

namespace Aicountly\Api\Domain;

use Aicountly\Api\Db;
use Aicountly\Api\Support\Clock;

/**
 * The one place a call's state changes.
 *
 * ## Why this is centralised
 *
 * Call state arrives from a carrier, over the internet, from several machines,
 * with no ordering guarantee and with retries. Three things follow, and each of
 * them has broken a telephony product somewhere:
 *
 *  1. THE SAME EVENT ARRIVES TWICE. Carriers retry on any non-2xx, and
 *     sometimes on a 2xx they did not see. Handled by the unique index on
 *     (connection_id, provider_event_id): the second insert conflicts and the
 *     event is a no-op.
 *
 *  2. EVENTS ARRIVE OUT OF ORDER. `ringing` can land after `completed` — the
 *     two were emitted a millisecond apart by different nodes and took
 *     different paths. Applying it would resurrect a finished call, and the
 *     agent's console would show a call that ended ten minutes ago as ringing.
 *     Handled by TERMINAL: nothing leaves a terminal state.
 *
 *  3. A STATE GOES BACKWARDS. `queued` after `answered` is not progress. The
 *     RANK table below gives every state a position and refuses a move
 *     backwards unless it is one of the legitimate ones (answered → held →
 *     answered, answered → transferring).
 *
 * ## What is NOT a source of state
 *
 * A browser timer. The console shows a duration counted locally because that is
 * what feels responsive, but the STATE it labels comes from here. A call whose
 * events have stopped arriving is marked stale and says so — it does not
 * silently keep counting as though it were still connected.
 *
 * Every transition runs inside a transaction with the row locked, because two
 * carrier callbacks for the same call can land on two PHP workers at the same
 * millisecond.
 */
final class CallStateMachine
{
    /** Once here, a call is over. No event moves it again. */
    public const TERMINAL = ['completed', 'busy', 'unanswered', 'cancelled', 'failed'];

    /**
     * How far through a call each state is.
     *
     * Used to reject a move backwards. Terminal states share the top rank
     * because which one a call ended in is decided by the first terminal event
     * to arrive, not by ordering between them.
     */
    private const RANK = [
        'unknown'      => 0,
        'initiated'    => 1,
        'queued'       => 2,
        'ringing'      => 3,
        'answered'     => 4,
        'held'         => 4,
        'transferring' => 4,
        'completed'    => 9,
        'busy'         => 9,
        'unanswered'   => 9,
        'cancelled'    => 9,
        'failed'       => 9,
    ];

    /**
     * Moves that are legitimate even though they do not advance the rank.
     *
     * A call comes off hold and is answered again; a transfer completes and the
     * call is answered by somebody else. Both are normal and neither is
     * progress in the ranking sense.
     *
     * @var array<string, list<string>>
     */
    private const LATERAL = [
        'answered'     => ['held', 'transferring'],
        'held'         => ['answered', 'transferring'],
        'transferring' => ['answered', 'held'],
    ];

    /** A call with no event for this long can no longer be asserted to be live. */
    private const STALE_AFTER_SECONDS = 120;

    public static function isTerminal(string $state): bool
    {
        return in_array($state, self::TERMINAL, true);
    }

    /**
     * Would this state change be accepted?
     *
     * Exposed for tests and for the recovery worker, which needs to ask without
     * writing.
     *
     * @return array{allowed: bool, reason: ?string}
     */
    public static function evaluate(string $from, string $to): array
    {
        if (!isset(self::RANK[$to])) {
            return ['allowed' => false, 'reason' => 'unknown_state'];
        }
        if ($from === $to) {
            return ['allowed' => false, 'reason' => 'no_change'];
        }
        if (self::isTerminal($from)) {
            // The single most important rule here.
            return ['allowed' => false, 'reason' => 'terminal'];
        }
        if (in_array($to, self::LATERAL[$from] ?? [], true)) {
            return ['allowed' => true, 'reason' => null];
        }
        if (self::RANK[$to] < self::RANK[$from]) {
            return ['allowed' => false, 'reason' => 'out_of_order'];
        }

        return ['allowed' => true, 'reason' => null];
    }

    /**
     * Record a provider event and apply it if it should be applied.
     *
     * Returns what happened, so the webhook controller can answer 200 in every
     * case — a carrier that gets a 500 because an event was a duplicate will
     * send it again, and again.
     *
     * @param array{
     *   provider_event_id:string, event_type:string, provider_ref:?string,
     *   leg_ref:?string, state:?string, timestamp:?string, sequence:?int,
     *   detail:array<string, mixed>
     * } $event
     * @return array{outcome: string, call_id: ?int, state: ?string, reason: ?string}
     */
    public static function applyProviderEvent(int $cmpId, int $connectionId, ?int $callId, array $event): array
    {
        // Record first, always. Even an event we will not apply is evidence of
        // what the carrier told us and when.
        $eventId = self::recordEvent($cmpId, $connectionId, $callId, $event);

        if ($eventId === null) {
            return ['outcome' => 'duplicate', 'call_id' => $callId, 'state' => null, 'reason' => 'already_seen'];
        }

        if ($callId === null) {
            self::markSkipped($eventId, 'unknown_call');

            return ['outcome' => 'orphan', 'call_id' => null, 'state' => null, 'reason' => 'unknown_call'];
        }

        $target = $event['state'] ?? null;
        if ($target === null) {
            // A recognised event that carries no state change — a recording
            // became available, a DTMF digit was pressed. Applied by whoever
            // handles that event type, not by the state machine.
            self::markApplied($eventId);

            return ['outcome' => 'recorded', 'call_id' => $callId, 'state' => null, 'reason' => null];
        }

        return Db::transaction(static function () use ($callId, $cmpId, $target, $event, $eventId): array {
            // FOR UPDATE: two callbacks for one call can land on two workers at
            // the same moment. Without the lock both read 'ringing', both decide
            // their transition is fine, and the later write wins by accident.
            $row = Db::first(
                'SELECT call_id, state, answered_at, initiated_at
                   FROM voice_calls
                  WHERE call_id = :id AND cmp_id = :cmp
                  FOR UPDATE',
                ['id' => $callId, 'cmp' => $cmpId],
            );

            if ($row === null) {
                self::markSkipped($eventId, 'unknown_call');

                return ['outcome' => 'orphan', 'call_id' => null, 'state' => null, 'reason' => 'unknown_call'];
            }

            $current = (string) $row['state'];
            $verdict = self::evaluate($current, $target);

            if (!$verdict['allowed']) {
                self::markSkipped($eventId, $verdict['reason']);

                return [
                    'outcome' => 'skipped',
                    'call_id' => $callId,
                    'state'   => $current,
                    'reason'  => $verdict['reason'],
                ];
            }

            $now = Clock::now();
            $values = [
                'state'          => $target,
                'state_reason'   => $event['event_type'],
                'state_stale_at' => null,
                'updated_at'     => Clock::sql($now),
            ];

            if ($target === 'answered' && $row['answered_at'] === null) {
                $values['answered_at'] = Clock::sql($now);
            }

            if (self::isTerminal($target)) {
                $values['ended_at'] = Clock::sql($now);
                $values['outcome'] = self::outcomeFor($target, $row, $event);
                $values['abandoned'] = ($target === 'unanswered' && $row['answered_at'] === null)
                    || ($target === 'cancelled' && $row['answered_at'] === null) ? 'true' : 'false';

                $started = Clock::parse((string) $row['initiated_at']) ?? $now;
                $values['total_seconds'] = max(0, $now->getTimestamp() - $started->getTimestamp());
                if ($row['answered_at'] !== null) {
                    $answered = Clock::parse((string) $row['answered_at']) ?? $now;
                    $values['talk_seconds'] = max(0, $now->getTimestamp() - $answered->getTimestamp());
                }
            }

            Db::update('voice_calls', $values, ['call_id' => $callId]);
            self::markApplied($eventId);

            return ['outcome' => 'applied', 'call_id' => $callId, 'state' => $target, 'reason' => null];
        });
    }

    /**
     * Move a call because THIS product decided to, not because a carrier said so.
     *
     * Used when an agent hangs up through the console and the adapter confirms
     * it. Same rules, same locking.
     *
     * @return array{applied: bool, state: string, reason: ?string}
     */
    public static function transition(int $cmpId, int $callId, string $target, string $reason): array
    {
        return Db::transaction(static function () use ($cmpId, $callId, $target, $reason): array {
            $row = Db::first(
                'SELECT state, answered_at, initiated_at FROM voice_calls
                  WHERE call_id = :id AND cmp_id = :cmp FOR UPDATE',
                ['id' => $callId, 'cmp' => $cmpId],
            );
            if ($row === null) {
                return ['applied' => false, 'state' => 'unknown', 'reason' => 'not_found'];
            }

            $current = (string) $row['state'];
            $verdict = self::evaluate($current, $target);
            if (!$verdict['allowed']) {
                return ['applied' => false, 'state' => $current, 'reason' => $verdict['reason']];
            }

            $now = Clock::now();
            $values = [
                'state'        => $target,
                'state_reason' => $reason,
                'updated_at'   => Clock::sql($now),
            ];
            if ($target === 'answered' && $row['answered_at'] === null) {
                $values['answered_at'] = Clock::sql($now);
            }
            if (self::isTerminal($target)) {
                $values['ended_at'] = Clock::sql($now);
                $values['outcome'] = self::outcomeFor($target, $row, ['detail' => []]);
                $started = Clock::parse((string) $row['initiated_at']) ?? $now;
                $values['total_seconds'] = max(0, $now->getTimestamp() - $started->getTimestamp());
                if ($row['answered_at'] !== null) {
                    $answered = Clock::parse((string) $row['answered_at']) ?? $now;
                    $values['talk_seconds'] = max(0, $now->getTimestamp() - $answered->getTimestamp());
                }
            }

            Db::update('voice_calls', $values, ['call_id' => $callId]);

            return ['applied' => true, 'state' => $target, 'reason' => null];
        });
    }

    /**
     * Mark live calls whose provider has gone quiet.
     *
     * NOT the same as ending them. The call may well still be up; what has
     * failed is our knowledge of it. The console shows these as "state unknown"
     * so nobody reads a stale row as a live conversation. Run by
     * bin/call-recovery.php.
     *
     * @return int calls marked
     */
    public static function markStale(): int
    {
        return Db::run(
            'UPDATE voice_calls
                SET state_stale_at = NOW(), updated_at = NOW()
              WHERE ended_at IS NULL
                AND state_stale_at IS NULL
                AND updated_at < NOW() - INTERVAL \'' . self::STALE_AFTER_SECONDS . ' seconds\'',
        )->rowCount();
    }

    /**
     * Which outcome a terminal state means.
     *
     * "AI answered it" is not "AI resolved it". A call the AI handled end to end
     * is `ai_completed`; a call it passed to a person is `handover_completed`
     * however well the AI did first. The Command Centre's AI resolution rate
     * counts only the former, which is why it is a number worth reading.
     *
     * @param array<string, mixed> $row
     * @param array<string, mixed> $event
     */
    private static function outcomeFor(string $target, array $row, array $event): string
    {
        if ($target !== 'completed') {
            return match ($target) {
                'busy', 'unanswered' => 'no_answer',
                'cancelled'          => 'abandoned',
                default              => 'failed',
            };
        }

        if ($row['answered_at'] === null) {
            return 'abandoned';
        }

        $handled = (string) ($event['detail']['handled_by'] ?? '');

        return match ($handled) {
            'ai'   => 'ai_completed',
            'both' => 'handover_completed',
            default => 'human_completed',
        };
    }

    /** @return int|null the new event row id, or null when this event was already seen */
    private static function recordEvent(int $cmpId, int $connectionId, ?int $callId, array $event): ?int
    {
        $id = Db::scalar(
            'INSERT INTO voice_call_events
                (cmp_id, call_id, connection_id, provider_event_id, provider_leg_ref,
                 event_type, provider_timestamp, provider_sequence, payload)
             VALUES (:cmp, :call, :conn, :eid, :leg, :type, :ts, :seq, :payload)
             ON CONFLICT (connection_id, provider_event_id) DO NOTHING
             RETURNING event_id',
            [
                'cmp'     => $cmpId,
                'call'    => $callId,
                'conn'    => $connectionId,
                'eid'     => $event['provider_event_id'],
                'leg'     => $event['leg_ref'],
                'type'    => $event['event_type'],
                'ts'      => $event['timestamp'],
                'seq'     => $event['sequence'],
                'payload' => json_encode($event['detail'] ?? [], JSON_UNESCAPED_UNICODE),
            ],
        );

        return $id === null ? null : (int) $id;
    }

    private static function markApplied(int $eventId): void
    {
        Db::run(
            'UPDATE voice_call_events SET applied = TRUE, applied_at = NOW() WHERE event_id = :id',
            ['id' => $eventId],
        );
    }

    private static function markSkipped(int $eventId, ?string $reason): void
    {
        Db::run(
            'UPDATE voice_call_events SET applied = FALSE, skip_reason = :reason WHERE event_id = :id',
            ['id' => $eventId, 'reason' => $reason],
        );
    }
}
