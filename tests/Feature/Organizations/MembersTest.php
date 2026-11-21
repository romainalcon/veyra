<?php

use App\Domain\Organizations\Enums\OrganizationRole;
use App\Models\User;
use Inertia\Testing\AssertableInertia as Assert;

beforeEach(function () {
    $this->owner = User::factory()->create();
    $this->organization = organizationWith($this->owner);
});

test('every member can list members but only managers see invitations', function () {
    $viewer = memberOf($this->organization, OrganizationRole::Viewer);

    $this->actingAs($viewer)
        ->get(route('organizations.members.index', $this->organization))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('organizations/Members')
            ->has('members', 2)
            ->where('invitations', [])
            ->where('assignableRoles', []));

    $this->actingAs($this->owner)
        ->get(route('organizations.members.index', $this->organization))
        ->assertInertia(fn (Assert $page) => $page->has('assignableRoles', 4));
});

test('an owner can change a member role', function () {
    $member = memberOf($this->organization, OrganizationRole::Member);
    $membership = $member->membershipIn($this->organization);

    $this->actingAs($this->owner)
        ->patch(route('organizations.members.update', [$this->organization, $membership]), ['role' => 'admin'])
        ->assertRedirect(route('organizations.members.index', $this->organization));

    expect($membership->fresh()->role)->toBe(OrganizationRole::Admin);
});

test('admins cannot promote to owner nor touch owners', function () {
    $admin = memberOf($this->organization, OrganizationRole::Admin);
    $member = memberOf($this->organization, OrganizationRole::Member);
    $ownerMembership = $this->owner->membershipIn($this->organization);
    $memberMembership = $member->membershipIn($this->organization);

    $this->actingAs($admin)
        ->patch(route('organizations.members.update', [$this->organization, $memberMembership]), ['role' => 'owner'])
        ->assertForbidden();

    $this->actingAs($admin)
        ->patch(route('organizations.members.update', [$this->organization, $ownerMembership]), ['role' => 'viewer'])
        ->assertForbidden();

    $this->actingAs($admin)
        ->delete(route('organizations.members.destroy', [$this->organization, $ownerMembership]))
        ->assertForbidden();

    expect($ownerMembership->fresh()->role)->toBe(OrganizationRole::Owner)
        ->and($memberMembership->fresh()->role)->toBe(OrganizationRole::Member);
});

test('members and viewers cannot manage members', function (OrganizationRole $role) {
    $actor = memberOf($this->organization, $role);
    $target = memberOf($this->organization, OrganizationRole::Viewer)->membershipIn($this->organization);

    $this->actingAs($actor)
        ->patch(route('organizations.members.update', [$this->organization, $target]), ['role' => 'member'])
        ->assertForbidden();

    $this->actingAs($actor)
        ->delete(route('organizations.members.destroy', [$this->organization, $target]))
        ->assertForbidden();
})->with([OrganizationRole::Member, OrganizationRole::Viewer]);

test('an invalid role is rejected with a validation error', function () {
    $member = memberOf($this->organization, OrganizationRole::Member)->membershipIn($this->organization);

    $this->actingAs($this->owner)
        ->patch(route('organizations.members.update', [$this->organization, $member]), ['role' => 'superuser'])
        ->assertSessionHasErrors('role');
});

test('the last owner cannot be demoted', function () {
    $ownerMembership = $this->owner->membershipIn($this->organization);

    $this->actingAs($this->owner)
        ->patch(route('organizations.members.update', [$this->organization, $ownerMembership]), ['role' => 'admin'])
        ->assertSessionHasErrors('member');

    expect($ownerMembership->fresh()->role)->toBe(OrganizationRole::Owner);
});

test('the last owner cannot leave the organization', function () {
    $ownerMembership = $this->owner->membershipIn($this->organization);

    $this->actingAs($this->owner)
        ->delete(route('organizations.members.destroy', [$this->organization, $ownerMembership]))
        ->assertSessionHasErrors('member');

    expect($ownerMembership->fresh())->not->toBeNull();
});

test('an owner can step down once another owner exists', function () {
    memberOf($this->organization, OrganizationRole::Owner);
    $ownerMembership = $this->owner->membershipIn($this->organization);

    $this->actingAs($this->owner)
        ->patch(route('organizations.members.update', [$this->organization, $ownerMembership]), ['role' => 'admin'])
        ->assertSessionHasNoErrors();

    expect($ownerMembership->fresh()->role)->toBe(OrganizationRole::Admin);
});

test('any member can leave the organization', function () {
    $viewer = memberOf($this->organization, OrganizationRole::Viewer);
    $membership = $viewer->membershipIn($this->organization);

    $this->actingAs($viewer)
        ->delete(route('organizations.members.destroy', [$this->organization, $membership]))
        ->assertRedirect(route('dashboard'));

    expect($membership->fresh())->toBeNull();

    $this->get(route('organizations.dashboard', $this->organization))->assertNotFound();
});
