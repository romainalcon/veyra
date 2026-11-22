<?php

use App\Domain\Incidents\Models\Incident;
use App\Domain\Monitoring\Models\Monitor;
use App\Domain\Monitoring\Models\TlsCertificateAlert;
use App\Domain\Monitoring\Probes\DestinationGuard;
use App\Domain\Notifications\Channels\MessageFactory;
use App\Domain\Notifications\Channels\WebhookSender;
use App\Domain\Notifications\Channels\WebhookSignature;
use App\Domain\Notifications\Enums\ChannelType;
use App\Domain\Notifications\Enums\DeliveryStatus;
use App\Domain\Notifications\Enums\NotificationEvent;
use App\Domain\Notifications\Jobs\SendNotificationDelivery;
use App\Domain\Notifications\Models\NotificationChannel;
use App\Domain\Notifications\Models\NotificationDelivery;
use App\Domain\Organizations\Enums\OrganizationRole;
use App\Domain\Organizations\Models\Organization;
use App\Models\User;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Inertia\Testing\AssertableInertia as Assert;

const ENDPOINT = 'https://api.example.com/hooks/veyra?token=secret-query-token';

beforeEach(function () {
    $this->admin = User::factory()->create();
    $this->organization = organizationWith($this->admin, OrganizationRole::Admin);
});

function webhookDelivery(NotificationChannel $channel, Incident $incident, NotificationEvent $event = NotificationEvent::Opened): NotificationDelivery
{
    return NotificationDelivery::create([
        'organization_id' => $incident->organization_id,
        'incident_id' => $incident->id,
        'channel_id' => $channel->id,
        'event_type' => $event,
        'status' => DeliveryStatus::Pending,
    ]);
}

function sendWebhookDelivery(NotificationDelivery $delivery): SendNotificationDelivery
{
    $job = (new SendNotificationDelivery($delivery->id))->withFakeQueueInteractions();
    app()->call([$job, 'handle']);

    return $job;
}

function webhookChannelIn(Organization $organization, string $url = ENDPOINT): NotificationChannel
{
    return NotificationChannel::factory()->webhook($url)->create(['organization_id' => $organization->id]);
}

/**
 * @return array{0: Monitor, 1: Incident}
 */
function webhookIncidentIn(Organization $organization): array
{
    $monitor = Monitor::factory()->create(['organization_id' => $organization->id, 'name' => 'Checkout API']);

    return [$monitor, Incident::factory()->create([
        'organization_id' => $organization->id,
        'monitor_id' => $monitor->id,
        'cause' => 'Timeout',
    ])];
}

test('admins create webhook channels; the secret is flashed once and encrypted at rest', function () {
    $this->actingAs($this->admin)
        ->post(route('organizations.notification-channels.store', $this->organization), [
            'type' => 'webhook', 'name' => 'Automation', 'webhook_url' => ENDPOINT,
        ])
        ->assertSessionHasNoErrors();

    $channel = NotificationChannel::sole();

    expect($channel->type)->toBe(ChannelType::Webhook)
        ->and($channel->webhookUrl())->toBe(ENDPOINT)
        ->and(WebhookSignature::isValidSecret($channel->signingSecret()))->toBeTrue()
        ->and(DB::table('notification_channels')->value('config_encrypted'))
        ->not->toContain('secret-query-token')
        ->not->toContain((string) $channel->signingSecret());

    $this->get(route('organizations.notification-channels.index', $this->organization))
        ->assertInertia(fn (Assert $page) => $page
            ->hasFlash('webhookSecret', ['channel_id' => $channel->id, 'secret' => $channel->signingSecret()])
            ->where('channels.0.summary', 'POST to api.example.com'));

    // Never again on later visits.
    $response = $this->get(route('organizations.notification-channels.index', $this->organization))
        ->assertInertia(fn (Assert $page) => $page->missingFlash('webhookSecret'));
    expect($response->getContent())
        ->not->toContain((string) $channel->signingSecret())
        ->not->toContain('secret-query-token')
        ->not->toContain('/hooks/veyra');
});

test('webhook URLs go through the SSRF policy when saved', function (string $url, array $dns = []) {
    foreach ($dns as $host => $ips) {
        $this->dns->set($host, $ips);
    }

    $this->actingAs($this->admin)
        ->post(route('organizations.notification-channels.store', $this->organization), [
            'type' => 'webhook', 'name' => 'Automation', 'webhook_url' => $url,
        ])
        ->assertSessionHasErrors('webhook_url');

    expect(NotificationChannel::count())->toBe(0);
})->with([
    'missing' => [''],
    'plain http' => ['http://api.example.com/hook'],
    'loopback' => ['https://127.0.0.1/hook'],
    'private' => ['https://10.0.0.5/hook'],
    'metadata' => ['https://169.254.169.254/latest/meta-data/'],
    'IPv6 loopback' => ['https://[::1]/hook'],
    'IPv6 unique local' => ['https://[fd00::1]/hook'],
    'IPv6 link-local' => ['https://[fe80::1]/hook'],
    'localhost' => ['https://localhost/hook'],
    'port not allowed' => ['https://api.example.com:9000/hook'],
    'credentials in URL' => ['https://user:pass@api.example.com/hook'],
    'host resolving to a private address' => ['https://internal.example.net/hook', ['internal.example.net' => ['192.168.1.10']]],
    'mixed DNS answer' => ['https://mixed.example.net/hook', ['mixed.example.net' => ['93.184.215.20', '10.0.0.1']]],
    'unresolvable host' => ['https://nowhere.example.net/hook'],
    'not a URL' => ['javascript:alert(1)'],
]);

test('plain http is accepted when the instance allows private networks', function () {
    config(['veyra.probes.allow_private_networks' => true]);
    $this->dns->set('n8n.internal', ['10.0.0.5']);

    $this->actingAs($this->admin)
        ->post(route('organizations.notification-channels.store', $this->organization), [
            'type' => 'webhook', 'name' => 'n8n', 'webhook_url' => 'http://n8n.internal/webhook/veyra',
        ])
        ->assertSessionHasNoErrors();

    expect(NotificationChannel::sole()->webhookUrl())->toBe('http://n8n.internal/webhook/veyra');
});

test('the cloud edition never accepts private webhook destinations', function () {
    config(['veyra.edition' => 'cloud', 'veyra.probes.allow_private_networks' => true]);

    $this->actingAs($this->admin)
        ->post(route('organizations.notification-channels.store', $this->organization), [
            'type' => 'webhook', 'name' => 'Internal', 'webhook_url' => 'http://10.0.0.5/hook',
        ])
        ->assertSessionHasErrors('webhook_url');
});

test('editing keeps the URL and the secret unless a new URL is given', function () {
    $channel = webhookChannelIn($this->organization);
    $secret = $channel->signingSecret();
    $this->actingAs($this->admin);

    $this->put(route('organizations.notification-channels.update', [$this->organization, $channel]), ['name' => 'Renamed'])
        ->assertSessionHasNoErrors();
    expect($channel->fresh())->webhookUrl()->toBe(ENDPOINT)->signingSecret()->toBe($secret)->name->toBe('Renamed');

    $this->put(route('organizations.notification-channels.update', [$this->organization, $channel]), ['name' => 'Renamed', 'webhook_url' => 'https://other.example.org/in'])
        ->assertSessionHasNoErrors();
    expect($channel->fresh())->webhookUrl()->toBe('https://other.example.org/in')->signingSecret()->toBe($secret);

    $this->put(route('organizations.notification-channels.update', [$this->organization, $channel]), ['name' => 'Renamed', 'webhook_url' => 'https://127.0.0.1/in'])
        ->assertSessionHasErrors('webhook_url');
});

test('admins regenerate the secret; it is flashed once', function () {
    $channel = webhookChannelIn($this->organization);
    $previous = $channel->signingSecret();

    $this->actingAs($this->admin)
        ->post(route('organizations.notification-channels.signing-secret.store', [$this->organization, $channel]))
        ->assertRedirect(route('organizations.notification-channels.index', $this->organization));

    $current = $channel->fresh()->signingSecret();

    expect($current)->not->toBe($previous)
        ->and(WebhookSignature::isValidSecret($current))->toBeTrue()
        ->and($channel->fresh()->webhookUrl())->toBe(ENDPOINT);

    $this->get(route('organizations.notification-channels.index', $this->organization))
        ->assertInertia(fn (Assert $page) => $page->hasFlash('webhookSecret', ['channel_id' => $channel->id, 'secret' => $current]));
});

test('only managers of the owning organization regenerate secrets', function (OrganizationRole $role) {
    $channel = webhookChannelIn($this->organization);
    $previous = $channel->signingSecret();

    $this->actingAs(memberOf($this->organization, $role))
        ->post(route('organizations.notification-channels.signing-secret.store', [$this->organization, $channel]))
        ->assertForbidden();

    expect($channel->fresh()->signingSecret())->toBe($previous);
})->with([OrganizationRole::Member, OrganizationRole::Viewer]);

test('secrets of another organization cannot be regenerated', function () {
    $other = Organization::factory()->create();
    $channel = webhookChannelIn($other);
    $previous = $channel->signingSecret();

    $this->actingAs($this->admin)
        ->post(route('organizations.notification-channels.signing-secret.store', [$this->organization, $channel]))
        ->assertNotFound();

    expect($channel->fresh()->signingSecret())->toBe($previous);
});

test('channels without a signing secret answer 404', function () {
    $channel = NotificationChannel::factory()->discord()->create(['organization_id' => $this->organization->id]);

    $this->actingAs($this->admin)
        ->post(route('organizations.notification-channels.signing-secret.store', [$this->organization, $channel]))
        ->assertNotFound();
});

test('incident deliveries are signed, carry the delivery id and a versioned payload', function () {
    Http::fake(['api.example.com/*' => Http::response('', 204)]);
    $channel = webhookChannelIn($this->organization);
    [$monitor, $incident] = webhookIncidentIn($this->organization);
    $delivery = webhookDelivery($channel, $incident);

    sendWebhookDelivery($delivery);

    expect($delivery->fresh()->status)->toBe(DeliveryStatus::Sent);

    Http::assertSent(function (Request $request) use ($channel, $delivery, $monitor, $incident): bool {
        $id = $request->header('webhook-id')[0];
        $timestamp = (int) $request->header('webhook-timestamp')[0];
        $payload = json_decode($request->body(), true);

        return $request->method() === 'POST'
            && $request->url() === ENDPOINT
            && $request->hasHeader('Content-Type', 'application/json')
            && $id === $delivery->id
            && abs($timestamp - now()->getTimestamp()) < 5
            && $request->header('webhook-signature')[0] === WebhookSignature::sign((string) $channel->signingSecret(), $id, $timestamp, $request->body())
            && $payload['version'] === 1
            && $payload['id'] === $delivery->id
            && $payload['event'] === 'incident.opened'
            && $payload['organization'] === ['id' => $this->organization->id, 'slug' => $this->organization->slug, 'name' => $this->organization->name]
            && $payload['monitor'] === ['id' => $monitor->id, 'name' => 'Checkout API', 'type' => 'http']
            && $payload['incident']['id'] === $incident->id
            && $payload['incident']['cause'] === 'Timeout'
            && $payload['incident']['started_at'] === $incident->started_at->toIso8601ZuluString()
            && $payload['occurred_at'] === $incident->started_at->toIso8601ZuluString()
            && $payload['certificate'] === null
            && $payload['title'] === '[Veyra] DOWN — Checkout API'
            && str_contains($payload['url'], "/incidents/{$incident->id}");
    });
});

test('payloads never contain the monitored URL, headers or secrets', function () {
    Http::fake(['api.example.com/*' => Http::response('', 204)]);
    $channel = webhookChannelIn($this->organization);
    $monitor = Monitor::factory()->http(['url' => 'https://private-target.example.com/health?key=monitor-secret'])->create(['organization_id' => $this->organization->id]);
    $incident = Incident::factory()->create(['organization_id' => $this->organization->id, 'monitor_id' => $monitor->id]);

    sendWebhookDelivery(webhookDelivery($channel, $incident));

    Http::assertSent(fn (Request $request): bool => ! str_contains($request->body(), 'private-target')
        && ! str_contains($request->body(), 'monitor-secret')
        && ! str_contains($request->body(), (string) $channel->signingSecret())
        && ! str_contains($request->body(), 'secret-query-token'));
});

test('title and summary follow the organization language, event names do not', function () {
    Http::fake(['api.example.com/*' => Http::response('', 204)]);
    $this->organization->update(['locale' => 'fr']);
    $channel = webhookChannelIn($this->organization);
    [, $incident] = webhookIncidentIn($this->organization);

    sendWebhookDelivery(webhookDelivery($channel, $incident));

    Http::assertSent(function (Request $request): bool {
        $payload = json_decode($request->body(), true);

        return $payload['event'] === 'incident.opened'
            && $payload['title'] === '[Veyra] '.__('DOWN', [], 'fr').' — Checkout API'
            && $payload['summary'] === __(':monitor is down.', ['monitor' => 'Checkout API'], 'fr')
            && $payload['incident']['cause'] === 'Timeout';
    });
});

test('retries reuse the same webhook id', function () {
    Http::fakeSequence('api.example.com/*')->push('', 503)->push('', 200);
    $channel = webhookChannelIn($this->organization);
    [, $incident] = webhookIncidentIn($this->organization);
    $delivery = webhookDelivery($channel, $incident);

    sendWebhookDelivery($delivery)->assertReleased(SendNotificationDelivery::BACKOFF[0]);
    expect($delivery->fresh())->status->toBe(DeliveryStatus::Pending)->last_error->toBe('Webhook endpoint error (HTTP 503).');

    sendWebhookDelivery($delivery);
    expect($delivery->fresh()->status)->toBe(DeliveryStatus::Sent);

    $ids = collect(Http::recorded())->map(fn (array $pair) => $pair[0]->header('webhook-id')[0])->unique();
    expect($ids->all())->toBe([$delivery->id]);
});

test('responses are classified as sent, retryable or permanent', function (mixed $response, bool $sent, bool $retryable, ?int $retryAfter, string $error) {
    Http::fake(['api.example.com/*' => $response]);
    $channel = webhookChannelIn($this->organization);

    $result = app(WebhookSender::class)->send($channel, MessageFactory::test($this->organization));

    expect($result->sent)->toBe($sent)
        ->and($result->retryable)->toBe($retryable)
        ->and($result->retryAfterSeconds)->toBe($retryAfter)
        ->and((string) $result->error)->toBe($error);
})->with([
    '200' => [fn () => Http::response('ok', 200), true, false, null, ''],
    '204' => [fn () => Http::response('', 204), true, false, null, ''],
    'redirect' => [fn () => Http::response('', 301, ['Location' => 'http://127.0.0.1/']), false, false, null, 'Webhook endpoint redirected (HTTP 301); redirects are not followed.'],
    '400' => [fn () => Http::response('', 400), false, false, null, 'Webhook endpoint rejected the notification (HTTP 400).'],
    '410' => [fn () => Http::response('', 410), false, false, null, 'Webhook endpoint rejected the notification (HTTP 410).'],
    '408' => [fn () => Http::response('', 408), false, true, null, 'Webhook endpoint error (HTTP 408).'],
    '429' => [fn () => Http::response('', 429, ['Retry-After' => '42']), false, true, 42, 'Rate limited by the webhook endpoint (HTTP 429).'],
    '503' => [fn () => Http::response('', 503), false, true, null, 'Webhook endpoint error (HTTP 503).'],
    'connection' => [fn () => fn () => throw new ConnectionException('cURL error 7: Failed to connect to '.ENDPOINT), false, true, null, 'Could not reach the webhook endpoint.'],
]);

test('the destination is re-validated before each send', function () {
    Http::fake();
    $channel = webhookChannelIn($this->organization, 'https://rebind.example.net/hook');
    [, $incident] = webhookIncidentIn($this->organization);
    $this->dns->set('rebind.example.net', ['10.0.0.8']);

    $delivery = webhookDelivery($channel, $incident);
    sendWebhookDelivery($delivery)->assertNotReleased();

    Http::assertNothingSent();
    expect($delivery->fresh())->status->toBe(DeliveryStatus::Failed)
        ->last_error->toStartWith('Webhook destination rejected:')
        ->last_error->not->toContain('10.0.0.8');
});

test('a host that stops resolving is retried, a tampered stored URL is never called', function () {
    Http::fake();
    $unresolvable = webhookChannelIn($this->organization, 'https://gone.example.net/hook');
    $tampered = webhookChannelIn($this->organization, 'http://169.254.169.254/latest/meta-data/');

    $sender = app(WebhookSender::class);
    $message = MessageFactory::test($this->organization);

    expect($sender->send($unresolvable, $message))->retryable->toBeTrue()->error->toBe('Could not resolve the webhook host.')
        ->and($sender->send($tampered, $message))->retryable->toBeFalse();

    Http::assertNothingSent();
});

test('a channel without a valid secret is never called', function () {
    Http::fake();
    $channel = webhookChannelIn($this->organization);
    $channel->update(['config_encrypted' => ['webhook_url' => ENDPOINT]]);

    $result = app(WebhookSender::class)->send($channel, MessageFactory::test($this->organization));

    expect($result)->sent->toBeFalse()->retryable->toBeFalse()->error->toBe('Invalid webhook configuration.');
    Http::assertNothingSent();
});

test('connections are pinned to the validated address without redirects or proxy', function () {
    $destination = app(DestinationGuard::class)->inspect(ENDPOINT);
    $options = app(WebhookSender::class)->requestOptions($destination);

    expect($options['curl'][CURLOPT_RESOLVE])->toBe(['api.example.com:443:93.184.215.16'])
        ->and($options['allow_redirects'])->toBeFalse()
        ->and($options['proxy'])->toBe('')
        ->and($options['protocols'])->toBe(['https'])
        ->and($options['verify'])->toBeTrue()
        ->and($options['timeout'])->toBe(WebhookSender::TIMEOUT_SECONDS)
        ->and($options['connect_timeout'])->toBe(WebhookSender::TIMEOUT_SECONDS);
});

test('URLs and tokens never reach logs or stored errors', function () {
    Log::spy();
    Http::fake(fn () => throw new ConnectionException('cURL error 7: Failed to connect to '.ENDPOINT));
    $channel = webhookChannelIn($this->organization);
    [, $incident] = webhookIncidentIn($this->organization);
    $delivery = webhookDelivery($channel, $incident);

    sendWebhookDelivery($delivery);

    expect($delivery->fresh()->last_error)->toBe('Could not reach the webhook endpoint.');
    Log::shouldNotHaveReceived('error');
    Log::shouldNotHaveReceived('warning');
});

test('test notifications reach webhook channels', function () {
    Http::fake(['api.example.com/*' => Http::response('', 204)]);
    $channel = webhookChannelIn($this->organization);

    $this->actingAs($this->admin)
        ->post(route('organizations.notification-channels.test', [$this->organization, $channel]))
        ->assertSessionHasNoErrors();

    Http::assertSent(fn (Request $request): bool => json_decode($request->body(), true)['event'] === 'test'
        && json_decode($request->body(), true)['monitor'] === null
        && json_decode($request->body(), true)['incident'] === null);
});

test('certificate warnings carry the certificate block', function () {
    $monitor = Monitor::factory()->create(['organization_id' => $this->organization->id]);
    $alert = TlsCertificateAlert::create([
        'organization_id' => $this->organization->id,
        'monitor_id' => $monitor->id,
        'fingerprint' => str_repeat('a', 64),
        'threshold_days' => 7,
        'not_after' => now()->addDays(6),
    ]);

    $message = MessageFactory::forCertificateAlert($alert, 'delivery-id');
    $payload = app(WebhookSender::class)->payload($message, 'delivery-id');

    expect($payload['event'])->toBe('certificate.expiring')
        ->and($payload['incident'])->toBeNull()
        ->and($payload['certificate']['expires_at'])->toBe($alert->not_after->toIso8601ZuluString())
        ->and($payload['monitor']['id'])->toBe($monitor->id);
});
