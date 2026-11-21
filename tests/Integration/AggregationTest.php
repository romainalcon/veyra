<?php

use App\Domain\Monitoring\Jobs\PurgeExpiredHistory;
use App\Domain\Monitoring\Models\Monitor;
use App\Domain\Monitoring\Stats\AggregateDailyStats;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/*
 * MariaDB-specific SQL (INSERT … SELECT … ON DUPLICATE KEY UPDATE,
 * DELETE … LIMIT) on a realistic volume.
 */
test('aggregation and batched purge handle 10 000 checks', function () {
    $monitor = Monitor::factory()->create(['interval_seconds' => 60, 'created_at' => '2026-01-01 00:00:00']);
    $day = CarbonImmutable::parse('2026-11-20');

    foreach (array_chunk(range(0, 9999), 1000) as $chunk) {
        DB::table('monitor_checks')->insert(array_map(fn (int $i) => [
            'id' => (string) Str::uuid7(),
            'monitor_id' => $monitor->id,
            'dispatch_id' => (string) Str::uuid7(),
            'scheduled_for' => $day->addSeconds($i * 8)->toDateTimeString(),
            'checked_at' => $day->addSeconds($i * 8 + 1)->toDateTimeString(),
            'success' => $i % 50 !== 0,
            'duration_ms' => 100,
            'status_code' => 200,
            'created_at' => $day->toDateTimeString(),
        ], $chunk));
    }

    $started = microtime(true);
    app(AggregateDailyStats::class)->handle($day, CarbonImmutable::parse('2026-11-22'));
    $elapsed = microtime(true) - $started;

    $stat = DB::table('monitor_daily_stats')->where('monitor_id', $monitor->id)->first();
    expect((int) $stat->checks_count)->toBe(10000)
        ->and((int) $stat->failure_count)->toBe(200)
        ->and($elapsed)->toBeLessThan(5.0);

    config(['veyra.monitoring.check_retention_days' => 1]);
    $this->travelTo('2026-11-23 00:00:00');
    (new PurgeExpiredHistory)->handle();

    expect(DB::table('monitor_checks')->count())->toBe(0)
        ->and(DB::table('monitor_daily_stats')->count())->toBe(1);
});
