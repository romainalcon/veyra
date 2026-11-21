<?php

namespace App\Console\Commands;

use App\Domain\Monitoring\Actions\PublishPendingDispatches;
use App\Domain\Monitoring\Actions\ScheduleDueMonitors;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('veyra:monitors:dispatch')]
#[Description('Claim due monitors into the outbox and publish pending dispatches to the probes queue.')]
class DispatchDueMonitors extends Command
{
    public function handle(ScheduleDueMonitors $scheduleDueMonitors, PublishPendingDispatches $publishPendingDispatches): int
    {
        $claimed = $scheduleDueMonitors->handle();
        $counts = $publishPendingDispatches->handle();

        $this->components->info(sprintf(
            'Claimed %d, published %d, skipped %d, failed %d.',
            $claimed,
            $counts['published'],
            $counts['skipped'],
            $counts['failed'],
        ));

        return self::SUCCESS;
    }
}
