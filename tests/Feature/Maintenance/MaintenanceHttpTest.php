<?php

use App\Domain\Maintenance\Models\MaintenanceWindow;
use App\Domain\Monitoring\Models\Monitor;
use App\Domain\Organizations\Enums\OrganizationRole;
use App\Models\User;
use Inertia\Testing\AssertableInertia as Assert;

beforeEach(function () {
    $this->travelTo('2026-11-22 12:00:00');
    $this->owner = User::factory()->create();
    $this->organization = organizationWith($this->owner);
    $this->monitor = Monitor::factory()->create(['organization_id' => $this->organization->id]);
});

function windowPayload(array $overrides = []): array
{
    return [
        'title' => 'Database upgrade',
        'description' => 'Short read-only period.',
        'starts_at' => '2026-11-22T13:00:00+01:00',
        'ends_at' => '2026-11-22T15:00:00+01:00',
        ...$overrides,
    ];
}

test('members schedule a window; times are stored in UTC', function () {
    $member = memberOf($this->organization, OrganizationRole::Member);

    $this->actingAs($member)
        ->post(route('organizations.maintenance.store', $this->organization), windowPayload(['monitor_ids' => [$this->monitor->id]]))
        ->assertRedirect(route('organizations.maintenance.index', $this->organization));

    $window = MaintenanceWindow::sole();
    expect($window)
        ->organization_id->toBe($this->organization->id)
        ->created_by->toBe($member->id)
        ->and($window->starts_at->toDateTimeString())->toBe('2026-11-22 12:00:00')
        ->and($window->ends_at->toDateTimeString())->toBe('2026-11-22 14:00:00')
        ->and($window->monitors()->pluck('monitors.id')->all())->toBe([$this->monitor->id]);
});

test('viewers can list windows but not manage them', function () {
    $viewer = memberOf($this->organization, OrganizationRole::Viewer);
    $window = MaintenanceWindow::factory()->create(['organization_id' => $this->organization->id]);

    $this->actingAs($viewer)->get(route('organizations.maintenance.index', $this->organization))
        ->assertInertia(fn (Assert $page) => $page
            ->component('maintenance/Index')
            ->where('canManage', false)
            ->has('upcoming', 1));

    $this->actingAs($viewer)->get(route('organizations.maintenance.create', $this->organization))->assertForbidden();
    $this->actingAs($viewer)->post(route('organizations.maintenance.store', $this->organization), windowPayload(['monitor_ids' => [$this->monitor->id]]))->assertForbidden();
    $this->actingAs($viewer)->post(route('organizations.maintenance.cancel', [$this->organization, $window]))->assertForbidden();

    expect(MaintenanceWindow::count())->toBe(1)
        ->and($window->fresh()->cancelled_at)->toBeNull();
});

test('monitors of another organization are rejected', function () {
    $foreign = Monitor::factory()->create();

    $this->actingAs($this->owner)
        ->post(route('organizations.maintenance.store', $this->organization), windowPayload(['monitor_ids' => [$foreign->id]]))
        ->assertSessionHasErrors('monitor_ids.0');

    expect(MaintenanceWindow::count())->toBe(0);
});

test('windows are validated', function (array $overrides, string $field) {
    $this->actingAs($this->owner)
        ->post(route('organizations.maintenance.store', $this->organization), windowPayload(['monitor_ids' => [$this->monitor->id], ...$overrides]))
        ->assertSessionHasErrors($field);

    expect(MaintenanceWindow::count())->toBe(0);
})->with([
    'missing title' => [['title' => ''], 'title'],
    'end before start' => [['ends_at' => '2026-11-22T11:30:00Z'], 'ends_at'],
    'too long' => [['ends_at' => '2026-12-01T12:00:00Z'], 'ends_at'],
    'starting in the past' => [['starts_at' => '2026-11-22T11:00:00Z'], 'starts_at'],
    'no monitor' => [['monitor_ids' => []], 'monitor_ids'],
]);

test('a running window can be ended now, a scheduled one cancelled', function () {
    $running = MaintenanceWindow::factory()->active()->create(['organization_id' => $this->organization->id]);
    $scheduled = MaintenanceWindow::factory()->create(['organization_id' => $this->organization->id]);

    $this->actingAs($this->owner)->post(route('organizations.maintenance.cancel', [$this->organization, $running]))
        ->assertSessionHasErrors('window');
    $this->actingAs($this->owner)->post(route('organizations.maintenance.end', [$this->organization, $running]))
        ->assertRedirect();
    $this->actingAs($this->owner)->post(route('organizations.maintenance.cancel', [$this->organization, $scheduled]))
        ->assertRedirect();

    expect($running->fresh()->ends_at->toDateTimeString())->toBe('2026-11-22 12:00:00')
        ->and($scheduled->fresh()->cancelled_at)->not->toBeNull();
});

test('a window is edited through the form', function () {
    $window = MaintenanceWindow::factory()->create(['organization_id' => $this->organization->id]);
    $window->monitors()->attach($this->monitor->id);

    $this->actingAs($this->owner)->get(route('organizations.maintenance.edit', [$this->organization, $window]))
        ->assertInertia(fn (Assert $page) => $page
            ->component('maintenance/Edit')
            ->where('window.id', $window->id)
            ->where('window.status', 'scheduled')
            ->has('monitors', 1));

    $this->actingAs($this->owner)
        ->put(route('organizations.maintenance.update', [$this->organization, $window]), windowPayload(['title' => 'Renamed', 'monitor_ids' => [$this->monitor->id]]))
        ->assertRedirect();

    expect($window->fresh()->title)->toBe('Renamed');
});

test('the monitor page announces the running window and flags maintenance checks', function () {
    $window = MaintenanceWindow::factory()->active()->create(['organization_id' => $this->organization->id, 'title' => 'Upgrade']);
    $window->monitors()->attach($this->monitor->id);
    recordCheck($this->monitor, false, '2026-11-22 11:59:00');

    $this->actingAs($this->owner)->get(route('organizations.monitors.show', [$this->organization, $this->monitor]))
        ->assertInertia(fn (Assert $page) => $page
            ->where('maintenance.title', 'Upgrade')
            ->where('maintenance.active', true)
            ->where('monitor.state.status', 'maintenance')
            ->where('checks.data.0.in_maintenance', true));
});
