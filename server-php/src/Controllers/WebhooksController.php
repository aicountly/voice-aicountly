<?php

declare(strict_types=1);

namespace Aicountly\Api\Controllers;

use Aicountly\Api\Audit;
use Aicountly\Api\Context;
use Aicountly\Api\Db;
use Aicountly\Api\Domain\CallStateMachine;
use Aicountly\Api\Domain\RecordingService;
use Aicountly\Api\Domain\UsageService;
use Aicountly\Api\Http;
use Aicountly\Api\Telephony\ProviderRegistry;

/**
 * Telephony provider callbacks.
 *
 * ## This route carries NO Aicountly identity
 *
 * A carrier has no session and no service key. It is authenticated by the
 * provider's OWN signature scheme, against the connection it claims to be for,
 * and that authentication grants exactly one thing: the ability to move
 * Voice-owned call state on that connection. It cannot read a transcript,
 * launch a campaign, or reach another product.
 *
 * ## Why almost everything answers 200
 *
 * A carrier that receives a non-2xx retries — often for hours, with backoff
 * that gets more aggressive rather than less. So a duplicate, an out-of-order
 * event, or an event for a call we do not recognise all answer 200 with a body
 * saying what was done. The only non-2xx answers are "the signature did not
 * verify" and "this connection does not exist", because both mean the sender
 * should stop.
 *
 * ## Replay protection
 *
 * Two layers. The adapter checks the signed timestamp, so yesterday's captured
 * callback is refused outright. The unique index on
 * (connection_id, provider_event_id) catches anything that passes that and is
 * still a repeat.
 */
final class WebhooksController extends Controller
{
    /** Bigger than any legitimate callback; a defence against a body that is an attack. */
    private const MAX_BODY_BYTES = 262144;

    public static function telephony(string $connectionId): never
    {
        $connection = Db::first(
            'SELECT * FROM voice_provider_connections WHERE connection_id = :id',
            ['id' => (int) $connectionId],
        );

        if ($connection === null) {
            // Not found, not unauthorised: there is nothing here to sign for.
            Http::notFound('Unknown connection.');
        }

        $raw = (string) file_get_contents('php://input');
        if (strlen($raw) > self::MAX_BODY_BYTES) {
            Http::error(413, 'payload_too_large', 'That callback is too large to process.');
        }

        $adapter = ProviderRegistry::build($connection);

        if (!$adapter->verifyWebhook($raw, self::headers())) {
            // Deliberately terse. A verification failure must not hint at which
            // part of the signature was wrong.
            Http::error(401, 'signature_invalid', 'Signature verification failed.');
        }

        $decoded = json_decode($raw, true);
        if (!is_array($decoded)) {
            Http::error(400, 'malformed', 'That callback is not JSON.');
        }

        $event = $adapter->parseWebhook($decoded);
        if ($event === null) {
            // Signed, but not an event this adapter knows. 200, because the
            // carrier retrying it forever helps nobody.
            Http::json(200, ['data' => ['outcome' => 'ignored', 'reason' => 'unrecognised_event']]);
        }

        $cmpId = (int) $connection['cmp_id'];
        $ctx = Context::forCompany($cmpId, (int) $connection['bo_id']);
        $callId = self::resolveCall($cmpId, $event, $decoded);

        $result = CallStateMachine::applyProviderEvent(
            $cmpId,
            (int) $connection['connection_id'],
            $callId,
            $event,
        );

        // Events that carry something beyond a state change.
        if ($result['outcome'] !== 'duplicate' && $callId !== null) {
            self::applySideEffects($ctx, $callId, $event, $decoded);
        }

        if ($result['outcome'] === 'applied' && CallStateMachine::isTerminal((string) $result['state'])) {
            UsageService::recordCallEstimate($ctx, $callId);
            Audit::fromProvider($ctx, 'voice.call.' . $result['state'], (string) $callId, [
                'event_type' => $event['event_type'],
            ]);
        }

        // Always 200 for anything correctly signed. The body says what happened
        // so a provider's dashboard shows something useful.
        Http::json(200, ['data' => [
            'outcome' => $result['outcome'],
            'state'   => $result['state'],
            'reason'  => $result['reason'],
        ]]);
    }

    /**
     * Which call this event belongs to.
     *
     * By our own correlation id first — we send it when placing the call, so it
     * works even for an event that arrives before the provider's reference has
     * been written. Then by the provider's leg reference.
     */
    private static function resolveCall(int $cmpId, array $event, array $payload): ?int
    {
        $correlationId = (string) ($payload['correlation_id'] ?? $payload['metadata']['call_uuid'] ?? '');
        if ($correlationId !== '') {
            $id = Db::scalar(
                'SELECT call_id FROM voice_calls WHERE cmp_id = :cmp AND call_uuid = :uuid',
                ['cmp' => $cmpId, 'uuid' => $correlationId],
            );
            if ($id !== null) {
                return (int) $id;
            }
        }

        foreach ([$event['leg_ref'] ?? null, $event['provider_ref'] ?? null] as $ref) {
            if ($ref === null || $ref === '') {
                continue;
            }
            $id = Db::scalar(
                'SELECT call_id FROM voice_call_legs WHERE cmp_id = :cmp AND provider_leg_ref = :ref LIMIT 1',
                ['cmp' => $cmpId, 'ref' => (string) $ref],
            );
            if ($id !== null) {
                return (int) $id;
            }
        }

        return null;
    }

    /**
     * Events that do something other than move state.
     *
     * A recording becoming available, a transcript segment arriving, a caller
     * declining to be recorded.
     */
    private static function applySideEffects(Context $ctx, int $callId, array $event, array $payload): void
    {
        switch ($event['event_type']) {
            case 'recording.available':
                RecordingService::register($ctx, $callId, [
                    'storage_key'      => $payload['storage_key'] ?? null,
                    'provider_ref'     => $payload['recording_ref'] ?? null,
                    'media_type'       => $payload['media_type'] ?? 'audio/mpeg',
                    'duration_seconds' => (int) ($payload['duration_seconds'] ?? 0),
                    'bytes'            => (int) ($payload['bytes'] ?? 0),
                    'status'           => 'available',
                ]);
                Db::update('voice_calls', ['recording_state' => 'stored'], ['call_id' => $callId, 'cmp_id' => $ctx->cmpId]);
                break;

            case 'consent.refused':
                // Recorded as evidence of what the caller said, and recording
                // stops. A refusal that only changes a flag somewhere is not a
                // refusal that was acted on.
                Db::update('voice_calls', [
                    'consent_state'    => 'refused',
                    'recording_state'  => 'refused',
                    'consent_evidence' => [
                        'at'     => $payload['occurred_at'] ?? null,
                        'source' => 'provider_event',
                    ],
                ], ['call_id' => $callId, 'cmp_id' => $ctx->cmpId]);
                break;

            case 'consent.granted':
                Db::update('voice_calls', [
                    'consent_state'    => 'granted',
                    'consent_evidence' => ['at' => $payload['occurred_at'] ?? null, 'source' => 'provider_event'],
                ], ['call_id' => $callId, 'cmp_id' => $ctx->cmpId]);
                break;

            case 'transcript.segment':
                self::appendSegment($ctx, $callId, $payload);
                break;
        }
    }

    /**
     * One transcript segment.
     *
     * ON CONFLICT DO UPDATE on (call_id, sequence_no) because a recogniser
     * revises: the same sequence arrives partial, then final. The final one
     * replaces the partial rather than appending a near-duplicate line.
     */
    private static function appendSegment(Context $ctx, int $callId, array $payload): void
    {
        $sequence = (int) ($payload['sequence_no'] ?? 0);
        if ($sequence <= 0) {
            return;
        }

        Db::run(
            'INSERT INTO voice_transcript_segments
                (call_id, cmp_id, sequence_no, speaker, speaker_label, started_ms, ended_ms,
                 is_final, language, text, translated_text, translated_to, confidence)
             VALUES (:call, :cmp, :seq, :speaker, :label, :start, :end,
                     :final, :lang, :text, :translated, :to, :confidence)
             ON CONFLICT (call_id, sequence_no) DO UPDATE SET
                text = EXCLUDED.text,
                translated_text = EXCLUDED.translated_text,
                is_final = EXCLUDED.is_final,
                ended_ms = EXCLUDED.ended_ms,
                confidence = EXCLUDED.confidence',
            [
                'call'       => $callId,
                'cmp'        => $ctx->cmpId,
                'seq'        => $sequence,
                'speaker'    => (string) ($payload['speaker'] ?? 'unknown'),
                'label'      => $payload['speaker_label'] ?? null,
                'start'      => (int) ($payload['started_ms'] ?? 0),
                'end'        => isset($payload['ended_ms']) ? (int) $payload['ended_ms'] : null,
                'final'      => !empty($payload['is_final']) ? 'true' : 'false',
                'lang'       => $payload['language'] ?? null,
                'text'       => (string) ($payload['text'] ?? ''),
                'translated' => $payload['translated_text'] ?? null,
                'to'         => $payload['translated_to'] ?? null,
                'confidence' => isset($payload['confidence']) ? (float) $payload['confidence'] : null,
            ],
        );
    }

    /** @return array<string, string> */
    private static function headers(): array
    {
        $out = [];
        foreach ($_SERVER as $key => $value) {
            if (is_string($key) && str_starts_with($key, 'HTTP_') && is_string($value)) {
                $out[strtolower(str_replace('_', '-', substr($key, 5)))] = $value;
            }
        }

        return $out;
    }
}
