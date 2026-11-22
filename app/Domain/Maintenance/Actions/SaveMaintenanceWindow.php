<?php

namespace App\Domain\Maintenance\Actions;

use App\Domain\Maintenance\DTOs\MaintenanceWindowData;
use App\Domain\Maintenance\Events\MaintenanceWindowScheduled;
use App\Domain\Maintenance\Models\MaintenanceWindow;
use App\Domain\Monitoring\Models\Monitor;
use App\Domain\Organizations\Models\Organization;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Creates or updates a one-off maintenance window. History is never rewritten:
 * a started window keeps its start, an ended or cancelled one is read-only.
 */
class SaveMaintenanceWindow
{
    /**
     * @throws ValidationException
     */
    public function handle(Organization $organization, MaintenanceWindowData $data, ?User $user = null, ?MaintenanceWindow $window = null): MaintenanceWindow
    {
        $now = CarbonImmutable::now();

        if ($window !== null && ($window->isCancelled() || $window->hasEnded($now))) {
            throw ValidationException::withMessages(['title' => __('This maintenance window is over and can no longer be changed.')]);
        }

        $startsAt = $window !== null && $window->hasStarted($now) ? $window->starts_at : $data->startsAt;

        if ($data->endsAt->lessThanOrEqualTo($startsAt)) {
            throw ValidationException::withMessages(['ends_at' => __('The end must be after the start.')]);
        }

        if ($window !== null && $window->hasStarted($now) && $data->endsAt->lessThan($now)) {
            throw ValidationException::withMessages(['ends_at' => __('A running window cannot end in the past; end it now instead.')]);
        }

        return DB::transaction(function () use ($organization, $data, $user, $window, $startsAt): MaintenanceWindow {
            $attributes = [
                'title' => $data->title,
                'description' => $data->description,
                'starts_at' => $startsAt,
                'ends_at' => $data->endsAt,
            ];

            $created = $window === null;

            if ($window === null) {
                $window = $organization->maintenanceWindows()->create([...$attributes, 'created_by' => $user?->id]);
            } else {
                $window->update($attributes);
            }

            // Defence in depth: only monitors of the window's own organization.
            $window->monitors()->sync(
                Monitor::query()
                    ->where('organization_id', $organization->id)
                    ->whereIn('id', $data->monitorIds)
                    ->pluck('id')
                    ->all(),
            );

            if ($created) {
                MaintenanceWindowScheduled::dispatch($window->id);
            }

            return $window;
        });
    }
}
