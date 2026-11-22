<?php

use App\Domain\Incidents\Models\Incident;
use App\Domain\Monitoring\Models\Monitor;
use App\Domain\Monitoring\Models\TlsCertificateAlert;
use App\Domain\Notifications\Channels\MessageFactory;
use App\Domain\Notifications\Channels\SlackSender;
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

const SLACK_HOOK = 'https://hooks.slack.com/services/T0123ABCD/B0456EFGH/abcdEFGHijklMNOPqrstUVwx';

beforeEach(function () {
    $this->admin = User::factory()->create();
    $this->organization = organizationWith($this->admin, OrganizationRole::Admin);
});

function slackChannelIn(Organization $organization, string $url = SLACK_HOOK): NotificationChannel
{
    return NotificationChannel::factory()->slack($url)->create(['organization_id' => $organization->id]);
}

function slackIncidentIn(Organization $organization, string $monitorName = 'Checkout API'): Incident
{
    $monitor = Monitor::factory()->create(['organization_id' => $organization->id, 'name' => $monitorName]);

    return Incident::factory()->create(['organization_id' => $organization->id, 'monitor_id' => $monitor->id, 'cause' => 'Timeout']);
}

function slackDelivery(NotificationChannel $channel, Incident $incident): NotificationDelivery
{
    return NotificationDelivery::create([
        'organization_id' => $incident->organization_id,
        'incident_id' => $incident->id,
        'channel_id' => $channel->id,
        'event_type' => NotificationEvent::Opened,
        'status' => DeliveryStatus::Pending,
    ]);
}

function sendSlackDelivery(NotificationDelivery $delivery): SendNotificationDelivery
{
    $job = (new SendNotificationDelivery($delivery->id))->withFakeQueueInteractions();
    app()->call([$job, 'handle']);

    return $job;
}

/**
 * @return array<string, mixed>
 */
function slackPayload(Request $request): array
{
    return json_decode($request->body(), true);
}

test('admins create Slack channels; the URL is encrypted and never sent to the browser', function () {
    $this->actingAs($this->admin)
        ->post(route('organizations.notification-channels.store', $this->organization), [
            'type' => 'slack', 'name' => 'Slack #alerts', 'webhook_url' => SLACK_HOOK,
        ])
        ->assertSessionHasNoErrors();

    $channel = NotificationChannel::sole();

    expect($channel->type)->toBe(ChannelType::Slack)
        ->and($channel->webhookUrl())->toBe(SLACK_HOOK)
        ->and(DB::table('notification_channels')->value('config_encrypted'))->not->toContain('abcdEFGHijkl');

    $response = $this->get(route('organizations.notification-channels.index', $this->organization))
        ->assertInertia(fn (Assert $page) => $page->where('channels.0.summary', 'Webhook …UVwx'));
    expect($response->getContent())->not->toContain('abcdEFGHijkl')->not->toContain('/services/');
});

test('only Slack incoming webhook URLs are accepted', function (string $url) {
    $this->actingAs($this->admin)
        ->post(route('organizations.notification-channels.store', $this->organization), [
            'type' => 'slack', 'name' => 'Slack', 'webhook_url' => $url,
        ])
        ->assertSessionHasErrors('webhook_url');

    expect(NotificationChannel::count())->toBe(0);
})->with([
    'other host' => ['https://example.com/services/T0123ABCD/B0456EFGH/abcdEFGHijklMNOPqrstUVwx'],
    'metadata' => ['https://169.254.169.254/latest/meta-data/'],
    'discord' => ['https://discord.com/api/webhooks/123456789012345678/AbCdEf_ghIJ-klmnop'],
    'workflow trigger' => ['https://hooks.slack.com/triggers/T0123ABCD/1234567890/abcdEFGHijklMNOPqrstUVwx'],
]);

test('the validation message follows the organization language', function () {
    $this->organization->update(['locale' => 'fr']);

    $this->actingAs($this->admin)
        ->post(route('organizations.notification-channels.store', $this->organization), [
            'type' => 'slack', 'name' => 'Slack', 'webhook_url' => 'https://example.com/hook',
        ])
        ->assertSessionHasErrors(['webhook_url' => 'Indiquez une URL de webhook entrant Slack (https://hooks.slack.com/services/…).']);
});

test('editing keeps the URL unless a new one is given', function () {
    $channel = slackChannelIn($this->organization);
    $other = 'https://hooks.slack.com/services/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';
    $this->actingAs($this->admin);

    $this->put(route('organizations.notification-channels.update', [$this->organization, $channel]), ['name' => 'Renamed'])
        ->assertSessionHasNoErrors();
    expect($channel->fresh())->webhookUrl()->toBe(SLACK_HOOK)->name->toBe('Renamed');

    $this->put(route('organizations.notification-channels.update', [$this->organization, $channel]), ['name' => 'Renamed', 'webhook_url' => $other])
        ->assertSessionHasNoErrors();
    expect($channel->fresh()->webhookUrl())->toBe($other);

    $this->put(route('organizations.notification-channels.update', [$this->organization, $channel]), ['name' => 'Renamed', 'webhook_url' => 'https://127.0.0.1/hook'])
        ->assertSessionHasErrors('webhook_url');
    expect($channel->fresh()->webhookUrl())->toBe($other);
});

test('members and viewers cannot create or edit Slack channels', function (OrganizationRole $role) {
    $channel = slackChannelIn($this->organization);
    $this->actingAs(memberOf($this->organization, $role));

    $this->post(route('organizations.notification-channels.store', $this->organization), [
        'type' => 'slack', 'name' => 'Slack', 'webhook_url' => SLACK_HOOK,
    ])->assertForbidden();
    $this->put(route('organizations.notification-channels.update', [$this->organization, $channel]), [
        'name' => 'Hijacked', 'webhook_url' => 'https://hooks.slack.com/services/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',
    ])->assertForbidden();

    expect(NotificationChannel::count())->toBe(1)
        ->and($channel->fresh())->name->toBe('Slack #alerts')->webhookUrl()->toBe(SLACK_HOOK);
})->with([OrganizationRole::Member, OrganizationRole::Viewer]);

test('Slack channels of another organization cannot be edited', function () {
    $channel = slackChannelIn(Organization::factory()->create());

    $this->actingAs($this->admin)
        ->put(route('organizations.notification-channels.update', [$this->organization, $channel]), ['name' => 'Hijacked'])
        ->assertNotFound();

    expect($channel->fresh()->name)->toBe('Slack #alerts');
});

test('incident deliveries post a Block Kit message', function () {
    Http::fake(['hooks.slack.com/*' => Http::response('ok', 200)]);
    $channel = slackChannelIn($this->organization);
    $incident = slackIncidentIn($this->organization);

    sendSlackDelivery($delivery = slackDelivery($channel, $incident));

    expect($delivery->fresh()->status)->toBe(DeliveryStatus::Sent);
    Http::assertSent(function (Request $request) use ($incident): bool {
        $payload = slackPayload($request);
        $attachment = $payload['attachments'][0];
        $button = $attachment['blocks'][3]['elements'][0];

        return $request->url() === SLACK_HOOK
            && $payload['text'] === '[Veyra] DOWN — Checkout API'
            && $attachment['color'] === '#dc2626'
            && $attachment['blocks'][1]['text']['text'] === 'Checkout API is down.'
            && $attachment['blocks'][1]['fields'][0]['text'] === "*Monitor*\nCheckout API"
            && $attachment['blocks'][1]['fields'][1]['text'] === "*Cause*\nTimeout"
            && $button['type'] === 'button'
            && str_ends_with($button['url'], "/incidents/{$incident->id}");
    });
});

test('untrusted names can never mention anyone or forge links', function () {
    $this->organization->update(['name' => 'Acme <!here>']);
    $incident = slackIncidentIn($this->organization, '<!channel> <https://evil.example|Click> & co');

    $payload = app(SlackSender::class)->payload(MessageFactory::forIncident($incident, NotificationEvent::Opened));
    $json = json_encode($payload);

    expect($json)->not->toContain('<!channel>')->not->toContain('<!here>')->not->toContain('<https://evil')
        ->and($payload['text'])->toBe('[Veyra] DOWN — &lt;!channel&gt; &lt;https://evil.example|Click&gt; &amp; co')
        ->and($payload['attachments'][0]['blocks'][2]['elements'][0]['text'])->toBe('Acme &lt;!here&gt;');
});

test('messages follow the organization language', function () {
    $this->organization->update(['locale' => 'fr']);
    $incident = slackIncidentIn($this->organization);

    $payload = app(SlackSender::class)->payload(MessageFactory::forIncident($incident, NotificationEvent::Opened));
    $blocks = $payload['attachments'][0]['blocks'];

    expect($payload['text'])->toBe('[Veyra] '.__('DOWN', [], 'fr').' — Checkout API')
        ->and($blocks[1]['text']['text'])->toBe(__(':monitor is down.', ['monitor' => 'Checkout API'], 'fr'))
        ->and($blocks[1]['fields'][0]['text'])->toStartWith('*Moniteur*')
        ->and($blocks[3]['elements'][0]['text']['text'])->toBe('Ouvrir dans Veyra');
});

test('each event gets its colour', function (NotificationEvent $event, string $color) {
    $incident = slackIncidentIn($this->organization);

    $payload = app(SlackSender::class)->payload(MessageFactory::forIncident($incident, $event));

    expect($payload['attachments'][0]['color'])->toBe($color);
})->with([
    [NotificationEvent::Opened, '#dc2626'],
    [NotificationEvent::Resolved, '#16a34a'],
    [NotificationEvent::Acknowledged, '#d97706'],
]);

test('certificate warnings show the expiry and the issuer', function () {
    $monitor = Monitor::factory()->create(['organization_id' => $this->organization->id]);
    $alert = TlsCertificateAlert::create([
        'organization_id' => $this->organization->id,
        'monitor_id' => $monitor->id,
        'fingerprint' => str_repeat('a', 64),
        'threshold_days' => 7,
        'not_after' => now()->addDays(6),
    ]);

    $payload = app(SlackSender::class)->payload(MessageFactory::forCertificateAlert($alert));
    $fields = collect($payload['attachments'][0]['blocks'][1]['fields'])->pluck('text');

    expect($payload['attachments'][0]['color'])->toBe('#d97706')
        ->and($fields->contains(fn (string $text) => str_starts_with($text, "*Expires*\n".$alert->not_after->toDateTimeString())))->toBeTrue()
        ->and($fields->contains(fn (string $text) => str_starts_with($text, '*Duration*')))->toBeFalse();
});

test('responses are classified as sent, retryable or permanent', function (mixed $response, bool $sent, bool $retryable, ?int $retryAfter, string $error) {
    Http::fake(['hooks.slack.com/*' => $response]);
    $channel = slackChannelIn($this->organization);

    $result = app(SlackSender::class)->send($channel, MessageFactory::test($this->organization));

    expect($result->sent)->toBe($sent)
        ->and($result->retryable)->toBe($retryable)
        ->and($result->retryAfterSeconds)->toBe($retryAfter)
        ->and((string) $result->error)->toBe($error);
})->with([
    '200' => [fn () => Http::response('ok', 200), true, false, null, ''],
    'invalid payload' => [fn () => Http::response('invalid_payload', 400), false, false, null, 'Slack rejected the webhook (HTTP 400: invalid_payload).'],
    'deleted webhook' => [fn () => Http::response('no_service', 404), false, false, null, 'Slack rejected the webhook (HTTP 404: no_service).'],
    'archived channel' => [fn () => Http::response('channel_is_archived', 410), false, false, null, 'Slack rejected the webhook (HTTP 410: channel_is_archived).'],
    'unexpected body' => [fn () => Http::response('<html>'.SLACK_HOOK.'</html>', 403), false, false, null, 'Slack rejected the webhook (HTTP 403).'],
    'redirect' => [fn () => Http::response('', 302, ['Location' => 'http://127.0.0.1/']), false, false, null, 'Slack rejected the webhook (HTTP 302).'],
    '429' => [fn () => Http::response('rate_limited', 429, ['Retry-After' => '17']), false, true, 17, 'Rate limited by Slack (HTTP 429).'],
    '429 without header' => [fn () => Http::response('rate_limited', 429), false, true, 30, 'Rate limited by Slack (HTTP 429).'],
    '503' => [fn () => Http::response('', 503), false, true, null, 'Slack error (HTTP 503).'],
    'connection' => [fn () => fn () => throw new ConnectionException('cURL error 7: Failed to connect to '.SLACK_HOOK), false, true, null, 'Could not reach Slack.'],
]);

test('transient failures are retried with backoff, then marked failed', function () {
    Http::fake(['hooks.slack.com/*' => Http::response('', 503)]);
    $delivery = slackDelivery(slackChannelIn($this->organization), slackIncidentIn($this->organization));

    sendSlackDelivery($delivery)->assertReleased(SendNotificationDelivery::BACKOFF[0]);
    expect($delivery->fresh())->status->toBe(DeliveryStatus::Pending)->attempts->toBe(1);

    $delivery->update(['attempts' => SendNotificationDelivery::MAX_ATTEMPTS - 1]);
    sendSlackDelivery($delivery)->assertNotReleased();

    expect($delivery->fresh())->status->toBe(DeliveryStatus::Failed)->last_error->toBe('Slack error (HTTP 503).');
});

test('rate limits honour Retry-After', function () {
    Http::fake(['hooks.slack.com/*' => Http::response('rate_limited', 429, ['Retry-After' => '12'])]);
    $delivery = slackDelivery(slackChannelIn($this->organization), slackIncidentIn($this->organization));

    sendSlackDelivery($delivery)->assertReleased(12);
});

test('a tampered stored URL is never called', function () {
    Http::fake();
    $channel = slackChannelIn($this->organization);
    $channel->update(['config_encrypted' => ['webhook_url' => 'https://169.254.169.254/services/T0123ABCD/B0456EFGH/abcdEFGHijklMNOPqrstUVwx']]);
    $delivery = slackDelivery($channel, slackIncidentIn($this->organization));

    sendSlackDelivery($delivery)->assertNotReleased();

    Http::assertNothingSent();
    expect($delivery->fresh())->status->toBe(DeliveryStatus::Failed)->last_error->toBe('Invalid Slack webhook configuration.');
});

test('requests follow no redirect and use no proxy', function () {
    $options = [];
    Http::fake(function (Request $request, array $requestOptions) use (&$options) {
        $options = $requestOptions;

        return Http::response('ok', 200);
    });

    app(SlackSender::class)->send(slackChannelIn($this->organization), MessageFactory::test($this->organization));

    expect($options['allow_redirects'])->toBeFalse()
        ->and($options['proxy'])->toBe('')
        ->and($options['timeout'])->toBe(SlackSender::TIMEOUT_SECONDS);
});

test('URLs and tokens never reach logs or stored errors', function () {
    Log::spy();
    Http::fake(fn () => throw new ConnectionException('cURL error 7: Failed to connect to '.SLACK_HOOK));
    $delivery = slackDelivery(slackChannelIn($this->organization), slackIncidentIn($this->organization));

    sendSlackDelivery($delivery);

    expect($delivery->fresh()->last_error)->toBe('Could not reach Slack.');
    Log::shouldNotHaveReceived('error');
    Log::shouldNotHaveReceived('warning');
});

test('test notifications reach Slack channels', function () {
    Http::fake(['hooks.slack.com/*' => Http::response('ok', 200)]);
    $channel = slackChannelIn($this->organization);

    $this->actingAs($this->admin)
        ->post(route('organizations.notification-channels.test', [$this->organization, $channel]))
        ->assertSessionHasNoErrors();

    Http::assertSent(fn (Request $request): bool => $request->url() === SLACK_HOOK
        && slackPayload($request)['attachments'][0]['color'] === '#6b7280');
});
