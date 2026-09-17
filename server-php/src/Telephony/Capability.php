<?php

declare(strict_types=1);

namespace Aicountly\Api\Telephony;

/**
 * What a telephony provider can actually do.
 *
 * THE RULE: the UI renders a control only when the adapter serving this
 * company's connection reports the matching capability. There is no hardcoded
 * list of buttons anywhere in this product.
 *
 * That is not a nicety. A Hold button that silently does nothing — because the
 * carrier has no hold operation and the adapter quietly mapped it to mute —
 * leaves a customer listening to an agent talk about them. A Transfer button
 * that fails halfway drops the call. If a provider cannot do a thing, the
 * control is absent and the screen says why.
 *
 * MUTE AND HOLD ARE DIFFERENT CAPABILITIES and different operations. Mute stops
 * the agent's microphone reaching the caller. Hold is a telephony operation
 * that parks the far leg, usually with music, and tells the caller they are on
 * hold. Mapping one to the other is the single most common way this goes wrong.
 */
final class Capability
{
    public const PLACE_CALL        = 'place_call';
    public const RECEIVE_CALL      = 'receive_call';
    public const END_CALL          = 'end_call';
    public const MUTE              = 'mute';
    public const HOLD              = 'hold';
    public const DTMF              = 'dtmf';
    public const BLIND_TRANSFER    = 'blind_transfer';
    public const ATTENDED_TRANSFER = 'attended_transfer';
    public const RECORDING         = 'recording';
    public const RECORDING_PAUSE   = 'recording_pause';
    /** Supervisor listen / whisper / barge. Rare, and never assumed. */
    public const MONITOR_LISTEN    = 'monitor_listen';
    public const MONITOR_WHISPER   = 'monitor_whisper';
    public const MONITOR_BARGE     = 'monitor_barge';
    public const BROWSER_CALLING   = 'browser_calling';
    public const LIVE_TRANSCRIPT   = 'live_transcript';
    public const TTS_PLAYBACK      = 'tts_playback';
    public const NUMBER_MANAGEMENT = 'number_management';
    public const USAGE_RETRIEVAL   = 'usage_retrieval';
    public const SIGNED_WEBHOOKS   = 'signed_webhooks';
    /** Failover to a backup route BEFORE the call connects. Mid-call migration is a separate, rarer thing. */
    public const PRECONNECT_FAILOVER = 'preconnect_failover';

    /** @return list<string> */
    public static function all(): array
    {
        return [
            self::PLACE_CALL, self::RECEIVE_CALL, self::END_CALL,
            self::MUTE, self::HOLD, self::DTMF,
            self::BLIND_TRANSFER, self::ATTENDED_TRANSFER,
            self::RECORDING, self::RECORDING_PAUSE,
            self::MONITOR_LISTEN, self::MONITOR_WHISPER, self::MONITOR_BARGE,
            self::BROWSER_CALLING, self::LIVE_TRANSCRIPT, self::TTS_PLAYBACK,
            self::NUMBER_MANAGEMENT, self::USAGE_RETRIEVAL, self::SIGNED_WEBHOOKS,
            self::PRECONNECT_FAILOVER,
        ];
    }

    /**
     * Normalise whatever an adapter reported into a complete, boolean map.
     *
     * Everything not explicitly claimed is FALSE. A capability that was simply
     * not mentioned is not a capability, and defaulting the other way is how a
     * missing key becomes a broken button.
     *
     * @param array<string, mixed> $claimed
     * @return array<string, bool>
     */
    public static function normalise(array $claimed): array
    {
        $out = [];
        foreach (self::all() as $capability) {
            $out[$capability] = (bool) ($claimed[$capability] ?? false);
        }

        return $out;
    }

    /** Words for a person, when a control is missing. */
    public static function describe(string $capability): string
    {
        return match ($capability) {
            self::HOLD              => 'putting a call on hold',
            self::MUTE              => 'muting the microphone',
            self::BLIND_TRANSFER    => 'transferring a call',
            self::ATTENDED_TRANSFER => 'announced transfers',
            self::DTMF              => 'sending keypad tones',
            self::RECORDING         => 'call recording',
            self::MONITOR_LISTEN    => 'listening to a call in progress',
            self::BROWSER_CALLING   => 'calling from the browser',
            self::LIVE_TRANSCRIPT   => 'live transcription',
            self::NUMBER_MANAGEMENT => 'managing numbers from here',
            self::USAGE_RETRIEVAL   => 'reading usage from the provider',
            self::PRECONNECT_FAILOVER => 'failing over to a backup route',
            default                 => str_replace('_', ' ', $capability),
        };
    }
}
