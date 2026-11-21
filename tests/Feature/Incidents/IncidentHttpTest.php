<?php

use App\Domain\Incidents\Enums\IncidentEventType;
use App\Domain\Incidents\Models\Incident;
use App\Domain\Monitoring\Models\Monitor;
use App\Domain\Organizations\Enums\OrganizationRole;
use App\Models\User;
use Inertia\Testing\AssertableInertia as Assert;

beforeEach(function () {
    $this->member = User::factory()->create();
    $this->organization = organizationWith($this->member, OrganizationRole::Member);
    $this->monitor = Monitor::factory()->create(['organization_id' => $this->organization->id]);
    $this->incident = Incident::factory()->create(['monitor_id' => $this->monitor->id]);
});

test('members list incidents with open ones first and can filter', function () {
    Incident::factory()->resolved()->create(['monitor_id' => $this->monitor->id, 'started_at' => now()]);

    $this->actingAs($this->member)
        ->get(route('organizations.incidents.index', $this->organization))
        ->assertInertia(fn (Assert $page) => $page
            ->component('incidents/Index')
            ->has('incidents.data', 2)
            ->where('incidents.data.0.status', 'open'));

    $this->get(route('organizations.incidents.index', [$this->organization, 'status' => 'resolved']))
        ->assertInertia(fn (Assert $page) => $page->has('incidents.data', 1)->where('filter', 'resolved'));
});

test('the incident page shows the timeline', function () {
    $this->incident->events()->create(['type' => IncidentEventType::Opened, 'created_at' => now()]);

    $this->actingAs($this->member)
        ->get(route('organizations.incidents.show', [$this->organization, $this->incident]))
        ->assertInertia(fn (Assert $page) => $page
            ->component('incidents/Show')
            ->where('incident.id', $this->incident->id)
            ->where('incident.monitor.name', $this->monitor->name)
            ->has('incident.events', 1)
            ->where('can.acknowledge', true));
});

test('acknowledging is idempotent', function () {
    $this->actingAs($this->member);

    $this->post(route('organizations.incidents.acknowledge', [$this->organization, $this->incident]))->assertRedirect();
    $this->post(route('organizations.incidents.acknowledge', [$this->organization, $this->incident]))->assertRedirect();

    $incident = $this->incident->fresh();
    expect($incident->acknowledged_at)->not->toBeNull()
        ->and($incident->acknowledged_by)->toBe($this->member->id)
        ->and($incident->events()->where('type', IncidentEventType::Acknowledged)->count())->toBe(1);
});

test('notes are validated and recorded', function () {
    $this->actingAs($this->member);

    $this->post(route('organizations.incidents.notes.store', [$this->organization, $this->incident]), ['message' => ''])
        ->assertSessionHasErrors('message');
    $this->post(route('organizations.incidents.notes.store', [$this->organization, $this->incident]), ['message' => str_repeat('a', 2001)])
        ->assertSessionHasErrors('message');
    $this->post(route('organizations.incidents.notes.store', [$this->organization, $this->incident]), ['message' => 'Rolled back the deploy.'])
        ->assertSessionHasNoErrors();

    $note = $this->incident->events()->where('type', IncidentEventType::Note)->sole();
    expect($note->message)->toBe('Rolled back the deploy.')
        ->and($note->user_id)->toBe($this->member->id);
});

test('viewers can read but not acknowledge or comment', function () {
    $viewer = memberOf($this->organization, OrganizationRole::Viewer);

    $this->actingAs($viewer)
        ->get(route('organizations.incidents.show', [$this->organization, $this->incident]))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page->where('can.acknowledge', false)->where('can.addNote', false));

    $this->post(route('organizations.incidents.acknowledge', [$this->organization, $this->incident]))->assertForbidden();
    $this->post(route('organizations.incidents.notes.store', [$this->organization, $this->incident]), ['message' => 'x'])->assertForbidden();

    expect($this->incident->fresh()->acknowledged_at)->toBeNull();
});

test('incidents of another organization are not reachable', function () {
    $stranger = User::factory()->create();
    $foreignOrganization = organizationWith($stranger);
    $foreignIncident = Incident::factory()->create([
        'monitor_id' => Monitor::factory()->create(['organization_id' => $foreignOrganization->id])->id,
    ]);

    $this->actingAs($this->member);
    $this->get(route('organizations.incidents.show', [$this->organization, $foreignIncident]))->assertNotFound();
    $this->post(route('organizations.incidents.acknowledge', [$this->organization, $foreignIncident]))->assertNotFound();
    $this->post(route('organizations.incidents.notes.store', [$this->organization, $foreignIncident]), ['message' => 'x'])->assertNotFound();
    $this->get(route('organizations.incidents.index', $foreignOrganization))->assertNotFound();

    $this->get(route('organizations.incidents.index', $this->organization))
        ->assertInertia(fn (Assert $page) => $page->has('incidents.data', 1)->where('incidents.data.0.id', $this->incident->id));

    expect($foreignIncident->fresh()->acknowledged_at)->toBeNull();
});

test('the open incident count is shared for the sidebar', function () {
    $this->actingAs($this->member)
        ->get(route('organizations.dashboard', $this->organization))
        ->assertInertia(fn (Assert $page) => $page
            ->where('currentOrganization.openIncidents', 1)
            ->has('openIncidents', 1)
            ->where('monitorCounts.byStatus.unknown', 1));
});
