<?php

declare(strict_types=1);

namespace Aicountly\Api\Clients;

use Aicountly\Api\Env;
use Aicountly\Api\Features;

/**
 * Live calls to Aicountly Lobby.
 *
 * Lobby owns the reception desk: who is waiting, who they came to see, what
 * happens when they arrive. Voice owns the phone call. The two meet at a
 * handful of points — a visitor at the desk asks to be rung back, a call needs
 * to reach whoever is covering reception — and at each of them the OTHER
 * product's workflow stays the other product's.
 *
 * Voice does not implement a lobby queue, a visitor log or a check-in flow, and
 * Lobby does not implement calling. Where Lobby needs a call placed it asks
 * Voice with its service key, and the call that results is attributed to LOBBY
 * because the key proves it — not because a request body said so.
 *
 * This integration is DECLARED and not yet operational in any deployment: the
 * flag stays off until LOBBY_SERVICE_KEY is set and Lobby answers. The
 * Integrations screen says exactly that rather than showing a connected badge.
 */
final class LobbyClient extends ApiClient
{
    private string $actorUuid = '';

    public function service(): string
    {
        return 'lobby';
    }

    protected function productionBase(): string
    {
        return 'https://lobby.aicountly.com';
    }

    protected function sandboxBase(): string
    {
        return 'https://lobby.gh.aicountly.com';
    }

    protected function baseEnvKey(): string
    {
        return 'LOBBY_API_BASE';
    }

    public function forActor(string $actorUuid): self
    {
        $clone = clone $this;
        $clone->actorUuid = trim($actorUuid);

        return $clone;
    }

    public function configured(): bool
    {
        return Features::enabled('LOBBY');
    }

    /** Who is covering reception right now, so a call can be routed to a person. */
    public function deskCoverage(int $cmpId, int $boId = 0): array
    {
        return $this->request(
            'GET',
            'desk/coverage' . self::query(['cmp_id' => $cmpId, 'bo_id' => $boId]),
            null,
            $this->headers(),
        );
    }

    /** Tell Lobby a visitor's callback was completed. Lobby decides what that means there. */
    public function notifyCallbackCompleted(string $lobbyRef, array $payload, string $correlationId): array
    {
        return $this->request(
            'POST',
            'visits/' . rawurlencode($lobbyRef) . '/callback-completed',
            $payload,
            $this->headers(['Idempotency-Key' => $correlationId]),
            true,
        );
    }

    public function health(): array
    {
        return $this->request('GET', 'health', null, []);
    }

    /** @param array<string, string> $extra @return array<string, string> */
    private function headers(array $extra = []): array
    {
        $key = Env::get('LOBBY_SERVICE_KEY');
        if ($key === '') {
            return $extra;
        }
        $headers = $extra + ['X-Service-Key' => $key];
        if ($this->actorUuid !== '') {
            $headers['X-Actor-Uuid'] = $this->actorUuid;
        }

        return $headers;
    }
}
