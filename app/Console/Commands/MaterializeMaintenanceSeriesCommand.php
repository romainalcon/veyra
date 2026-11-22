<?php

namespace App\Console\Commands;

use App\Domain\Maintenance\Actions\MaterializeMaintenanceSeries;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('veyra:maintenance:materialize')]
#[Description('Create the upcoming occurrences of recurring maintenance series.')]
class MaterializeMaintenanceSeriesCommand extends Command
{
    public function handle(MaterializeMaintenanceSeries $materializeMaintenanceSeries): int
    {
        $created = $materializeMaintenanceSeries->handleAll();

        $this->components->info(sprintf('%d maintenance occurrence(s) created.', $created));

        return self::SUCCESS;
    }
}
