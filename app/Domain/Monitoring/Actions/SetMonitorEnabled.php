<?php

namespace App\Domain\Monitoring\Actions;

use App\Domain\Monitoring\Enums\MonitorStatus;
use App\Domain\Monitoring\Events\MonitorStateChanged;
use App\Domain\Monitoring\Models\Monitor;
use App\Domain\Monitoring\Models\MonitorState;
use App\Domain\Realtime\OrganizationUpdate;
use Illuminate\Support\Facades\DB;

class SetMonitorEnabled
{
    /**
     * Pausing stops scheduling (queued dispatches are skipped by the probe
     * job) and the state becomes UNKNOWN; an open incident stays open.
     * Resuming schedules a check right away and restarts the streaks, so
     * results from before the pause never confirm a transition.
     */
    public function handle(Monitor $monitor, bool $enabled): Monitor
    {
        if ($monitor->enabled === $enabled) {
            return $monitor;
        }

        DB::transaction(function () use ($monitor, $enabled): void {
            MonitorState::ensureFor($monitor->id);
            $state = MonitorState::query()->lockForUpdate()->findOrFail($monitor->id);
            $previous = $state->status;

            $monitor->update([
                'enabled' => $enabled,
                'next_check_at' => $enabled ? now()->startOfSecond() : null,
            ]);

            $state->update([
                'status' => MonitorStatus::Unknown,
                'consecutive_failures' => 0,
                'consecutive_successes' => 0,
                'streak_since' => now(),
                ...($previous !== MonitorStatus::Unknown ? ['last_changed_at' => now()] : []),
            ]);

            if ($previous !== MonitorStatus::Unknown) {
                MonitorStateChanged::dispatch($monitor->id, $previous, MonitorStatus::Unknown);
            }

            OrganizationUpdate::dispatch($monitor->organization_id, OrganizationUpdate::MONITOR_STATE_CHANGED, [
                'monitor_id' => $monitor->id,
                'status' => MonitorStatus::Unknown->value,
            ]);
        });

        return $monitor;
    }
}
