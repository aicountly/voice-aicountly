<?php

declare(strict_types=1);

namespace Aicountly\Api\Domain;

use Aicountly\Api\Context;
use Aicountly\Api\Db;
use Aicountly\Api\Env;
use Aicountly\Api\Settings;
use Aicountly\Api\Support\Clock;
use Aicountly\Api\Support\PhoneNumber;
use DateTimeImmutable;

/**
 * May this company call this number, right now?
 *
 * Three questions, each of which can stop a call, and all three are asked again
 * at DISPATCH TIME rather than when a campaign was built:
 *
 *  1. Has this person asked not to be called? (suppression)
 *  2. Is it inside the calling window this company configured?
 *  3. Is the number one we can dial at all?
 *
 * ## What this class deliberately does NOT claim
 *
 * It does not certify that a call is lawful. A green tick here means the
 * company's own configured policy permits it — a suppression list this company
 * maintains, a calling window this company set. Whether a particular call to a
 * particular person for a particular purpose is permitted is a question about
 * that business's obligations, which this code cannot answer and does not
 * pretend to. There is no "compliant" badge in this product for that reason.
 *
 * In particular, AN EXISTING BUSINESS RELATIONSHIP IS NOT CONSENT TO MARKETING.
 * The campaign builder asks separately what the purpose of a campaign is and
 * whether the audience may be used for it; it never infers permission from the
 * fact that somebody is a customer.
 */
final class CallingPolicy
{
    /**
     * @return array{allowed: bool, reason: ?string, message: ?string}
     */
    public static function check(Context $ctx, string $e164, ?DateTimeImmutable $at = null): array
    {
        $number = self::normaliseFor($ctx, $e164);
        if ($number === null) {
            return [
                'allowed' => false,
                'reason'  => 'no_number',
                'message' => 'That is not a number this system can dial.',
            ];
        }

        if (self::isSuppressed($ctx, $number)) {
            return [
                'allowed' => false,
                'reason'  => 'suppressed',
                'message' => 'This number is on the suppression list for this company.',
            ];
        }

        $window = self::windowCheck($ctx, $at);
        if (!$window['allowed']) {
            return $window;
        }

        return ['allowed' => true, 'reason' => null, 'message' => null];
    }

    /**
     * Has this number opted out?
     *
     * Checked on EVERY dispatch, not once when the list was built. Somebody who
     * opted out this morning must not be called this afternoon because a
     * campaign was prepared last week.
     */
    public static function isSuppressed(Context $ctx, string $e164): bool
    {
        $row = Db::first(
            'SELECT 1 FROM voice_suppressions
              WHERE cmp_id = :cmp AND e164 = :num
                AND (expires_at IS NULL OR expires_at > NOW())',
            ['cmp' => $ctx->cmpId, 'num' => $e164],
        );

        return $row !== null;
    }

    /** Add a number to the suppression list. Idempotent — asking twice is still one entry. */
    public static function suppress(Context $ctx, string $e164, string $reason, string $source, ?string $actor = null): void
    {
        $number = self::normaliseFor($ctx, $e164);
        if ($number === null) {
            return;
        }

        Db::run(
            'INSERT INTO voice_suppressions (cmp_id, e164, reason, source, created_by)
             VALUES (:cmp, :num, :reason, :source, :by)
             ON CONFLICT (cmp_id, e164) DO UPDATE
                SET reason = EXCLUDED.reason, source = EXCLUDED.source, expires_at = NULL',
            [
                'cmp' => $ctx->cmpId, 'num' => $number,
                'reason' => $reason, 'source' => $source, 'by' => $actor,
            ],
        );
    }

    /**
     * Is now inside the configured calling window?
     *
     * Evaluated in the COMPANY'S timezone, from local wall-clock minutes. Doing
     * this in UTC is how a window that reads "9am to 8pm" starts calling people
     * at 3:30am after a clock change.
     *
     * @return array{allowed: bool, reason: ?string, message: ?string}
     */
    public static function windowCheck(Context $ctx, ?DateTimeImmutable $at = null): array
    {
        $settings = Settings::forCompany($ctx->cmpId);

        return self::windowCheckFor(
            $at ?? Clock::now(),
            (string) $settings['timezone'],
            (int) $settings['calling_window_start_min'],
            (int) $settings['calling_window_end_min'],
            (array) $settings['calling_window_days'],
        );
    }

    /**
     * The window test itself, with the policy passed in.
     *
     * Separate from windowCheck() because a campaign carries its OWN window and
     * timezone, and both need the same arithmetic.
     *
     * @param list<int> $days 0 = Sunday … 6 = Saturday
     * @return array{allowed: bool, reason: ?string, message: ?string}
     */
    public static function windowCheckFor(
        DateTimeImmutable $at,
        string $timezone,
        int $startMinute,
        int $endMinute,
        array $days,
    ): array {
        $zone = Clock::zone($timezone);
        $local = $at->setTimezone($zone);
        $dayOfWeek = (int) $local->format('w');
        $minuteOfDay = ((int) $local->format('G')) * 60 + (int) $local->format('i');

        if ($days !== [] && !in_array($dayOfWeek, array_map('intval', $days), true)) {
            return [
                'allowed' => false,
                'reason'  => 'outside_window',
                'message' => 'This company does not make calls on ' . $local->format('l') . '.',
            ];
        }

        if ($minuteOfDay < $startMinute || $minuteOfDay >= $endMinute) {
            return [
                'allowed' => false,
                'reason'  => 'outside_window',
                'message' => sprintf(
                    'Outside the calling window (%s–%s %s).',
                    self::formatMinute($startMinute),
                    self::formatMinute($endMinute),
                    $timezone,
                ),
            ];
        }

        return ['allowed' => true, 'reason' => null, 'message' => null];
    }

    /**
     * E.164, or null.
     *
     * Deliberately strict. A number this cannot normalise is a number the
     * carrier will reject — or, worse, a different number. National numbers
     * need a region: without one only `+CC…` / `00CC…` forms are accepted. The
     * old rule put '+' in front of any digit string, which turned the Indian
     * mobile 9876543210 into +9876543210, another country (G18#6).
     */
    public static function normalise(string $raw, ?string $region = null): ?string
    {
        return PhoneNumber::toE164($raw, $region);
    }

    /** E.164 for this company: national numbers are read in its region (see regionFor). */
    public static function normaliseFor(Context $ctx, string $raw, ?string $preferredRegion = null): ?string
    {
        return PhoneNumber::toE164($raw, self::regionFor($ctx, $preferredRegion));
    }

    /**
     * The region national numbers are read in, for this company.
     *
     * In order: a region the caller has good reason to prefer (the country of
     * the business number a call is placed from), this company's
     * `default_phone_region` setting, the server's VOICE_DEFAULT_PHONE_REGION,
     * then IN — the order Contacts uses for the phones it stores, so a number
     * typed nationally here and one stored in Contacts land on the same E.164.
     */
    public static function regionFor(Context $ctx, ?string $preferred = null): string
    {
        foreach ([
            $preferred,
            Settings::forCompany($ctx->cmpId)['default_phone_region'] ?? null,
            Env::get('VOICE_DEFAULT_PHONE_REGION'),
        ] as $candidate) {
            $region = PhoneNumber::region(is_string($candidate) ? $candidate : null);
            if ($region !== null) {
                return $region;
            }
        }

        return 'IN';
    }

    /**
     * A number with its middle hidden, for screens where the full number is not
     * needed to do the job.
     */
    public static function mask(string $e164): string
    {
        $length = strlen($e164);
        if ($length <= 7) {
            return $e164;
        }

        return substr($e164, 0, 3) . str_repeat('•', max(2, $length - 7)) . substr($e164, -4);
    }

    private static function formatMinute(int $minute): string
    {
        return sprintf('%02d:%02d', intdiv($minute, 60) % 24, $minute % 60);
    }
}
