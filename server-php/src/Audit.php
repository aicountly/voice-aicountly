<?php

declare(strict_types=1);

namespace Aicountly\Api;

use Aicountly\Api\Domain\CallingPolicy;

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
 * TWO WRITERS.
 *   record()    best-effort: a failure is logged and swallowed, because an audit
 *               trail that can 500 a call is an audit trail that gets disabled.
 *               Under a transaction it runs in a SAVEPOINT, so a failed audit
 *               insert cannot poison the caller's own work.
 *   material()  fail-closed: throws when the row cannot be written. Call it
 *               INSIDE the business transaction (Db::transaction) so the change
 *               and its audit row commit or roll back together. Used for access
 *               and role changes, settings, provider and retention changes,
 *               AI publishing, campaign approval/launch/cancel and access to
 *               recordings.
 *
 * Rows are append-only: migration 014 puts a BEFORE UPDATE/DELETE trigger on
 * the table. Telephone numbers are written masked (CallingPolicy::mask), and
 * free-text fields (notes, reasons, summaries) are recorded as "changed", never
 * as their text.
 */
final class Audit
{
    public const TABLE = 'voice_audit_events';

    public const SEVERITIES = ['info', 'material', 'security'];

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
    public const AI_ACTION            = 'voice.ai.action';
    public const PROVIDER_CONFIGURED  = 'voice.provider.configured';
    public const RETENTION_CHANGED    = 'voice.retention.changed';
    public const SETTINGS_CHANGED     = 'voice.settings.changed';
    public const ACCESS_CHANGED       = 'voice.access.changed';
    public const ACCESS_DENIED        = 'voice.access.denied';
    public const EXPORT_TAKEN         = 'voice.export.taken';
    public const EXTERNAL_WRITE       = 'voice.external.write';

    /** Whole words in a field name that make it a secret. */
    private const SECRET_PARTS = ['password', 'passwd', 'secret', 'token', 'otp', 'pin', 'cvv', 'credential', 'credentials', 'signature', 'apikey', 'authorization'];
    private const SECRET_NAMES = ['api_key', 'private_key', 'ses_key', 'session_key', 'service_key', 'auth_token', 'access_token', 'refresh_token', 'credentials_enc'];

    /** Free text: the trail says it changed, never what it said. */
    private const CONTENT_FIELDS = ['note', 'notes', 'reason', 'body', 'message', 'transcript', 'summary_text', 'disposition_note', 'prompt', 'system_prompt', 'greeting', 'script', 'announcements'];

    /** Fields holding a telephone number: written masked, like everywhere else in this product. */
    private const PHONE_FIELDS = ['e164', 'phone', 'msisdn', 'remote_e164', 'caller_e164', 'callee_e164', 'destination'];

    /** What each record type is called, for the one-line summary. */
    private const ENTITY_NAMES = [
        'call' => 'Call', 'callback' => 'Callback', 'campaign' => 'Campaign', 'ai_agent' => 'AI agent',
        'call_flow' => 'Call flow', 'number' => 'Number', 'queue' => 'Queue', 'agent' => 'Agent',
        'connection' => 'Provider connection', 'recording' => 'Recording', 'settings' => 'Voice settings',
        'permission_profile' => 'Permission profile', 'permission_assignment' => 'Access grant',
        'suppression' => 'Do-not-call entry', 'commitment' => 'Commitment', 'contact' => 'Contact',
        'summary' => 'Call summary', 'call_review' => 'Call review', 'audit_log' => 'Audit trail',
    ];

    private const FIELD_LABELS = [
        'status' => 'Status', 'name' => 'Name', 'label' => 'Label', 'is_active' => 'Active', 'priority' => 'Priority',
        'due_at' => 'Due', 'assigned_agent_id' => 'Assigned agent', 'queue_id' => 'Queue', 'e164' => 'Number',
        'country' => 'Country', 'number_type' => 'Number type', 'connection_id' => 'Provider connection',
        'team_id' => 'Team', 'inbound_flow_id' => 'Inbound flow', 'recording_policy' => 'Recording policy',
        'business_hours' => 'Business hours', 'routing_status' => 'Routing', 'kind' => 'Kind', 'strategy' => 'Strategy',
        'ring_seconds' => 'Ring seconds', 'wrap_up_seconds' => 'Wrap-up seconds', 'timeout_seconds' => 'Timeout seconds',
        'overflow_type' => 'Overflow type', 'overflow_ref' => 'Overflow target', 'max_waiting' => 'Max waiting',
        'extension' => 'Extension', 'voice_role' => 'Voice role', 'skills' => 'Skills', 'languages' => 'Languages',
        'permissions' => 'Permissions', 'description' => 'Description', 'disposition' => 'Disposition',
        'disposition_note' => 'Call note', 'reason' => 'Reason', 'exact_time' => 'Exact time',
        'calendar_requested' => 'Diary entry requested', 'max_attempts' => 'Max attempts',
        'recording_retention_days' => 'Recording retention (days)', 'transcript_retention_days' => 'Transcript retention (days)',
        'provider' => 'Provider', 'role' => 'Role', 'max_concurrent' => 'Max concurrent calls',
        'approved_at' => 'Approved at', 'approved_by' => 'Approved by',
    ];

    private static ?string $requestId = null;

    /**
     * Best-effort. Never throws.
     *
     * @param array<string, mixed>      $detail  must contain no secret, no URL and no transcript text
     * @param array<string, mixed>|null $before  readable state before (names and values, not only ids)
     * @param array<string, mixed>|null $after   readable state after
     * @param array<string, string>     $labels  field => display label, over the built-in ones
     */
    public static function record(
        Context $ctx,
        ?Auth $auth,
        string $action,
        ?string $entityType = null,
        ?string $entityId = null,
        array $detail = [],
        ?array $before = null,
        ?array $after = null,
        string $label = '',
        string $summary = '',
        string $severity = '',
        ?string $actor = null,
        array $labels = [],
    ): void {
        $pdo = null;
        $savepoint = false;
        try {
            $pdo = Db::connect();
            if ($pdo->inTransaction()) {
                $pdo->exec('SAVEPOINT voice_audit_record');
                $savepoint = true;
            }
            self::write($ctx, $auth, $action, $entityType, $entityId, $detail, $before, $after, $label, $summary,
                $severity !== '' ? $severity : self::severityOf($action, false), $actor, $labels);
            if ($savepoint) {
                $pdo->exec('RELEASE SAVEPOINT voice_audit_record');
            }
        } catch (\Throwable $e) {
            if ($savepoint && $pdo !== null) {
                try {
                    $pdo->exec('ROLLBACK TO SAVEPOINT voice_audit_record');
                    $pdo->exec('RELEASE SAVEPOINT voice_audit_record');
                } catch (\Throwable) {
                    // The transaction was already beyond saving; the caller's next statement will say so.
                }
            }
            error_log('[audit] could not record ' . $action . ': ' . $e->getMessage());
        }
    }

    /**
     * Fail-closed. Call inside the business transaction: if the row cannot be
     * written this throws, and the change it describes rolls back with it.
     *
     * @param array<string, mixed>      $detail
     * @param array<string, mixed>|null $before
     * @param array<string, mixed>|null $after
     * @param array<string, string>     $labels
     */
    public static function material(
        Context $ctx,
        ?Auth $auth,
        string $action,
        ?string $entityType = null,
        ?string $entityId = null,
        array $detail = [],
        ?array $before = null,
        ?array $after = null,
        string $label = '',
        string $summary = '',
        string $severity = '',
        ?string $actor = null,
        array $labels = [],
    ): void {
        try {
            self::write($ctx, $auth, $action, $entityType, $entityId, $detail, $before, $after, $label, $summary,
                $severity !== '' ? $severity : self::severityOf($action, true), $actor, $labels);
        } catch (\Throwable $e) {
            error_log('[audit] REFUSED ' . $action . ': the audit row could not be written: ' . $e->getMessage());

            throw new \RuntimeException('The change was not saved because its audit record could not be written. Nothing was changed; try again.', 0, $e);
        }
    }

    /**
     * Record a create or an update of a configuration record, with the field diff.
     *
     * $before is the row as it was (null for a create); $after is the values
     * just written. Only the fields in $after are compared, so a column the
     * caller did not touch is never reported. An update that changed nothing
     * writes no row.
     *
     * @param array<string, mixed>|null $before
     * @param array<string, mixed>      $after
     * @param array<string, string>     $labels
     */
    public static function saved(
        Context $ctx,
        ?Auth $auth,
        string $entityType,
        int|string|null $entityId,
        string $label,
        ?array $before,
        array $after,
        array $labels = [],
        bool $material = false,
        string $action = '',
    ): void {
        unset($after['updated_at'], $after['created_at']);
        $beforeView = null;
        if ($before !== null) {
            $beforeView = [];
            foreach (array_keys($after) as $field) {
                $beforeView[$field] = self::normalise($before[$field] ?? null);
            }
        }
        $afterView = array_map([self::class, 'normalise'], $after);
        if ($beforeView !== null && self::changes($beforeView, $afterView, $labels) === []) {
            return;
        }

        $action = $action !== '' ? $action : 'voice.' . $entityType . ($before === null ? '.created' : '.updated');
        $writer = $material ? 'material' : 'record';
        self::$writer($ctx, $auth, $action, $entityType, $entityId === null ? null : (string) $entityId,
            before: $beforeView, after: $afterView, label: $label, labels: $labels);
    }

    /** A provider callback changed Voice-owned state. No Auth exists for these. */
    public static function fromProvider(Context $ctx, string $action, ?string $entityId, array $detail = []): void
    {
        self::record($ctx, null, $action, 'call', $entityId, $detail, actor: 'app:telephony');
    }

    /**
     * The field-by-field difference between two states: [{field, label, old, new}].
     *
     * Unchanged fields and secrets are skipped; free text is reported as changed
     * without its text; telephone numbers are masked.
     *
     * @param array<string, mixed>|null $before
     * @param array<string, mixed>|null $after
     * @param array<string, string>     $labels
     * @return list<array{field: string, label: string, old: mixed, new: mixed}>
     */
    public static function changes(?array $before, ?array $after, array $labels = []): array
    {
        if ($before === null || $after === null) {
            return [];
        }
        $out = [];
        foreach (array_unique(array_merge(array_keys($before), array_keys($after))) as $field) {
            $field = (string) $field;
            if (self::isSecret($field)) {
                continue;
            }
            $old = $before[$field] ?? null;
            $new = $after[$field] ?? null;
            if (self::same($old, $new)) {
                continue;
            }
            $out[] = [
                'field' => $field,
                'label' => $labels[$field] ?? self::FIELD_LABELS[$field] ?? ucfirst(str_replace('_', ' ', $field)),
                'old'   => self::present($field, $old),
                'new'   => self::present($field, $new),
            ];
        }

        return $out;
    }

    public static function isSecret(string $field): bool
    {
        $name = strtolower($field);
        if (in_array($name, self::SECRET_NAMES, true)) {
            return true;
        }
        foreach (preg_split('/[^a-z0-9]+/', $name) ?: [] as $part) {
            if (in_array($part, self::SECRET_PARTS, true)) {
                return true;
            }
        }

        return str_contains($name, 'apikey') || str_contains($name, 'password') || str_contains($name, 'secret');
    }

    /** The request id every row of one request shares; an acceptable X-Request-Id / X-Correlation-Id is honoured. */
    public static function requestId(): string
    {
        if (self::$requestId !== null) {
            return self::$requestId;
        }
        foreach (['X-Request-Id', 'X-Correlation-Id'] as $header) {
            $given = Http::header($header);
            if (preg_match('/^[A-Za-z0-9._:-]{8,128}$/', $given) === 1) {
                return self::$requestId = $given;
            }
        }

        return self::$requestId = bin2hex(random_bytes(12));
    }

    /** For tests: a new request starts with a new id. */
    public static function resetRequest(): void
    {
        self::$requestId = null;
    }

    /**
     * @param array<string, mixed>      $detail
     * @param array<string, mixed>|null $before
     * @param array<string, mixed>|null $after
     * @param array<string, string>     $labels
     */
    private static function write(
        Context $ctx,
        ?Auth $auth,
        string $action,
        ?string $entityType,
        ?string $entityId,
        array $detail,
        ?array $before,
        ?array $after,
        string $label,
        string $summary,
        string $severity,
        ?string $actor,
        array $labels,
    ): void {
        $changes = self::changes($before, $after, $labels);
        $before = self::snapshot($before);
        $after = self::snapshot($after);
        $label = $label !== '' ? $label : self::labelFrom($after) ?? self::labelFrom($before) ?? '';
        $label = mb_substr(trim($label), 0, 200);
        $summary = $summary !== '' ? mb_substr($summary, 0, 500) : self::summarise($action, $entityType, $entityId, $label);

        $actorUuid = $auth?->uuid ?? $actor ?? 'system:voice';
        $actorLabel = null;
        if ($auth !== null && !$auth->isService()) {
            $name = $auth->displayName();
            $actorLabel = $name !== '' && $name !== $auth->uuid ? mb_substr($name, 0, 200) : null;
        }

        Db::run(
            'INSERT INTO ' . self::TABLE . '
                (cmp_id, bo_id, actor_uuid, actor_label, actor_kind, actor_app, action,
                 entity_type, entity_id, entity_label, summary, detail, changes, before_state, after_state,
                 severity, correlation_id, request_id, user_agent, ip_hash)
             VALUES (:cmp, :bo, :actor, :alabel, :kind, :app, :action,
                     :etype, :eid, :elabel, :summary, CAST(:detail AS jsonb), CAST(:changes AS jsonb),
                     CAST(:before AS jsonb), CAST(:after AS jsonb),
                     :severity, :corr, :req, :ua, :ip)',
            [
                'cmp'      => $ctx->cmpId,
                'bo'       => $ctx->boId,
                'actor'    => $actorUuid,
                'alabel'   => $actorLabel,
                'kind'     => $auth === null ? ($actor !== null && str_starts_with($actor, 'app:') ? 'provider' : 'system') : $auth->kind,
                'app'      => $auth?->sourceApp,
                'action'   => $action,
                'etype'    => $entityType,
                'eid'      => $entityId === null ? null : (string) $entityId,
                'elabel'   => $label === '' ? null : $label,
                'summary'  => $summary,
                'detail'   => json_encode(self::scrub($detail), JSON_UNESCAPED_UNICODE | JSON_PARTIAL_OUTPUT_ON_ERROR) ?: '{}',
                'changes'  => $changes === [] ? null : json_encode($changes, JSON_UNESCAPED_UNICODE | JSON_PARTIAL_OUTPUT_ON_ERROR),
                'before'   => $before === null ? null : json_encode($before, JSON_UNESCAPED_UNICODE | JSON_PARTIAL_OUTPUT_ON_ERROR),
                'after'    => $after === null ? null : json_encode($after, JSON_UNESCAPED_UNICODE | JSON_PARTIAL_OUTPUT_ON_ERROR),
                'severity' => in_array($severity, self::SEVERITIES, true) ? $severity : 'info',
                'corr'     => Http::header('X-Correlation-Id') ?: null,
                'req'      => self::requestId(),
                'ua'       => self::userAgent(),
                'ip'       => self::ipHash(),
            ],
        );
    }

    private static function severityOf(string $action, bool $material): string
    {
        if (preg_match('/^voice\.(access|provider|retention)\./', $action) === 1
            || in_array($action, [self::RECORDING_DOWNLOADED, self::RECORDING_DELETED, self::CALL_MONITORED], true)) {
            return 'security';
        }

        return $material ? 'material' : 'info';
    }

    private static function summarise(string $action, ?string $entityType, ?string $id, string $label): string
    {
        $verb = str_replace(['_', '.'], ' ', preg_replace('/^voice\./', '', $action) ?? $action);
        if ($entityType === null) {
            return $verb;
        }
        $name = self::ENTITY_NAMES[$entityType] ?? ucfirst(str_replace('_', ' ', $entityType));
        $what = $name . ' ' . ($label !== '' ? $label : ($id !== null && $entityType !== 'settings' ? '#' . $id : ''));

        return trim($what) . ': ' . $verb;
    }

    /** @param array<string, mixed>|null $state */
    private static function labelFrom(?array $state): ?string
    {
        foreach (['label', 'name', 'profile_name', 'title'] as $key) {
            $value = $state[$key] ?? null;
            if (is_string($value) && trim($value) !== '') {
                return $value;
            }
        }

        return null;
    }

    /**
     * A state as it may come to rest: secrets redacted, free text and numbers
     * made safe. Keys are kept so the snapshot still reads as the record.
     *
     * @param array<string, mixed>|null $state
     * @return array<string, mixed>|null
     */
    private static function snapshot(?array $state): ?array
    {
        if ($state === null) {
            return null;
        }
        $out = [];
        foreach ($state as $key => $value) {
            if (is_string($key) && self::isSecret($key)) {
                $out[$key] = '[redacted]';
            } elseif (is_array($value)) {
                $out[$key] = self::snapshot($value);
            } else {
                $out[$key] = is_string($key) ? self::present($key, $value) : $value;
            }
        }

        return $out;
    }

    /** One value as shown in the trail: free text withheld, numbers masked. */
    private static function present(string $field, mixed $value, bool $content = true): mixed
    {
        $name = strtolower($field);
        if ($value === null || $value === '' || is_bool($value)) {
            return $value;
        }
        if ($content && in_array($name, self::CONTENT_FIELDS, true)) {
            return '[text not logged]';
        }
        if (in_array($name, self::PHONE_FIELDS, true) && is_string($value)) {
            return CallingPolicy::mask($value);
        }
        if (is_string($value) && preg_match('#^https?://#i', $value) === 1) {
            $parts = parse_url($value);

            return ($parts['host'] ?? '') . ($parts['path'] ?? '');
        }
        if (is_string($value) && strlen($value) > 500) {
            return substr($value, 0, 500) . '…';
        }

        return $value;
    }

    /** A database value as a comparable PHP value: JSON text decoded. */
    private static function normalise(mixed $value): mixed
    {
        if (is_string($value) && $value !== '' && ($value[0] === '{' || $value[0] === '[')) {
            $decoded = json_decode($value, true);
            if (is_array($decoded)) {
                return $decoded;
            }
        }

        return $value;
    }

    private static function same(mixed $a, mixed $b): bool
    {
        if (is_array($a) || is_array($b)) {
            return json_encode($a) === json_encode($b);
        }
        if ($a === null || $b === null) {
            return $a === $b;
        }
        $stamp = '/^\d{4}-\d{2}-\d{2}[ T]\d{2}:\d{2}/';
        if (is_string($a) && is_string($b) && preg_match($stamp, $a) === 1 && preg_match($stamp, $b) === 1) {
            $ta = strtotime($a);
            $tb = strtotime($b);
            if ($ta !== false && $tb !== false) {
                return $ta === $tb;
            }
        }
        if (is_bool($a) || is_bool($b)) {
            return filter_var($a, FILTER_VALIDATE_BOOLEAN) === filter_var($b, FILTER_VALIDATE_BOOLEAN);
        }

        return (string) $a === (string) $b;
    }

    private static function userAgent(): ?string
    {
        $ua = $_SERVER['HTTP_USER_AGENT'] ?? '';

        return is_string($ua) && $ua !== '' ? mb_substr($ua, 0, 255) : null;
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
            if (self::isSecret($lower) || preg_match('/(secret|token|password|credential|api_key|apikey|signature|authorization|ses_key)/', $lower) === 1) {
                $out[$key] = '[redacted]';
                continue;
            }
            if (is_array($value)) {
                $out[$key] = self::scrub($value);
                continue;
            }
            $out[$key] = is_string($key) ? self::present($key, $value, false) : $value;
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
