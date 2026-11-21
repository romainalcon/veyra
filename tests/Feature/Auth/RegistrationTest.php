<?php

use App\Domain\Identity\Services\RegistrationGate;
use App\Domain\Organizations\Models\OrganizationInvitation;
use App\Models\User;

test('the registration screen can be rendered', function () {
    $this->get(route('register'))->assertOk();
});

test('new users can register', function () {
    $this->post(route('register.store'), [
        'name' => 'Test User',
        'email' => 'Test@Example.com',
        'password' => 'password',
        'password_confirmation' => 'password',
    ])->assertRedirect(route('dashboard', absolute: false));

    $this->assertAuthenticated();

    $user = User::sole();
    expect($user->email)->toBe('test@example.com')
        ->and(strlen($user->id))->toBe(36);
});

test('registration validates its input', function () {
    $this->post(route('register.store'), [
        'name' => '',
        'email' => 'not-an-email',
        'password' => 'password',
        'password_confirmation' => 'different',
    ])->assertSessionHasErrors(['name', 'email', 'password']);

    $this->assertGuest();
});

test('e-mail addresses are unique regardless of case', function () {
    User::factory()->create(['email' => 'taken@example.com']);

    $this->post(route('register.store'), [
        'name' => 'Someone',
        'email' => 'TAKEN@example.com',
        'password' => 'password',
        'password_confirmation' => 'password',
    ])->assertSessionHasErrors('email');
});

describe('when public registration is closed', function () {
    beforeEach(fn () => config(['veyra.registration_enabled' => false]));

    test('the registration screen is not available', function () {
        $this->get(route('register'))->assertNotFound();
    });

    test('accounts cannot be created', function () {
        $this->post(route('register.store'), [
            'name' => 'Intruder',
            'email' => 'intruder@example.com',
            'password' => 'password',
            'password_confirmation' => 'password',
        ])->assertForbidden();

        expect(User::count())->toBe(0);
    });

    test('invited people may still register with the invited e-mail', function () {
        OrganizationInvitation::factory()->withToken('plain-token')->create(['email' => 'invitee@example.com']);

        $this->withSession([RegistrationGate::SESSION_INVITATION_TOKEN => 'plain-token'])
            ->get(route('register'))
            ->assertOk();

        $this->post(route('register.store'), [
            'name' => 'Invitee',
            'email' => 'invitee@example.com',
            'password' => 'password',
            'password_confirmation' => 'password',
        ])->assertRedirect();

        $this->assertAuthenticated();
    });

    test('an expired invitation does not open registration', function () {
        OrganizationInvitation::factory()->expired()->create(['email' => 'late@example.com']);

        $this->post(route('register.store'), [
            'name' => 'Late',
            'email' => 'late@example.com',
            'password' => 'password',
            'password_confirmation' => 'password',
        ])->assertForbidden();
    });
});
