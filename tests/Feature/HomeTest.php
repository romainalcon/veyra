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

test('the landing page carries link preview metadata in the server-rendered HTML', function () {
    config(['veyra.landing_page' => true]);

    $this->get('/')
        ->assertOk()
        ->assertSee('<title>Uptime monitoring you can host yourself - Veyra</title>', false)
        ->assertSee('<meta property="og:title" content="Uptime monitoring you can host yourself - Veyra">', false)
        ->assertSee('<meta property="og:url" content="'.url('/').'">', false)
        ->assertSee('<meta property="og:image" content="'.asset('images/og-image-en.png').'">', false)
        ->assertSee('<meta property="og:locale" content="en_US">', false)
        ->assertSee('<meta name="twitter:card" content="summary_large_image">', false)
        ->assertSee('<link rel="canonical" href="'.url('/').'">', false);

    expect(public_path('images/og-image-en.png'))->toBeFile()
        ->and(public_path('images/og-image-fr.png'))->toBeFile();
});

test('link preview metadata follows the browser language', function () {
    config(['veyra.landing_page' => true]);

    $this->withHeader('Accept-Language', 'fr-FR,fr;q=0.9')
        ->get('/')
        ->assertSee('<meta property="og:locale" content="fr_FR">', false)
        ->assertSee('<meta property="og:image" content="'.asset('images/og-image-fr.png').'">', false)
        ->assertSee('La surveillance de disponibilité que vous hébergez vous-même - Veyra', false);
});

test('other pages do not carry the landing page metadata', function () {
    $this->get(route('login'))
        ->assertOk()
        ->assertDontSee('og:title', false)
        ->assertDontSee('twitter:card', false);
});
