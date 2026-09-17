<?php

declare(strict_types=1);

namespace Aicountly\Api;

/**
 * This company's Voice settings and policies.
 *
 * Every policy here is a CHOICE THE BUSINESS MAKES, and the product presents it
 * as exactly that. Recording disclosure, AI disclosure, calling windows,
 * retention and suppression are settings a company configures to meet whatever
 * obligations apply to it; Voice enforces what it is told and does not claim
 * that any combination of them constitutes legal compliance. There is no green
 * "compliant" badge in this product, because this code cannot know that.
 *
 * Defaults are the cautious end of each choice: recording off, disclosure on,
 * campaign approval required. A company that has configured nothing yet should
 * not be recording calls because nobody got round to the settings screen.
 */
final class Settings
{
    public const TABLE = 'voice_settings';

    /** @var array<int, array<string, mixed>> */
    private static array $cache = [];

    /** @return array<string, mixed> */
    public static function forCompany(int $cmpId): array
    {
        if (isset(self::$cache[$cmpId])) {
            return self::$cache[$cmpId];
        }

        $row = null;
        try {
            $row = Db::first('SELECT * FROM ' . self::TABLE . ' WHERE cmp_id = :cmp', ['cmp' => $cmpId]);
        } catch (\Throwable $e) {
            error_log('[settings] lookup failed: ' . $e->getMessage());
        }

        return self::$cache[$cmpId] = self::shape($row);
    }

    /** @param array<string, mixed> $values @return array<string, mixed> */
    public static function save(Context $ctx, array $values, string $actor): array
    {
        $current = self::forCompany($ctx->cmpId);
        $merged = array_merge($current, self::filter($values));

        Db::run(
            'INSERT INTO ' . self::TABLE . ' (
                cmp_id, timezone, currency, recording_policy, recording_disclosure,
                ai_disclosure, ai_disclosure_text, recording_retention_days,
                transcript_retention_days, calling_window_start_min, calling_window_end_min,
                calling_window_days, supervisor_monitoring, campaign_approval_required,
                max_concurrent_calls, wrap_up_seconds, updated_at, updated_by
             ) VALUES (
                :cmp, :tz, :cur, :rec_policy, :rec_disc, :ai_disc, :ai_text, :rec_days,
                :tr_days, :win_start, :win_end, :win_days, :sup_mon, :camp_appr,
                :max_conc, :wrap, NOW(), :by
             )
             ON CONFLICT (cmp_id) DO UPDATE SET
                timezone = EXCLUDED.timezone,
                currency = EXCLUDED.currency,
                recording_policy = EXCLUDED.recording_policy,
                recording_disclosure = EXCLUDED.recording_disclosure,
                ai_disclosure = EXCLUDED.ai_disclosure,
                ai_disclosure_text = EXCLUDED.ai_disclosure_text,
                recording_retention_days = EXCLUDED.recording_retention_days,
                transcript_retention_days = EXCLUDED.transcript_retention_days,
                calling_window_start_min = EXCLUDED.calling_window_start_min,
                calling_window_end_min = EXCLUDED.calling_window_end_min,
                calling_window_days = EXCLUDED.calling_window_days,
                supervisor_monitoring = EXCLUDED.supervisor_monitoring,
                campaign_approval_required = EXCLUDED.campaign_approval_required,
                max_concurrent_calls = EXCLUDED.max_concurrent_calls,
                wrap_up_seconds = EXCLUDED.wrap_up_seconds,
                updated_at = NOW(),
                updated_by = EXCLUDED.updated_by',
            [
                'cmp'        => $ctx->cmpId,
                'tz'         => $merged['timezone'],
                'cur'        => $merged['currency'],
                'rec_policy' => $merged['recording_policy'],
                'rec_disc'   => $merged['recording_disclosure'] ? 'true' : 'false',
                'ai_disc'    => $merged['ai_disclosure'] ? 'true' : 'false',
                'ai_text'    => $merged['ai_disclosure_text'],
                'rec_days'   => $merged['recording_retention_days'],
                'tr_days'    => $merged['transcript_retention_days'],
                'win_start'  => $merged['calling_window_start_min'],
                'win_end'    => $merged['calling_window_end_min'],
                'win_days'   => json_encode(array_values($merged['calling_window_days'])),
                'sup_mon'    => $merged['supervisor_monitoring'] ? 'true' : 'false',
                'camp_appr'  => $merged['campaign_approval_required'] ? 'true' : 'false',
                'max_conc'   => $merged['max_concurrent_calls'],
                'wrap'       => $merged['wrap_up_seconds'],
                'by'         => $actor,
            ],
        );

        unset(self::$cache[$ctx->cmpId]);

        return self::forCompany($ctx->cmpId);
    }

    /**
     * Only the keys this class owns, coerced to the right types.
     *
     * @param array<string, mixed> $values
     * @return array<string, mixed>
     */
    private static function filter(array $values): array
    {
        $out = [];
        foreach (['timezone', 'currency', 'recording_policy', 'ai_disclosure_text'] as $key) {
            if (isset($values[$key]) && is_string($values[$key])) {
                $out[$key] = trim($values[$key]);
            }
        }
        foreach (['recording_disclosure', 'ai_disclosure', 'supervisor_monitoring', 'campaign_approval_required'] as $key) {
            if (array_key_exists($key, $values)) {
                $out[$key] = (bool) $values[$key];
            }
        }
        foreach ([
            'recording_retention_days', 'transcript_retention_days',
            'calling_window_start_min', 'calling_window_end_min',
            'max_concurrent_calls', 'wrap_up_seconds',
        ] as $key) {
            if (isset($values[$key]) && is_numeric($values[$key])) {
                $out[$key] = max(0, (int) $values[$key]);
            }
        }
        if (isset($values['calling_window_days']) && is_array($values['calling_window_days'])) {
            $days = [];
            foreach ($values['calling_window_days'] as $day) {
                $day = (int) $day;
                if ($day >= 0 && $day <= 6) {
                    $days[$day] = $day;
                }
            }
            $out['calling_window_days'] = array_values($days);
        }

        // A recording policy this product does not implement must not be stored:
        // an unrecognised value would read as "not never" somewhere downstream.
        if (isset($out['recording_policy'])
            && !in_array($out['recording_policy'], ['never', 'always', 'on_consent'], true)) {
            unset($out['recording_policy']);
        }

        return $out;
    }

    /** @param array<string, mixed>|null $row @return array<string, mixed> */
    private static function shape(?array $row): array
    {
        return [
            'timezone'                   => (string) ($row['timezone'] ?? 'Asia/Kolkata'),
            'currency'                   => (string) ($row['currency'] ?? 'INR'),
            'recording_policy'           => (string) ($row['recording_policy'] ?? 'never'),
            'recording_disclosure'       => self::bool($row['recording_disclosure'] ?? true),
            'ai_disclosure'              => self::bool($row['ai_disclosure'] ?? true),
            'ai_disclosure_text'         => (string) ($row['ai_disclosure_text'] ?? ''),
            'recording_retention_days'   => (int) ($row['recording_retention_days'] ?? 0),
            'transcript_retention_days'  => (int) ($row['transcript_retention_days'] ?? 0),
            'calling_window_start_min'   => (int) ($row['calling_window_start_min'] ?? 540),
            'calling_window_end_min'     => (int) ($row['calling_window_end_min'] ?? 1200),
            'calling_window_days'        => $row === null
                ? [1, 2, 3, 4, 5, 6]
                : array_values(array_map('intval', Db::jsonColumn($row['calling_window_days'] ?? null))),
            'supervisor_monitoring'      => self::bool($row['supervisor_monitoring'] ?? false),
            'campaign_approval_required' => self::bool($row['campaign_approval_required'] ?? true),
            'max_concurrent_calls'       => (int) ($row['max_concurrent_calls'] ?? 0),
            'wrap_up_seconds'            => (int) ($row['wrap_up_seconds'] ?? 60),
            'configured'                 => $row !== null,
        ];
    }

    private static function bool(mixed $value): bool
    {
        if (is_bool($value)) {
            return $value;
        }

        return in_array(strtolower((string) $value), ['1', 't', 'true', 'yes'], true);
    }

    /** CLI only, so one test's settings do not leak into the next. */
    public static function resetForTesting(): void
    {
        if (PHP_SAPI === 'cli') {
            self::$cache = [];
        }
    }
}
