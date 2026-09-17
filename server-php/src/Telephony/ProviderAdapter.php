<?php

declare(strict_types=1);

namespace Aicountly\Api\Telephony;

/**
 * The contract every telephony provider is reached through.
 *
 * ## What an adapter is, and what it is not
 *
 * An adapter is the CONTROL PLANE for one carrier or gateway account: it asks
 * for a call to be placed, asks for one to be ended, reports what the provider
 * can do, and verifies the provider's callbacks. It is a small class that makes
 * HTTP requests and normalises the answers.
 *
 * It is NOT where media lives. SIP negotiation, RTP, WebRTC, streaming speech
 * recognition, barge-in and audio playback happen in the Voice Gateway — a
 * separate service, on its own process model, reached over HTTP and WebSocket.
 * None of that can happen inside a PHP web request: a PHP-FPM worker handling a
 * 30-second HTTP request cannot also carry a 12-minute audio stream, and
 * pretending otherwise produces a product that works in a demo and dies at
 * three concurrent calls.
 *
 * ## Writing a new adapter
 *
 * Against the carrier's REAL, CURRENT documentation. Their authentication
 * scheme, their endpoint paths, their status vocabulary, their signature
 * algorithm. An adapter written from a plausible guess at a carrier's API is
 * worse than no adapter: it looks finished, and it fails on the first live
 * call, in production, to a customer.
 *
 * This repository ships two adapters — the Aicountly Voice Gateway, whose
 * contract we define, and a Null adapter that refuses every action with a
 * reason a person can act on. Exotel, Airtel IQ, Tata, Twilio and the rest are
 * deliberately absent: each needs its own file, written against its own docs
 * with credentials to test against.
 *
 * Every method returns a normalised result rather than throwing: a carrier
 * being unreachable is an ordinary Tuesday and the call screen has to show
 * something useful when it happens.
 */
interface ProviderAdapter
{
    /** Adapter key, matching voice_provider_connections.provider. */
    public function key(): string;

    /** Human name for the Integrations and Network screens. */
    public function label(): string;

    /**
     * What this connection can do.
     *
     * Reported from the adapter's own knowledge of the provider, narrowed by
     * what this particular account is provisioned for where the provider
     * exposes that. Read by the UI to decide which controls exist at all.
     *
     * @return array<string, bool> a complete Capability map
     */
    public function capabilities(): array;

    /**
     * Is this connection usable right now?
     *
     * A real check against the provider, not a cached flag. Used by the Network
     * dashboard and by the pre-flight check before a campaign dispatches.
     *
     * @return array{ok: bool, status: string, detail: string, latency_ms: ?int}
     */
    public function healthCheck(): array;

    /**
     * Ask the provider to place a call.
     *
     * Returns as soon as the provider has ACCEPTED the request — which is not
     * the same as the call connecting. The lifecycle after that arrives as
     * signed callbacks, and voice_calls.state follows those, never a timer.
     */
    public function placeCall(CallRequest $request): CallResult;

    /** Hang up. `$providerRef` is the carrier's own call identifier. */
    public function endCall(string $providerRef, string $reason = 'agent_ended'): CallResult;

    /**
     * Microphone off. NOT hold — see Capability.
     *
     * Refuses with `capability_unsupported` where the provider has no such
     * operation, rather than silently doing something adjacent.
     */
    public function setMute(string $providerRef, bool $muted): CallResult;

    /** Park the far leg. NOT mute. */
    public function setHold(string $providerRef, bool $held): CallResult;

    public function sendDtmf(string $providerRef, string $digits): CallResult;

    /**
     * Transfer. `$mode` is 'blind' or 'attended'; an adapter that supports only
     * one refuses the other by name rather than downgrading it, because an
     * announced transfer that silently becomes a blind one drops the customer
     * on somebody who was never asked.
     */
    public function transfer(string $providerRef, string $destination, string $mode = 'blind'): CallResult;

    /** Start or stop recording mid-call, where the provider allows it. */
    public function setRecording(string $providerRef, bool $recording): CallResult;

    /**
     * Supervisor monitoring. Separate from everything else because it is
     * surveillance of a colleague and must be capability-gated, permission-
     * gated and audited.
     */
    public function monitor(string $providerRef, string $supervisorEndpoint, string $mode = 'listen'): CallResult;

    /**
     * Confirm a callback really came from this provider.
     *
     * The provider's OWN scheme — HMAC over the raw body, a signed JWT,
     * mutual TLS, whatever they actually use. Never "the request reached us, so
     * it must be them". Includes replay protection via the timestamp the
     * provider signs.
     *
     * @param array<string, string> $headers
     */
    public function verifyWebhook(string $rawBody, array $headers): bool;

    /**
     * Turn one provider callback into the fields Voice's state machine speaks.
     *
     * @param array<string, mixed> $payload
     * @return array{
     *   provider_event_id: string, event_type: string, provider_ref: ?string,
     *   leg_ref: ?string, state: ?string, timestamp: ?string, sequence: ?int,
     *   detail: array<string, mixed>
     * }|null null when the payload is not an event this adapter recognises
     */
    public function parseWebhook(array $payload): ?array;

    /**
     * Usage the provider has CONFIRMED, for a period.
     *
     * Kept apart from Voice's own estimates: see voice_usage_entries.basis.
     *
     * @return array{ok: bool, entries: list<array<string, mixed>>, error: ?string}
     */
    public function fetchUsage(string $startIso, string $endIso): array;
}
