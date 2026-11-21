<?php

namespace App\Domain\Monitoring\Actions;

use App\Domain\Monitoring\Models\Monitor;

class SetMonitorEnabled
{
    /**
     * Resuming schedules a check right away; pausing stops scheduling (and the
     * probe job skips any dispatch already queued).
     */
    public function handle(Monitor $monitor, bool $enabled): Monitor
    {
        if ($monitor->enabled === $enabled) {
            return $monitor;
        }

        $monitor->update([
            'enabled' => $enabled,
            'next_check_at' => $enabled ? now()->startOfSecond() : null,
        ]);

        return $monitor;
    }
}
