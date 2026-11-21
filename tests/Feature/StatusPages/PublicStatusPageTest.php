<?php

use App\Domain\Incidents\Actions\AddIncidentNote;
use App\Domain\Incidents\Actions\PostPublicIncidentUpdate;
use App\Domain\Incidents\Models\Incident;
use App\Domain\Maintenance\Models\MaintenanceWindow;
use App\Domain\Monitoring\Enums\MonitorStatus;
use App\Domain\Monitoring\Models\Monitor;
use App\Domain\Monitoring\Models\MonitorState;
use App\Domain\Organizations\Enums\OrganizationRole;
use App\Domain\StatusPages\Models\StatusPage;
use App\Models\User;
use Illuminate\Support\Facades\Cache;
use Inertia\Testing\AssertableInertia as Assert;

beforeEach(function () {
    $this->travelTo('2026-11-22 12:00:00');
    $this->owner = User::factory()->create();
    $this->organization = organizationWith($this->owner);
    $this->monitor = Monitor::factory()->create([
        'organization_id' => $this->organization->id,
        'name' => 'INTERNAL-db-primary',
    ]);
    $this->monitor->httpConfig()->update(['url' => 'https://internal.example.com/secret-health?token=abc']);
    $this->page = StatusPage::factory()->create(['organization_id' => $this->organization->id, 'slug' => 'acme']);
    $this->component = $this->page->components()->create([
        'monitor_id' => $this->monitor->id,
        'display_name' => 'API',
        'group_name' => 'Core',
    ]);
});

function setState(Monitor $monitor, MonitorStatus $status): void
{
    MonitorState::whereKey($monitor->id)->update(['status' => $status->value]);
    Cache::flush();
}

test('a published page is served without a session or cookies', function () {
    setState($this->monitor, MonitorStatus::Up);

    $response = $this->get('/status/acme');

    $response->assertOk()
        ->assertHeader('Cache-Control', 'max-age=30, public')
        ->assertInertia(fn (Assert $page) => $page
            ->component('status/Show')
            ->where('snapshot.page.title', 'Acme status')
            ->where('snapshot.status', 'operational')
            ->where('snapshot.groups.0.name', 'Core')
            ->where('snapshot.groups.0.components.0.name', 'API')
            ->where('snapshot.groups.0.components.0.status', 'operational')
            ->missing('auth')
            ->missing('organizations')
            ->missing('currentOrganization'));

    expect($response->headers->getCookies())->toBe([]);
});

test('unpublished and unknown pages are not found', function () {
    StatusPage::factory()->unpublished()->create(['slug' => 'draft']);

    $this->get('/status/draft')->assertNotFound();
    $this->get('/status/nope')->assertNotFound();
});

test('missing data is shown as no data, never operational', function () {
    setState($this->monitor, MonitorStatus::Unknown);

    $this->get('/status/acme')->assertInertia(fn (Assert $page) => $page
        ->where('snapshot.status', 'no_data')
        ->where('snapshot.groups.0.components.0.status', 'no_data'));
});

test('the public payload never leaks internal identifiers, names, URLs, causes or notes', function () {
    setState($this->monitor, MonitorStatus::Down);
    $incident = Incident::factory()->create(['monitor_id' => $this->monitor->id, 'cause' => 'TLS error (secret-cause)']);
    $incident->events()->create(['type' => 'opened', 'created_at' => now()->subMinutes(5)]);
    app(AddIncidentNote::class)->handle($incident, $this->owner, 'INTERNAL NOTE: root password rotated');
    app(PostPublicIncidentUpdate::class)->handle($incident, $this->owner, 'We are investigating API errors.');

    $response = $this->get('/status/acme');
    $html = $response->getContent();

    $response->assertInertia(fn (Assert $page) => $page
        ->where('snapshot.status', 'outage')
        ->where('snapshot.incidents.0.title', 'Outage — API')
        ->where('snapshot.incidents.0.status', 'ongoing')
        ->where('snapshot.incidents.0.updates.0.message', 'We are investigating API errors.'));

    foreach ([$this->monitor->id, $incident->id, $this->organization->id, $this->organization->slug, 'INTERNAL-db-primary', 'internal.example.com', 'secret-cause', 'INTERNAL NOTE', $this->owner->email, $this->owner->name] as $secret) {
        expect($html)->not->toContain($secret);
    }
});

test('incidents of monitors not on the page are not shown', function () {
    $other = Monitor::factory()->create(['organization_id' => $this->organization->id]);
    Incident::factory()->create(['monitor_id' => $other->id]);

    $this->get('/status/acme')->assertInertia(fn (Assert $page) => $page->where('snapshot.incidents', []));
});

test('resolved incidents older than 14 days disappear', function () {
    Incident::factory()->resolved()->create(['monitor_id' => $this->monitor->id, 'started_at' => '2026-11-01 10:00:00', 'resolved_at' => '2026-11-01 11:00:00']);
    Incident::factory()->resolved()->create(['monitor_id' => $this->monitor->id, 'started_at' => '2026-11-20 10:00:00', 'resolved_at' => '2026-11-20 11:00:00']);

    $this->get('/status/acme')->assertInertia(fn (Assert $page) => $page
        ->has('snapshot.incidents', 1)
        ->where('snapshot.incidents.0.status', 'resolved'));
});

test('running and planned maintenance of listed monitors are announced', function () {
    setState($this->monitor, MonitorStatus::Maintenance);
    $window = MaintenanceWindow::factory()->active()->create(['organization_id' => $this->organization->id, 'title' => 'Upgrade']);
    $window->monitors()->attach($this->monitor->id);
    MaintenanceWindow::factory()->create(['organization_id' => $this->organization->id])->monitors()->attach(Monitor::factory()->create(['organization_id' => $this->organization->id])->id);

    $this->get('/status/acme')->assertInertia(fn (Assert $page) => $page
        ->where('snapshot.status', 'maintenance')
        ->has('snapshot.maintenance', 1)
        ->where('snapshot.maintenance.0.title', 'Upgrade')
        ->where('snapshot.maintenance.0.active', true)
        ->where('snapshot.maintenance.0.components', ['API']));
});

test('uptime is hidden when disabled', function () {
    $this->page->update(['show_uptime' => false]);

    $this->get('/status/acme')->assertInertia(fn (Assert $page) => $page
        ->where('snapshot.groups.0.components.0.uptime_90d', null)
        ->where('snapshot.groups.0.components.0.days', []));
});

test('deleted monitors and monitors of another organization are not exposed', function () {
    $foreign = Monitor::factory()->create(['name' => 'FOREIGN']);
    $this->page->components()->create(['monitor_id' => $foreign->id, 'display_name' => 'Foreign']);
    $this->monitor->delete();

    $this->get('/status/acme')->assertInertia(fn (Assert $page) => $page
        ->where('snapshot.groups', [])
        ->where('snapshot.status', 'no_data'));
});

test('public pages are rate limited per IP', function () {
    foreach (range(1, 120) as $ignored) {
        $this->get('/status/acme')->assertOk();
    }

    $this->get('/status/acme')->assertTooManyRequests();
});

test('members post public updates; viewers cannot', function () {
    $incident = Incident::factory()->create(['monitor_id' => $this->monitor->id]);
    $viewer = memberOf($this->organization, OrganizationRole::Viewer);
    $route = route('organizations.incidents.public-updates.store', [$this->organization, $incident]);

    $this->actingAs($viewer)->post($route, ['public_message' => 'Hi'])->assertForbidden();
    $this->actingAs($this->owner)->post($route, ['public_message' => ''])->assertSessionHasErrors('public_message');
    $this->actingAs($this->owner)->post($route, ['public_message' => 'Fix deployed.'])->assertRedirect();

    expect($incident->events()->where('type', 'public_update')->sole()->message)->toBe('Fix deployed.');
});
