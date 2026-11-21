<?php

namespace App\Domain\Monitoring\Actions;

use App\Domain\Incidents\Actions\OpenIncident;
use App\Domain\Incidents\Actions\RecordIncidentEvent;
use App\Domain\Incidents\Actions\ResolveIncident;
use App\Domain\Incidents\Enums\IncidentEventType;
use App\Domain\Incidents\Models\Incident;
use App\Domain\Maintenance\Services\MaintenanceSchedule;
use App\Domain\Monitoring\Enums\MonitorStatus;
use App\Domain\Monitoring\Events\MonitorStateChanged;
use App\Domain\Monitoring\Models\Monitor;
use App\Domain\Monitoring\Models\MonitorCheck;
use App\Domain\Monitoring\Models\MonitorState;
use App\Domain\Monitoring\State\StateEvaluator;
use App\Domain\Monitoring\State\StateThresholds;
use App\Domain\Realtime\OrganizationUpdate;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Derives the confirmed state of a monitor from its latest checks and opens or
 * resolves its incident. Runs under the monitor_states row lock, so concurrent
 * workers serialize; replays and late (out-of-order) checks are no-ops thanks
 * to the `last_applied_scheduled_for` guard. Checks flagged `in_maintenance`
 * hold the MAINTENANCE state and never count towards a streak.
 */
class ApplyMonitorState
{
    /**
     * Checks fetched per evaluation (covers thresholds and the streak start).
     */
    public const LOOKBACK = 10;

    public function __construct(
        private readonly OpenIncident $openIncident,
        private readonly ResolveIncident $resolveIncident,
        private readonly RecordIncidentEvent $recordIncidentEvent,
        private readonly MaintenanceSchedule $maintenanceSchedule,
    ) {}

    public function handle(string $monitorId, ?StateThresholds $thresholds = null): ?MonitorStatus
    {
        $thresholds ??= StateThresholds::fromConfig();

        return DB::transaction(function () use ($monitorId, $thresholds): ?MonitorStatus {
            MonitorState::ensureFor($monitorId);

            $state = MonitorState::query()->lockForUpdate()->find($monitorId);
            $monitor = Monitor::withTrashed()->find($monitorId);

            if ($state === null || $monitor === null || $monitor->trashed() || ! $monitor->enabled) {
                return null;
            }

            $checks = MonitorCheck::query()
                ->where('monitor_id', $monitorId)
                ->when($state->streak_since, fn ($query, $since) => $query->where('scheduled_for', '>=', $since))
                ->orderByDesc('scheduled_for')
                ->orderByDesc('id')
                ->limit(max(self::LOOKBACK, $thresholds->window()))
                ->get(['id', 'scheduled_for', 'checked_at', 'success', 'status_code', 'error_code', 'in_maintenance']);

            $newest = $checks->first();

            if ($newest === null
                || ($state->last_applied_scheduled_for !== null && $newest->scheduled_for->lessThanOrEqualTo($state->last_applied_scheduled_for))) {
                return $state->status;
            }

            $previous = $state->status;

            if ($newest->in_maintenance
                || ($previous === MonitorStatus::Maintenance && $this->maintenanceSchedule->covers($monitorId, CarbonImmutable::now()))) {
                return $this->holdMaintenance($state, $monitor, $newest);
            }

            $baseline = $previous;

            if ($previous === MonitorStatus::Maintenance) {
                // First result after the window: streaks restart from it, as after a resume.
                $state->streak_since = $newest->scheduled_for;
                $checks = $checks->take(1);
                $baseline = MonitorStatus::Unknown;
            }

            $checks = $checks->reject(fn (MonitorCheck $check): bool => $check->in_maintenance)->values();
            $results = array_values($checks->map(fn (MonitorCheck $check): bool => $check->success)->all());
            $evaluation = StateEvaluator::evaluate($baseline, $results, $thresholds);
            $streak = $checks->take(max($evaluation->consecutiveFailures, $evaluation->consecutiveSuccesses));
            $streakStart = $streak->last() ?? $newest;

            $incident = $state->active_incident_id ? Incident::query()->find($state->active_incident_id) : null;

            if ($incident !== null && $previous === MonitorStatus::Unknown && $this->lastEventIs($incident, IncidentEventType::DataUnavailable)) {
                $this->recordIncidentEvent->handle($incident, IncidentEventType::DataRestored, at: $newest->checked_at);
            }

            if ($incident !== null && $previous === MonitorStatus::Maintenance && $this->lastEventIs($incident, IncidentEventType::MaintenanceStarted)) {
                $this->recordIncidentEvent->handle($incident, IncidentEventType::MaintenanceEnded, at: $newest->checked_at);
            }

            if ($evaluation->status === MonitorStatus::Down && $incident === null) {
                $incident = $this->openIncident->handle($monitor, $streakStart->checked_at, $this->cause($newest));
            }

            if ($evaluation->status === MonitorStatus::Up && $incident !== null) {
                $this->resolveIncident->handle($incident, $streakStart->checked_at);
                $incident = null;
            }

            $changed = $evaluation->status !== $previous;

            $state->update([
                'status' => $evaluation->status,
                'consecutive_failures' => $evaluation->consecutiveFailures,
                'consecutive_successes' => $evaluation->consecutiveSuccesses,
                'last_checked_at' => $newest->checked_at,
                'last_applied_scheduled_for' => $newest->scheduled_for,
                'active_incident_id' => $incident?->id,
                'streak_since' => $state->streak_since,
                ...($changed ? ['last_changed_at' => $newest->checked_at] : []),
            ]);

            OrganizationUpdate::dispatch($monitor->organization_id, OrganizationUpdate::MONITOR_CHECKED, ['monitor_id' => $monitorId]);

            if ($changed) {
                MonitorStateChanged::dispatch($monitorId, $previous, $evaluation->status);
                OrganizationUpdate::dispatch($monitor->organization_id, OrganizationUpdate::MONITOR_STATE_CHANGED, [
                    'monitor_id' => $monitorId,
                    'status' => $evaluation->status->value,
                ]);
            }

            return $evaluation->status;
        });
    }

    /**
     * During planned maintenance the state is MAINTENANCE: results are kept in
     * history but never open, resolve or confirm anything.
     */
    private function holdMaintenance(MonitorState $state, Monitor $monitor, MonitorCheck $newest): MonitorStatus
    {
        $previous = $state->status;
        $changed = $previous !== MonitorStatus::Maintenance;

        $state->update([
            'status' => MonitorStatus::Maintenance,
            'consecutive_failures' => 0,
            'consecutive_successes' => 0,
            'last_checked_at' => $newest->checked_at,
            'last_applied_scheduled_for' => $newest->scheduled_for,
            ...($changed ? ['last_changed_at' => $newest->checked_at] : []),
        ]);

        $incident = $state->active_incident_id ? Incident::query()->find($state->active_incident_id) : null;

        if ($changed && $incident !== null) {
            $this->recordIncidentEvent->handle($incident, IncidentEventType::MaintenanceStarted, at: $newest->checked_at);
        }

        OrganizationUpdate::dispatch($monitor->organization_id, OrganizationUpdate::MONITOR_CHECKED, ['monitor_id' => $monitor->id]);

        if ($changed) {
            MonitorStateChanged::dispatch($monitor->id, $previous, MonitorStatus::Maintenance);
            OrganizationUpdate::dispatch($monitor->organization_id, OrganizationUpdate::MONITOR_STATE_CHANGED, [
                'monitor_id' => $monitor->id,
                'status' => MonitorStatus::Maintenance->value,
            ]);
        }

        return MonitorStatus::Maintenance;
    }

    private function cause(MonitorCheck $check): string
    {
        $label = $check->error_code?->label() ?? 'Check failed';

        return $check->status_code !== null ? "{$label} ({$check->status_code})" : $label;
    }

    private function lastEventIs(Incident $incident, IncidentEventType $type): bool
    {
        return $incident->events()->latest('created_at')->latest('id')->first()?->type === $type;
    }
}
