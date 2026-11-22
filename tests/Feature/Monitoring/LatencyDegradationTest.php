<?php

use App\Domain\Incidents\Enums\IncidentStatus;
use App\Domain\Incidents\Models\Incident;
use App\Domain\Monitoring\Actions\ApplyMonitorState;
use App\Domain\Monitoring\Actions\RecordProbeResult;
use App\Domain\Monitoring\DTOs\ProbeResult;
use App\Domain\Monitoring\Enums\DispatchStatus;
use App\Domain\Monitoring\Enums\DispatchTrigger;
use App\Domain\Monitoring\Enums\MonitorStatus;
use App\Domain\Monitoring\Events\MonitorStateChanged;
use App\Domain\Monitoring\Models\Monitor;
use App\Domain\Monitoring\Models\MonitorCheck;
use App\Domain\Monitoring\Models\MonitorDispatch;
use App\Domain\Monitoring\Models\MonitorState;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Queue;

beforeEach(function () {
    $this->monitor = Monitor::factory()->http(['latency_threshold_ms' => 500])->create();
});

function recordLatencyCheck(Monitor $monitor, string $kind, string $scheduledFor): ?MonitorStatus
{
    MonitorCheck::factory()->create([
        'monitor_id' => $monitor->id,
        'scheduled_for' => $scheduledFor,
        'checked_at' => CarbonImmutable::parse($scheduledFor)->addSeconds(1),
        'success' => $kind !== 'fail',
        'slow' => $kind === 'slow',
        'duration_ms' => $kind === 'slow' ? 900 : 100,
        'status_code' => $kind === 'fail' ? 503 : 200,
        'error_code' => $kind === 'fail' ? 'unexpected_status' : null,
    ]);

    return app(ApplyMonitorState::class)->handle($monitor->id);
}

test('three slow checks degrade without an incident, two fast checks recover', function () {
    Event::fake([MonitorStateChanged::class]);

    recordLatencyCheck($this->monitor, 'fast', '2026-12-03 10:00:00');
    recordLatencyCheck($this->monitor, 'fast', '2026-12-03 10:01:00');
    expect(MonitorState::find($this->monitor->id)->status)->toBe(MonitorStatus::Up);

    recordLatencyCheck($this->monitor, 'slow', '2026-12-03 10:02:00');
    recordLatencyCheck($this->monitor, 'slow', '2026-12-03 10:03:00');
    expect(MonitorState::find($this->monitor->id)->status)->toBe(MonitorStatus::Up);

    expect(recordLatencyCheck($this->monitor, 'slow', '2026-12-03 10:04:00'))->toBe(MonitorStatus::Degraded)
        ->and(Incident::count())->toBe(0);

    foreach (range(5, 12) as $minute) {
        recordLatencyCheck($this->monitor, 'slow', sprintf('2026-12-03 10:%02d:00', $minute));
    }

    expect(MonitorState::find($this->monitor->id)->status)->toBe(MonitorStatus::Degraded)
        ->and(Incident::count())->toBe(0);

    expect(recordLatencyCheck($this->monitor, 'fast', '2026-12-03 10:13:00'))->toBe(MonitorStatus::Degraded)
        ->and(recordLatencyCheck($this->monitor, 'fast', '2026-12-03 10:14:00'))->toBe(MonitorStatus::Up);

    Event::assertDispatched(MonitorStateChanged::class, fn (MonitorStateChanged $event): bool => $event->to === MonitorStatus::Degraded);
});

test('slow but successful checks resolve an outage', function () {
    foreach (['10:00', '10:01', '10:02'] as $time) {
        recordLatencyCheck($this->monitor, 'fail', "2026-12-03 {$time}:00");
    }

    $incident = Incident::sole();
    expect(MonitorState::find($this->monitor->id)->status)->toBe(MonitorStatus::Down);

    recordLatencyCheck($this->monitor, 'slow', '2026-12-03 10:03:00');
    expect(recordLatencyCheck($this->monitor, 'slow', '2026-12-03 10:04:00'))->toBe(MonitorStatus::Up)
        ->and($incident->fresh()->status)->toBe(IncidentStatus::Resolved)
        ->and($incident->fresh()->resolved_at->toDateTimeString())->toBe('2026-12-03 10:03:01');
});

test('replaying a slow check is a no-op', function () {
    foreach (['10:00', '10:01', '10:02'] as $time) {
        recordLatencyCheck($this->monitor, 'slow', "2026-12-03 {$time}:00");
    }

    $state = MonitorState::find($this->monitor->id);
    expect($state->status)->toBe(MonitorStatus::Degraded);

    app(ApplyMonitorState::class)->handle($this->monitor->id);

    expect(MonitorState::find($this->monitor->id)->only(['status', 'last_changed_at', 'consecutive_successes']))
        ->toEqual($state->only(['status', 'last_changed_at', 'consecutive_successes']));
});

test('the slow flag is recorded with the check', function () {
    Queue::fake();
    $dispatch = MonitorDispatch::create([
        'monitor_id' => $this->monitor->id,
        'scheduled_for' => '2026-12-03 10:00:00',
        'trigger' => DispatchTrigger::Scheduled,
        'status' => DispatchStatus::Dispatched,
    ]);

    app(RecordProbeResult::class)->handle($dispatch, new ProbeResult(true, 900, 200, null, slow: true));

    expect(MonitorCheck::sole()->slow)->toBeTrue()
        ->and(MonitorCheck::sole()->success)->toBeTrue();
});
