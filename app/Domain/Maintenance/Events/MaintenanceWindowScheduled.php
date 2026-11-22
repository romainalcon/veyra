<?php

namespace App\Domain\Maintenance\Events;

use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * A maintenance window was created (status page subscribers). Edits are not
 * announced again.
 */
class MaintenanceWindowScheduled implements ShouldDispatchAfterCommit
{
    use Dispatchable;

    public function __construct(public readonly string $maintenanceWindowId) {}
}
