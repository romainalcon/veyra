<?php

use App\Domain\Monitoring\Actions\ApplyMonitorState;
use App\Domain\Monitoring\Models\Monitor;
use App\Domain\Monitoring\Models\MonitorCheck;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

/*
 * The monitor_states row lock serializes concurrent evaluations, which is what
 * guarantees a single active incident per monitor.
 */
test('state evaluation holds the monitor state row lock', function () {
    $monitor = Monitor::factory()->create();
    MonitorCheck::factory()->create(['monitor_id' => $monitor->id, 'success' => false, 'scheduled_for' => now()->subMinute()]);

    config(['database.connections.concurrent' => config('database.connections.mysql')]);
    DB::purge('concurrent');
    $other = DB::connection('concurrent');
    $other->statement('SET SESSION innodb_lock_wait_timeout = 1');

    DB::transaction(function () use ($monitor, $other) {
        app(ApplyMonitorState::class)->handle($monitor->id);

        expect(fn () => $other->transaction(fn () => $other->table('monitor_states')
            ->where('monitor_id', $monitor->id)
            ->lockForUpdate()
            ->first()))->toThrow(QueryException::class);
    });

    DB::disconnect('concurrent');
});

test('repeated evaluations of three failures open exactly one incident', function () {
    $monitor = Monitor::factory()->create();

    foreach ([3, 2, 1] as $minutesAgo) {
        MonitorCheck::factory()->create([
            'monitor_id' => $monitor->id,
            'success' => false,
            'scheduled_for' => now()->subMinutes($minutesAgo)->startOfSecond(),
        ]);
        app(ApplyMonitorState::class)->handle($monitor->id);
    }

    app(ApplyMonitorState::class)->handle($monitor->id);

    expect(DB::table('incidents')->where('monitor_id', $monitor->id)->count())->toBe(1);
});
