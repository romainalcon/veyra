<?php

use App\Domain\Audit\Enums\AuditAction;
use App\Domain\Audit\Models\AuditLog;
use App\Domain\Entitlements\Contracts\Entitlements;
use App\Domain\Entitlements\Enums\Feature;
use App\Domain\Monitoring\Actions\SyncMonitorTags;
use App\Domain\Monitoring\Models\Monitor;
use App\Domain\Monitoring\Transfer\MonitorExport;
use App\Domain\Organizations\Enums\OrganizationRole;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Support\FakeEntitlements;

beforeEach(function () {
    $this->user = User::factory()->create();
    $this->organization = organizationWith($this->user, OrganizationRole::Member);
});

function exportFile(array $monitors, array $envelope = []): UploadedFile
{
    return UploadedFile::fake()->createWithContent('monitors.json', json_encode([
        'format' => MonitorExport::FORMAT,
        'version' => MonitorExport::VERSION,
        'monitors' => $monitors,
        ...$envelope,
    ]));
}

function tcpEntry(array $overrides = []): array
{
    return ['name' => 'Database', 'type' => 'tcp', 'enabled' => true, 'interval_seconds' => 60, 'timeout_seconds' => 5, 'tags' => [], 'tcp' => ['host' => 'example.com', 'port' => 443], ...$overrides];
}

function httpEntry(array $overrides = []): array
{
    return ['name' => 'Website', 'type' => 'http', 'enabled' => true, 'interval_seconds' => 60, 'timeout_seconds' => 10, 'tags' => ['web'], 'http' => ['url' => 'https://example.com/health', 'method' => 'GET', 'expected_statuses' => '200-299', 'follow_redirects' => true], ...$overrides];
}

/**
 * Creates one monitor of each type through the interface, with secrets.
 */
function createMonitorsWithSecrets(object $test): void
{
    $test->actingAs($test->user);
    $org = $test->organization;

    $test->post(route('organizations.monitors.store', $org), [
        'type' => 'http', 'name' => 'Checkout', 'url' => 'https://example.com/api?ref=1', 'method' => 'POST', 'interval' => 60, 'timeout' => 10,
        'expected_statuses' => '200, 201', 'follow_redirects' => false, 'keyword' => 'ok', 'keyword_mode' => 'contains', 'latency_threshold_ms' => 2000,
        'headers' => [['name' => 'X-Secret-Header', 'value' => 'header-value-secret']],
        'body' => '{"token":"body-secret"}', 'body_content_type' => 'application/json',
        'basic_auth_username' => 'probe-user', 'basic_auth_password' => 'basic-password-secret',
        'tags_submitted' => '1', 'tags' => ['production', 'web'],
    ])->assertSessionHasNoErrors();
    $test->post(route('organizations.monitors.store', $org), ['type' => 'tcp', 'name' => 'Postgres', 'host' => 'example.com', 'port' => 443, 'interval' => 300, 'timeout' => 5])->assertSessionHasNoErrors();
    $test->post(route('organizations.monitors.store', $org), ['type' => 'dns', 'name' => 'MX', 'record_name' => 'example.com', 'record_type' => 'MX', 'resolver' => 'cloudflare', 'expected_values' => '10 mx.example.com', 'match_mode' => 'contains', 'interval' => 300, 'timeout' => 5])->assertSessionHasNoErrors();
    $test->post(route('organizations.monitors.store', $org), ['type' => 'heartbeat', 'name' => 'Nightly backup', 'interval' => 86400, 'grace_minutes' => 30])->assertSessionHasNoErrors();
    $test->patch(route('organizations.monitors.enabled', [$org, Monitor::query()->where('name', 'Postgres')->sole()]), ['enabled' => false]);
}

test('the export is versioned, secret-free and audited', function () {
    createMonitorsWithSecrets($this);

    $response = $this->get(route('organizations.monitors.export', $this->organization))->assertOk();
    $content = $response->streamedContent();
    $document = json_decode($content, true);

    expect($response->headers->get('content-disposition'))->toContain('veyra-monitors-')
        ->and($document['format'])->toBe('veyra.monitors')
        ->and($document['version'])->toBe(1)
        ->and(collect($document['monitors'])->pluck('name')->all())->toBe(['Checkout', 'MX', 'Nightly backup', 'Postgres']);

    foreach (['header-value-secret', 'X-Secret-Header', 'body-secret', 'basic-password-secret', 'probe-user', 'token_hash', 'escalation', 'notification'] as $secret) {
        expect($content)->not->toContain($secret);
    }

    expect($document['monitors'][0])->toMatchArray([
        'type' => 'http',
        'tags' => ['production', 'web'],
        'http' => ['url' => 'https://example.com/api?ref=1', 'method' => 'POST', 'expected_statuses' => '200, 201', 'follow_redirects' => false, 'keyword' => 'ok', 'keyword_mode' => 'contains', 'keyword_case_sensitive' => false, 'latency_threshold_ms' => 2000],
        'excluded' => ['headers', 'body', 'basic_auth'],
    ])
        ->and($document['monitors'][2])->toMatchArray(['type' => 'heartbeat', 'heartbeat' => ['grace_minutes' => 30], 'excluded' => ['ping_token']])
        ->and($document['monitors'][3]['enabled'])->toBeFalse();

    expect(AuditLog::query()->where('action', AuditAction::MonitorsExported)->sole()->metadata)->toBe(['count' => 4]);
});

test('the export can be limited to a tag; viewers may export', function () {
    $tagged = Monitor::factory()->create(['organization_id' => $this->organization->id, 'name' => 'Tagged']);
    app(SyncMonitorTags::class)->handle($tagged, ['production']);
    Monitor::factory()->create(['organization_id' => $this->organization->id, 'name' => 'Other']);

    $document = json_decode($this->actingAs(memberOf($this->organization, OrganizationRole::Viewer))
        ->get(route('organizations.monitors.export', [$this->organization, 'tag' => 'production']))
        ->assertOk()
        ->streamedContent(), true);

    expect(collect($document['monitors'])->pluck('name')->all())->toBe(['Tagged']);
});

test('an export imported into another organization recreates the monitors', function () {
    createMonitorsWithSecrets($this);
    $content = $this->get(route('organizations.monitors.export', $this->organization))->streamedContent();

    $target = organizationWith($this->user, OrganizationRole::Admin);
    $this->post(route('organizations.monitors.import.store', $target), ['file' => UploadedFile::fake()->createWithContent('export.json', $content)])
        ->assertRedirect(route('organizations.monitors.index', $target))
        ->assertSessionHasNoErrors();

    $imported = $target->monitors()->with(['httpConfig', 'tcpConfig', 'dnsConfig', 'heartbeatConfig', 'tags'])->orderBy('name')->get();
    $http = $imported->firstWhere('name', 'Checkout');

    expect($imported->pluck('name')->all())->toBe(['Checkout', 'MX', 'Nightly backup', 'Postgres'])
        ->and($http->httpConfig->url)->toBe('https://example.com/api?ref=1')
        ->and($http->httpConfig->expected_statuses->toExpression())->toBe('200, 201')
        ->and($http->httpConfig->latency_threshold_ms)->toBe(2000)
        ->and($http->httpConfig->headerNames())->toBe([])
        ->and($http->httpConfig->hasBody())->toBeFalse()
        ->and($http->tags->pluck('name')->all())->toBe(['production', 'web'])
        ->and($imported->firstWhere('name', 'MX')->dnsConfig->expected_values)->toBe(['10 mx.example.com'])
        ->and($imported->firstWhere('name', 'Nightly backup')->heartbeatConfig->grace_seconds)->toBe(1800)
        ->and($imported->firstWhere('name', 'Postgres')->enabled)->toBeFalse()
        ->and($imported->firstWhere('name', 'Checkout')->enabled)->toBeTrue();

    // A POST with a body was exported without it: it is recreated as a POST without a body.
    expect($http->httpConfig->method->value)->toBe('POST');
});

test('the dry run reports every entry and writes nothing', function () {
    Monitor::factory()->create(['organization_id' => $this->organization->id, 'type' => 'tcp', 'name' => 'Existing']);
    $existing = Monitor::query()->sole()->tcpConfig;
    $existing->update(['host' => 'example.com', 'port' => 22]);

    $response = $this->actingAs($this->user)
        ->post(route('organizations.monitors.import.preview', $this->organization), ['file' => exportFile([
            tcpEntry(['tcp' => ['host' => $existing->host, 'port' => $existing->port]]),
            httpEntry(['excluded' => ['headers']]),
            httpEntry(['name' => 'Metadata', 'http' => ['url' => 'http://169.254.169.254/latest/meta-data', 'method' => 'GET', 'expected_statuses' => '200']]),
            tcpEntry(['name' => 'SSH', 'tcp' => ['host' => 'example.com', 'port' => 2222]]),
            tcpEntry(['name' => 'Copy', 'tcp' => ['host' => 'example.com', 'port' => 443]]),
            tcpEntry(['name' => 'Copy again', 'tcp' => ['host' => 'EXAMPLE.com', 'port' => 443]]),
        ])])
        ->assertRedirect(route('organizations.monitors.import.create', $this->organization))
        ->assertSessionHasNoErrors();

    expect(Monitor::query()->count())->toBe(1);

    $preview = $response->getSession()->get('inertia.flash_data')['importPreview'];
    $statuses = array_column($preview['rows'], 'status');

    expect($preview['summary'])->toBe(['total' => 6, 'valid' => 2, 'duplicates' => 2, 'invalid' => 2])
        ->and($statuses)->toBe(['duplicate', 'valid', 'invalid', 'invalid', 'valid', 'duplicate'])
        ->and($preview['rows'][1]['excluded'])->toBe(['headers'])
        ->and($preview['rows'][1]['tags'])->toBe(['web'])
        ->and($preview['rows'][2]['errors'])->not->toBeEmpty()
        ->and($preview['rows'][3]['errors'])->toBe(['This port is not allowed for TCP monitors on this instance.'])
        ->and($preview['fitsQuota'])->toBeTrue()
        ->and(json_encode($preview))->not->toContain('MonitorData');

    $this->get(route('organizations.monitors.import.create', $this->organization))
        ->assertInertia(fn (Assert $page) => $page->component('monitors/Import')->where('maxMonitors', 100));
});

test('an invalid entry blocks the whole import', function () {
    $this->actingAs($this->user)
        ->post(route('organizations.monitors.import.store', $this->organization), ['file' => exportFile([
            tcpEntry(),
            httpEntry(['http' => ['url' => 'http://127.0.0.1/', 'method' => 'GET', 'expected_statuses' => '200']]),
        ])])
        ->assertSessionHasErrors('file');

    expect(Monitor::query()->count())->toBe(0)
        ->and(AuditLog::query()->count())->toBe(0);
});

test('secrets added to a file by hand are ignored', function () {
    $this->actingAs($this->user)
        ->post(route('organizations.monitors.import.store', $this->organization), ['file' => exportFile([
            httpEntry(['http' => ['url' => 'https://example.com/', 'method' => 'GET', 'expected_statuses' => '200', 'headers' => [['name' => 'Authorization', 'value' => 'x']]], 'headers' => [['name' => 'Authorization', 'value' => 'x']], 'notification_channel_ids' => ['x']]),
        ])])
        ->assertSessionHasNoErrors();

    expect(Monitor::query()->sole()->httpConfig->headerNames())->toBe([]);
});

test('existing monitors are skipped unless asked otherwise', function () {
    $file = fn () => exportFile([tcpEntry()]);

    $this->actingAs($this->user)->post(route('organizations.monitors.import.store', $this->organization), ['file' => $file()])->assertSessionHasNoErrors();
    $this->post(route('organizations.monitors.import.store', $this->organization), ['file' => $file()])->assertSessionHasNoErrors();
    expect(Monitor::query()->count())->toBe(1);

    $this->post(route('organizations.monitors.import.store', $this->organization), ['file' => $file(), 'skip_existing' => '0'])->assertSessionHasNoErrors();
    expect(Monitor::query()->count())->toBe(2);
});

test('the active monitor quota applies to the whole import', function () {
    $this->app->instance(Entitlements::class, (new FakeEntitlements)->set(Feature::Monitors, 2));
    Monitor::factory()->create(['organization_id' => $this->organization->id]);

    $file = fn () => exportFile([tcpEntry(['name' => 'Service A', 'tcp' => ['host' => 'example.com', 'port' => 22]]), tcpEntry(['name' => 'Service B', 'tcp' => ['host' => 'example.com', 'port' => 80]])]);

    $preview = $this->actingAs($this->user)->post(route('organizations.monitors.import.preview', $this->organization), ['file' => $file()])
        ->getSession()->get('inertia.flash_data')['importPreview'];
    expect($preview['fitsQuota'])->toBeFalse()
        ->and($preview['quota'])->toBe(['used' => 1, 'limit' => 2]);

    $this->post(route('organizations.monitors.import.store', $this->organization), ['file' => $file()])->assertSessionHasErrors('limit');
    expect(Monitor::query()->count())->toBe(1);

    // Paused entries fit as long as one slot is free.
    $this->post(route('organizations.monitors.import.store', $this->organization), ['file' => exportFile([
        tcpEntry(['name' => 'Service A', 'enabled' => false, 'tcp' => ['host' => 'example.com', 'port' => 22]]),
        tcpEntry(['name' => 'Service B', 'enabled' => false, 'tcp' => ['host' => 'example.com', 'port' => 80]]),
        tcpEntry(['name' => 'Service C', 'tcp' => ['host' => 'example.com', 'port' => 25]]),
    ])])->assertSessionHasNoErrors();

    expect(Monitor::query()->count())->toBe(4)
        ->and(Monitor::query()->where('enabled', true)->count())->toBe(2);
});

test('malformed files are refused', function (Closure $file, string $message) {
    $this->actingAs($this->user)
        ->post(route('organizations.monitors.import.preview', $this->organization), ['file' => $file()])
        ->assertSessionHasErrors(['file' => $message]);
})->with([
    'not JSON' => [fn () => UploadedFile::fake()->createWithContent('x.json', '{oops'), 'The file is not valid JSON.'],
    'other format' => [fn () => exportFile([tcpEntry()], ['format' => 'other']), 'This is not a Veyra monitor export (format veyra.monitors, version 1).'],
    'future version' => [fn () => exportFile([tcpEntry()], ['version' => 2]), 'This is not a Veyra monitor export (format veyra.monitors, version 1).'],
    'empty' => [fn () => exportFile([]), 'The file must contain between 1 and 100 monitors.'],
    'too many' => [fn () => exportFile(array_fill(0, 101, tcpEntry())), 'The file must contain between 1 and 100 monitors.'],
]);

test('viewers cannot import', function () {
    $this->actingAs(memberOf($this->organization, OrganizationRole::Viewer))
        ->post(route('organizations.monitors.import.store', $this->organization), ['file' => exportFile([tcpEntry()])])
        ->assertForbidden();

    $this->get(route('organizations.monitors.import.create', $this->organization))->assertForbidden();
    expect(Monitor::query()->count())->toBe(0);
});

test('imports are rate limited', function () {
    config(['veyra.monitors.imports_per_hour' => 2]);
    $this->actingAs($this->user);

    foreach (range(1, 2) as $attempt) {
        $this->post(route('organizations.monitors.import.preview', $this->organization), ['file' => exportFile([tcpEntry()])])->assertRedirect();
    }

    $this->post(route('organizations.monitors.import.preview', $this->organization), ['file' => exportFile([tcpEntry()])])->assertTooManyRequests();
});
