<?php

declare(strict_types=1);

namespace Aicountly\Api\Clients;

use Aicountly\Api\Env;
use Aicountly\Api\Environment;
use Aicountly\Api\Features;

/**
 * Live calls to Aicountly Messaging.
 *
 * Messaging owns SMS and WhatsApp. Voice owns the call. When a call ends with
 * "I'll text you the link", the message is SENT BY MESSAGING — Voice asks, and
 * stores the reference it gets back.
 *
 * Voice does not hold a message template library, a delivery-status table or a
 * send queue. Those exist once, in the product whose job they are.
 */
final class MessagingClient extends ApiClient
{
    private string $actorUuid = '';

    public function service(): string
    {
        return 'messaging';
    }

    protected function productionBase(): string
    {
        return 'https://messaging.aicountly.com';
    }

    protected function sandboxBase(): string
    {
        return 'https://messaging.gh.aicountly.com';
    }

    protected function baseEnvKey(): string
    {
        return 'MESSAGING_API_BASE';
    }

    public function forActor(string $actorUuid): self
    {
        $clone = clone $this;
        $clone->actorUuid = trim($actorUuid);

        return $clone;
    }

    public function configured(): bool
    {
        return Features::enabled('MESSAGING');
    }

    /**
     * Messaging's service send (E8-ID / F155): `POST v1/messages`, which Messaging serves — the
     * old `messages` path was never one of its routes — with the environment this call is meant
     * for. Messaging refuses a service call without X-AIC-Environment equal to its own (401
     * service_environment_mismatch), so with no environment configured here nothing is sent.
     *
     * @param array<string, mixed> $payload
     */
    public function send(array $payload, string $correlationId): array
    {
        $refused = $this->environmentRefusal('v1/messages');
        if ($refused !== null) {
            return $refused;
        }

        return $this->request('POST', 'v1/messages', $payload, $this->headers([
            'Idempotency-Key' => $correlationId,
        ]), true);
    }

    public function message(string $messageRef, int $cmpId): array
    {
        $path = 'v1/messages/' . rawurlencode($messageRef) . '?' . http_build_query(['cmp_id' => $cmpId]);
        $refused = $this->environmentRefusal($path);
        if ($refused !== null) {
            return $refused;
        }

        return $this->request('GET', $path, null, $this->headers());
    }

    /** @return array{ok:bool, status:int, body:?array, error:?string}|null */
    private function environmentRefusal(string $path): ?array
    {
        if (Environment::current() !== null) {
            return null;
        }
        $this->log('refused', $path, 0, 0.0, 'environment_not_configured');

        return ['ok' => false, 'status' => 0, 'body' => null, 'error' => 'environment_not_configured'];
    }

    public function health(): array
    {
        return $this->request('GET', 'health', null, []);
    }

    /** @param array<string, string> $extra @return array<string, string> */
    private function headers(array $extra = []): array
    {
        $key = Env::get('MESSAGING_SERVICE_KEY');
        if ($key === '') {
            return $extra;
        }
        $headers = $extra + ['X-Service-Key' => $key, 'X-AIC-Environment' => (string) Environment::current()];
        if ($this->actorUuid !== '') {
            $headers['X-Actor-Uuid'] = $this->actorUuid;
        }

        return $headers;
    }
}
