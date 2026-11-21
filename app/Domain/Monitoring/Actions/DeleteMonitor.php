<?php

namespace App\Domain\Monitoring\Actions;

use App\Domain\Monitoring\Models\Monitor;

class DeleteMonitor
{
    /**
     * Soft delete: history is kept until retention purges it. Pending
     * dispatches are skipped by the probe job.
     */
    public function handle(Monitor $monitor): void
    {
        $monitor->delete();
    }
}
