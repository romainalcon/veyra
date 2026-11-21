<?php

use App\Domain\Maintenance\Actions\CancelMaintenanceWindow;
use App\Domain\Maintenance\Actions\EndMaintenanceWindowNow;
use App\Domain\Maintenance\Actions\SaveMaintenanceWindow;
use App\Domain\Maintenance\DTOs\MaintenanceWindowData;
use App\Domain\Maintenance\Models\MaintenanceWindow;
use App\Domain\Monitoring\Enums\MonitorStatus;
use App\Domain\Monitoring\Models\Monitor;
use App\Domain\Monitoring\Models\MonitorState;
use App\Domain\Organizations\Models\Organization;
use Carbon\CarbonImmutable;
use Illuminate\Validation\ValidationException;

beforeEach(function () {
    $this->travelTo('2026-11-22 12:00:00');
    $this->organization = Organization::factory()->create();
    $this->monitor = Monitor::factory()->create(['organization_id' => $this->organization->id]);
});

function windowData(array $monitorIds, string $startsAt = '2026-11-22 13:00:00', string $endsAt = '2026-11-22 14:00:00'): MaintenanceWindowData
{
    return new MaintenanceWindowData('Database upgrade', null, CarbonImmutable::parse($startsAt), CarbonImmutable::parse($endsAt), $monitorIds);
}

test('only monitors of the same organization are attached', function () {
    $foreign = Monitor::factory()->create();

    $window = app(SaveMaintenanceWindow::class)->handle($this->organization, windowData([$this->monitor->id, $foreign->id]));

    expect($window->monitors()->pluck('monitors.id')->all())->toBe([$this->monitor->id]);
});

test('the end must follow the start', function () {
    app(SaveMaintenanceWindow::class)->handle($this->organization, windowData([$this->monitor->id], endsAt: '2026-11-22 13:00:00'));
})->throws(ValidationException::class);

test('a started window keeps its start and cannot end in the past', function () {
    $window = MaintenanceWindow::factory()->between('2026-11-22 11:00:00', '2026-11-22 13:00:00')->create(['organization_id' => $this->organization->id]);

    $updated = app(SaveMaintenanceWindow::class)->handle($this->organization, windowData([$this->monitor->id], '2026-11-22 12:30:00', '2026-11-22 15:00:00'), window: $window);

    expect($updated->starts_at->toDateTimeString())->toBe('2026-11-22 11:00:00')
        ->and($updated->ends_at->toDateTimeString())->toBe('2026-11-22 15:00:00');

    expect(fn () => app(SaveMaintenanceWindow::class)->handle($this->organization, windowData([], '2026-11-22 11:00:00', '2026-11-22 11:30:00'), window: $window))
        ->toThrow(ValidationException::class);
});

test('ended and cancelled windows are read-only', function () {
    $ended = MaintenanceWindow::factory()->between('2026-11-22 09:00:00', '2026-11-22 10:00:00')->create(['organization_id' => $this->organization->id]);

    app(SaveMaintenanceWindow::class)->handle($this->organization, windowData([$this->monitor->id]), window: $ended);
})->throws(ValidationException::class);

test('only windows that have not started can be cancelled', function () {
    $future = MaintenanceWindow::factory()->create(['organization_id' => $this->organization->id]);
    $running = MaintenanceWindow::factory()->active()->create(['organization_id' => $this->organization->id]);

    expect(app(CancelMaintenanceWindow::class)->handle($future)->cancelled_at)->not->toBeNull();
    expect(fn () => app(CancelMaintenanceWindow::class)->handle($running))->toThrow(ValidationException::class);
});

test('ending a window now restores the state immediately', function () {
    $window = MaintenanceWindow::factory()->active()->create(['organization_id' => $this->organization->id]);
    $window->monitors()->attach($this->monitor->id);
    MonitorState::whereKey($this->monitor->id)->update(['status' => MonitorStatus::Maintenance->value]);

    app(EndMaintenanceWindowNow::class)->handle($window);

    expect($window->fresh()->ends_at->toDateTimeString())->toBe('2026-11-22 12:00:00')
        ->and(MonitorState::findOrFail($this->monitor->id)->status)->toBe(MonitorStatus::Unknown);

    expect(fn () => app(EndMaintenanceWindowNow::class)->handle($window->fresh()))->toThrow(ValidationException::class);
});
