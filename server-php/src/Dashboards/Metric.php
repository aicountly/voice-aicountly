<?php

declare(strict_types=1);

namespace Aicountly\Api\Dashboards;

/**
 * One KPI card, in the shape the frontend renders without knowing anything
 * about calling.
 *
 * ## The change figure
 *
 * `change_pct` is computed here from the current and previous values, and is
 * NULL rather than 0 when there is nothing to compare against. That matters:
 * "0%" tells a reader the number held steady, which is a different claim from
 * "we have no previous period for this". A brand-new company's first week
 * showing "↑ 0%" on every card is a dashboard lying quietly.
 *
 * ## `direction`
 *
 * Whether up is good. A rising utilisation is good news and a rising no-show
 * rate is not, and the card colours accordingly — so the metric says which it
 * is rather than the UI guessing from the label.
 */
final class Metric
{
    private function __construct(
        public readonly string $id,
        public readonly string $label,
        public readonly int|float|null $value,
        public readonly string $unit,          // count | percent | hours | currency | score
        public readonly int|float|null $previous,
        public readonly string $direction,     // up_is_good | down_is_good | neutral
        public readonly ?string $note,
        public readonly ?string $drilldownRoute,
        /** @var array<string, mixed> */
        public readonly array $drilldownParams,
        public readonly ?string $status,       // null | 'unavailable'
        public readonly ?string $unavailableReason,
    ) {
    }

    /** @param array<string, mixed> $drilldownParams */
    public static function make(
        string $id,
        string $label,
        int|float|null $value,
        string $unit = 'count',
        int|float|null $previous = null,
        string $direction = 'up_is_good',
        ?string $note = null,
        ?string $drilldownRoute = null,
        array $drilldownParams = [],
    ): self {
        return new self(
            $id, $label, $value, $unit, $previous, $direction, $note,
            $drilldownRoute, $drilldownParams, null, null,
        );
    }

    /**
     * A card for something this deployment cannot currently measure.
     *
     * Used where a figure depends on a provider or an integration that is not
     * connected — an answer rate with no telephony behind it, say. It
     * renders as a card that says why, which is honest; a zero would not be.
     */
    public static function unavailable(string $id, string $label, string $reason, string $unit = 'count'): self
    {
        return new self($id, $label, null, $unit, null, 'neutral', null, null, [], 'unavailable', $reason);
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'id'         => $this->id,
            'label'      => $this->label,
            'value'      => $this->value,
            'unit'       => $this->unit,
            'previous'   => $this->previous,
            'change_pct' => $this->changePct(),
            'direction'  => $this->direction,
            'note'       => $this->note,
            'status'     => $this->status,
            'unavailable_reason' => $this->unavailableReason,
            'drilldown'  => $this->drilldownRoute === null ? null : [
                'route'  => $this->drilldownRoute,
                'params' => $this->drilldownParams,
            ],
        ];
    }

    private function changePct(): ?float
    {
        if ($this->value === null || $this->previous === null) {
            return null;
        }

        // Nothing to divide by. Going from zero to eight calls is a real
        // change and not "infinity per cent", so it is reported as no
        // comparison rather than a number that breaks the card.
        if ((float) $this->previous == 0.0) {
            return null;
        }

        return round((((float) $this->value - (float) $this->previous) / abs((float) $this->previous)) * 100, 1);
    }
}
