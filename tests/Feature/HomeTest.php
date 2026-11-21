<?php

use App\Models\User;
use Inertia\Testing\AssertableInertia as Assert;

test('guests are sent to the login page when the landing page is disabled', function () {
    config(['veyra.landing_page' => false]);

    $this->get('/')->assertRedirect(route('login'));
});

test('guests see the landing page when it is enabled', function () {
    config(['veyra.landing_page' => true, 'veyra.source_url' => 'https://github.com/acme/veyra']);

    $this->get('/')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('Welcome')
            ->where('canRegister', true)
            ->where('sourceUrl', 'https://github.com/acme/veyra')
            ->where('auth.user', null)
            ->where('currentOrganization', null));
});

test('the landing page hides sign-up when public registration is closed', function () {
    config(['veyra.landing_page' => true, 'veyra.registration_enabled' => false]);

    $this->get('/')->assertInertia(fn (Assert $page) => $page->where('canRegister', false));
});

test('the landing page follows the browser language', function () {
    config(['veyra.landing_page' => true]);

    $this->withHeader('Accept-Language', 'fr-FR,fr;q=0.9')
        ->get('/')
        ->assertSee('<html lang="fr"', false)
        ->assertInertia(fn (Assert $page) => $page->where('locale', 'fr'));
});

test('signed-in users are sent to their dashboard', function (bool $landingPage) {
    config(['veyra.landing_page' => $landingPage]);
    $user = User::factory()->create();

    $this->actingAs($user)->get('/')->assertRedirect(route('dashboard'));
})->with([
    'landing page enabled' => [true],
    'landing page disabled' => [false],
]);
