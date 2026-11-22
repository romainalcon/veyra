<?php

use App\Domain\Entitlements\Contracts\Entitlements;
use App\Domain\Entitlements\Enums\Feature;
use App\Domain\Monitoring\Models\Monitor;
use App\Domain\Organizations\Enums\OrganizationRole;
use App\Domain\StatusPages\Models\StatusPage;
use App\Domain\StatusPages\Models\StatusPageAsset;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Cache;
use Illuminate\Testing\TestResponse;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Support\FakeEntitlements;
use Tests\Support\TestImages;

beforeEach(function () {
    $this->travelTo('2026-11-22 12:00:00');
    Cache::flush();
    $this->owner = User::factory()->create();
    $this->organization = organizationWith($this->owner);
    $this->page = StatusPage::factory()->create(['organization_id' => $this->organization->id, 'slug' => 'acme']);
    $this->monitor = Monitor::factory()->create(['organization_id' => $this->organization->id]);
});

function uploadBrandingAsset(string $kind, string $bytes, string $name = 'logo.png'): TestResponse
{
    $file = UploadedFile::fake()->createWithContent($name, $bytes);

    return test()->actingAs(test()->owner)->post(
        route('organizations.status-pages.assets.store', [test()->organization, test()->page, $kind]),
        ['file' => $file],
    );
}

function brandedPagePayload(array $overrides = []): array
{
    return [
        'title' => 'Acme status',
        'slug' => 'acme',
        'is_published' => '1',
        'show_uptime' => '1',
        'components' => [['monitor_id' => test()->monitor->id, 'display_name' => 'API']],
        ...$overrides,
    ];
}

test('managers upload a logo and favicon that the public page serves immutably, without cookies', function () {
    uploadBrandingAsset('logo', TestImages::png())->assertRedirect()->assertSessionHasNoErrors();
    uploadBrandingAsset('favicon', TestImages::ico(), 'favicon.ico')->assertSessionHasNoErrors();

    $logo = StatusPageAsset::query()->where('kind', 'logo')->sole();

    $this->get('/status/acme')->assertInertia(fn (Assert $page) => $page
        ->where('snapshot.page.branding.logo.file', $logo->fileName())
        ->where('snapshot.page.branding.favicon', StatusPageAsset::query()->where('kind', 'favicon')->sole()->fileName())
        ->where('basePath', '/status/acme'));

    $response = $this->get('/status/acme/assets/'.$logo->fileName());

    $response->assertOk()
        ->assertHeader('Content-Type', 'image/png')
        ->assertHeader('X-Content-Type-Options', 'nosniff')
        ->assertHeader('Cache-Control', 'immutable, max-age=31536000, public');
    expect($response->headers->get('Content-Security-Policy'))->toStartWith("default-src 'none'")
        ->and($response->headers->getCookies())->toBe([])
        ->and($response->getContent())->toBe(TestImages::png());
});

test('replacing a logo changes its address and the old one stops resolving', function () {
    uploadBrandingAsset('logo', TestImages::png());
    $old = StatusPageAsset::query()->sole()->fileName();

    uploadBrandingAsset('logo', TestImages::webp(), 'logo.webp');
    $new = StatusPageAsset::query()->sole()->fileName();

    expect($new)->not->toBe($old)->toEndWith('.webp');
    $this->get("/status/acme/assets/{$old}")->assertNotFound();
    $this->get("/status/acme/assets/{$new}")->assertOk()->assertHeader('Content-Type', 'image/webp');
});

test('SVG, polyglots, wrong formats and oversized files are refused', function (Closure $bytes, string $kind, string $name) {
    uploadBrandingAsset($kind, $bytes(), $name)->assertSessionHasErrors('file');

    expect(StatusPageAsset::query()->count())->toBe(0);
})->with([
    'svg logo' => [fn () => TestImages::svg(), 'logo', 'logo.svg'],
    'svg renamed as png' => [fn () => TestImages::svg(), 'logo', 'logo.png'],
    'html behind a png signature' => [fn () => TestImages::fakePng(), 'logo', 'logo.png'],
    'ico as a logo' => [fn () => TestImages::ico(), 'logo', 'logo.ico'],
    'oversized favicon' => [fn () => TestImages::png().str_repeat("\0", 70 * 1024), 'favicon', 'favicon.png'],
]);

test('assets of unpublished pages are not served publicly, but managers can preview them', function () {
    uploadBrandingAsset('logo', TestImages::png());
    $file = StatusPageAsset::query()->sole()->fileName();
    $this->page->update(['is_published' => false]);

    $this->get("/status/acme/assets/{$file}")->assertNotFound();
    $this->actingAs($this->owner)->get(route('organizations.status-pages.assets.show', [$this->organization, $this->page, 'logo']))
        ->assertOk()
        ->assertHeader('Content-Type', 'image/png');
});

test('asset files of another page are not reachable through this page', function () {
    uploadBrandingAsset('logo', TestImages::png());
    $file = StatusPageAsset::query()->sole()->fileName();
    StatusPage::factory()->create(['slug' => 'other']);

    $this->get("/status/other/assets/{$file}")->assertNotFound();
});

test('only page managers can upload or remove branding', function () {
    $viewer = memberOf($this->organization, OrganizationRole::Viewer);
    $file = UploadedFile::fake()->createWithContent('logo.png', TestImages::png());

    $this->actingAs($viewer)->post(route('organizations.status-pages.assets.store', [$this->organization, $this->page, 'logo']), ['file' => $file])->assertForbidden();
    $this->actingAs($viewer)->delete(route('organizations.status-pages.assets.destroy', [$this->organization, $this->page, 'logo']))->assertForbidden();
    $this->actingAs($viewer)->get(route('organizations.status-pages.assets.show', [$this->organization, $this->page, 'logo']))->assertForbidden();
});

test('removing an asset clears it from the public page', function () {
    uploadBrandingAsset('logo', TestImages::png());

    $this->actingAs($this->owner)->delete(route('organizations.status-pages.assets.destroy', [$this->organization, $this->page, 'logo']))->assertRedirect();

    expect(StatusPageAsset::query()->count())->toBe(0);
    $this->get('/status/acme')->assertInertia(fn (Assert $page) => $page->where('snapshot.page.branding.logo', null));
});

test('the accent colour is validated and published with its contrast decisions', function () {
    $this->actingAs($this->owner)
        ->put(route('organizations.status-pages.update', [$this->organization, $this->page]), brandedPagePayload(['accent_color' => 'red; background:url(x)']))
        ->assertSessionHasErrors('accent_color');

    $this->actingAs($this->owner)
        ->put(route('organizations.status-pages.update', [$this->organization, $this->page]), brandedPagePayload(['accent_color' => '#FACC15']))
        ->assertSessionHasNoErrors();

    expect($this->page->fresh()->accent_color)->toBe('#facc15');
    $this->get('/status/acme')->assertInertia(fn (Assert $page) => $page
        ->where('snapshot.page.branding.accent', '#facc15')
        ->where('snapshot.page.branding.accent_foreground', '#000000')
        ->where('snapshot.page.branding.text_light', false)
        ->where('snapshot.page.branding.text_dark', true));
});

test('without the branding entitlement new branding is refused but existing branding is kept', function () {
    $this->page->update(['accent_color' => '#4f46e5']);
    $this->app->instance(Entitlements::class, (new FakeEntitlements)->set(Feature::StatusPageBranding, 0));

    // Keeping the existing accent is allowed (growth only).
    $this->actingAs($this->owner)
        ->put(route('organizations.status-pages.update', [$this->organization, $this->page]), brandedPagePayload(['accent_color' => '#4f46e5']))
        ->assertSessionHasNoErrors();

    $this->actingAs($this->owner)
        ->put(route('organizations.status-pages.update', [$this->organization, $this->page]), brandedPagePayload(['accent_color' => '#16a34a']))
        ->assertSessionHasErrors('limit');
    expect($this->page->fresh()->accent_color)->toBe('#4f46e5');

    uploadBrandingAsset('logo', TestImages::png())->assertSessionHasErrors('limit');
    expect(StatusPageAsset::query()->count())->toBe(0);

    $this->actingAs($this->owner)->get(route('organizations.status-pages.edit', [$this->organization, $this->page]))
        ->assertInertia(fn (Assert $page) => $page->where('canBrand', false));
});

test('Community allows branding', function () {
    $this->actingAs($this->owner)->get(route('organizations.status-pages.edit', [$this->organization, $this->page]))
        ->assertInertia(fn (Assert $page) => $page->where('canBrand', true)->where('audience.assets.logo', null));
});
