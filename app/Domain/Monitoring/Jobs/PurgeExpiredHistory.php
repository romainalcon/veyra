<?php

namespace App\Domain\Monitoring\Jobs;

use App\Domain\Monitoring\Enums\DispatchStatus;
use App\Domain\System\SchedulerBeatLog;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Database\Query\Builder;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\DB;

/**
 * Bounded retention cleanup. Raw checks older than the retention period are
 * deleted per monitor in batches (index (monitor_id, checked_at)); daily
 * aggregates are kept. The job re-dispatches itself when its time budget is
 * exhausted instead of running one unbounded DELETE.
 */
class PurgeExpiredHistory implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public const BATCH_SIZE = 1000;

    public const TIME_BUDGET_SECONDS = 90;

    public const DISPATCH_RETENTION_DAYS = 7;

    public const DELIVERY_RETENTION_DAYS = 90;

    public int $tries = 3;

    public int $timeout = 110;

    public int $uniqueFor = 3600;

    public function __construct(public readonly ?string $afterMonitorId = null)
    {
        $this->onQueue('maintenance');
    }

    public function handle(): void
    {
        $startedAt = microtime(true);
        $now = CarbonImmutable::now();
        $checksCutoff = $now->subDays((int) config('veyra.monitoring.check_retention_days', 30));

        $monitorIds = DB::table('monitors')
            ->when($this->afterMonitorId, fn ($query, $after) => $query->where('id', '>', $after))
            ->orderBy('id')
            ->pluck('id');

        foreach ($monitorIds as $monitorId) {
            do {
                $deleted = DB::table('monitor_checks')
                    ->where('monitor_id', $monitorId)
                    ->where('checked_at', '<', $checksCutoff)
                    ->limit(self::BATCH_SIZE)
                    ->delete();

                if ($this->outOfTime($startedAt)) {
                    self::dispatch((string) $monitorId)->delay(5);

                    return;
                }
            } while ($deleted === self::BATCH_SIZE);
        }

        $this->purgeInBatches(fn () => DB::table('monitor_dispatches')
            ->whereIn('status', [DispatchStatus::Completed->value, DispatchStatus::Skipped->value, DispatchStatus::Failed->value])
            ->where('completed_at', '<', $now->subDays(self::DISPATCH_RETENTION_DAYS)), $startedAt);

        $this->purgeInBatches(fn () => DB::table('notification_deliveries')
            ->where('created_at', '<', $now->subDays(self::DELIVERY_RETENTION_DAYS)), $startedAt);

        // Alerts outlive their deliveries (cascade) only as long as the deliveries are kept.
        $this->purgeInBatches(fn () => DB::table('tls_certificate_alerts')
            ->where('created_at', '<', $now->subDays(self::DELIVERY_RETENTION_DAYS)), $startedAt);

        do {
            $deleted = SchedulerBeatLog::purge($now, self::BATCH_SIZE);
        } while ($deleted === self::BATCH_SIZE && ! $this->outOfTime($startedAt));
    }

    /**
     * @param  callable(): Builder  $query
     */
    private function purgeInBatches(callable $query, float $startedAt): void
    {
        do {
            $deleted = $query()->limit(self::BATCH_SIZE)->delete();
        } while ($deleted === self::BATCH_SIZE && ! $this->outOfTime($startedAt));
    }

    private function outOfTime(float $startedAt): bool
    {
        return microtime(true) - $startedAt > self::TIME_BUDGET_SECONDS;
    }

    public function uniqueId(): string
    {
        return 'purge:'.($this->afterMonitorId ?? 'start');
    }
}
