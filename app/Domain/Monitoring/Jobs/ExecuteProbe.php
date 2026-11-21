<?php

namespace App\Domain\Monitoring\Jobs;

use App\Domain\Monitoring\Actions\RecordProbeResult;
use App\Domain\Monitoring\Enums\DispatchStatus;
use App\Domain\Monitoring\Models\MonitorCheck;
use App\Domain\Monitoring\Models\MonitorDispatch;
use App\Domain\Monitoring\Probes\ProbeRegistry;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Executes one outbox dispatch with the probe of the monitor's type. Bounded
 * (single try, hard timeout) and idempotent: a redelivered or duplicated job
 * records nothing twice. A lost job is redelivered by PublishPendingDispatches.
 */
class ExecuteProbe implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout = 45;

    public bool $failOnTimeout = true;

    public function __construct(public readonly string $dispatchId)
    {
        $this->onQueue('probes');
    }

    public function handle(ProbeRegistry $probes, RecordProbeResult $recordProbeResult): void
    {
        $dispatch = MonitorDispatch::query()->with('monitor')->find($this->dispatchId);

        if ($dispatch === null || in_array($dispatch->status, [DispatchStatus::Completed, DispatchStatus::Skipped, DispatchStatus::Failed], true)) {
            return;
        }

        // A redelivered job whose result is already stored must not probe again.
        if (MonitorCheck::query()->where('dispatch_id', $dispatch->id)->exists()) {
            $dispatch->update(['status' => DispatchStatus::Completed, 'completed_at' => $dispatch->completed_at ?? now()]);

            return;
        }

        $monitor = $dispatch->monitor;
        $probe = $monitor === null ? null : $probes->for($monitor->type);

        if ($monitor === null || $monitor->trashed() || ! $monitor->enabled || $probe === null || ! $probe->canProbe($monitor)) {
            $dispatch->update(['status' => DispatchStatus::Skipped, 'completed_at' => now()]);

            return;
        }

        $recordProbeResult->handle($dispatch, $probe->probe($monitor));
    }

    /**
     * @return list<string>
     */
    public function tags(): array
    {
        return ['dispatch:'.$this->dispatchId];
    }
}
