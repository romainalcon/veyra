<?php

use App\Domain\Incidents\Actions\AddIncidentNote;
use App\Domain\Incidents\Actions\OpenIncident;
use App\Domain\Incidents\Actions\PostPublicIncidentUpdate;
use App\Domain\Incidents\Actions\ResolveIncident;
use App\Domain\Incidents\Events\IncidentOpened;
use App\Domain\Incidents\Models\Incident;
use App\Domain\Maintenance\Actions\SaveMaintenanceWindow;
use App\Domain\Maintenance\DTOs\MaintenanceWindowData;
use App\Domain\Monitoring\Jobs\PurgeExpiredHistory;
use App\Domain\Monitoring\Models\Monitor;
use App\Domain\Notifications\Enums\DeliveryStatus;
use App\Domain\StatusPages\Actions\QueueStatusPageAnnouncements;
use App\Domain\StatusPages\Enums\AnnouncementEvent;
use App\Domain\StatusPages\Jobs\SendStatusPageAnnouncement;
use App\Domain\StatusPages\Jobs\SendSubscriberAnnouncement;
use App\Domain\StatusPages\Mail\StatusPageAnnouncementMail;
use App\Domain\StatusPages\Models\StatusPage;
use App\Domain\StatusPages\Models\StatusPageAnnouncement;
use App\Domain\StatusPages\Models\StatusPageSubscriber;
use App\Domain\StatusPages\Models\StatusPageSubscriberDelivery;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Mail\Mailer;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;

beforeEach(function () {
    $this->travelTo('2026-11-22 12:00:00');
    Mail::fake();
    $this->owner = User::factory()->create();
    $this->organization = organizationWith($this->owner, attributes: ['locale' => 'fr']);
    $this->monitor = Monitor::factory()->create(['organization_id' => $this->organization->id, 'name' => 'INTERNAL-db-primary']);
    $this->page = StatusPage::factory()->withSubscriptions()->create(['organization_id' => $this->organization->id, 'slug' => 'acme']);
    $this->page->components()->create(['monitor_id' => $this->monitor->id, 'display_name' => 'API']);
    $this->subscriber = createPageSubscriber($this->page, 'visitor@example.com');
});

function createPageSubscriber(StatusPage $page, string $email, bool $confirmed = true): StatusPageSubscriber
{
    return StatusPageSubscriber::query()->create([
        'organization_id' => $page->organization_id,
        'status_page_id' => $page->id,
        'email' => $email,
        'email_hash' => StatusPageSubscriber::hashEmail($email),
        'confirmed_at' => $confirmed ? now()->subMinute() : null,
    ]);
}

function openAnnouncedIncident(Monitor $monitor): Incident
{
    return app(OpenIncident::class)->handle($monitor, CarbonImmutable::now(), 'TLS error (secret-cause)');
}

test('incident openings, public updates and resolutions reach confirmed subscribers once each', function () {
    $pending = createPageSubscriber($this->page, 'pending@example.com', confirmed: false);

    $incident = openAnnouncedIncident($this->monitor);
    app(PostPublicIncidentUpdate::class)->handle($incident, $this->owner, 'We are investigating API errors.');
    app(AddIncidentNote::class)->handle($incident, $this->owner, 'INTERNAL NOTE: root password rotated');
    app(ResolveIncident::class)->handle($incident->fresh(), CarbonImmutable::now()->addMinutes(10));

    expect(StatusPageAnnouncement::query()->pluck('event')->map->value->sort()->values()->all())
        ->toBe(['incident_opened', 'incident_resolved', 'incident_updated']);

    Mail::assertSent(StatusPageAnnouncementMail::class, 3);
    Mail::assertNotSent(StatusPageAnnouncementMail::class, fn ($mail) => $mail->hasTo($pending->email));
    expect(StatusPageSubscriberDelivery::query()->where('status', DeliveryStatus::Sent)->count())->toBe(3);
});

test('announcement e-mails carry only whitelisted public content, in the organization language', function () {
    $incident = openAnnouncedIncident($this->monitor);
    app(PostPublicIncidentUpdate::class)->handle($incident, $this->owner, 'We are investigating API errors.');

    Mail::assertSent(StatusPageAnnouncementMail::class, function (StatusPageAnnouncementMail $mail) {
        if ($mail->announcement->event !== AnnouncementEvent::IncidentUpdated) {
            return false;
        }

        $html = $mail->render();

        expect($mail->locale)->toBe('fr')
            ->and($html)->toContain('API')
            ->toContain('We are investigating API errors.')
            ->toContain('Se désabonner')
            ->not->toContain('INTERNAL-db-primary')
            ->not->toContain('secret-cause')
            ->not->toContain($this->monitor->id);

        return true;
    });

    $payloads = StatusPageAnnouncement::query()->pluck('payload')->toJson();
    expect($payloads)->not->toContain('INTERNAL-db-primary')->not->toContain('secret-cause');
});

test('every announcement offers one-click unsubscribe headers', function () {
    openAnnouncedIncident($this->monitor);

    Mail::assertSent(StatusPageAnnouncementMail::class, function (StatusPageAnnouncementMail $mail) {
        $headers = $mail->headers()->text;

        return str_starts_with($headers['List-Unsubscribe'], '<'.url('/status-subscriptions/'.$this->subscriber->id.'/unsubscribe'))
            && $headers['List-Unsubscribe-Post'] === 'List-Unsubscribe=One-Click';
    });
});

test('planned maintenance is announced on creation, not on edits', function () {
    $data = new MaintenanceWindowData('Database upgrade', 'Short read-only period.', CarbonImmutable::now()->addDay(), CarbonImmutable::now()->addDay()->addHour(), [$this->monitor->id]);

    $window = app(SaveMaintenanceWindow::class)->handle($this->organization, $data, $this->owner);
    app(SaveMaintenanceWindow::class)->handle($this->organization, new MaintenanceWindowData('Database upgrade v2', null, $data->startsAt, $data->endsAt, [$this->monitor->id]), $this->owner, $window);

    $announcement = StatusPageAnnouncement::query()->sole();
    expect($announcement->event)->toBe(AnnouncementEvent::MaintenanceScheduled)
        ->and($announcement->payload['title'])->toBe('Database upgrade')
        ->and($announcement->payload['components'])->toBe(['API']);
    Mail::assertSent(StatusPageAnnouncementMail::class, 1);
});

test('monitors not shown on a page, unpublished pages and pages without subscriptions announce nothing', function () {
    $hidden = Monitor::factory()->create(['organization_id' => $this->organization->id]);
    openAnnouncedIncident($hidden);

    $draft = StatusPage::factory()->unpublished()->withSubscriptions()->create(['organization_id' => $this->organization->id]);
    $draft->components()->create(['monitor_id' => $this->monitor->id, 'display_name' => 'Draft API']);
    $quiet = StatusPage::factory()->create(['organization_id' => $this->organization->id]);
    $quiet->components()->create(['monitor_id' => $this->monitor->id, 'display_name' => 'Quiet API']);
    createPageSubscriber($draft, 'draft@example.com');
    createPageSubscriber($quiet, 'quiet@example.com');

    openAnnouncedIncident($this->monitor);

    expect(StatusPageAnnouncement::query()->pluck('status_page_id')->all())->toBe([$this->page->id]);
    Mail::assertSent(StatusPageAnnouncementMail::class, 1);
    Mail::assertNotSent(StatusPageAnnouncementMail::class, fn ($mail) => $mail->hasTo('draft@example.com') || $mail->hasTo('quiet@example.com'));
});

test('another organization\'s page never announces this organization\'s incidents', function () {
    $foreign = StatusPage::factory()->withSubscriptions()->create();
    // A component pointing at a monitor of another organization (defence in depth).
    $foreign->components()->create(['monitor_id' => $this->monitor->id, 'display_name' => 'Stolen']);
    createPageSubscriber($foreign, 'foreign@example.com');

    openAnnouncedIncident($this->monitor);

    Mail::assertNotSent(StatusPageAnnouncementMail::class, fn ($mail) => $mail->hasTo('foreign@example.com'));
    expect(StatusPageAnnouncement::query()->where('status_page_id', $foreign->id)->exists())->toBeFalse();
});

test('replayed domain events and duplicate fan-out jobs send nothing twice', function () {
    $incident = openAnnouncedIncident($this->monitor);

    IncidentOpened::dispatch($incident->id);
    app(QueueStatusPageAnnouncements::class)->forIncident($incident, AnnouncementEvent::IncidentOpened);
    $announcement = StatusPageAnnouncement::query()->sole();
    (new SendStatusPageAnnouncement($announcement->id))->handle();
    (new SendSubscriberAnnouncement(StatusPageSubscriberDelivery::query()->sole()->id))->handle(app(Mailer::class));

    expect(StatusPageAnnouncement::query()->count())->toBe(1)
        ->and(StatusPageSubscriberDelivery::query()->count())->toBe(1);
    Mail::assertSent(StatusPageAnnouncementMail::class, 1);
});

test('subscribers confirmed after an announcement do not receive it', function () {
    Queue::fake();
    $incident = openAnnouncedIncident($this->monitor);
    $announcement = StatusPageAnnouncement::query()->sole();

    $this->travel(5)->minutes();
    createPageSubscriber($this->page, 'late@example.com')->update(['confirmed_at' => now()]);
    (new SendStatusPageAnnouncement($announcement->id))->handle();

    expect(StatusPageSubscriberDelivery::query()->pluck('subscriber_id')->all())->toBe([$this->subscriber->id]);
});

test('fan-out works in bounded batches with a cursor', function () {
    Queue::fake();
    $incident = openAnnouncedIncident($this->monitor);
    $announcement = StatusPageAnnouncement::query()->sole();

    (new SendStatusPageAnnouncement($announcement->id, afterSubscriberId: 'ffffffff-ffff-ffff-ffff-ffffffffffff'))->handle();

    expect(StatusPageSubscriberDelivery::query()->count())->toBe(0);
    Queue::assertNotPushed(SendStatusPageAnnouncement::class, fn ($job) => $job->afterSubscriberId !== null);
});

test('transport errors are retried, recorded without the address, then given up', function () {
    Queue::fake();
    openAnnouncedIncident($this->monitor);
    (new SendStatusPageAnnouncement(StatusPageAnnouncement::query()->sole()->id))->handle();
    $delivery = StatusPageSubscriberDelivery::query()->sole();

    $mailer = Mockery::mock(Mailer::class);
    $mailer->shouldReceive('to')->andThrow(new RuntimeException('550 rejected visitor@example.com'));

    foreach (range(1, SendSubscriberAnnouncement::MAX_ATTEMPTS) as $attempt) {
        $job = (new SendSubscriberAnnouncement($delivery->id))->withFakeQueueInteractions();
        $job->handle($mailer);
    }

    $delivery->refresh();
    expect($delivery->status)->toBe(DeliveryStatus::Failed)
        ->and($delivery->attempts)->toBe(SendSubscriberAnnouncement::MAX_ATTEMPTS)
        ->and($delivery->last_error)->toBe('Mail transport error: RuntimeException')
        ->and($delivery->last_error)->not->toContain('example.com');
});

test('unsubscribing before delivery cancels it', function () {
    Queue::fake();
    openAnnouncedIncident($this->monitor);
    (new SendStatusPageAnnouncement(StatusPageAnnouncement::query()->sole()->id))->handle();
    $deliveryId = StatusPageSubscriberDelivery::query()->sole()->id;

    $this->subscriber->delete();
    (new SendSubscriberAnnouncement($deliveryId))->handle(app(Mailer::class));

    Mail::assertNothingSent();
    expect(StatusPageSubscriberDelivery::query()->count())->toBe(0);
});

test('announcements and deliveries are purged after their retention period', function () {
    openAnnouncedIncident($this->monitor);

    $this->travel(31)->days();
    (new PurgeExpiredHistory)->handle();

    expect(StatusPageAnnouncement::query()->count())->toBe(0)
        ->and(StatusPageSubscriberDelivery::query()->count())->toBe(0)
        ->and(StatusPageSubscriber::query()->count())->toBe(1);
});
