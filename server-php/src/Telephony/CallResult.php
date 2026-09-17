<?php

declare(strict_types=1);

namespace Aicountly\Api\Telephony;

/**
 * What came back from asking a provider to do something.
 *
 * Three outcomes, and the third is the one that matters:
 *
 *   ok        — the provider accepted the instruction.
 *   refused   — the provider (or this adapter) said no, for a reason we can
 *               show: unsupported capability, bad number, no credit.
 *   unknown   — we do not know. The request may have been carried out.
 *
 * `unknown` exists for the same reason ExternalOperations::UNKNOWN does. A
 * place-call request that times out may have rung somebody's phone. Reporting
 * that as a failure and letting the agent press Call again is how a customer
 * gets two calls thirty seconds apart.
 */
final class CallResult
{
    private function __construct(
        public readonly string $outcome,      // ok | refused | unknown
        public readonly ?string $providerRef,
        public readonly ?string $code,
        public readonly ?string $message,
        public readonly array $detail = [],
    ) {
    }

    public static function ok(?string $providerRef = null, array $detail = []): self
    {
        return new self('ok', $providerRef, null, null, $detail);
    }

    public static function refused(string $code, string $message, array $detail = []): self
    {
        return new self('refused', null, $code, $message, $detail);
    }

    /** The provider did not answer, or answered in a way that settles nothing. */
    public static function unknown(string $message, array $detail = []): self
    {
        return new self('unknown', null, 'outcome_unknown', $message, $detail);
    }

    /** The standard refusal for a control the provider does not have. */
    public static function unsupported(string $capability): self
    {
        return self::refused(
            'capability_unsupported',
            'This connection does not support ' . Capability::describe($capability) . '.',
            ['capability' => $capability],
        );
    }

    public function isOk(): bool
    {
        return $this->outcome === 'ok';
    }

    public function isUnknown(): bool
    {
        return $this->outcome === 'unknown';
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'outcome'      => $this->outcome,
            'provider_ref' => $this->providerRef,
            'code'         => $this->code,
            'message'      => $this->message,
            'detail'       => $this->detail,
        ];
    }
}
