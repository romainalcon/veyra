<?php

use App\Domain\Monitoring\Enums\MonitorStatus;
use App\Domain\Monitoring\Models\Monitor;
use App\Domain\Monitoring\Models\MonitorCheck;
use App\Domain\Monitoring\Models\MonitorState;
use App\Domain\Monitoring\Models\MonitorTlsCertificate;
use App\Domain\Organizations\Enums\OrganizationRole;
use App\Domain\StatusPages\Models\StatusPage;
use App\Http\Presenters\MonitorPresenter;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Cache;
use Inertia\Testing\AssertableInertia as Assert;

const TYPES_PAGE_TOKEN = 'abcdefghijklmnopqrstuvwxyz0123456789ABCD';

beforeEach(function () {
    $this->travelTo('2026-11-22 12:00:00');
    $this->user = User::factory()->create();
    $this->organization = organizationWith($this->user, OrganizationRole::Viewer);
    $attributes = ['organization_id' => $this->organization->id, 'created_at' => '2026-11-01 00:00:00'];
    $this->http = Monitor::factory()->create([...$attributes, 'name' => 'Shop']);
    $this->tcp = Monitor::factory()->tcp('db.internal-example.com', 5432)->create([...$attributes, 'name' => 'Database']);
    $this->heartbeat = Monitor::factory()->heartbeat(TYPES_PAGE_TOKEN)->create([...$attributes, 'name' => 'Nightly backup']);
});

function certificateFor(Monitor $monitor, string $notAfter): void
{
    MonitorTlsCertificate::create([
        'monitor_id' => $monitor->id,
        'fingerprint' => str_repeat('c', 64),
        'subject' => 'shop.example.com',
        'issuer' => "Let's Encrypt",
        'not_before' => '2026-09-01 00:00:00',
        'not_after' => $notAfter,
        'checked_at' => now(),
    ]);
}

test('the certificate status follows the warning thresholds', function (string $notAfter, string $status, int $daysLeft) {
    $certificate = new MonitorTlsCertificate([
        'subject' => 'shop.example.com', 'issuer' => 'CA', 'not_before' => '2026-09-01 00:00:00',
        'not_after' => $notAfter, 'checked_at' => '2026-11-22 11:00:00',
    ]);

    expect(MonitorPresenter::certificate($certificate, CarbonImmutable::parse('2026-11-22 12:00:00')))
        ->toMatchArray(['status' => $status, 'days_left' => $daysLeft]);
})->with([
    'valid' => ['2027-02-01 12:00:00', 'valid', 71],
    'first warning' => ['2026-12-06 12:00:00', 'expiring', 14],
    'last hours' => ['2026-11-22 20:00:00', 'expiring', 0],
    'expired' => ['2026-11-20 12:00:00', 'expired', -2],
]);

test('the HTTPS detail page exposes the last certificate seen', function () {
    certificateFor($this->http, '2026-11-27 12:00:00');

    $this->actingAs($this->user)
        ->get(route('organizations.monitors.show', [$this->organization, $this->http]))
        ->assertInertia(fn (Assert $page) => $page
            ->where('monitor.certificate.subject', 'shop.example.com')
            ->where('monitor.certificate.issuer', "Let's Encrypt")
            ->where('monitor.certificate.days_left', 5)
            ->where('monitor.certificate.status', 'expiring')
            ->missing('monitor.certificate.fingerprint'));
});

test('monitors without a certificate expose none', function () {
    certificateFor($this->tcp, '2026-11-27 12:00:00'); // stale row from another type is ignored

    foreach ([$this->http, $this->tcp, $this->heartbeat] as $monitor) {
        $this->actingAs($this->user)
            ->get(route('organizations.monitors.show', [$this->organization, $monitor]))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page->where('monitor.certificate', null));
    }
});

test('the monitor list and dashboard cover every monitor type', function () {
    foreach ([$this->http, $this->tcp, $this->heartbeat] as $monitor) {
        MonitorCheck::factory()->create(['monitor_id' => $monitor->id, 'scheduled_for' => '2026-11-22 11:00:00', 'checked_at' => '2026-11-22 11:00:01', 'success' => true]);
    }

    $this->actingAs($this->user)
        ->get(route('organizations.monitors.index', $this->organization))
        ->assertInertia(fn (Assert $page) => $page
            ->has('monitors.data', 3)
            ->where('monitors.data', fn ($monitors) => collect($monitors)->pluck('type')->sort()->values()->all() === ['heartbeat', 'http', 'tcp']));

    $this->actingAs($this->user)
        ->get(route('organizations.dashboard', $this->organization))
        ->assertInertia(fn (Assert $page) => $page
            ->loadDeferredProps(fn (Assert $reload) => $reload
                ->has('analytics.monitors', 3)
                ->where('analytics.uptime.24h.uptime', 100)));
});

test('status pages show TCP and heartbeat components without leaking targets', function () {
    $page = StatusPage::factory()->create(['organization_id' => $this->organization->id, 'slug' => 'acme']);
    $page->components()->create(['monitor_id' => $this->tcp->id, 'display_name' => 'Database', 'group_name' => 'Core']);
    $page->components()->create(['monitor_id' => $this->heartbeat->id, 'display_name' => 'Backups', 'group_name' => 'Core', 'position' => 1]);
    MonitorState::whereKey($this->tcp->id)->update(['status' => MonitorStatus::Up->value]);
    MonitorState::whereKey($this->heartbeat->id)->update(['status' => MonitorStatus::Down->value]);
    Cache::flush();

    $response = $this->get('/status/acme');

    $response->assertOk()->assertInertia(fn (Assert $inertia) => $inertia
        ->where('snapshot.groups.0.components.0.name', 'Database')
        ->where('snapshot.groups.0.components.0.status', 'operational')
        ->where('snapshot.groups.0.components.1.name', 'Backups')
        ->where('snapshot.groups.0.components.1.status', 'outage'));

    expect($response->getContent())
        ->not->toContain('db.internal-example.com')
        // The port as a target or a field: a bare "5432" can occur in random ids or asset hashes.
        ->not->toContain(':5432')
        ->not->toContain('"port"')
        ->not->toContain(TYPES_PAGE_TOKEN);
});
