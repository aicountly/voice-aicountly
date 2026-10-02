<?php

declare(strict_types=1);

namespace Aicountly\Api\Domain;

use Aicountly\Api\Clients\AppointmentsClient;
use Aicountly\Api\Support\Clock;
use Aicountly\Api\Support\Uuid;

/**
 * A stand-in for Aicountly Appointments, for the rehearsal room ONLY.
 *
 * A rehearsal must never write to another product, yet "the same action twice
 * creates one record" is a claim about Voice's own booking code, and the only
 * honest way to check it is to run that code. This answers the handful of
 * Appointments routes AppointmentsBooking uses, in memory, the way the
 * contract says Appointments answers them (an Idempotency-Key replays the
 * first answer; a 201 carries the booking's id and reference) — and it can be
 * told to misbehave the ways a real network does:
 *
 *   lose_answer   the first booking request is ACTED ON and its answer lost;
 *                 the first read-back cannot be made either
 *   drop_request  the first booking request is lost before it is acted on
 *   down          the service can be read, then nothing answers
 *
 * Constructed only by AppointmentsBooking::rehearse(), whose work is rolled
 * back. A live call always gets the real AppointmentsClient.
 */
final class RehearsalAppointments extends AppointmentsClient
{
    public const SERVICE = '00000000-0000-4000-8000-00000000a001';
    public const MEMBER = '00000000-0000-4000-8000-00000000b001';

    /** @var array<string, array<string, mixed>> */
    private array $bookings = [];

    /** @var array<string, array{ok:bool, status:int, body:?array, error:?string}> */
    private array $replays = [];

    /** @var list<string> */
    private array $keys = [];

    private int $lookups = 0;

    public function __construct(private readonly string $mode)
    {
    }

    public function configured(): bool
    {
        return true;
    }

    public function bookingsHeld(): int
    {
        return count($this->bookings);
    }

    public function posts(): int
    {
        return count($this->keys);
    }

    /** @return list<string> */
    public function postKeys(): array
    {
        return $this->keys;
    }

    public function onlyBookingUuid(): ?string
    {
        return count($this->bookings) === 1 ? (string) array_key_first($this->bookings) : null;
    }

    protected function key(): string
    {
        return 'rehearsal';
    }

    protected function send(string $method, string $path, ?array $body, array $extra, bool $required): array
    {
        $route = explode('?', $path, 2)[0];
        parse_str(explode('?', $path, 2)[1] ?? '', $query);

        if ($method === 'GET' && str_starts_with($route, 'v1/services/')) {
            return self::answer(200, ['data' => ['service' => [
                'service_uuid' => self::SERVICE, 'name' => 'Rehearsal consultation', 'is_active' => true,
                'is_bookable_online' => true, 'deposit_required' => false,
            ]]]);
        }

        if ($method === 'POST' && $route === 'v1/bookings') {
            $key = (string) ($extra['Idempotency-Key'] ?? '');
            $this->keys[] = $key;
            $first = count($this->keys) === 1;

            if ($this->mode === 'down' || ($this->mode === 'drop_request' && $first)) {
                return self::lost();
            }
            if (isset($this->replays[$key])) {
                return $this->replays[$key];
            }

            $uuid = Uuid::v4();
            $this->bookings[$uuid] = [
                'booking_uuid' => $uuid,
                'reference'    => 'R-' . strtoupper(substr($uuid, 0, 6)),
                'status'       => 'CONFIRMED',
                'starts_at'    => Clock::iso(Clock::parse((string) ($body['starts_at'] ?? '')) ?? Clock::now()),
                'timezone'     => 'UTC',
                'service'      => ['service_uuid' => (string) ($body['service_uuid'] ?? '')],
                'member'       => ['member_uuid' => (string) ($body['member_uuid'] ?? '')],
                'client'       => ['phone' => (string) ($body['client_phone'] ?? '')],
                'calendar'     => ['state' => 'synced', 'confirmed' => true, 'drift' => null],
                'lifecycle'    => ['cancellation_code' => null],
            ];
            $this->replays[$key] = self::answer(201, ['data' => ['booking' => $this->bookings[$uuid]]]);

            return $this->mode === 'lose_answer' && $first ? self::lost() : $this->replays[$key];
        }

        if ($method === 'GET' && $route === 'v1/bookings') {
            $this->lookups++;
            if ($this->mode === 'down' || ($this->mode === 'lose_answer' && $this->lookups === 1)) {
                return self::lost();
            }

            $from = Clock::parse((string) ($query['from'] ?? ''));
            $to = Clock::parse((string) ($query['to'] ?? ''));
            $rows = array_values(array_filter($this->bookings, static function (array $b) use ($from, $to): bool {
                $at = Clock::parse((string) $b['starts_at']);

                return $at !== null && ($from === null || $at >= $from) && ($to === null || $at < $to);
            }));

            return self::answer(200, ['data' => $rows, 'meta' => ['total' => count($rows)]]);
        }

        if ($method === 'GET' && str_starts_with($route, 'v1/bookings/')) {
            if ($this->mode === 'down') {
                return self::lost();
            }
            $booking = $this->bookings[substr($route, strlen('v1/bookings/'))] ?? null;

            return $booking === null
                ? self::answer(404, ['error' => ['code' => 'not_found', 'message' => 'That appointment does not exist.', 'details' => []]])
                : self::answer(200, ['data' => ['booking' => $booking]]);
        }

        if ($method === 'GET' && $route === 'v1/availability/slots') {
            return $this->mode === 'down' ? self::lost() : self::answer(200, ['data' => ['slots' => []]]);
        }

        return self::answer(404, ['error' => ['code' => 'not_found', 'message' => 'Not part of the rehearsal.', 'details' => []]]);
    }

    /** @return array{ok:bool, status:int, body:?array, error:?string} */
    private static function answer(int $status, array $body): array
    {
        $ok = $status >= 200 && $status < 300;

        return ['ok' => $ok, 'status' => $status, 'body' => $body, 'error' => $ok ? null : (string) ($body['error']['message'] ?? 'HTTP ' . $status)];
    }

    /** @return array{ok:bool, status:int, body:?array, error:?string} */
    private static function lost(): array
    {
        return ['ok' => false, 'status' => 0, 'body' => null, 'error' => 'no response (rehearsal)'];
    }
}
