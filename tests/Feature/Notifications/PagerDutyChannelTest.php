<?php

use App\Domain\Incidents\Models\Incident;
use App\Domain\Monitoring\Models\Monitor;
use App\Domain\Monitoring\Models\TlsCertificateAlert;
use App\Domain\Notifications\Actions\QueueIncidentNotifications;
use App\Domain\Notifications\Channels\MessageFactory;
use App\Domain\Notifications\Channels\PagerDutySender;
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
use Illuminate\Support\Facades\Queue;
use Inertia\Testing\AssertableInertia as Assert;

const PAGERDUTY_KEY = 'testroutingkeynotreal00000000000';

beforeEach(function () {
    $this->admin = User::factory()->create();
    $this->organization = organizationWith($this->admin, OrganizationRole::Admin);
});

function pagerDutyChannelIn(Organization $organization): NotificationChannel
{
    return NotificationChannel::factory()->pagerDuty()->create(['organization_id' => $organization->id]);
}

function pagerDutyIncidentIn(Organization $organization): Incident
{
    $monitor = Monitor::factory()->create(['organization_id' => $organization->id, 'name' => 'Checkout API']);

    return Incident::factory()->create(['organization_id' => $organization->id, 'monitor_id' => $monitor->id, 'cause' => 'Timeout']);
}

function pagerDutyDelivery(NotificationChannel $channel, Incident $incident, NotificationEvent $event): NotificationDelivery
{
    return NotificationDelivery::create([
        'organization_id' => $incident->organization_id,
        'incident_id' => $incident->id,
        'channel_id' => $channel->id,
        'event_type' => $event,
        'status' => DeliveryStatus::Pending,
    ]);
}

function sendPagerDutyDelivery(NotificationDelivery $delivery): SendNotificationDelivery
{
    $job = (new SendNotificationDelivery($delivery->id))->withFakeQueueInteractions();
    app()->call([$job, 'handle']);

    return $job;
}

test('admins create PagerDuty channels; the routing key is encrypted and never sent to the browser', function () {
    $this->actingAs($this->admin)
        ->post(route('organizations.notification-channels.store', $this->organization), [
            'type' => 'pagerduty', 'name' => 'Checkout service', 'token' => PAGERDUTY_KEY,
        ])
        ->assertSessionHasNoErrors();

    expect(NotificationChannel::sole())->type->toBe(ChannelType::PagerDuty)->token()->toBe(PAGERDUTY_KEY)
        ->and(DB::table('notification_channels')->value('config_encrypted'))->not->toContain(PAGERDUTY_KEY);

    $response = $this->get(route('organizations.notification-channels.index', $this->organization))
        ->assertInertia(fn (Assert $page) => $page->where('channels.0.summary', 'Integration key …0000'));
    expect($response->getContent())->not->toContain(PAGERDUTY_KEY);
});

test('routing keys must be 32 alphanumeric characters', function (string $key) {
    $this->actingAs($this->admin)
        ->post(route('organizations.notification-channels.store', $this->organization), [
            'type' => 'pagerduty', 'name' => 'PD', 'token' => $key,
        ])
        ->assertSessionHasErrors(['token' => 'Provide the 32-character integration key of a PagerDuty Events API v2 integration.']);
})->with(['too short' => ['abc'], 'too long' => [PAGERDUTY_KEY.'x'], 'symbols' => [str_repeat('a', 31).'/']]);

test('viewers cannot create PagerDuty channels', function () {
    $this->actingAs(memberOf($this->organization, OrganizationRole::Viewer))
        ->post(route('organizations.notification-channels.store', $this->organization), [
            'type' => 'pagerduty', 'name' => 'PD', 'token' => PAGERDUTY_KEY,
        ])
        ->assertForbidden();

    expect(NotificationChannel::count())->toBe(0);
});

test('an incident triggers, acknowledges and resolves one alert with a stable dedup key', function () {
    Http::fake(['events.pagerduty.com/*' => Http::response(['status' => 'success', 'dedup_key' => 'x'], 202)]);
    $channel = pagerDutyChannelIn($this->organization);
    $incident = pagerDutyIncidentIn($this->organization);

    sendPagerDutyDelivery(pagerDutyDelivery($channel, $incident, NotificationEvent::Opened));
    $incident->update(['acknowledged_at' => now()]);
    sendPagerDutyDelivery(pagerDutyDelivery($channel, $incident, NotificationEvent::Acknowledged));
    $incident->update(['resolved_at' => now()]);
    sendPagerDutyDelivery(pagerDutyDelivery($channel, $incident, NotificationEvent::Resolved));

    $events = collect(Http::recorded())->map(fn (array $pair) => $pair[0]->data());

    expect(NotificationDelivery::where('status', DeliveryStatus::Sent)->count())->toBe(3)
        ->and($events->pluck('event_action')->all())->toBe(['trigger', 'acknowledge', 'resolve'])
        ->and($events->pluck('dedup_key')->unique()->all())->toBe(["veyra-incident-{$incident->id}"])
        ->and($events->pluck('routing_key')->unique()->all())->toBe([PAGERDUTY_KEY]);

    $trigger = $events->first();
    expect($trigger['payload'])->toMatchArray([
        'summary' => '[Veyra] DOWN — Checkout API',
        'source' => 'Checkout API',
        'severity' => 'critical',
        'component' => 'Checkout API',
        'class' => 'Timeout',
    ])
        ->and($trigger['payload']['custom_details']['Cause'])->toBe('Timeout')
        ->and($trigger['links'][0]['href'])->toEndWith("/incidents/{$incident->id}")
        ->and($events[1])->not->toHaveKey('payload');
    Http::assertSent(fn (Request $request): bool => $request->url() === PagerDutySender::ENDPOINT);
});

test('a trigger retried after recovery is skipped instead of reopening the alert', function () {
    Http::fake();
    $incident = pagerDutyIncidentIn($this->organization);
    $incident->update(['resolved_at' => now()]);
    $delivery = pagerDutyDelivery(pagerDutyChannelIn($this->organization), $incident, NotificationEvent::Opened);

    sendPagerDutyDelivery($delivery)->assertNotReleased();

    Http::assertNothingSent();
    expect($delivery->fresh())->status->toBe(DeliveryStatus::Failed)->last_error->toBe('Incident already resolved; PagerDuty event skipped.');
});

test('reminders are never queued for PagerDuty channels', function () {
    Queue::fake([SendNotificationDelivery::class]);
    $incident = pagerDutyIncidentIn($this->organization);
    $pagerDuty = pagerDutyChannelIn($this->organization);
    $email = NotificationChannel::factory()->create(['organization_id' => $this->organization->id]);
    $incident->monitor->notificationChannels()->attach([$pagerDuty->id, $email->id]);

    $queue = app(QueueIncidentNotifications::class);

    expect($queue->handle($incident, NotificationEvent::Reminder))->toBe(1)
        ->and($queue->handle($incident, NotificationEvent::Opened))->toBe(2)
        ->and(NotificationDelivery::where('channel_id', $pagerDuty->id)->pluck('event_type')->all())->toBe([NotificationEvent::Opened]);
});

test('certificate warnings trigger their own alert keyed by the delivery', function () {
    $monitor = Monitor::factory()->create(['organization_id' => $this->organization->id]);
    $alert = TlsCertificateAlert::create([
        'organization_id' => $this->organization->id,
        'monitor_id' => $monitor->id,
        'fingerprint' => str_repeat('b', 64),
        'threshold_days' => 7,
        'not_after' => now()->addDays(6),
    ]);
    $message = MessageFactory::forCertificateAlert($alert, 'delivery-1');

    $event = app(PagerDutySender::class)->event($message, PAGERDUTY_KEY, 'trigger');

    expect($event['dedup_key'])->toBe('veyra-certificate-expiring-delivery-1')
        ->and($event['payload']['severity'])->toBe('warning')
        ->and($event['payload'])->not->toHaveKey('class');
});

test('a test triggers then resolves a throwaway alert', function () {
    Http::fake(['events.pagerduty.com/*' => Http::response(['status' => 'success'], 202)]);
    $channel = pagerDutyChannelIn($this->organization);

    $this->actingAs($this->admin)
        ->post(route('organizations.notification-channels.test', [$this->organization, $channel]))
        ->assertSessionHasNoErrors();

    $events = collect(Http::recorded())->map(fn (array $pair) => $pair[0]->data());
    expect($events->pluck('event_action')->all())->toBe(['trigger', 'resolve'])
        ->and($events[0]['dedup_key'])->toBe($events[1]['dedup_key'])->toStartWith('veyra-test-')
        ->and($events[0]['payload']['severity'])->toBe('info');
});

test('PagerDuty responses are classified as sent, retryable or permanent', function (mixed $response, bool $sent, bool $retryable, ?int $retryAfter, string $error) {
    Http::fake(['events.pagerduty.com/*' => $response]);
    $incident = pagerDutyIncidentIn($this->organization);

    $result = app(PagerDutySender::class)->send(pagerDutyChannelIn($this->organization), MessageFactory::forIncident($incident, NotificationEvent::Opened));

    expect($result->sent)->toBe($sent)
        ->and($result->retryable)->toBe($retryable)
        ->and($result->retryAfterSeconds)->toBe($retryAfter)
        ->and((string) $result->error)->toBe($error);
})->with([
    '202' => [fn () => Http::response(['status' => 'success'], 202), true, false, null, ''],
    'invalid event' => [fn () => Http::response(['status' => 'invalid event', 'message' => 'Event object is invalid'], 400), false, false, null, 'PagerDuty rejected the event (HTTP 400: Event object is invalid).'],
    'markup in message' => [fn () => Http::response(['message' => '<b>Invalid</b>'], 400), false, false, null, 'PagerDuty rejected the event (HTTP 400).'],
    'message echoing the key' => [fn () => Http::response(['message' => 'Unknown key '.PAGERDUTY_KEY], 400), false, false, null, 'PagerDuty rejected the event (HTTP 400).'],
    '429' => [fn () => Http::response('', 429), false, true, 30, 'Rate limited by PagerDuty (HTTP 429).'],
    '500' => [fn () => Http::response('', 500), false, true, null, 'PagerDuty error (HTTP 500).'],
    'connection' => [fn () => fn () => throw new ConnectionException('cURL error 7'), false, true, null, 'Could not reach PagerDuty.'],
]);

test('a tampered routing key is never sent', function () {
    Http::fake();
    $channel = pagerDutyChannelIn($this->organization);
    $channel->update(['config_encrypted' => ['token' => 'short']]);

    $result = app(PagerDutySender::class)->send($channel, MessageFactory::test($this->organization));

    expect($result->error)->toBe('Invalid PagerDuty configuration.');
    Http::assertNothingSent();
});
