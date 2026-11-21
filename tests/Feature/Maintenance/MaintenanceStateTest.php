<?php

use App\Domain\Incidents\Enums\IncidentStatus;
use App\Domain\Incidents\Models\Incident;
use App\Domain\Maintenance\Actions\ApplyMaintenanceTransitions;
use App\Domain\Maintenance\Models\MaintenanceWindow;
use App\Domain\Monitoring\Actions\DetectStaleMonitors;
use App\Domain\Monitoring\Enums\MonitorStatus;
use App\Domain\Monitoring\Models\Monitor;
use App\Domain\Monitoring\Models\MonitorCheck;
use App\Domain\Monitoring\Models\MonitorState;
use App\Domain\Notifications\Actions\SendIncidentReminders;
use App\Domain\Notifications\Jobs\SendNotificationDelivery;
use App\Domain\Notifications\Models\NotificationChannel;
use App\Domain\Notifications\Models\NotificationDelivery;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Queue;

beforeEach(function () {
    Queue::fake([SendNotificationDelivery::class]);
    $this->monitor = Monitor::factory()->create();
    $channel = NotificationChannel::factory()->create(['organization_id' => $this->monitor->organization_id]);
    $this->monitor->notificationChannels()->attach($channel->id);
});

function maintenanceFor(Monitor $monitor, string $startsAt, string $endsAt): MaintenanceWindow
{
    $window = MaintenanceWindow::factory()
        ->between($startsAt, $endsAt)
        ->create(['organization_id' => $monitor->organization_id]);
    $window->monitors()->attach($monitor->id);

    return $window;
}

function stateOf(Monitor $monitor): MonitorState
{
    return MonitorState::findOrFail($monitor->id);
}

test('failures during maintenance never open an incident nor notify', function () {
    maintenanceFor($this->monitor, '2026-11-22 10:00:00', '2026-11-22 11:00:00');
    $this->travelTo('2026-11-22 10:30:00');

    foreach (['10:00', '10:01', '10:02', '10:03', '10:04'] as $time) {
        recordCheck($this->monitor, false, "2026-11-22 {$time}:00");
    }

    expect(stateOf($this->monitor))
        ->status->toBe(MonitorStatus::Maintenance)
        ->consecutive_failures->toBe(0);
    expect(Incident::count())->toBe(0)
        ->and(NotificationDelivery::count())->toBe(0)
        ->and(MonitorCheck::where('in_maintenance', true)->count())->toBe(5);
});

test('checks are classified by their scheduled slot over [start, end)', function () {
    maintenanceFor($this->monitor, '2026-11-22 10:00:00', '2026-11-22 11:00:00');
    $this->travelTo('2026-11-22 12:00:00');

    recordCheck($this->monitor, true, '2026-11-22 09:59:59');
    recordCheck($this->monitor, true, '2026-11-22 10:00:00');
    recordCheck($this->monitor, true, '2026-11-22 10:59:59');
    recordCheck($this->monitor, true, '2026-11-22 11:00:00');

    expect(MonitorCheck::orderBy('scheduled_for')->pluck('in_maintenance')->all())
        ->toBe([false, true, true, false]);
});

test('cancelled windows do not cover checks', function () {
    $window = maintenanceFor($this->monitor, '2026-11-22 10:00:00', '2026-11-22 11:00:00');
    $window->update(['cancelled_at' => '2026-11-22 09:00:00']);
    $this->travelTo('2026-11-22 10:30:00');

    recordCheck($this->monitor, false, '2026-11-22 10:00:00');

    expect(MonitorCheck::sole()->in_maintenance)->toBeFalse()
        ->and(stateOf($this->monitor)->status)->toBe(MonitorStatus::Unknown);
});

test('streaks restart after maintenance: results before the window never confirm DOWN', function () {
    $this->travelTo('2026-11-22 09:58:00');
    recordCheck($this->monitor, true, '2026-11-22 09:56:00');
    recordCheck($this->monitor, true, '2026-11-22 09:57:00');
    recordCheck($this->monitor, false, '2026-11-22 09:58:00');
    recordCheck($this->monitor, false, '2026-11-22 09:59:00');
    expect(stateOf($this->monitor)->status)->toBe(MonitorStatus::Degraded);

    maintenanceFor($this->monitor, '2026-11-22 10:00:00', '2026-11-22 10:30:00');
    $this->travelTo('2026-11-22 10:10:00');
    recordCheck($this->monitor, false, '2026-11-22 10:05:00');
    expect(stateOf($this->monitor)->status)->toBe(MonitorStatus::Maintenance);

    $this->travelTo('2026-11-22 10:31:00');
    recordCheck($this->monitor, false, '2026-11-22 10:30:00');

    expect(stateOf($this->monitor))
        ->status->toBe(MonitorStatus::Unknown)
        ->consecutive_failures->toBe(1);
    expect(Incident::count())->toBe(0);

    $this->travelTo('2026-11-22 10:33:00');
    recordCheck($this->monitor, false, '2026-11-22 10:31:00');
    recordCheck($this->monitor, false, '2026-11-22 10:32:00');

    expect(stateOf($this->monitor)->status)->toBe(MonitorStatus::Down)
        ->and(Incident::sole()->started_at->toDateTimeString())->toBe('2026-11-22 10:30:01');
});

test('an incident open before maintenance stays open, gets no reminder and resolves afterwards', function () {
    $this->travelTo('2026-11-22 09:05:00');
    foreach (['09:00', '09:01', '09:02'] as $time) {
        recordCheck($this->monitor, false, "2026-11-22 {$time}:00");
    }
    $incident = Incident::sole();

    maintenanceFor($this->monitor, '2026-11-22 09:10:00', '2026-11-22 10:30:00');
    $this->travelTo('2026-11-22 09:10:00');
    app(ApplyMaintenanceTransitions::class)->handle(CarbonImmutable::now());

    recordCheck($this->monitor, true, '2026-11-22 09:11:00');
    recordCheck($this->monitor, true, '2026-11-22 09:12:00');

    $this->travelTo('2026-11-22 10:00:00');
    app(SendIncidentReminders::class)->handle(CarbonImmutable::now());

    expect($incident->fresh())
        ->status->toBe(IncidentStatus::Open)
        ->reminder_sent_at->toBeNull();
    expect(stateOf($this->monitor)->status)->toBe(MonitorStatus::Maintenance);

    $this->travelTo('2026-11-22 10:32:00');
    recordCheck($this->monitor, true, '2026-11-22 10:30:00');
    recordCheck($this->monitor, true, '2026-11-22 10:31:00');

    expect($incident->fresh()->status)->toBe(IncidentStatus::Resolved)
        ->and(stateOf($this->monitor)->status)->toBe(MonitorStatus::Up)
        ->and($incident->events()->orderBy('created_at')->pluck('type')->map->value->all())
        ->toBe(['opened', 'maintenance_started', 'maintenance_ended', 'resolved']);
});

test('the transition command enters and leaves maintenance on time and is idempotent', function () {
    recordCheck($this->monitor, true, '2026-11-22 09:50:00');
    recordCheck($this->monitor, true, '2026-11-22 09:51:00');
    maintenanceFor($this->monitor, '2026-11-22 10:00:00', '2026-11-22 11:00:00');

    $this->travelTo('2026-11-22 10:00:30');
    $this->artisan('veyra:maintenance:transition')->assertSuccessful();
    $this->artisan('veyra:maintenance:transition')->assertSuccessful();

    expect(stateOf($this->monitor)->status)->toBe(MonitorStatus::Maintenance);

    $this->travelTo('2026-11-22 11:00:30');
    expect(app(ApplyMaintenanceTransitions::class)->handle(CarbonImmutable::now()))->toBe(['entered' => 0, 'exited' => 1])
        ->and(app(ApplyMaintenanceTransitions::class)->handle(CarbonImmutable::now()))->toBe(['entered' => 0, 'exited' => 0]);

    expect(stateOf($this->monitor))
        ->status->toBe(MonitorStatus::Unknown)
        ->streak_since->toDateTimeString()->toBe('2026-11-22 11:00:00');
});

test('a late check scheduled before the window does not end a running maintenance', function () {
    maintenanceFor($this->monitor, '2026-11-22 10:00:00', '2026-11-22 11:00:00');
    $this->travelTo('2026-11-22 10:01:00');
    app(ApplyMaintenanceTransitions::class)->handle(CarbonImmutable::now());

    recordCheck($this->monitor, false, '2026-11-22 09:59:00');

    expect(stateOf($this->monitor)->status)->toBe(MonitorStatus::Maintenance);
});

test('paused monitors do not enter maintenance', function () {
    $this->monitor->update(['enabled' => false]);
    maintenanceFor($this->monitor, '2026-11-22 10:00:00', '2026-11-22 11:00:00');
    $this->travelTo('2026-11-22 10:01:00');

    expect(app(ApplyMaintenanceTransitions::class)->handle(CarbonImmutable::now()))->toBe(['entered' => 0, 'exited' => 0]);
});

test('stale detection leaves monitors under maintenance alone', function () {
    maintenanceFor($this->monitor, '2026-11-22 10:00:00', '2026-11-22 18:00:00');
    $this->travelTo('2026-11-22 10:01:00');
    recordCheck($this->monitor, true, '2026-11-22 10:00:00');

    $this->travelTo('2026-11-22 12:00:00');

    expect(app(DetectStaleMonitors::class)->handle(CarbonImmutable::now()))->toBe(0)
        ->and(stateOf($this->monitor)->status)->toBe(MonitorStatus::Maintenance);
});
