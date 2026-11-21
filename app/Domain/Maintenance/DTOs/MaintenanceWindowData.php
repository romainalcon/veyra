<?php

namespace App\Domain\Maintenance\DTOs;

use Carbon\CarbonImmutable;

final readonly class MaintenanceWindowData
{
    /**
     * @param  list<string>  $monitorIds
     */
    public function __construct(
        public string $title,
        public ?string $description,
        public CarbonImmutable $startsAt,
        public CarbonImmutable $endsAt,
        public array $monitorIds,
    ) {}
}
