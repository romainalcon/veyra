<?php

use App\Domain\Incidents\Models\Incident;
use App\Domain\Monitoring\Enums\HttpMethod;
use App\Domain\Monitoring\Enums\KeywordMode;
use App\Domain\Monitoring\Enums\ProbeErrorCode;
use App\Domain\Monitoring\Enums\RequestContentType;
use App\Domain\Monitoring\Models\Monitor;
use App\Domain\Notifications\Enums\DeliveryStatus;
use App\Domain\Notifications\Enums\NotificationEvent;
use App\Domain\Notifications\Jobs\SendNotificationDelivery;
use App\Domain\Notifications\Models\NotificationChannel;
use App\Domain\Notifications\Models\NotificationDelivery;
use App\Domain\Organizations\Enums\OrganizationRole;
use App\Models\User;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Inertia\Testing\AssertableInertia as Assert;

beforeEach(function () {
    $this->user = User::factory()->create();
    $this->organization = organizationWith($this->user, OrganizationRole::Member);
});

function advancedPayload(array $overrides = []): array
{
    return [
        'name' => 'Login API',
        'url' => 'https://example.com/login',
        'method' => 'POST',
        'interval' => 60,
        'timeout' => 10,
        'expected_statuses' => '200-299',
        'follow_redirects' => true,
        'headers' => [],
        'body' => '{"user":"probe","token":"body-secret-token"}',
        'body_content_type' => 'application/json',
        'basic_auth_username' => 'robot',
        'basic_auth_password' => 'basic-secret-password',
        'keyword' => '"ok":true',
        'keyword_mode' => 'contains',
        'keyword_case_sensitive' => true,
        'latency_threshold_ms' => 800,
        ...$overrides,
    ];
}

function createAdvancedMonitor(array $overrides = []): Monitor
{
    test()->actingAs(test()->user)
        ->post(route('organizations.monitors.store', test()->organization), advancedPayload($overrides))
        ->assertSessionHasNoErrors();

    return Monitor::sole();
}

test('members create a POST monitor with body, basic auth, keyword and latency threshold', function () {
    $config = createAdvancedMonitor()->httpConfig;

    expect($config->method)->toBe(HttpMethod::Post)
        ->and($config->body_encrypted)->toBe('{"user":"probe","token":"body-secret-token"}')
        ->and($config->body_content_type)->toBe(RequestContentType::Json)
        ->and($config->basic_auth_encrypted)->toBe(['username' => 'robot', 'password' => 'basic-secret-password'])
        ->and($config->keyword)->toBe('"ok":true')
        ->and($config->keyword_mode)->toBe(KeywordMode::Contains)
        ->and($config->keyword_case_sensitive)->toBeTrue()
        ->and($config->latency_threshold_ms)->toBe(800);
});

test('the body and the basic auth credentials are encrypted at rest', function () {
    createAdvancedMonitor();

    $row = (array) DB::table('monitor_http_configs')->first();

    expect($row['body_encrypted'])->not->toContain('body-secret-token')
        ->and($row['basic_auth_encrypted'])->not->toContain('basic-secret-password')
        ->and($row['basic_auth_encrypted'])->not->toContain('robot');
});

test('the body and the password never reach the browser', function () {
    $monitor = createAdvancedMonitor();

    foreach (['show', 'edit'] as $page) {
        $response = $this->get(route("organizations.monitors.{$page}", [$this->organization, $monitor]))
            ->assertInertia(fn (Assert $inertia) => $inertia
                ->where('monitor.body', ['content_type' => 'application/json', 'bytes' => 44])
                ->where('monitor.basic_auth_username', 'robot')
                ->where('monitor.latency_threshold_ms', 800)
                ->missing('monitor.body_encrypted')
                ->missing('monitor.basic_auth_encrypted'));

        expect($response->getContent())->not->toContain('body-secret-token')
            ->and($response->getContent())->not->toContain('basic-secret-password');
    }
});

test('webhook payloads never contain the request body or credentials', function () {
    Http::fake(['api.example.com/*' => Http::response('', 204)]);
    $monitor = createAdvancedMonitor();
    $channel = NotificationChannel::factory()->webhook('https://api.example.com/hooks')->create(['organization_id' => $this->organization->id]);
    $incident = Incident::factory()->create(['organization_id' => $this->organization->id, 'monitor_id' => $monitor->id, 'cause' => ProbeErrorCode::KeywordMissing->label()]);
    $delivery = NotificationDelivery::create([
        'organization_id' => $this->organization->id,
        'incident_id' => $incident->id,
        'channel_id' => $channel->id,
        'event_type' => NotificationEvent::Opened,
        'status' => DeliveryStatus::Pending,
    ]);

    $job = (new SendNotificationDelivery($delivery->id))->withFakeQueueInteractions();
    app()->call([$job, 'handle']);

    Http::assertSent(fn (Request $request): bool => str_contains($request->body(), 'Expected keyword not found')
        && ! str_contains($request->body(), 'body-secret-token')
        && ! str_contains($request->body(), 'basic-secret-password')
        && ! str_contains($request->body(), 'robot')
        && ! str_contains($request->body(), '"ok":true'));
});

test('advanced HTTP input is validated', function (array $overrides, string $field) {
    $this->actingAs($this->user)
        ->post(route('organizations.monitors.store', $this->organization), advancedPayload($overrides))
        ->assertSessionHasErrors($field);

    expect(Monitor::count())->toBe(0);
})->with([
    'body with GET' => [['method' => 'GET'], 'body'],
    'body too large' => [['body' => str_repeat('a', 16_385), 'body_content_type' => 'text/plain'], 'body'],
    'invalid JSON' => [['body' => '{"user":'], 'body'],
    'unknown content type' => [['body_content_type' => 'application/octet-stream'], 'body_content_type'],
    'body without content type' => [['body_content_type' => null], 'body_content_type'],
    'Content-Type header with a body' => [['headers' => [['name' => 'content-type', 'value' => 'text/plain']]], 'headers'],
    'Authorization header with basic auth' => [['headers' => [['name' => 'Authorization', 'value' => 'Bearer x']]], 'basic_auth_username'],
    'colon in user name' => [['basic_auth_username' => 'ro:bot'], 'basic_auth_username'],
    'user name without password' => [['basic_auth_password' => null], 'basic_auth_password'],
    'control character in password' => [['basic_auth_password' => "pass\nword"], 'basic_auth_password'],
    'keyword with HEAD' => [['method' => 'HEAD', 'body' => null, 'body_content_type' => null], 'keyword'],
    'keyword too long' => [['keyword' => str_repeat('k', 256)], 'keyword'],
    'unknown keyword mode' => [['keyword_mode' => 'regex'], 'keyword_mode'],
    'threshold too low' => [['latency_threshold_ms' => 50], 'latency_threshold_ms'],
    'threshold not below the timeout' => [['timeout' => 5, 'latency_threshold_ms' => 5000], 'latency_threshold_ms'],
]);

test('a body is accepted at the size limit', function () {
    $monitor = createAdvancedMonitor(['body' => str_repeat('a', 16_384), 'body_content_type' => 'text/plain']);

    expect(strlen($monitor->httpConfig->body_encrypted))->toBe(16_384);
});

test('editing keeps the stored body and credentials unless they are replaced', function () {
    $monitor = createAdvancedMonitor();

    $this->put(route('organizations.monitors.update', [$this->organization, $monitor]), advancedPayload([
        'name' => 'Renamed',
        'headers_mode' => 'keep',
        'body_mode' => 'keep',
        'auth_mode' => 'keep',
        'body' => null,
        'body_content_type' => null,
        'basic_auth_username' => null,
        'basic_auth_password' => null,
    ]))->assertSessionHasNoErrors();

    $config = $monitor->fresh()->httpConfig;
    expect($config->body_encrypted)->toContain('body-secret-token')
        ->and($config->basic_auth_encrypted['password'])->toBe('basic-secret-password');

    $this->put(route('organizations.monitors.update', [$this->organization, $monitor]), advancedPayload([
        'headers_mode' => 'keep',
        'body_mode' => 'replace',
        'auth_mode' => 'replace',
        'body' => 'a=1',
        'body_content_type' => 'application/x-www-form-urlencoded',
        'basic_auth_username' => null,
        'basic_auth_password' => null,
    ]))->assertSessionHasNoErrors();

    $config = $monitor->fresh()->httpConfig;
    expect($config->body_encrypted)->toBe('a=1')
        ->and($config->body_content_type)->toBe(RequestContentType::Form)
        ->and($config->basic_auth_encrypted)->toBeNull();
});

test('switching to GET drops the stored body; HEAD drops the keyword', function () {
    $monitor = createAdvancedMonitor();
    $update = fn (array $overrides) => $this->put(route('organizations.monitors.update', [$this->organization, $monitor]), advancedPayload([
        'headers_mode' => 'keep', 'body_mode' => 'keep', 'auth_mode' => 'keep', 'body' => null, 'body_content_type' => null,
        'basic_auth_username' => null, 'basic_auth_password' => null, ...$overrides,
    ]))->assertSessionHasNoErrors();

    $update(['method' => 'GET']);
    expect($monitor->fresh()->httpConfig->body_encrypted)->toBeNull()
        ->and($monitor->fresh()->httpConfig->keyword)->toBe('"ok":true');

    $update(['method' => 'HEAD', 'keyword' => null]);
    expect($monitor->fresh()->httpConfig->keyword)->toBeNull()
        ->and($monitor->fresh()->httpConfig->basic_auth_encrypted['username'])->toBe('robot');
});

test('a kept Authorization header still conflicts with new basic auth', function () {
    $monitor = createAdvancedMonitor(['basic_auth_username' => null, 'basic_auth_password' => null, 'headers' => [['name' => 'Authorization', 'value' => 'Bearer t']]]);

    $this->put(route('organizations.monitors.update', [$this->organization, $monitor]), advancedPayload([
        'headers_mode' => 'keep', 'body_mode' => 'keep', 'auth_mode' => 'replace', 'body' => null, 'body_content_type' => null,
    ]))->assertSessionHasErrors('basic_auth_username');
});

test('viewers and other organizations cannot change advanced settings', function () {
    $monitor = createAdvancedMonitor();
    $viewer = memberOf($this->organization, OrganizationRole::Viewer);
    $outsider = User::factory()->create();
    $otherOrganization = organizationWith($outsider, OrganizationRole::Owner);

    $this->actingAs($viewer)
        ->put(route('organizations.monitors.update', [$this->organization, $monitor]), advancedPayload(['body_mode' => 'replace', 'body' => '{}']))
        ->assertForbidden();

    $this->actingAs($outsider)
        ->put(route('organizations.monitors.update', [$otherOrganization, $monitor]), advancedPayload(['body_mode' => 'replace', 'body' => '{}']))
        ->assertNotFound();

    expect($monitor->fresh()->httpConfig->body_encrypted)->toContain('body-secret-token');
});

test('new failure reasons are translated', function () {
    app()->setLocale('fr');

    expect(__(ProbeErrorCode::KeywordMissing->label()))->toBe('Mot-clé attendu introuvable')
        ->and(__(ProbeErrorCode::KeywordFound->label()))->toBe('Mot-clé interdit trouvé')
        ->and(__(ProbeErrorCode::BodyUnreadable->label()))->toBe('Corps de la réponse illisible')
        ->and(ProbeErrorCode::translateCause('Expected keyword not found', 'fr'))->toBe('Mot-clé attendu introuvable');
});
