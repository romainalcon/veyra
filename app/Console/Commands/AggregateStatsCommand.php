<?php

namespace App\Console\Commands;

use App\Domain\Monitoring\Jobs\AggregateDailyStatsJob;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('veyra:stats:aggregate')]
#[Description('Queue the refresh of daily monitor statistics (today and yesterday).')]
class AggregateStatsCommand extends Command
{
    public function handle(): int
    {
        AggregateDailyStatsJob::dispatch();

        return self::SUCCESS;
    }
}
