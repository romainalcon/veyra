<?php

namespace App\Domain\Maintenance\Actions;

use App\Domain\Maintenance\Models\MaintenanceWindow;
use Carbon\CarbonImmutable;
use Illuminate\Validation\ValidationException;

/**
 * Cancels a window that has not started yet. A running window is ended
 * instead (EndMaintenanceWindowNow) so that history stays truthful.
 */
class CancelMaintenanceWindow
{
    /**
     * @throws ValidationException
     */
    public function handle(MaintenanceWindow $window): MaintenanceWindow
    {
        $now = CarbonImmutable::now();

        if ($window->isCancelled()) {
            return $window;
        }

        if ($window->hasStarted($now)) {
            throw ValidationException::withMessages(['window' => __('This maintenance window has already started.')]);
        }

        $window->update(['cancelled_at' => $now]);

        return $window;
    }
}
