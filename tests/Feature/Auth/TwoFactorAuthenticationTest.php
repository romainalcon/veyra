<?php

use App\Domain\Organizations\Enums\OrganizationRole;
use App\Models\User;
use Database\Factories\UserFactory;
use Inertia\Testing\AssertableInertia as Assert;
use PragmaRX\Google2FA\Google2FA;

function currentOtp(string $secret = UserFactory::TWO_FACTOR_TEST_SECRET): string
{
    return app(Google2FA::class)->getCurrentOtp($secret);
}

test('a user enables two-factor authentication by confirming a code; recovery codes are shown once', function () {
    $user = User::factory()->create();
    $this->actingAs($user)->withSession(['auth.password_confirmed_at' => time()]);

    $this->from(route('security.edit'))->post(route('two-factor.enable'))->assertRedirect(route('security.edit'));

    $user->refresh();
    expect($user->two_factor_secret)->not->toBeNull()
        ->and($user->two_factor_confirmed_at)->toBeNull()
        ->and($user->hasEnabledTwoFactorAuthentication())->toBeFalse();

    $secret = decrypt($user->two_factor_secret);

    $this->get(route('security.edit'))->assertInertia(fn (Assert $page) => $page
        ->component('settings/Security')
        ->where('twoFactor.enabled', false)
        ->where('twoFactor.pending', true)
        ->where('twoFactor.setupKey', $secret)
        ->where('twoFactor.qrCodeSvg', fn (string $svg) => str_contains($svg, '<svg'))
        ->where('twoFactor.recoveryCodes', null));

    $this->from(route('security.edit'))->post(route('two-factor.confirm'), ['code' => currentOtp($secret)])
        ->assertSessionHasNoErrors();

    expect($user->fresh()->hasEnabledTwoFactorAuthentication())->toBeTrue();

    $this->get(route('security.edit'))->assertInertia(fn (Assert $page) => $page
        ->where('twoFactor.enabled', true)
        ->where('twoFactor.pending', false)
        ->where('twoFactor.setupKey', null)
        ->where('twoFactor.recoveryCodes', fn ($codes) => count($codes) === 8));

    $this->get(route('security.edit'))->assertInertia(fn (Assert $page) => $page->where('twoFactor.recoveryCodes', null));
});

test('a wrong confirmation code leaves two-factor authentication disabled', function () {
    $user = User::factory()->create();
    $this->actingAs($user)->withSession(['auth.password_confirmed_at' => time()]);
    $this->post(route('two-factor.enable'));

    $this->from(route('security.edit'))->post(route('two-factor.confirm'), ['code' => '000000'])
        ->assertSessionHasErrorsIn('confirmTwoFactorAuthentication', 'code');

    expect($user->fresh()->hasEnabledTwoFactorAuthentication())->toBeFalse();
});

test('managing two-factor authentication requires a recent password confirmation', function () {
    $this->actingAs(User::factory()->create())
        ->post(route('two-factor.enable'))
        ->assertRedirect(route('password.confirm'));
});

test('users with two-factor authentication must pass the challenge to sign in', function () {
    $user = User::factory()->withTwoFactor()->create();

    $this->post(route('login.store'), ['email' => $user->email, 'password' => 'password'])
        ->assertRedirect(route('two-factor.login'));
    $this->assertGuest();

    $this->get(route('two-factor.login'))->assertInertia(fn (Assert $page) => $page->component('auth/TwoFactorChallenge'));

    $this->post(route('two-factor.login.store'), ['code' => '000000'])->assertSessionHasErrors('code');
    $this->assertGuest();

    $this->post(route('two-factor.login.store'), ['code' => currentOtp()])->assertRedirect(route('dashboard', absolute: false));
    $this->assertAuthenticatedAs($user);
});

test('a recovery code signs in once and is then replaced', function () {
    $user = User::factory()->withTwoFactor()->create();

    $this->post(route('login.store'), ['email' => $user->email, 'password' => 'password']);
    $this->post(route('two-factor.login.store'), ['recovery_code' => 'recovery-code-one']);

    $this->assertAuthenticatedAs($user);
    expect($user->fresh()->recoveryCodes())->not->toContain('recovery-code-one')->toContain('recovery-code-two');

    auth()->logout();
    $this->post(route('login.store'), ['email' => $user->email, 'password' => 'password']);
    $this->post(route('two-factor.login.store'), ['recovery_code' => 'recovery-code-one'])->assertSessionHasErrors('recovery_code');
    $this->assertGuest();
});

test('the challenge is rate limited', function () {
    $user = User::factory()->withTwoFactor()->create();
    $this->post(route('login.store'), ['email' => $user->email, 'password' => 'password']);

    foreach (range(1, 5) as $attempt) {
        $this->post(route('two-factor.login.store'), ['code' => '000000']);
    }

    $this->post(route('two-factor.login.store'), ['code' => currentOtp()])->assertTooManyRequests();
    $this->assertGuest();
});

test('users without two-factor authentication sign in with their password only', function () {
    $user = User::factory()->create();

    $this->post(route('login.store'), ['email' => $user->email, 'password' => 'password'])
        ->assertRedirect(route('dashboard', absolute: false));

    $this->assertAuthenticatedAs($user);
});

test('the secret and recovery codes are never serialized with the user', function () {
    $user = User::factory()->withTwoFactor()->create();

    expect($user->toArray())->not->toHaveKey('two_factor_secret')->not->toHaveKey('two_factor_recovery_codes');
});

test('the security page lists the organizations that require two-factor authentication', function () {
    $user = User::factory()->create();
    organizationWith($user, OrganizationRole::Member, ['name' => 'Strict Co', 'require_two_factor' => true]);
    organizationWith($user, OrganizationRole::Member, ['name' => 'Relaxed Co']);

    $this->actingAs($user)->withSession(['auth.password_confirmed_at' => time()])
        ->get(route('security.edit'))
        ->assertInertia(fn (Assert $page) => $page->where('twoFactor.requiredBy', ['Strict Co']));
});
