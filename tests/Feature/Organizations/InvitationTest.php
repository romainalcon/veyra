<?php

use App\Domain\Identity\Services\RegistrationGate;
use App\Domain\Organizations\Enums\OrganizationRole;
use App\Domain\Organizations\Models\OrganizationInvitation;
use App\Domain\Organizations\Notifications\OrganizationInvitationNotification;
use App\Models\User;
use Illuminate\Contracts\Queue\ShouldBeEncrypted;
use Illuminate\Notifications\AnonymousNotifiable;
use Illuminate\Support\Facades\Notification;
use Inertia\Testing\AssertableInertia as Assert;

beforeEach(function () {
    Notification::fake();
    $this->owner = User::factory()->create();
    $this->organization = organizationWith($this->owner, attributes: ['name' => 'Acme']);
});

function sentInvitationToken(string $email): string
{
    $token = null;

    Notification::assertSentTo(
        new AnonymousNotifiable,
        OrganizationInvitationNotification::class,
        function (OrganizationInvitationNotification $notification, array $channels, AnonymousNotifiable $notifiable) use ($email, &$token) {
            if (($notifiable->routes['mail'] ?? null) !== $email) {
                return false;
            }

            $token = $notification->plainToken;

            return true;
        },
    );

    return $token;
}

test('an owner can invite someone by e-mail', function () {
    $this->actingAs($this->owner)
        ->post(route('organizations.invitations.store', $this->organization), [
            'email' => 'New.Person@Example.com',
            'role' => 'admin',
        ])->assertRedirect(route('organizations.members.index', $this->organization));

    $invitation = OrganizationInvitation::sole();
    $token = sentInvitationToken('new.person@example.com');

    expect($invitation->email)->toBe('new.person@example.com')
        ->and($invitation->role)->toBe(OrganizationRole::Admin)
        ->and($invitation->token_hash)->toBe(hash('sha256', $token))
        ->and($invitation->token_hash)->not->toBe($token)
        ->and($invitation->expires_at->isFuture())->toBeTrue();
});

test('the invitation e-mail is queued, encrypted, on the notifications queue', function () {
    $notification = new OrganizationInvitationNotification('Acme', 'Owner', OrganizationRole::Member, 'token');

    expect($notification)->toBeInstanceOf(ShouldBeEncrypted::class)
        ->and($notification->queue)->toBe('notifications');
});

test('re-inviting the same e-mail refreshes the pending invitation', function () {
    $this->actingAs($this->owner);
    $this->post(route('organizations.invitations.store', $this->organization), ['email' => 'a@example.com', 'role' => 'member']);
    $first = OrganizationInvitation::sole()->token_hash;

    $this->post(route('organizations.invitations.store', $this->organization), ['email' => 'a@example.com', 'role' => 'viewer']);

    $invitation = OrganizationInvitation::sole();
    expect($invitation->token_hash)->not->toBe($first)
        ->and($invitation->role)->toBe(OrganizationRole::Viewer);
});

test('existing members cannot be invited', function () {
    $this->actingAs($this->owner)
        ->post(route('organizations.invitations.store', $this->organization), ['email' => $this->owner->email, 'role' => 'member'])
        ->assertSessionHasErrors('email');

    expect(OrganizationInvitation::count())->toBe(0);
});

test('admins cannot invite owners and members cannot invite at all', function () {
    $admin = memberOf($this->organization, OrganizationRole::Admin);
    $member = memberOf($this->organization, OrganizationRole::Member);

    $this->actingAs($admin)
        ->post(route('organizations.invitations.store', $this->organization), ['email' => 'x@example.com', 'role' => 'owner'])
        ->assertForbidden();

    $this->actingAs($member)
        ->post(route('organizations.invitations.store', $this->organization), ['email' => 'x@example.com', 'role' => 'viewer'])
        ->assertForbidden();

    $this->actingAs($admin)
        ->post(route('organizations.invitations.store', $this->organization), ['email' => 'x@example.com', 'role' => 'admin'])
        ->assertSessionHasNoErrors();

    expect(OrganizationInvitation::sole()->role)->toBe(OrganizationRole::Admin);
});

test('sending invitations is rate limited', function () {
    config(['veyra.invitations.max_per_hour' => 2]);
    $this->actingAs($this->owner);

    foreach (['a', 'b'] as $prefix) {
        $this->post(route('organizations.invitations.store', $this->organization), ['email' => "$prefix@example.com", 'role' => 'member'])
            ->assertSessionHasNoErrors();
    }

    $this->post(route('organizations.invitations.store', $this->organization), ['email' => 'c@example.com', 'role' => 'member'])
        ->assertTooManyRequests();
});

test('the invitation token is never exposed in the members page props', function () {
    $this->actingAs($this->owner)
        ->post(route('organizations.invitations.store', $this->organization), ['email' => 'secret@example.com', 'role' => 'member']);
    $token = sentInvitationToken('secret@example.com');

    $response = $this->get(route('organizations.members.index', $this->organization))
        ->assertInertia(fn (Assert $page) => $page
            ->has('invitations', 1)
            ->missing('invitations.0.token_hash'));

    expect($response->getContent())
        ->not->toContain($token)
        ->not->toContain(hash('sha256', $token));
});

test('the invited user can accept the invitation', function () {
    $invitee = User::factory()->create(['email' => 'invitee@example.com']);
    OrganizationInvitation::factory()->withToken('valid-token')->create([
        'organization_id' => $this->organization->id,
        'email' => 'invitee@example.com',
        'role' => OrganizationRole::Member,
    ]);

    $this->actingAs($invitee)
        ->get(route('invitations.show', 'valid-token'))
        ->assertInertia(fn (Assert $page) => $page
            ->component('invitations/Show')
            ->where('organizationName', 'Acme')
            ->where('emailMatches', true));

    $this->post(route('invitations.accept', 'valid-token'))
        ->assertRedirect(route('organizations.dashboard', $this->organization));

    expect($invitee->roleIn($this->organization))->toBe(OrganizationRole::Member)
        ->and(OrganizationInvitation::count())->toBe(0);
});

test('an invitation cannot be reused', function () {
    $invitee = User::factory()->create(['email' => 'invitee@example.com']);
    OrganizationInvitation::factory()->withToken('once')->create([
        'organization_id' => $this->organization->id,
        'email' => 'invitee@example.com',
    ]);

    $this->actingAs($invitee)->post(route('invitations.accept', 'once'))->assertRedirect();
    $this->actingAs($invitee)->post(route('invitations.accept', 'once'))->assertSessionHasErrors('invitation');

    expect($this->organization->members()->count())->toBe(2);
});

test('an expired invitation is refused', function () {
    $invitee = User::factory()->create(['email' => 'invitee@example.com']);
    OrganizationInvitation::factory()->withToken('expired')->expired()->create([
        'organization_id' => $this->organization->id,
        'email' => 'invitee@example.com',
    ]);

    $this->get(route('invitations.show', 'expired'))->assertNotFound();

    $this->actingAs($invitee)
        ->post(route('invitations.accept', 'expired'))
        ->assertSessionHasErrors('invitation');

    expect($invitee->roleIn($this->organization))->toBeNull();
});

test('an invitation for another e-mail cannot be accepted', function () {
    $intruder = User::factory()->create(['email' => 'intruder@example.com']);
    OrganizationInvitation::factory()->withToken('not-yours')->create([
        'organization_id' => $this->organization->id,
        'email' => 'invitee@example.com',
    ]);

    $this->actingAs($intruder)
        ->post(route('invitations.accept', 'not-yours'))
        ->assertSessionHasErrors('invitation');

    expect($intruder->roleIn($this->organization))->toBeNull()
        ->and(OrganizationInvitation::count())->toBe(1);
});

test('unknown tokens return 404', function () {
    $this->get(route('invitations.show', 'does-not-exist'))->assertNotFound();
});

test('a revoked invitation can no longer be accepted', function () {
    $invitee = User::factory()->create(['email' => 'invitee@example.com']);
    $invitation = OrganizationInvitation::factory()->withToken('revoked')->create([
        'organization_id' => $this->organization->id,
        'email' => 'invitee@example.com',
    ]);

    $this->actingAs($this->owner)
        ->delete(route('organizations.invitations.destroy', [$this->organization, $invitation]))
        ->assertRedirect();

    $this->actingAs($invitee)
        ->post(route('invitations.accept', 'revoked'))
        ->assertSessionHasErrors('invitation');
});

test('guests are guided to log in and resume the invitation afterwards', function () {
    $invitee = User::factory()->create(['email' => 'invitee@example.com']);
    OrganizationInvitation::factory()->withToken('resume')->create([
        'organization_id' => $this->organization->id,
        'email' => 'invitee@example.com',
    ]);

    $this->get(route('invitations.show', 'resume'))
        ->assertInertia(fn (Assert $page) => $page->where('isAuthenticated', false))
        ->assertSessionHas(RegistrationGate::SESSION_INVITATION_TOKEN, 'resume');

    $this->actingAs($invitee)
        ->get(route('dashboard'))
        ->assertRedirect(route('invitations.show', 'resume'));
});
