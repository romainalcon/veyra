<?php

namespace App\Domain\Maintenance\Services;

use App\Domain\Maintenance\Enums\MaintenanceFrequency;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use DateTimeZone;

/**
 * A daily, weekly or monthly maintenance rule and its expansion into UTC
 * occurrences (ADR-041). Pure: no database access.
 *
 * Local wall-clock rules: an occurrence starts on its local date at
 * `startTime` in the given time zone and lasts `durationMinutes` of real time.
 * A start that does not exist (spring-forward gap) is moved forward by the
 * gap; an ambiguous start (fall-back overlap) takes the first of the two
 * instants. A monthly day beyond the month's length means its last day.
 */
final readonly class RecurrenceRule
{
    /**
     * @param  list<int>  $weekdays  ISO-8601 days (1 = Monday … 7 = Sunday), weekly rules only
     * @param  string  $startTime  HH:MM
     * @param  string  $startsOn  first local date (Y-m-d)
     * @param  string|null  $endsOn  last local date (Y-m-d), inclusive
     */
    public function __construct(
        public MaintenanceFrequency $frequency,
        public array $weekdays,
        public ?int $monthDay,
        public string $startTime,
        public int $durationMinutes,
        public string $startsOn,
        public ?string $endsOn = null,
    ) {}

    /**
     * Occurrences starting within [from, until], in chronological order.
     *
     * @return list<array{date: string, starts_at: CarbonImmutable, ends_at: CarbonImmutable}>
     */
    public function occurrencesBetween(CarbonInterface $from, CarbonInterface $until, string $timezone): array
    {
        $zone = new DateTimeZone($timezone);
        $from = CarbonImmutable::instance($from)->utc();
        $until = CarbonImmutable::instance($until)->utc();

        // One local day of margin on each side: the zone offset may move a
        // local date across the UTC boundaries of the range.
        $day = $from->setTimezone($zone)->startOfDay()->subDay();
        $lastDay = $until->setTimezone($zone)->startOfDay()->addDay();
        $first = CarbonImmutable::parse($this->startsOn, $zone);

        if ($day->lessThan($first)) {
            $day = $first;
        }

        if ($this->endsOn !== null) {
            $end = CarbonImmutable::parse($this->endsOn, $zone);
            $lastDay = $end->lessThan($lastDay) ? $end : $lastDay;
        }

        $occurrences = [];

        for (; $day->lessThanOrEqualTo($lastDay); $day = $day->addDay()) {
            if (! $this->matches($day)) {
                continue;
            }

            $startsAt = $this->localStart($day->toDateString(), $zone);

            if ($startsAt->lessThan($from) || $startsAt->greaterThan($until)) {
                continue;
            }

            $occurrences[] = [
                'date' => $day->toDateString(),
                'starts_at' => $startsAt,
                'ends_at' => $startsAt->addMinutes($this->durationMinutes),
            ];
        }

        return $occurrences;
    }

    private function matches(CarbonImmutable $day): bool
    {
        return match ($this->frequency) {
            MaintenanceFrequency::Daily => true,
            MaintenanceFrequency::Weekly => in_array($day->isoWeekday(), $this->weekdays, true),
            MaintenanceFrequency::Monthly => $this->monthDay !== null
                && $day->day === min($this->monthDay, $day->daysInMonth),
        };
    }

    /**
     * UTC instant of the local wall-clock start on the given local date.
     */
    private function localStart(string $date, DateTimeZone $zone): CarbonImmutable
    {
        $wallClock = $date.' '.$this->startTime;

        // PHP moves a non-existent local time forward by the gap and resolves
        // an ambiguous one to the later instant; prefer the earlier one.
        $start = CarbonImmutable::createFromFormat('Y-m-d H:i', $wallClock, $zone);

        if ($start === null) {
            $start = CarbonImmutable::parse($wallClock, $zone);
        }

        $start = $start->startOfMinute();
        $offsetBefore = $start->subHours(3)->getOffset();
        $shift = $offsetBefore - $start->getOffset();

        if ($shift > 0) {
            $earlier = $start->subSeconds($shift);

            if ($earlier->format('Y-m-d H:i') === $wallClock) {
                $start = $earlier;
            }
        }

        return $start->utc();
    }
}
