<?php

use App\Domain\Incidents\Enums\IncidentEventType;
use App\Domain\Incidents\Enums\IncidentStatus;
use App\Domain\Incidents\Models\Incident;
use App\Domain\Monitoring\Actions\ApplyMonitorState;
use App\Domain\Monitoring\Actions\DeleteMonitor;
use App\Domain\Monitoring\Enums\MonitorStatus;
use App\Domain\Monitoring\Enums\MonitorType;
use App\Domain\Monitoring\Jobs\ApplyMonitorStateJob;
use App\Domain\Monitoring\Jobs\ExecuteProbe;
use App\Domain\Monitoring\Models\Monitor;
use App\Domain\Monitoring\Models\MonitorDispatch;
use App\Domain\Monitoring\Models\MonitorState;
use App\Domain\Monitoring\State\StateThresholds;
use App\Models\User;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;

beforeEach(function () {
    $this->monitor = Monitor::factory()->create();
});

function state(Monitor $monitor): MonitorState
{
    return MonitorState::findOrFail($monitor->id);
}

test('new monitors start unknown', function () {
    expect(state($this->monitor)->status)->toBe(MonitorStatus::Unknown);
});

test('two successes confirm up, failures degrade, three failures open one incident', function () {
    recordCheck($this->monitor, true, '2026-11-22 10:00:00');
    expect(state($this->monitor)->status)->toBe(MonitorStatus::Unknown);

    recordCheck($this->monitor, true, '2026-11-22 10:01:00');
    expect(state($this->monitor)->status)->toBe(MonitorStatus::Up);

    recordCheck($this->monitor, false, '2026-11-22 10:02:00');
    recordCheck($this->monitor, false, '2026-11-22 10:03:00');
    expect(state($this->monitor)->status)->toBe(MonitorStatus::Degraded)
        ->and(Incident::count())->toBe(0);

    recordCheck($this->monitor, false, '2026-11-22 10:04:00');
    recordCheck($this->monitor, false, '2026-11-22 10:05:00');
    recordCheck($this->monitor, false, '2026-11-22 10:06:00');

    $incident = Incident::sole();
    expect(state($this->monitor))
        ->status->toBe(MonitorStatus::Down)
        ->active_incident_id->toBe($incident->id)
        ->consecutive_failures->toBe(5);
    expect($incident->status)->toBe(IncidentStatus::Open)
        ->and($incident->organization_id)->toBe($this->monitor->organization_id)
        ->and($incident->started_at->toDateTimeString())->toBe('2026-11-22 10:02:01')
        ->and($incident->cause)->toBe('Unexpected status code (503)');
});

test('an isolated success does not resolve, two successes do', function () {
    foreach (['10:00', '10:01', '10:02'] as $time) {
        recordCheck($this->monitor, false, "2026-11-22 {$time}:00");
    }

    recordCheck($this->monitor, true, '2026-11-22 10:03:00');
    expect(state($this->monitor)->status)->toBe(MonitorStatus::Down)
        ->and(Incident::sole()->isOpen())->toBeTrue();

    recordCheck($this->monitor, false, '2026-11-22 10:04:00');
    recordCheck($this->monitor, true, '2026-11-22 10:05:00');
    recordCheck($this->monitor, true, '2026-11-22 10:06:00');

    $incident = Incident::sole();
    expect(state($this->monitor))
        ->status->toBe(MonitorStatus::Up)
        ->active_incident_id->toBeNull();
    expect($incident->status)->toBe(IncidentStatus::Resolved)
        ->and($incident->resolved_at->toDateTimeString())->toBe('2026-11-22 10:05:01')
        ->and($incident->events()->pluck('type')->map->value->all())->toBe(['opened', 'resolved']);
});

test('a new outage after recovery opens a new incident', function () {
    foreach (['00', '01', '02'] as $minute) {
        recordCheck($this->monitor, false, "2026-11-22 10:{$minute}:00");
    }
    recordCheck($this->monitor, true, '2026-11-22 10:03:00');
    recordCheck($this->monitor, true, '2026-11-22 10:04:00');
    foreach (['05', '06', '07'] as $minute) {
        recordCheck($this->monitor, false, "2026-11-22 10:{$minute}:00");
    }

    expect(Incident::count())->toBe(2)
        ->and(Incident::where('status', IncidentStatus::Open)->count())->toBe(1);
});

test('late, out-of-order checks never change a newer state', function () {
    recordCheck($this->monitor, true, '2026-11-22 10:05:00');
    recordCheck($this->monitor, true, '2026-11-22 10:06:00');
    expect(state($this->monitor)->status)->toBe(MonitorStatus::Up);

    recordCheck($this->monitor, false, '2026-11-22 10:01:00');
    recordCheck($this->monitor, false, '2026-11-22 10:02:00');
    recordCheck($this->monitor, false, '2026-11-22 10:03:00');

    expect(state($this->monitor))
        ->status->toBe(MonitorStatus::Up)
        ->last_applied_scheduled_for->toDateTimeString()->toBe('2026-11-22 10:06:00');
    expect(Incident::count())->toBe(0);
});

test('replayed state jobs are no-ops', function () {
    foreach (['00', '01', '02'] as $minute) {
        recordCheck($this->monitor, false, "2026-11-22 10:{$minute}:00");
    }

    app(ApplyMonitorState::class)->handle($this->monitor->id);
    app(ApplyMonitorState::class)->handle($this->monitor->id);
    (new ApplyMonitorStateJob($this->monitor->id))->handle(app(ApplyMonitorState::class));

    expect(Incident::count())->toBe(1)
        ->and(Incident::sole()->events()->count())->toBe(1);
});

test('the probe job triggers the state transition on the incidents queue', function () {
    Queue::fake([ApplyMonitorStateJob::class]);
    Http::fake(['*' => Http::response('', 200)]);
    $dispatch = MonitorDispatch::factory()->create(['monitor_id' => $this->monitor->id]);

    app()->call([new ExecuteProbe($dispatch->id), 'handle']);

    Queue::assertPushedOn('incidents', ApplyMonitorStateJob::class, fn (ApplyMonitorStateJob $job) => $job->monitorId === $this->monitor->id);
});

test('pausing makes the state unknown and keeps the incident open; resuming restarts streaks', function () {
    foreach (['00', '01', '02'] as $minute) {
        recordCheck($this->monitor, false, "2026-11-22 10:{$minute}:00");
    }

    $this->actingAs($user = User::factory()->create());
    $this->monitor->organization->members()->create(['user_id' => $user->id, 'role' => 'member', 'joined_at' => now()]);

    $this->patch(route('organizations.monitors.enabled', [$this->monitor->organization, $this->monitor]), ['enabled' => false]);
    expect(state($this->monitor)->status)->toBe(MonitorStatus::Unknown)
        ->and(Incident::sole()->isOpen())->toBeTrue();

    $this->patch(route('organizations.monitors.enabled', [$this->monitor->organization, $this->monitor]), ['enabled' => true]);
    $this->travelTo('2026-11-22 12:00:00');

    // Old failures before the pause no longer count towards a streak.
    recordCheck($this->monitor, true, '2026-11-22 12:00:00');
    recordCheck($this->monitor, true, '2026-11-22 12:01:00');

    expect(state($this->monitor)->status)->toBe(MonitorStatus::Up)
        ->and(Incident::sole()->status)->toBe(IncidentStatus::Resolved);
});

test('deleting a monitor closes its open incident', function () {
    foreach (['00', '01', '02'] as $minute) {
        recordCheck($this->monitor, false, "2026-11-22 10:{$minute}:00");
    }

    app(DeleteMonitor::class)->handle($this->monitor);

    $incident = Incident::sole();
    expect($incident->status)->toBe(IncidentStatus::Resolved)
        ->and($incident->events()->latest('created_at')->latest('id')->first()->type)->toBe(IncidentEventType::MonitorDeleted)
        ->and(state($this->monitor)->active_incident_id)->toBeNull();
});

test('heartbeat monitors confirm down on one missed ping and up on one ping', function () {
    $heartbeat = Monitor::factory()->ofType(MonitorType::Heartbeat)->create();

    recordCheck($heartbeat, true, '2026-11-22 10:00:00');
    expect(state($heartbeat)->status)->toBe(MonitorStatus::Up);

    recordCheck($heartbeat, false, '2026-11-22 11:00:00');
    expect(state($heartbeat)->status)->toBe(MonitorStatus::Down)
        ->and(Incident::where('monitor_id', $heartbeat->id)->sole()->status)->toBe(IncidentStatus::Open);

    recordCheck($heartbeat, true, '2026-11-22 12:00:00');
    expect(state($heartbeat)->status)->toBe(MonitorStatus::Up)
        ->and(Incident::where('monitor_id', $heartbeat->id)->sole()->status)->toBe(IncidentStatus::Resolved);
});

test('heartbeat thresholds are configured separately from probe thresholds', function () {
    config([
        'veyra.monitoring.heartbeat_down_after_misses' => 2,
        'veyra.monitoring.down_after_failures' => 5,
    ]);

    expect(StateThresholds::forType(MonitorType::Heartbeat))
        ->downAfter->toBe(2)->upAfter->toBe(1)
        ->and(StateThresholds::forType(MonitorType::Http))->downAfter->toBe(5)->upAfter->toBe(2)
        ->and(StateThresholds::forType(MonitorType::Tcp))->downAfter->toBe(5);
});
