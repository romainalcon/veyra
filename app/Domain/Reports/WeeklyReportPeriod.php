<?php

namespace App\Domain\Reports;

use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;

/**
 * The reported week: Monday 00:00 to the next Monday 00:00 in the
 * organization time zone, due from Monday `SEND_HOUR`:00 local time of the
 * following week. Boundaries are local midnights, so a week that crosses a
 * daylight saving change lasts 167 or 169 hours.
 */
final readonly class WeeklyReportPeriod
{
    public const SEND_HOUR = 8;

    private function __construct(
        public CarbonImmutable $start,
        public CarbonImmutable $end,
        public string $localStartDate,
    ) {}

    /**
     * The last completed week whose report is due at `$now`, or null before
     * Monday's sending hour (last week's report was due a week ago).
     */
    public static function dueAt(CarbonImmutable $now, string $timezone): ?self
    {
        $currentMonday = $now->setTimezone($timezone)->startOfWeek(CarbonInterface::MONDAY);

        if ($now->lessThan($currentMonday->setTime(self::SEND_HOUR, 0))) {
            return null;
        }

        $previousMonday = $currentMonday->subWeek();

        return new self(
            start: $previousMonday->utc(),
            end: $currentMonday->utc(),
            localStartDate: $previousMonday->toDateString(),
        );
    }
}
