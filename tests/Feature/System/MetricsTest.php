<?php

use App\Domain\Monitoring\Enums\DispatchStatus;
use App\Domain\Monitoring\Enums\MonitorType;
use App\Domain\Monitoring\Models\Monitor;
use App\Domain\Monitoring\Models\MonitorDispatch;
use App\Domain\Notifications\Enums\ChannelType;
use App\Domain\Notifications\Enums\NotificationEvent;
use App\Domain\System\CheckResult;
use App\Domain\System\ConfigurationDoctor;
use App\Domain\System\Metrics\MetricsCollector;
use App\Domain\System\Metrics\MetricsRecorder;
use App\Models\User;
use Illuminate\Redis\RedisManager;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Redis;

const METRICS_TOKEN = 'metrics-test-token-0123456789abcdef';

beforeEach(function () {
    Redis::connection()->del(MetricsRecorder::KEY);
    config(['veyra.metrics' => ['enabled' => true, 'token' => METRICS_TOKEN, 'allowed_ips' => []]]);
});

afterEach(function () {
    rescue(fn () => Redis::connection()->del(MetricsRecorder::KEY), report: false);
});

function scrape(): string
{
    return test()->get('/metrics', ['Authorization' => 'Bearer '.METRICS_TOKEN])->assertOk()->getContent();
}

/**
 * @return list<array{name: string, labels: array<string, string>, value: string}>
 */
function samples(string $body): array
{
    $samples = [];

    foreach (explode("\n", trim($body)) as $line) {
        if ($line === '' || str_starts_with($line, '#')) {
            continue;
        }

        expect(preg_match('/^([a-z_]+)(?:\{(.*)\})? (\S+)$/', $line, $matches))->toBe(1, "Malformed sample: {$line}");
        preg_match_all('/([a-z_]+)="((?:[^"\\\\]|\\\\.)*)"/', $matches[2], $pairs, PREG_SET_ORDER);

        $samples[] = [
            'name' => $matches[1],
            'labels' => collect($pairs)->mapWithKeys(fn (array $pair) => [$pair[1] => $pair[2]])->all(),
            'value' => $matches[3],
        ];
    }

    return $samples;
}

function sampleValue(string $body, string $name, array $labels = []): ?string
{
    foreach (samples($body) as $sample) {
        if ($sample['name'] === $name && $sample['labels'] == $labels) {
            return $sample['value'];
        }
    }

    return null;
}

test('the endpoint does not exist while metrics are disabled', function () {
    config(['veyra.metrics.enabled' => false]);

    $this->get('/metrics', ['Authorization' => 'Bearer '.METRICS_TOKEN])->assertNotFound();
});

test('enabled without a token or an allow-list, every scrape is refused', function () {
    config(['veyra.metrics.token' => null, 'veyra.metrics.allowed_ips' => []]);

    $this->get('/metrics')->assertNotFound();
});

test('the bearer token is required when configured', function () {
    $this->get('/metrics')->assertUnauthorized()->assertHeader('WWW-Authenticate', 'Bearer');
    $this->get('/metrics', ['Authorization' => 'Bearer wrong'])->assertUnauthorized();

    $this->get('/metrics', ['Authorization' => 'Bearer '.METRICS_TOKEN])
        ->assertOk()
        ->assertHeader('Content-Type', 'text/plain; version=0.0.4; charset=utf-8')
        ->assertHeader('Cache-Control', 'no-store, private');
});

test('the IP allow-list accepts addresses and CIDR ranges, IPv4 and IPv6', function () {
    config(['veyra.metrics.token' => null, 'veyra.metrics.allowed_ips' => ['10.0.0.0/8', '2001:db8::/32', '192.0.2.7']]);

    $this->withServerVariables(['REMOTE_ADDR' => '10.20.30.40'])->get('/metrics')->assertOk();
    $this->withServerVariables(['REMOTE_ADDR' => '2001:db8::1'])->get('/metrics')->assertOk();
    $this->withServerVariables(['REMOTE_ADDR' => '192.0.2.7'])->get('/metrics')->assertOk();
    $this->withServerVariables(['REMOTE_ADDR' => '192.0.2.8'])->get('/metrics')->assertForbidden();
    $this->withServerVariables(['REMOTE_ADDR' => '2001:db9::1'])->get('/metrics')->assertForbidden();
});

test('with a token and an allow-list both are required', function () {
    config(['veyra.metrics.allowed_ips' => ['10.0.0.0/8']]);

    $this->withServerVariables(['REMOTE_ADDR' => '10.0.0.5'])->get('/metrics')->assertUnauthorized();
    $this->withServerVariables(['REMOTE_ADDR' => '172.16.0.5'])->get('/metrics', ['Authorization' => 'Bearer '.METRICS_TOKEN])->assertForbidden();
    $this->withServerVariables(['REMOTE_ADDR' => '10.0.0.5'])->get('/metrics', ['Authorization' => 'Bearer '.METRICS_TOKEN])->assertOk();
});

test('metric names are a stable contract', function () {
    $body = scrape();

    preg_match_all('/^# TYPE (\S+) (\S+)$/m', $body, $types, PREG_SET_ORDER);

    expect(collect($types)->mapWithKeys(fn (array $type) => [$type[1] => $type[2]])->all())->toBe([
        'veyra_info' => 'gauge',
        'veyra_metrics_collector_up' => 'gauge',
        'veyra_queue_jobs' => 'gauge',
        'veyra_outbox_dispatches' => 'gauge',
        'veyra_outbox_oldest_age_seconds' => 'gauge',
        'veyra_outbox_failed_dispatches_last_hour' => 'gauge',
        'veyra_scheduler_heartbeat_age_seconds' => 'gauge',
        'veyra_horizon_master_supervisors' => 'gauge',
        'veyra_horizon_recent_failed_jobs' => 'gauge',
        'veyra_monitors' => 'gauge',
        'veyra_probe_duration_seconds' => 'histogram',
        'veyra_notification_deliveries_total' => 'counter',
    ])->and(array_keys(collect($types)->keyBy(1)->all()))->toBe(MetricsCollector::FAMILIES);

    expect(sampleValue($body, 'veyra_metrics_collector_up', ['collector' => 'outbox']))->toBe('1')
        ->and(sampleValue($body, 'veyra_queue_jobs', ['queue' => 'probes', 'state' => 'pending']))->not->toBeNull()
        ->and(sampleValue($body, 'veyra_monitors', ['state' => 'down']))->toBe('0');
});

test('no tenant data appears in names, labels or values', function () {
    Mail::fake();
    $user = User::factory()->create(['email' => 'secret.owner@tenant-leak.test']);
    $organization = organizationWith($user, attributes: ['name' => 'Leaky Tenant Corp', 'slug' => 'leaky-tenant-corp']);

    $this->actingAs($user)->post(route('organizations.notification-channels.store', $organization), [
        'type' => 'email', 'name' => 'Leaky on-call', 'recipients' => 'oncall@tenant-leak.test', 'is_default' => '1',
    ])->assertSessionHasNoErrors();
    $this->actingAs($user)->post(route('organizations.monitors.store', $organization), [
        'name' => 'Leaky private API', 'url' => 'https://example.com/leaky-secret-path?token=abc', 'method' => 'GET', 'interval' => 60,
        'timeout' => 10, 'expected_statuses' => '200-299', 'follow_redirects' => '1',
    ])->assertSessionHasNoErrors();
    $monitor = Monitor::sole();

    Http::fakeSequence('example.com/*')->push('', 200)->push('', 503)->push('', 503)->push('', 503);

    foreach (range(1, 4) as $cycle) {
        $this->artisan('veyra:monitors:dispatch')->assertSuccessful();
        $this->travel(60)->seconds();
    }

    $body = scrape();

    foreach (['Leaky', 'leaky', 'tenant-leak', $organization->id, $monitor->id, $user->id, 'example.com', 'token=abc'] as $needle) {
        expect($body)->not->toContain($needle);
    }

    $allowedLabels = ['version', 'collector', 'queue', 'state', 'status', 'type', 'result', 'le', 'channel', 'event'];

    foreach (samples($body) as $sample) {
        expect(array_diff(array_keys($sample['labels']), $allowedLabels))->toBe([], "Unexpected label on {$sample['name']}");
    }

    expect(sampleValue($body, 'veyra_probe_duration_seconds_count', ['type' => 'http', 'result' => 'success']))->toBe('1')
        ->and(sampleValue($body, 'veyra_probe_duration_seconds_count', ['type' => 'http', 'result' => 'failure']))->toBe('3')
        ->and(sampleValue($body, 'veyra_probe_duration_seconds_bucket', ['type' => 'http', 'result' => 'failure', 'le' => '+Inf']))->toBe('3')
        ->and(sampleValue($body, 'veyra_notification_deliveries_total', ['channel' => 'email', 'event' => 'opened', 'result' => 'sent']))->toBe('1')
        ->and(sampleValue($body, 'veyra_monitors', ['state' => 'down']))->toBe('1');
});

test('histogram buckets are cumulative and heartbeats are not timed', function () {
    $recorder = app(MetricsRecorder::class);
    $recorder->recordProbe(MonitorType::Tcp, true, 40);
    $recorder->recordProbe(MonitorType::Tcp, true, 300);
    $recorder->recordProbe(MonitorType::Tcp, true, 45_000);
    $recorder->recordProbe(MonitorType::Heartbeat, false, 0);
    $recorder->recordProbe(MonitorType::Dns, true, null);

    $body = scrape();
    $tcp = ['type' => 'tcp', 'result' => 'success'];

    expect(sampleValue($body, 'veyra_probe_duration_seconds_bucket', [...$tcp, 'le' => '0.05']))->toBe('1')
        ->and(sampleValue($body, 'veyra_probe_duration_seconds_bucket', [...$tcp, 'le' => '0.25']))->toBe('1')
        ->and(sampleValue($body, 'veyra_probe_duration_seconds_bucket', [...$tcp, 'le' => '0.5']))->toBe('2')
        ->and(sampleValue($body, 'veyra_probe_duration_seconds_bucket', [...$tcp, 'le' => '30']))->toBe('2')
        ->and(sampleValue($body, 'veyra_probe_duration_seconds_bucket', [...$tcp, 'le' => '+Inf']))->toBe('3')
        ->and(sampleValue($body, 'veyra_probe_duration_seconds_sum', $tcp))->toBe('45.34')
        ->and($body)->not->toContain('type="heartbeat"')
        ->and($body)->not->toContain('type="dns"');
});

test('nothing is recorded while metrics are disabled', function () {
    config(['veyra.metrics.enabled' => false]);

    app(MetricsRecorder::class)->recordProbe(MonitorType::Http, true, 120);
    app(MetricsRecorder::class)->recordDelivery(ChannelType::Slack, NotificationEvent::Opened, sent: false);

    expect(Redis::connection()->exists(MetricsRecorder::KEY))->toBe(0);
});

test('outbox backlog, age and failures are reported', function () {
    $this->travelTo('2026-12-01 09:00:00');
    $monitor = Monitor::factory()->create();

    MonitorDispatch::factory()->status(DispatchStatus::Pending)->create(['monitor_id' => $monitor->id, 'scheduled_for' => '2026-12-01 08:55:00']);
    MonitorDispatch::factory()->status(DispatchStatus::Pending)->create(['monitor_id' => $monitor->id, 'scheduled_for' => '2026-12-01 08:58:00']);
    MonitorDispatch::factory()->status(DispatchStatus::Dispatched)->create(['monitor_id' => $monitor->id, 'scheduled_for' => '2026-12-01 08:59:00', 'dispatched_at' => '2026-12-01 08:59:30']);
    MonitorDispatch::factory()->status(DispatchStatus::Failed)->create(['monitor_id' => $monitor->id, 'scheduled_for' => '2026-12-01 08:30:00', 'completed_at' => '2026-12-01 08:40:00']);
    MonitorDispatch::factory()->status(DispatchStatus::Failed)->create(['monitor_id' => $monitor->id, 'scheduled_for' => '2026-12-01 07:00:00', 'completed_at' => '2026-12-01 07:10:00']);

    $body = scrape();

    expect(sampleValue($body, 'veyra_outbox_dispatches', ['status' => 'pending']))->toBe('2')
        ->and(sampleValue($body, 'veyra_outbox_dispatches', ['status' => 'dispatched']))->toBe('1')
        ->and(sampleValue($body, 'veyra_outbox_oldest_age_seconds', ['status' => 'pending']))->toBe('300')
        ->and(sampleValue($body, 'veyra_outbox_oldest_age_seconds', ['status' => 'dispatched']))->toBe('30')
        ->and(sampleValue($body, 'veyra_outbox_failed_dispatches_last_hour'))->toBe('1');
});

test('the scheduler heartbeat age is infinite until a beat is recorded', function () {
    expect(sampleValue(scrape(), 'veyra_scheduler_heartbeat_age_seconds'))->toBe('+Inf');

    $this->artisan('veyra:heartbeat')->assertSuccessful();
    $this->travel(42)->seconds();

    expect(sampleValue(scrape(), 'veyra_scheduler_heartbeat_age_seconds'))->toBe('42');
});

test('a Redis outage never fails a probe and still serves the MariaDB metrics', function () {
    $unreachable = config('database.redis');
    $unreachable['default'] = [...$unreachable['default'], 'port' => 1, 'max_retries' => 0];
    app()->instance('redis', new RedisManager(app(), (string) config('database.redis.client'), $unreachable));
    Redis::clearResolvedInstances();

    app(MetricsRecorder::class)->recordProbe(MonitorType::Http, true, 120);

    $body = MetricsCollector::render(app(MetricsCollector::class)->collect());

    expect(sampleValue($body, 'veyra_metrics_collector_up', ['collector' => 'counters']))->toBe('0')
        ->and(sampleValue($body, 'veyra_metrics_collector_up', ['collector' => 'outbox']))->toBe('1')
        ->and(sampleValue($body, 'veyra_outbox_dispatches', ['status' => 'pending']))->toBe('0');

    app()->forgetInstance('redis');
    Redis::clearResolvedInstances();
});

test('the doctor flags an unusable metrics configuration', function (array $metrics, string $status) {
    config(['veyra.metrics' => $metrics]);

    $result = collect(app(ConfigurationDoctor::class)->run())->firstWhere('name', 'metrics');

    expect($result->status)->toBe($status);
})->with([
    'disabled' => [['enabled' => false, 'token' => null, 'allowed_ips' => []], CheckResult::OK],
    'no restriction' => [['enabled' => true, 'token' => null, 'allowed_ips' => []], CheckResult::FAIL],
    'short token' => [['enabled' => true, 'token' => 'short', 'allowed_ips' => []], CheckResult::FAIL],
    'invalid range' => [['enabled' => true, 'token' => null, 'allowed_ips' => ['10.0.0.0/33']], CheckResult::FAIL],
    'hostname' => [['enabled' => true, 'token' => null, 'allowed_ips' => ['prometheus.internal']], CheckResult::FAIL],
    'token and ranges' => [['enabled' => true, 'token' => METRICS_TOKEN, 'allowed_ips' => ['10.0.0.0/8', '2001:db8::/32']], CheckResult::OK],
]);
