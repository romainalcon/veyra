<?php

namespace App\Http\Presenters;

use App\Domain\Maintenance\Models\MaintenanceWindow;
use App\Domain\Monitoring\Models\Monitor;
use Carbon\CarbonImmutable;

final class MaintenanceWindowPresenter
{
    /**
     * @return array<string, mixed>
     */
    public static function summary(MaintenanceWindow $window, ?CarbonImmutable $now = null): array
    {
        $now ??= CarbonImmutable::now();

        return [
            'id' => $window->id,
            'title' => $window->title,
            'description' => $window->description,
            'starts_at' => $window->starts_at->toIso8601String(),
            'ends_at' => $window->ends_at->toIso8601String(),
            'cancelled_at' => $window->cancelled_at?->toIso8601String(),
            'status' => self::status($window, $now),
            'creator' => $window->creator?->name,
            'monitors' => $window->monitors
                ->map(fn (Monitor $monitor): array => ['id' => $monitor->id, 'name' => $monitor->name])
                ->values()
                ->all(),
        ];
    }

    /**
     * scheduled | active | completed | cancelled
     */
    public static function status(MaintenanceWindow $window, CarbonImmutable $now): string
    {
        return match (true) {
            $window->isCancelled() => 'cancelled',
            $window->hasEnded($now) => 'completed',
            $window->hasStarted($now) => 'active',
            default => 'scheduled',
        };
    }
}
