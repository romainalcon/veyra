<?php

use App\Domain\Monitoring\Models\Monitor;
use App\Domain\Monitoring\Models\MonitorCheck;
use App\Domain\Monitoring\Models\MonitorDailyStat;
use App\Domain\Monitoring\Stats\AggregateDailyStats;
use App\Domain\Monitoring\Stats\MonitorSeries;
use App\Domain\Monitoring\Stats\StatsRange;
use App\Domain\Monitoring\Stats\UptimeCalculator;
use App\Models\User;
use Carbon\CarbonImmutable;
use Inertia\Testing\AssertableInertia as Assert;

beforeEach(function () {
    $this->now = CarbonImmutable::parse('2026-11-22 12:00:00');
    $this->travelTo($this->now);
    $this->monitor = Monitor::factory()->create(['interval_seconds' => 60, 'created_at' => '2026-11-01 00:00:00']);
});

function checksAt(Monitor $monitor, string $start, int $count, int $failures = 0, int $durationMs = 100): void
{
    foreach (range(0, $count - 1) as $i) {
        MonitorCheck::factory()->create([
            'monitor_id' => $monitor->id,
            'scheduled_for' => CarbonImmutable::parse($start)->addMinutes($i),
            'checked_at' => CarbonImmutable::parse($start)->addMinutes($i)->addSecond(),
            'success' => $i >= $failures,
            'duration_ms' => $durationMs + $i,
        ]);
    }
}

test('daily aggregates are computed from raw checks and recomputation is idempotent', function () {
    checksAt($this->monitor, '2026-11-21 08:00:00', 10, failures: 2);

    app(AggregateDailyStats::class)->handle(CarbonImmutable::parse('2026-11-21'), $this->now);
    app(AggregateDailyStats::class)->handle(CarbonImmutable::parse('2026-11-21'), $this->now);

    $stat = MonitorDailyStat::sole();
    expect($stat)
        ->checks_count->toBe(10)
        ->success_count->toBe(8)
        ->failure_count->toBe(2)
        ->latency_avg_ms->toBe(106)
        ->latency_max_ms->toBe(109)
        ->expected_checks->toBe(1440);
});

test('the current day only expects checks up to now', function () {
    checksAt($this->monitor, '2026-11-22 11:00:00', 5);

    app(AggregateDailyStats::class)->handle($this->now, $this->now);

    expect(MonitorDailyStat::sole()->expected_checks)->toBe(720);
});

test('uptime is computed over observed checks with explicit coverage', function () {
    checksAt($this->monitor, '2026-11-22 11:00:00', 50, failures: 1);

    $summary = app(UptimeCalculator::class)->forMonitors(collect([$this->monitor]), StatsRange::Day)[$this->monitor->id];

    expect($summary->uptime())->toBe(98.0)
        ->and($summary->checks)->toBe(50)
        ->and($summary->expected)->toBe(1440)
        ->and($summary->isPartial())->toBeTrue();
});

test('no data is never reported as 100 % or 0 %', function () {
    $summary = app(UptimeCalculator::class)->forMonitors(collect([$this->monitor]), StatsRange::Week)[$this->monitor->id];

    expect($summary->uptime())->toBeNull()
        ->and($summary->toArray()['uptime'])->toBeNull();
});

test('series are bounded and mark empty buckets as no data', function (StatsRange $range, int $points) {
    checksAt($this->monitor, '2026-11-22 11:00:00', 30);
    app(AggregateDailyStats::class)->handle($this->now, $this->now);

    $series = app(MonitorSeries::class)->latency($this->monitor, $range);

    expect($series)->toHaveCount($points)
        ->and(collect($series)->where('checks', 0)->first()['latency_avg'])->toBeNull()
        ->and(collect($series)->sum('checks'))->toBe(30);
})->with([
    '24h' => [StatsRange::Day, 96],
    '7d' => [StatsRange::Week, 168],
    '30d' => [StatsRange::Month, 30],
]);

test('uptime days cover 90 days with no-data gaps', function () {
    checksAt($this->monitor, '2026-11-21 08:00:00', 10, failures: 5);
    app(AggregateDailyStats::class)->handle(CarbonImmutable::parse('2026-11-21'), $this->now);

    $days = app(MonitorSeries::class)->uptimeDays($this->monitor, 90);

    expect($days)->toHaveCount(90)
        ->and($days[88])->toMatchArray(['day' => '2026-11-21', 'uptime' => 50.0])
        ->and($days[0]['uptime'])->toBeNull();
});

test('monitor stats are served as a deferred, tenant-scoped prop', function () {
    $user = User::factory()->create();
    $this->monitor->organization->members()->create(['user_id' => $user->id, 'role' => 'viewer', 'joined_at' => now()]);
    checksAt($this->monitor, '2026-11-22 11:00:00', 10);

    $this->actingAs($user)
        ->get(route('organizations.monitors.show', [$this->monitor->organization, $this->monitor, 'range' => '7d']))
        ->assertInertia(fn (Assert $page) => $page
            ->where('range', '7d')
            ->missing('stats')
            ->loadDeferredProps(fn (Assert $reload) => $reload
                ->has('stats.latency', 168)
                ->has('stats.days', 90)
                ->where('stats.uptime.24h.uptime', 100)));
});

test('the dashboard analytics only cover the current organization', function () {
    $user = User::factory()->create();
    $organization = organizationWith($user);
    $own = Monitor::factory()->create(['organization_id' => $organization->id, 'name' => 'Own']);
    checksAt($own, '2026-11-22 11:00:00', 5);
    checksAt($this->monitor, '2026-11-22 11:00:00', 5, failures: 5);

    $this->actingAs($user)
        ->get(route('organizations.dashboard', $organization))
        ->assertInertia(fn (Assert $page) => $page
            ->loadDeferredProps(fn (Assert $reload) => $reload
                ->has('analytics.monitors', 1)
                ->where('analytics.monitors.0.name', 'Own')
                ->where('analytics.uptime.24h.uptime', 100)
                ->has('analytics.incidents.days', 30)));
});
