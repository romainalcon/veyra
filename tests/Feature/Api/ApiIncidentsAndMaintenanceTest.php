<?php

use App\Domain\Incidents\Enums\IncidentEventType;
use App\Domain\Incidents\Events\IncidentAcknowledged;
use App\Domain\Incidents\Models\Incident;
use App\Domain\Maintenance\Models\MaintenanceSeries;
use App\Domain\Maintenance\Models\MaintenanceWindow;
use App\Domain\Monitoring\Models\Monitor;
use App\Domain\Organizations\Enums\ApiTokenAbility;
use App\Models\User;
use Illuminate\Support\Facades\Event;
use Inertia\Testing\AssertableInertia as Assert;

beforeEach(function () {
    $this->owner = User::factory()->create();
    $this->organization = organizationWith($this->owner);
    $this->token = apiTokenFor($this->organization, attributes: ['name' => 'Deploy bot']);
    $this->monitor = Monitor::factory()->create(['organization_id' => $this->organization->id]);
});

test('incidents are listed newest first and can be filtered', function () {
    $resolved = Incident::factory()->resolved()->create(['monitor_id' => $this->monitor->id, 'started_at' => now()->subDay()]);
    $open = Incident::factory()->create(['monitor_id' => $this->monitor->id]);

    $this->withApiToken($this->token)->getJson(route('api.v1.incidents.index'))
        ->assertOk()
        ->assertJsonPath('data.0.id', $open->id)
        ->assertJsonPath('data.1.id', $resolved->id)
        ->assertJsonPath('data.0.cause', 'Unexpected status code (503)');

    $this->withApiToken($this->token)->getJson(route('api.v1.incidents.index', ['status' => 'resolved']))
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.id', $resolved->id);

    $this->withApiToken($this->token)->getJson(route('api.v1.incidents.index', ['status' => 'bogus']))
        ->assertUnprocessable()
        ->assertJsonValidationErrors('status');
});

test('acknowledging through the API is idempotent and names the token in the timeline', function () {
    Event::fake([IncidentAcknowledged::class]);
    $incident = Incident::factory()->create(['monitor_id' => $this->monitor->id]);

    foreach (range(1, 2) as $ignored) {
        $this->withApiToken($this->token)->postJson(route('api.v1.incidents.acknowledge', $incident))
            ->assertOk()
            ->assertJsonPath('data.events.0.actor', 'api_token');
    }

    $incident->refresh();
    expect($incident->acknowledged_at)->not->toBeNull()
        ->and($incident->acknowledged_by)->toBeNull()
        ->and($incident->events()->where('type', IncidentEventType::Acknowledged)->count())->toBe(1);

    Event::assertDispatchedTimes(IncidentAcknowledged::class, 1);

    $this->actingAs($this->owner)
        ->get(route('organizations.incidents.show', [$this->organization, $incident]))
        ->assertInertia(fn (Assert $page) => $page
            ->where('incident.acknowledged_by', 'API token Deploy bot')
            ->where('incident.events.0.user', 'API token Deploy bot'));
});

test('read tokens cannot acknowledge', function () {
    $incident = Incident::factory()->create(['monitor_id' => $this->monitor->id]);

    $this->withApiToken(apiTokenFor($this->organization, ApiTokenAbility::Read))
        ->postJson(route('api.v1.incidents.acknowledge', $incident))
        ->assertForbidden();

    expect($incident->refresh()->acknowledged_at)->toBeNull();
});

test('maintenance windows can be scheduled, updated, ended and cancelled', function () {
    $startsAt = now()->addHour()->startOfMinute();

    $created = $this->withApiToken($this->token)->postJson(route('api.v1.maintenance-windows.store'), [
        'title' => 'Database upgrade',
        'starts_at' => $startsAt->toIso8601String(),
        'ends_at' => $startsAt->addHour()->toIso8601String(),
        'monitor_ids' => [$this->monitor->id],
    ])->assertCreated()
        ->assertJsonPath('data.status', 'scheduled')
        ->assertJsonPath('data.monitor_ids', [$this->monitor->id]);

    $window = MaintenanceWindow::query()->findOrFail($created->json('data.id'));
    expect($window->organization_id)->toBe($this->organization->id);

    $this->withApiToken($this->token)->putJson(route('api.v1.maintenance-windows.update', $window), [
        'title' => 'Database upgrade (extended)',
        'starts_at' => $startsAt->toIso8601String(),
        'ends_at' => $startsAt->addHours(2)->toIso8601String(),
        'monitor_ids' => [$this->monitor->id],
    ])->assertOk()->assertJsonPath('data.title', 'Database upgrade (extended)');

    $this->withApiToken($this->token)->postJson(route('api.v1.maintenance-windows.end', $window))
        ->assertUnprocessable()
        ->assertJsonValidationErrors('window');

    $this->withApiToken($this->token)->postJson(route('api.v1.maintenance-windows.cancel', $window))
        ->assertOk()
        ->assertJsonPath('data.status', 'cancelled');

    $active = MaintenanceWindow::factory()->active()->create(['organization_id' => $this->organization->id]);
    $active->monitors()->attach($this->monitor->id);

    $this->withApiToken($this->token)->postJson(route('api.v1.maintenance-windows.end', $active))
        ->assertOk()
        ->assertJsonPath('data.status', 'completed');

    $this->withApiToken($this->token)->getJson(route('api.v1.maintenance-windows.index', ['upcoming' => 1]))
        ->assertOk()
        ->assertJsonCount(0, 'data');
});

test('maintenance validation is the interface validation', function (array $overrides, string $field) {
    $startsAt = now()->addHour()->startOfMinute();

    $this->withApiToken($this->token)->postJson(route('api.v1.maintenance-windows.store'), [
        'title' => 'Upgrade',
        'starts_at' => $startsAt->toIso8601String(),
        'ends_at' => $startsAt->addHour()->toIso8601String(),
        'monitor_ids' => [$this->monitor->id],
        ...$overrides,
    ])->assertUnprocessable()->assertJsonValidationErrors($field);
})->with([
    'past start' => [['starts_at' => now()->subDay()->toIso8601String(), 'ends_at' => now()->subDay()->addHour()->toIso8601String()], 'starts_at'],
    'end before start' => [['ends_at' => now()->toIso8601String()], 'ends_at'],
    'foreign monitor' => fn () => [['monitor_ids' => [Monitor::factory()->create()->id]], 'monitor_ids.0'],
    'no monitor' => [['monitor_ids' => []], 'monitor_ids'],
]);

test('occurrences of a series cannot be edited through the API', function () {
    $series = MaintenanceSeries::factory()->create(['organization_id' => $this->organization->id]);
    $occurrence = MaintenanceWindow::factory()->create(['organization_id' => $this->organization->id, 'series_id' => $series->id, 'occurrence_date' => now()->addDay()->toDateString()]);
    $occurrence->monitors()->attach($this->monitor->id);

    $this->withApiToken($this->token)->putJson(route('api.v1.maintenance-windows.update', $occurrence), [
        'title' => 'Changed',
        'starts_at' => $occurrence->starts_at->toIso8601String(),
        'ends_at' => $occurrence->ends_at->toIso8601String(),
        'monitor_ids' => [$this->monitor->id],
    ])->assertUnprocessable();

    expect($occurrence->refresh()->title)->not->toBe('Changed');
});
