<?php

use App\Domain\Entitlements\Contracts\Entitlements;
use App\Domain\Entitlements\Enums\Feature;
use App\Domain\Monitoring\Models\Monitor;
use App\Domain\Monitoring\Models\MonitorCheck;
use App\Domain\Notifications\Models\NotificationChannel;
use App\Domain\Organizations\Enums\OrganizationRole;
use App\Models\User;
use Tests\Support\FakeEntitlements;

beforeEach(function () {
    $this->organization = organizationWith(User::factory()->create(), OrganizationRole::Owner);
    $this->token = apiTokenFor($this->organization);
});

function apiMonitorPayload(array $overrides = []): array
{
    return [
        'type' => 'http',
        'name' => 'Website',
        'url' => 'https://example.com/health',
        'method' => 'GET',
        'interval' => 60,
        'timeout' => 10,
        'expected_statuses' => '200-299',
        'follow_redirects' => true,
        'headers' => [['name' => 'Authorization', 'value' => 'Bearer top-secret-header']],
        ...$overrides,
    ];
}

test('a monitor can be created, read, updated, paused, resumed and deleted', function () {
    $created = $this->withApiToken($this->token)
        ->postJson(route('api.v1.monitors.store'), apiMonitorPayload())
        ->assertCreated()
        ->assertJsonPath('data.name', 'Website')
        ->assertJsonPath('data.type', 'http')
        ->assertJsonPath('data.status', 'unknown')
        ->assertJsonPath('data.http.url', 'https://example.com/health')
        ->assertJsonPath('data.http.header_names', ['Authorization'])
        ->assertJsonPath('data.notification_channel_ids', []);

    $monitor = Monitor::sole();
    expect($created->json('data.id'))->toBe($monitor->id)
        ->and($monitor->organization_id)->toBe($this->organization->id)
        ->and($created->getContent())->not->toContain('top-secret-header');

    $this->withApiToken($this->token)->getJson(route('api.v1.monitors.show', $monitor))
        ->assertOk()
        ->assertJsonPath('data.id', $monitor->id);

    $this->withApiToken($this->token)
        ->putJson(route('api.v1.monitors.update', $monitor), apiMonitorPayload(['name' => 'Renamed', 'headers_mode' => 'keep', 'headers' => []]))
        ->assertOk()
        ->assertJsonPath('data.name', 'Renamed')
        ->assertJsonPath('data.http.header_names', ['Authorization']);

    $this->withApiToken($this->token)->postJson(route('api.v1.monitors.pause', $monitor))
        ->assertOk()
        ->assertJsonPath('data.enabled', false);

    $this->withApiToken($this->token)->postJson(route('api.v1.monitors.resume', $monitor))
        ->assertOk()
        ->assertJsonPath('data.enabled', true);

    $this->withApiToken($this->token)->deleteJson(route('api.v1.monitors.destroy', $monitor))->assertNoContent();

    expect(Monitor::query()->count())->toBe(0)
        ->and(Monitor::withTrashed()->count())->toBe(1);

    $this->withApiToken($this->token)->getJson(route('api.v1.monitors.show', $monitor))->assertNotFound();
});

test('validation is the interface validation', function (array $overrides, string $field) {
    $this->withApiToken($this->token)
        ->postJson(route('api.v1.monitors.store'), apiMonitorPayload($overrides))
        ->assertUnprocessable()
        ->assertJsonValidationErrors($field);

    expect(Monitor::query()->count())->toBe(0);
})->with([
    'missing name' => [['name' => ''], 'name'],
    'bad scheme' => [['url' => 'ftp://example.com'], 'url'],
    'credentials in url' => [['url' => 'https://user:pass@example.com'], 'url'],
    'unknown interval' => [['interval' => 61], 'interval'],
    'timeout above interval' => [['interval' => 30, 'timeout' => 30], 'timeout'],
    'forbidden header' => [['headers' => [['name' => 'Host', 'value' => 'x']]], 'headers.0.name'],
    'bad statuses' => [['expected_statuses' => '999'], 'expected_statuses'],
]);

test('SSRF rules apply to API monitors', function (string $url) {
    $this->dns->set('intranet.example.com', ['10.1.2.3']);

    $this->withApiToken($this->token)
        ->postJson(route('api.v1.monitors.store'), apiMonitorPayload(['url' => $url]))
        ->assertUnprocessable()
        ->assertJsonValidationErrors('url');
})->with([
    'loopback' => 'http://127.0.0.1/',
    'metadata' => 'http://169.254.169.254/latest/meta-data',
    'ipv6 loopback' => 'http://[::1]/',
    'private DNS answer' => 'https://intranet.example.com/',
]);

test('quotas apply to API monitors', function () {
    $entitlements = (new FakeEntitlements)->set(Feature::Monitors, 1);
    $this->app->instance(Entitlements::class, $entitlements);
    $paused = Monitor::factory()->create(['organization_id' => $this->organization->id, 'enabled' => false]);
    Monitor::factory()->create(['organization_id' => $this->organization->id]);

    $this->withApiToken($this->token)
        ->postJson(route('api.v1.monitors.store'), apiMonitorPayload())
        ->assertUnprocessable()
        ->assertJsonValidationErrors('limit');

    $this->withApiToken($this->token)->postJson(route('api.v1.monitors.resume', $paused))
        ->assertUnprocessable()
        ->assertJsonValidationErrors('limit');

    expect($paused->refresh()->enabled)->toBeFalse();
});

test('monitor creation is rate limited per token', function () {
    config(['veyra.monitors.max_per_hour' => 1]);

    $this->withApiToken($this->token)->postJson(route('api.v1.monitors.store'), apiMonitorPayload())->assertCreated();
    $this->withApiToken($this->token)->postJson(route('api.v1.monitors.store'), apiMonitorPayload())->assertTooManyRequests();
});

test('heartbeat monitors return their ping URL once', function () {
    $response = $this->withApiToken($this->token)
        ->postJson(route('api.v1.monitors.store'), ['type' => 'heartbeat', 'name' => 'Backup', 'interval' => 3600, 'grace_minutes' => 5])
        ->assertCreated()
        ->assertJsonPath('data.type', 'heartbeat');

    $pingUrl = (string) $response->json('meta.ping_url');
    expect($pingUrl)->toStartWith(url('ping').'/');

    $show = $this->withApiToken($this->token)->getJson(route('api.v1.monitors.show', Monitor::sole()));
    expect($show->getContent())->not->toContain(basename($pingUrl))
        ->and($show->json('meta'))->toBeNull();
});

test('notification channels are replaced only when sent and must belong to the organization', function () {
    $channel = NotificationChannel::factory()->create(['organization_id' => $this->organization->id]);
    $foreign = NotificationChannel::factory()->create();

    $this->withApiToken($this->token)
        ->postJson(route('api.v1.monitors.store'), apiMonitorPayload(['notification_channel_ids' => [$foreign->id]]))
        ->assertUnprocessable()
        ->assertJsonValidationErrors('notification_channel_ids.0');

    $this->withApiToken($this->token)
        ->postJson(route('api.v1.monitors.store'), apiMonitorPayload(['notification_channel_ids' => [$channel->id]]))
        ->assertCreated()
        ->assertJsonPath('data.notification_channel_ids', [$channel->id]);

    $monitor = Monitor::sole();

    $this->withApiToken($this->token)
        ->putJson(route('api.v1.monitors.update', $monitor), apiMonitorPayload(['headers_mode' => 'keep']))
        ->assertOk()
        ->assertJsonPath('data.notification_channel_ids', [$channel->id]);

    $this->withApiToken($this->token)
        ->putJson(route('api.v1.monitors.update', $monitor), apiMonitorPayload(['headers_mode' => 'keep', 'notification_channel_ids' => []]))
        ->assertOk()
        ->assertJsonPath('data.notification_channel_ids', []);
});

test('monitors and checks are cursor paginated', function () {
    Monitor::factory()->count(3)->create(['organization_id' => $this->organization->id]);

    $first = $this->withApiToken($this->token)->getJson(route('api.v1.monitors.index', ['per_page' => 2]))
        ->assertOk()
        ->assertJsonCount(2, 'data');

    $next = (string) $first->json('meta.next_cursor');
    expect($next)->not->toBe('');

    $this->withApiToken($this->token)->getJson(route('api.v1.monitors.index', ['per_page' => 2, 'cursor' => $next]))
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('meta.next_cursor', null);
});

test('checks are listed newest first', function () {
    $monitor = Monitor::factory()->create(['organization_id' => $this->organization->id]);
    MonitorCheck::factory()->create(['monitor_id' => $monitor->id, 'checked_at' => now()->subMinutes(2), 'scheduled_for' => now()->subMinutes(2)]);
    $latest = MonitorCheck::factory()->create(['monitor_id' => $monitor->id, 'checked_at' => now()->subMinute(), 'scheduled_for' => now()->subMinute(), 'success' => false, 'status_code' => 503]);

    $this->withApiToken($this->token)->getJson(route('api.v1.monitors.checks.index', $monitor))
        ->assertOk()
        ->assertJsonCount(2, 'data')
        ->assertJsonPath('data.0.id', $latest->id)
        ->assertJsonPath('data.0.success', false)
        ->assertJsonPath('data.0.status_code', 503)
        ->assertJsonMissingPath('data.0.metadata');
});
