<?php

namespace App\Domain\Monitoring\Jobs;

use App\Domain\Monitoring\Actions\RecordProbeResult;
use App\Domain\Monitoring\Certificates\RecordTlsCertificate;
use App\Domain\Monitoring\Enums\DispatchStatus;
use App\Domain\Monitoring\Models\MonitorCheck;
use App\Domain\Monitoring\Models\MonitorDispatch;
use App\Domain\Monitoring\Models\MonitorDnsConfig;
use App\Domain\Monitoring\Probes\ProbeRegistry;
use App\Domain\System\Metrics\MetricsRecorder;
use Carbon\CarbonImmutable;
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

    public function handle(ProbeRegistry $probes, RecordProbeResult $recordProbeResult, RecordTlsCertificate $recordTlsCertificate, MetricsRecorder $metrics): void
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

        if ($monitor === null || $probe === null || $monitor->trashed() || ! $monitor->enabled || ! $probe->canProbe($monitor)) {
            $dispatch->update(['status' => DispatchStatus::Skipped, 'completed_at' => now()]);

            return;
        }

        $result = $probe->probe($monitor, $dispatch->scheduled_for);

        if ($result === null) {
            $dispatch->update(['status' => DispatchStatus::Skipped, 'completed_at' => now()]);

            return;
        }

        if ($recordProbeResult->handle($dispatch, $result)) {
            $metrics->recordProbe($monitor->type, $result->success, $result->durationMs);
        }

        if ($result->certificate !== null) {
            $recordTlsCertificate->handle($monitor->id, $result->certificate);
        }

        if ($result->dnsAnswers !== null) {
            $this->recordDnsAnswers($monitor->id, $result->dnsAnswers, $dispatch->scheduled_for);
        }
    }

    /**
     * Keeps the answers of the newest slot only: a late result for an older
     * slot never overwrites them.
     *
     * @param  list<string>  $answers
     */
    private function recordDnsAnswers(string $monitorId, array $answers, CarbonImmutable $scheduledFor): void
    {
        MonitorDnsConfig::query()
            ->where('monitor_id', $monitorId)
            ->where(fn ($query) => $query->whereNull('last_resolved_at')->orWhere('last_resolved_at', '<=', $scheduledFor))
            ->update(['last_answers' => json_encode($answers), 'last_resolved_at' => $scheduledFor]);
    }

    /**
     * @return list<string>
     */
    public function tags(): array
    {
        return ['dispatch:'.$this->dispatchId];
    }
}
