<?php

namespace App\Domain\Monitoring\Jobs;

use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\DB;

/**
 * Deletes history rows of hard-deleted monitors in bounded batches, so a
 * large organization deletion never runs one unbounded DELETE.
 */
class PurgeMonitorHistory implements ShouldQueue
{
    use Queueable;

    public const BATCH_SIZE = 1000;

    public const MAX_BATCHES_PER_RUN = 50;

    public int $tries = 3;

    public int $timeout = 110;

    /**
     * @param  list<string>  $monitorIds
     */
    public function __construct(public readonly array $monitorIds)
    {
        $this->onQueue('maintenance');
    }

    public function handle(): void
    {
        $batches = 0;

        foreach (['monitor_checks', 'monitor_dispatches'] as $table) {
            do {
                $deleted = DB::table($table)
                    ->whereIn('monitor_id', $this->monitorIds)
                    ->limit(self::BATCH_SIZE)
                    ->delete();

                if (++$batches >= self::MAX_BATCHES_PER_RUN && $deleted > 0) {
                    self::dispatch($this->monitorIds);

                    return;
                }
            } while ($deleted === self::BATCH_SIZE);
        }
    }
}
