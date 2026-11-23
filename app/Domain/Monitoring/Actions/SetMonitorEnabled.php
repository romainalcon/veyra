<?php

namespace App\Domain\Monitoring\Actions;

use App\Domain\Audit\Enums\AuditAction;
use App\Domain\Audit\Services\AuditLogger;
use App\Domain\Audit\Services\AuditSnapshot;
use App\Domain\Entitlements\Enums\Feature;
use App\Domain\Entitlements\Services\Quota;
use App\Domain\Monitoring\Enums\MonitorStatus;
use App\Domain\Monitoring\Events\MonitorStateChanged;
use App\Domain\Monitoring\Models\Monitor;
use App\Domain\Monitoring\Models\MonitorState;
use App\Domain\Monitoring\Support\CheckSchedule;
use App\Domain\Realtime\OrganizationUpdate;
use Illuminate\Support\Facades\DB;

class SetMonitorEnabled
{
    public function __construct(
        private readonly Quota $quota,
        private readonly AuditLogger $audit,
    ) {}

    /**
     * Pausing stops scheduling (queued dispatches are skipped by the probe
     * job) and the state becomes UNKNOWN; an open incident stays open.
     * Resuming schedules a check right away (a heartbeat gets a full period
     * plus grace) and restarts the streaks, so results from before the pause
     * never confirm a transition. Resuming counts against the active monitor
     * limit; pausing is always allowed.
     */
    public function handle(Monitor $monitor, bool $enabled): Monitor
    {
        if ($monitor->enabled === $enabled) {
            return $monitor;
        }

        DB::transaction(function () use ($monitor, $enabled): void {
            if ($enabled) {
                $this->quota->ensureCanAdd($monitor->organization, Feature::Monitors);
            }

            MonitorState::ensureFor($monitor->id);
            $state = MonitorState::query()->lockForUpdate()->findOrFail($monitor->id);
            $previous = $state->status;

            $monitor->update([
                'enabled' => $enabled,
                'next_check_at' => $enabled
                    ? CheckSchedule::initial($monitor->type, $monitor->interval_seconds, $monitor->heartbeatConfig->grace_seconds ?? 0)
                    : null,
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

            $this->audit->record($monitor->organization_id, $enabled ? AuditAction::MonitorResumed : AuditAction::MonitorPaused, AuditSnapshot::monitorSubject($monitor));

            OrganizationUpdate::dispatch($monitor->organization_id, OrganizationUpdate::MONITOR_STATE_CHANGED, [
                'monitor_id' => $monitor->id,
                'status' => MonitorStatus::Unknown->value,
            ]);
        });

        return $monitor;
    }
}
