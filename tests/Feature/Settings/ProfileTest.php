<?php

use App\Domain\Organizations\Enums\OrganizationRole;
use App\Domain\Organizations\Models\Organization;
use App\Models\User;

test('the profile page is displayed', function () {
    $this->actingAs(User::factory()->create())
        ->get(route('profile.edit'))
        ->assertOk();
});

test('profile information can be updated', function () {
    $user = User::factory()->create();

    $this->actingAs($user)->patch(route('profile.update'), [
        'name' => 'Updated Name',
        'email' => 'Updated@Example.com',
    ])->assertSessionHasNoErrors()->assertRedirect(route('profile.edit'));

    $user->refresh();
    expect($user->name)->toBe('Updated Name')
        ->and($user->email)->toBe('updated@example.com')
        ->and($user->email_verified_at)->toBeNull();
});

test('verification status is kept when the e-mail is unchanged', function () {
    $user = User::factory()->create();

    $this->actingAs($user)->patch(route('profile.update'), [
        'name' => 'Updated Name',
        'email' => $user->email,
    ])->assertSessionHasNoErrors();

    expect($user->fresh()->email_verified_at)->not->toBeNull();
});

test('users can delete their account and their sole-member organizations', function () {
    $user = User::factory()->create();
    $organization = organizationWith($user);

    $this->actingAs($user)
        ->delete(route('profile.destroy'), ['password' => 'password'])
        ->assertSessionHasNoErrors()
        ->assertRedirect('/');

    $this->assertGuest();
    expect(User::find($user->id))->toBeNull()
        ->and(Organization::find($organization->id))->toBeNull();
});

test('the correct password is required to delete the account', function () {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->from(route('profile.edit'))
        ->delete(route('profile.destroy'), ['password' => 'wrong-password'])
        ->assertSessionHasErrors('password');

    expect($user->fresh())->not->toBeNull();
});

test('the last owner of a shared organization cannot delete their account', function () {
    $owner = User::factory()->create();
    $organization = organizationWith($owner);
    memberOf($organization, OrganizationRole::Member);

    $this->actingAs($owner)
        ->from(route('profile.edit'))
        ->delete(route('profile.destroy'), ['password' => 'password'])
        ->assertSessionHasErrors('password');

    expect($owner->fresh())->not->toBeNull()
        ->and($organization->fresh())->not->toBeNull();
});

test('an owner can delete their account when another owner remains', function () {
    $owner = User::factory()->create();
    $organization = organizationWith($owner);
    memberOf($organization, OrganizationRole::Owner);

    $this->actingAs($owner)
        ->delete(route('profile.destroy'), ['password' => 'password'])
        ->assertSessionHasNoErrors();

    expect($owner->fresh())->toBeNull()
        ->and($organization->fresh())->not->toBeNull()
        ->and($organization->members()->count())->toBe(1);
});
