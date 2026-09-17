<?php

declare(strict_types=1);

namespace Aicountly\Api\Clients;

use Aicountly\Api\Env;
use Aicountly\Api\Features;

/**
 * Live reads and writes against Aicountly Pay.
 *
 * Pay owns payment links and payment status. Voice never holds a payment
 * object, never mirrors a ledger, and — above all — never decides that a
 * payment succeeded. Money is the one place where "we think it worked" is worth
 * nothing: a call may only tell a customer they have paid once Pay says so.
 *
 * A call that takes a payment stores a `payment_link_ref` and reads the status
 * from Pay whenever it is displayed.
 */
final class PayClient extends ApiClient
{
    private string $actorUuid = '';
    private string $sesKey = '';

    public function service(): string
    {
        return 'pay';
    }

    protected function productionBase(): string
    {
        return 'https://pay.aicountly.com';
    }

    protected function sandboxBase(): string
    {
        return 'https://pay.gh.aicountly.com';
    }

    protected function baseEnvKey(): string
    {
        return 'PAY_API_BASE';
    }

    public function forActor(string $actorUuid): self
    {
        $clone = clone $this;
        $clone->actorUuid = trim($actorUuid);

        return $clone;
    }

    public function withSession(string $sesKey): self
    {
        $clone = clone $this;
        $clone->sesKey = trim($sesKey);

        return $clone;
    }

    public function configured(): bool
    {
        return Features::enabled('PAY');
    }

    /** @param array<string, mixed> $payload */
    public function createPaymentLink(array $payload, string $correlationId): array
    {
        return $this->request('POST', 'payment-links', $payload, $this->headers([
            'Idempotency-Key' => $correlationId,
        ]), true);
    }

    /** Authoritative status. Never inferred, never cached to a table. */
    public function paymentLink(string $linkRef): array
    {
        return $this->request('GET', 'payment-links/' . rawurlencode($linkRef), null, $this->headers());
    }

    public function findByCorrelation(string $correlationId): array
    {
        return $this->request(
            'GET',
            'payment-links' . self::query(['correlation_id' => $correlationId, 'limit' => 1]),
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
        $key = Env::get('PAY_SERVICE_KEY');
        if ($key !== '' && $this->actorUuid !== '') {
            return $extra + ['X-Service-Key' => $key, 'X-Actor-Uuid' => $this->actorUuid];
        }
        if ($this->sesKey !== '') {
            return $extra + ['Authorization' => 'Bearer ' . $this->sesKey];
        }

        return $extra;
    }
}
