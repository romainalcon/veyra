<?php

namespace App\Domain\Monitoring\Stats;

use Carbon\CarbonImmutable;

enum StatsRange: string
{
    case Day = '24h';
    case Week = '7d';
    case Month = '30d';
    case Quarter = '90d';

    public function start(CarbonImmutable $now): CarbonImmutable
    {
        return match ($this) {
            self::Day => $now->subDay(),
            self::Week => $now->subDays(7),
            self::Month => $now->subDays(30)->startOfDay()->addDay(),
            self::Quarter => $now->subDays(90)->startOfDay()->addDay(),
        };
    }

    /**
     * Bucket size in seconds for raw-check series; null = daily aggregates.
     */
    public function bucketSeconds(): ?int
    {
        return match ($this) {
            self::Day => 900,
            self::Week => 3600,
            default => null,
        };
    }
}
