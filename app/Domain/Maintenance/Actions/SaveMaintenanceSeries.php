<?php

namespace App\Domain\Maintenance\Actions;

use App\Domain\Maintenance\DTOs\MaintenanceSeriesData;
use App\Domain\Maintenance\Enums\MaintenanceFrequency;
use App\Domain\Maintenance\Events\MaintenanceWindowScheduled;
use App\Domain\Maintenance\Models\MaintenanceSeries;
use App\Domain\Monitoring\Models\Monitor;
use App\Domain\Organizations\Models\Organization;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Creates or updates a recurring maintenance series, then (re)generates its
 * future occurrences. Started and past occurrences are never altered. Only
 * the creation of a series is announced to status page subscribers (its first
 * occurrence), never each generated occurrence.
 */
class SaveMaintenanceSeries
{
    public function __construct(private readonly MaterializeMaintenanceSeries $materializeMaintenanceSeries) {}

    public function handle(Organization $organization, MaintenanceSeriesData $data, ?User $user = null, ?MaintenanceSeries $series = null): MaintenanceSeries
    {
        $created = $series === null;

        $series = DB::transaction(function () use ($organization, $data, $user, $series): MaintenanceSeries {
            $attributes = [
                'title' => $data->title,
                'description' => $data->description,
                'frequency' => $data->frequency,
                'weekdays' => $data->frequency === MaintenanceFrequency::Weekly ? $data->weekdays : null,
                'month_day' => $data->frequency === MaintenanceFrequency::Monthly ? $data->monthDay : null,
                'start_time' => $data->startTime.':00',
                'duration_minutes' => $data->durationMinutes,
                'starts_on' => $data->startsOn,
                'ends_on' => $data->endsOn,
            ];

            if ($series === null) {
                $series = $organization->maintenanceSeries()->create([...$attributes, 'created_by' => $user?->id]);
            } else {
                $series->update($attributes);
            }

            // Defence in depth: only monitors of the series' own organization.
            $series->monitors()->sync(
                Monitor::query()
                    ->where('organization_id', $organization->id)
                    ->whereIn('id', $data->monitorIds)
                    ->pluck('id')
                    ->all(),
            );

            return $series;
        });

        $this->materializeMaintenanceSeries->regenerate($series);

        if ($created) {
            $first = $series->occurrences()->whereNull('cancelled_at')->orderBy('starts_at')->first();

            if ($first !== null) {
                MaintenanceWindowScheduled::dispatch($first->id);
            }
        }

        return $series;
    }
}
