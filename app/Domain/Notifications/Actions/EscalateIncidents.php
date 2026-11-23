<?php

namespace App\Domain\Notifications\Actions;

use App\Domain\Incidents\Actions\RecordIncidentEvent;
use App\Domain\Incidents\Enums\IncidentEventType;
use App\Domain\Incidents\Enums\IncidentStatus;
use App\Domain\Incidents\Models\Incident;
use App\Domain\Monitoring\Enums\MonitorStatus;
use App\Domain\Monitoring\Models\Monitor;
use App\Domain\Monitoring\Models\MonitorState;
use App\Domain\Notifications\Models\EscalationPolicy;
use App\Domain\Notifications\Models\EscalationPolicyStep;
use App\Domain\Notifications\Models\NotificationChannel;
use Carbon\CarbonImmutable;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

/**
 * Fires the due steps of escalation policies (ADR-045). A step fires once
 * the incident has been open for its delay, only while the incident is
 * unacknowledged and its monitor is confirmed DOWN: maintenance, missing
 * data (UNKNOWN) and paused monitors postpone it, acknowledgement and
 * recovery stop the chain.
 *
 * Every incident is re-checked under its row lock and `incidents.escalation_step`
 * only moves forward, so concurrent or replayed runs cannot fire a step twice;
 * the delivery key (incident, channel, event, step) is a second guard.
 */
class EscalateIncidents
{
    public const BATCH = 500;

    public function __construct(
        private readonly QueueIncidentNotifications $queueIncidentNotifications,
        private readonly RecordIncidentEvent $recordIncidentEvent,
    ) {}

    /**
     * @return int number of steps fired
     */
    public function handle(?CarbonImmutable $now = null): int
    {
        $now ??= CarbonImmutable::now();

        $ids = $this->dueIncidents($now)->limit(self::BATCH)->pluck('incidents.id');

        $fired = 0;

        foreach ($ids as $id) {
            $fired += DB::transaction(fn (): int => $this->escalate((string) $id, $now));
        }

        return $fired;
    }

    /**
     * Open, unacknowledged incidents of DOWN monitors whose effective policy
     * has a step past `escalation_step` with an elapsed delay.
     */
    private function dueIncidents(CarbonImmutable $now): Builder
    {
        return DB::table('incidents')
            ->join('monitors', 'monitors.id', '=', 'incidents.monitor_id')
            ->join('monitor_states', 'monitor_states.monitor_id', '=', 'incidents.monitor_id')
            ->where('incidents.status', IncidentStatus::Open->value)
            ->whereNull('incidents.acknowledged_at')
            ->whereNull('monitors.deleted_at')
            ->where('monitor_states.status', MonitorStatus::Down->value)
            ->whereExists(fn (Builder $query) => $query
                ->selectRaw('1')
                ->from('escalation_policy_steps as steps')
                ->join('escalation_policies as policies', 'policies.id', '=', 'steps.policy_id')
                ->whereColumn('policies.organization_id', 'incidents.organization_id')
                ->where(fn (Builder $policy) => $policy
                    ->whereColumn('policies.id', 'monitors.escalation_policy_id')
                    ->orWhere(fn (Builder $fallback) => $fallback
                        ->whereNull('monitors.escalation_policy_id')
                        ->where('policies.is_default', true)))
                ->whereColumn('steps.position', '>', 'incidents.escalation_step')
                ->whereRaw('incidents.started_at <= DATE_SUB(?, INTERVAL steps.delay_minutes MINUTE)', [$now->toDateTimeString()]))
            ->orderBy('incidents.started_at');
    }

    private function escalate(string $incidentId, CarbonImmutable $now): int
    {
        $incident = Incident::query()->lockForUpdate()->find($incidentId);

        if ($incident === null || ! $incident->isOpen() || $incident->acknowledged_at !== null) {
            return 0;
        }

        $monitor = Monitor::query()->find($incident->monitor_id);
        $isDown = MonitorState::query()->whereKey($incident->monitor_id)->where('status', MonitorStatus::Down->value)->exists();

        if ($monitor === null || ! $isDown) {
            return 0;
        }

        $policy = $this->policyFor($monitor);

        if ($policy === null) {
            return 0;
        }

        $elapsedMinutes = (int) floor($incident->started_at->diffInSeconds($now, absolute: false) / 60);

        /** @var list<EscalationPolicyStep> $dueSteps */
        $dueSteps = $policy->steps()
            ->with('channels:id')
            ->where('position', '>', $incident->escalation_step)
            ->where('delay_minutes', '<=', $elapsedMinutes)
            ->get()
            ->all();

        if ($dueSteps === []) {
            return 0;
        }

        foreach ($dueSteps as $step) {
            $deliveries = $this->queueIncidentNotifications->escalate($incident, $step->position, $step->channels->map(fn (NotificationChannel $channel): string => $channel->id)->all());

            $this->recordIncidentEvent->handle($incident, IncidentEventType::Escalated, metadata: [
                'step' => $step->position,
                'delay_minutes' => $step->delay_minutes,
                'policy' => $policy->name,
                'deliveries' => $deliveries,
            ], at: $now);
        }

        $incident->update(['escalation_step' => end($dueSteps)->position]);

        return count($dueSteps);
    }

    /**
     * The monitor's own policy, or the organization default. A policy of
     * another organization is never used (defence in depth).
     */
    private function policyFor(Monitor $monitor): ?EscalationPolicy
    {
        return EscalationPolicy::query()
            ->where('organization_id', $monitor->organization_id)
            ->when(
                $monitor->escalation_policy_id !== null,
                fn ($query) => $query->whereKey($monitor->escalation_policy_id),
                fn ($query) => $query->where('is_default', true),
            )
            ->first();
    }
}
