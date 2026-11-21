<?php

namespace App\Domain\Monitoring\Jobs;

use App\Domain\Monitoring\Stats\AggregateDailyStats;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Refreshes today's and yesterday's aggregates (late checks may still land
 * in yesterday around midnight).
 */
class AggregateDailyStatsJob implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public int $timeout = 110;

    public int $uniqueFor = 600;

    public function __construct()
    {
        $this->onQueue('maintenance');
    }

    public function handle(AggregateDailyStats $aggregateDailyStats): void
    {
        $now = CarbonImmutable::now();

        $aggregateDailyStats->handle($now->subDay(), $now);
        $aggregateDailyStats->handle($now, $now);
    }
}
