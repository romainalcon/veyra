<?php

namespace App\Http\Resources\Api\V1;

use App\Domain\Maintenance\Models\MaintenanceWindow;
use App\Http\Presenters\MaintenanceWindowPresenter;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A maintenance window (ADR-044). Occurrences of a recurring series carry
 * their `series_id`; series themselves are managed in the interface.
 *
 * @mixin MaintenanceWindow
 */
class MaintenanceWindowResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        /** @var MaintenanceWindow $window */
        $window = $this->resource;

        return [
            'id' => $window->id,
            'series_id' => $window->series_id,
            'title' => $window->title,
            'description' => $window->description,
            'starts_at' => $window->starts_at->toIso8601String(),
            'ends_at' => $window->ends_at->toIso8601String(),
            'cancelled_at' => $window->cancelled_at?->toIso8601String(),
            'status' => MaintenanceWindowPresenter::status($window, CarbonImmutable::now()),
            'monitor_ids' => $window->monitors->pluck('id')->values()->all(),
            'created_at' => $window->created_at?->toIso8601String(),
        ];
    }
}
