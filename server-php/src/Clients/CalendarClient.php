<?php

declare(strict_types=1);

namespace Aicountly\Api\Clients;

use Aicountly\Api\Env;
use Aicountly\Api\Features;

/**
 * Aicountly Calendar, through the Calendar Events API v1.
 *
 * The contract is calendar-react-app's docs/ecosystem-alignment/CONTRACTS.md
 * (§1–§7 and §11–§12; Voice's row in §18). This class speaks it and nothing
 * else: no invented field, no invented route, no guessed response shape.
 *
 * ## What Voice writes there
 *
 * One thing: a callback's diary entry — a busy block in the diary of the person
 * who will make the call. Calendar owns the event; Voice owns the callback and
 * maintains the entry as a projection of it (Domain\CallbackDiary). Voice keeps
 * the event's id, its owner and its version, never its contents. Booking a
 * customer is not this: that is Appointments' workflow, not a diary write.
 *
 * ## How it authenticates (Mode S)
 *
 * With Voice's own service key — `CALENDAR_SERVICE_KEY`, registered on the
 * Calendar host as `voice:<key>` in CALENDAR_SERVICE_KEYS — and three headers:
 *
 *   X-Actor-Uuid   whose diary. For a callback that is the ASSIGNED agent, not
 *                  whoever typed it. A my.aicountly subscriber id is a positive
 *                  integer; anything else is refused here before a request
 *                  leaves, because Calendar refuses it too, and a diary called
 *                  "service:lobby" is one no person will ever open.
 *   X-Tenant-Ref   cmp:<cmp_id> — the company Voice is acting for, which
 *                  Calendar checks the person belongs to.
 *   X-Calendar-Contract: 1 — strict v1: a field Calendar does not know is a
 *                  422, never a 201 that quietly dropped it.
 *
 * Never a borrowed user session: the diary owner is usually not the person
 * signed in, and a session can only ever reach its own diary.
 *
 * ## What it does not do
 *
 * Decide anything. Every method returns the raw ApiClient result. Reading it —
 * what is settled, what is unknown, what may be resent and under which key —
 * is CallbackDiary's job, under the contract's §12.
 */
final class CalendarClient extends ApiClient
{
    /** The label Voice's key is registered under on the Calendar host. */
    public const SOURCE_APP = 'voice';

    /** The Events API version this client is written against. */
    public const CONTRACT = '1';

    /** A my.aicountly subscriber id: a positive integer, at most 19 digits. */
    private const SUBSCRIBER_ID = '/^[1-9][0-9]{0,18}$/';

    private string $actorUuid = '';
    private string $tenantRef = '';

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
     * Never reuse a Calendar answer within a request.
     *
     * The lookup that decides whether to resend, and the free/busy read that
     * decides whether a time is promised, must be asked fresh every time.
     */
    protected function memoises(): bool
    {
        return false;
    }

    /** True when $value is something Calendar accepts as X-Actor-Uuid. */
    public static function isSubscriberId(?string $value): bool
    {
        return preg_match(self::SUBSCRIBER_ID, trim((string) $value)) === 1;
    }

    /**
     * Act on this person's diary.
     *
     * Calendar has no "company calendar", only people's — a callback for an
     * agent is written to that agent's diary, because that is where they look.
     */
    public function forSubscriber(string $subscriberId): self
    {
        $clone = clone $this;
        $clone->actorUuid = trim($subscriberId);

        return $clone;
    }

    /** The company Voice is acting for, sent as X-Tenant-Ref. */
    public function forCompany(int $cmpId): self
    {
        $clone = clone $this;
        $clone->tenantRef = $cmpId > 0 ? 'cmp:' . $cmpId : '';

        return $clone;
    }

    /**
     * True when this deployment is set up to reach Calendar at all: switched
     * on, with Voice's key present.
     *
     * Configured is not connected — whether Calendar ACCEPTS the key is what
     * probe() finds out, and what the Integrations screen reports.
     */
    public function configured(): bool
    {
        return Features::enabled('CALENDAR') && Env::get('CALENDAR_SERVICE_KEY') !== '';
    }

    /**
     * Send one write — POST or PATCH on an event — exactly as given.
     *
     * The diary records method, path, body and precondition BEFORE calling
     * this, so a retry of the same attempt can send the identical request under
     * the same Idempotency-Key and Calendar replays rather than acts twice.
     *
     * @param array<string, mixed> $body
     */
    public function write(string $method, string $path, array $body, string $idempotencyKey, ?string $ifMatch = null): array
    {
        $extra = ['Idempotency-Key' => $idempotencyKey];
        if ($ifMatch !== null && $ifMatch !== '') {
            $extra['If-Match'] = $ifMatch;
        }

        return $this->send(strtoupper($method), $path, $body, $extra, true);
    }

    /** @param array<string, mixed> $body */
    public function createEvent(array $body, string $idempotencyKey): array
    {
        return $this->write('POST', self::eventsPath(), $body, $idempotencyKey);
    }

    /**
     * Change an event's fields. A time change must carry the version Voice last
     * saw, or Calendar answers 428; a stale one answers 409 version_conflict
     * with the event as it now is.
     *
     * @param array<string, mixed> $patch
     */
    public function updateEvent(string $eventId, array $patch, string $idempotencyKey, ?int $version): array
    {
        return $this->write('PATCH', self::eventPath($eventId), $patch, $idempotencyKey, self::ifMatch($version));
    }

    /**
     * Cancel an event. Unconditional and idempotent on Calendar's side, so no
     * If-Match: an owner retitling the entry must not stop Voice releasing the
     * time.
     */
    public function cancelEvent(string $eventId, string $idempotencyKey): array
    {
        return $this->write('PATCH', self::eventPath($eventId), ['status' => 'cancelled'], $idempotencyKey);
    }

    /**
     * THE RECONCILIATION READ: what does Calendar hold under this source_ref,
     * in this diary? 0 or 1 events, cancelled ones included.
     *
     * Asked as the owner the event was written for — Calendar answers every
     * other caller as if it does not exist.
     */
    public function lookup(string $sourceRef): array
    {
        return $this->send(
            'GET',
            self::eventsPath() . self::query(['source_app' => self::SOURCE_APP, 'source_ref' => $sourceRef]),
            null,
            [],
            false,
        );
    }

    /**
     * Busy intervals for several people at once: times only, no titles.
     *
     * @param list<string> $subscriberIds
     */
    public function freeBusy(array $subscriberIds, string $startIso, string $endIso): array
    {
        return $this->send(
            'GET',
            'calendar/free-busy' . self::query([
                'subscribers' => implode(',', array_map('trim', $subscriberIds)),
                'start'       => $startIso,
                'end'         => $endIso,
            ]),
            null,
            [],
            false,
        );
    }

    /**
     * Would this interval clash, per Calendar, right now?
     *
     * ADVISORY. Only a write sent with conflict_policy "reject" decides; this
     * answers early so a person is not promised a time their diary already
     * holds. `checked:false` means Calendar could not tell — unknown, not free.
     *
     * @param list<string> $subscriberIds
     * @param list<string> $ignoreEventIds an entry being moved does not clash with itself
     */
    public function conflictCheck(array $subscriberIds, string $startAt, string $endAt, array $ignoreEventIds = []): array
    {
        return $this->send('POST', 'calendar/conflict-check', [
            'subscribers'      => array_values(array_map('trim', $subscriberIds)),
            'start_at'         => $startAt,
            'end_at'           => $endAt,
            'ignore_event_ids' => array_values($ignoreEventIds),
        ], [], false);
    }

    /** Reachability and the contract version Calendar serves. No credentials. */
    public function health(): array
    {
        return $this->request('GET', 'health', null, ['X-Calendar-Contract' => self::CONTRACT]);
    }

    /**
     * Does Calendar accept Voice — this key, this contract — right now?
     *
     * Asked with a real, harmless read: free/busy for one person over one
     * minute, as that person, for this company. /health alone proves only that
     * a web server answered; a missing, mistyped or unregistered key passes it
     * and fails every write.
     *
     * @return array{ok: bool, status: string, reason: ?string}
     */
    public function probe(string $subscriberId, int $cmpId): array
    {
        $health = $this->health();
        if (!$health['ok']) {
            return [
                'ok' => false,
                'status' => $health['status'] === 0 ? 'unavailable' : 'degraded',
                'reason' => 'Aicountly Calendar did not answer its health check (' . ($health['error'] ?? 'no answer') . ').',
            ];
        }
        $contract = $health['body']['contract_version'] ?? $health['body']['data']['contract_version'] ?? null;
        if ((string) $contract !== self::CONTRACT) {
            return [
                'ok' => false,
                'status' => 'degraded',
                'reason' => 'Aicountly Calendar does not report Events API contract v' . self::CONTRACT
                    . ', which Voice needs before it can write a diary entry.',
            ];
        }

        if (!self::isSubscriberId($subscriberId)) {
            return [
                'ok' => false,
                'status' => 'configured',
                'reason' => 'Calendar is reachable, but Voice\'s key could not be tested: the signed-in person has no AICOUNTLY subscriber id to test it with.',
            ];
        }

        $now = time();
        $result = $this->forSubscriber($subscriberId)->forCompany($cmpId)->freeBusy(
            [$subscriberId],
            gmdate('Y-m-d\TH:i:s\Z', $now),
            gmdate('Y-m-d\TH:i:s\Z', $now + 60),
        );
        $code = (string) ($result['body']['code'] ?? '');

        if ($result['ok'] && ($result['body']['success'] ?? null) === true && is_array($result['body']['data']['subscribers'] ?? null)) {
            return ['ok' => true, 'status' => 'connected', 'reason' => null];
        }

        return [
            'ok' => false,
            'status' => $result['status'] === 0 ? 'unavailable' : 'degraded',
            'reason' => match (true) {
                $result['status'] === 401 => 'Aicountly Calendar does not accept Voice\'s service key. Check CALENDAR_SERVICE_KEY here and the voice:<key> entry in CALENDAR_SERVICE_KEYS on the Calendar host.',
                $result['status'] === 403 => 'Aicountly Calendar accepts Voice\'s key but refused this read (' . ($code !== '' ? $code : 'forbidden') . ').',
                $code === 'schema_not_ready' => 'Aicountly Calendar\'s v1 database migration has not been applied.',
                $result['status'] === 0 => 'Aicountly Calendar did not answer.',
                default => 'Aicountly Calendar answered HTTP ' . $result['status'] . ($code !== '' ? ' (' . $code . ')' : '') . '.',
            },
        ];
    }

    // -----------------------------------------------------------------------

    /**
     * Every Calendar request goes out as Mode S, or not at all.
     *
     * A request with no key, or for somebody who is not a subscriber id, is
     * answered HERE with the refusal Calendar would give, marked `local` —
     * nothing is sent. CallbackDiary checks both before it gets this far; this
     * is the backstop, and it can only ever produce a refusal, never a success.
     *
     * @param array<string, mixed>|null $body
     * @param array<string, string>     $extra
     */
    private function send(string $method, string $path, ?array $body, array $extra, bool $required): array
    {
        $key = Env::get('CALENDAR_SERVICE_KEY');
        if ($key === '') {
            return self::refusedLocally(401, 'unauthenticated', 'CALENDAR_SERVICE_KEY is not set.');
        }
        if (!self::isSubscriberId($this->actorUuid)) {
            return self::refusedLocally(422, 'invalid_actor', 'Calendar accepts only a my.aicountly subscriber id as the diary owner.');
        }

        return $this->request($method, $path, $body, $extra + [
            'X-Service-Key'       => $key,
            'X-Actor-Uuid'        => $this->actorUuid,
            'X-Tenant-Ref'        => $this->tenantRef,
            'X-Calendar-Contract' => self::CONTRACT,
        ], $required);
    }

    /** @return array{ok:bool, status:int, body:array<string, mixed>, error:string, local:bool} */
    private static function refusedLocally(int $status, string $code, string $message): array
    {
        return [
            'ok'     => false,
            'status' => $status,
            'body'   => ['success' => false, 'code' => $code, 'message' => $message, 'data' => null, 'errors' => []],
            'error'  => $message,
            'local'  => true,
        ];
    }

    public static function eventsPath(): string
    {
        return 'calendar/events';
    }

    public static function eventPath(string $eventId): string
    {
        return 'calendar/events/' . rawurlencode($eventId);
    }

    /** `"<version>"` — Calendar's ETag form — or null when there is none. */
    public static function ifMatch(?int $version): ?string
    {
        return $version === null || $version < 1 ? null : '"' . $version . '"';
    }
}
