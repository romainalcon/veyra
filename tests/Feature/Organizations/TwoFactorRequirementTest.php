<?php

use App\Domain\Organizations\Enums\OrganizationRole;
use App\Domain\Organizations\Models\Organization;
use App\Domain\Organizations\Models\OrganizationMember;
use App\Models\User;
use Illuminate\Support\Facades\Broadcast;
use Inertia\Testing\AssertableInertia as Assert;

function authorizeOrganizationChannel(User $user, Organization $organization): bool
{
    $callback = Broadcast::driver()->getChannels()->get('organizations.{organizationId}');

    return $callback($user, $organization->id);
}

test('owners with two-factor authentication can require it', function () {
    $owner = User::factory()->withTwoFactor()->create();
    $organization = organizationWith($owner);

    $this->actingAs($owner)
        ->put(route('organizations.two-factor.update', $organization), ['require_two_factor' => '1'])
        ->assertRedirect(route('organizations.edit', $organization))
        ->assertSessionHasNoErrors();

    expect($organization->fresh()->require_two_factor)->toBeTrue();

    $this->put(route('organizations.two-factor.update', $organization), ['require_two_factor' => '0']);
    expect($organization->fresh()->require_two_factor)->toBeFalse();
});

test('owners without two-factor authentication cannot require it', function () {
    $owner = User::factory()->create();
    $organization = organizationWith($owner, OrganizationRole::Owner, ['locale' => 'fr']);

    $this->actingAs($owner)
        ->put(route('organizations.two-factor.update', $organization), ['require_two_factor' => '1'])
        ->assertSessionHasErrors(['require_two_factor' => 'Activez d’abord la double authentification sur votre propre compte.']);

    expect($organization->fresh()->require_two_factor)->toBeFalse();
});

test('only owners change the requirement', function (OrganizationRole $role) {
    $organization = Organization::factory()->create();
    $user = User::factory()->withTwoFactor()->create();
    $organization->members()->create(['user_id' => $user->id, 'role' => $role, 'joined_at' => now()]);

    $this->actingAs($user)
        ->put(route('organizations.two-factor.update', $organization), ['require_two_factor' => '1'])
        ->assertForbidden();

    expect($organization->fresh()->require_two_factor)->toBeFalse();
})->with([OrganizationRole::Admin, OrganizationRole::Member, OrganizationRole::Viewer]);

test('owners cannot change the requirement of another organization', function () {
    $owner = User::factory()->withTwoFactor()->create();
    organizationWith($owner);
    $other = Organization::factory()->create();

    $this->actingAs($owner)
        ->put(route('organizations.two-factor.update', $other), ['require_two_factor' => '1'])
        ->assertNotFound();

    expect($other->fresh()->require_two_factor)->toBeFalse();
});

test('members without two-factor authentication are sent to their security settings', function () {
    $organization = Organization::factory()->create(['require_two_factor' => true, 'name' => 'Strict Co']);
    $member = memberOf($organization, OrganizationRole::Admin);

    $this->actingAs($member);

    $this->get(route('organizations.dashboard', $organization))->assertRedirect(route('security.edit'));
    $this->get(route('organizations.monitors.index', $organization))->assertRedirect(route('security.edit'));
    $this->post(route('organizations.invitations.store', $organization), ['email' => 'new@example.com', 'role' => 'member'])
        ->assertRedirect(route('security.edit'));

    expect($organization->invitations()->count())->toBe(0);
});

test('the redirect explains the requirement in the organization language', function () {
    $organization = Organization::factory()->create(['require_two_factor' => true, 'name' => 'Strict Co', 'locale' => 'fr']);
    $member = memberOf($organization, OrganizationRole::Member);

    $this->actingAs($member)
        ->get(route('organizations.dashboard', $organization))
        ->assertInertiaFlash('toast.message', 'Strict Co exige la double authentification. Activez-la pour continuer.');
});

test('members with two-factor authentication keep their access', function () {
    $organization = Organization::factory()->create(['require_two_factor' => true]);
    $member = User::factory()->withTwoFactor()->create();
    $organization->members()->create(['user_id' => $member->id, 'role' => OrganizationRole::Member, 'joined_at' => now()]);

    $this->actingAs($member)->get(route('organizations.dashboard', $organization))->assertOk();
});

test('other organizations of the member stay reachable', function () {
    $strict = Organization::factory()->create(['require_two_factor' => true]);
    $member = memberOf($strict, OrganizationRole::Member);
    $relaxed = organizationWith($member, OrganizationRole::Member);

    $this->actingAs($member)->get(route('organizations.dashboard', $relaxed))->assertOk();
});

test('non-members still get a 404, never the two-factor redirect', function () {
    $organization = Organization::factory()->create(['require_two_factor' => true]);

    $this->actingAs(User::factory()->create())
        ->get(route('organizations.dashboard', $organization))
        ->assertNotFound();
});

test('members without two-factor authentication can still leave the organization', function () {
    $organization = Organization::factory()->create(['require_two_factor' => true]);
    $owner = User::factory()->withTwoFactor()->create();
    $organization->members()->create(['user_id' => $owner->id, 'role' => OrganizationRole::Owner, 'joined_at' => now()]);
    $member = memberOf($organization, OrganizationRole::Member);
    $other = OrganizationMember::query()->where('organization_id', $organization->id)->where('user_id', $owner->id)->sole();
    $own = OrganizationMember::query()->where('organization_id', $organization->id)->where('user_id', $member->id)->sole();

    $this->actingAs($member);

    $this->delete(route('organizations.members.destroy', [$organization, $other]))->assertRedirect(route('security.edit'));
    $this->delete(route('organizations.members.destroy', [$organization, $own]));

    expect($member->membershipIn($organization))->toBeNull()
        ->and($owner->membershipIn($organization))->not->toBeNull();
});

test('real-time channels follow the requirement', function () {
    $organization = Organization::factory()->create(['require_two_factor' => true]);
    $without = memberOf($organization, OrganizationRole::Member);
    $with = User::factory()->withTwoFactor()->create();
    $organization->members()->create(['user_id' => $with->id, 'role' => OrganizationRole::Member, 'joined_at' => now()]);
    $relaxed = organizationWith($without, OrganizationRole::Member);

    expect(authorizeOrganizationChannel($without, $organization))->toBeFalse()
        ->and(authorizeOrganizationChannel($with, $organization))->toBeTrue()
        ->and(authorizeOrganizationChannel($without, $relaxed))->toBeTrue()
        ->and(authorizeOrganizationChannel(User::factory()->withTwoFactor()->create(), $organization))->toBeFalse();
});

test('the settings page tells owners how many members would be affected', function () {
    $owner = User::factory()->withTwoFactor()->create();
    $organization = organizationWith($owner);
    memberOf($organization, OrganizationRole::Member);
    memberOf($organization, OrganizationRole::Viewer);

    $this->actingAs($owner)
        ->get(route('organizations.edit', $organization))
        ->assertInertia(fn (Assert $page) => $page
            ->where('organization.require_two_factor', false)
            ->where('twoFactor.canRequire', true)
            ->where('twoFactor.ownEnabled', true)
            ->where('twoFactor.membersWithout', 2));

    $this->actingAs(memberOf($organization, OrganizationRole::Admin))
        ->get(route('organizations.edit', $organization))
        ->assertInertia(fn (Assert $page) => $page
            ->where('twoFactor.canRequire', false)
            ->where('twoFactor.membersWithout', null));
});
