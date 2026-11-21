<?php

use App\Domain\Monitoring\Actions\PublishPendingDispatches;
use App\Domain\Monitoring\Actions\ScheduleDueMonitors;
use App\Domain\Monitoring\Enums\DispatchStatus;
use App\Domain\Monitoring\Jobs\ExecuteProbe;
use App\Domain\Monitoring\Models\Monitor;
use App\Domain\Monitoring\Models\MonitorCheck;
use App\Domain\Monitoring\Models\MonitorDispatch;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;

beforeEach(function () {
    $this->now = CarbonImmutable::parse('2026-11-22 10:00:30');
    $this->travelTo($this->now);
});

test('due monitors are claimed into the outbox and rescheduled', function () {
    $monitor = Monitor::factory()->create(['next_check_at' => '2026-11-22 10:00:00', 'interval_seconds' => 60]);

    expect(app(ScheduleDueMonitors::class)->handle($this->now))->toBe(1);

    $dispatch = MonitorDispatch::sole();
    expect($dispatch->monitor_id)->toBe($monitor->id)
        ->and($dispatch->scheduled_for->toDateTimeString())->toBe('2026-11-22 10:00:00')
        ->and($dispatch->status)->toBe(DispatchStatus::Pending)
        ->and($monitor->fresh()->next_check_at->toDateTimeString())->toBe('2026-11-22 10:01:00');
});

test('paused, deleted and not-yet-due monitors are not claimed', function () {
    Monitor::factory()->paused()->create(['next_check_at' => '2026-11-22 09:00:00']);
    Monitor::factory()->create(['next_check_at' => '2026-11-22 09:00:00'])->delete();
    Monitor::factory()->create(['next_check_at' => '2026-11-22 10:05:00']);

    expect(app(ScheduleDueMonitors::class)->handle($this->now))->toBe(0)
        ->and(MonitorDispatch::count())->toBe(0);
});

test('replaying a slot never duplicates a dispatch', function () {
    $monitor = Monitor::factory()->create(['next_check_at' => '2026-11-22 10:00:00']);

    app(ScheduleDueMonitors::class)->handle($this->now);
    $monitor->update(['next_check_at' => '2026-11-22 10:00:00']);
    app(ScheduleDueMonitors::class)->handle($this->now);

    expect(MonitorDispatch::count())->toBe(1);
});

test('after an outage a monitor gets one dispatch, not one per missed slot', function () {
    $monitor = Monitor::factory()->create(['next_check_at' => '2026-11-22 07:00:00', 'interval_seconds' => 60]);

    app(ScheduleDueMonitors::class)->handle($this->now);
    app(ScheduleDueMonitors::class)->handle($this->now);

    expect(MonitorDispatch::count())->toBe(1)
        ->and($monitor->fresh()->next_check_at->toDateTimeString())->toBe('2026-11-22 10:01:30');
});

test('pending dispatches are published to the probes queue', function () {
    Queue::fake();
    $dispatch = MonitorDispatch::factory()->create(['scheduled_for' => '2026-11-22 10:00:00']);

    $counts = app(PublishPendingDispatches::class)->handle($this->now);

    expect($counts['published'])->toBe(1);
    Queue::assertPushedOn('probes', ExecuteProbe::class, fn (ExecuteProbe $job) => $job->dispatchId === $dispatch->id);

    expect($dispatch->fresh())
        ->status->toBe(DispatchStatus::Dispatched)
        ->attempts->toBe(1);
});

test('a queue outage keeps dispatches pending for the next run', function () {
    Queue::shouldReceive('connection')->andThrow(new RuntimeException('Redis unavailable'));
    $dispatch = MonitorDispatch::factory()->create(['scheduled_for' => '2026-11-22 10:00:00']);

    expect(fn () => app(PublishPendingDispatches::class)->handle($this->now))->toThrow(RuntimeException::class);

    expect($dispatch->fresh()->status)->toBe(DispatchStatus::Pending)
        ->and($dispatch->fresh()->attempts)->toBe(0);
});

test('lost jobs are redelivered, then given up after three attempts', function () {
    Queue::fake();
    $redeliver = MonitorDispatch::factory()->status(DispatchStatus::Dispatched)->create([
        'scheduled_for' => '2026-11-22 09:59:00', 'attempts' => 1, 'dispatched_at' => '2026-11-22 09:54:00',
    ]);
    $exhausted = MonitorDispatch::factory()->status(DispatchStatus::Dispatched)->create([
        'scheduled_for' => '2026-11-22 09:59:00', 'attempts' => 3, 'dispatched_at' => '2026-11-22 09:54:00',
    ]);
    $recent = MonitorDispatch::factory()->status(DispatchStatus::Dispatched)->create([
        'scheduled_for' => '2026-11-22 10:00:00', 'attempts' => 1, 'dispatched_at' => '2026-11-22 10:00:10',
    ]);

    app(PublishPendingDispatches::class)->handle($this->now);

    expect($redeliver->fresh()->attempts)->toBe(2)
        ->and($exhausted->fresh()->status)->toBe(DispatchStatus::Failed)
        ->and($recent->fresh()->attempts)->toBe(1);
    Queue::assertPushed(ExecuteProbe::class, 1);
});

test('stale dispatches are skipped instead of executed late', function () {
    Queue::fake();
    $stale = MonitorDispatch::factory()->create(['scheduled_for' => '2026-11-22 09:00:00']);

    $counts = app(PublishPendingDispatches::class)->handle($this->now);

    expect($counts['skipped'])->toBe(1)
        ->and($stale->fresh()->status)->toBe(DispatchStatus::Skipped);
    Queue::assertNothingPushed();
});

test('the scheduled command claims and publishes in one run', function () {
    Queue::fake();
    Monitor::factory()->create(['next_check_at' => '2026-11-22 10:00:00']);

    $this->artisan('veyra:monitors:dispatch')->assertSuccessful();

    Queue::assertPushedOn('probes', ExecuteProbe::class);
    expect(MonitorDispatch::sole()->status)->toBe(DispatchStatus::Dispatched);
});

test('a fast worker completing the job is never overwritten by the relay', function () {
    // The sync queue runs the job inside the relay: the worst-case ordering.
    config(['queue.default' => 'sync']);
    Http::fake(['*' => Http::response('', 200)]);
    $dispatch = MonitorDispatch::factory()->create(['scheduled_for' => '2026-11-22 10:00:00']);

    app(PublishPendingDispatches::class)->handle($this->now);

    expect($dispatch->fresh()->status)->toBe(DispatchStatus::Completed);

    // Ten minutes later the reaper must not re-run the probe.
    app(PublishPendingDispatches::class)->handle($this->now->addMinutes(10));
    Http::assertSentCount(1);
});

test('a redelivered job whose check already exists does not probe again', function () {
    Http::fake();
    $dispatch = MonitorDispatch::factory()->status(DispatchStatus::Dispatched)->create();
    MonitorCheck::factory()->create(['monitor_id' => $dispatch->monitor_id, 'dispatch_id' => $dispatch->id]);

    app()->call([new ExecuteProbe($dispatch->id), 'handle']);

    Http::assertNothingSent();
    expect($dispatch->fresh()->status)->toBe(DispatchStatus::Completed);
});
