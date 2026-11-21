<?php

use App\Domain\Organizations\Enums\OrganizationRole;
use App\Domain\Organizations\Models\Organization;
use App\Models\User;
use Inertia\Testing\AssertableInertia as Assert;

test('users without an organization are sent to onboarding', function () {
    $this->actingAs(User::factory()->create())
        ->get(route('dashboard'))
        ->assertRedirect(route('organizations.create'));
});

test('users are sent to their organization dashboard', function () {
    $user = User::factory()->create();
    $organization = organizationWith($user);

    $this->actingAs($user)
        ->get(route('dashboard'))
        ->assertRedirect(route('organizations.dashboard', $organization));
});

test('the last visited organization is remembered', function () {
    $user = User::factory()->create();
    organizationWith($user, attributes: ['name' => 'Alpha']);
    $beta = organizationWith($user, attributes: ['name' => 'Beta']);

    $this->actingAs($user)->get(route('organizations.dashboard', $beta))->assertOk();

    $this->get(route('dashboard'))->assertRedirect(route('organizations.dashboard', $beta));
});

test('a user can create an organization and becomes its owner', function () {
    $user = User::factory()->create();

    $response = $this->actingAs($user)->post(route('organizations.store'), ['name' => 'Acme Monitoring']);

    $organization = Organization::sole();
    $response->assertRedirect(route('organizations.dashboard', $organization));

    expect($organization->name)->toBe('Acme Monitoring')
        ->and($organization->slug)->toBe('acme-monitoring')
        ->and($user->roleIn($organization))->toBe(OrganizationRole::Owner);
});

test('organization slugs are unique', function () {
    $user = User::factory()->create();

    $this->actingAs($user)->post(route('organizations.store'), ['name' => 'Acme']);
    $this->actingAs($user)->post(route('organizations.store'), ['name' => 'Acme']);

    $slugs = Organization::pluck('slug');
    expect($slugs)->toHaveCount(2)
        ->and($slugs->unique())->toHaveCount(2);
});

test('organization creation is validated', function () {
    $this->actingAs(User::factory()->create())
        ->post(route('organizations.store'), ['name' => ''])
        ->assertSessionHasErrors('name');

    expect(Organization::count())->toBe(0);
});

test('unverified users cannot create organizations', function () {
    $this->actingAs(User::factory()->unverified()->create())
        ->post(route('organizations.store'), ['name' => 'Acme'])
        ->assertRedirect(route('verification.notice'));
});

test('the dashboard shares the current organization without sensitive data', function () {
    $user = User::factory()->create();
    $organization = organizationWith($user, OrganizationRole::Admin);

    $this->actingAs($user)
        ->get(route('organizations.dashboard', $organization))
        ->assertInertia(fn (Assert $page) => $page
            ->component('organizations/Dashboard')
            ->where('currentOrganization.slug', $organization->slug)
            ->where('currentOrganization.role', 'admin')
            ->where('currentOrganization.can.update', true)
            ->where('currentOrganization.can.delete', false)
            ->where('auth.user.id', $user->id)
            ->missing('auth.user.password')
            ->missing('auth.user.remember_token')
            ->has('organizations', 1));
});

test('admins can rename the organization but the slug is immutable', function () {
    $user = User::factory()->create();
    $organization = organizationWith($user, OrganizationRole::Admin, ['slug' => 'acme']);

    $this->actingAs($user)
        ->patch(route('organizations.update', $organization), ['name' => 'Renamed', 'slug' => 'hijack'])
        ->assertRedirect(route('organizations.edit', $organization));

    expect($organization->fresh())
        ->name->toBe('Renamed')
        ->slug->toBe('acme');
});

test('members and viewers cannot update the organization', function (OrganizationRole $role) {
    $user = User::factory()->create();
    $organization = organizationWith($user, $role, ['name' => 'Original']);

    $this->actingAs($user)
        ->patch(route('organizations.update', $organization), ['name' => 'Renamed'])
        ->assertForbidden();

    expect($organization->fresh()->name)->toBe('Original');
})->with([OrganizationRole::Member, OrganizationRole::Viewer]);

test('only owners can delete the organization, with confirmation', function () {
    $owner = User::factory()->create();
    $organization = organizationWith($owner);
    $admin = memberOf($organization, OrganizationRole::Admin);

    $this->actingAs($admin)
        ->delete(route('organizations.destroy', $organization), ['confirmation' => $organization->slug])
        ->assertForbidden();

    $this->actingAs($owner)
        ->delete(route('organizations.destroy', $organization), ['confirmation' => 'wrong'])
        ->assertSessionHasErrors('confirmation');

    $this->actingAs($owner)
        ->delete(route('organizations.destroy', $organization), ['confirmation' => $organization->slug])
        ->assertRedirect(route('dashboard'));

    expect(Organization::find($organization->id))->toBeNull();
});
