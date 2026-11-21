<?php

namespace App\Domain\Maintenance\Actions;

use App\Domain\Maintenance\Models\MaintenanceWindow;
use Carbon\CarbonImmutable;
use Illuminate\Validation\ValidationException;

/**
 * Ends a running window early and restores the monitors' states right away.
 */
class EndMaintenanceWindowNow
{
    public function __construct(private readonly ApplyMaintenanceTransitions $applyMaintenanceTransitions) {}

    /**
     * @throws ValidationException
     */
    public function handle(MaintenanceWindow $window): MaintenanceWindow
    {
        $now = CarbonImmutable::now()->startOfSecond();

        if (! $window->isActive($now)) {
            throw ValidationException::withMessages(['window' => __('This maintenance window is not running.')]);
        }

        $window->update(['ends_at' => $now]);

        $this->applyMaintenanceTransitions->handle($now);

        return $window;
    }
}
