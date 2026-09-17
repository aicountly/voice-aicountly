<?php

declare(strict_types=1);

namespace Aicountly\Api\Clients;

use Aicountly\Api\Env;
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

    /** @param array<string, mixed> $payload */
    public function send(array $payload, string $correlationId): array
    {
        return $this->request('POST', 'messages', $payload, $this->headers([
            'Idempotency-Key' => $correlationId,
        ]), true);
    }

    public function message(string $messageRef): array
    {
        return $this->request('GET', 'messages/' . rawurlencode($messageRef), null, $this->headers());
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
        $headers = $extra + ['X-Service-Key' => $key];
        if ($this->actorUuid !== '') {
            $headers['X-Actor-Uuid'] = $this->actorUuid;
        }

        return $headers;
    }
}
