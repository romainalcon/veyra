<?php

use App\Domain\Extensions\PublicNavigation;
use App\Domain\System\Site\Changelog;
use App\Models\User;
use Inertia\Testing\AssertableInertia as Assert;

test('the changelog and roadmap are not found without the landing page', function (string $route) {
    config(['veyra.landing_page' => false]);

    $this->get(route($route))->assertNotFound();
    $this->actingAs(User::factory()->create())->get(route($route))->assertNotFound();
})->with(['changelog', 'roadmap']);

test('guests and signed-in users see the changelog with the landing page', function () {
    config(['veyra.landing_page' => true]);
    $releases = app(Changelog::class)->releases();

    $this->get(route('changelog'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('site/Changelog')
            ->where('releases', $releases)
            ->where('canRegister', true));

    $this->actingAs(User::factory()->create())
        ->get(route('changelog'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page->component('site/Changelog'));
});

test('the roadmap lists the milestones and links the latest release', function () {
    config(['veyra.landing_page' => true, 'veyra.registration_enabled' => false]);
    $latest = app(Changelog::class)->latest();

    $this->get(route('roadmap'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('site/Roadmap')
            ->has('milestones.0', fn (Assert $milestone) => $milestone
                ->hasAll(['id', 'version', 'status', 'statusLabel', 'title', 'summary', 'highlights']))
            ->where('latestRelease', [
                'version' => $latest['version'],
                'date' => $latest['date'],
                'href' => route('changelog').'#'.$latest['anchor'],
            ])
            ->where('canRegister', false));
});

test('the roadmap follows the browser language', function () {
    config(['veyra.landing_page' => true]);

    $this->withHeader('Accept-Language', 'fr-FR,fr;q=0.9')
        ->get(route('roadmap'))
        ->assertSee('<html lang="fr"', false)
        ->assertInertia(fn (Assert $page) => $page->where('milestones.0.statusLabel', 'Prévu'));
});

test('the site pages carry their own link preview metadata', function (string $route, string $title) {
    config(['veyra.landing_page' => true]);

    $this->get(route($route))
        ->assertSee("<title>{$title} - Veyra</title>", false)
        ->assertSee('<meta property="og:url" content="'.route($route).'">', false)
        ->assertSee('<link rel="canonical" href="'.route($route).'">', false);
})->with([
    ['changelog', 'Changelog'],
    ['roadmap', 'Roadmap'],
]);

test('the changelog and roadmap are linked only with the landing page', function () {
    config(['veyra.landing_page' => false]);

    expect(app(PublicNavigation::class)->all())->toBe([]);

    config(['veyra.landing_page' => true]);

    $this->withHeader('Accept-Language', 'fr')
        ->get(route('login'))
        ->assertInertia(fn (Assert $page) => $page->where('publicNavigation', [
            ['id' => 'changelog', 'title' => 'Journal des modifications', 'href' => route('changelog'), 'placement' => 'header'],
            ['id' => 'roadmap', 'title' => 'Feuille de route', 'href' => route('roadmap'), 'placement' => 'header'],
            ['id' => 'changelog', 'title' => 'Journal des modifications', 'href' => route('changelog'), 'placement' => 'footer'],
            ['id' => 'roadmap', 'title' => 'Feuille de route', 'href' => route('roadmap'), 'placement' => 'footer'],
        ]));
});
