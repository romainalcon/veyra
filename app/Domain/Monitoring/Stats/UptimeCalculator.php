<?php

namespace App\Domain\Monitoring\Stats;

use App\Domain\Monitoring\Models\Monitor;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Uptime from observed checks with explicit coverage. 24 h uses raw checks;
 * longer ranges use the daily aggregates. Planned maintenance is excluded
 * from both the observed and the expected checks.
 */
class UptimeCalculator
{
    /**
     * @param  Collection<int, Monitor>  $monitors
     * @return array<string, UptimeSummary> keyed by monitor id
     */
    public function forMonitors(Collection $monitors, StatsRange $range, ?CarbonImmutable $now = null): array
    {
        $now ??= CarbonImmutable::now();
        $start = $range->start($now);
        $ids = $monitors->pluck('id')->all();

        if ($ids === []) {
            return [];
        }

        $counts = $range === StatsRange::Day
            ? DB::table('monitor_checks')
                ->selectRaw('monitor_id, SUM(in_maintenance = 0) as checks, SUM(success = 1 AND in_maintenance = 0) as successes, SUM(in_maintenance = 1) as maintenance')
                ->whereIn('monitor_id', $ids)
                ->where('checked_at', '>=', $start)
                ->groupBy('monitor_id')
                ->get()
                ->keyBy('monitor_id')
            : DB::table('monitor_daily_stats')
                ->selectRaw('monitor_id, SUM(checks_count) as checks, SUM(success_count) as successes, SUM(maintenance_count) as maintenance')
                ->whereIn('monitor_id', $ids)
                ->where('day', '>=', $start->toDateString())
                ->groupBy('monitor_id')
                ->get()
                ->keyBy('monitor_id');

        $summaries = [];

        foreach ($monitors as $monitor) {
            $row = $counts->get($monitor->id);
            $activeFrom = $monitor->created_at !== null && $monitor->created_at->greaterThan($start) ? $monitor->created_at : $start;
            $expected = (int) floor(max(0, $activeFrom->diffInSeconds($now, absolute: true)) / max(1, $monitor->interval_seconds));
            $expected = max(0, $expected - (int) ($row->maintenance ?? 0));

            $summaries[$monitor->id] = new UptimeSummary(
                checks: (int) ($row->checks ?? 0),
                successes: (int) ($row->successes ?? 0),
                expected: $expected,
            );
        }

        return $summaries;
    }

    /**
     * Aggregate several monitors into one figure, weighted by checks.
     *
     * @param  array<string, UptimeSummary>  $summaries
     */
    public static function combine(array $summaries): UptimeSummary
    {
        return new UptimeSummary(
            checks: array_sum(array_map(fn (UptimeSummary $summary): int => $summary->checks, $summaries)),
            successes: array_sum(array_map(fn (UptimeSummary $summary): int => $summary->successes, $summaries)),
            expected: array_sum(array_map(fn (UptimeSummary $summary): int => $summary->expected, $summaries)),
        );
    }
}
