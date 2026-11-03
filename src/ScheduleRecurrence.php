<?php

declare(strict_types=1);

namespace Scrapfly;

/**
 * When a schedule fires: a cron expression, or an interval of units. Times are UTC.
 * A schedule without an end runs until it is cancelled.
 */
final class ScheduleRecurrence
{
    /**
     * @param string|null $unit `minute`, `hour`, `day`, `week` or `month`, with `interval`
     * @param list<string> $days the week days an interval fires on
     * @param string|null $endsType `never`, `on` (with `endsDate`) or `after` (with `endsCount`)
     */
    public function __construct(
        public ?string $cron = null,
        public ?int $interval = null,
        public ?string $unit = null,
        public array $days = [],
        public ?string $endsType = null,
        public ?string $endsDate = null,
        public ?int $endsCount = null,
    ) {
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        $ends = $this->endsType === null ? null : array_filter(['type' => $this->endsType, 'date' => $this->endsDate, 'count' => $this->endsCount], static fn (string|int|null $value): bool => $value !== null);

        return array_filter([
            'cron' => $this->cron,
            'interval' => $this->interval,
            'unit' => $this->unit,
            'days' => $this->days === [] ? null : $this->days,
            'ends' => $ends,
        ], static fn (mixed $value): bool => $value !== null);
    }
}
