<?php

use App\Domain\Monitoring\Enums\DnsMatchMode;
use App\Domain\Monitoring\Enums\DnsRecordType;
use App\Domain\Monitoring\Enums\DnsResolverChoice;
use App\Domain\Monitoring\Enums\MonitorType;
use App\Domain\Monitoring\Models\Monitor;
use App\Domain\Monitoring\Models\MonitorDomain;
use App\Domain\Organizations\Enums\OrganizationRole;
use App\Models\User;
use Inertia\Testing\AssertableInertia as Assert;

beforeEach(function () {
    $this->user = User::factory()->create();
    $this->organization = organizationWith($this->user, OrganizationRole::Member);
});

function dnsPayload(array $overrides = []): array
{
    return [
        'type' => 'dns',
        'name' => 'Mail routing',
        'record_name' => ' Example.COM. ',
        'record_type' => 'MX',
        'resolver' => 'cloudflare',
        'expected_values' => "10 Mail.example.com\r\n\r\nbackup.example.com\n10 mail.example.com",
        'match_mode' => 'exact',
        'interval' => 300,
        'timeout' => 10,
        ...$overrides,
    ];
}

test('members can create a DNS monitor with normalized expected values', function () {
    $this->actingAs($this->user)
        ->post(route('organizations.monitors.store', $this->organization), dnsPayload())
        ->assertSessionHasNoErrors();

    $monitor = Monitor::sole();
    $config = $monitor->dnsConfig;

    expect($monitor->type)->toBe(MonitorType::Dns)
        ->and($monitor->httpConfig)->toBeNull()
        ->and($monitor->tcpConfig)->toBeNull()
        ->and($config->record_name)->toBe('example.com')
        ->and($config->record_type)->toBe(DnsRecordType::Mx)
        ->and($config->resolver)->toBe(DnsResolverChoice::Cloudflare)
        ->and($config->expected_values)->toBe(['10 mail.example.com', 'backup.example.com'])
        ->and($config->match_mode)->toBe(DnsMatchMode::Exact)
        ->and($monitor->target())->toBe('MX example.com')
        ->and($monitor->next_check_at->isFuture())->toBeFalse()
        ->and($this->dnsQueries->queries)->toBe([]);
});

test('expected values are optional', function () {
    $this->actingAs($this->user)
        ->post(route('organizations.monitors.store', $this->organization), dnsPayload(['record_type' => 'A', 'expected_values' => '']))
        ->assertSessionHasNoErrors();

    expect(Monitor::sole()->dnsConfig->expected_values)->toBeNull();
});

test('DNS input is validated', function (array $overrides, string $field) {
    $this->actingAs($this->user)
        ->post(route('organizations.monitors.store', $this->organization), dnsPayload($overrides))
        ->assertSessionHasErrors($field);

    expect(Monitor::count())->toBe(0);
})->with([
    'missing name' => [['record_name' => ''], 'record_name'],
    'IP address' => [['record_name' => '93.184.215.14'], 'record_name'],
    'localhost' => [['record_name' => 'localhost'], 'record_name'],
    'internal name' => [['record_name' => 'db.internal'], 'record_name'],
    'invalid name' => [['record_name' => 'mail example.com'], 'record_name'],
    'unsupported type' => [['record_type' => 'PTR'], 'record_type'],
    'arbitrary resolver IP' => [['resolver' => '10.0.0.53'], 'resolver'],
    'unknown match mode' => [['match_mode' => 'regex'], 'match_mode'],
    'value invalid for the type' => [['record_type' => 'A', 'expected_values' => '2001:db8::1'], 'expected_values'],
    'too many values' => [['record_type' => 'TXT', 'expected_values' => implode("\n", range(1, 21))], 'expected_values'],
    'timeout above 30 s' => [['timeout' => 31], 'timeout'],
]);

test('only the resolvers enabled by the operator can be chosen', function () {
    config(['veyra.dns.resolvers' => ['system', 'quad9']]);

    $this->actingAs($this->user)
        ->post(route('organizations.monitors.store', $this->organization), dnsPayload(['resolver' => 'cloudflare']))
        ->assertSessionHasErrors('resolver');

    $this->get(route('organizations.monitors.create', $this->organization))
        ->assertInertia(fn (Assert $page) => $page
            ->where('dnsResolvers.0.value', 'system')
            ->where('dnsResolvers.1.value', 'quad9')
            ->has('dnsResolvers', 2)
            ->where('dnsRecordTypes', ['A', 'AAAA', 'CNAME', 'MX', 'TXT', 'NS']));
});

test('changing the query clears the last answers, other edits keep them', function () {
    $monitor = Monitor::factory()->dns(['last_answers' => ['93.184.215.14'], 'last_resolved_at' => now()])->create(['organization_id' => $this->organization->id]);
    $update = fn (array $overrides) => $this->actingAs($this->user)
        ->put(route('organizations.monitors.update', [$this->organization, $monitor]), dnsPayload(['record_type' => 'A', 'record_name' => 'example.com', 'expected_values' => '', 'match_mode' => 'contains', ...$overrides]))
        ->assertSessionHasNoErrors();

    $update(['expected_values' => '93.184.215.14', 'name' => 'Website A record']);
    expect($monitor->dnsConfig()->sole()->last_answers)->toBe(['93.184.215.14']);

    $update(['resolver' => 'google']);
    expect($monitor->dnsConfig()->sole())->last_answers->toBeNull()->last_resolved_at->toBeNull();
});

test('the type of a DNS monitor cannot change on update', function () {
    $monitor = Monitor::factory()->dns()->create(['organization_id' => $this->organization->id]);

    $this->actingAs($this->user)
        ->put(route('organizations.monitors.update', [$this->organization, $monitor]), dnsPayload(['type' => 'http', 'url' => 'https://example.com']))
        ->assertSessionHasNoErrors();

    expect($monitor->fresh()->type)->toBe(MonitorType::Dns)
        ->and($monitor->fresh()->httpConfig)->toBeNull();
});

test('the detail page shows the query, last answers and domain registration', function () {
    $this->travelTo('2026-11-22 09:00:00');
    $monitor = Monitor::factory()->dns([
        'record_name' => 'www.example.com',
        'last_answers' => ['93.184.215.14'],
        'last_resolved_at' => now(),
    ])->create(['organization_id' => $this->organization->id]);
    MonitorDomain::query()->create([
        'monitor_id' => $monitor->id,
        'host' => 'www.example.com',
        'domain' => 'example.com',
        'expires_at' => '2026-12-01 00:00:00',
        'registrar' => 'Example Registrar, Inc.',
        'checked_at' => now(),
    ]);

    $this->actingAs($this->user)
        ->get(route('organizations.monitors.show', [$this->organization, $monitor]))
        ->assertInertia(fn (Assert $page) => $page
            ->where('monitor.type', 'dns')
            ->where('monitor.target', 'A www.example.com')
            ->where('monitor.dns.resolver', 'cloudflare')
            ->where('monitor.dns.last_answers', ['93.184.215.14'])
            ->where('monitor.domain.domain', 'example.com')
            ->where('monitor.domain.registrar', 'Example Registrar, Inc.')
            ->where('monitor.domain.days_left', 8)
            ->where('monitor.domain.status', 'expiring')
            ->where('monitor.certificate', null));
});

test('viewers cannot create or edit DNS monitors', function (string $method) {
    $viewer = memberOf($this->organization, OrganizationRole::Viewer);
    $monitor = Monitor::factory()->dns()->create(['organization_id' => $this->organization->id]);
    $url = $method === 'post'
        ? route('organizations.monitors.store', $this->organization)
        : route('organizations.monitors.update', [$this->organization, $monitor]);

    $this->actingAs($viewer)->{$method}($url, dnsPayload(['record_name' => 'renamed.example.com']))->assertForbidden();

    expect($monitor->dnsConfig()->sole()->record_name)->toBe('example.com');
})->with(['post', 'put']);

test('DNS monitors of another organization are not reachable', function () {
    $foreign = Monitor::factory()->dns()->create(['organization_id' => organizationWith(User::factory()->create())->id]);

    $this->actingAs($this->user)
        ->get(route('organizations.monitors.show', [$this->organization, $foreign]))
        ->assertNotFound();
    $this->actingAs($this->user)
        ->put(route('organizations.monitors.update', [$this->organization, $foreign]), dnsPayload())
        ->assertNotFound();
    $this->actingAs($this->user)
        ->post(route('organizations.monitors.checks.store', [$this->organization, $foreign]))
        ->assertNotFound();

    expect($foreign->dnsConfig()->sole()->record_type)->toBe(DnsRecordType::A);
});
