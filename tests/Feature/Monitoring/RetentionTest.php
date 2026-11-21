<?php

use App\Domain\Monitoring\Enums\DispatchStatus;
use App\Domain\Monitoring\Jobs\AggregateDailyStatsJob;
use App\Domain\Monitoring\Jobs\PurgeExpiredHistory;
use App\Domain\Monitoring\Models\Monitor;
use App\Domain\Monitoring\Models\MonitorCheck;
use App\Domain\Monitoring\Models\MonitorDailyStat;
use App\Domain\Monitoring\Models\MonitorDispatch;

beforeEach(function () {
    $this->travelTo('2026-11-22 03:15:00');
    $this->monitor = Monitor::factory()->create();
});

test('raw checks past the retention period are purged and aggregates kept', function () {
    MonitorCheck::factory()->count(3)->create(['monitor_id' => $this->monitor->id, 'checked_at' => now()->subDays(31)]);
    MonitorCheck::factory()->count(2)->create(['monitor_id' => $this->monitor->id, 'checked_at' => now()->subDays(29)]);
    MonitorDailyStat::create(['monitor_id' => $this->monitor->id, 'day' => now()->subDays(31)->toDateString(), 'checks_count' => 3, 'success_count' => 3]);

    (new PurgeExpiredHistory)->handle();

    expect(MonitorCheck::count())->toBe(2)
        ->and(MonitorDailyStat::count())->toBe(1);
});

test('the retention period is configurable and covers deleted monitors', function () {
    config(['veyra.monitoring.check_retention_days' => 7]);
    MonitorCheck::factory()->create(['monitor_id' => $this->monitor->id, 'checked_at' => now()->subDays(8)]);
    $this->monitor->delete();

    (new PurgeExpiredHistory)->handle();

    expect(MonitorCheck::count())->toBe(0);
});

test('old finished dispatches are purged but pending ones kept', function () {
    MonitorDispatch::factory()->status(DispatchStatus::Completed)->create(['monitor_id' => $this->monitor->id, 'completed_at' => now()->subDays(8), 'scheduled_for' => now()->subDays(8)]);
    MonitorDispatch::factory()->status(DispatchStatus::Completed)->create(['monitor_id' => $this->monitor->id, 'completed_at' => now()->subDay(), 'scheduled_for' => now()->subDay()]);
    MonitorDispatch::factory()->create(['monitor_id' => $this->monitor->id, 'scheduled_for' => now()->subDays(9)]);

    (new PurgeExpiredHistory)->handle();

    expect(MonitorDispatch::count())->toBe(2);
});

test('maintenance jobs are scheduled on the maintenance queue', function () {
    expect((new PurgeExpiredHistory)->queue)->toBe('maintenance')
        ->and((new AggregateDailyStatsJob)->queue)->toBe('maintenance');
});
