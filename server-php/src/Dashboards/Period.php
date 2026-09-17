<?php

declare(strict_types=1);

namespace Aicountly\Api\Dashboards;

use Aicountly\Api\Domain\Settings;
use Aicountly\Api\Http;
use Aicountly\Api\Support\Clock;
use DateTimeImmutable;
use DateTimeZone;

/**
 * The window a dashboard is describing, and the one before it to compare against.
 *
 * ## Why `as_of` is separate from `to`
 *
 * Looking at "this month" on the 16th means figures up to the 16th. Drawing a
 * line to the 30th flattens it after today and reads as a collapse. So the
 * range for filtering is `from`–`to` and the point the numbers are current to
 * is `as_of`.
 *
 * ## Why the comparison period is computed here
 *
 * "Up 14.8% vs the previous period" is only meaningful if the previous period
 * is the same LENGTH. Comparing a 16-day month-to-date against a full 31-day
 * month produces a collapse every time, which is how a growing business gets
 * told it is shrinking on the 2nd of each month.
 */
final class Period
{
    /** @var array<string, int> preset => days */
    public const PRESETS = [
        'today'      => 1,
        '7d'         => 7,
        '30d'        => 30,
        '90d'        => 90,
        'quarter'    => 90,
        'year'       => 365,
        'last_4_weeks' => 28,
        'last_7_weeks' => 49,
    ];

    private function __construct(
        public readonly DateTimeImmutable $from,
        public readonly DateTimeImmutable $to,
        public readonly DateTimeImmutable $asOf,
        public readonly DateTimeImmutable $previousFrom,
        public readonly DateTimeImmutable $previousTo,
        public readonly string $preset,
        public readonly string $label,
        public readonly DateTimeZone $zone,
    ) {
    }

    /** Read the period off the request, in the company's own timezone. */
    public static function fromRequest(\Aicountly\Api\Context $ctx, string $default = '30d'): self
    {
        $settings = Settings::for($ctx);
        $zone = Clock::zone($settings['timezone']);

        $preset = strtolower(trim((string) (Http::param('period') ?? $default)));
        $explicitFrom = Clock::parse(Http::param('from'));
        $explicitTo = Clock::parse(Http::param('to'));

        $now = Clock::now();

        if ($explicitFrom !== null && $explicitTo !== null && $explicitTo > $explicitFrom) {
            $from = Clock::startOfLocalDay($explicitFrom, $zone);
            // Exclusive end: the day the caller named is included in full.
            $to = Clock::startOfLocalDay($explicitTo, $zone)->modify('+1 day');

            return self::build($from, $to, $now, 'custom', self::describe($from, $to, $zone), $zone);
        }

        $days = self::PRESETS[$preset] ?? self::PRESETS[$default] ?? 30;
        $to = Clock::startOfLocalDay($now, $zone)->modify('+1 day');
        $from = $to->modify('-' . $days . ' days');

        return self::build($from, $to, $now, $preset, self::labelFor($preset, $days), $zone);
    }

    /** Today, in the company's timezone. What the Overview and Live dashboards run on. */
    public static function today(\Aicountly\Api\Context $ctx): self
    {
        $settings = Settings::for($ctx);
        $zone = Clock::zone($settings['timezone']);
        $now = Clock::now();
        $from = Clock::startOfLocalDay($now, $zone);

        return self::build($from, $from->modify('+1 day'), $now, 'today', 'Today', $zone);
    }

    private static function build(
        DateTimeImmutable $from,
        DateTimeImmutable $to,
        DateTimeImmutable $now,
        string $preset,
        string $label,
        DateTimeZone $zone,
    ): self {
        // The same number of days, immediately before. Not "last month" —
        // the same LENGTH, so the comparison is fair mid-period.
        $span = $to->getTimestamp() - $from->getTimestamp();
        $previousTo = $from;
        $previousFrom = $from->modify('-' . max(1, (int) round($span / 86400)) . ' days');

        $asOf = $now < $to ? ($now > $from ? $now : $from) : $to;

        return new self($from, $to, $asOf, $previousFrom, $previousTo, $preset, $label, $zone);
    }

    public function fromSql(): string
    {
        return Clock::sql($this->from);
    }

    public function toSql(): string
    {
        return Clock::sql($this->to);
    }

    public function previousFromSql(): string
    {
        return Clock::sql($this->previousFrom);
    }

    public function previousToSql(): string
    {
        return Clock::sql($this->previousTo);
    }

    public function days(): int
    {
        return max(1, (int) round(($this->to->getTimestamp() - $this->from->getTimestamp()) / 86400));
    }

    /** Stable key for the insight cache, so one period's insight is never shown for another. */
    public function cacheKey(): string
    {
        return $this->preset . ':' . $this->from->format('Ymd') . '-' . $this->to->format('Ymd');
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'preset'        => $this->preset,
            'label'         => $this->label,
            'from'          => $this->from->setTimezone($this->zone)->format('Y-m-d'),
            'to'            => $this->to->modify('-1 second')->setTimezone($this->zone)->format('Y-m-d'),
            'as_of'         => Clock::iso($this->asOf),
            'timezone'      => $this->zone->getName(),
            'days'          => $this->days(),
            'compared_with' => [
                'from' => $this->previousFrom->setTimezone($this->zone)->format('Y-m-d'),
                'to'   => $this->previousTo->modify('-1 second')->setTimezone($this->zone)->format('Y-m-d'),
            ],
        ];
    }

    private static function labelFor(string $preset, int $days): string
    {
        return match ($preset) {
            'today'        => 'Today',
            '7d'           => 'Last 7 days',
            '30d'          => 'Last 30 days',
            '90d', 'quarter' => 'Last quarter',
            'year'         => 'Last 12 months',
            'last_4_weeks' => 'Last 4 weeks',
            'last_7_weeks' => 'Last 7 weeks',
            default        => 'Last ' . $days . ' days',
        };
    }

    private static function describe(DateTimeImmutable $from, DateTimeImmutable $to, DateTimeZone $zone): string
    {
        return $from->setTimezone($zone)->format('j M Y')
            . ' – ' . $to->modify('-1 second')->setTimezone($zone)->format('j M Y');
    }
}
