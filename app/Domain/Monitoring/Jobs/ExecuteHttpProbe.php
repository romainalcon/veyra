<?php

namespace App\Domain\Monitoring\Jobs;

use App\Domain\Monitoring\Actions\RecordProbeResult;
use App\Domain\Monitoring\Enums\DispatchStatus;
use App\Domain\Monitoring\Models\MonitorDispatch;
use App\Domain\Monitoring\Probes\HttpProbe;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Executes one outbox dispatch. Bounded (single try, hard timeout) and
 * idempotent: a redelivered or duplicated job records nothing twice. A lost
 * job is redelivered by PublishPendingDispatches.
 */
class ExecuteHttpProbe implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout = 45;

    public bool $failOnTimeout = true;

    public function __construct(public readonly string $dispatchId)
    {
        $this->onQueue('probes');
    }

    public function handle(HttpProbe $probe, RecordProbeResult $recordProbeResult): void
    {
        $dispatch = MonitorDispatch::query()->with('monitor.httpConfig')->find($this->dispatchId);

        if ($dispatch === null || in_array($dispatch->status, [DispatchStatus::Completed, DispatchStatus::Skipped, DispatchStatus::Failed], true)) {
            return;
        }

        $monitor = $dispatch->monitor;

        if ($monitor === null || $monitor->trashed() || ! $monitor->enabled || $monitor->httpConfig === null) {
            $dispatch->update(['status' => DispatchStatus::Skipped, 'completed_at' => now()]);

            return;
        }

        $result = $probe->run($monitor->httpConfig, $monitor->timeout_seconds);

        $recordProbeResult->handle($dispatch, $result);
    }

    /**
     * @return list<string>
     */
    public function tags(): array
    {
        return ['dispatch:'.$this->dispatchId];
    }
}
