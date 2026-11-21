<?php

use App\Domain\Monitoring\Enums\DispatchStatus;
use App\Domain\Monitoring\Enums\DispatchTrigger;
use App\Domain\Monitoring\Jobs\ExecuteHttpProbe;
use App\Domain\Monitoring\Models\Monitor;
use App\Domain\Monitoring\Models\MonitorCheck;
use App\Domain\Monitoring\Models\MonitorDispatch;
use App\Domain\Organizations\Enums\OrganizationRole;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Inertia\Testing\AssertableInertia as Assert;

beforeEach(function () {
    $this->user = User::factory()->create();
    $this->organization = organizationWith($this->user, OrganizationRole::Member);
});

function monitorPayload(array $overrides = []): array
{
    return [
        'name' => 'Website',
        'url' => 'https://example.com/health',
        'method' => 'GET',
        'interval' => 60,
        'timeout' => 10,
        'expected_statuses' => '200-299, 301',
        'follow_redirects' => true,
        'headers' => [['name' => 'Authorization', 'value' => 'Bearer top-secret-token']],
        ...$overrides,
    ];
}

test('members can create a monitor that is due immediately', function () {
    $response = $this->actingAs($this->user)
        ->post(route('organizations.monitors.store', $this->organization), monitorPayload());

    $monitor = Monitor::sole();
    $response->assertRedirect(route('organizations.monitors.show', [$this->organization, $monitor]));

    expect($monitor->organization_id)->toBe($this->organization->id)
        ->and($monitor->enabled)->toBeTrue()
        ->and($monitor->next_check_at->isFuture())->toBeFalse()
        ->and($monitor->httpConfig->expected_statuses->toExpression())->toBe('200-299, 301')
        ->and($monitor->httpConfig->headers())->toBe(['Authorization' => 'Bearer top-secret-token']);
});

test('custom headers are encrypted at rest', function () {
    $this->actingAs($this->user)->post(route('organizations.monitors.store', $this->organization), monitorPayload());

    $raw = DB::table('monitor_http_configs')->value('headers_encrypted');

    expect($raw)->not->toContain('top-secret-token')
        ->and($raw)->not->toContain('Authorization');
});

test('header values never reach the browser', function () {
    $this->actingAs($this->user)->post(route('organizations.monitors.store', $this->organization), monitorPayload());
    $monitor = Monitor::sole();

    foreach (['show', 'edit'] as $page) {
        $response = $this->get(route("organizations.monitors.{$page}", [$this->organization, $monitor]))
            ->assertInertia(fn (Assert $inertia) => $inertia
                ->where('monitor.header_names', ['Authorization'])
                ->missing('monitor.headers')
                ->missing('monitor.headers_encrypted'));

        expect($response->getContent())->not->toContain('top-secret-token');
    }

    $this->get(route('organizations.monitors.index', $this->organization))
        ->assertInertia(fn (Assert $inertia) => $inertia->has('monitors.data', 1));
});

test('monitor input is validated', function (array $overrides, string $field) {
    $this->actingAs($this->user)
        ->post(route('organizations.monitors.store', $this->organization), monitorPayload($overrides))
        ->assertSessionHasErrors($field);

    expect(Monitor::count())->toBe(0);
})->with([
    'missing name' => [['name' => ''], 'name'],
    'bad scheme' => [['url' => 'ftp://example.com'], 'url'],
    'credentials in url' => [['url' => 'https://user:pass@example.com'], 'url'],
    'unknown method' => [['method' => 'POST'], 'method'],
    'unknown interval' => [['interval' => 30], 'interval'],
    'timeout too long' => [['timeout' => 45], 'timeout'],
    'timeout not below interval' => [['interval' => 60, 'timeout' => 60], 'timeout'],
    'bad statuses' => [['expected_statuses' => '200-700'], 'expected_statuses'],
    'forbidden header' => [['headers' => [['name' => 'Host', 'value' => 'internal']]], 'headers.0.name'],
    'proxy header' => [['headers' => [['name' => 'Proxy-Authorization', 'value' => 'x']]], 'headers.0.name'],
    'header injection' => [['headers' => [['name' => 'X-Test', 'value' => "a\r\nHost: evil"]]], 'headers.0.value'],
    'invalid header name' => [['headers' => [['name' => 'Bad Header', 'value' => 'x']]], 'headers.0.name'],
    'too many headers' => [['headers' => array_map(fn ($i) => ['name' => "X-H{$i}", 'value' => 'v'], range(1, 11))], 'headers'],
]);

test('private and internal destinations are refused when saving', function (string $url) {
    $this->dns->set('intranet.example.com', ['10.1.2.3']);

    $this->actingAs($this->user)
        ->post(route('organizations.monitors.store', $this->organization), monitorPayload(['url' => $url]))
        ->assertSessionHasErrors('url');
})->with([
    'http://127.0.0.1/',
    'http://169.254.169.254/latest/meta-data/',
    'http://[::1]/',
    'https://intranet.example.com/',
    'http://example.com:22/',
]);

test('operators can allow private destinations for self-hosted instances', function () {
    config(['veyra.probes.allow_private_networks' => true]);
    $this->dns->set('intranet.example.com', ['10.1.2.3']);

    $this->actingAs($this->user)
        ->post(route('organizations.monitors.store', $this->organization), monitorPayload(['url' => 'https://intranet.example.com/']))
        ->assertSessionHasNoErrors();

    $this->actingAs($this->user)
        ->post(route('organizations.monitors.store', $this->organization), monitorPayload(['url' => 'http://127.0.0.1/']))
        ->assertSessionHasErrors('url');
});

test('viewers can read monitors but not change them', function () {
    $viewer = memberOf($this->organization, OrganizationRole::Viewer);
    $monitor = Monitor::factory()->create(['organization_id' => $this->organization->id]);

    $this->actingAs($viewer)->get(route('organizations.monitors.index', $this->organization))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page->where('canCreate', false));
    $this->actingAs($viewer)->get(route('organizations.monitors.show', [$this->organization, $monitor]))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page->where('can.update', false));

    $this->actingAs($viewer)->get(route('organizations.monitors.create', $this->organization))->assertForbidden();
    $this->actingAs($viewer)->post(route('organizations.monitors.store', $this->organization), monitorPayload())->assertForbidden();
    $this->actingAs($viewer)->put(route('organizations.monitors.update', [$this->organization, $monitor]), monitorPayload(['headers_mode' => 'keep']))->assertForbidden();
    $this->actingAs($viewer)->delete(route('organizations.monitors.destroy', [$this->organization, $monitor]))->assertForbidden();
    $this->actingAs($viewer)->patch(route('organizations.monitors.enabled', [$this->organization, $monitor]), ['enabled' => false])->assertForbidden();
    $this->actingAs($viewer)->post(route('organizations.monitors.checks.store', [$this->organization, $monitor]))->assertForbidden();

    expect($monitor->fresh()->trashed())->toBeFalse();
});

test('monitors of another organization are not reachable', function () {
    $stranger = User::factory()->create();
    $foreignOrganization = organizationWith($stranger);
    $foreignMonitor = Monitor::factory()->create(['organization_id' => $foreignOrganization->id]);

    $this->actingAs($this->user)
        ->get(route('organizations.monitors.show', [$this->organization, $foreignMonitor]))
        ->assertNotFound();
    $this->actingAs($this->user)
        ->put(route('organizations.monitors.update', [$this->organization, $foreignMonitor]), monitorPayload(['headers_mode' => 'keep']))
        ->assertNotFound();
    $this->actingAs($this->user)
        ->delete(route('organizations.monitors.destroy', [$this->organization, $foreignMonitor]))
        ->assertNotFound();
    $this->actingAs($this->user)
        ->post(route('organizations.monitors.checks.store', [$this->organization, $foreignMonitor]))
        ->assertNotFound();
    $this->actingAs($this->user)
        ->get(route('organizations.monitors.index', $foreignOrganization))
        ->assertNotFound();

    $this->actingAs($this->user)
        ->get(route('organizations.monitors.index', $this->organization))
        ->assertInertia(fn (Assert $page) => $page->has('monitors.data', 0));

    expect($foreignMonitor->fresh()->trashed())->toBeFalse();
});

test('updating keeps stored headers unless they are replaced', function () {
    $this->actingAs($this->user)->post(route('organizations.monitors.store', $this->organization), monitorPayload());
    $monitor = Monitor::sole();

    $this->put(route('organizations.monitors.update', [$this->organization, $monitor]), monitorPayload([
        'name' => 'Renamed',
        'headers_mode' => 'keep',
        'headers' => [],
    ]))->assertSessionHasNoErrors();

    expect($monitor->fresh()->name)->toBe('Renamed')
        ->and($monitor->fresh()->httpConfig->headers())->toBe(['Authorization' => 'Bearer top-secret-token']);

    $this->put(route('organizations.monitors.update', [$this->organization, $monitor]), monitorPayload([
        'headers_mode' => 'replace',
        'headers' => [],
    ]))->assertSessionHasNoErrors();

    expect($monitor->fresh()->httpConfig->headers())->toBe([]);
});

test('monitors can be paused, resumed and soft deleted with history kept', function () {
    $monitor = Monitor::factory()->create(['organization_id' => $this->organization->id]);
    MonitorCheck::factory()->create(['monitor_id' => $monitor->id]);

    $this->actingAs($this->user)
        ->patch(route('organizations.monitors.enabled', [$this->organization, $monitor]), ['enabled' => false])
        ->assertRedirect();
    expect($monitor->fresh())->enabled->toBeFalse()->next_check_at->toBeNull();

    $this->patch(route('organizations.monitors.enabled', [$this->organization, $monitor]), ['enabled' => true]);
    expect($monitor->fresh())->enabled->toBeTrue()->next_check_at->not->toBeNull();

    $this->delete(route('organizations.monitors.destroy', [$this->organization, $monitor]))
        ->assertRedirect(route('organizations.monitors.index', $this->organization));

    expect(Monitor::withTrashed()->find($monitor->id)->trashed())->toBeTrue()
        ->and(MonitorCheck::where('monitor_id', $monitor->id)->count())->toBe(1);
    $this->get(route('organizations.monitors.show', [$this->organization, $monitor]))->assertNotFound();
});

test('check now queues a manual dispatch once per second', function () {
    Queue::fake();
    $monitor = Monitor::factory()->create(['organization_id' => $this->organization->id]);

    $this->actingAs($this->user)
        ->post(route('organizations.monitors.checks.store', [$this->organization, $monitor]))
        ->assertRedirect();

    $dispatch = MonitorDispatch::sole();
    expect($dispatch->trigger)->toBe(DispatchTrigger::Manual)
        ->and($dispatch->status)->toBe(DispatchStatus::Dispatched);
    Queue::assertPushedOn('probes', ExecuteHttpProbe::class);
});

test('check now is refused for paused monitors', function () {
    $monitor = Monitor::factory()->paused()->create(['organization_id' => $this->organization->id]);

    $this->actingAs($this->user)
        ->post(route('organizations.monitors.checks.store', [$this->organization, $monitor]))
        ->assertSessionHasErrors('monitor');

    expect(MonitorDispatch::count())->toBe(0);
});

test('check now is rate limited per monitor', function () {
    Queue::fake();
    $monitor = Monitor::factory()->create(['organization_id' => $this->organization->id]);

    $this->actingAs($this->user)->post(route('organizations.monitors.checks.store', [$this->organization, $monitor]))->assertRedirect();
    $this->actingAs($this->user)->post(route('organizations.monitors.checks.store', [$this->organization, $monitor]))->assertTooManyRequests();
});

test('monitor creation is rate limited', function () {
    config(['veyra.monitors.max_per_hour' => 2]);
    $this->actingAs($this->user);

    $this->post(route('organizations.monitors.store', $this->organization), monitorPayload())->assertSessionHasNoErrors();
    $this->post(route('organizations.monitors.store', $this->organization), monitorPayload())->assertSessionHasNoErrors();
    $this->post(route('organizations.monitors.store', $this->organization), monitorPayload())->assertTooManyRequests();
});

test('the history is shown newest first with cursor pagination', function () {
    $monitor = Monitor::factory()->create(['organization_id' => $this->organization->id]);
    MonitorCheck::factory()->count(55)->sequence(fn ($sequence) => ['checked_at' => now()->subMinutes($sequence->index)])->create(['monitor_id' => $monitor->id]);

    $this->actingAs($this->user)
        ->get(route('organizations.monitors.show', [$this->organization, $monitor]))
        ->assertInertia(fn (Assert $page) => $page
            ->component('monitors/Show')
            ->has('checks.data', 50)
            ->where('checks.prev_cursor', null)
            ->whereNot('checks.next_cursor', null)
            ->where('monitor.latest_check.id', $page->toArray()['props']['checks']['data'][0]['id']));
});

test('deleting an organization purges its monitoring history in batches', function () {
    $owner = User::factory()->create();
    $organization = organizationWith($owner);
    $monitor = Monitor::factory()->create(['organization_id' => $organization->id]);
    MonitorCheck::factory()->count(3)->create(['monitor_id' => $monitor->id]);
    MonitorDispatch::factory()->create(['monitor_id' => $monitor->id]);

    $this->actingAs($owner)
        ->delete(route('organizations.destroy', $organization), ['confirmation' => $organization->slug])
        ->assertRedirect();

    expect(Monitor::withTrashed()->count())->toBe(0)
        ->and(MonitorCheck::count())->toBe(0)
        ->and(MonitorDispatch::count())->toBe(0);
});
