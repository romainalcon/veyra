<?php

namespace App\Domain\Maintenance\DTOs;

use App\Domain\Maintenance\Enums\MaintenanceFrequency;

final readonly class MaintenanceSeriesData
{
    /**
     * @param  list<int>  $weekdays  ISO-8601 days, weekly series only
     * @param  string  $startTime  HH:MM in the organization time zone
     * @param  string  $startsOn  Y-m-d
     * @param  string|null  $endsOn  Y-m-d, inclusive
     * @param  list<string>  $monitorIds
     */
    public function __construct(
        public string $title,
        public ?string $description,
        public MaintenanceFrequency $frequency,
        public array $weekdays,
        public ?int $monthDay,
        public string $startTime,
        public int $durationMinutes,
        public string $startsOn,
        public ?string $endsOn,
        public array $monitorIds,
    ) {}
}
