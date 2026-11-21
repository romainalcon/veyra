<?php

namespace App\Domain\Maintenance\Actions;

use App\Domain\Incidents\Actions\RecordIncidentEvent;
use App\Domain\Incidents\Enums\IncidentEventType;
use App\Domain\Incidents\Models\Incident;
use App\Domain\Maintenance\Services\MaintenanceSchedule;
use App\Domain\Monitoring\Enums\MonitorStatus;
use App\Domain\Monitoring\Events\MonitorStateChanged;
use App\Domain\Monitoring\Models\Monitor;
use App\Domain\Monitoring\Models\MonitorState;
use App\Domain\Realtime\OrganizationUpdate;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Moves monitors into MAINTENANCE when a window starts and back to UNKNOWN
 * when it ends, without waiting for the next check. Each monitor is handled
 * under its monitor_states row lock and re-verified there, so the command is
 * idempotent and safe alongside ApplyMonitorState.
 */
class ApplyMaintenanceTransitions
{
    public const BATCH = 500;

    public function __construct(
        private readonly MaintenanceSchedule $maintenanceSchedule,
        private readonly RecordIncidentEvent $recordIncidentEvent,
    ) {}

    /**
     * @return array{entered: int, exited: int}
     */
    public function handle(?CarbonImmutable $now = null): array
    {
        $now ??= CarbonImmutable::now();
        $underMaintenance = $this->maintenanceSchedule->monitorsUnderMaintenance($now);

        $entering = MonitorState::query()
            ->whereIn('monitor_id', $underMaintenance)
            ->where('status', '!=', MonitorStatus::Maintenance->value)
            ->limit(self::BATCH)
            ->pluck('monitor_id');

        $exiting = MonitorState::query()
            ->where('status', MonitorStatus::Maintenance->value)
            ->whereNotIn('monitor_id', $underMaintenance)
            ->limit(self::BATCH)
            ->pluck('monitor_id');

        $entered = 0;
        $exited = 0;

        foreach ($entering as $monitorId) {
            $entered += $this->enter((string) $monitorId, $now);
        }

        foreach ($exiting as $monitorId) {
            $exited += $this->exit((string) $monitorId, $now);
        }

        return ['entered' => $entered, 'exited' => $exited];
    }

    private function enter(string $monitorId, CarbonImmutable $now): int
    {
        return DB::transaction(function () use ($monitorId, $now): int {
            $state = MonitorState::query()->lockForUpdate()->find($monitorId);
            $monitor = Monitor::query()->find($monitorId);

            if ($state === null || $monitor === null || ! $monitor->enabled
                || $state->status === MonitorStatus::Maintenance
                || ! $this->maintenanceSchedule->covers($monitorId, $now)) {
                return 0;
            }

            $previous = $state->status;

            $state->update([
                'status' => MonitorStatus::Maintenance,
                'consecutive_failures' => 0,
                'consecutive_successes' => 0,
                'last_changed_at' => $now,
            ]);

            $this->recordOnIncident($state, IncidentEventType::MaintenanceStarted, $now);
            $this->announce($monitor, $previous, MonitorStatus::Maintenance);

            return 1;
        });
    }

    private function exit(string $monitorId, CarbonImmutable $now): int
    {
        return DB::transaction(function () use ($monitorId, $now): int {
            $state = MonitorState::query()->lockForUpdate()->find($monitorId);
            $monitor = Monitor::withTrashed()->find($monitorId);

            if ($state === null || $monitor === null
                || $state->status !== MonitorStatus::Maintenance
                || $this->maintenanceSchedule->covers($monitorId, $now)) {
                return 0;
            }

            $endedAt = $this->maintenanceSchedule->lastEndedAt($monitorId, $now);

            $state->update([
                'status' => MonitorStatus::Unknown,
                'consecutive_failures' => 0,
                'consecutive_successes' => 0,
                'streak_since' => $endedAt ?? $now,
                'last_changed_at' => $now,
            ]);

            $this->recordOnIncident($state, IncidentEventType::MaintenanceEnded, $now);
            $this->announce($monitor, MonitorStatus::Maintenance, MonitorStatus::Unknown);

            return 1;
        });
    }

    private function recordOnIncident(MonitorState $state, IncidentEventType $type, CarbonImmutable $at): void
    {
        $incident = $state->active_incident_id ? Incident::query()->find($state->active_incident_id) : null;

        if ($incident !== null) {
            $this->recordIncidentEvent->handle($incident, $type, at: $at);
        }
    }

    private function announce(Monitor $monitor, MonitorStatus $previous, MonitorStatus $current): void
    {
        MonitorStateChanged::dispatch($monitor->id, $previous, $current);
        OrganizationUpdate::dispatch($monitor->organization_id, OrganizationUpdate::MONITOR_STATE_CHANGED, [
            'monitor_id' => $monitor->id,
            'status' => $current->value,
        ]);
    }
}
