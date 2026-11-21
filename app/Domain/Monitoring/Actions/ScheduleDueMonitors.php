<?php

namespace App\Domain\Monitoring\Actions;

use App\Domain\Monitoring\Enums\DispatchStatus;
use App\Domain\Monitoring\Enums\DispatchTrigger;
use App\Domain\Monitoring\Models\Monitor;
use App\Domain\Monitoring\Support\CheckSchedule;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Claims due monitors and writes one outbox row per scheduled slot, in the
 * same transaction that advances `next_check_at`.
 *
 * `FOR UPDATE SKIP LOCKED` (MariaDB 10.6+) lets concurrent schedulers claim
 * disjoint batches; the unique (monitor_id, scheduled_for) index makes a
 * replay harmless.
 */
class ScheduleDueMonitors
{
    public const BATCH_SIZE = 500;

    public function handle(?CarbonImmutable $now = null, int $limit = self::BATCH_SIZE): int
    {
        $now ??= CarbonImmutable::now();

        return DB::transaction(function () use ($now, $limit): int {
            $monitors = Monitor::query()
                ->select(['id', 'interval_seconds', 'next_check_at'])
                ->where('enabled', true)
                ->whereNotNull('next_check_at')
                ->where('next_check_at', '<=', $now)
                ->orderBy('next_check_at')
                ->limit($limit)
                ->lock('for update skip locked')
                ->get();

            if ($monitors->isEmpty()) {
                return 0;
            }

            $timestamp = $now->toDateTimeString();
            $rows = [];

            foreach ($monitors as $monitor) {
                /** @var CarbonImmutable $scheduledFor */
                $scheduledFor = $monitor->next_check_at;

                $rows[] = [
                    'id' => (string) Str::uuid7(),
                    'monitor_id' => $monitor->id,
                    'scheduled_for' => $scheduledFor->toDateTimeString(),
                    'trigger' => DispatchTrigger::Scheduled->value,
                    'status' => DispatchStatus::Pending->value,
                    'attempts' => 0,
                    'created_at' => $timestamp,
                    'updated_at' => $timestamp,
                ];

                Monitor::query()->whereKey($monitor->id)->update([
                    'next_check_at' => CheckSchedule::next($scheduledFor, $monitor->interval_seconds, $now),
                ]);
            }

            DB::table('monitor_dispatches')->insertOrIgnore($rows);

            return count($rows);
        });
    }
}
