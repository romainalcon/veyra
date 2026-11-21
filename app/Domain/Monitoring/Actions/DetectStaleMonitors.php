<?php

namespace App\Domain\Monitoring\Actions;

use App\Domain\Incidents\Actions\RecordIncidentEvent;
use App\Domain\Incidents\Enums\IncidentEventType;
use App\Domain\Incidents\Models\Incident;
use App\Domain\Monitoring\Enums\MonitorStatus;
use App\Domain\Monitoring\Events\MonitorStateChanged;
use App\Domain\Monitoring\Models\MonitorState;
use App\Domain\Realtime\OrganizationUpdate;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Missing or stale data means UNKNOWN, never DOWN: a scheduler or worker
 * outage must not open (or resolve) incidents. Monitors under planned
 * maintenance are left alone: their state is restored when the window ends.
 */
class DetectStaleMonitors
{
    public function __construct(private readonly RecordIncidentEvent $recordIncidentEvent) {}

    public function handle(?CarbonImmutable $now = null): int
    {
        $now ??= CarbonImmutable::now();
        $grace = (int) config('veyra.monitoring.stale_grace_seconds', 120);

        // Heartbeats may legitimately stay silent for their grace time on top of the period.
        $candidates = DB::table('monitor_states')
            ->join('monitors', 'monitors.id', '=', 'monitor_states.monitor_id')
            ->leftJoin('monitor_heartbeat_configs', 'monitor_heartbeat_configs.monitor_id', '=', 'monitors.id')
            ->where('monitors.enabled', true)
            ->whereNull('monitors.deleted_at')
            ->whereNotIn('monitor_states.status', [MonitorStatus::Unknown->value, MonitorStatus::Maintenance->value])
            ->whereNotNull('monitor_states.last_checked_at')
            ->whereRaw('monitor_states.last_checked_at < DATE_SUB(?, INTERVAL (2 * monitors.interval_seconds + COALESCE(monitor_heartbeat_configs.grace_seconds, 0) + ?) SECOND)', [$now->toDateTimeString(), $grace])
            ->limit(500)
            ->get(['monitor_states.monitor_id', 'monitors.organization_id', 'monitors.interval_seconds', 'monitor_heartbeat_configs.grace_seconds as heartbeat_grace']);

        $marked = 0;

        foreach ($candidates as $candidate) {
            $monitorId = (string) $candidate->monitor_id;
            $organizationId = (string) $candidate->organization_id;
            $staleAfter = 2 * (int) $candidate->interval_seconds + (int) ($candidate->heartbeat_grace ?? 0) + $grace;

            $marked += DB::transaction(function () use ($monitorId, $organizationId, $staleAfter, $now): int {
                $state = MonitorState::query()->lockForUpdate()->find($monitorId);

                if ($state === null
                    || in_array($state->status, [MonitorStatus::Unknown, MonitorStatus::Maintenance], true)
                    || $state->last_checked_at === null
                    || $state->last_checked_at->greaterThanOrEqualTo($now->subSeconds($staleAfter))) {
                    return 0;
                }

                $previous = $state->status;

                $state->update([
                    'status' => MonitorStatus::Unknown,
                    'consecutive_failures' => 0,
                    'consecutive_successes' => 0,
                    'streak_since' => $now,
                    'last_changed_at' => $now,
                ]);

                $incident = $state->active_incident_id ? Incident::query()->find($state->active_incident_id) : null;

                if ($incident !== null) {
                    $this->recordIncidentEvent->handle($incident, IncidentEventType::DataUnavailable, at: $now);
                }

                MonitorStateChanged::dispatch($monitorId, $previous, MonitorStatus::Unknown);
                OrganizationUpdate::dispatch($organizationId, OrganizationUpdate::MONITOR_STATE_CHANGED, [
                    'monitor_id' => $monitorId,
                    'status' => MonitorStatus::Unknown->value,
                ]);

                return 1;
            });
        }

        return $marked;
    }
}
