<?php

use App\Domain\Organizations\Enums\OrganizationRole;
use App\Domain\StatusPages\Actions\SetStatusPageDomain;
use App\Domain\StatusPages\Actions\VerifyPendingDomains;
use App\Domain\StatusPages\Actions\VerifyStatusPageDomain;
use App\Domain\StatusPages\Contracts\TxtResolver;
use App\Domain\StatusPages\Models\StatusPage;
use App\Domain\StatusPages\Support\DomainName;
use App\Models\User;
use Carbon\CarbonImmutable;
use Inertia\Testing\AssertableInertia as Assert;

beforeEach(function () {
    config(['app.url' => 'https://veyra.example.net']);
    $this->travelTo('2026-11-22 12:00:00');
    $this->owner = User::factory()->create();
    $this->organization = organizationWith($this->owner);
    $this->page = StatusPage::factory()->create(['organization_id' => $this->organization->id, 'slug' => 'acme']);
});

function verifiedDomain(StatusPage $page, string $domain = 'status.acme-corp.com'): StatusPage
{
    app(SetStatusPageDomain::class)->handle($page, $domain);
    app(TxtResolver::class)->set(DomainName::challengeName($domain), [DomainName::challengeValue($page->fresh()->domain_verification_token)]);
    app(VerifyStatusPageDomain::class)->handle($page->fresh());

    return $page->fresh();
}

test('domains are normalized and validated', function (string $input, ?string $expected) {
    $response = $this->actingAs($this->owner)
        ->put(route('organizations.status-pages.domain.update', [$this->organization, $this->page]), ['custom_domain' => $input]);

    if ($expected === null) {
        $response->assertSessionHasErrors('custom_domain');
        expect($this->page->fresh()->custom_domain)->toBeNull();
    } else {
        $response->assertSessionHasNoErrors();
        expect($this->page->fresh())
            ->custom_domain->toBe($expected)
            ->domain_verified_at->toBeNull()
            ->domain_verification_token->toHaveLength(40);
    }
})->with([
    'plain' => ['Status.Acme-Corp.com.', 'status.acme-corp.com'],
    'idn' => ['statut.café.fr', 'statut.xn--caf-dma.fr'],
    'ip' => ['93.184.215.14', null],
    'single label' => ['intranet', null],
    'localhost' => ['status.localhost', null],
    'internal suffix' => ['status.corp.internal', null],
    'with a port' => ['status.acme-corp.com:8443', null],
    'with a path' => ['status.acme-corp.com/x', null],
    'numeric tld' => ['status.123', null],
    'the instance itself' => ['veyra.example.net', null],
    'a subdomain of the instance' => ['acme.veyra.example.net', null],
]);

test('a domain belongs to a single status page', function () {
    $other = StatusPage::factory()->create();
    app(SetStatusPageDomain::class)->handle($other, 'status.acme-corp.com');

    $this->actingAs($this->owner)
        ->put(route('organizations.status-pages.domain.update', [$this->organization, $this->page]), ['custom_domain' => 'status.acme-corp.com'])
        ->assertSessionHasErrors('custom_domain');
});

test('viewers cannot manage domains', function () {
    $viewer = memberOf($this->organization, OrganizationRole::Viewer);

    $this->actingAs($viewer)->put(route('organizations.status-pages.domain.update', [$this->organization, $this->page]), ['custom_domain' => 'status.acme-corp.com'])->assertForbidden();
    $this->actingAs($viewer)->post(route('organizations.status-pages.domain.verify', [$this->organization, $this->page]))->assertForbidden();
    $this->actingAs($viewer)->delete(route('organizations.status-pages.domain.destroy', [$this->organization, $this->page]))->assertForbidden();
});

test('ownership is proven with the TXT record', function () {
    app(SetStatusPageDomain::class)->handle($this->page, 'status.acme-corp.com');
    $page = $this->page->fresh();

    $this->actingAs($this->owner)->post(route('organizations.status-pages.domain.verify', [$this->organization, $page]));
    expect($page->fresh()->domain_verified_at)->toBeNull();

    $this->txt->set('_veyra-challenge.status.acme-corp.com', ['unrelated', 'veyra-verification='.$page->domain_verification_token]);
    $this->actingAs($this->owner)->post(route('organizations.status-pages.domain.verify', [$this->organization, $page]));

    expect($page->fresh()->domain_verified_at)->not->toBeNull();

    $this->actingAs($this->owner)->get(route('organizations.status-pages.edit', [$this->organization, $page]))
        ->assertInertia(fn (Assert $inertia) => $inertia
            ->where('statusPage.domain.verified', true)
            ->where('statusPage.domain.txt_name', '_veyra-challenge.status.acme-corp.com')
            ->where('statusPage.custom_url', 'https://status.acme-corp.com'));
});

test('changing the domain resets verification and the token', function () {
    $page = verifiedDomain($this->page);
    $token = $page->domain_verification_token;

    app(SetStatusPageDomain::class)->handle($page, 'status.other-corp.com');

    expect($page->fresh())
        ->domain_verified_at->toBeNull()
        ->domain_verification_token->not->toBe($token);
});

test('a verified domain is revoked after three failed daily checks', function () {
    $page = verifiedDomain($this->page);
    $this->txt->records = [];

    foreach ([1, 2] as $day) {
        $this->travelTo(CarbonImmutable::parse('2026-11-22 12:00:00')->addDays($day));
        app(VerifyPendingDomains::class)->handle();
        expect($page->fresh()->hasVerifiedDomain())->toBeTrue();
    }

    $this->travelTo('2026-11-25 12:00:00');
    $this->artisan('veyra:status-domains:verify')->assertSuccessful();

    expect($page->fresh()->hasVerifiedDomain())->toBeFalse();
    // No longer diverted: the host falls through to the application.
    $this->get('http://status.acme-corp.com/')->assertRedirect();
});

test('verified domains are re-checked daily, not hourly', function () {
    verifiedDomain($this->page);

    $this->travelTo('2026-11-22 13:00:00');
    expect(app(VerifyPendingDomains::class)->handle())->toBe(['checked' => 0, 'verified' => 0]);

    $this->travelTo('2026-11-23 12:00:00');
    expect(app(VerifyPendingDomains::class)->handle())->toBe(['checked' => 1, 'verified' => 1]);
});

test('a verified domain serves only the status page, without a session', function () {
    verifiedDomain($this->page);

    $response = $this->get('http://status.acme-corp.com/');

    $response->assertOk()
        ->assertHeader('Cache-Control', 'max-age=30, public')
        ->assertInertia(fn (Assert $inertia) => $inertia
            ->component('status/Show')
            ->where('snapshot.page.title', 'Acme status')
            ->missing('auth'));
    expect($response->headers->getCookies())->toBe([]);

    foreach (['/login', '/dashboard', '/status/acme', '/o/'.$this->organization->slug.'/dashboard', '/health'] as $path) {
        $this->get('http://status.acme-corp.com'.$path)->assertNotFound();
    }

    $this->post('http://status.acme-corp.com/login', ['email' => $this->owner->email, 'password' => 'password'])->assertNotFound();
});

test('unverified or unpublished domains are not served', function () {
    app(SetStatusPageDomain::class)->handle($this->page, 'status.acme-corp.com');

    $this->get('http://status.acme-corp.com/status/acme')->assertOk();
    $this->get('http://status.acme-corp.com/')->assertRedirect();

    $page = verifiedDomain($this->page);
    $page->update(['is_published' => false]);

    $this->get('http://status.acme-corp.com/')->assertRedirect();
});

test('the instance host is never taken over', function () {
    verifiedDomain($this->page);

    // The application answers (guests are sent to the login page), not the status page.
    $this->get('https://veyra.example.net/')->assertRedirect('/login');
});

test('removing the domain stops serving it immediately', function () {
    $page = verifiedDomain($this->page);
    $removeUrl = route('organizations.status-pages.domain.destroy', [$this->organization, $page]);
    $this->get('http://status.acme-corp.com/')->assertOk();

    $this->actingAs($this->owner)->delete($removeUrl);
    auth()->logout();

    expect($page->fresh()->custom_domain)->toBeNull();
    $this->get('http://status.acme-corp.com/')->assertRedirect();
});

test('the TLS ask endpoint only approves served domains', function () {
    verifiedDomain($this->page);
    StatusPage::factory()->create(['custom_domain' => 'pending.acme-corp.com']);

    $this->get('/status-domains/tls-check?domain=status.acme-corp.com')->assertOk();
    $this->get('/status-domains/tls-check?domain=STATUS.acme-corp.com.')->assertOk();
    $this->get('/status-domains/tls-check?domain=pending.acme-corp.com')->assertNotFound();
    $this->get('/status-domains/tls-check?domain=evil.example.org')->assertNotFound();
    $this->get('/status-domains/tls-check')->assertNotFound();

    $this->page->update(['is_published' => false]);
    $this->get('/status-domains/tls-check?domain=status.acme-corp.com')->assertNotFound();
});
