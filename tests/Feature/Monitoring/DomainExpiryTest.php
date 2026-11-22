<?php

use App\Domain\Monitoring\Domains\CheckDomainExpiry;
use App\Domain\Monitoring\Domains\DomainLookupFailed;
use App\Domain\Monitoring\Domains\RdapLookup;
use App\Domain\Monitoring\Domains\RefreshDomainExpiries;
use App\Domain\Monitoring\Jobs\CheckDomainExpiriesJob;
use App\Domain\Monitoring\Jobs\PurgeExpiredHistory;
use App\Domain\Monitoring\Models\DomainExpiryAlert;
use App\Domain\Monitoring\Models\Monitor;
use App\Domain\Monitoring\Models\MonitorDomain;
use App\Domain\Notifications\Channels\MessageFactory;
use App\Domain\Notifications\Channels\WebhookSender;
use App\Domain\Notifications\Enums\NotificationEvent;
use App\Domain\Notifications\Jobs\SendNotificationDelivery;
use App\Domain\Notifications\Models\NotificationChannel;
use App\Domain\Notifications\Models\NotificationDelivery;
use Carbon\CarbonImmutable;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;

const BOOTSTRAP_URL = 'https://data.iana.org/rdap/dns.json';

beforeEach(function () {
    $this->travelTo('2026-11-22 09:00:00');
    foreach (['data.iana.org', 'rdap.verisign.com', 'rdap.nominet.uk'] as $index => $host) {
        $this->dns->set($host, ['93.184.216.'.(10 + $index)]);
    }
    $this->monitor = Monitor::factory()->http(['url' => 'https://www.example.com/health'])->create(['name' => 'Shop']);
    $this->channel = NotificationChannel::factory()->create(['organization_id' => $this->monitor->organization_id, 'config_encrypted' => ['recipients' => ['ops@example.com']]]);
    $this->monitor->notificationChannels()->attach($this->channel->id);
});

function rdapBootstrap(): array
{
    return ['version' => '1.0', 'services' => [
        [['com', 'net'], ['http://rdap.verisign.com/com/v1/', 'https://rdap.verisign.com/com/v1/']],
        [['uk'], ['https://rdap.nominet.uk/uk']],
        [['org'], ['http://rdap.example-insecure.org/']],
    ]];
}

function rdapDomain(string $name, ?string $expiresAt, ?string $registrar = 'Example Registrar, Inc.'): array
{
    return [
        'objectClassName' => 'domain',
        'ldhName' => strtoupper($name),
        'events' => array_values(array_filter([
            ['eventAction' => 'registration', 'eventDate' => '1995-08-14T04:00:00Z'],
            $expiresAt === null ? null : ['eventAction' => 'expiration', 'eventDate' => $expiresAt],
        ])),
        'entities' => $registrar === null ? [] : [[
            'roles' => ['registrar'],
            'vcardArray' => ['vcard', [['version', [], 'text', '4.0'], ['fn', [], 'text', $registrar]]],
        ]],
    ];
}

/**
 * Fakes the bootstrap registry and RDAP servers; the returned object can be
 * changed later to alter responses (Http::fake stubs cannot be replaced).
 */
function fakeRdap(array $responses = []): ArrayObject
{
    $domains = new ArrayObject($responses);

    Http::fake(function (Request $request) use ($domains) {
        if ($request->url() === BOOTSTRAP_URL) {
            return Http::response(rdapBootstrap());
        }

        foreach ($domains as $url => $response) {
            if ($request->url() === $url) {
                return is_int($response) ? Http::response('', $response) : Http::response($response, 200, ['Content-Type' => 'application/rdap+json']);
            }
        }

        return Http::response(['errorCode' => 404], 404);
    });

    return $domains;
}

function refreshDomains(): int
{
    return app(RefreshDomainExpiries::class)->handle();
}

function checkDomains(): int
{
    return app(CheckDomainExpiry::class)->handle();
}

test('the registered domain is found from the longest candidate and stored', function () {
    fakeRdap(['https://rdap.verisign.com/com/v1/domain/example.com' => rdapDomain('example.com', '2027-08-13T04:00:00Z')]);

    expect(refreshDomains())->toBe(1);

    $row = MonitorDomain::sole();
    expect($row->host)->toBe('www.example.com')
        ->and($row->domain)->toBe('example.com')
        ->and($row->expires_at->toDateTimeString())->toBe('2027-08-13 04:00:00')
        ->and($row->registrar)->toBe('Example Registrar, Inc.')
        ->and($row->last_error)->toBeNull();

    Http::assertSentInOrder([
        fn (Request $request) => $request->url() === BOOTSTRAP_URL,
        fn (Request $request) => $request->url() === 'https://rdap.verisign.com/com/v1/domain/www.example.com',
        fn (Request $request) => $request->url() === 'https://rdap.verisign.com/com/v1/domain/example.com',
    ]);
});

test('second-level registries are found without a public suffix list', function () {
    $monitor = Monitor::factory()->dns(['record_name' => 'shop.example.co.uk'])->create();
    fakeRdap(['https://rdap.nominet.uk/uk/domain/example.co.uk' => rdapDomain('example.co.uk', '2027-01-02T00:00:00Z')]);

    refreshDomains();

    expect(MonitorDomain::find($monitor->id)->domain)->toBe('example.co.uk');
});

test('lookups happen at most once a day, shared by monitors following the same host', function () {
    $other = Monitor::factory()->http(['url' => 'https://www.example.com/'])->create();
    fakeRdap(['https://rdap.verisign.com/com/v1/domain/example.com' => rdapDomain('example.com', '2027-08-13T04:00:00Z')]);

    expect(refreshDomains())->toBe(1);
    $this->travel(23)->hours();
    expect(refreshDomains())->toBe(0);
    $this->travel(2)->hours();
    refreshDomains();

    expect(MonitorDomain::find($other->id)->domain)->toBe('example.com')
        ->and(MonitorDomain::find($this->monitor->id)->checked_at->toDateTimeString())->toBe('2026-11-23 10:00:00');
    Http::assertSentCount(6); // per daily lookup: the bootstrap (cached one day) and two candidates
});

test('lookup failures are recorded and keep the last known expiry', function (mixed $response, string $reason) {
    $domains = fakeRdap(['https://rdap.verisign.com/com/v1/domain/example.com' => rdapDomain('example.com', '2027-08-13T04:00:00Z')]);
    refreshDomains();

    $this->travel(25)->hours();
    $domains['https://rdap.verisign.com/com/v1/domain/example.com'] = $response;
    refreshDomains();

    $row = MonitorDomain::sole();
    expect($row->last_error)->toBe($reason)
        ->and($row->expires_at->toDateTimeString())->toBe('2027-08-13 04:00:00')
        ->and($row->checked_at->toDateTimeString())->toBe('2026-11-23 10:00:00');
})->with([
    'server error' => [503, 'The RDAP service returned an error.'],
    'rate limited' => [429, 'The RDAP service rate-limited the request.'],
    'redirect' => [302, 'The RDAP service redirected the request; redirects are not followed.'],
    'no expiry date' => [rdapDomain('example.com', null), 'The registry does not publish an expiry date for this domain.'],
    'not JSON' => [200, 'The RDAP response could not be read.'],
]);

test('hosts without an HTTPS RDAP service or a registration are reported', function (string $url, string $reason) {
    $this->monitor->httpConfig()->update(['url' => $url]);
    fakeRdap();

    refreshDomains();

    expect(MonitorDomain::sole()->last_error)->toBe($reason);
})->with([
    'unknown TLD' => ['https://example.invalid/', 'No RDAP service is published for this top-level domain.'],
    'HTTP-only RDAP service' => ['https://www.example.org/', 'No RDAP service is published for this top-level domain.'],
    'not registered' => ['https://unregistered-name.com/', 'The domain was not found in its registry.'],
]);

test('RDAP servers resolving to forbidden addresses are never contacted', function () {
    $this->dns->set('rdap.verisign.com', ['10.0.0.8']);
    fakeRdap();

    refreshDomains();

    expect(MonitorDomain::sole()->last_error)->toBe('The RDAP service address is not allowed.');
    Http::assertSentCount(1);
});

test('IP targets, TCP monitors, heartbeats and paused monitors are not looked up', function () {
    $this->monitor->httpConfig()->update(['url' => 'https://93.184.215.14/']);
    Monitor::factory()->tcp('example.com', 443)->create();
    Monitor::factory()->heartbeat('t0k3n')->create();
    Monitor::factory()->paused()->http(['url' => 'https://paused.example.com/'])->create();
    fakeRdap();

    expect(refreshDomains())->toBe(0)
        ->and(MonitorDomain::count())->toBe(0);
    Http::assertNothingSent();
});

test('a changed host forgets the previous registration', function () {
    MonitorDomain::query()->create(['monitor_id' => $this->monitor->id, 'host' => 'old.example.net', 'domain' => 'example.net', 'expires_at' => now()->addDay(), 'checked_at' => now()]);
    fakeRdap(['https://rdap.verisign.com/com/v1/domain/example.com' => 404]);

    expect(checkDomains())->toBe(0);

    refreshDomains();

    expect(MonitorDomain::sole())->host->toBe('www.example.com')->domain->toBeNull()->expires_at->toBeNull();
});

test('the lookup budget bounds a run', function () {
    Monitor::factory()->count(3)->sequence(
        ['name' => 'a'], ['name' => 'b'], ['name' => 'c'],
    )->create()->each(fn (Monitor $monitor, int $index) => $monitor->httpConfig()->update(['url' => "https://site{$index}.example.com/"]));
    fakeRdap();

    expect(app(RefreshDomainExpiries::class)->handle(maxLookups: 2))->toBe(2)
        ->and(MonitorDomain::count())->toBe(2);
});

test('expiry warnings are raised once per threshold and expiry date', function () {
    Queue::fake([SendNotificationDelivery::class]);
    NotificationChannel::factory()->create(['organization_id' => $this->monitor->organization_id]); // not attached
    $foreign = NotificationChannel::factory()->create();
    $this->monitor->notificationChannels()->attach($foreign->id);
    MonitorDomain::query()->create(['monitor_id' => $this->monitor->id, 'host' => 'www.example.com', 'domain' => 'example.com', 'expires_at' => now()->addDays(5), 'checked_at' => now()]);

    expect(checkDomains())->toBe(1)->and(checkDomains())->toBe(0);

    $this->travel(5)->days();
    checkDomains();

    // Renewal: a new expiry date starts over.
    MonitorDomain::sole()->update(['expires_at' => now()->addDays(10)]);
    checkDomains();

    expect(DomainExpiryAlert::orderBy('created_at')->pluck('threshold_days')->all())->toBe([7, 0, 14])
        ->and(NotificationDelivery::pluck('channel_id')->unique()->values()->all())->toBe([$this->channel->id])
        ->and(NotificationDelivery::orderBy('created_at')->pluck('event_type')->map->value->all())->toBe(['domain_expiring', 'domain_expired', 'domain_expiring'])
        ->and(NotificationDelivery::whereNotNull('incident_id')->orWhereNotNull('certificate_alert_id')->count())->toBe(0);
});

test('paused and deleted monitors raise no domain warning', function () {
    Queue::fake([SendNotificationDelivery::class]);
    MonitorDomain::query()->create(['monitor_id' => $this->monitor->id, 'host' => 'www.example.com', 'domain' => 'example.com', 'expires_at' => now()->addDay(), 'checked_at' => now()]);

    $this->monitor->update(['enabled' => false]);
    expect(checkDomains())->toBe(0);

    $this->monitor->update(['enabled' => true]);
    $this->monitor->delete();
    expect(checkDomains())->toBe(0);
});

test('domain warnings are delivered with the domain and its expiry, in the organization language', function () {
    $this->monitor->organization->update(['locale' => 'fr']);
    $alert = DomainExpiryAlert::query()->create([
        'organization_id' => $this->monitor->organization_id,
        'monitor_id' => $this->monitor->id,
        'domain' => 'example.com',
        'threshold_days' => 7,
        'expires_at' => CarbonImmutable::parse('2026-11-27 09:00:00'),
        'created_at' => now(),
    ]);

    $message = MessageFactory::forDomainAlert($alert, 'delivery-1');
    $payload = app(WebhookSender::class)->payload($message, 'msg_1');

    expect($message->event)->toBe(NotificationEvent::DomainExpiring)
        ->and($message->title())->toBe('[Veyra] LE DOMAINE EXPIRE BIENTÔT — Shop')
        ->and($message->summary())->toContain('example.com')->toContain('5j 0h')
        ->and(array_column($message->facts(), 'value', 'label'))->toMatchArray(['Domaine' => 'example.com', 'Expiration' => '2026-11-27 09:00:00 UTC'])
        ->and($payload['event'])->toBe('domain.expiring')
        ->and($payload['domain'])->toBe(['name' => 'example.com', 'expires_at' => '2026-11-27T09:00:00Z'])
        ->and($payload['certificate'])->toBeNull()
        ->and($payload['incident'])->toBeNull();
});

test('the delivery history names the monitor of a domain warning', function () {
    Queue::fake([SendNotificationDelivery::class]);
    MonitorDomain::query()->create(['monitor_id' => $this->monitor->id, 'host' => 'www.example.com', 'domain' => 'example.com', 'expires_at' => now()->addDay(), 'checked_at' => now()]);
    checkDomains();

    expect(NotificationDelivery::sole()->monitorName())->toBe('Shop');
});

test('alerts are purged with their deliveries', function () {
    Queue::fake([SendNotificationDelivery::class]);
    MonitorDomain::query()->create(['monitor_id' => $this->monitor->id, 'host' => 'www.example.com', 'domain' => 'example.com', 'expires_at' => now()->addDay(), 'checked_at' => now()]);
    checkDomains();

    $this->travel(PurgeExpiredHistory::DELIVERY_RETENTION_DAYS + 1)->days();
    app()->call([new PurgeExpiredHistory, 'handle']);

    expect(DomainExpiryAlert::count())->toBe(0)->and(NotificationDelivery::count())->toBe(0);
});

test('the command queues the check on the maintenance queue unless disabled', function () {
    Queue::fake();

    $this->artisan('veyra:domains:check')->assertSuccessful();
    Queue::assertPushedOn('maintenance', CheckDomainExpiriesJob::class);

    config(['veyra.domains.enabled' => false]);
    $this->artisan('veyra:domains:check')->expectsOutputToContain('disabled')->assertSuccessful();
    Queue::assertPushed(CheckDomainExpiriesJob::class, 1);
});

test('the job refreshes registrations then raises warnings', function () {
    Queue::fake([SendNotificationDelivery::class]);
    fakeRdap(['https://rdap.verisign.com/com/v1/domain/example.com' => rdapDomain('example.com', now()->addDays(3)->toIso8601ZuluString())]);

    app()->call([new CheckDomainExpiriesJob, 'handle']);

    expect(DomainExpiryAlert::sole()->threshold_days)->toBe(7);
});

test('the parser rejects documents without an expiration event', function () {
    RdapLookup::parse(['events' => [['eventAction' => 'expiration', 'eventDate' => 'not a date']]], 'example.com');
})->throws(DomainLookupFailed::class);
