<?php

use App\Domain\Maintenance\Models\MaintenanceSeries;
use App\Domain\Maintenance\Models\MaintenanceWindow;
use App\Domain\Monitoring\Models\Monitor;
use App\Domain\Organizations\Enums\OrganizationRole;
use App\Domain\StatusPages\Models\StatusPage;
use App\Http\Requests\Maintenance\SaveMaintenanceSeriesRequest;
use App\Models\User;
use Illuminate\Support\Facades\Cache;
use Inertia\Testing\AssertableInertia as Assert;

beforeEach(function () {
    $this->travelTo('2026-11-22 12:00:00');
    $this->owner = User::factory()->create();
    $this->organization = organizationWith($this->owner, attributes: ['timezone' => 'Europe/Paris']);
    $this->monitor = Monitor::factory()->create(['organization_id' => $this->organization->id]);
});

function seriesPayload(array $overrides = []): array
{
    return [
        'title' => 'Nightly backup',
        'description' => '',
        'frequency' => 'weekly',
        'weekdays' => [1, 3],
        'start_time' => '03:00',
        'duration_minutes' => 90,
        'starts_on' => '2026-11-22',
        'ends_on' => '',
        ...$overrides,
    ];
}

test('members create a weekly series and see it with its occurrences', function () {
    $member = memberOf($this->organization, OrganizationRole::Member);

    $this->actingAs($member)
        ->post(route('organizations.maintenance.series.store', $this->organization), seriesPayload(['monitor_ids' => [$this->monitor->id]]))
        ->assertRedirect(route('organizations.maintenance.index', $this->organization));

    $series = MaintenanceSeries::sole();
    expect($series)
        ->created_by->toBe($member->id)
        ->weekdays->toBe([1, 3])
        ->month_day->toBeNull()
        ->duration_minutes->toBe(90)
        ->ends_on->toBeNull()
        ->and($series->occurrences()->count())->toBe(4);

    $this->actingAs($member)->get(route('organizations.maintenance.index', $this->organization))
        ->assertInertia(fn (Assert $page) => $page
            ->component('maintenance/Index')
            ->where('timezone', 'Europe/Paris')
            ->has('series', 1)
            ->where('series.0.frequency', 'weekly')
            ->where('series.0.start_time', '03:00')
            ->has('upcoming', 4)
            ->where('upcoming.0.series_id', $series->id));
});

test('viewers cannot manage series', function () {
    $viewer = memberOf($this->organization, OrganizationRole::Viewer);
    $series = MaintenanceSeries::factory()->create(['organization_id' => $this->organization->id]);

    $this->actingAs($viewer)->get(route('organizations.maintenance.series.create', $this->organization))->assertForbidden();
    $this->actingAs($viewer)->post(route('organizations.maintenance.series.store', $this->organization), seriesPayload(['monitor_ids' => [$this->monitor->id]]))->assertForbidden();
    $this->actingAs($viewer)->get(route('organizations.maintenance.series.edit', [$this->organization, $series]))->assertForbidden();
    $this->actingAs($viewer)->put(route('organizations.maintenance.series.update', [$this->organization, $series]), seriesPayload(['monitor_ids' => [$this->monitor->id]]))->assertForbidden();
    $this->actingAs($viewer)->delete(route('organizations.maintenance.series.destroy', [$this->organization, $series]))->assertForbidden();

    expect(MaintenanceSeries::count())->toBe(1);
});

test('series of another organization are not reachable', function () {
    $foreign = MaintenanceSeries::factory()->create();
    $foreignMonitor = Monitor::factory()->create();

    $this->actingAs($this->owner)->get(route('organizations.maintenance.series.edit', [$this->organization, $foreign]))->assertNotFound();
    $this->actingAs($this->owner)->delete(route('organizations.maintenance.series.destroy', [$this->organization, $foreign]))->assertNotFound();

    $this->actingAs($this->owner)
        ->post(route('organizations.maintenance.series.store', $this->organization), seriesPayload(['monitor_ids' => [$foreignMonitor->id]]))
        ->assertSessionHasErrors('monitor_ids.0');

    expect(MaintenanceSeries::count())->toBe(1);
});

test('series rules are validated', function (array $overrides, string $field) {
    $this->actingAs($this->owner)
        ->post(route('organizations.maintenance.series.store', $this->organization), seriesPayload(['monitor_ids' => [$this->monitor->id], ...$overrides]))
        ->assertSessionHasErrors($field);

    expect(MaintenanceSeries::count())->toBe(0);
})->with([
    'unknown frequency' => [['frequency' => 'yearly'], 'frequency'],
    'weekly without days' => [['weekdays' => []], 'weekdays'],
    'invalid weekday' => [['weekdays' => [8]], 'weekdays.0'],
    'monthly without day' => [['frequency' => 'monthly'], 'month_day'],
    'monthly day 32' => [['frequency' => 'monthly', 'month_day' => 32], 'month_day'],
    'bad time' => [['start_time' => '25:00'], 'start_time'],
    'too short' => [['duration_minutes' => 1], 'duration_minutes'],
    'too long' => [['duration_minutes' => 168 * 60 + 1], 'duration_minutes'],
    'start in the past' => [['starts_on' => '2026-11-21'], 'starts_on'],
    'end before start' => [['ends_on' => '2026-11-20'], 'ends_on'],
    'no monitors' => [['monitor_ids' => []], 'monitor_ids'],
]);

test('the number of series per organization is bounded', function () {
    MaintenanceSeries::factory()->count(SaveMaintenanceSeriesRequest::MAX_SERIES)->create(['organization_id' => $this->organization->id, 'starts_on' => '2027-01-01']);

    $this->actingAs($this->owner)
        ->post(route('organizations.maintenance.series.store', $this->organization), seriesPayload(['monitor_ids' => [$this->monitor->id]]))
        ->assertSessionHasErrors('title');
});

test('updating and deleting a series through HTTP', function () {
    $this->actingAs($this->owner)->post(route('organizations.maintenance.series.store', $this->organization), seriesPayload(['monitor_ids' => [$this->monitor->id]]));
    $series = MaintenanceSeries::sole();

    $this->actingAs($this->owner)->get(route('organizations.maintenance.series.edit', [$this->organization, $series]))
        ->assertInertia(fn (Assert $page) => $page
            ->component('maintenance/series/Edit')
            ->where('timezone', 'Europe/Paris')
            ->where('series.weekdays', [1, 3]));

    $this->actingAs($this->owner)
        ->put(route('organizations.maintenance.series.update', [$this->organization, $series]), seriesPayload([
            'frequency' => 'monthly',
            'month_day' => 31,
            'monitor_ids' => [$this->monitor->id],
        ]))
        ->assertRedirect(route('organizations.maintenance.index', $this->organization));

    expect($series->fresh())->weekdays->toBeNull()->month_day->toBe(31)
        ->and($series->occurrences()->pluck('occurrence_date')->map->toDateString()->all())->toBe(['2026-11-30']);

    $this->actingAs($this->owner)->delete(route('organizations.maintenance.series.destroy', [$this->organization, $series]))
        ->assertRedirect(route('organizations.maintenance.index', $this->organization));

    expect(MaintenanceSeries::count())->toBe(0)->and(MaintenanceWindow::count())->toBe(0);
});

test('an occurrence is skipped with cancel and edited through its series', function () {
    $this->actingAs($this->owner)->post(route('organizations.maintenance.series.store', $this->organization), seriesPayload(['monitor_ids' => [$this->monitor->id]]));
    $series = MaintenanceSeries::sole();
    $occurrence = $series->occurrences()->orderBy('starts_at')->first();

    $this->actingAs($this->owner)->get(route('organizations.maintenance.edit', [$this->organization, $occurrence]))
        ->assertRedirect(route('organizations.maintenance.series.edit', [$this->organization, $series]));

    $this->actingAs($this->owner)->post(route('organizations.maintenance.cancel', [$this->organization, $occurrence]))
        ->assertSessionHas('inertia.flash_data.toast.message', 'Occurrence skipped.');

    expect($occurrence->fresh()->cancelled_at)->not->toBeNull();

    $this->artisan('veyra:maintenance:materialize');

    expect($series->occurrences()->where('occurrence_date', $occurrence->occurrence_date)->count())->toBe(1);
});

test('status pages show only the next occurrence of a series', function () {
    $page = StatusPage::factory()->create(['organization_id' => $this->organization->id, 'slug' => 'acme']);
    $page->components()->create(['monitor_id' => $this->monitor->id, 'display_name' => 'API']);

    $this->actingAs($this->owner)->post(route('organizations.maintenance.series.store', $this->organization), seriesPayload([
        'frequency' => 'daily',
        'monitor_ids' => [$this->monitor->id],
    ]));
    $oneOff = MaintenanceWindow::factory()->create(['organization_id' => $this->organization->id, 'title' => 'Upgrade', 'starts_at' => '2026-11-24 10:00:00', 'ends_at' => '2026-11-24 11:00:00']);
    $oneOff->monitors()->attach($this->monitor->id);
    Cache::flush();

    $this->get('/status/acme')->assertInertia(fn (Assert $page) => $page
        ->has('snapshot.maintenance', 2)
        ->where('snapshot.maintenance.0.title', 'Nightly backup')
        ->where('snapshot.maintenance.0.recurring', true)
        ->where('snapshot.maintenance.0.starts_at', '2026-11-23T02:00:00+00:00')
        ->where('snapshot.maintenance.1.title', 'Upgrade')
        ->where('snapshot.maintenance.1.recurring', false));
});

test('owners and admins set the organization time zone', function () {
    $this->actingAs($this->owner)
        ->patch(route('organizations.update', $this->organization), ['name' => 'Acme', 'timezone' => 'America/New_York'])
        ->assertRedirect();

    expect($this->organization->fresh()->timezone)->toBe('America/New_York');

    $this->actingAs($this->owner)
        ->patch(route('organizations.update', $this->organization), ['name' => 'Acme', 'timezone' => 'Mars/Olympus'])
        ->assertSessionHasErrors('timezone');

    $member = memberOf($this->organization, OrganizationRole::Member);
    $this->actingAs($member)
        ->patch(route('organizations.update', $this->organization), ['name' => 'Acme', 'timezone' => 'UTC'])
        ->assertForbidden();

    expect($this->organization->fresh()->timezone)->toBe('America/New_York');

    $this->actingAs($this->owner)->get(route('organizations.edit', $this->organization))
        ->assertInertia(fn (Assert $page) => $page
            ->where('organization.timezone', 'America/New_York')
            ->has('timezones'));
});
