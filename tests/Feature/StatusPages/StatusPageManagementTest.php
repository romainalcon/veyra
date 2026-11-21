<?php

use App\Domain\Monitoring\Models\Monitor;
use App\Domain\Organizations\Enums\OrganizationRole;
use App\Domain\StatusPages\Models\StatusPage;
use App\Domain\StatusPages\Services\StatusPageSnapshot;
use App\Models\User;
use Illuminate\Support\Facades\Cache;
use Inertia\Testing\AssertableInertia as Assert;

beforeEach(function () {
    $this->owner = User::factory()->create();
    $this->organization = organizationWith($this->owner);
    $this->api = Monitor::factory()->create(['organization_id' => $this->organization->id, 'name' => 'api-prod']);
    $this->web = Monitor::factory()->create(['organization_id' => $this->organization->id, 'name' => 'web-prod']);
});

function pagePayload(array $overrides = []): array
{
    return [
        'title' => 'Acme status',
        'slug' => 'acme',
        'description' => '',
        'is_published' => '1',
        'show_uptime' => '1',
        ...$overrides,
    ];
}

test('members create a page with ordered, renamed components', function () {
    $member = memberOf($this->organization, OrganizationRole::Member);

    $this->actingAs($member)
        ->post(route('organizations.status-pages.store', $this->organization), pagePayload(['components' => [
            ['monitor_id' => $this->web->id, 'display_name' => 'Website', 'group_name' => ''],
            ['monitor_id' => $this->api->id, 'display_name' => 'API', 'group_name' => 'Core'],
        ]]))
        ->assertRedirect();

    $page = StatusPage::sole();
    expect($page)
        ->organization_id->toBe($this->organization->id)
        ->is_published->toBeTrue()
        ->and($page->components->map->only(['monitor_id', 'display_name', 'group_name', 'position'])->all())->toBe([
            ['monitor_id' => $this->web->id, 'display_name' => 'Website', 'group_name' => null, 'position' => 0],
            ['monitor_id' => $this->api->id, 'display_name' => 'API', 'group_name' => 'Core', 'position' => 1],
        ]);
});

test('viewers see the list but cannot manage pages', function () {
    $viewer = memberOf($this->organization, OrganizationRole::Viewer);
    $page = StatusPage::factory()->create(['organization_id' => $this->organization->id]);

    $this->actingAs($viewer)->get(route('organizations.status-pages.index', $this->organization))
        ->assertInertia(fn (Assert $inertia) => $inertia
            ->component('status-pages/Index')
            ->where('canManage', false)
            ->has('statusPages', 1));

    $this->actingAs($viewer)->get(route('organizations.status-pages.create', $this->organization))->assertForbidden();
    $this->actingAs($viewer)->post(route('organizations.status-pages.store', $this->organization), pagePayload())->assertForbidden();
    $this->actingAs($viewer)->put(route('organizations.status-pages.update', [$this->organization, $page]), pagePayload())->assertForbidden();
    $this->actingAs($viewer)->delete(route('organizations.status-pages.destroy', [$this->organization, $page]))->assertForbidden();

    expect(StatusPage::count())->toBe(1);
});

test('monitors of another organization cannot be published', function () {
    $foreign = Monitor::factory()->create();

    $this->actingAs($this->owner)
        ->post(route('organizations.status-pages.store', $this->organization), pagePayload(['components' => [
            ['monitor_id' => $foreign->id, 'display_name' => 'Foreign'],
        ]]))
        ->assertSessionHasErrors('components.0.monitor_id');

    expect(StatusPage::count())->toBe(0);
});

test('slugs are validated', function (string $slug) {
    StatusPage::factory()->create(['slug' => 'taken']);

    $this->actingAs($this->owner)
        ->post(route('organizations.status-pages.store', $this->organization), pagePayload(['slug' => $slug]))
        ->assertSessionHasErrors('slug');
})->with(['taken', 'ab', 'Has Spaces', '-dash', 'dash-', 'admin', 'été']);

test('slugs are normalized to lowercase', function () {
    $this->actingAs($this->owner)
        ->post(route('organizations.status-pages.store', $this->organization), pagePayload(['slug' => 'ACME-Status']))
        ->assertSessionHasNoErrors();

    expect(StatusPage::sole()->slug)->toBe('acme-status');
});

test('updating syncs components and clears the public cache', function () {
    $page = StatusPage::factory()->create(['organization_id' => $this->organization->id, 'slug' => 'acme']);
    $page->components()->create(['monitor_id' => $this->api->id, 'display_name' => 'API']);
    Cache::put(StatusPageSnapshot::cacheKey($page), ['stale' => true], 60);

    $this->actingAs($this->owner)
        ->put(route('organizations.status-pages.update', [$this->organization, $page]), pagePayload(['components' => [
            ['monitor_id' => $this->web->id, 'display_name' => 'Website'],
        ]]))
        ->assertRedirect(route('organizations.status-pages.edit', [$this->organization, $page]));

    expect($page->components()->pluck('monitor_id')->all())->toBe([$this->web->id])
        ->and(Cache::has(StatusPageSnapshot::cacheKey($page)))->toBeFalse();

    $this->actingAs($this->owner)->get(route('organizations.status-pages.edit', [$this->organization, $page]))
        ->assertInertia(fn (Assert $inertia) => $inertia
            ->component('status-pages/Edit')
            ->where('statusPage.components.0.display_name', 'Website')
            ->where('statusPage.public_url', route('status-pages.public', 'acme'))
            ->has('monitors', 2));
});

test('unpublishing takes the public page offline', function () {
    $page = StatusPage::factory()->create(['organization_id' => $this->organization->id, 'slug' => 'acme']);
    $this->get('/status/acme')->assertOk();

    $this->actingAs($this->owner)->put(route('organizations.status-pages.update', [$this->organization, $page]), pagePayload(['is_published' => '0']));

    $this->get('/status/acme')->assertNotFound();
});

test('deleting a page removes it and its components', function () {
    $page = StatusPage::factory()->create(['organization_id' => $this->organization->id]);
    $page->components()->create(['monitor_id' => $this->api->id, 'display_name' => 'API']);

    $this->actingAs($this->owner)->delete(route('organizations.status-pages.destroy', [$this->organization, $page]))
        ->assertRedirect(route('organizations.status-pages.index', $this->organization));

    expect(StatusPage::count())->toBe(0)
        ->and(DB::table('status_page_components')->count())->toBe(0);
});
