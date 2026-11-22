<?php

use App\Domain\Monitoring\Dns\DnsNameservers;
use App\Domain\Monitoring\Dns\DnsResponse;
use App\Domain\Monitoring\Enums\DispatchStatus;
use App\Domain\Monitoring\Enums\DnsMatchMode;
use App\Domain\Monitoring\Enums\DnsRecordType;
use App\Domain\Monitoring\Enums\DnsResolverChoice;
use App\Domain\Monitoring\Enums\MonitorType;
use App\Domain\Monitoring\Enums\ProbeErrorCode;
use App\Domain\Monitoring\Jobs\ExecuteProbe;
use App\Domain\Monitoring\Models\Monitor;
use App\Domain\Monitoring\Models\MonitorCheck;
use App\Domain\Monitoring\Models\MonitorDispatch;
use App\Domain\Monitoring\Probes\DnsProbe;
use App\Domain\Monitoring\Probes\ProbeRegistry;
use App\Domain\Monitoring\State\StateThresholds;

function dnsProbe(string $name = 'example.com', DnsRecordType $type = DnsRecordType::A, DnsResolverChoice $resolver = DnsResolverChoice::Cloudflare, array $expected = [], DnsMatchMode $mode = DnsMatchMode::Contains, int $timeout = 5)
{
    return app(DnsProbe::class)->run($name, $type, $resolver, $expected, $mode, $timeout);
}

function runDispatch(Monitor $monitor, string $scheduledFor = '2026-11-22 09:00:00'): MonitorDispatch
{
    $dispatch = MonitorDispatch::factory()->status(DispatchStatus::Dispatched)->create(['monitor_id' => $monitor->id, 'scheduled_for' => $scheduledFor]);
    app()->call([new ExecuteProbe($dispatch->id), 'handle']);

    return $dispatch->fresh();
}

test('the registry resolves the DNS probe', function () {
    expect(app(ProbeRegistry::class)->for(MonitorType::Dns))->toBeInstanceOf(DnsProbe::class);
});

test('matching answers are a success with the resolver and answer count', function () {
    $this->dnsQueries->answer('example.com', DnsRecordType::A, ['93.184.215.14', '93.184.215.15']);

    $result = dnsProbe(expected: ['93.184.215.15']);

    expect($result->success)->toBeTrue()
        ->and($result->errorCode)->toBeNull()
        ->and($result->metadata)->toBe(['resolver' => 'cloudflare', 'rcode' => 'NOERROR', 'answers' => 2])
        ->and($result->dnsAnswers)->toBe(['93.184.215.14', '93.184.215.15'])
        ->and($this->dnsQueries->queries[0]['nameserver'])->toBe('1.1.1.1');
});

test('resolution failures and mismatched answers are distinct results', function (array $setup, array $expected, ProbeErrorCode $code, ?array $answers) {
    [$answerList, $rcode] = $setup;
    $answerList === null
        ? $this->dnsQueries->fail('example.com', DnsRecordType::A, timedOut: $rcode === 1)
        : $this->dnsQueries->answer('example.com', DnsRecordType::A, $answerList, $rcode);

    $result = dnsProbe(expected: $expected);

    expect($result->success)->toBeFalse()
        ->and($result->errorCode)->toBe($code)
        ->and($result->dnsAnswers)->toBe($answers);
})->with([
    'mismatch' => [[['93.184.215.99'], 0], ['93.184.215.14'], ProbeErrorCode::DnsMismatch, ['93.184.215.99']],
    'no record of this type' => [[[], 0], [], ProbeErrorCode::DnsNoAnswer, []],
    'name does not exist' => [[[], DnsResponse::NXDOMAIN], [], ProbeErrorCode::DnsNxDomain, []],
    'server failure' => [[[], DnsResponse::SERVFAIL], [], ProbeErrorCode::DnsFailure, null],
    'unreachable' => [[null, 0], [], ProbeErrorCode::DnsFailure, null],
    'timeout' => [[null, 1], [], ProbeErrorCode::Timeout, null],
]);

test('a failing server falls back to the next one of the same resolver', function () {
    $this->dnsQueries
        ->answer('example.com', DnsRecordType::Mx, [], DnsResponse::SERVFAIL, '8.8.8.8')
        ->answer('example.com', DnsRecordType::Mx, ['10 mail.example.com'], nameserver: '8.8.4.4');

    $result = dnsProbe(type: DnsRecordType::Mx, resolver: DnsResolverChoice::Google, expected: ['mail.example.com'], mode: DnsMatchMode::Exact);

    expect($result->success)->toBeTrue()
        ->and(array_column($this->dnsQueries->queries, 'nameserver'))->toBe(['8.8.8.8', '8.8.4.4']);
});

test('the query is bounded by the monitor timeout across servers', function () {
    dnsProbe(timeout: 3);

    expect($this->dnsQueries->queries)->toHaveCount(2)
        ->and($this->dnsQueries->queries[0]['timeout'])->toBeLessThanOrEqual(3.0)
        ->and($this->dnsQueries->queries[1]['timeout'])->toBeLessThanOrEqual($this->dnsQueries->queries[0]['timeout']);
});

test('record names are checked before any query', function (string $name, ProbeErrorCode $code) {
    $result = dnsProbe(name: $name);

    expect($result->errorCode)->toBe($code)
        ->and($this->dnsQueries->queries)->toBe([]);
})->with([
    'IP literal' => ['127.0.0.1', ProbeErrorCode::InvalidUrl],
    'single label' => ['intranet', ProbeErrorCode::InvalidUrl],
    'localhost' => ['db.localhost', ProbeErrorCode::ForbiddenDestination],
    'internal suffix' => ['vault.internal', ProbeErrorCode::ForbiddenDestination],
    'reverse zone' => ['1.0.0.127.in-addr.arpa', ProbeErrorCode::ForbiddenDestination],
]);

test('underscore labels and international names are queried in ASCII', function () {
    $this->dnsQueries->answer('_dmarc.example.com', DnsRecordType::Txt, ['v=DMARC1; p=reject']);
    $this->dnsQueries->answer('xn--bcher-kva.example', DnsRecordType::A, ['93.184.215.14']);

    expect(dnsProbe(name: '_DMARC.example.com.', type: DnsRecordType::Txt, expected: ['p=reject'])->success)->toBeTrue()
        ->and(dnsProbe(name: 'bücher.example')->success)->toBeTrue();
});

test('the system resolver uses the worker name servers and hides non-public addresses', function () {
    $resolvConf = tempnam(sys_get_temp_dir(), 'resolv');
    file_put_contents($resolvConf, "# comment\nnameserver 10.0.0.2\nnameserver fe80::1%eth0\nnameserver 10.0.0.3\nsearch corp\n");
    $this->app->instance(DnsNameservers::class, new DnsNameservers($resolvConf));
    $this->dnsQueries->answer('example.com', DnsRecordType::A, ['10.1.2.3', '93.184.215.14', '10.1.2.4'], nameserver: '10.0.0.2');

    $result = dnsProbe(resolver: DnsResolverChoice::System, expected: ['10.1.2.3']);

    expect($result->success)->toBeTrue()
        ->and($result->dnsAnswers)->toBe([DnsProbe::REDACTED_ADDRESS, '93.184.215.14'])
        ->and(app(DnsNameservers::class)->system())->toBe(['10.0.0.2', '10.0.0.3']);

    unlink($resolvConf);
});

test('without listed name servers the system resolver is the local one', function () {
    expect((new DnsNameservers('/nonexistent/resolv.conf'))->system())->toBe(['127.0.0.1']);
});

test('the job records the check and keeps the newest answers only', function () {
    $monitor = Monitor::factory()->dns(['expected_values' => ['93.184.215.14']])->create();
    $this->dnsQueries->answer('example.com', DnsRecordType::A, ['93.184.215.14']);

    runDispatch($monitor, '2026-11-22 09:01:00');

    $this->dnsQueries->answer('example.com', DnsRecordType::A, ['93.184.215.99']);
    runDispatch($monitor, '2026-11-22 09:00:00'); // late result for an older slot

    $config = $monitor->dnsConfig()->sole();

    expect(MonitorCheck::orderBy('scheduled_for')->pluck('error_code')->all())->toBe([ProbeErrorCode::DnsMismatch, null])
        ->and($config->last_answers)->toBe(['93.184.215.14'])
        ->and($config->last_resolved_at->toDateTimeString())->toBe('2026-11-22 09:01:00');
});

test('a resolver disabled by the operator skips the dispatch instead of failing', function () {
    config(['veyra.dns.resolvers' => ['system']]);
    $monitor = Monitor::factory()->dns()->create();

    expect(runDispatch($monitor)->status)->toBe(DispatchStatus::Skipped)
        ->and(MonitorCheck::count())->toBe(0)
        ->and($this->dnsQueries->queries)->toBe([]);
});

test('a DNS monitor without configuration is skipped', function () {
    $monitor = Monitor::factory()->dns()->create();
    $monitor->dnsConfig()->delete();

    expect(runDispatch($monitor)->status)->toBe(DispatchStatus::Skipped);
});

test('DNS monitors have their own confirmation thresholds', function () {
    config([
        'veyra.monitoring.down_after_failures' => 3,
        'veyra.monitoring.dns_down_after_failures' => 4,
        'veyra.monitoring.dns_up_after_successes' => 1,
    ]);

    expect(StateThresholds::forType(MonitorType::Dns))->downAfter->toBe(4)->upAfter->toBe(1)
        ->and(StateThresholds::forType(MonitorType::Http))->downAfter->toBe(3);
});
