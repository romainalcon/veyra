<?php

namespace App\Domain\Monitoring\Stats;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Recomputes the daily aggregates of one UTC day from raw checks. Idempotent:
 * rows are upserted (INSERT … SELECT … ON DUPLICATE KEY UPDATE), so it can run
 * repeatedly for "today" and "yesterday". Checks flagged `in_maintenance` are
 * counted apart and removed from the expected checks: planned maintenance is
 * neither uptime nor downtime.
 */
class AggregateDailyStats
{
    public const MONITOR_BATCH = 500;

    public function handle(CarbonImmutable $day, ?CarbonImmutable $now = null): int
    {
        $now ??= CarbonImmutable::now();
        $dayStart = $day->startOfDay();
        $dayEnd = $dayStart->addDay();
        $windowEnd = $now->lessThan($dayEnd) ? $now : $dayEnd;
        $rows = 0;

        DB::table('monitors')->orderBy('id')->select('id')->chunk(self::MONITOR_BATCH, function ($monitors) use ($dayStart, $dayEnd, $windowEnd, &$rows): void {
            $ids = $monitors->pluck('id')->all();
            $placeholders = implode(',', array_fill(0, count($ids), '?'));

            $rows += DB::affectingStatement(<<<SQL
                INSERT INTO monitor_daily_stats
                    (monitor_id, day, checks_count, success_count, failure_count, maintenance_count, latency_avg_ms, latency_max_ms, dns_avg_ms, connect_avg_ms, tls_avg_ms, ttfb_avg_ms, transfer_avg_ms, expected_checks, updated_at)
                SELECT
                    c.monitor_id,
                    ?,
                    SUM(c.in_maintenance = 0),
                    SUM(c.success = 1 AND c.in_maintenance = 0),
                    SUM(c.success = 0 AND c.in_maintenance = 0),
                    SUM(c.in_maintenance = 1),
                    ROUND(AVG(CASE WHEN c.success = 1 AND c.in_maintenance = 0 THEN c.duration_ms END)),
                    MAX(CASE WHEN c.success = 1 AND c.in_maintenance = 0 THEN c.duration_ms END),
                    ROUND(AVG(CASE WHEN c.success = 1 AND c.in_maintenance = 0 THEN c.dns_ms END)),
                    ROUND(AVG(CASE WHEN c.success = 1 AND c.in_maintenance = 0 THEN c.connect_ms END)),
                    ROUND(AVG(CASE WHEN c.success = 1 AND c.in_maintenance = 0 THEN c.tls_ms END)),
                    ROUND(AVG(CASE WHEN c.success = 1 AND c.in_maintenance = 0 THEN c.ttfb_ms END)),
                    ROUND(AVG(CASE WHEN c.success = 1 AND c.in_maintenance = 0 THEN c.transfer_ms END)),
                    GREATEST(0, FLOOR(TIMESTAMPDIFF(SECOND, GREATEST(?, m.created_at), ?) / m.interval_seconds) - SUM(c.in_maintenance = 1)),
                    UTC_TIMESTAMP()
                FROM monitor_checks c
                JOIN monitors m ON m.id = c.monitor_id
                WHERE c.monitor_id IN ({$placeholders})
                  AND c.checked_at >= ?
                  AND c.checked_at < ?
                GROUP BY c.monitor_id, m.created_at, m.interval_seconds
                ON DUPLICATE KEY UPDATE
                    checks_count = VALUES(checks_count),
                    success_count = VALUES(success_count),
                    failure_count = VALUES(failure_count),
                    maintenance_count = VALUES(maintenance_count),
                    latency_avg_ms = VALUES(latency_avg_ms),
                    latency_max_ms = VALUES(latency_max_ms),
                    dns_avg_ms = VALUES(dns_avg_ms),
                    connect_avg_ms = VALUES(connect_avg_ms),
                    tls_avg_ms = VALUES(tls_avg_ms),
                    ttfb_avg_ms = VALUES(ttfb_avg_ms),
                    transfer_avg_ms = VALUES(transfer_avg_ms),
                    expected_checks = VALUES(expected_checks),
                    updated_at = VALUES(updated_at)
                SQL, [
                $dayStart->toDateString(),
                $dayStart->toDateTimeString(),
                $windowEnd->toDateTimeString(),
                ...$ids,
                $dayStart->toDateTimeString(),
                $dayEnd->toDateTimeString(),
            ]);
        });

        return $rows;
    }
}
