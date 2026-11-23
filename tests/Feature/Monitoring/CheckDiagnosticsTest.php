<?php

use App\Domain\Incidents\Models\Incident;
use App\Domain\Monitoring\Actions\ApplyMonitorState;
use App\Domain\Monitoring\Actions\RecordProbeResult;
use App\Domain\Monitoring\DTOs\CheckTimings;
use App\Domain\Monitoring\DTOs\ExpectedStatuses;
use App\Domain\Monitoring\DTOs\ProbeResult;
use App\Domain\Monitoring\Enums\DnsMatchMode;
use App\Domain\Monitoring\Enums\DnsRecordType;
use App\Domain\Monitoring\Enums\DnsResolverChoice;
use App\Domain\Monitoring\Enums\HttpMethod;
use App\Domain\Monitoring\Enums\ProbeErrorCode;
use App\Domain\Monitoring\Jobs\PurgeExpiredHistory;
use App\Domain\Monitoring\Models\CheckEvidence;
use App\Domain\Monitoring\Models\Monitor;
use App\Domain\Monitoring\Models\MonitorCheck;
use App\Domain\Monitoring\Models\MonitorDispatch;
use App\Domain\Monitoring\Probes\ContentAssertion;
use App\Domain\Monitoring\Probes\Contracts\TcpConnector;
use App\Domain\Monitoring\Probes\DnsProbe;
use App\Domain\Monitoring\Probes\FailureEvidence;
use App\Domain\Monitoring\Probes\HttpProbe;
use App\Domain\Monitoring\Probes\TcpProbe;
use App\Domain\Monitoring\Stats\AggregateDailyStats;
use App\Domain\Monitoring\Stats\MonitorSeries;
use App\Domain\Monitoring\Stats\StatsRange;
use App\Domain\Notifications\Channels\MessageFactory;
use App\Domain\Notifications\Enums\NotificationEvent;
use App\Domain\Organizations\Enums\OrganizationRole;
use App\Models\User;
use Carbon\CarbonImmutable;
use GuzzleHttp\Psr7\Request as PsrRequest;
use GuzzleHttp\TransferStats;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Support\FakeTcpConnector;

/**
 * Sprint 23 — check diagnostics (ADR-050): phase timings and failure evidence.
 */
function diagnosticsProbe(string $url, HttpMethod $method = HttpMethod::Get, string $expected = '200-299', ?ContentAssertion $assertion = null): ProbeResult
{
    return app(HttpProbe::class)->probe($url, $method, [], ExpectedStatuses::parse($expected), true, 10, assertion: $assertion);
}

/**
 * Calls on_stats like cURL would after the transfer.
 *
 * @param  array<string, int>  $stats
 */
function fakeTransferStats(array $options, array $stats): void
{
    if (isset($options['on_stats'])) {
        $options['on_stats'](new TransferStats(new PsrRequest('GET', 'https://example.com/'), null, 0.18, null, $stats));
    }
}

function failingResult(string $body = 'Service unavailable', ?CheckTimings $timings = null): ProbeResult
{
    $evidence = new FailureEvidence(503, '1.1', ProbeErrorCode::UnexpectedStatus, 'text/plain', ['server' => 'nginx'], $body, false, null);

    return ProbeResult::failure(ProbeErrorCode::UnexpectedStatus, 120, 503, ['redirects' => 0], $timings, $evidence);
}

function recordResult(Monitor $monitor, ProbeResult $result, string $scheduledFor): void
{
    $dispatch = MonitorDispatch::factory()->create(['monitor_id' => $monitor->id, 'scheduled_for' => $scheduledFor]);
    app(RecordProbeResult::class)->handle($dispatch, $result, CarbonImmutable::parse($scheduledFor)->addSecond());
    app(ApplyMonitorState::class)->handle($monitor->id);
}

const HTTPS_STATS = [
    'namelookup_time_us' => 0,
    'connect_time_us' => 20_000,
    'appconnect_time_us' => 60_000,
    'starttransfer_time_us' => 170_000,
    'total_time_us' => 180_000,
];

test('an HTTPS check records every phase of its connection', function () {
    Http::fake(function ($request, array $options) {
        fakeTransferStats($options, HTTPS_STATS);

        return Http::response('ok', 200);
    });

    $result = diagnosticsProbe('https://example.com/');

    expect($result->timings?->toArray())->toMatchArray(['connect' => 20, 'tls' => 40, 'ttfb' => 110, 'transfer' => 10, 'redirect' => 0])
        ->and($result->timings?->dnsMs)->toBeInt()
        ->and($result->evidence)->toBeNull();
});

test('an IP address target has no DNS phase and redirects are timed separately', function () {
    Http::fake([
        'http://93.184.215.14/' => Http::response('', 301, ['Location' => 'http://93.184.215.14/home']),
        'http://93.184.215.14/home' => Http::response('ok', 200),
    ]);

    $result = diagnosticsProbe('http://93.184.215.14/');

    expect($result->timings?->dnsMs)->toBeNull()
        ->and($result->timings?->redirectMs)->toBeGreaterThanOrEqual(0)
        ->and($result->timings?->tlsMs)->toBeNull();
});

test('a failing response keeps allow-listed headers and a bounded text excerpt', function () {
    Http::fake(['*' => Http::response(str_repeat('é', 3000), 503, [
        'Content-Type' => 'text/html; charset=utf-8',
        'Server' => 'nginx',
        'Set-Cookie' => 'session=top-secret',
        'WWW-Authenticate' => 'Bearer realm="internal"',
        'X-Internal-Token' => 'top-secret',
        'Retry-After' => '120',
    ])]);

    $evidence = diagnosticsProbe('https://example.com/')->evidence;

    expect($evidence)->not->toBeNull()
        ->and($evidence->statusCode)->toBe(503)
        ->and($evidence->errorCode)->toBe(ProbeErrorCode::UnexpectedStatus)
        ->and($evidence->headers)->toBe(['content-type' => 'text/html; charset=utf-8', 'retry-after' => '120', 'server' => 'nginx'])
        ->and(strlen((string) $evidence->bodyExcerpt))->toBeLessThanOrEqual(FailureEvidence::EXCERPT_BYTES)
        ->and(mb_check_encoding((string) $evidence->bodyExcerpt, 'UTF-8'))->toBeTrue()
        ->and($evidence->bodyTruncated)->toBeTrue()
        ->and(json_encode($evidence->toArray()))->not->toContain('top-secret');
});

test('the Location header of an evidence never keeps its query string or credentials', function () {
    Http::fake(['*' => Http::response('', 302, ['Location' => 'https://user:pass@login.example.com/sso?token=abc#frag'])]);

    $evidence = app(HttpProbe::class)->probe('https://example.com/', HttpMethod::Get, [], ExpectedStatuses::parse('200'), false, 10)->evidence;

    expect($evidence?->headers['location'])->toBe('https://login.example.com/sso');
});

test('binary, compressed and empty bodies are handled', function (array $response, ?string $excerpt, ?string $omitted) {
    Http::fake(['*' => Http::response(...$response)]);

    $evidence = diagnosticsProbe('https://example.com/')->evidence;

    expect($evidence?->bodyExcerpt)->toBe($excerpt)
        ->and($evidence?->bodyOmittedReason)->toBe($omitted);
})->with([
    'image' => [["\x89PNG\r\n\x1a\n\0\0", 500, ['Content-Type' => 'image/png']], null, FailureEvidence::OMITTED_BINARY],
    'octet stream' => [['plain', 500, ['Content-Type' => 'application/octet-stream']], null, FailureEvidence::OMITTED_BINARY],
    'no content type' => [['plain', 500], null, FailureEvidence::OMITTED_BINARY],
    'text with NUL bytes' => [["a\0b", 500, ['Content-Type' => 'text/plain']], null, FailureEvidence::OMITTED_BINARY],
    'invalid UTF-8' => [["\xff\xfe\xfd", 500, ['Content-Type' => 'text/plain']], null, FailureEvidence::OMITTED_BINARY],
    'gzip json' => [[gzencode('{"error":"db down"}'), 500, ['Content-Type' => 'application/json', 'Content-Encoding' => 'gzip']], '{"error":"db down"}', null],
    'brotli' => [['xxxx', 500, ['Content-Type' => 'text/plain', 'Content-Encoding' => 'br']], null, FailureEvidence::OMITTED_ENCODED],
    'empty' => [['', 500, ['Content-Type' => 'text/plain']], null, FailureEvidence::OMITTED_EMPTY],
]);

test('keyword failures keep the excerpt', function () {
    Http::fake(['*' => Http::response('maintenance page', 200, ['Content-Type' => 'text/plain'])]);

    $evidence = diagnosticsProbe('https://example.com/', assertion: new ContentAssertion('welcome'))->evidence;

    expect($evidence?->errorCode)->toBe(ProbeErrorCode::KeywordMissing)
        ->and($evidence?->bodyExcerpt)->toBe('maintenance page');
});

test('HEAD checks keep no body', function () {
    Http::fake(['*' => Http::response('', 500, ['Content-Type' => 'text/plain'])]);

    expect(diagnosticsProbe('https://example.com/', HttpMethod::Head)->evidence?->bodyOmittedReason)->toBe(FailureEvidence::OMITTED_HEAD);
});

test('failures without a response keep no evidence', function () {
    Http::fake(fn () => throw new ConnectionException('cURL error 28: Operation timed out'));

    $result = diagnosticsProbe('https://example.com/');

    expect($result->errorCode)->toBe(ProbeErrorCode::Timeout)
        ->and($result->evidence)->toBeNull()
        ->and($result->timings?->ttfbMs)->toBeNull();
});

test('TCP checks keep resolution and connect time', function () {
    config(['veyra.probes.tcp_allowed_ports' => [5432]]);
    $connector = new FakeTcpConnector;
    $this->app->instance(TcpConnector::class, $connector);

    $success = app(TcpProbe::class)->run('example.com', 5432, 5);

    expect($success->timings?->dnsMs)->toBeInt()
        ->and($success->timings?->connectMs)->toBeInt()
        ->and($success->timings?->ttfbMs)->toBeNull();

    $connector->result = ProbeErrorCode::ConnectionFailed;
    $failure = app(TcpProbe::class)->run('93.184.215.14', 5432, 5);

    expect($failure->timings?->dnsMs)->toBeNull()
        ->and($failure->timings?->connectMs)->toBeNull()
        ->and($failure->evidence)->toBeNull();
});

test('DNS checks keep their resolution time', function () {
    $this->dnsQueries->answer('example.com', DnsRecordType::A, ['93.184.215.14']);

    $result = app(DnsProbe::class)->run('example.com', DnsRecordType::A, DnsResolverChoice::Cloudflare, [], DnsMatchMode::Contains, 5);

    expect($result->timings?->dnsMs)->toBe($result->durationMs)
        ->and($result->timings?->connectMs)->toBeNull();
});

test('timings are stored on the check and exposed to the UI and the API', function () {
    $owner = User::factory()->create();
    $organization = organizationWith($owner);
    $monitor = Monitor::factory()->create(['organization_id' => $organization->id]);
    $timings = new CheckTimings(10, 20, 40, 110, 9, 0);
    $dispatch = MonitorDispatch::factory()->create(['monitor_id' => $monitor->id, 'scheduled_for' => now()]);

    app(RecordProbeResult::class)->handle($dispatch, new ProbeResult(true, 189, 200, null, ['redirects' => 0], timings: $timings));

    $check = MonitorCheck::query()->where('monitor_id', $monitor->id)->sole();
    expect($check->timings()->toArray())->toBe($timings->toArray());

    $this->actingAs($owner)
        ->get(route('organizations.monitors.show', [$organization, $monitor]))
        ->assertInertia(fn (Assert $page) => $page->where('checks.data.0.timings.ttfb', 110)->where('checks.data.0.timings.tls', 40));

    $this->withApiToken(apiTokenFor($organization))
        ->getJson(route('api.v1.monitors.checks.index', $monitor))
        ->assertJsonPath('data.0.timings', $timings->toArray());
});

test('older checks without timings stay null', function () {
    $check = MonitorCheck::factory()->create();

    expect($check->fresh()->timings()->isEmpty())->toBeTrue();
});

test('phases are averaged in the series and in the daily aggregate', function () {
    $monitor = Monitor::factory()->create();
    $day = CarbonImmutable::now()->subDay()->startOfDay()->addHours(10);

    MonitorCheck::factory()->withTimings(ttfb: 100)->create(['monitor_id' => $monitor->id, 'checked_at' => $day]);
    MonitorCheck::factory()->withTimings(ttfb: 200)->create(['monitor_id' => $monitor->id, 'checked_at' => $day->addMinute()]);
    // Failed checks and checks without timings do not count.
    MonitorCheck::factory()->withTimings(ttfb: 9000)->create(['monitor_id' => $monitor->id, 'checked_at' => $day->addMinutes(2), 'success' => false]);
    MonitorCheck::factory()->create(['monitor_id' => $monitor->id, 'checked_at' => $day->addMinutes(3)]);

    $bucket = collect(app(MonitorSeries::class)->latency($monitor, StatsRange::Week))
        ->first(fn (array $point): bool => $point['phases']['ttfb'] !== null);

    expect($bucket['phases'])->toBe(['dns' => 12, 'connect' => 25, 'tls' => 40, 'ttfb' => 150, 'transfer' => 8]);

    app(AggregateDailyStats::class)->handle($day);

    $stat = DB::table('monitor_daily_stats')->where('monitor_id', $monitor->id)->sole();
    expect((int) $stat->ttfb_avg_ms)->toBe(150)
        ->and((int) $stat->tls_avg_ms)->toBe(40);
});

test('the evidence of the first failing check is attached to the incident', function () {
    $monitor = Monitor::factory()->create();

    recordResult($monitor, failingResult('first failure'), '2026-11-23 10:00:00');
    recordResult($monitor, failingResult('second failure'), '2026-11-23 10:01:00');

    expect(CheckEvidence::query()->where('monitor_id', $monitor->id)->whereNull('incident_id')->count())->toBe(2);

    recordResult($monitor, failingResult('third failure'), '2026-11-23 10:02:00');

    $incident = Incident::query()->where('monitor_id', $monitor->id)->sole();
    $evidence = CheckEvidence::query()->where('monitor_id', $monitor->id)->sole();

    expect($evidence->incident_id)->toBe($incident->id)
        ->and($evidence->body_excerpt)->toBe('first failure')
        ->and($evidence->headers)->toBe(['server' => 'nginx']);

    // While the incident is active, failing checks keep no evidence.
    recordResult($monitor, failingResult('fourth failure'), '2026-11-23 10:03:00');
    expect(CheckEvidence::query()->where('monitor_id', $monitor->id)->count())->toBe(1);
});

test('the first failing check without a response falls back to the next evidence', function () {
    $monitor = Monitor::factory()->create();

    recordResult($monitor, ProbeResult::failure(ProbeErrorCode::Timeout, 10_000), '2026-11-23 10:00:00');
    recordResult($monitor, failingResult('answered'), '2026-11-23 10:01:00');
    recordResult($monitor, failingResult('again'), '2026-11-23 10:02:00');

    expect(Incident::query()->where('monitor_id', $monitor->id)->sole()->evidence?->body_excerpt)->toBe('answered');
});

test('evidence of failures that were never confirmed is discarded', function () {
    $monitor = Monitor::factory()->create();

    recordResult($monitor, failingResult('blip'), '2026-11-23 10:00:00');
    recordResult($monitor, new ProbeResult(true, 100, 200, null), '2026-11-23 10:01:00');
    recordResult($monitor, failingResult('first of the outage'), '2026-11-23 10:02:00');
    recordResult($monitor, failingResult('second'), '2026-11-23 10:03:00');
    recordResult($monitor, failingResult('third'), '2026-11-23 10:04:00');

    expect(Incident::query()->where('monitor_id', $monitor->id)->sole()->evidence?->body_excerpt)->toBe('first of the outage')
        ->and(CheckEvidence::query()->where('monitor_id', $monitor->id)->count())->toBe(1);
});

test('replaying a dispatch does not duplicate its evidence', function () {
    $monitor = Monitor::factory()->create();
    $dispatch = MonitorDispatch::factory()->create(['monitor_id' => $monitor->id, 'scheduled_for' => now()]);

    expect(app(RecordProbeResult::class)->handle($dispatch, failingResult()))->toBeTrue()
        ->and(app(RecordProbeResult::class)->handle($dispatch, failingResult()))->toBeFalse()
        ->and(CheckEvidence::query()->count())->toBe(1);
});

test('unattached evidence is purged after a day and attached evidence follows its incident', function () {
    $monitor = Monitor::factory()->create();
    $incident = Incident::factory()->create(['monitor_id' => $monitor->id]);
    $base = ['organization_id' => $monitor->organization_id, 'monitor_id' => $monitor->id, 'status_code' => 503, 'error_code' => ProbeErrorCode::UnexpectedStatus, 'headers' => [], 'checked_at' => now()];

    $stale = CheckEvidence::create([...$base, 'check_id' => (string) Str::uuid7()]);
    $stale->forceFill(['created_at' => now()->subHours(25)])->save();
    $recent = CheckEvidence::create([...$base, 'check_id' => (string) Str::uuid7()]);
    $attached = CheckEvidence::create([...$base, 'check_id' => (string) Str::uuid7(), 'incident_id' => $incident->id]);
    $attached->forceFill(['created_at' => now()->subYear()])->save();

    (new PurgeExpiredHistory)->handle();

    expect(CheckEvidence::query()->pluck('id')->sort()->values()->all())->toBe(collect([$recent->id, $attached->id])->sort()->values()->all());

    $incident->delete();
    expect(CheckEvidence::query()->find($attached->id))->toBeNull();
});

test('members see the evidence on the incident page of their organization only', function () {
    $member = User::factory()->create();
    $organization = organizationWith($member, OrganizationRole::Member);
    $monitor = Monitor::factory()->create(['organization_id' => $organization->id]);

    recordResult($monitor, failingResult('<script>alert(1)</script>'), '2026-11-23 10:00:00');
    recordResult($monitor, failingResult(), '2026-11-23 10:01:00');
    recordResult($monitor, failingResult(), '2026-11-23 10:02:00');
    $incident = Incident::query()->where('monitor_id', $monitor->id)->sole();

    $this->actingAs($member)
        ->get(route('organizations.incidents.show', [$organization, $incident]))
        ->assertInertia(fn (Assert $page) => $page
            ->where('incident.evidence.status_code', 503)
            ->where('incident.evidence.body_excerpt', '<script>alert(1)</script>')
            ->where('incident.evidence.headers', ['server' => 'nginx']));

    $outsider = User::factory()->create();
    $other = organizationWith($outsider);

    $this->actingAs($outsider)
        ->get(route('organizations.incidents.show', [$organization, $incident]))
        ->assertNotFound();

    $this->actingAs($outsider)
        ->get(route('organizations.incidents.show', [$other, $incident]))
        ->assertNotFound();
});

test('evidence never reaches the API, notifications or broadcasts', function () {
    $owner = User::factory()->create();
    $organization = organizationWith($owner);
    $monitor = Monitor::factory()->create(['organization_id' => $organization->id]);

    recordResult($monitor, failingResult('evidence-marker'), '2026-11-23 10:00:00');
    recordResult($monitor, failingResult('evidence-marker'), '2026-11-23 10:01:00');
    recordResult($monitor, failingResult('evidence-marker'), '2026-11-23 10:02:00');
    $incident = Incident::query()->where('monitor_id', $monitor->id)->sole();
    expect($incident->evidence)->not->toBeNull();

    $token = apiTokenFor($organization);
    $this->withApiToken($token)->getJson(route('api.v1.incidents.show', $incident))
        ->assertOk()
        ->assertDontSee('evidence-marker')
        ->assertJsonMissingPath('data.evidence');
    $this->withApiToken($token)->getJson(route('api.v1.monitors.checks.index', $monitor))->assertDontSee('evidence-marker');

    $message = MessageFactory::forIncident($incident->fresh(), NotificationEvent::Opened);
    expect(json_encode((array) $message))->not->toContain('evidence-marker');
});
