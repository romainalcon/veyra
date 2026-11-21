<?php

namespace App\Domain\Monitoring\Actions;

use App\Domain\Monitoring\Enums\DispatchStatus;
use App\Domain\Monitoring\Jobs\ExecuteProbe;
use App\Domain\Monitoring\Models\Monitor;
use App\Domain\Monitoring\Models\MonitorDispatch;
use App\Domain\Monitoring\Support\CheckSchedule;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Relays outbox rows to the Redis `probes` queue.
 *
 * Jobs are pushed inside the transaction: if Redis is unavailable the push
 * throws, the transaction rolls back and the rows stay `pending` for the next
 * run. If the commit fails after a push, the duplicate job is harmless
 * because check persistence is idempotent per dispatch.
 */
class PublishPendingDispatches
{
    public const BATCH_SIZE = 500;

    public const REDELIVER_AFTER_SECONDS = 300;

    public const MAX_ATTEMPTS = 3;

    /**
     * @return array{published: int, skipped: int, failed: int}
     */
    public function handle(?CarbonImmutable $now = null, int $limit = self::BATCH_SIZE): array
    {
        $now ??= CarbonImmutable::now();

        return DB::transaction(function () use ($now, $limit): array {
            $dispatches = MonitorDispatch::query()
                ->where(function ($query) use ($now): void {
                    $query->where('status', DispatchStatus::Pending)
                        ->orWhere(function ($query) use ($now): void {
                            $query->where('status', DispatchStatus::Dispatched)
                                ->where('dispatched_at', '<=', $now->subSeconds(self::REDELIVER_AFTER_SECONDS));
                        });
                })
                ->orderBy('scheduled_for')
                ->limit($limit)
                ->lock('for update skip locked')
                ->get();

            $intervals = Monitor::withTrashed()
                ->whereIn('id', $dispatches->pluck('monitor_id')->unique())
                ->pluck('interval_seconds', 'id');

            $counts = ['published' => 0, 'skipped' => 0, 'failed' => 0];

            foreach ($dispatches as $dispatch) {
                $interval = (int) ($intervals[$dispatch->monitor_id] ?? 0);
                $isStale = $interval === 0
                    || $dispatch->scheduled_for->addSeconds(CheckSchedule::staleAfterSeconds($interval))->lessThan($now);

                if ($isStale) {
                    $dispatch->update(['status' => DispatchStatus::Skipped, 'completed_at' => $now]);
                    $counts['skipped']++;

                    continue;
                }

                if ($dispatch->attempts >= self::MAX_ATTEMPTS) {
                    $dispatch->update(['status' => DispatchStatus::Failed, 'completed_at' => $now]);
                    $counts['failed']++;

                    continue;
                }

                // Mark first, then push: a worker finishing quickly must never have
                // its "completed" status overwritten by this relay.
                $dispatch->update([
                    'status' => DispatchStatus::Dispatched,
                    'attempts' => $dispatch->attempts + 1,
                    'dispatched_at' => $now,
                ]);

                ExecuteProbe::dispatch($dispatch->id);
                $counts['published']++;
            }

            return $counts;
        });
    }
}
