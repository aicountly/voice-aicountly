<?php

declare(strict_types=1);

namespace Aicountly\Api\Telephony;

/**
 * The adapter for a company that has no working telephony connection.
 *
 * It exists so that "no provider configured" is a FIRST-CLASS STATE with a
 * clear explanation, rather than a null reference somewhere between the Call
 * button and a 500.
 *
 * Every capability is false, so the console renders no call controls at all and
 * the Network screen shows the connection as not configured. Every action is
 * refused with a reason an administrator can act on. Nothing here pretends, and
 * nothing here half-works.
 */
final class NullAdapter implements ProviderAdapter
{
    public function __construct(private readonly string $reason = 'No telephony provider is connected for this company.')
    {
    }

    public function key(): string
    {
        return 'null';
    }

    public function label(): string
    {
        return 'Not connected';
    }

    /** Everything false. A company with no carrier can do nothing on the phone. */
    public function capabilities(): array
    {
        return Capability::normalise([]);
    }

    public function healthCheck(): array
    {
        return [
            'ok'         => false,
            'status'     => 'not_configured',
            'detail'     => $this->reason,
            'latency_ms' => null,
        ];
    }

    public function placeCall(CallRequest $request): CallResult
    {
        return $this->refuse();
    }

    public function endCall(string $providerRef, string $reason = 'agent_ended'): CallResult
    {
        return $this->refuse();
    }

    public function setMute(string $providerRef, bool $muted): CallResult
    {
        return $this->refuse();
    }

    public function setHold(string $providerRef, bool $held): CallResult
    {
        return $this->refuse();
    }

    public function sendDtmf(string $providerRef, string $digits): CallResult
    {
        return $this->refuse();
    }

    public function transfer(string $providerRef, string $destination, string $mode = 'blind'): CallResult
    {
        return $this->refuse();
    }

    public function setRecording(string $providerRef, bool $recording): CallResult
    {
        return $this->refuse();
    }

    public function monitor(string $providerRef, string $supervisorEndpoint, string $mode = 'listen'): CallResult
    {
        return $this->refuse();
    }

    /**
     * Never. With no provider there is no signing secret, so there is no
     * callback that could legitimately be from one.
     */
    public function verifyWebhook(string $rawBody, array $headers): bool
    {
        return false;
    }

    public function parseWebhook(array $payload): ?array
    {
        return null;
    }

    public function fetchUsage(string $startIso, string $endIso): array
    {
        return ['ok' => false, 'entries' => [], 'error' => $this->reason];
    }

    private function refuse(): CallResult
    {
        return CallResult::refused('provider_not_configured', $this->reason);
    }
}
