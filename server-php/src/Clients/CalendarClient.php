<?php

declare(strict_types=1);

namespace Aicountly\Api\Clients;

use Aicountly\Api\Env;
use Aicountly\Api\Features;

/**
 * Live reads and writes against Aicountly Calendar.
 *
 * CALENDAR OWNS SCHEDULING. When a caller asks to move Friday's meeting, the
 * availability is read from Calendar on that request and the change is written
 * to Calendar. Voice keeps `calendar_event_ref` — the id — and not one field
 * more. Not the start time, not the title, not the attendee list.
 *
 * That rule has teeth on exactly the screen this product is for. An agent is on
 * the phone reading a time out loud to a customer. If that time came from a
 * copy taken this morning, and somebody moved the meeting at lunchtime, the
 * agent confirms a time that is wrong to somebody who will act on it. So the
 * Copilot re-reads availability before it offers a slot, and again before it
 * books one.
 *
 * ## When Calendar is unavailable
 *
 * The booking action is DISABLED and says why. It does not write the event
 * locally "to sync later" — there is nowhere to sync from and no local event
 * table to hold it. A caller is told the diary cannot be reached right now,
 * which is true, instead of being told they are booked, which would not be.
 */
final class CalendarClient extends ApiClient
{
    private string $actorUuid = '';
    private string $sesKey = '';

    public function service(): string
    {
        return 'calendar';
    }

    protected function productionBase(): string
    {
        return 'https://calendar.aicountly.com';
    }

    protected function sandboxBase(): string
    {
        return 'https://calendar.gh.aicountly.com';
    }

    protected function baseEnvKey(): string
    {
        return 'CALENDAR_API_BASE';
    }

    /**
     * Act on this subscriber's calendar.
     *
     * Calendar has no concept of "the company's calendar", only a person's — a
     * callback booked for an agent is written to that agent's diary, because
     * that is where a human looks.
     */
    public function forSubscriber(string $subscriberUuid): self
    {
        $clone = clone $this;
        $clone->actorUuid = trim($subscriberUuid);

        return $clone;
    }

    public function withSession(string $sesKey): self
    {
        $clone = clone $this;
        $clone->sesKey = trim($sesKey);

        return $clone;
    }

    /**
     * True when this deployment can reach Calendar at all.
     *
     * Checked BEFORE a booking is offered to a caller, not after it is
     * attempted: an AI agent that discovers on submit that it never had a
     * calendar has already promised somebody an appointment.
     */
    public function configured(): bool
    {
        return Features::enabled('CALENDAR') && Env::get('CALENDAR_SERVICE_KEY') !== '';
    }

    /**
     * Busy intervals for several people at once.
     *
     * @param list<string> $subscriberUuids
     */
    public function freeBusy(array $subscriberUuids, string $startIso, string $endIso): array
    {
        return $this->request('POST', 'calendar/free-busy', [
            'subscriber_uuids' => array_values($subscriberUuids),
            'start'            => $startIso,
            'end'              => $endIso,
        ], $this->headers());
    }

    public function events(string $startIso, string $endIso): array
    {
        return $this->request(
            'GET',
            'calendar/events' . self::query(['start' => $startIso, 'end' => $endIso]),
            null,
            $this->headers(),
        );
    }

    public function event(string $eventUuid): array
    {
        return $this->request('GET', 'calendar/events/' . rawurlencode($eventUuid), null, $this->headers());
    }

    /**
     * Create an event, with our correlation id attached.
     *
     * `$correlationId` goes out as the idempotency key AND is stored in
     * voice_external_operations. That pairing is what makes a timed-out create
     * recoverable: we can ask Calendar what it holds against this id instead of
     * sending the request a second time and hoping.
     *
     * @param array<string, mixed> $payload
     */
    public function createEvent(array $payload, string $correlationId): array
    {
        return $this->request('POST', 'calendar/events', $payload, $this->headers([
            'Idempotency-Key' => $correlationId,
        ]), true);
    }

    /** @param array<string, mixed> $patch */
    public function updateEvent(string $eventUuid, array $patch, string $correlationId): array
    {
        return $this->request(
            'PATCH',
            'calendar/events/' . rawurlencode($eventUuid),
            $patch,
            $this->headers(['Idempotency-Key' => $correlationId]),
            true,
        );
    }

    public function cancelEvent(string $eventUuid, string $correlationId): array
    {
        return $this->request(
            'PATCH',
            'calendar/events/' . rawurlencode($eventUuid),
            ['status' => 'cancelled'],
            $this->headers(['Idempotency-Key' => $correlationId]),
            true,
        );
    }

    /**
     * What did Calendar do with the request we sent under this correlation id?
     *
     * THE RECONCILIATION READ. Called after a create timed out, so the outcome
     * is settled by asking the owner rather than by guessing or by resending.
     */
    public function findByCorrelation(string $correlationId): array
    {
        return $this->request(
            'GET',
            'calendar/events' . self::query(['correlation_id' => $correlationId, 'limit' => 1]),
            null,
            $this->headers(),
        );
    }

    public function health(): array
    {
        return $this->request('GET', 'health', null, []);
    }

    /**
     * @param array<string, string> $extra
     * @return array<string, string>
     */
    private function headers(array $extra = []): array
    {
        $key = Env::get('CALENDAR_SERVICE_KEY');

        if ($key !== '' && $this->actorUuid !== '') {
            return $extra + [
                'X-Service-Key' => $key,
                'X-Actor-Uuid'  => $this->actorUuid,
            ];
        }

        // No service key configured: fall back to the caller's own session,
        // which works for the one case that needs no impersonation — a staff
        // member looking at their own diary. Everything else fails loudly at
        // configured(), which is where an administrator can see it.
        if ($this->sesKey !== '') {
            return $extra + ['Authorization' => 'Bearer ' . $this->sesKey];
        }

        return $extra;
    }
}
