<?php

namespace App\Domain\Maintenance\Actions;

use App\Domain\Maintenance\Models\MaintenanceSeries;
use App\Domain\Maintenance\Models\MaintenanceWindow;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Deletes a series and its occurrences that have not started yet. Started and
 * past occurrences stay in the history as one-off windows (series_id is set
 * to null by the foreign key).
 */
class DeleteMaintenanceSeries
{
    public function handle(MaintenanceSeries $series): void
    {
        $now = CarbonImmutable::now()->startOfSecond();

        DB::transaction(function () use ($series, $now): void {
            $locked = MaintenanceSeries::query()->lockForUpdate()->find($series->id);

            if ($locked === null) {
                return;
            }

            MaintenanceWindow::query()
                ->where('series_id', $locked->id)
                ->where('starts_at', '>', $now)
                ->delete();

            $locked->delete();
        });
    }
}
