<?php

namespace App\Domain\Monitoring\Actions;

use App\Domain\Monitoring\Enums\DispatchStatus;
use App\Domain\Monitoring\Enums\DispatchTrigger;
use App\Domain\Monitoring\Jobs\ExecuteHttpProbe;
use App\Domain\Monitoring\Models\Monitor;
use App\Domain\Monitoring\Models\MonitorDispatch;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Throwable;

/**
 * Queues an immediate check through the same outbox as scheduled checks.
 */
class RequestManualCheck
{
    /**
     * @return bool false when a check for this second is already queued
     */
    public function handle(Monitor $monitor): bool
    {
        $now = now()->startOfSecond();
        $id = (string) Str::uuid7();

        $inserted = DB::table('monitor_dispatches')->insertOrIgnore([
            'id' => $id,
            'monitor_id' => $monitor->id,
            'scheduled_for' => $now->toDateTimeString(),
            'trigger' => DispatchTrigger::Manual->value,
            'status' => DispatchStatus::Pending->value,
            'attempts' => 0,
            'created_at' => $now->toDateTimeString(),
            'updated_at' => $now->toDateTimeString(),
        ]);

        if ($inserted === 0) {
            return false;
        }

        // Mark first, then push, so a fast worker's "completed" status is never overwritten.
        MonitorDispatch::query()->whereKey($id)->update([
            'status' => DispatchStatus::Dispatched,
            'attempts' => 1,
            'dispatched_at' => now(),
        ]);

        try {
            ExecuteHttpProbe::dispatch($id);
        } catch (Throwable $exception) {
            // Back to pending: the scheduler's relay will publish it.
            MonitorDispatch::query()->whereKey($id)->where('status', DispatchStatus::Dispatched)->update([
                'status' => DispatchStatus::Pending,
                'attempts' => 0,
                'dispatched_at' => null,
            ]);
            Log::warning('veyra.manual_check.publish_failed', ['dispatch_id' => $id, 'exception' => $exception::class]);
        }

        return true;
    }
}
