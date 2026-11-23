<?php

use App\Domain\Monitoring\Actions\SyncMonitorTags;
use App\Domain\Monitoring\Models\Monitor;
use App\Domain\Monitoring\Models\Tag;
use App\Domain\Organizations\Enums\OrganizationRole;
use App\Domain\Organizations\Models\Organization;
use App\Models\User;
use Illuminate\Validation\ValidationException;
use Inertia\Testing\AssertableInertia as Assert;

beforeEach(function () {
    $this->user = User::factory()->create();
    $this->organization = organizationWith($this->user, OrganizationRole::Member);
});

function taggedTcpPayload(array $overrides = []): array
{
    return ['type' => 'tcp', 'name' => 'Database', 'host' => 'example.com', 'port' => 443, 'interval' => 60, 'timeout' => 5, 'tags_submitted' => '1', ...$overrides];
}

function tagMonitor(Organization $organization, array $tags, array $attributes = []): Monitor
{
    $monitor = Monitor::factory()->create(['organization_id' => $organization->id, ...$attributes]);
    app(SyncMonitorTags::class)->handle($monitor, $tags);

    return $monitor;
}

test('tags are normalized, deduplicated and shared within the organization', function () {
    $this->actingAs($this->user)
        ->post(route('organizations.monitors.store', $this->organization), taggedTcpPayload(['tags' => ['  Production ', 'EU   West', 'production']]))
        ->assertSessionHasNoErrors();
    $this->post(route('organizations.monitors.store', $this->organization), taggedTcpPayload(['name' => 'Cache', 'tags' => ['production']]))
        ->assertSessionHasNoErrors();

    expect(Tag::query()->orderBy('name')->pluck('name')->all())->toBe(['eu west', 'production'])
        ->and(Monitor::query()->where('name', 'Database')->sole()->tags->pluck('name')->all())->toBe(['eu west', 'production']);
});

test('an update replaces tags when submitted, keeps them otherwise, and removes unused tags', function () {
    $monitor = tagMonitor($this->organization, ['api', 'legacy'], ['type' => 'tcp']);

    $this->actingAs($this->user)
        ->put(route('organizations.monitors.update', [$this->organization, $monitor]), taggedTcpPayload(['tags' => ['api']]))
        ->assertSessionHasNoErrors();

    expect($monitor->fresh()->tags->pluck('name')->all())->toBe(['api'])
        ->and(Tag::query()->pluck('name')->all())->toBe(['api']);

    $this->put(route('organizations.monitors.update', [$this->organization, $monitor]), [...taggedTcpPayload(), 'tags_submitted' => null])
        ->assertSessionHasNoErrors();
    expect($monitor->fresh()->tags->pluck('name')->all())->toBe(['api']);

    // An empty submitted list clears them (the form sends only the flag).
    $this->put(route('organizations.monitors.update', [$this->organization, $monitor]), taggedTcpPayload())->assertSessionHasNoErrors();
    expect($monitor->fresh()->tags)->toBeEmpty()
        ->and(Tag::query()->count())->toBe(0);
});

test('deleting the last monitor of a tag removes the tag', function () {
    $monitor = tagMonitor($this->organization, ['temporary']);

    $this->actingAs($this->user)->delete(route('organizations.monitors.destroy', [$this->organization, $monitor]))->assertRedirect();

    expect(Tag::query()->count())->toBe(0);
});

test('tags are validated', function (array $tags, string $error) {
    $this->actingAs($this->user)
        ->post(route('organizations.monitors.store', $this->organization), taggedTcpPayload(['tags' => $tags]))
        ->assertSessionHasErrors($error);

    expect(Monitor::query()->count())->toBe(0);
})->with([
    'too many' => [array_map(fn (int $i): string => "tag{$i}", range(1, 11)), 'tags'],
    'too long' => [[str_repeat('a', 33)], 'tags.0'],
    'leading symbol' => [['-prod'], 'tags.0'],
    'forbidden characters' => [['<script>'], 'tags.0'],
]);

test('an organization has at most 100 tags', function () {
    $monitor = Monitor::factory()->create(['organization_id' => $this->organization->id]);
    foreach (range(1, Tag::MAX_PER_ORGANIZATION) as $i) {
        Tag::query()->create(['organization_id' => $this->organization->id, 'name' => "tag-{$i}"]);
    }
    $monitor->tags()->sync(Tag::query()->pluck('id'));

    expect(fn () => app(SyncMonitorTags::class)->handle(Monitor::factory()->create(['organization_id' => $this->organization->id]), ['tag-1', 'one-more']))
        ->toThrow(ValidationException::class);

    app(SyncMonitorTags::class)->handle($other = Monitor::factory()->create(['organization_id' => $this->organization->id]), ['tag-1', 'tag-2']);
    expect($other->tags()->count())->toBe(2);
});

test('the monitor list filters by tag', function () {
    tagMonitor($this->organization, ['production'], ['name' => 'Prod API']);
    tagMonitor($this->organization, ['staging'], ['name' => 'Staging API']);

    $this->actingAs($this->user)
        ->get(route('organizations.monitors.index', [$this->organization, 'tag' => 'Production']))
        ->assertInertia(fn (Assert $page) => $page
            ->where('tag', 'production')
            ->where('tagOptions', ['production', 'staging'])
            ->has('monitors.data', 1)
            ->where('monitors.data.0.name', 'Prod API')
            ->where('monitors.data.0.tags', ['production']));

    $this->get(route('organizations.monitors.index', $this->organization))
        ->assertInertia(fn (Assert $page) => $page->where('tag', null)->has('monitors.data', 2));
});

test('the dashboard filters every figure by tag', function () {
    tagMonitor($this->organization, ['production'], ['name' => 'Prod API']);
    tagMonitor($this->organization, ['staging'], ['name' => 'Staging API', 'enabled' => false]);

    $this->actingAs($this->user)
        ->get(route('organizations.dashboard', [$this->organization, 'tag' => 'production']))
        ->assertInertia(fn (Assert $page) => $page
            ->where('tag', 'production')
            ->where('monitorCounts.total', 1)
            ->where('monitorCounts.paused', 0)
            ->loadDeferredProps(fn (Assert $reload) => $reload
                ->has('analytics.monitors', 1)
                ->where('analytics.monitors.0.name', 'Prod API')));
});

test('a tag of another organization never matches', function () {
    $other = organizationWith(User::factory()->create());
    tagMonitor($other, ['production'], ['name' => 'Foreign']);
    tagMonitor($this->organization, ['production'], ['name' => 'Ours']);

    $this->actingAs($this->user)
        ->get(route('organizations.monitors.index', [$this->organization, 'tag' => 'production']))
        ->assertInertia(fn (Assert $page) => $page->has('monitors.data', 1)->where('monitors.data.0.name', 'Ours')->where('tagOptions', ['production']));

    expect(Tag::query()->where('name', 'production')->count())->toBe(2);
});

test('the API returns, filters and writes tags', function () {
    $token = apiTokenFor($this->organization);
    tagMonitor($this->organization, ['staging'], ['name' => 'Staging']);

    $created = $this->withApiToken($token)
        ->postJson(route('api.v1.monitors.store'), ['type' => 'tcp', 'name' => 'DB', 'host' => 'example.com', 'port' => 443, 'interval' => 60, 'timeout' => 5, 'tags' => ['Production']])
        ->assertCreated()
        ->assertJsonPath('data.tags', ['production'])
        ->json('data.id');

    $this->withApiToken($token)->getJson(route('api.v1.monitors.index', ['tag' => 'production']))
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.id', $created);

    // Omitting tags on a replacement keeps them; sending them replaces them.
    $payload = ['name' => 'DB', 'host' => 'example.com', 'port' => 443, 'interval' => 60, 'timeout' => 5];
    $this->withApiToken($token)->putJson(route('api.v1.monitors.update', $created), $payload)->assertOk()->assertJsonPath('data.tags', ['production']);
    $this->withApiToken($token)->putJson(route('api.v1.monitors.update', $created), [...$payload, 'tags' => []])->assertOk()->assertJsonPath('data.tags', []);
});
