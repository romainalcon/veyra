<?php

namespace App\Http\Presenters;

use App\Domain\Maintenance\Models\MaintenanceSeries;
use App\Domain\Monitoring\Models\Monitor;

final class MaintenanceSeriesPresenter
{
    /**
     * @return array<string, mixed>
     */
    public static function summary(MaintenanceSeries $series): array
    {
        return [
            'id' => $series->id,
            'title' => $series->title,
            'description' => $series->description,
            'frequency' => $series->frequency->value,
            'weekdays' => array_map('intval', $series->weekdays ?? []),
            'month_day' => $series->month_day,
            'start_time' => substr($series->start_time, 0, 5),
            'duration_minutes' => $series->duration_minutes,
            'starts_on' => $series->starts_on->toDateString(),
            'ends_on' => $series->ends_on?->toDateString(),
            'creator' => $series->creator?->name,
            'monitors' => $series->monitors
                ->map(fn (Monitor $monitor): array => ['id' => $monitor->id, 'name' => $monitor->name])
                ->values()
                ->all(),
        ];
    }
}
