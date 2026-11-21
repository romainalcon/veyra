<?php

use App\Domain\Incidents\Actions\AcknowledgeIncident;
use App\Domain\Incidents\Models\Incident;
use App\Domain\Monitoring\Models\Monitor;
use App\Domain\Notifications\Actions\QueueIncidentNotifications;
use App\Domain\Notifications\Enums\DeliveryStatus;
use App\Domain\Notifications\Enums\NotificationEvent;
use App\Domain\Notifications\Jobs\SendNotificationDelivery;
use App\Domain\Notifications\Mail\IncidentNotificationMail;
use App\Domain\Notifications\Models\NotificationChannel;
use App\Domain\Notifications\Models\NotificationDelivery;
use App\Models\User;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;

const HOOK = 'https://discord.com/api/webhooks/123456789012345678/very-secret-token-123';

beforeEach(function () {
    $this->monitor = Monitor::factory()->create(['name' => 'Checkout API']);
    $this->email = NotificationChannel::factory()->create(['organization_id' => $this->monitor->organization_id, 'config_encrypted' => ['recipients' => ['ops@example.com']]]);
    $this->discord = NotificationChannel::factory()->discord(HOOK)->create(['organization_id' => $this->monitor->organization_id]);
    $this->monitor->notificationChannels()->attach([$this->email->id, $this->discord->id]);
});

function failMonitor(Monitor $monitor): Incident
{
    foreach (['00', '01', '02'] as $minute) {
        recordCheck($monitor, false, "2026-11-22 10:{$minute}:00");
    }

    return Incident::sole();
}

test('an opened incident notifies every enabled attached channel', function () {
    Mail::fake();
    Http::fake(['discord.com/*' => Http::response('', 204)]);
    NotificationChannel::factory()->create(['organization_id' => $this->monitor->organization_id]); // not attached
    $this->email->update(['enabled' => true]);

    $incident = failMonitor($this->monitor);

    expect(NotificationDelivery::where('incident_id', $incident->id)->count())->toBe(2)
        ->and(NotificationDelivery::where('status', DeliveryStatus::Sent)->count())->toBe(2);

    Mail::assertSent(IncidentNotificationMail::class, fn (IncidentNotificationMail $mail) => $mail->hasTo('ops@example.com')
        && $mail->envelope()->subject === '[Veyra] DOWN — Checkout API'
        && str_contains($mail->notification->url, "/incidents/{$incident->id}"));

    Http::assertSent(fn (Request $request) => $request->url() === HOOK
        && $request['allowed_mentions'] === ['parse' => []]
        && $request['embeds'][0]['title'] === '[Veyra] DOWN — Checkout API');
});

test('disabled channels are skipped', function () {
    Mail::fake();
    Http::fake();
    $this->discord->update(['enabled' => false]);

    failMonitor($this->monitor);

    expect(NotificationDelivery::count())->toBe(1);
    Http::assertNothingSent();
});

test('resolution and acknowledgement are notified, once each', function () {
    Mail::fake();
    Http::fake(['discord.com/*' => Http::response('', 204)]);
    $incident = failMonitor($this->monitor);

    app(AcknowledgeIncident::class)->handle($incident, User::factory()->create(['name' => 'Alice']));
    app(AcknowledgeIncident::class)->handle($incident, User::factory()->create());
    recordCheck($this->monitor, true, '2026-11-22 10:03:00');
    recordCheck($this->monitor, true, '2026-11-22 10:04:00');

    $events = NotificationDelivery::where('channel_id', $this->email->id)->pluck('event_type')->map->value->sort()->values()->all();

    expect($events)->toBe(['acknowledged', 'opened', 'resolved']);
    Mail::assertSent(IncidentNotificationMail::class, fn ($mail) => $mail->notification->event === NotificationEvent::Acknowledged
        && str_contains($mail->notification->summary(), 'Alice'));
});

test('replayed domain events never create duplicate deliveries', function () {
    Queue::fake([SendNotificationDelivery::class]);
    $incident = failMonitor($this->monitor);

    app(QueueIncidentNotifications::class)->handle($incident, NotificationEvent::Opened);
    app(QueueIncidentNotifications::class)->handle($incident, NotificationEvent::Opened);

    expect(NotificationDelivery::count())->toBe(2);
    Queue::assertPushedOn('notifications', SendNotificationDelivery::class);
    Queue::assertPushed(SendNotificationDelivery::class, 2);
});

function deliveryFor(NotificationChannel $channel, Incident $incident): NotificationDelivery
{
    return NotificationDelivery::create([
        'organization_id' => $incident->organization_id,
        'incident_id' => $incident->id,
        'channel_id' => $channel->id,
        'event_type' => NotificationEvent::Opened,
        'status' => DeliveryStatus::Pending,
    ]);
}

function runDelivery(NotificationDelivery $delivery): SendNotificationDelivery
{
    $job = (new SendNotificationDelivery($delivery->id))->withFakeQueueInteractions();
    app()->call([$job, 'handle']);

    return $job;
}

test('transient Discord failures are retried with backoff, then marked failed', function () {
    Http::fake(['discord.com/*' => Http::response('', 503)]);
    $incident = Incident::factory()->create(['monitor_id' => $this->monitor->id]);
    $delivery = deliveryFor($this->discord, $incident);

    $job = runDelivery($delivery);
    $job->assertReleased(SendNotificationDelivery::BACKOFF[0]);
    expect($delivery->fresh())->status->toBe(DeliveryStatus::Pending)->attempts->toBe(1);

    $delivery->update(['attempts' => SendNotificationDelivery::MAX_ATTEMPTS - 1]);
    runDelivery($delivery)->assertNotReleased();

    expect($delivery->fresh())
        ->status->toBe(DeliveryStatus::Failed)
        ->attempts->toBe(SendNotificationDelivery::MAX_ATTEMPTS)
        ->last_error->toBe('Discord error (HTTP 503).');
});

test('Discord rate limits honour Retry-After', function () {
    Http::fake(['discord.com/*' => Http::response(['retry_after' => 12.4], 429)]);
    $delivery = deliveryFor($this->discord, Incident::factory()->create(['monitor_id' => $this->monitor->id]));

    runDelivery($delivery)->assertReleased(13);
});

test('a deleted webhook fails permanently without retrying', function () {
    Http::fake(['discord.com/*' => Http::response(['message' => 'Unknown Webhook'], 404)]);
    $delivery = deliveryFor($this->discord, Incident::factory()->create(['monitor_id' => $this->monitor->id]));

    runDelivery($delivery)->assertNotReleased();

    expect($delivery->fresh()->status)->toBe(DeliveryStatus::Failed);
});

test('sent deliveries are never sent again', function () {
    Http::fake(['discord.com/*' => Http::response('', 204)]);
    $delivery = deliveryFor($this->discord, Incident::factory()->create(['monitor_id' => $this->monitor->id]));

    runDelivery($delivery);
    runDelivery($delivery);

    Http::assertSentCount(1);
});

test('webhook URLs and tokens never leak into logs or errors', function () {
    Log::spy();
    Http::fake(fn () => throw new ConnectionException('cURL error 7: Failed to connect to '.HOOK));
    $delivery = deliveryFor($this->discord, Incident::factory()->create(['monitor_id' => $this->monitor->id]));

    runDelivery($delivery);

    expect($delivery->fresh()->last_error)->toBe('Could not reach Discord.')
        ->and($delivery->fresh()->last_error)->not->toContain('very-secret-token');
    Log::shouldNotHaveReceived('error');
});

test('a tampered stored webhook is never called', function () {
    Http::fake();
    $this->discord->update(['config_encrypted' => ['webhook_url' => 'http://169.254.169.254/latest/meta-data/']]);
    $delivery = deliveryFor($this->discord, Incident::factory()->create(['monitor_id' => $this->monitor->id]));

    runDelivery($delivery);

    Http::assertNothingSent();
    expect($delivery->fresh()->status)->toBe(DeliveryStatus::Failed);
});
