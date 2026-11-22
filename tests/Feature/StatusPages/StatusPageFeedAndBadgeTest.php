<?php

use App\Domain\Incidents\Actions\AddIncidentNote;
use App\Domain\Incidents\Actions\PostPublicIncidentUpdate;
use App\Domain\Incidents\Models\Incident;
use App\Domain\Maintenance\Models\MaintenanceWindow;
use App\Domain\Monitoring\Enums\MonitorStatus;
use App\Domain\Monitoring\Models\Monitor;
use App\Domain\Monitoring\Models\MonitorState;
use App\Domain\StatusPages\Models\StatusPage;
use App\Domain\StatusPages\Models\StatusPageAsset;
use App\Models\User;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Queue;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Support\TestImages;

beforeEach(function () {
    $this->travelTo('2026-11-22 12:00:00');
    Cache::flush();
    $this->owner = User::factory()->create();
    $this->organization = organizationWith($this->owner, attributes: ['locale' => 'fr']);
    $this->monitor = Monitor::factory()->create(['organization_id' => $this->organization->id, 'name' => 'INTERNAL-db-primary']);
    $this->page = StatusPage::factory()->create(['organization_id' => $this->organization->id, 'slug' => 'acme']);
    $this->component = $this->page->components()->create(['monitor_id' => $this->monitor->id, 'display_name' => 'API']);
    MonitorState::whereKey($this->monitor->id)->update(['status' => MonitorStatus::Up->value]);
});

function incidentWithPublicUpdate(Monitor $monitor, User $user): Incident
{
    $incident = Incident::factory()->create(['monitor_id' => $monitor->id, 'cause' => 'TLS error (secret-cause)']);
    $incident->events()->create(['type' => 'opened', 'created_at' => now()->subMinutes(5)]);
    app(AddIncidentNote::class)->handle($incident, $user, 'INTERNAL NOTE: root password rotated');
    app(PostPublicIncidentUpdate::class)->handle($incident, $user, 'We are investigating API errors & <timeouts>.');
    Cache::flush();

    return $incident;
}

test('the Atom feed lists public incidents and maintenance in the organization language', function () {
    incidentWithPublicUpdate($this->monitor, $this->owner);
    MaintenanceWindow::factory()->create(['organization_id' => $this->organization->id, 'title' => 'Database upgrade'])
        ->monitors()->attach($this->monitor->id);
    Cache::flush();

    $response = $this->get('/status/acme/feed.atom');

    $response->assertOk()
        ->assertHeader('Content-Type', 'application/atom+xml; charset=UTF-8')
        ->assertHeader('Cache-Control', 'max-age=300, public');
    expect($response->headers->getCookies())->toBe([]);

    $xml = simplexml_load_string($response->getContent());
    expect($xml)->not->toBeFalse()
        ->and((string) $xml->title)->toBe('Acme status')
        ->and(count($xml->entry))->toBe(2)
        ->and($response->getContent())
        ->toContain('xml:lang="fr"')
        ->toContain('Panne — API')
        ->toContain('Maintenance planifiée — Database upgrade')
        ->toContain('We are investigating API errors &amp; &lt;timeouts&gt;.')
        ->not->toContain('INTERNAL')
        ->not->toContain('secret-cause')
        ->not->toContain($this->monitor->id);
});

test('the RSS feed is valid and whitelisted too', function () {
    incidentWithPublicUpdate($this->monitor, $this->owner);

    $response = $this->get('/status/acme/feed.rss');

    $response->assertOk()->assertHeader('Content-Type', 'application/rss+xml; charset=UTF-8');
    $xml = simplexml_load_string($response->getContent());
    expect($xml)->not->toBeFalse()
        ->and((string) $xml->channel->language)->toBe('fr')
        ->and(count($xml->channel->item))->toBe(1)
        ->and($response->getContent())->not->toContain('INTERNAL')->not->toContain('secret-cause');
});

test('feeds of unpublished or unknown pages are not found', function () {
    $this->page->update(['is_published' => false]);

    $this->get('/status/acme/feed.atom')->assertNotFound();
    $this->get('/status/nope/feed.rss')->assertNotFound();
    $this->get('/status/acme/feed.json')->assertNotFound();
});

test('status badges are cacheable SVG from the snapshot', function () {
    $response = $this->get("/status/acme/badges/{$this->component->id}/status.svg");

    $response->assertOk()
        ->assertHeader('Content-Type', 'image/svg+xml; charset=utf-8')
        ->assertHeader('Cache-Control', 'max-age=60, public');
    expect($response->headers->getCookies())->toBe([])
        ->and($response->headers->get('Content-Security-Policy'))->toStartWith("default-src 'none'")
        ->and(simplexml_load_string($response->getContent()))->not->toBeFalse()
        ->and($response->getContent())->toContain('API')->toContain('Opérationnel')->not->toContain('INTERNAL');
});

test('missing data is shown as no data on badges, never operational', function () {
    MonitorState::whereKey($this->monitor->id)->update(['status' => MonitorStatus::Unknown->value]);

    $this->get("/status/acme/badges/{$this->component->id}/status.svg")->assertOk()->assertSee('Aucune donnée');
    $this->get("/status/acme/badges/{$this->component->id}/uptime-24h.svg")->assertOk()->assertSee('Aucune donnée');
});

test('uptime badges cover 24 hours, 7 days and 30 days', function (string $range) {
    recordCheck($this->monitor, true, now()->subHours(2)->toDateTimeString());
    recordCheck($this->monitor, false, now()->subHour()->toDateTimeString());
    // 7 and 30 days read the daily aggregates, like every other long range.
    $this->artisan('veyra:stats:aggregate')->assertSuccessful();
    Cache::flush();

    $this->get("/status/acme/badges/{$this->component->id}/uptime-{$range}.svg")
        ->assertOk()
        ->assertSee('50,00 % · '.$range);
})->with(['24h', '7d', '30d']);

test('badges never expose unpublished pages, other pages\' components or hidden uptime', function () {
    $other = StatusPage::factory()->create(['organization_id' => $this->organization->id, 'slug' => 'other']);
    $otherComponent = $other->components()->create(['monitor_id' => Monitor::factory()->create(['organization_id' => $this->organization->id])->id, 'display_name' => 'Secret']);

    $this->get("/status/acme/badges/{$otherComponent->id}/status.svg")->assertNotFound();
    $this->get('/status/acme/badges/'.$this->monitor->id.'/status.svg')->assertNotFound();
    $this->get('/status/acme/badges/00000000-0000-0000-0000-000000000000/status.svg')->assertNotFound();

    $this->page->update(['show_uptime' => false]);
    Cache::flush();
    $this->get("/status/acme/badges/{$this->component->id}/uptime-30d.svg")->assertNotFound();
    $this->get("/status/acme/badges/{$this->component->id}/status.svg")->assertOk();

    $this->page->update(['is_published' => false]);
    $this->get("/status/acme/badges/{$this->component->id}/status.svg")->assertNotFound();
});

test('the page advertises its feeds and the subscription form only when enabled', function () {
    $this->get('/status/acme')->assertInertia(fn (Assert $page) => $page
        ->where('snapshot.page.subscriptions_enabled', false)
        ->where('basePath', '/status/acme'));

    $this->page->update(['subscriptions_enabled' => true]);
    Cache::flush();

    $this->get('/status/acme')->assertInertia(fn (Assert $page) => $page->where('snapshot.page.subscriptions_enabled', true));
});

test('the edit page offers feed and badge addresses on the public host', function () {
    $this->actingAs($this->owner)->get(route('organizations.status-pages.edit', [$this->organization, $this->page]))
        ->assertInertia(fn (Assert $page) => $page
            ->where('audience.feeds.atom', url('/status/acme/feed.atom'))
            ->where('audience.badges.0.status', url("/status/acme/badges/{$this->component->id}/status.svg"))
            ->where('audience.badges.0.name', 'API'));
});

describe('on a verified custom domain', function () {
    beforeEach(function () {
        $this->page->update(['custom_domain' => 'status.acme-corp.com', 'domain_verified_at' => now(), 'subscriptions_enabled' => true]);
        Cache::flush();
    });

    test('the page, feeds, badges and assets are served at the root, with an empty base path', function () {
        StatusPageAsset::query()->create([
            'status_page_id' => $this->page->id,
            'kind' => 'logo',
            'mime' => 'image/png',
            'sha256' => hash('sha256', TestImages::png()),
            'width' => 1,
            'height' => 1,
            'size' => strlen(TestImages::png()),
            'content' => TestImages::png(),
        ]);
        Cache::flush();
        $file = StatusPageAsset::query()->sole()->fileName();

        $this->get('http://status.acme-corp.com/')->assertOk()->assertInertia(fn (Assert $page) => $page->where('basePath', '')->where('snapshot.page.branding.logo.file', $file));
        $this->get('http://status.acme-corp.com/feed.atom')->assertOk()->assertSee('http://status.acme-corp.com/feed.atom', false);
        $this->get('http://status.acme-corp.com/feed.rss')->assertOk();
        $this->get("http://status.acme-corp.com/badges/{$this->component->id}/status.svg")->assertOk();
        $this->get("http://status.acme-corp.com/assets/{$file}")->assertOk()->assertHeader('Content-Type', 'image/png');
    });

    test('visitors can subscribe through the custom domain', function () {
        Queue::fake();

        $response = $this->postJson('http://status.acme-corp.com/subscribe', ['email' => 'visitor@example.com']);

        $response->assertStatus(202);
        expect($response->headers->getCookies())->toBe([])
            ->and($this->page->subscribers()->count())->toBe(1);
    });

    test('everything else stays unreachable', function () {
        foreach (['/feed.json', '/badges/x/status.svg', '/assets/logo.png', '/status/acme/feed.atom', '/status-subscriptions/'.$this->component->id.'/confirm', '/login'] as $path) {
            $this->get('http://status.acme-corp.com'.$path)->assertNotFound();
        }

        $this->get('http://status.acme-corp.com/subscribe')->assertNotFound();
        $this->post('http://status.acme-corp.com/feed.atom')->assertNotFound();
    });
});
