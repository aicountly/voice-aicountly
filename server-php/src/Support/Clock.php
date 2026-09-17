<?php

declare(strict_types=1);

namespace Aicountly\Api\Support;

use DateTimeImmutable;
use DateTimeZone;

/**
 * Time, in one place, because a booking product gets this wrong everywhere else.
 *
 * ## The rules this class exists to hold
 *
 * 1. EVERYTHING IS STORED IN UTC. `TIMESTAMPTZ` columns, ISO 8601 with an
 *    offset on the wire, no exceptions. A local time in a database is a time
 *    that becomes ambiguous the night the clocks change and stays wrong.
 *
 * 2. EVERYTHING IS RENDERED IN A NAMED ZONE. "Asia/Kolkata", not "+05:30" — a
 *    fixed offset is a guess that expires. The company's zone is the default;
 *    a location may override it, because a business with branches in two zones
 *    is normal and a client booking the Dubai branch expects Dubai's clock.
 *
 * 3. SLOT ARITHMETIC HAPPENS IN THE LOCAL ZONE. "Every 30 minutes from 9am"
 *    means 9am as the clinic reads it. Doing it in UTC and converting afterwards
 *    produces 8:30 and 9:30 the week after a DST change — which is exactly the
 *    week a client turns up an hour early.
 *
 * ## Frozen time
 *
 * `freezeForTesting()` exists because half the interesting behaviour in this
 * product is "what happens two hours before the appointment", and a test that
 * sleeps is not a test. CLI only.
 */
final class Clock
{
    private static ?DateTimeImmutable $frozen = null;

    public static function now(): DateTimeImmutable
    {
        return self::$frozen ?? new DateTimeImmutable('now', new DateTimeZone('UTC'));
    }

    public static function freezeForTesting(?string $iso): void
    {
        if (PHP_SAPI !== 'cli') {
            return;
        }
        self::$frozen = $iso === null ? null : new DateTimeImmutable($iso, new DateTimeZone('UTC'));
    }

    public static function utc(): DateTimeZone
    {
        return new DateTimeZone('UTC');
    }

    /**
     * A timezone that is definitely real.
     *
     * An unknown zone name falls back rather than throwing: a typo in a
     * location's configuration should show the wrong hours, not take the
     * booking page down.
     */
    public static function zone(?string $name, string $fallback = 'Asia/Kolkata'): DateTimeZone
    {
        $name = trim((string) $name);
        if ($name !== '') {
            try {
                return new DateTimeZone($name);
            } catch (\Throwable) {
                error_log('[clock] unknown timezone "' . $name . '", falling back to ' . $fallback);
            }
        }

        try {
            return new DateTimeZone($fallback);
        } catch (\Throwable) {
            return new DateTimeZone('UTC');
        }
    }

    /** Parse anything ISO-ish into UTC, or null when it is not a time. */
    public static function parse(?string $value): ?DateTimeImmutable
    {
        $value = trim((string) $value);
        if ($value === '') {
            return null;
        }

        try {
            return (new DateTimeImmutable($value))->setTimezone(self::utc());
        } catch (\Throwable) {
            return null;
        }
    }

    /** The wire format for every timestamp this API sends. */
    public static function iso(DateTimeImmutable $moment): string
    {
        return $moment->setTimezone(self::utc())->format('Y-m-d\TH:i:s\Z');
    }

    /** What PostgreSQL wants in a bound TIMESTAMPTZ parameter. */
    public static function sql(DateTimeImmutable $moment): string
    {
        return $moment->setTimezone(self::utc())->format('Y-m-d H:i:sP');
    }

    /**
     * A local wall-clock moment: this date, this many minutes past midnight, in
     * this zone — then expressed in UTC.
     *
     * The DST case is handled by DateTimeImmutable's own zone arithmetic rather
     * than by adding seconds to a timestamp, which is the whole point of going
     * through a formatted local string here.
     */
    public static function atLocalMinute(DateTimeImmutable $day, int $minuteOfDay, DateTimeZone $zone): DateTimeImmutable
    {
        $local = $day->setTimezone($zone);
        $hours = intdiv($minuteOfDay, 60);
        $minutes = $minuteOfDay % 60;

        // Build from the date parts so the zone resolves the offset for THAT
        // day — adding 540 minutes to local midnight gives 8am or 10am on the
        // two days a year the offset moves.
        $built = new DateTimeImmutable(
            sprintf('%s %02d:%02d:00', $local->format('Y-m-d'), $hours % 24, $minutes),
            $zone,
        );

        if ($hours >= 24) {
            $built = $built->modify('+' . intdiv($hours, 24) . ' day');
        }

        return $built->setTimezone(self::utc());
    }

    /** Minutes past local midnight, for putting a UTC moment back on a grid. */
    public static function localMinuteOfDay(DateTimeImmutable $moment, DateTimeZone $zone): int
    {
        $local = $moment->setTimezone($zone);

        return ((int) $local->format('G')) * 60 + (int) $local->format('i');
    }

    /** 0 = Sunday … 6 = Saturday, matching PostgreSQL's EXTRACT(DOW) and the availability table. */
    public static function localDayOfWeek(DateTimeImmutable $moment, DateTimeZone $zone): int
    {
        return (int) $moment->setTimezone($zone)->format('w');
    }

    /** Local midnight, as a UTC moment. The start of a "day" everywhere in this product. */
    public static function startOfLocalDay(DateTimeImmutable $moment, DateTimeZone $zone): DateTimeImmutable
    {
        $local = $moment->setTimezone($zone);

        return (new DateTimeImmutable($local->format('Y-m-d') . ' 00:00:00', $zone))->setTimezone(self::utc());
    }

    /** morning | afternoon | evening, by the local clock. Matches the waitlist's daypart. */
    public static function daypart(DateTimeImmutable $moment, DateTimeZone $zone): string
    {
        $hour = (int) $moment->setTimezone($zone)->format('G');

        return match (true) {
            $hour < 12 => 'morning',
            $hour < 17 => 'afternoon',
            default    => 'evening',
        };
    }

    /** Whole minutes between two moments, negative when $to is earlier. */
    public static function minutesBetween(DateTimeImmutable $from, DateTimeImmutable $to): int
    {
        return (int) round(($to->getTimestamp() - $from->getTimestamp()) / 60);
    }
}
