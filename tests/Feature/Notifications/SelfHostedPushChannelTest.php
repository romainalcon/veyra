<?php

use App\Domain\Incidents\Models\Incident;
use App\Domain\Monitoring\Models\Monitor;
use App\Domain\Notifications\Channels\GotifySender;
use App\Domain\Notifications\Channels\MessageFactory;
use App\Domain\Notifications\Channels\NtfySender;
use App\Domain\Notifications\Channels\PinnedJsonPost;
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

const NTFY_TOPIC = 'https://ntfy.example.com/veyra-alerts';
const NTFY_TOKEN = 'test-ntfy-token-not-real';
const GOTIFY_SERVER = 'https://gotify.example.com';
const GOTIFY_TOKEN = 'testGotifyToken';

beforeEach(function () {
    $this->admin = User::factory()->create();
    $this->organization = organizationWith($this->admin, OrganizationRole::Admin);
    $this->dns->set('ntfy.example.com', ['93.184.215.30'])->set('gotify.example.com', ['93.184.215.31']);
});

function pushIncidentIn(Organization $organization, string $monitorName = 'Checkout API'): Incident
{
    $monitor = Monitor::factory()->create(['organization_id' => $organization->id, 'name' => $monitorName]);

    return Incident::factory()->create(['organization_id' => $organization->id, 'monitor_id' => $monitor->id, 'cause' => 'Timeout']);
}

function pushDelivery(NotificationChannel $channel, Incident $incident): NotificationDelivery
{
    return NotificationDelivery::create([
        'organization_id' => $incident->organization_id,
        'incident_id' => $incident->id,
        'channel_id' => $channel->id,
        'event_type' => NotificationEvent::Opened,
        'status' => DeliveryStatus::Pending,
    ]);
}

function sendPushDelivery(NotificationDelivery $delivery): SendNotificationDelivery
{
    $job = (new SendNotificationDelivery($delivery->id))->withFakeQueueInteractions();
    app()->call([$job, 'handle']);

    return $job;
}

test('admins create ntfy and Gotify channels; tokens are encrypted and never sent to the browser', function () {
    $this->actingAs($this->admin);

    $this->post(route('organizations.notification-channels.store', $this->organization), [
        'type' => 'ntfy', 'name' => 'Phones', 'webhook_url' => NTFY_TOPIC, 'token' => NTFY_TOKEN,
    ])->assertSessionHasNoErrors();
    $this->post(route('organizations.notification-channels.store', $this->organization), [
        'type' => 'gotify', 'name' => 'Gotify', 'webhook_url' => GOTIFY_SERVER, 'token' => GOTIFY_TOKEN,
    ])->assertSessionHasNoErrors();

    $ntfy = NotificationChannel::query()->where('type', ChannelType::Ntfy)->sole();
    $gotify = NotificationChannel::query()->where('type', ChannelType::Gotify)->sole();

    expect($ntfy->webhookUrl())->toBe(NTFY_TOPIC)->and($ntfy->token())->toBe(NTFY_TOKEN)
        ->and($gotify->webhookUrl())->toBe(GOTIFY_SERVER)->and($gotify->token())->toBe(GOTIFY_TOKEN)
        ->and(DB::table('notification_channels')->pluck('config_encrypted')->implode(' '))->not->toContain(NTFY_TOKEN)->not->toContain(GOTIFY_TOKEN);

    $response = $this->get(route('organizations.notification-channels.index', $this->organization))
        ->assertInertia(fn (Assert $page) => $page
            ->where('channels.1.summary', 'Topic veyra-alerts on ntfy.example.com')
            ->where('channels.0.summary', 'POST to gotify.example.com'));
    expect($response->getContent())->not->toContain(NTFY_TOKEN)->not->toContain(GOTIFY_TOKEN);
});

test('the ntfy token is optional; the Gotify token is required', function () {
    $this->actingAs($this->admin);

    $this->post(route('organizations.notification-channels.store', $this->organization), [
        'type' => 'ntfy', 'name' => 'Public topic', 'webhook_url' => NTFY_TOPIC,
    ])->assertSessionHasNoErrors();
    $this->post(route('organizations.notification-channels.store', $this->organization), [
        'type' => 'gotify', 'name' => 'Gotify', 'webhook_url' => GOTIFY_SERVER,
    ])->assertSessionHasErrors('token');

    expect(NotificationChannel::sole()->token())->toBeNull();
});

test('server URLs go through the probe SSRF policy', function (string $type, string $url, array $dns = []) {
    foreach ($dns as $host => $ips) {
        $this->dns->set($host, $ips);
    }

    $this->actingAs($this->admin)
        ->post(route('organizations.notification-channels.store', $this->organization), [
            'type' => $type, 'name' => 'Push', 'webhook_url' => $url, 'token' => GOTIFY_TOKEN,
        ])
        ->assertSessionHasErrors('webhook_url');

    expect(NotificationChannel::count())->toBe(0);
})->with([
    'ntfy loopback' => ['ntfy', 'https://127.0.0.1/alerts'],
    'ntfy metadata' => ['ntfy', 'https://169.254.169.254/alerts'],
    'ntfy IPv6 loopback' => ['ntfy', 'https://[::1]/alerts'],
    'ntfy private DNS answer' => ['ntfy', 'https://ntfy.internal.example/alerts', ['ntfy.internal.example' => ['10.0.0.8']]],
    'ntfy plain http' => ['ntfy', 'http://ntfy.example.com/alerts'],
    'ntfy port not allowed' => ['ntfy', 'https://ntfy.example.com:9000/alerts'],
    'ntfy without topic' => ['ntfy', 'https://ntfy.example.com/'],
    'ntfy invalid topic' => ['ntfy', 'https://ntfy.example.com/bad%20topic'],
    'ntfy query string' => ['ntfy', 'https://ntfy.example.com/alerts?auth=abc'],
    'ntfy credentials' => ['ntfy', 'https://user:pass@ntfy.example.com/alerts'],
    'gotify private' => ['gotify', 'https://192.168.1.20'],
    'gotify IPv6 unique local' => ['gotify', 'https://[fd00::1]'],
    'gotify token in query' => ['gotify', 'https://gotify.example.com/message?token=abc'],
    'gotify unresolvable' => ['gotify', 'https://nowhere.example.net'],
]);

test('self-hosted servers on private networks are accepted when the instance allows them', function () {
    config(['veyra.probes.allow_private_networks' => true]);
    $this->dns->set('ntfy.lan', ['10.0.0.5']);

    $this->actingAs($this->admin)
        ->post(route('organizations.notification-channels.store', $this->organization), [
            'type' => 'ntfy', 'name' => 'LAN', 'webhook_url' => 'http://ntfy.lan/alerts',
        ])
        ->assertSessionHasNoErrors();

    expect(NotificationChannel::sole()->webhookUrl())->toBe('http://ntfy.lan/alerts');
});

test('the cloud edition never accepts private push servers', function () {
    config(['veyra.edition' => 'cloud', 'veyra.probes.allow_private_networks' => true]);
    $this->dns->set('ntfy.lan', ['10.0.0.5']);

    $this->actingAs($this->admin)
        ->post(route('organizations.notification-channels.store', $this->organization), [
            'type' => 'ntfy', 'name' => 'LAN', 'webhook_url' => 'https://ntfy.lan/alerts',
        ])
        ->assertSessionHasErrors('webhook_url');
});

test('tokens are rejected when they could inject headers', function () {
    $this->actingAs($this->admin)
        ->post(route('organizations.notification-channels.store', $this->organization), [
            'type' => 'gotify', 'name' => 'Gotify', 'webhook_url' => GOTIFY_SERVER, 'token' => "abcdefgh\r\nX-Evil: 1",
        ])
        ->assertSessionHasErrors(['token' => 'The token may only contain letters, digits, ".", "-" and "_".']);
});

test('editing keeps the URL and the token unless new ones are given', function () {
    $channel = NotificationChannel::factory()->gotify()->create(['organization_id' => $this->organization->id]);
    $this->actingAs($this->admin);

    $this->put(route('organizations.notification-channels.update', [$this->organization, $channel]), ['name' => 'Renamed'])
        ->assertSessionHasNoErrors();
    expect($channel->fresh())->webhookUrl()->toBe(GOTIFY_SERVER)->token()->toBe(GOTIFY_TOKEN)->name->toBe('Renamed');

    $this->put(route('organizations.notification-channels.update', [$this->organization, $channel]), ['name' => 'Renamed', 'token' => 'anotherToken42'])
        ->assertSessionHasNoErrors();
    expect($channel->fresh()->token())->toBe('anotherToken42');

    $this->put(route('organizations.notification-channels.update', [$this->organization, $channel]), ['name' => 'Renamed', 'webhook_url' => 'https://10.0.0.1'])
        ->assertSessionHasErrors('webhook_url');
    expect($channel->fresh()->webhookUrl())->toBe(GOTIFY_SERVER);
});

test('members cannot create push channels and other organizations stay isolated', function () {
    $this->actingAs(memberOf($this->organization, OrganizationRole::Member))
        ->post(route('organizations.notification-channels.store', $this->organization), [
            'type' => 'ntfy', 'name' => 'Phones', 'webhook_url' => NTFY_TOPIC,
        ])->assertForbidden();

    $foreign = NotificationChannel::factory()->ntfy()->create(['organization_id' => Organization::factory()->create()->id]);

    $this->actingAs($this->admin)
        ->put(route('organizations.notification-channels.update', [$this->organization, $foreign]), ['name' => 'Hijacked', 'token' => 'hijackedToken'])
        ->assertNotFound();

    expect(NotificationChannel::count())->toBe(1)->and($foreign->fresh()->token())->toBe(NTFY_TOKEN);
});

test('ntfy deliveries publish JSON to the server root with the Bearer token', function () {
    Http::fake(['ntfy.example.com/*' => Http::response('{}', 200)]);
    $channel = NotificationChannel::factory()->ntfy()->create(['organization_id' => $this->organization->id]);
    $incident = pushIncidentIn($this->organization);

    sendPushDelivery($delivery = pushDelivery($channel, $incident));

    expect($delivery->fresh()->status)->toBe(DeliveryStatus::Sent);
    Http::assertSent(function (Request $request) use ($incident): bool {
        $payload = $request->data();

        return $request->url() === 'https://ntfy.example.com/'
            && $request->hasHeader('Authorization', 'Bearer '.NTFY_TOKEN)
            && $payload['topic'] === 'veyra-alerts'
            && $payload['title'] === '[Veyra] DOWN — Checkout API'
            && str_starts_with($payload['message'], "Checkout API is down.\n\nMonitor: Checkout API\nCause: Timeout")
            && $payload['priority'] === 5
            && $payload['tags'] === ['rotating_light']
            && str_ends_with($payload['click'], "/incidents/{$incident->id}")
            && $payload['actions'][0]['label'] === 'Open in Veyra';
    });
});

test('ntfy servers behind a path prefix are published to under that prefix', function () {
    Http::fake(['ntfy.example.com/*' => Http::response('{}', 200)]);
    $channel = NotificationChannel::factory()->ntfy('https://ntfy.example.com/push/alerts', null)->create(['organization_id' => $this->organization->id]);

    app(NtfySender::class)->send($channel, MessageFactory::test($this->organization));

    Http::assertSent(fn (Request $request): bool => $request->url() === 'https://ntfy.example.com/push/'
        && $request->data()['topic'] === 'alerts'
        && ! $request->hasHeader('Authorization'));
});

test('Gotify deliveries post a message with the application token header', function () {
    Http::fake(['gotify.example.com/*' => Http::response('{}', 200)]);
    $channel = NotificationChannel::factory()->gotify('https://gotify.example.com/gotify/')->create(['organization_id' => $this->organization->id]);

    sendPushDelivery($delivery = pushDelivery($channel, pushIncidentIn($this->organization)));

    expect($delivery->fresh()->status)->toBe(DeliveryStatus::Sent);
    Http::assertSent(fn (Request $request): bool => $request->url() === 'https://gotify.example.com/gotify/message'
        && $request->hasHeader('X-Gotify-Key', GOTIFY_TOKEN)
        && ! str_contains($request->url(), GOTIFY_TOKEN)
        && $request->data()['priority'] === 8
        && $request->data()['extras']['client::display']['contentType'] === 'text/plain');
});

test('messages follow the organization language', function () {
    $this->organization->update(['locale' => 'fr']);
    $message = MessageFactory::forIncident(pushIncidentIn($this->organization), NotificationEvent::Resolved);

    $payload = app(GotifySender::class)->payload($message);

    expect($payload['title'])->toBe('[Veyra] '.__('RESOLVED', [], 'fr').' — Checkout API')
        ->and($payload['message'])->toContain('Moniteur : Checkout API')
        ->and($payload['priority'])->toBe(5);
});

test('connections are pinned to the validated IP and never follow redirects', function () {
    Http::fake(['ntfy.example.com/*' => Http::response('{}', 200)]);
    $post = app(PinnedJsonPost::class);
    app()->instance(PinnedJsonPost::class, $post);
    $channel = NotificationChannel::factory()->ntfy()->create(['organization_id' => $this->organization->id]);

    app(NtfySender::class)->send($channel, MessageFactory::test($this->organization));

    expect($post->lastOptions['curl'][CURLOPT_RESOLVE])->toBe(['ntfy.example.com:443:93.184.215.30'])
        ->and($post->lastOptions['allow_redirects'])->toBeFalse()
        ->and($post->lastOptions['proxy'])->toBe('')
        ->and($post->lastOptions['timeout'])->toBe(PinnedJsonPost::TIMEOUT_SECONDS);
});

test('a destination rebound to a private address is never called', function () {
    Http::fake();
    $channel = NotificationChannel::factory()->ntfy()->create(['organization_id' => $this->organization->id]);
    $this->dns->set('ntfy.example.com', ['10.0.0.9']);
    $delivery = pushDelivery($channel, pushIncidentIn($this->organization));

    sendPushDelivery($delivery)->assertNotReleased();

    Http::assertNothingSent();
    expect($delivery->fresh())->status->toBe(DeliveryStatus::Failed)->last_error->toStartWith('ntfy destination rejected');
});

test('responses are classified as sent, retryable or permanent', function (mixed $response, bool $sent, bool $retryable, ?int $retryAfter, string $error) {
    Http::fake(['gotify.example.com/*' => $response]);
    $channel = NotificationChannel::factory()->gotify()->create(['organization_id' => $this->organization->id]);

    $result = app(GotifySender::class)->send($channel, MessageFactory::test($this->organization));

    expect($result->sent)->toBe($sent)
        ->and($result->retryable)->toBe($retryable)
        ->and($result->retryAfterSeconds)->toBe($retryAfter)
        ->and((string) $result->error)->toBe($error);
})->with([
    '200' => [fn () => Http::response('{}', 200), true, false, null, ''],
    'bad token' => [fn () => Http::response('{"error":"Unauthorized"}', 401), false, false, null, 'Gotify rejected the notification (HTTP 401).'],
    'redirect' => [fn () => Http::response('', 302, ['Location' => 'http://127.0.0.1/']), false, false, null, 'Gotify redirected (HTTP 302); redirects are not followed.'],
    '429' => [fn () => Http::response('', 429, ['Retry-After' => '9']), false, true, 9, 'Rate limited by Gotify (HTTP 429).'],
    '408' => [fn () => Http::response('', 408), false, true, null, 'Gotify error (HTTP 408).'],
    '502' => [fn () => Http::response('', 502), false, true, null, 'Gotify error (HTTP 502).'],
    'connection' => [fn () => fn () => throw new ConnectionException('cURL error 7: '.GOTIFY_TOKEN), false, true, null, 'Could not reach the Gotify server.'],
]);

test('DNS failures are retried, then the delivery fails without leaking the token', function () {
    Log::spy();
    Http::fake();
    $channel = NotificationChannel::factory()->ntfy()->create(['organization_id' => $this->organization->id]);
    $this->dns->set('ntfy.example.com', []);
    $delivery = pushDelivery($channel, pushIncidentIn($this->organization));

    sendPushDelivery($delivery)->assertReleased(SendNotificationDelivery::BACKOFF[0]);

    expect($delivery->fresh())->status->toBe(DeliveryStatus::Pending)->last_error->toBe('Could not resolve the ntfy host.');
    Log::shouldNotHaveReceived('error');
});

test('test notifications reach ntfy channels', function () {
    Http::fake(['ntfy.example.com/*' => Http::response('{}', 200)]);
    $channel = NotificationChannel::factory()->ntfy()->create(['organization_id' => $this->organization->id]);

    $this->actingAs($this->admin)
        ->post(route('organizations.notification-channels.test', [$this->organization, $channel]))
        ->assertSessionHasNoErrors();

    Http::assertSent(fn (Request $request): bool => $request->data()['tags'] === ['test_tube'] && $request->data()['priority'] === 2);
});
