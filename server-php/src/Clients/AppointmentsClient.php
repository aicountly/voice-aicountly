<?php

declare(strict_types=1);

namespace Aicountly\Api\Clients;

use Aicountly\Api\Env;
use Aicountly\Api\Features;

/**
 * Aicountly Appointments — the owner of every customer booking.
 *
 * A caller asking an AI agent for an appointment is asking for an Appointments
 * booking: services, durations, staff hours, buffers, notice, holds and the
 * lifecycle are Appointments' rules, and it reaches Calendar itself. Voice
 * never writes a customer booking into anybody's diary, and keeps no copy of a
 * booking — only the reference Appointments answers with (calendar-react-app's
 * docs/ecosystem-alignment/CONTRACTS.md §0, §13–§14).
 *
 * ## How it authenticates
 *
 * As a partner backend, the way Appointments documents for a caller on the
 * phone: `X-Service-Key` — Voice's key, registered on the Appointments host as
 * `voice:<key>` in SERVICE_KEYS — and `cmp_id`, the company the call belongs
 * to. A service key is never an override there: the booking still has to be
 * exactly a slot Appointments would offer now (§13 commit-time validation).
 *
 * ## What it does not do
 *
 * Decide anything. Every method returns the raw ApiClient result; reading it —
 * settled, refused, unknown — is Domain\AppointmentsBooking's job.
 *
 * Not final for one reason: the rehearsal room's simulated owner
 * (Domain\RehearsalAppointments) stands in for it so a rehearsal can exercise
 * Voice's real booking code without writing to Appointments.
 */
class AppointmentsClient extends ApiClient
{
    /** The label Voice's key is registered under on the Appointments host. */
    public const SOURCE_APP = 'voice';

    public function service(): string
    {
        return 'appointments';
    }

    protected function productionBase(): string
    {
        return 'https://appointments.aicountly.com';
    }

    protected function sandboxBase(): string
    {
        return 'https://appointments.gh.aicountly.com';
    }

    protected function baseEnvKey(): string
    {
        return 'APPOINTMENTS_API_BASE';
    }

    /**
     * Never reuse an Appointments answer within a request: the free times read
     * before a booking, and the read-back that decides whether to resend one,
     * must be asked fresh.
     */
    protected function memoises(): bool
    {
        return false;
    }

    /**
     * Switched on, with Voice's key present. Configured is not connected —
     * probe() finds out whether Appointments accepts the key.
     */
    public function configured(): bool
    {
        return Features::enabled('APPOINTMENTS') && $this->key() !== '';
    }

    /** One service, as Appointments describes it now: active, bookable online, deposit. */
    public function serviceDetail(int $cmpId, string $serviceUuid): array
    {
        return $this->send('GET', 'v1/services/' . rawurlencode($serviceUuid) . self::query(['cmp_id' => $cmpId]), null, [], false);
    }

    /**
     * Times Appointments would offer now — every one already checked against
     * the practitioner's live diary on its side.
     *
     * @param array<string, string|int|null> $filters service_uuid, member_uuid, from, to, daypart, limit
     */
    public function slots(int $cmpId, array $filters): array
    {
        return $this->send('GET', 'v1/availability/slots' . self::query(['cmp_id' => $cmpId] + $filters), null, [], false);
    }

    /**
     * Book. The body and the key are exactly what the operation stored before
     * this call, so a resend of this attempt is the same request under the same
     * Idempotency-Key and Appointments answers it rather than booking twice.
     *
     * @param array<string, mixed> $body
     */
    public function createBooking(int $cmpId, array $body, string $idempotencyKey): array
    {
        return $this->send('POST', 'v1/bookings' . self::query(['cmp_id' => $cmpId]), $body, ['Idempotency-Key' => $idempotencyKey], true);
    }

    /** One booking, live. */
    public function booking(int $cmpId, string $bookingUuid): array
    {
        return $this->send('GET', 'v1/bookings/' . rawurlencode($bookingUuid) . self::query(['cmp_id' => $cmpId]), null, [], false);
    }

    /**
     * THE READ-BACK: bookings at one start time for one service, narrowed by
     * the client's number. Asked when an answer was lost, before anything is
     * sent again.
     *
     * @param array<string, string|int|null> $filters service_uuid, member_uuid, from, to, q
     */
    public function findBookings(int $cmpId, array $filters): array
    {
        return $this->send('GET', 'v1/bookings' . self::query(['cmp_id' => $cmpId] + $filters), null, [], false);
    }

    /**
     * Does Appointments accept Voice — this key, for this company — right now?
     *
     * A real, harmless authenticated read (one service), not a health check: a
     * web server answering proves nothing about the key.
     *
     * @return array{ok: bool, status: string, reason: ?string}
     */
    public function probe(int $cmpId): array
    {
        if ($this->key() === '') {
            return ['ok' => false, 'status' => 'not_configured', 'reason' => 'APPOINTMENTS_SERVICE_KEY is not set.'];
        }

        $result = $this->send('GET', 'v1/services' . self::query(['cmp_id' => $cmpId, 'limit' => 1]), null, [], false);
        $data = $result['body']['data'] ?? null;

        if ($result['ok'] && is_array($data) && array_is_list($data)) {
            return ['ok' => true, 'status' => 'connected', 'reason' => null];
        }

        $code = (string) ($result['body']['error']['code'] ?? '');

        return [
            'ok'     => false,
            'status' => $result['status'] === 0 ? 'unavailable' : 'degraded',
            'reason' => match (true) {
                $result['status'] === 401 => 'Aicountly Appointments does not accept Voice\'s service key. Check APPOINTMENTS_SERVICE_KEY here and the voice:<key> entry in SERVICE_KEYS on the Appointments host.',
                $result['status'] === 403 => 'Aicountly Appointments accepts Voice\'s key but refused this read (' . ($code !== '' ? $code : 'forbidden') . ').',
                $result['status'] === 0   => 'Aicountly Appointments did not answer.',
                $result['ok']             => 'Aicountly Appointments answered, but not with the list of services Voice asked for.',
                default                   => 'Aicountly Appointments answered HTTP ' . $result['status'] . ($code !== '' ? ' (' . $code . ')' : '') . '.',
            },
        ];
    }

    /** Voice's key for Appointments, or '' when none is usable. */
    protected function key(): string
    {
        $key = trim(Env::get('APPOINTMENTS_SERVICE_KEY'));

        // A template placeholder left in a deployed .env authenticates nothing.
        return str_starts_with($key, 'CHANGE_ME') ? '' : $key;
    }

    /**
     * Every Appointments request goes out with Voice's key, or not at all: with
     * no key the refusal Appointments would give is answered here, marked
     * `local`, and nothing is sent. It can only ever produce a refusal.
     *
     * @param array<string, mixed>|null $body
     * @param array<string, string>     $extra
     * @return array{ok:bool, status:int, body:?array, error:?string}
     */
    protected function send(string $method, string $path, ?array $body, array $extra, bool $required): array
    {
        $key = $this->key();
        if ($key === '') {
            return [
                'ok'     => false,
                'status' => 401,
                'body'   => ['error' => ['code' => 'unauthorized', 'message' => 'APPOINTMENTS_SERVICE_KEY is not set.', 'details' => []]],
                'error'  => 'APPOINTMENTS_SERVICE_KEY is not set.',
                'local'  => true,
            ];
        }

        return $this->request($method, $path, $body, $extra + ['X-Service-Key' => $key], $required);
    }
}
