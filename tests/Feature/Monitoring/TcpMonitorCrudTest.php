<?php

use App\Domain\Monitoring\Enums\MonitorType;
use App\Domain\Monitoring\Models\Monitor;
use App\Domain\Monitoring\Probes\Contracts\DnsResolver;
use App\Domain\Organizations\Enums\OrganizationRole;
use App\Models\User;
use Inertia\Testing\AssertableInertia as Assert;

beforeEach(function () {
    $this->user = User::factory()->create();
    $this->organization = organizationWith($this->user, OrganizationRole::Member);
    config(['veyra.probes.tcp_allowed_ports' => [22, 443, 5432]]);
});

function tcpPayload(array $overrides = []): array
{
    return [
        'type' => 'tcp',
        'name' => 'Database',
        'host' => ' db.example.com ',
        'port' => 5432,
        'interval' => 60,
        'timeout' => 5,
        ...$overrides,
    ];
}

test('members can create a TCP monitor without HTTP fields', function () {
    $this->dns->set('db.example.com', ['93.184.215.40']);

    $this->actingAs($this->user)
        ->post(route('organizations.monitors.store', $this->organization), tcpPayload())
        ->assertSessionHasNoErrors();

    $monitor = Monitor::sole();

    expect($monitor->type)->toBe(MonitorType::Tcp)
        ->and($monitor->httpConfig)->toBeNull()
        ->and($monitor->tcpConfig->host)->toBe('db.example.com')
        ->and($monitor->tcpConfig->port)->toBe(5432)
        ->and($monitor->next_check_at->isFuture())->toBeFalse()
        ->and($monitor->target())->toBe('db.example.com:5432');
});

test('TCP input is validated', function (array $overrides, string $field) {
    $this->dns->set('db.example.com', ['93.184.215.40']);
    $this->dns->set('internal.example.com', ['172.16.0.9']);

    $this->actingAs($this->user)
        ->post(route('organizations.monitors.store', $this->organization), tcpPayload($overrides))
        ->assertSessionHasErrors($field);

    expect(Monitor::count())->toBe(0);
})->with([
    'missing host' => [['host' => ''], 'host'],
    'port not allowed' => [['port' => 6379], 'port'],
    'port out of range' => [['port' => 70000], 'port'],
    'loopback' => [['host' => '127.0.0.1'], 'host'],
    'IPv6 loopback' => [['host' => '[::1]'], 'host'],
    'metadata' => [['host' => '169.254.169.254'], 'host'],
    'private answer' => [['host' => 'internal.example.com'], 'host'],
    'localhost' => [['host' => 'localhost'], 'host'],
    'invalid host' => [['host' => 'db example.com'], 'host'],
    'url instead of host' => [['host' => 'https://db.example.com'], 'host'],
    'timeout not below interval' => [['interval' => 60, 'timeout' => 60], 'timeout'],
]);

test('unknown types cannot be created', function (string $type) {
    $this->actingAs($this->user)
        ->post(route('organizations.monitors.store', $this->organization), tcpPayload(['type' => $type]))
        ->assertSessionHasErrors('type');

    expect(Monitor::count())->toBe(0);
})->with(['ftp', 'dns']);

test('a TCP monitor can be updated but its type cannot change', function () {
    $monitor = Monitor::factory()->tcp('example.com', 443)->create(['organization_id' => $this->organization->id]);

    $this->actingAs($this->user)
        ->put(route('organizations.monitors.update', [$this->organization, $monitor]), tcpPayload([
            'type' => 'http',
            'url' => 'https://example.com/',
            'host' => 'example.com',
            'port' => 22,
        ]))
        ->assertSessionHasNoErrors();

    $monitor->refresh();

    expect($monitor->type)->toBe(MonitorType::Tcp)
        ->and($monitor->httpConfig)->toBeNull()
        ->and($monitor->tcpConfig->port)->toBe(22);
});

test('pages expose the TCP target and the allowed ports', function () {
    $monitor = Monitor::factory()->tcp('example.com', 22)->create(['organization_id' => $this->organization->id]);

    $this->actingAs($this->user)
        ->get(route('organizations.monitors.show', [$this->organization, $monitor]))
        ->assertInertia(fn (Assert $page) => $page
            ->where('monitor.type', 'tcp')
            ->where('monitor.target', 'example.com:22')
            ->where('monitor.host', 'example.com')
            ->where('monitor.port', 22)
            ->where('monitor.url', null));

    $this->get(route('organizations.monitors.create', $this->organization))
        ->assertInertia(fn (Assert $page) => $page
            ->where('tcpPorts', [22, 443, 5432])
            ->where('types.1.value', 'tcp')
            ->where('types.2.value', 'heartbeat'));
});

test('viewers are refused before validation and without DNS lookups', function (string $method) {
    $viewer = memberOf($this->organization, OrganizationRole::Viewer);
    $monitor = Monitor::factory()->tcp()->create(['organization_id' => $this->organization->id]);
    $dns = Mockery::spy(DnsResolver::class);
    $this->app->instance(DnsResolver::class, $dns);

    $url = $method === 'post'
        ? route('organizations.monitors.store', $this->organization)
        : route('organizations.monitors.update', [$this->organization, $monitor]);

    $this->actingAs($viewer)
        ->{$method}($url, tcpPayload(['host' => 'invalid host', 'port' => 1]))
        ->assertForbidden()
        ->assertSessionHasNoErrors();

    $dns->shouldNotHaveReceived('resolve');
})->with(['post', 'put']);
