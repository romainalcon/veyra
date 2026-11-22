<?php

namespace App\Domain\Monitoring\Jobs;

use App\Domain\Entitlements\Services\Quota;
use App\Domain\Monitoring\Enums\DispatchStatus;
use App\Domain\Organizations\Models\Organization;
use App\Domain\System\SchedulerBeatLog;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Database\Query\Builder;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\DB;

/**
 * Bounded retention cleanup. Raw checks older than their organization's
 * retention period (ADR-029, instance default otherwise) are deleted per monitor in batches (index (monitor_id, checked_at)); daily
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
        $quota = app(Quota::class);

        /** @var array<string, CarbonImmutable> $cutoffs */
        $cutoffs = [];

        $monitors = DB::table('monitors')
            ->when($this->afterMonitorId, fn ($query, $after) => $query->where('id', '>', $after))
            ->orderBy('id')
            ->get(['id', 'organization_id']);

        foreach ($monitors as $monitor) {
            $monitorId = (string) $monitor->id;
            $organizationId = (string) $monitor->organization_id;
            $checksCutoff = $cutoffs[$organizationId] ??= $now->subDays($this->retentionDays($quota, $organizationId));

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

        // Status page audience (ADR-036): unconfirmed subscriptions and old announcements.
        $this->purgeInBatches(fn () => DB::table('status_page_subscribers')
            ->whereNull('confirmed_at')
            ->where('created_at', '<', $now->subDays((int) config('veyra.status_pages.unconfirmed_retention_days', 7))), $startedAt);

        $announcementCutoff = $now->subDays((int) config('veyra.status_pages.announcement_retention_days', 30));

        $this->purgeInBatches(fn () => DB::table('status_page_subscriber_deliveries')
            ->where('created_at', '<', $announcementCutoff), $startedAt);

        $this->purgeInBatches(fn () => DB::table('status_page_announcements')
            ->where('created_at', '<', $announcementCutoff), $startedAt);

        do {
            $deleted = SchedulerBeatLog::purge($now, self::BATCH_SIZE);
        } while ($deleted === self::BATCH_SIZE && ! $this->outOfTime($startedAt));
    }

    private function retentionDays(Quota $quota, string $organizationId): int
    {
        $organization = Organization::query()->find($organizationId);

        return $organization === null
            ? max(1, (int) config('veyra.monitoring.check_retention_days', 30))
            : $quota->checkRetentionDays($organization);
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
