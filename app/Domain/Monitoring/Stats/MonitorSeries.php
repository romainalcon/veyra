<?php

namespace App\Domain\Monitoring\Stats;

use App\Domain\Monitoring\Models\Monitor;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Bounded, downsampled series for charts. Empty buckets are explicit
 * (`checks = 0`, null values) so the UI can show "no data". `maintenance`
 * counts checks run during planned maintenance (excluded from the others).
 * `phases` holds the average of each timing phase over successful checks
 * (ADR-050), null where no check of the bucket measured it.
 */
class MonitorSeries
{
    /**
     * Timing phases charted per bucket (redirects are not a phase of the final hop).
     */
    public const PHASES = ['dns', 'connect', 'tls', 'ttfb', 'transfer'];

    /**
     * @return list<array{t: string, checks: int, successes: int, failures: int, maintenance: int, latency_avg: int|null, latency_max: int|null, phases: array{dns: int|null, connect: int|null, tls: int|null, ttfb: int|null, transfer: int|null}}>
     */
    public function latency(Monitor $monitor, StatsRange $range, ?CarbonImmutable $now = null): array
    {
        $now ??= CarbonImmutable::now();
        $bucket = $range->bucketSeconds();

        return $bucket === null
            ? $this->daily($monitor, $range, $now)
            : $this->buckets($monitor, $range, $bucket, $now);
    }

    /**
     * One entry per day (UTC) with uptime and coverage, for the uptime bars.
     *
     * @return list<array{day: string, uptime: float|null, coverage: float|null, checks: int}>
     */
    public function uptimeDays(Monitor $monitor, int $days = 90, ?CarbonImmutable $now = null): array
    {
        $now ??= CarbonImmutable::now();
        $first = $now->startOfDay()->subDays($days - 1);

        $stats = DB::table('monitor_daily_stats')
            ->where('monitor_id', $monitor->id)
            ->where('day', '>=', $first->toDateString())
            ->get(['day', 'checks_count', 'success_count', 'expected_checks'])
            ->keyBy(fn ($row) => substr((string) $row->day, 0, 10));

        $series = [];

        for ($day = $first; $day->lessThanOrEqualTo($now); $day = $day->addDay()) {
            $row = $stats->get($day->toDateString());
            $summary = new UptimeSummary((int) ($row->checks_count ?? 0), (int) ($row->success_count ?? 0), (int) ($row->expected_checks ?? 0));

            $series[] = [
                'day' => $day->toDateString(),
                'uptime' => $summary->uptime(),
                'coverage' => $summary->coverage(),
                'checks' => $summary->checks,
            ];
        }

        return $series;
    }

    /**
     * @return list<array{t: string, checks: int, successes: int, failures: int, maintenance: int, latency_avg: int|null, latency_max: int|null, phases: array{dns: int|null, connect: int|null, tls: int|null, ttfb: int|null, transfer: int|null}}>
     */
    private function buckets(Monitor $monitor, StatsRange $range, int $bucket, CarbonImmutable $now): array
    {
        $end = CarbonImmutable::createFromTimestampUTC(intdiv($now->getTimestamp(), $bucket) * $bucket);
        $start = $end->subSeconds($bucket * ($range === StatsRange::Day ? 95 : 167));

        $rows = DB::table('monitor_checks')
            ->selectRaw('FLOOR(UNIX_TIMESTAMP(checked_at) / ?) * ? as bucket', [$bucket, $bucket])
            ->selectRaw('SUM(in_maintenance = 0) as checks, SUM(success = 1 AND in_maintenance = 0) as successes, SUM(in_maintenance = 1) as maintenance')
            ->selectRaw('ROUND(AVG(CASE WHEN success = 1 AND in_maintenance = 0 THEN duration_ms END)) as latency_avg')
            ->selectRaw('MAX(CASE WHEN success = 1 AND in_maintenance = 0 THEN duration_ms END) as latency_max')
            ->selectRaw(implode(', ', array_map(
                fn (string $phase): string => "ROUND(AVG(CASE WHEN success = 1 AND in_maintenance = 0 THEN {$phase}_ms END)) as {$phase}_avg",
                self::PHASES,
            )))
            ->where('monitor_id', $monitor->id)
            ->where('checked_at', '>=', $start)
            ->groupBy('bucket')
            ->get()
            ->keyBy(fn ($row) => (int) $row->bucket);

        $series = [];

        for ($at = $start; $at->lessThanOrEqualTo($end); $at = $at->addSeconds($bucket)) {
            $series[] = $this->point($at, $rows->get($at->getTimestamp()));
        }

        return $series;
    }

    /**
     * @return list<array{t: string, checks: int, successes: int, failures: int, maintenance: int, latency_avg: int|null, latency_max: int|null, phases: array{dns: int|null, connect: int|null, tls: int|null, ttfb: int|null, transfer: int|null}}>
     */
    private function daily(Monitor $monitor, StatsRange $range, CarbonImmutable $now): array
    {
        $first = $range->start($now)->startOfDay();

        $rows = DB::table('monitor_daily_stats')
            ->where('monitor_id', $monitor->id)
            ->where('day', '>=', $first->toDateString())
            ->get()
            ->keyBy(fn ($row) => substr((string) $row->day, 0, 10));

        $series = [];

        for ($day = $first; $day->lessThanOrEqualTo($now); $day = $day->addDay()) {
            $row = $rows->get($day->toDateString());
            $series[] = $this->point($day, $row === null ? null : (object) [
                'checks' => $row->checks_count,
                'successes' => $row->success_count,
                'maintenance' => $row->maintenance_count,
                'latency_avg' => $row->latency_avg_ms,
                'latency_max' => $row->latency_max_ms,
                'dns_avg' => $row->dns_avg_ms,
                'connect_avg' => $row->connect_avg_ms,
                'tls_avg' => $row->tls_avg_ms,
                'ttfb_avg' => $row->ttfb_avg_ms,
                'transfer_avg' => $row->transfer_avg_ms,
            ]);
        }

        return $series;
    }

    /**
     * @return array{t: string, checks: int, successes: int, failures: int, maintenance: int, latency_avg: int|null, latency_max: int|null, phases: array{dns: int|null, connect: int|null, tls: int|null, ttfb: int|null, transfer: int|null}}
     */
    private function point(CarbonImmutable $at, ?object $row): array
    {
        $checks = (int) ($row->checks ?? 0);
        $successes = (int) ($row->successes ?? 0);

        return [
            't' => $at->toIso8601String(),
            'checks' => $checks,
            'successes' => $successes,
            'failures' => $checks - $successes,
            'maintenance' => (int) ($row->maintenance ?? 0),
            'latency_avg' => isset($row->latency_avg) ? (int) $row->latency_avg : null,
            'latency_max' => isset($row->latency_max) ? (int) $row->latency_max : null,
            'phases' => [
                'dns' => isset($row->dns_avg) ? (int) $row->dns_avg : null,
                'connect' => isset($row->connect_avg) ? (int) $row->connect_avg : null,
                'tls' => isset($row->tls_avg) ? (int) $row->tls_avg : null,
                'ttfb' => isset($row->ttfb_avg) ? (int) $row->ttfb_avg : null,
                'transfer' => isset($row->transfer_avg) ? (int) $row->transfer_avg : null,
            ],
        ];
    }
}
