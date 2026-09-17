<?php

declare(strict_types=1);

namespace Aicountly\Api;

/**
 * The record of what was done, for the actions somebody will have to account
 * for later.
 *
 * In a calling product that means, above all, ACCESS TO RECORDINGS. Playing
 * back a recording opens a private conversation between two people, at least
 * one of whom is not in the room. Downloading it takes that conversation out of
 * this product's retention and permission controls entirely. Both are logged
 * here with who did it and when, and that log is not optional.
 *
 * Also logged: supervisor monitoring, campaign launches, provider credential
 * changes, retention policy edits, exports, AI publishing and every write into
 * another product.
 *
 * WHAT NEVER REACHES THIS TABLE:
 *   - A signed playback URL. Logging one turns the audit trail into a set of
 *     working keys to the recordings it is meant to protect.
 *   - Transcript text. The audit trail says a transcript was read; it does not
 *     reproduce the conversation.
 *   - Any credential, in any form.
 *
 * Writing an audit row must never fail the operation it describes, so every
 * failure here is logged and swallowed. An audit trail that can 500 a call is
 * an audit trail that gets disabled.
 */
final class Audit
{
    public const TABLE = 'voice_audit_events';

    // The actions this product cares enough about to name.
    public const RECORDING_PLAYED     = 'voice.recording.played';
    public const RECORDING_DOWNLOADED = 'voice.recording.downloaded';
    public const RECORDING_DELETED    = 'voice.recording.deleted';
    public const TRANSCRIPT_VIEWED    = 'voice.transcript.viewed';
    public const CALL_PLACED          = 'voice.call.placed';
    public const CALL_MONITORED       = 'voice.call.monitored';
    public const CALL_TRANSFERRED     = 'voice.call.transferred';
    public const CAMPAIGN_LAUNCHED    = 'voice.campaign.launched';
    public const CAMPAIGN_PAUSED      = 'voice.campaign.paused';
    public const CAMPAIGN_CANCELLED   = 'voice.campaign.cancelled';
    public const AI_PUBLISHED         = 'voice.ai.published';
    public const AI_ROLLED_BACK       = 'voice.ai.rolled_back';
    public const PROVIDER_CONFIGURED  = 'voice.provider.configured';
    public const RETENTION_CHANGED    = 'voice.retention.changed';
    public const SETTINGS_CHANGED     = 'voice.settings.changed';
    public const ACCESS_CHANGED       = 'voice.access.changed';
    public const ACCESS_DENIED        = 'voice.access.denied';
    public const EXPORT_TAKEN         = 'voice.export.taken';
    public const EXTERNAL_WRITE       = 'voice.external.write';

    /**
     * @param array<string, mixed> $detail must contain no secret, no URL and no transcript text
     */
    public static function record(
        Context $ctx,
        ?Auth $auth,
        string $action,
        ?string $entityType = null,
        ?string $entityId = null,
        array $detail = [],
    ): void {
        try {
            Db::run(
                'INSERT INTO ' . self::TABLE . '
                    (cmp_id, bo_id, actor_uuid, actor_kind, actor_app, action,
                     entity_type, entity_id, detail, correlation_id, ip_hash)
                 VALUES (:cmp, :bo, :actor, :kind, :app, :action,
                         :etype, :eid, :detail, :corr, :ip)',
                [
                    'cmp'    => $ctx->cmpId,
                    'bo'     => $ctx->boId,
                    'actor'  => $auth?->uuid,
                    'kind'   => $auth === null ? 'system' : $auth->kind,
                    'app'    => $auth?->sourceApp,
                    'action' => $action,
                    'etype'  => $entityType,
                    'eid'    => $entityId === null ? null : (string) $entityId,
                    'detail' => json_encode(self::scrub($detail), JSON_UNESCAPED_UNICODE),
                    'corr'   => Http::header('X-Correlation-Id') ?: null,
                    'ip'     => self::ipHash(),
                ],
            );
        } catch (\Throwable $e) {
            error_log('[audit] could not record ' . $action . ': ' . $e->getMessage());
        }
    }

    /** A provider callback changed Voice-owned state. No Auth exists for these. */
    public static function fromProvider(Context $ctx, string $action, ?string $entityId, array $detail = []): void
    {
        try {
            Db::run(
                'INSERT INTO ' . self::TABLE . '
                    (cmp_id, actor_kind, action, entity_type, entity_id, detail)
                 VALUES (:cmp, :kind, :action, :etype, :eid, :detail)',
                [
                    'cmp'    => $ctx->cmpId,
                    'kind'   => 'provider',
                    'action' => $action,
                    'etype'  => 'call',
                    'eid'    => $entityId,
                    'detail' => json_encode(self::scrub($detail), JSON_UNESCAPED_UNICODE),
                ],
            );
        } catch (\Throwable $e) {
            error_log('[audit] could not record provider ' . $action . ': ' . $e->getMessage());
        }
    }

    /**
     * Strip anything that must not come to rest in an audit row.
     *
     * A belt-and-braces pass over what callers hand us: the callers are
     * supposed to pass only safe fields, and this is what catches the one that
     * forgets. Keys that look like secrets are dropped; values that look like
     * URLs with a signature on them are replaced by their host and path.
     *
     * @param array<string, mixed> $detail
     * @return array<string, mixed>
     */
    private static function scrub(array $detail): array
    {
        $out = [];
        foreach ($detail as $key => $value) {
            $lower = strtolower((string) $key);
            if (preg_match('/(secret|token|password|credential|api_key|apikey|signature|authorization|ses_key)/', $lower) === 1) {
                $out[$key] = '[redacted]';
                continue;
            }
            if (is_string($value) && preg_match('#^https?://#i', $value) === 1) {
                $parts = parse_url($value);
                $out[$key] = ($parts['host'] ?? '') . ($parts['path'] ?? '');
                continue;
            }
            if (is_array($value)) {
                $out[$key] = self::scrub($value);
                continue;
            }
            if (is_string($value) && strlen($value) > 500) {
                $out[$key] = substr($value, 0, 500) . '…';
                continue;
            }
            $out[$key] = $value;
        }

        return $out;
    }

    /**
     * A hash, not the address.
     *
     * Enough to see that twenty exports came from one place, without keeping a
     * log of where staff work from.
     */
    private static function ipHash(): ?string
    {
        $ip = $_SERVER['REMOTE_ADDR'] ?? '';
        if (!is_string($ip) || $ip === '') {
            return null;
        }

        return substr(hash('sha256', $ip . '|' . Env::get('APP_PRODUCT_KEY', 'voice')), 0, 32);
    }
}
