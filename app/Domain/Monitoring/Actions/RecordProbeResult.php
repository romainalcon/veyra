<?php

namespace App\Domain\Monitoring\Actions;

use App\Domain\Maintenance\Services\MaintenanceSchedule;
use App\Domain\Monitoring\DTOs\ProbeResult;
use App\Domain\Monitoring\Enums\DispatchStatus;
use App\Domain\Monitoring\Jobs\ApplyMonitorStateJob;
use App\Domain\Monitoring\Models\MonitorDispatch;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Persists a probe result exactly once per dispatch (replays are no-ops).
 * Checks scheduled inside a maintenance window are flagged `in_maintenance`.
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

            $inserted = DB::table('monitor_checks')->insertOrIgnore([
                'id' => (string) Str::uuid7(),
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
            ]);

            $locked->update(['status' => DispatchStatus::Completed, 'completed_at' => $checkedAt]);

            if ($inserted === 1) {
                ApplyMonitorStateJob::dispatch($locked->monitor_id)->afterCommit();
            }

            return $inserted === 1;
        });
    }
}
