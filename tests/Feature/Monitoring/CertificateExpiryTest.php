<?php

use App\Domain\Monitoring\Certificates\CheckCertificateExpiry;
use App\Domain\Monitoring\Enums\MonitorType;
use App\Domain\Monitoring\Jobs\PurgeExpiredHistory;
use App\Domain\Monitoring\Models\Monitor;
use App\Domain\Monitoring\Models\MonitorTlsCertificate;
use App\Domain\Monitoring\Models\TlsCertificateAlert;
use App\Domain\Notifications\Enums\DeliveryStatus;
use App\Domain\Notifications\Enums\NotificationEvent;
use App\Domain\Notifications\Jobs\SendNotificationDelivery;
use App\Domain\Notifications\Mail\IncidentNotificationMail;
use App\Domain\Notifications\Models\NotificationChannel;
use App\Domain\Notifications\Models\NotificationDelivery;
use App\Domain\Organizations\Enums\OrganizationRole;
use Carbon\CarbonImmutable;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Inertia\Testing\AssertableInertia as Assert;

beforeEach(function () {
    $this->travelTo('2026-11-22 09:00:00');
    $this->monitor = Monitor::factory()->create(['name' => 'Checkout API']);
    $this->channel = NotificationChannel::factory()->create(['organization_id' => $this->monitor->organization_id, 'config_encrypted' => ['recipients' => ['ops@example.com']]]);
    $this->monitor->notificationChannels()->attach($this->channel->id);
});

function seeCertificate(Monitor $monitor, CarbonImmutable|string $notAfter, string $fingerprint = 'a'): MonitorTlsCertificate
{
    return MonitorTlsCertificate::query()->updateOrCreate(['monitor_id' => $monitor->id], [
        'fingerprint' => str_repeat($fingerprint, 64),
        'subject' => 'shop.example.com',
        'issuer' => "Let's Encrypt",
        'not_before' => now()->subDays(60),
        'not_after' => $notAfter,
        'checked_at' => now(),
    ]);
}

function checkCertificates(): int
{
    return app(CheckCertificateExpiry::class)->handle();
}

test('the most urgent threshold reached is selected', function (string $notAfter, ?int $expected) {
    expect(CheckCertificateExpiry::thresholdFor(CarbonImmutable::parse($notAfter), CarbonImmutable::parse('2026-11-22 09:00:00'), [14, 7, 1]))->toBe($expected);
})->with([
    'far away' => ['2026-12-20 09:00:00', null],
    'exactly 14 days' => ['2026-12-06 09:00:00', 14],
    '10 days' => ['2026-12-02 09:00:00', 14],
    '5 days' => ['2026-11-27 09:00:00', 7],
    '12 hours' => ['2026-11-22 21:00:00', 1],
    'expired' => ['2026-11-22 09:00:00', 0],
]);

test('thresholds come from config, sanitized and descending', function () {
    config(['veyra.certificates.warning_days' => [3, 30, 0, -2, 365, 30]]);
    expect(CheckCertificateExpiry::thresholds())->toBe([30, 3]);

    config(['veyra.certificates.warning_days' => []]);
    expect(CheckCertificateExpiry::thresholds())->toBe([14, 7, 1]);
});

test('a certificate close to expiry warns attached enabled channels once', function () {
    Queue::fake([SendNotificationDelivery::class]);
    NotificationChannel::factory()->create(['organization_id' => $this->monitor->organization_id]); // not attached
    $disabled = NotificationChannel::factory()->create(['organization_id' => $this->monitor->organization_id, 'enabled' => false]);
    $this->monitor->notificationChannels()->attach($disabled->id);
    seeCertificate($this->monitor, now()->addDays(10));

    expect(checkCertificates())->toBe(1)
        ->and(checkCertificates())->toBe(0);

    $alert = TlsCertificateAlert::sole();
    $delivery = NotificationDelivery::sole();

    expect($alert->threshold_days)->toBe(14)
        ->and($alert->organization_id)->toBe($this->monitor->organization_id)
        ->and($delivery->channel_id)->toBe($this->channel->id)
        ->and($delivery->incident_id)->toBeNull()
        ->and($delivery->certificate_alert_id)->toBe($alert->id)
        ->and($delivery->event_type)->toBe(NotificationEvent::CertificateExpiring);
    Queue::assertPushedOn('notifications', SendNotificationDelivery::class);
});

test('a certificate first seen late skips the earlier warnings, then gets the next ones', function () {
    Queue::fake([SendNotificationDelivery::class]);
    seeCertificate($this->monitor, now()->addDays(5));

    checkCertificates();
    $this->travel(108)->hours();
    checkCertificates();
    $this->travel(1)->days();
    checkCertificates();

    expect(TlsCertificateAlert::orderBy('created_at')->pluck('threshold_days')->all())->toBe([7, 1, 0])
        ->and(NotificationDelivery::orderBy('created_at')->pluck('event_type')->map->value->all())
        ->toBe(['certificate_expiring', 'certificate_expiring', 'certificate_expired']);
});

test('a renewed certificate starts over and a far one raises nothing', function () {
    Queue::fake([SendNotificationDelivery::class]);
    seeCertificate($this->monitor, now()->addDays(3));
    checkCertificates();

    seeCertificate($this->monitor, now()->addDays(90), 'b');
    expect(checkCertificates())->toBe(0);

    $this->travel(80)->days();
    expect(checkCertificates())->toBe(1)
        ->and(TlsCertificateAlert::where('fingerprint', str_repeat('b', 64))->value('threshold_days'))->toBe(14);
});

test('paused, deleted and non-HTTP monitors are not warned', function () {
    Queue::fake([SendNotificationDelivery::class]);
    $paused = Monitor::factory()->paused()->create(['organization_id' => $this->monitor->organization_id]);
    $deleted = Monitor::factory()->create(['organization_id' => $this->monitor->organization_id]);
    $tcp = Monitor::factory()->tcp()->create(['organization_id' => $this->monitor->organization_id]);
    foreach ([$paused, $deleted, $tcp] as $monitor) {
        seeCertificate($monitor, now()->addDay());
    }
    $deleted->delete();

    expect($tcp->type)->toBe(MonitorType::Tcp)
        ->and(checkCertificates())->toBe(0);
});

test('channels of another organization are never used', function () {
    Queue::fake([SendNotificationDelivery::class]);
    $foreign = NotificationChannel::factory()->create();
    $this->monitor->notificationChannels()->attach($foreign->id);
    seeCertificate($this->monitor, now()->addDay());

    checkCertificates();

    expect(NotificationDelivery::pluck('channel_id')->all())->toBe([$this->channel->id]);
});

test('certificate warnings are delivered by e-mail and Discord', function () {
    Mail::fake();
    Http::fake(['discord.com/*' => Http::response('', 204)]);
    $discord = NotificationChannel::factory()->discord()->create(['organization_id' => $this->monitor->organization_id]);
    $this->monitor->notificationChannels()->attach($discord->id);
    seeCertificate($this->monitor, now()->addDays(6));

    checkCertificates();

    expect(NotificationDelivery::where('status', DeliveryStatus::Sent)->count())->toBe(2);
    Mail::assertSent(IncidentNotificationMail::class, fn (IncidentNotificationMail $mail) => $mail->hasTo('ops@example.com')
        && $mail->envelope()->subject === '[Veyra] CERTIFICATE EXPIRES SOON — Checkout API'
        && $mail->notification->certificateIssuer === "Let's Encrypt"
        && str_contains($mail->notification->summary(), 'expires in 6d 0h'));
    Http::assertSent(fn (Request $request) => $request['embeds'][0]['title'] === '[Veyra] CERTIFICATE EXPIRES SOON — Checkout API'
        && collect($request['embeds'][0]['fields'])->pluck('name')->all() === ['Monitor', 'Expires', 'Issuer']);
});

test('an expired certificate sends the expired event', function () {
    Mail::fake();
    seeCertificate($this->monitor, now()->subHour());

    checkCertificates();

    Mail::assertSent(IncidentNotificationMail::class, fn (IncidentNotificationMail $mail) => $mail->notification->event === NotificationEvent::CertificateExpired
        && $mail->envelope()->subject === '[Veyra] CERTIFICATE EXPIRED — Checkout API');
});

test('certificate deliveries appear in the delivery history', function () {
    Mail::fake();
    $organization = $this->monitor->organization;
    $admin = memberOf($organization, OrganizationRole::Admin);
    seeCertificate($this->monitor, now()->addDay());
    checkCertificates();

    $this->actingAs($admin)
        ->get(route('organizations.notification-channels.index', $organization))
        ->assertInertia(fn (Assert $page) => $page
            ->where('deliveries.0.event', 'certificate_expiring')
            ->where('deliveries.0.monitor', 'Checkout API')
            ->where('deliveries.0.incident_id', null));
});

test('old certificate alerts are purged with their deliveries', function () {
    Queue::fake([SendNotificationDelivery::class]);
    seeCertificate($this->monitor, now()->addDay());
    checkCertificates();

    $this->travel(PurgeExpiredHistory::DELIVERY_RETENTION_DAYS + 1)->days();
    (new PurgeExpiredHistory)->handle();

    expect(TlsCertificateAlert::count())->toBe(0)
        ->and(NotificationDelivery::count())->toBe(0);
});

test('the command is scheduled hourly and reports raised alerts', function () {
    Queue::fake([SendNotificationDelivery::class]);
    seeCertificate($this->monitor, now()->addDay());

    $this->artisan('veyra:certificates:check')->expectsOutputToContain('Raised 1 certificate alert(s).')->assertSuccessful();
});
