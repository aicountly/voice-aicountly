<?php

declare(strict_types=1);

namespace Aicountly\Api\Telephony;

use Aicountly\Api\Env;

/**
 * Control-plane client for the Aicountly Voice Gateway.
 *
 * ## The division of labour
 *
 * The Gateway is a separate service that owns everything PHP cannot do:
 * SIP registration and negotiation, RTP and WebRTC media, streaming speech
 * recognition and synthesis, barge-in, and playback control. It holds the
 * carrier trunk credentials and speaks to the carrier.
 *
 * This class is the other half: it asks the Gateway to place or change a call
 * over ordinary HTTP, and it verifies the Gateway's callbacks. Nothing here
 * touches audio, and no method blocks for the length of a conversation.
 *
 * The Gateway's contract is OURS — it is an Aicountly service — which is why
 * this adapter can be written and shipped now. A carrier adapter cannot be,
 * because its contract belongs to the carrier and must be written from their
 * documentation against their credentials.
 *
 * ## Capabilities
 *
 * Asked for, not assumed. `GET /capabilities` returns what this Gateway build
 * and this trunk can actually do, and that answer drives which controls the
 * console renders. A Gateway that reports no `hold` gets no Hold button.
 *
 * With `VOICE_GATEWAY_URL` unset this adapter is never constructed —
 * ProviderRegistry hands back a NullAdapter instead, which refuses clearly.
 */
final class GatewayAdapter implements ProviderAdapter
{
    private const CONNECT_TIMEOUT = 3;
    private const TIMEOUT = 10;

    /** Callbacks older than this are replays, not news. */
    private const WEBHOOK_MAX_AGE_SECONDS = 300;

    /** @var array<string, bool>|null */
    private ?array $capabilities = null;

    /**
     * @param array<string, mixed> $config non-secret per-connection settings
     */
    public function __construct(
        private readonly int $connectionId,
        private readonly array $config = [],
        private readonly string $signingSecret = '',
    ) {
    }

    public function key(): string
    {
        return 'gateway';
    }

    public function label(): string
    {
        return 'Aicountly Voice Gateway';
    }

    public function capabilities(): array
    {
        if ($this->capabilities !== null) {
            return $this->capabilities;
        }

        $result = $this->call('GET', 'capabilities');
        if (!$result['ok'] || !is_array($result['body'])) {
            // Unreachable is not "everything works". An empty capability map
            // means the console draws no controls, which is the correct thing
            // to show when we cannot confirm a single one of them.
            return $this->capabilities = Capability::normalise([]);
        }

        $claimed = $result['body']['capabilities'] ?? $result['body']['data']['capabilities'] ?? [];

        return $this->capabilities = Capability::normalise(is_array($claimed) ? $claimed : []);
    }

    public function healthCheck(): array
    {
        $startedAt = microtime(true);
        $result = $this->call('GET', 'health');
        $latency = (int) ((microtime(true) - $startedAt) * 1000);

        if (!$result['ok']) {
            return [
                'ok'         => false,
                'status'     => $result['status'] === 0 ? 'unavailable' : 'degraded',
                'detail'     => $result['status'] === 0
                    ? 'The voice gateway did not respond.'
                    : 'The voice gateway answered ' . $result['status'] . '.',
                'latency_ms' => $latency,
            ];
        }

        $body = $result['body'] ?? [];
        $trunk = (string) ($body['trunk_status'] ?? $body['data']['trunk_status'] ?? 'unknown');

        return [
            'ok'         => $trunk === 'registered' || $trunk === 'ok',
            'status'     => match ($trunk) {
                'registered', 'ok' => 'connected',
                'unregistered'     => 'degraded',
                default            => 'unknown',
            },
            'detail'     => (string) ($body['detail'] ?? $body['data']['detail'] ?? 'Gateway reachable; trunk ' . $trunk . '.'),
            'latency_ms' => $latency,
        ];
    }

    public function placeCall(CallRequest $request): CallResult
    {
        $result = $this->call('POST', 'calls', $request->toArray() + [
            'connection_id' => $this->connectionId,
        ]);

        if ($result['ok']) {
            $ref = $this->extract($result['body'], ['call_ref', 'provider_ref', 'id']);

            // Accepted, but with nothing to address later. We cannot end, hold
            // or transfer a call we have no handle on — and it may well be
            // ringing — so this is unknown, not success.
            return $ref === null
                ? CallResult::unknown('The gateway accepted the call but returned no reference.')
                : CallResult::ok($ref, ['accepted' => true]);
        }

        // A dial that may have gone out. Never re-dialled on the strength of this.
        if ($result['status'] === 0 || $result['status'] >= 500) {
            return CallResult::unknown(
                'The gateway did not confirm the call. Check the call list before dialling again.',
            );
        }

        return CallResult::refused(
            (string) ($result['code'] ?? 'call_refused'),
            (string) ($result['error'] ?? 'The gateway refused the call.'),
        );
    }

    public function endCall(string $providerRef, string $reason = 'agent_ended'): CallResult
    {
        return $this->command($providerRef, 'hangup', ['reason' => $reason], Capability::END_CALL);
    }

    public function setMute(string $providerRef, bool $muted): CallResult
    {
        // Deliberately its own command. Mapping this onto hold would silence the
        // agent's microphone while leaving the caller listening to the room.
        return $this->command($providerRef, $muted ? 'mute' : 'unmute', [], Capability::MUTE);
    }

    public function setHold(string $providerRef, bool $held): CallResult
    {
        return $this->command($providerRef, $held ? 'hold' : 'resume', [], Capability::HOLD);
    }

    public function sendDtmf(string $providerRef, string $digits): CallResult
    {
        $digits = preg_replace('/[^0-9A-D#*]/i', '', $digits) ?? '';
        if ($digits === '') {
            return CallResult::refused('invalid_dtmf', 'Those are not keypad digits.');
        }

        return $this->command($providerRef, 'dtmf', ['digits' => substr($digits, 0, 32)], Capability::DTMF);
    }

    public function transfer(string $providerRef, string $destination, string $mode = 'blind'): CallResult
    {
        $capability = $mode === 'attended' ? Capability::ATTENDED_TRANSFER : Capability::BLIND_TRANSFER;

        // An attended transfer that quietly becomes a blind one hands the
        // customer to somebody who was never asked whether they could take it.
        if (!($this->capabilities()[$capability] ?? false)) {
            return CallResult::unsupported($capability);
        }

        return $this->command($providerRef, 'transfer', [
            'destination' => $destination,
            'mode'        => $mode,
        ], $capability);
    }

    public function setRecording(string $providerRef, bool $recording): CallResult
    {
        return $this->command(
            $providerRef,
            $recording ? 'record_start' : 'record_stop',
            [],
            $recording ? Capability::RECORDING : Capability::RECORDING_PAUSE,
        );
    }

    public function monitor(string $providerRef, string $supervisorEndpoint, string $mode = 'listen'): CallResult
    {
        $capability = match ($mode) {
            'whisper' => Capability::MONITOR_WHISPER,
            'barge'   => Capability::MONITOR_BARGE,
            default   => Capability::MONITOR_LISTEN,
        };

        return $this->command($providerRef, 'monitor', [
            'endpoint' => $supervisorEndpoint,
            'mode'     => $mode,
        ], $capability);
    }

    /**
     * HMAC-SHA256 over `timestamp.rawBody`, compared in constant time.
     *
     * The timestamp is INSIDE the signed material, which is what makes the age
     * check meaningful: an attacker replaying yesterday's callback cannot move
     * the timestamp forward without invalidating the signature.
     *
     * @param array<string, string> $headers
     */
    public function verifyWebhook(string $rawBody, array $headers): bool
    {
        if ($this->signingSecret === '') {
            // No secret configured means no callback can be trusted. Accepting
            // unsigned callbacks would let anyone who can reach this URL move
            // call state for any tenant.
            return false;
        }

        $signature = '';
        $timestamp = '';
        foreach ($headers as $name => $value) {
            $lower = strtolower((string) $name);
            if ($lower === 'x-voice-signature') {
                $signature = trim((string) $value);
            } elseif ($lower === 'x-voice-timestamp') {
                $timestamp = trim((string) $value);
            }
        }

        if ($signature === '' || $timestamp === '' || !ctype_digit($timestamp)) {
            return false;
        }

        if (abs(time() - (int) $timestamp) > self::WEBHOOK_MAX_AGE_SECONDS) {
            return false;
        }

        $expected = hash_hmac('sha256', $timestamp . '.' . $rawBody, $this->signingSecret);

        return hash_equals($expected, $signature);
    }

    /** @param array<string, mixed> $payload */
    public function parseWebhook(array $payload): ?array
    {
        $eventId = (string) ($payload['event_id'] ?? '');
        $type = (string) ($payload['event'] ?? $payload['type'] ?? '');
        if ($eventId === '' || $type === '') {
            return null;
        }

        return [
            'provider_event_id' => $eventId,
            'event_type'        => $type,
            'provider_ref'      => isset($payload['call_ref']) ? (string) $payload['call_ref'] : null,
            'leg_ref'           => isset($payload['leg_ref']) ? (string) $payload['leg_ref'] : null,
            'state'             => self::mapState($type, $payload),
            'timestamp'         => isset($payload['occurred_at']) ? (string) $payload['occurred_at'] : null,
            'sequence'          => isset($payload['sequence']) ? (int) $payload['sequence'] : null,
            'detail'            => is_array($payload['detail'] ?? null) ? $payload['detail'] : [],
        ];
    }

    public function fetchUsage(string $startIso, string $endIso): array
    {
        if (!($this->capabilities()[Capability::USAGE_RETRIEVAL] ?? false)) {
            return ['ok' => false, 'entries' => [], 'error' => 'This connection does not report usage.'];
        }

        $result = $this->call('GET', 'usage?' . http_build_query([
            'connection_id' => $this->connectionId,
            'start'         => $startIso,
            'end'           => $endIso,
        ]));

        if (!$result['ok']) {
            return ['ok' => false, 'entries' => [], 'error' => (string) ($result['error'] ?? 'Usage unavailable.')];
        }

        $entries = $result['body']['entries'] ?? $result['body']['data'] ?? [];

        return ['ok' => true, 'entries' => is_array($entries) ? array_values($entries) : [], 'error' => null];
    }

    /**
     * The gateway's state vocabulary, mapped onto ours.
     *
     * Anything unrecognised becomes null and the state machine leaves the call
     * alone. Guessing at an unknown event is how a live call gets marked
     * completed because a new event type shipped on the gateway.
     *
     * @param array<string, mixed> $payload
     */
    private static function mapState(string $type, array $payload): ?string
    {
        return match ($type) {
            'call.initiated'    => 'initiated',
            'call.queued'       => 'queued',
            'call.ringing'      => 'ringing',
            'call.answered'     => 'answered',
            'call.held'         => 'held',
            'call.resumed'      => 'answered',
            'call.transferring' => 'transferring',
            'call.completed'    => 'completed',
            'call.busy'         => 'busy',
            'call.no_answer'    => 'unanswered',
            'call.cancelled'    => 'cancelled',
            'call.failed'       => 'failed',
            default             => null,
        };
    }

    /** @param array<string, mixed> $body */
    private function command(string $providerRef, string $command, array $body, string $capability): CallResult
    {
        if (!($this->capabilities()[$capability] ?? false)) {
            return CallResult::unsupported($capability);
        }

        $result = $this->call('POST', 'calls/' . rawurlencode($providerRef) . '/commands', [
            'command' => $command,
        ] + $body);

        if ($result['ok']) {
            return CallResult::ok($providerRef, ['command' => $command]);
        }

        if ($result['status'] === 0 || $result['status'] >= 500) {
            return CallResult::unknown('The gateway did not confirm "' . $command . '".');
        }

        return CallResult::refused(
            (string) ($result['code'] ?? 'command_refused'),
            (string) ($result['error'] ?? 'The gateway refused "' . $command . '".'),
        );
    }

    /**
     * One HTTP call to the gateway.
     *
     * The gateway key is sent as a header and never appears in a URL, a log
     * line or a response.
     *
     * @param array<string, mixed>|null $body
     * @return array{ok:bool, status:int, body:?array, error:?string, code:?string}
     */
    private function call(string $method, string $path, ?array $body = null): array
    {
        $base = rtrim(Env::get('VOICE_GATEWAY_URL'), '/');
        if ($base === '') {
            return ['ok' => false, 'status' => 0, 'body' => null, 'error' => 'No gateway configured.', 'code' => 'not_configured'];
        }

        $ch = curl_init($base . '/' . ltrim($path, '/'));
        if ($ch === false) {
            return ['ok' => false, 'status' => 0, 'body' => null, 'error' => 'curl_init_failed', 'code' => null];
        }

        $headers = [
            'Accept: application/json',
            'X-Gateway-Key: ' . Env::get('VOICE_GATEWAY_KEY'),
        ];
        if ($body !== null) {
            $headers[] = 'Content-Type: application/json';
        }

        $options = [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CUSTOMREQUEST  => $method,
            CURLOPT_HTTPHEADER     => $headers,
            CURLOPT_CONNECTTIMEOUT => self::CONNECT_TIMEOUT,
            CURLOPT_TIMEOUT        => self::TIMEOUT,
        ];
        if ($body !== null) {
            $options[CURLOPT_POSTFIELDS] = json_encode($body, JSON_UNESCAPED_UNICODE);
        }
        curl_setopt_array($ch, $options);

        $raw = curl_exec($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        curl_close($ch);

        if ($raw === false || $status === 0) {
            return ['ok' => false, 'status' => 0, 'body' => null, 'error' => 'The voice gateway is unreachable.', 'code' => 'unreachable'];
        }

        $decoded = json_decode((string) $raw, true);
        $decoded = is_array($decoded) ? $decoded : null;

        if ($status >= 200 && $status < 300) {
            return ['ok' => true, 'status' => $status, 'body' => $decoded, 'error' => null, 'code' => null];
        }

        return [
            'ok'     => false,
            'status' => $status,
            'body'   => $decoded,
            'error'  => (string) ($decoded['error']['message'] ?? $decoded['message'] ?? 'HTTP ' . $status),
            'code'   => isset($decoded['error']['code']) ? (string) $decoded['error']['code'] : null,
        ];
    }

    /** @param array<string, mixed>|null $body @param list<string> $keys */
    private function extract(?array $body, array $keys): ?string
    {
        if ($body === null) {
            return null;
        }
        $candidates = [$body, $body['data'] ?? []];
        foreach ($candidates as $candidate) {
            if (!is_array($candidate)) {
                continue;
            }
            foreach ($keys as $key) {
                if (isset($candidate[$key]) && is_scalar($candidate[$key]) && (string) $candidate[$key] !== '') {
                    return (string) $candidate[$key];
                }
            }
        }

        return null;
    }
}
