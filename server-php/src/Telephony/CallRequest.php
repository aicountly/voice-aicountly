<?php

declare(strict_types=1);

namespace Aicountly\Api\Telephony;

/**
 * Everything an adapter needs to place one call.
 *
 * `correlationId` is Voice's own identifier, sent to the provider so their
 * callbacks can be matched to our row even before they have told us their id.
 * Without it, a callback arriving before the place-call response has been
 * written is an orphan.
 */
final class CallRequest
{
    /**
     * @param array<string, string> $metadata small, non-sensitive, echoed back in callbacks
     */
    public function __construct(
        public readonly string $toE164,
        public readonly string $fromE164,
        public readonly string $correlationId,
        /** 'browser' routes the agent leg through the gateway; an e164 or SIP URI rings a device. */
        public readonly string $agentEndpoint = '',
        public readonly bool $record = false,
        public readonly ?string $aiAgentRef = null,
        public readonly ?string $flowRef = null,
        public readonly int $timeoutSeconds = 45,
        public readonly array $metadata = [],
    ) {
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'to'              => $this->toE164,
            'from'            => $this->fromE164,
            'correlation_id'  => $this->correlationId,
            'agent_endpoint'  => $this->agentEndpoint,
            'record'          => $this->record,
            'ai_agent_ref'    => $this->aiAgentRef,
            'flow_ref'        => $this->flowRef,
            'timeout_seconds' => $this->timeoutSeconds,
            'metadata'        => $this->metadata,
        ];
    }
}
