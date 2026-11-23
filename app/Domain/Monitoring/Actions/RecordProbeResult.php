<?php

namespace App\Domain\Monitoring\Actions;

use App\Domain\Maintenance\Services\MaintenanceSchedule;
use App\Domain\Monitoring\DTOs\CheckTimings;
use App\Domain\Monitoring\DTOs\ProbeResult;
use App\Domain\Monitoring\Enums\DispatchStatus;
use App\Domain\Monitoring\Jobs\ApplyMonitorStateJob;
use App\Domain\Monitoring\Models\MonitorDispatch;
use App\Domain\Monitoring\Probes\FailureEvidence;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Persists a probe result exactly once per dispatch (replays are no-ops).
 * Checks scheduled inside a maintenance window are flagged `in_maintenance`.
 * Phase timings are stored on the check; failure evidence is stored next to
 * it only while the monitor has no active incident, so a long outage does
 * not write one row per check (ADR-050).
 */
class RecordProbeResult
{
    public function __construct(private readonly MaintenanceSchedule $maintenanceSchedule) {}

    public function handle(MonitorDispatch $dispatch, ProbeResult $result, ?CarbonImmutable $checkedAt = null): bool
    {
        $checkedAt ??= CarbonImmutable::now();

        return DB::transaction(function () use ($dispatch, $result, $checkedAt): bool {
            $locked = MonitorDispatch::query()->lockForUpdate()->find($dispatch->id);

            if ($locked === null || $locked->status === DispatchStatus::Completed) {
                return false;
            }

            $checkId = (string) Str::uuid7();
            $inserted = DB::table('monitor_checks')->insertOrIgnore([
                'id' => $checkId,
                'monitor_id' => $locked->monitor_id,
                'dispatch_id' => $locked->id,
                'scheduled_for' => $locked->scheduled_for->toDateTimeString(),
                'checked_at' => $checkedAt->toDateTimeString(),
                'success' => $result->success,
                'slow' => $result->slow,
                'duration_ms' => $result->durationMs,
                'status_code' => $result->statusCode,
                'error_code' => $result->errorCode?->value,
                'metadata' => $result->metadata === [] ? null : json_encode($result->metadata),
                'in_maintenance' => $this->maintenanceSchedule->covers($locked->monitor_id, $locked->scheduled_for),
                'created_at' => $checkedAt->toDateTimeString(),
                ...($result->timings ?? new CheckTimings)->toColumns(),
            ]);

            if ($inserted === 1 && $result->evidence !== null) {
                $this->recordEvidence($locked->monitor_id, $checkId, $result->evidence, $checkedAt);
            }

            $locked->update(['status' => DispatchStatus::Completed, 'completed_at' => $checkedAt]);

            if ($inserted === 1) {
                ApplyMonitorStateJob::dispatch($locked->monitor_id)->afterCommit();
            }

            return $inserted === 1;
        });
    }

    private function recordEvidence(string $monitorId, string $checkId, FailureEvidence $evidence, CarbonImmutable $checkedAt): void
    {
        $monitor = DB::table('monitors')
            ->leftJoin('monitor_states', 'monitor_states.monitor_id', '=', 'monitors.id')
            ->where('monitors.id', $monitorId)
            ->first(['monitors.organization_id', 'monitor_states.active_incident_id']);

        if ($monitor === null || $monitor->active_incident_id !== null) {
            return;
        }

        DB::table('check_evidence')->insertOrIgnore([
            'id' => (string) Str::uuid7(),
            'organization_id' => $monitor->organization_id,
            'monitor_id' => $monitorId,
            'check_id' => $checkId,
            'incident_id' => null,
            'status_code' => $evidence->statusCode,
            'http_version' => $evidence->httpVersion,
            'error_code' => $evidence->errorCode->value,
            'content_type' => $evidence->contentType,
            'headers' => json_encode((object) $evidence->headers, JSON_THROW_ON_ERROR),
            'body_excerpt' => $evidence->bodyExcerpt,
            'body_truncated' => $evidence->bodyTruncated,
            'body_omitted_reason' => $evidence->bodyOmittedReason,
            'checked_at' => $checkedAt->toDateTimeString(),
            'created_at' => $checkedAt->toDateTimeString(),
        ]);
    }
}
