<?php

declare(strict_types=1);

namespace Aicountly\Api\Domain;

use Aicountly\Api\Clients\AppointmentsClient;
use Aicountly\Api\Context;
use Aicountly\Api\Db;
use Aicountly\Api\ExternalOperations;
use Aicountly\Api\Settings;
use Aicountly\Api\Support\Clock;
use Aicountly\Api\Support\Uuid;

/**
 * An AI agent's booking, made in Aicountly Appointments — and never claimed
 * before Appointments has answered with it.
 *
 * ## The rule
 *
 * Appointments owns the booking (contract §0, §13). The agent may say "you're
 * booked" only with Appointments' own answer in hand: a 2xx whose envelope
 * carries the booking's id and reference, for exactly the service, time and
 * client Voice asked for. Everything short of that is said as what it is —
 * taken, not bookable, pending verification — and anything that needs a
 * person goes to a person (AiActionRunner arranges the callback).
 *
 * ## One intent, one key, one booking (contract §12, §14)
 *
 * The operation row is written BEFORE the request leaves, with the exact body
 * and the Idempotency-Key for this intent (one tool call). A retry of the same
 * tool call, or the recovery worker, sends that same body under that same key
 * — never a new one — so a lost answer can never become a second booking.
 *
 * An answer that does not settle it (no response, a 5xx, a 2xx without the
 * booking) is UNKNOWN, never "failed": Voice first reads back what Appointments
 * holds at that time for that service and that number, adopts the booking if it
 * is there, and only when it is not sends the same attempt again. A read-back
 * that cannot be made leaves the operation pending verification, with backoff.
 *
 * ## What is kept
 *
 * The operation (our request and Appointments' booking id as `external_ref`).
 * Never the booking: its time, status and reference are read live from
 * Appointments whenever they are needed again.
 */
final class AppointmentsBooking
{
    public const TARGET = 'appointments';
    public const OPERATION = 'create_booking';

    /** Appointments' refusals that mean "not that time": the caller is offered others. */
    private const NOT_BOOKABLE = [
        'notice_period', 'beyond_horizon', 'in_the_past', 'no_staff', 'not_offered',
        'member_not_eligible', 'mode_not_offered',
    ];

    /** Appointments' own 503s for a booking it did NOT take, and said why. */
    private const NOT_TAKEN_503 = ['calendar_unavailable', 'calendar_misconfigured', 'schema_not_ready', 'payment_unavailable'];

    private const ACTIVE = ['PENDING', 'CONFIRMED'];

    /** Alternatives offered when a time cannot be had. */
    private const ALTERNATIVES = 3;

    /** A write answered less than this long ago may still be in flight; it is not raced. */
    private const IN_FLIGHT_SECONDS = 30;

    private readonly AppointmentsClient $client;

    public function __construct(private readonly Context $ctx, ?AppointmentsClient $client = null)
    {
        $this->client = $client ?? new AppointmentsClient();
    }

    // -----------------------------------------------------------------------
    // check_availability
    // -----------------------------------------------------------------------

    /**
     * Free times, as Appointments would offer them now.
     *
     * @param array<string, mixed> $args service_uuid, member_uuid, from, to, daypart, limit
     * @return array<string, mixed>
     */
    public function availability(array $args): array
    {
        $serviceUuid = self::str($args['service_uuid'] ?? null);
        if ($serviceUuid === null) {
            return self::result('invalid', ['detail' => 'service_uuid is required.']);
        }

        $from = self::instant($args['from'] ?? null);
        $to = self::instant($args['to'] ?? null);
        $result = $this->client->slots($this->ctx->cmpId, [
            'service_uuid' => $serviceUuid,
            'member_uuid'  => self::str($args['member_uuid'] ?? null),
            'from'         => $from === null ? null : Clock::iso($from),
            'to'           => $to === null ? null : Clock::iso($to),
            'daypart'      => self::str($args['daypart'] ?? null),
            'limit'        => max(1, min(5, (int) ($args['limit'] ?? self::ALTERNATIVES))),
        ]);

        $slots = self::slotsIn($result);
        if ($slots === null) {
            return self::result('unavailable', [
                'handoff' => "I can't check availability from this call right now",
                'detail'  => 'Aicountly Appointments did not give Voice its free times: ' . self::said($result),
            ]);
        }

        if ($slots === []) {
            return self::result('no_slots', ['say' => "I can't see a free time for that."]);
        }

        return self::result('slots', [
            'slots' => $slots,
            'say'   => 'I can offer ' . self::spokenList(array_map([$this, 'slotWhen'], $slots)) . '. Which would suit you?',
        ]);
    }

    // -----------------------------------------------------------------------
    // create_booking
    // -----------------------------------------------------------------------

    /**
     * Book, once.
     *
     * @param array{correlation_id: string, call_id: ?int, actor: ?string, request: array<string, mixed>} $intent
     * @return array<string, mixed>
     */
    public function book(array $intent): array
    {
        $body = self::canonical($intent['request']);
        $existing = ExternalOperations::findByCorrelation($this->ctx, self::TARGET, $intent['correlation_id']);
        if ($existing !== null) {
            if (self::canonical(Db::jsonColumn($existing['request_body'] ?? null)) !== $body) {
                return self::result('invalid', [
                    'code'   => 'tool_call_reused',
                    'detail' => 'This tool call id was already used for a different booking request. Each booking intent needs its own id.',
                ]);
            }

            return $this->continueWith($existing);
        }

        // What Appointments says about the service now. A service it will not
        // let a client book online, or one that takes a deposit Voice cannot
        // collect on a call, goes to a person — Voice does not book it.
        $service = $this->client->serviceDetail($this->ctx->cmpId, (string) $body['service_uuid']);
        $detail = is_array($service['body']['data']['service'] ?? null) ? $service['body']['data']['service'] : null;
        if (!$service['ok'] || $detail === null) {
            return self::result('unavailable', [
                'handoff' => "I can't book that from this call",
                'detail'  => $service['status'] === 404
                    ? 'Aicountly Appointments has no such service in this company.'
                    : 'Aicountly Appointments did not describe the service: ' . self::said($service) . ' Nothing was sent.',
            ]);
        }
        if (($detail['is_active'] ?? false) !== true) {
            return self::result('not_bookable', ['handoff' => "I can't book that from this call", 'detail' => 'The service is no longer offered.']);
        }
        if (($detail['is_bookable_online'] ?? true) !== true) {
            return self::result('not_bookable', [
                'handoff' => "I can't book that appointment from this call",
                'detail'  => 'Appointments does not let clients book this service themselves, so Voice does not book it automatically.',
            ]);
        }
        if (($detail['deposit_required'] ?? false) === true) {
            return self::result('not_bookable', [
                'handoff' => "That appointment needs a deposit, which I can't take on this call",
                'detail'  => 'The service takes a deposit, and Voice cannot collect one on a call.',
            ]);
        }

        try {
            $opened = ExternalOperations::begin(
                $this->ctx,
                self::TARGET,
                self::OPERATION,
                // What we asked for, without the caller's details.
                ['service_uuid' => $body['service_uuid'] ?? null, 'member_uuid' => $body['member_uuid'] ?? null, 'starts_at' => $body['starts_at'] ?? null],
                ['call_id' => $intent['call_id']],
                $intent['actor'],
                $intent['correlation_id'],
                [
                    'request_method' => 'POST',
                    'request_path'   => 'v1/bookings',
                    'request_body'   => $body,
                    // Not before this request could have finished: the recovery
                    // worker must never race a write that is still in flight.
                    'next_check_at'  => Clock::sql(Clock::now()->modify('+2 minutes')),
                ],
            );
        } catch (\PDOException $e) {
            // The same tool call, arriving twice at once: the other request
            // holds this intent's operation, and this one must not send.
            $raced = ExternalOperations::findByCorrelation($this->ctx, self::TARGET, $intent['correlation_id']);
            if ($e->getCode() !== '23505' || $raced === null) {
                throw $e;
            }

            return $this->continueWith($raced);
        }

        $op = $this->operation($opened['operation_id']);
        $answer = $this->client->createBooking($this->ctx->cmpId, $body, $opened['correlation_id']);

        return $this->settle($op, $answer);
    }

    /**
     * The recovery worker's turn: settle an operation whose outcome nobody knows.
     *
     * @param array<string, mixed> $op
     * @return string settled | resent | waiting | abandoned
     */
    public static function recover(array $op): string
    {
        $ctx = Context::forCompany((int) $op['cmp_id'], (int) ($op['bo_id'] ?? 0));
        $booking = new self($ctx);

        if (Db::jsonColumn($op['request_body'] ?? null) === []) {
            ExternalOperations::mark((int) $op['operation_id'], ExternalOperations::ABANDONED, [
                'error_code'    => 'unaddressable',
                'error_message' => 'The request was not recorded, so Appointments cannot be asked about it. A person needs to check Appointments.',
                'next_check_at' => null,
            ]);

            return 'abandoned';
        }

        if (!$booking->client->configured()) {
            ExternalOperations::retryLater((int) $op['operation_id'], (int) $op['attempts'] + 1,
                'Aicountly Appointments is not connected in this deployment right now.');

            return 'waiting';
        }

        $result = $booking->resolve($op);

        return match (true) {
            ($result['resolution'] ?? '') === 'resent' && $result['confirmed'] => 'resent',
            $result['outcome'] === 'pending_verification' => 'waiting',
            default => 'settled',
        };
    }

    /**
     * A rehearsal: Voice's own booking code against a stand-in for
     * Appointments, inside a transaction that is rolled back. Nothing is sent
     * anywhere and nothing is kept.
     *
     * @return array{status: string, detail: string}
     */
    public static function rehearse(Context $ctx, string $scenario): array
    {
        return self::rolledBack(static function () use ($ctx, $scenario): array {
            $request = [
                'service_uuid' => RehearsalAppointments::SERVICE,
                'member_uuid'  => RehearsalAppointments::MEMBER,
                'starts_at'    => Clock::iso(Clock::now()->modify('+1 day')->setTime(10, 0)),
                'client_phone' => '+919000000001',
                'client_name'  => 'Rehearsal caller',
            ];
            $intent = static fn (string $tag): array => [
                'correlation_id' => 'voice:rehearsal:' . $tag . ':' . substr(Uuid::v4(), 0, 8),
                'call_id'        => null,
                'actor'          => 'rehearsal',
                'request'        => $request,
            ];

            if ($scenario === 'api_unavailable') {
                $owner = new RehearsalAppointments('down');
                $booking = new self($ctx, $owner);
                $tag = $intent('down');
                $first = $booking->book($tag);
                $op = ExternalOperations::findByCorrelation($ctx, self::TARGET, $tag['correlation_id']);
                $claimed = $first['confirmed'] || stripos((string) $first['say'] . (string) ($first['handoff'] ?? ''), 'booked') !== false;
                $ok = !$claimed && $first['outcome'] === 'pending_verification'
                    && $op !== null && (string) $op['status'] === ExternalOperations::UNKNOWN
                    && $owner->bookingsHeld() === 0;

                return [
                    'status' => $ok ? 'passed' : 'failed',
                    'detail' => $ok
                        ? 'Simulated: Appointments stopped answering mid-booking. The attempt was kept as pending verification, the caller was told it could not be confirmed yet, and nothing claimed a booking.'
                        : 'Simulated: with Appointments not answering, Voice answered "' . $first['outcome'] . '" — it must keep the attempt as pending verification and claim nothing.',
                ];
            }

            // A lost answer after Appointments booked, then the same tool call again.
            $lost = new RehearsalAppointments('lose_answer');
            $one = new self($ctx, $lost);
            $tag = $intent('lost');
            $firstTry = $one->book($tag);
            $retry = $one->book($tag);
            $adopted = !$firstTry['confirmed'] && $firstTry['outcome'] === 'pending_verification'
                && $retry['confirmed'] && $lost->bookingsHeld() === 1 && $lost->posts() === 1
                && ($retry['booking']['booking_uuid'] ?? null) === $lost->onlyBookingUuid();

            // A request lost before Appointments acted: resent under the same key.
            $dropped = new RehearsalAppointments('drop_request');
            $two = new self($ctx, $dropped);
            $tag = $intent('dropped');
            $secondTry = $two->book($tag);
            $secondRetry = $two->book($tag);
            $keys = array_unique($dropped->postKeys());
            $resent = $secondTry['confirmed'] && $secondRetry['confirmed'] && $dropped->bookingsHeld() === 1
                && $dropped->posts() === 2 && count($keys) === 1;

            $ok = $adopted && $resent;

            return [
                'status' => $ok ? 'passed' : 'failed',
                'detail' => $ok
                    ? 'Simulated against a stand-in for Appointments: when its answer was lost after booking, the first reply said "pending verification" (not booked) and the repeated action adopted that one booking; when the request was lost before it acted, Voice resent it under the same Idempotency-Key. One booking each time.'
                    : 'Simulated: the same booking attempted twice did not end as exactly one booking under one Idempotency-Key ('
                        . $lost->bookingsHeld() . ' and ' . $dropped->bookingsHeld() . ' held).',
            ];
        });
    }

    // -----------------------------------------------------------------------
    // Settling
    // -----------------------------------------------------------------------

    /**
     * Where a repeated tool call picks up: the operation's own state.
     *
     * @param array<string, mixed> $op
     * @return array<string, mixed>
     */
    private function continueWith(array $op): array
    {
        $status = (string) $op['status'];

        if ($status === ExternalOperations::SUCCEEDED) {
            $live = $this->client->booking($this->ctx->cmpId, (string) $op['external_ref']);
            $booking = is_array($live['body']['data']['booking'] ?? null) ? $live['body']['data']['booking'] : null;
            if ($live['ok'] && $booking !== null && self::matches($op, $booking)) {
                return $this->booked($op, $booking);
            }

            // Appointments answered with this booking earlier; it is the
            // details that cannot be read right now.
            return self::result('booked', [
                'confirmed' => true,
                'booking'   => ['booking_uuid' => (string) $op['external_ref']],
                'say'       => 'That booking is made. The team will confirm the details with you.',
                'operation' => ExternalOperations::present($op),
            ]);
        }

        if ($status === ExternalOperations::FAILED) {
            $code = (string) ($op['error_code'] ?? '');

            return match (true) {
                $code === 'slot_taken'                      => $this->taken($op),
                in_array($code, self::NOT_BOOKABLE, true)   => $this->notBookable($op, (string) ($op['error_message'] ?? '')),
                default                                     => self::result('refused', [
                    'handoff'   => "I can't book that from this call",
                    'detail'    => (string) ($op['error_message'] ?? 'Aicountly Appointments refused this booking.'),
                    'operation' => ExternalOperations::present($op),
                ]),
            };
        }

        if ($status === ExternalOperations::PENDING && self::inFlight($op)) {
            // The first attempt may still be waiting on Appointments. Racing it
            // with a second send is how a caller hears "taken" about their own
            // booking.
            return $this->pending($op, 'The first attempt is still waiting for Appointments to answer.');
        }

        if ($status === ExternalOperations::ABANDONED) {
            return self::result('refused', [
                'handoff'   => "I can't book that from this call",
                'detail'    => (string) ($op['error_message'] ?? 'The outcome could not be confirmed with Appointments.'),
                'operation' => ExternalOperations::present($op),
            ]);
        }

        return $this->resolve($op);
    }

    /**
     * Read the answer and record what it established.
     *
     * @param array<string, mixed> $op
     * @param array{ok:bool, status:int, body:?array, error:?string} $answer
     * @return array<string, mixed>
     */
    private function settle(array $op, array $answer): array
    {
        $verdict = self::classify($op, $answer);

        return match ($verdict['outcome']) {
            'settled'      => $this->adopt($op, $verdict['booking'], (int) $answer['status']),
            'taken'        => $this->takenAfterCheck($op, (int) $answer['status']),
            'not_bookable' => $this->refuse($op, $verdict['code'], $verdict['detail'], (int) $answer['status'], true),
            'refused'      => $this->refuse($op, $verdict['code'], $verdict['detail'], (int) $answer['status'], false),
            'in_progress'  => $this->unknown($op, $verdict['detail'], (int) $answer['status']),
            default        => $this->resolve($op, $verdict['detail'], (int) $answer['status']),
        };
    }

    /**
     * UNKNOWN: ask Appointments what it holds, adopt it, or send the same
     * attempt again — never a new key, never "failed".
     *
     * @param array<string, mixed> $op
     * @return array<string, mixed>
     */
    private function resolve(array $op, string $why = '', int $httpStatus = 0): array
    {
        $found = $this->lookup($op);
        if (!$found['ok']) {
            return $this->unknown($op, trim($why . ' Appointments could not be asked what it holds: ' . $found['error']), $httpStatus);
        }
        if ($found['booking'] !== null) {
            return $this->adopt($op, $found['booking'], 200);
        }
        if ($found['voided']) {
            return $this->refuse($op, 'slot_taken', 'Appointments took the booking and then gave the time up: the diary was already taken.', 409, true);
        }

        // Appointments holds nothing for this attempt: send it again, as it was.
        Db::update(ExternalOperations::TABLE, [
            'attempts'        => (int) $op['attempts'] + 1,
            'last_attempt_at' => Clock::sql(Clock::now()),
        ], ['operation_id' => (int) $op['operation_id']]);
        $op['attempts'] = (int) $op['attempts'] + 1;

        $answer = $this->client->createBooking(
            $this->ctx->cmpId,
            self::canonical(Db::jsonColumn($op['request_body'] ?? null)),
            (string) $op['idempotency_key'],
        );
        $verdict = self::classify($op, $answer);

        $result = match ($verdict['outcome']) {
            'settled'      => $this->adopt($op, $verdict['booking'], (int) $answer['status']),
            'taken'        => $this->takenAfterCheck($op, (int) $answer['status']),
            'not_bookable' => $this->refuse($op, $verdict['code'], $verdict['detail'], (int) $answer['status'], true),
            'refused'      => $this->refuse($op, $verdict['code'], $verdict['detail'], (int) $answer['status'], false),
            default        => $this->unknown($op, $verdict['detail'], (int) $answer['status']),
        };

        return $result + ['resolution' => 'resent'];
    }

    /**
     * Appointments answered "slot taken". When the attempt may already have
     * booked that very slot — an earlier send of this same key — the slot may
     * be taken by this caller's own booking, so look before saying so.
     *
     * @param array<string, mixed> $op
     * @return array<string, mixed>
     */
    private function takenAfterCheck(array $op, int $httpStatus): array
    {
        if ((int) $op['attempts'] > 1) {
            $found = $this->lookup($op);
            if ($found['ok'] && $found['booking'] !== null) {
                return $this->adopt($op, $found['booking'], 200);
            }
            if (!$found['ok']) {
                return $this->unknown($op, 'Appointments said the time is taken, possibly by this same booking, and could not be asked which: ' . $found['error'], $httpStatus);
            }
        }

        return $this->refuse($op, 'slot_taken', 'The time was taken before Appointments could book it.', $httpStatus, true);
    }

    /**
     * @param array<string, mixed> $op
     * @param array<string, mixed> $booking
     * @return array<string, mixed>
     */
    private function adopt(array $op, array $booking, int $httpStatus): array
    {
        ExternalOperations::mark((int) $op['operation_id'], ExternalOperations::SUCCEEDED, [
            'external_ref'  => (string) $booking['booking_uuid'],
            'http_status'   => $httpStatus ?: null,
            'error_code'    => null,
            'error_message' => null,
            'next_check_at' => null,
        ]);

        return $this->booked($this->operation((int) $op['operation_id']), $booking);
    }

    /**
     * @param array<string, mixed> $op
     * @return array<string, mixed>
     */
    private function refuse(array $op, string $code, string $detail, int $httpStatus, bool $offerOthers): array
    {
        ExternalOperations::mark((int) $op['operation_id'], ExternalOperations::FAILED, [
            'http_status'   => $httpStatus ?: null,
            'error_code'    => $code !== '' ? $code : 'rejected',
            'error_message' => mb_substr($detail !== '' ? $detail : 'Aicountly Appointments refused this booking.', 0, 300),
            'next_check_at' => null,
        ]);
        $op = $this->operation((int) $op['operation_id']);

        if (!$offerOthers) {
            // Credentials, configuration, a request Appointments could not
            // read: nothing was booked, and this attempt is NOT sent again
            // later — the caller is being handed to a person, and a booking
            // made behind the team's back an hour from now helps nobody.
            return self::result('refused', [
                'handoff'   => "I can't book that from this call",
                'detail'    => (string) $op['error_message'],
                'operation' => ExternalOperations::present($op),
            ]);
        }

        return $code === 'slot_taken' ? $this->taken($op) : $this->notBookable($op, (string) $op['error_message']);
    }

    /**
     * @param array<string, mixed> $op
     * @return array<string, mixed>
     */
    private function unknown(array $op, string $detail, int $httpStatus): array
    {
        $attempts = max(1, (int) $op['attempts']);
        ExternalOperations::mark((int) $op['operation_id'], ExternalOperations::UNKNOWN, [
            'http_status'   => $httpStatus ?: null,
            'error_code'    => 'outcome_unknown',
            'error_message' => mb_substr($detail !== '' ? $detail : 'Aicountly Appointments has not confirmed this booking.', 0, 300),
            'next_check_at' => Clock::sql(Clock::now()->modify('+' . min(240, 2 ** min(8, $attempts)) . ' minutes')),
        ]);

        return $this->pending($this->operation((int) $op['operation_id']), $detail);
    }

    // -----------------------------------------------------------------------
    // What the caller is told
    // -----------------------------------------------------------------------

    /**
     * @param array<string, mixed> $op
     * @param array<string, mixed> $booking Appointments' own answer
     * @return array<string, mixed>
     */
    private function booked(array $op, array $booking): array
    {
        $reference = (string) $booking['reference'];
        $when = $this->bookingWhen($booking);
        $status = (string) $booking['status'];
        $calendar = is_array($booking['calendar'] ?? null) ? $booking['calendar'] : [];
        $diarySettled = in_array((string) ($calendar['state'] ?? ''), ['synced', 'not_required'], true)
            && ($calendar['drift'] ?? null) === null;

        $shape = [
            'booking_uuid' => (string) $booking['booking_uuid'],
            'reference'    => $reference,
            'status'       => $status,
            'starts_at'    => $booking['starts_at'] ?? null,
            'timezone'     => $booking['timezone'] ?? null,
        ];
        $common = ['confirmed' => true, 'booking' => $shape, 'operation' => ExternalOperations::present($op)];

        if ($status === 'CONFIRMED' && $diarySettled) {
            return self::result('booked', $common + [
                'say' => "You're booked for " . $when . '. Your booking reference is ' . $reference . '.',
            ]);
        }
        if ($status === 'CONFIRMED') {
            // Appointments holds the time; its diary entry is not confirmed yet
            // (contract §13: "confirmation pending", never "in the calendar").
            return self::result('booked_pending_confirmation', $common + [
                'say' => "I've reserved " . $when . ' for you. Your booking reference is ' . $reference
                    . '; confirmation is pending, and the team will confirm it with you.',
            ]);
        }
        if ($status === 'PENDING') {
            return self::result('requested', $common + [
                'say' => "I've requested " . $when . ' for you. Your booking reference is ' . $reference
                    . '; the team will confirm it with you.',
            ]);
        }

        // Made, and changed since (cancelled or moved by the business).
        return self::result('booking_changed', $common + [
            'handoff' => "That booking has changed since it was made, so I can't confirm it from this call",
            'detail'  => 'Appointments holds booking ' . $reference . ' as ' . strtolower($status) . '.',
        ]);
    }

    /** @param array<string, mixed> $op @return array<string, mixed> */
    private function taken(array $op): array
    {
        $others = $this->alternatives($op);
        if ($others === []) {
            return self::result('slot_taken', [
                'handoff'   => "That time has just been taken, and I can't see another free time right now",
                'operation' => ExternalOperations::present($op),
            ]);
        }

        return self::result('slot_taken', [
            'alternatives' => $others,
            'say'          => 'That time has just been taken. I can offer ' . self::spokenList(array_map([$this, 'slotWhen'], $others))
                . '. Would one of those suit you?',
            'operation'    => ExternalOperations::present($op),
        ]);
    }

    /** @param array<string, mixed> $op @return array<string, mixed> */
    private function notBookable(array $op, string $why): array
    {
        $others = $this->alternatives($op);
        if ($others === []) {
            return self::result('not_bookable', [
                'handoff'   => "I can't book that time, and I can't see another free time right now",
                'detail'    => $why,
                'operation' => ExternalOperations::present($op),
            ]);
        }

        return self::result('not_bookable', [
            'alternatives' => $others,
            'say'          => "I can't book that time. I can offer " . self::spokenList(array_map([$this, 'slotWhen'], $others))
                . '. Would one of those suit you?',
            'detail'       => $why,
            'operation'    => ExternalOperations::present($op),
        ]);
    }

    /** @param array<string, mixed> $op @return array<string, mixed> */
    private function pending(array $op, string $detail): array
    {
        return self::result('pending_verification', [
            'handoff'   => "I've sent your booking request, but I can't confirm it yet, so please don't book again",
            'detail'    => $detail,
            'operation' => ExternalOperations::present($op),
        ]);
    }

    // -----------------------------------------------------------------------
    // Reading Appointments
    // -----------------------------------------------------------------------

    /**
     * What Appointments holds for this attempt: the booking at this start, for
     * this service (and practitioner), for this client's number.
     *
     * @param array<string, mixed> $op
     * @return array{ok: bool, booking: ?array<string, mixed>, voided: bool, error: string}
     */
    private function lookup(array $op): array
    {
        $request = Db::jsonColumn($op['request_body'] ?? null);
        $start = Clock::parse((string) ($request['starts_at'] ?? ''));
        if ($start === null) {
            return ['ok' => false, 'booking' => null, 'voided' => false, 'error' => 'the recorded request has no start time'];
        }

        $answer = $this->client->findBookings($this->ctx->cmpId, [
            'service_uuid' => self::str($request['service_uuid'] ?? null),
            'member_uuid'  => self::str($request['member_uuid'] ?? null),
            'from'         => Clock::iso($start),
            'to'           => Clock::iso($start->modify('+1 minute')),
            'q'            => ltrim((string) ($request['client_phone'] ?? ''), '+'),
            'limit'        => 50,
        ]);
        $rows = $answer['body']['data'] ?? null;
        if (!$answer['ok'] || !is_array($rows) || !array_is_list($rows)) {
            return ['ok' => false, 'booking' => null, 'voided' => false, 'error' => self::said($answer)];
        }

        $voided = false;
        foreach ($rows as $row) {
            if (!is_array($row) || !self::matches($op, $row)) {
                continue;
            }
            if (in_array((string) $row['status'], self::ACTIVE, true)) {
                return ['ok' => true, 'booking' => $row, 'voided' => false, 'error' => ''];
            }
            if ((string) ($row['lifecycle']['cancellation_code'] ?? '') === 'slot_taken') {
                $voided = true;
            }
        }

        return ['ok' => true, 'booking' => null, 'voided' => $voided, 'error' => ''];
    }

    /**
     * Other times for the same service, from Appointments, now.
     *
     * @param array<string, mixed> $op
     * @return list<array<string, mixed>>
     */
    private function alternatives(array $op): array
    {
        $request = Db::jsonColumn($op['request_body'] ?? null);
        $start = Clock::parse((string) ($request['starts_at'] ?? ''));
        $earliest = Clock::now()->modify('+1 minute');
        $from = $start === null ? $earliest : max($earliest, $start->modify('-3 hours'));

        $slots = self::slotsIn($this->client->slots($this->ctx->cmpId, [
            'service_uuid' => self::str($request['service_uuid'] ?? null),
            'from'         => Clock::iso($from),
            'to'           => Clock::iso(($start ?? $from)->modify('+3 days')),
            'limit'        => self::ALTERNATIVES + 1,
        ])) ?? [];

        $others = array_values(array_filter($slots, static function (array $slot) use ($start): bool {
            $at = Clock::parse((string) $slot['starts_at']);

            return $start === null || $at === null || $at->getTimestamp() !== $start->getTimestamp();
        }));

        return array_slice($others, 0, self::ALTERNATIVES);
    }

    /**
     * The slots in an availability answer, or null when there was no usable
     * answer (which is not "no free times").
     *
     * @param array{ok:bool, status:int, body:?array, error:?string} $answer
     * @return list<array<string, mixed>>|null
     */
    private static function slotsIn(array $answer): ?array
    {
        $slots = $answer['body']['data']['slots'] ?? null;
        if (!$answer['ok'] || !is_array($slots) || !array_is_list($slots)) {
            return null;
        }

        $out = [];
        foreach ($slots as $slot) {
            if (!is_array($slot) || Clock::parse((string) ($slot['starts_at'] ?? '')) === null) {
                continue;
            }
            $out[] = [
                'starts_at'    => (string) $slot['starts_at'],
                'ends_at'      => isset($slot['ends_at']) ? (string) $slot['ends_at'] : null,
                'member_uuid'  => isset($slot['member_uuid']) ? (string) $slot['member_uuid'] : null,
                'member_label' => isset($slot['member_label']) ? (string) $slot['member_label'] : null,
                'local_date'   => isset($slot['local_date']) ? (string) $slot['local_date'] : null,
                'local_time'   => isset($slot['local_time']) ? (string) $slot['local_time'] : null,
            ];
        }

        return $out;
    }

    // -----------------------------------------------------------------------
    // Classification (contract §12.3, as Appointments answers)
    // -----------------------------------------------------------------------

    /**
     * @param array<string, mixed> $op
     * @param array{ok:bool, status:int, body:?array, error:?string} $answer
     * @return array{outcome: string, code: string, detail: string, booking: ?array<string, mixed>}
     */
    public static function classify(array $op, array $answer): array
    {
        $status = (int) $answer['status'];
        $body = is_array($answer['body'] ?? null) ? $answer['body'] : [];
        $code = is_string($body['error']['code'] ?? null) ? (string) $body['error']['code'] : '';
        $reason = is_string($body['error']['details']['reason'] ?? null) ? (string) $body['error']['details']['reason'] : '';
        $said = self::said($answer);
        $verdict = static fn (string $outcome, string $code, string $detail, ?array $booking = null): array => [
            'outcome' => $outcome, 'code' => $code, 'detail' => $detail, 'booking' => $booking,
        ];

        if ($answer['ok']) {
            $booking = $body['data']['booking'] ?? null;
            if (!is_array($booking) || !self::matches($op, $booking)) {
                return $verdict('unknown', 'unconfirmed', 'Appointments answered HTTP ' . $status
                    . ' without the booking Voice asked for, so it cannot be confirmed.');
            }
            if (!in_array((string) $booking['status'], self::ACTIVE, true)) {
                return $verdict('unknown', 'unexpected_status', 'Appointments answered with the booking as '
                    . strtolower((string) $booking['status']) . '.');
            }

            return $verdict('settled', '', '', $booking);
        }

        if ($status === 0) {
            return $verdict('unknown', 'no_response', 'Appointments did not answer (' . $said . ').');
        }

        $why = $reason !== '' ? $reason : $code;

        if ($status === 409) {
            return match ($why) {
                'slot_taken'          => $verdict('taken', 'slot_taken', $said),
                'request_in_progress' => $verdict('in_progress', $why, 'Appointments is still working on this same request.'),
                default               => $verdict('refused', $why !== '' ? $why : 'conflict', $said),
            };
        }

        if ($status === 422 && in_array($reason, self::NOT_BOOKABLE, true)) {
            return $verdict('not_bookable', $reason, $said);
        }

        if ($status === 503 && in_array($code, self::NOT_TAKEN_503, true)) {
            return $verdict('refused', $code, $said);
        }

        if ($status >= 500 || $status === 408 || $status === 429) {
            return $verdict('unknown', $why !== '' ? $why : 'http_' . $status, 'Appointments answered HTTP ' . $status . ', so it may or may not have booked.');
        }

        // 401/403 (key, scope), 404 (no such service or company), 422 (a
        // request Appointments could not accept): it understood and did not book.
        return $verdict('refused', $why !== '' ? $why : 'http_' . $status, $said);
    }

    /**
     * Is this booking the one this attempt asked for? Same service, same start,
     * same client number, same practitioner — and Appointments' id and
     * reference present.
     *
     * @param array<string, mixed> $op
     * @param array<string, mixed> $booking
     */
    private static function matches(array $op, array $booking): bool
    {
        $request = Db::jsonColumn($op['request_body'] ?? null);

        if (!Uuid::isValid((string) ($booking['booking_uuid'] ?? '')) || trim((string) ($booking['reference'] ?? '')) === '') {
            return false;
        }

        $asked = Clock::parse((string) ($request['starts_at'] ?? ''));
        $given = Clock::parse((string) ($booking['starts_at'] ?? ''));
        if ($asked === null || $given === null || $asked->getTimestamp() !== $given->getTimestamp()) {
            return false;
        }

        $service = $booking['service']['service_uuid'] ?? null;
        if (!is_string($service) || $service !== (string) ($request['service_uuid'] ?? '')) {
            return false;
        }

        $member = $booking['member']['member_uuid'] ?? null;
        if (isset($request['member_uuid']) && $member !== $request['member_uuid']) {
            return false;
        }

        $phone = preg_replace('/\D/', '', (string) ($booking['client']['phone'] ?? '')) ?? '';

        return $phone !== '' && $phone === (preg_replace('/\D/', '', (string) ($request['client_phone'] ?? '')) ?? '');
    }

    // -----------------------------------------------------------------------
    // Helpers
    // -----------------------------------------------------------------------

    /**
     * One shape for every answer, so the runner never has to guess.
     *
     * `handoff` is the start of a sentence: the runner finishes it according
     * to whether a callback for the team was actually arranged.
     *
     * @param array<string, mixed> $values
     * @return array<string, mixed>
     */
    private static function result(string $outcome, array $values = []): array
    {
        return $values + [
            'outcome'      => $outcome,
            'confirmed'    => false,
            'say'          => null,
            'handoff'      => null,
            'booking'      => null,
            'slots'        => [],
            'alternatives' => [],
            'operation'    => null,
            'detail'       => null,
            'code'         => null,
        ];
    }

    /**
     * The body exactly as it is stored and sent: keys sorted, nothing empty.
     *
     * @param array<string, mixed> $request
     * @return array<string, mixed>
     */
    private static function canonical(array $request): array
    {
        $out = array_filter($request, static fn ($value): bool => $value !== null && $value !== '');
        ksort($out);

        return $out;
    }

    /** @return array<string, mixed> */
    private function operation(int $operationId): array
    {
        return (array) Db::first('SELECT * FROM ' . ExternalOperations::TABLE . ' WHERE operation_id = :id', ['id' => $operationId]);
    }

    /** @param array<string, mixed> $op */
    private static function inFlight(array $op): bool
    {
        $last = Clock::parse((string) ($op['last_attempt_at'] ?? ''));

        return $last !== null && Clock::now()->getTimestamp() - $last->getTimestamp() < self::IN_FLIGHT_SECONDS;
    }

    /** @param array<string, mixed> $booking */
    private function bookingWhen(array $booking): string
    {
        $at = Clock::parse((string) ($booking['starts_at'] ?? ''));
        if ($at === null) {
            return 'the time you asked for';
        }

        return $at->setTimezone($this->zone((string) ($booking['timezone'] ?? '')))->format('l j F \a\t H:i');
    }

    /** @param array<string, mixed> $slot */
    private function slotWhen(array $slot): string
    {
        $date = is_string($slot['local_date'] ?? null) ? \DateTimeImmutable::createFromFormat('!Y-m-d', $slot['local_date']) : false;
        if ($date !== false && is_string($slot['local_time'] ?? null) && $slot['local_time'] !== '') {
            return $date->format('l j F') . ' at ' . $slot['local_time'];
        }

        $at = Clock::parse((string) ($slot['starts_at'] ?? ''));

        return $at === null ? 'another time' : $at->setTimezone($this->zone(''))->format('l j F \a\t H:i');
    }

    /** The booking's own zone, else the company's, as Voice's settings hold it. */
    private function zone(string $name): \DateTimeZone
    {
        return Clock::zone($name !== '' ? $name : null, (string) (Settings::forCompany($this->ctx->cmpId)['timezone'] ?? 'UTC'));
    }

    /** @param list<string> $items */
    private static function spokenList(array $items): string
    {
        if (count($items) <= 1) {
            return (string) ($items[0] ?? '');
        }
        $last = array_pop($items);

        return implode(', ', $items) . ' or ' . $last;
    }

    /** @param array{ok:bool, status:int, body:?array, error:?string} $answer */
    private static function said(array $answer): string
    {
        $message = trim((string) ($answer['body']['error']['message'] ?? $answer['body']['message'] ?? $answer['error'] ?? ''));
        if ($message === '') {
            $message = $answer['status'] === 0 ? 'no response' : 'HTTP ' . $answer['status'];
        }

        return mb_substr($message, 0, 240);
    }

    private static function instant(mixed $value): ?\DateTimeImmutable
    {
        return is_string($value) ? Clock::parse($value) : null;
    }

    private static function str(mixed $value): ?string
    {
        if (!is_scalar($value)) {
            return null;
        }
        $value = trim((string) $value);

        return $value === '' ? null : $value;
    }

    /**
     * Run $work in a transaction that is always rolled back.
     *
     * @template T
     * @param callable(): T $work
     * @return T
     */
    private static function rolledBack(callable $work): mixed
    {
        $pdo = Db::connect();
        $outer = $pdo->inTransaction();
        if ($outer) {
            Db::run('SAVEPOINT voice_rehearsal');
        } else {
            $pdo->beginTransaction();
        }

        try {
            return $work();
        } finally {
            if ($outer) {
                Db::run('ROLLBACK TO SAVEPOINT voice_rehearsal');
            } elseif ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
        }
    }
}
