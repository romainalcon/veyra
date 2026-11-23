<?php

use App\Domain\Monitoring\Models\Monitor;
use App\Domain\Notifications\Models\EscalationPolicy;
use App\Domain\Notifications\Models\NotificationChannel;
use App\Domain\Organizations\Enums\OrganizationRole;
use App\Domain\Organizations\Models\Organization;
use App\Models\User;
use Inertia\Testing\AssertableInertia as Assert;

beforeEach(function () {
    $this->admin = User::factory()->create();
    $this->organization = organizationWith($this->admin, OrganizationRole::Admin);
    $this->team = NotificationChannel::factory()->create(['organization_id' => $this->organization->id, 'name' => 'Team']);
    $this->manager = NotificationChannel::factory()->create(['organization_id' => $this->organization->id, 'name' => 'Manager']);
});

function policyPayload(array $overrides = []): array
{
    return [
        'name' => 'Production',
        'is_default' => '1',
        'steps' => [
            ['delay_minutes' => 10, 'channel_ids' => [test()->team->id]],
            ['delay_minutes' => 30, 'channel_ids' => [test()->team->id, test()->manager->id]],
        ],
        ...$overrides,
    ];
}

test('admins create, list, update and delete escalation policies', function () {
    $this->actingAs($this->admin)
        ->post(route('organizations.escalation-policies.store', $this->organization), policyPayload())
        ->assertSessionHasNoErrors()
        ->assertRedirect(route('organizations.notification-channels.index', $this->organization));

    $policy = EscalationPolicy::query()->sole();
    expect($policy->is_default)->toBeTrue()
        ->and($policy->steps->pluck('delay_minutes')->all())->toBe([10, 30])
        ->and($policy->steps->pluck('position')->all())->toBe([1, 2])
        ->and($policy->steps[1]->channels->pluck('id')->sort()->values()->all())
        ->toBe(collect([$this->team->id, $this->manager->id])->sort()->values()->all());

    $this->get(route('organizations.notification-channels.index', $this->organization))
        ->assertInertia(fn (Assert $page) => $page
            ->where('canManageEscalation', true)
            ->has('escalationPolicies', 1)
            ->where('escalationPolicies.0.name', 'Production')
            ->where('escalationPolicies.0.steps.1.channels.1.name', fn ($name) => in_array($name, ['Team', 'Manager'], true)));

    $this->put(route('organizations.escalation-policies.update', [$this->organization, $policy]), policyPayload([
        'name' => 'Night',
        'steps' => [['delay_minutes' => 5, 'channel_ids' => [$this->manager->id]]],
    ]))->assertSessionHasNoErrors();

    $policy->refresh();
    expect($policy->name)->toBe('Night')
        ->and($policy->steps)->toHaveCount(1)
        ->and($policy->steps[0]->channels->pluck('id')->all())->toBe([$this->manager->id]);

    $monitor = Monitor::factory()->create(['organization_id' => $this->organization->id, 'escalation_policy_id' => $policy->id]);

    $this->delete(route('organizations.escalation-policies.destroy', [$this->organization, $policy]))->assertRedirect();

    expect(EscalationPolicy::count())->toBe(0)
        ->and($monitor->fresh()->escalation_policy_id)->toBeNull();
});

test('only one policy is the organization default', function () {
    $first = EscalationPolicy::factory()->default()->create(['organization_id' => $this->organization->id]);
    $otherOrganization = EscalationPolicy::factory()->default()->create();

    $this->actingAs($this->admin)
        ->post(route('organizations.escalation-policies.store', $this->organization), policyPayload())
        ->assertSessionHasNoErrors();

    expect($first->fresh()->is_default)->toBeFalse()
        ->and($otherOrganization->fresh()->is_default)->toBeTrue()
        ->and(EscalationPolicy::where('organization_id', $this->organization->id)->where('is_default', true)->count())->toBe(1);
});

test('escalation policy input is validated', function (Closure $payload, string $field) {
    $this->actingAs($this->admin)
        ->post(route('organizations.escalation-policies.store', $this->organization), $payload())
        ->assertSessionHasErrors($field);

    expect(EscalationPolicy::count())->toBe(0);
})->with([
    'missing name' => [fn () => policyPayload(['name' => '']), 'name'],
    'no steps' => [fn () => policyPayload(['steps' => []]), 'steps'],
    'too many steps' => [fn () => policyPayload(['steps' => array_map(fn ($i) => ['delay_minutes' => $i, 'channel_ids' => [test()->team->id]], range(1, 6))]), 'steps'],
    'zero delay' => [fn () => policyPayload(['steps' => [['delay_minutes' => 0, 'channel_ids' => [test()->team->id]]]]), 'steps.0.delay_minutes'],
    'delay above a day' => [fn () => policyPayload(['steps' => [['delay_minutes' => 1441, 'channel_ids' => [test()->team->id]]]]), 'steps.0.delay_minutes'],
    'delays not increasing' => [fn () => policyPayload(['steps' => [
        ['delay_minutes' => 30, 'channel_ids' => [test()->team->id]],
        ['delay_minutes' => 30, 'channel_ids' => [test()->team->id]],
    ]]), 'steps.1.delay_minutes'],
    'step without channel' => [fn () => policyPayload(['steps' => [['delay_minutes' => 5, 'channel_ids' => []]]]), 'steps.0.channel_ids'],
    'unknown step key' => [fn () => policyPayload(['steps' => [['delay_minutes' => 5, 'channel_ids' => [test()->team->id], 'extra' => 1]]]), 'steps.0'],
    'channel of another organization' => [fn () => policyPayload(['steps' => [['delay_minutes' => 5, 'channel_ids' => [NotificationChannel::factory()->create()->id]]]]), 'steps.0.channel_ids.0'],
]);

test('members and viewers see policies but cannot manage them', function (OrganizationRole $role) {
    $user = memberOf($this->organization, $role);
    $policy = EscalationPolicy::factory()->create(['organization_id' => $this->organization->id]);

    $this->actingAs($user)
        ->get(route('organizations.notification-channels.index', $this->organization))
        ->assertInertia(fn (Assert $page) => $page->where('canManageEscalation', false)->has('escalationPolicies', 1));

    $this->post(route('organizations.escalation-policies.store', $this->organization), policyPayload())->assertForbidden();
    $this->put(route('organizations.escalation-policies.update', [$this->organization, $policy]), policyPayload())->assertForbidden();
    $this->delete(route('organizations.escalation-policies.destroy', [$this->organization, $policy]))->assertForbidden();

    expect(EscalationPolicy::count())->toBe(1);
})->with([OrganizationRole::Member, OrganizationRole::Viewer]);

test('policies of another organization are not reachable', function () {
    $foreign = EscalationPolicy::factory()->create(['organization_id' => Organization::factory()->create()->id]);

    $this->actingAs($this->admin);
    $this->put(route('organizations.escalation-policies.update', [$this->organization, $foreign]), policyPayload())->assertNotFound();
    $this->delete(route('organizations.escalation-policies.destroy', [$this->organization, $foreign]))->assertNotFound();

    expect($foreign->fresh())->not->toBeNull();
});

test('monitors pick a policy of their own organization; omitting the field keeps it', function () {
    $policy = EscalationPolicy::factory()->create(['organization_id' => $this->organization->id]);
    $foreign = EscalationPolicy::factory()->create();
    $monitor = Monitor::factory()->http(['url' => 'https://example.com'])->create(['organization_id' => $this->organization->id]);
    $payload = ['type' => 'http', 'name' => 'API', 'url' => 'https://example.com', 'method' => 'GET', 'interval' => 60, 'timeout' => 10, 'expected_statuses' => '200-299', 'headers_mode' => 'keep'];

    $this->actingAs($this->admin);

    $this->put(route('organizations.monitors.update', [$this->organization, $monitor]), [...$payload, 'escalation_policy_id' => $foreign->id])
        ->assertSessionHasErrors('escalation_policy_id');

    $this->put(route('organizations.monitors.update', [$this->organization, $monitor]), [...$payload, 'escalation_policy_id' => $policy->id])
        ->assertSessionHasNoErrors();
    expect($monitor->fresh()->escalation_policy_id)->toBe($policy->id);

    $this->put(route('organizations.monitors.update', [$this->organization, $monitor]), $payload)->assertSessionHasNoErrors();
    expect($monitor->fresh()->escalation_policy_id)->toBe($policy->id);

    $this->put(route('organizations.monitors.update', [$this->organization, $monitor]), [...$payload, 'escalation_policy_id' => ''])
        ->assertSessionHasNoErrors();
    expect($monitor->fresh()->escalation_policy_id)->toBeNull();

    $this->get(route('organizations.monitors.edit', [$this->organization, $monitor]))
        ->assertInertia(fn (Assert $page) => $page->has('escalationPolicies', 1)->where('monitor.escalation_policy_id', null));
});

test('the API reads and writes the monitor escalation policy', function () {
    $policy = EscalationPolicy::factory()->create(['organization_id' => $this->organization->id]);
    $monitor = Monitor::factory()->http(['url' => 'https://example.com'])->create(['organization_id' => $this->organization->id]);
    $token = apiTokenFor($this->organization);

    $this->withToken($token)
        ->putJson("/api/v1/monitors/{$monitor->id}", [
            'type' => 'http', 'name' => 'API', 'url' => 'https://example.com', 'method' => 'GET', 'interval' => 60, 'timeout' => 10,
            'expected_statuses' => '200-299', 'headers_mode' => 'keep', 'escalation_policy_id' => $policy->id,
        ])
        ->assertOk()
        ->assertJsonPath('data.escalation_policy_id', $policy->id);
});
