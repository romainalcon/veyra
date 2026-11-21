<?php

use App\Domain\Incidents\Enums\IncidentEventType;
use App\Domain\Incidents\Models\Incident;
use App\Domain\Monitoring\Enums\MonitorStatus;
use App\Domain\Monitoring\Models\Monitor;
use App\Domain\Monitoring\Models\MonitorState;

test('monitors without recent results become unknown, never down', function () {
    $monitor = Monitor::factory()->create(['interval_seconds' => 60]);
    $this->travelTo('2026-11-22 10:02:00');
    recordCheck($monitor, true, '2026-11-22 10:00:00');
    recordCheck($monitor, true, '2026-11-22 10:01:00');

    // Workers stop: within 2 × interval + 120 s nothing changes.
    $this->travelTo('2026-11-22 10:04:30');
    $this->artisan('veyra:monitors:detect-stale')->assertSuccessful();
    expect(MonitorState::find($monitor->id)->status)->toBe(MonitorStatus::Up);

    $this->travelTo('2026-11-22 10:06:00');
    $this->artisan('veyra:monitors:detect-stale')->assertSuccessful();

    expect(MonitorState::find($monitor->id)->status)->toBe(MonitorStatus::Unknown)
        ->and(Incident::count())->toBe(0);
});

test('an open incident stays open while data is missing, and is annotated', function () {
    $monitor = Monitor::factory()->create(['interval_seconds' => 60]);
    $this->travelTo('2026-11-22 10:03:00');
    foreach (['00', '01', '02'] as $minute) {
        recordCheck($monitor, false, "2026-11-22 10:{$minute}:00");
    }

    $this->travelTo('2026-11-22 10:30:00');
    $this->artisan('veyra:monitors:detect-stale');

    $incident = Incident::sole();
    expect($incident->isOpen())->toBeTrue()
        ->and(MonitorState::find($monitor->id)->status)->toBe(MonitorStatus::Unknown)
        ->and($incident->events()->latest('created_at')->latest('id')->first()->type)->toBe(IncidentEventType::DataUnavailable);

    // Data comes back: the gap resets streaks, so one failure keeps UNKNOWN.
    recordCheck($monitor, false, '2026-11-22 10:30:00');
    expect(MonitorState::find($monitor->id)->status)->toBe(MonitorStatus::Unknown)
        ->and($incident->fresh()->events()->pluck('type')->map->value->all())->toContain('data_restored');

    recordCheck($monitor, true, '2026-11-22 10:31:00');
    recordCheck($monitor, true, '2026-11-22 10:32:00');

    expect($incident->fresh()->isOpen())->toBeFalse()
        ->and(Incident::count())->toBe(1);
});

test('paused and never-checked monitors are ignored', function () {
    $paused = Monitor::factory()->paused()->create();
    MonitorState::whereKey($paused->id)->update(['status' => 'up', 'last_checked_at' => '2026-01-01 00:00:00']);
    $neverChecked = Monitor::factory()->create();

    $this->travelTo('2026-11-22 10:00:00');
    $this->artisan('veyra:monitors:detect-stale');

    expect(MonitorState::find($paused->id)->status)->toBe(MonitorStatus::Up)
        ->and(MonitorState::find($neverChecked->id)->status)->toBe(MonitorStatus::Unknown);
});
