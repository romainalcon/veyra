<?php

namespace App\Console\Commands;

use App\Domain\Maintenance\Actions\ApplyMaintenanceTransitions;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('veyra:maintenance:transition')]
#[Description('Move monitors into and out of planned maintenance windows.')]
class ApplyMaintenanceTransitionsCommand extends Command
{
    public function handle(ApplyMaintenanceTransitions $applyMaintenanceTransitions): int
    {
        $result = $applyMaintenanceTransitions->handle();

        $this->components->info(sprintf('%d monitor(s) entered and %d left maintenance.', $result['entered'], $result['exited']));

        return self::SUCCESS;
    }
}
