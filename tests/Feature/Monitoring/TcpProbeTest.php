<?php

use App\Domain\Monitoring\Enums\DispatchStatus;
use App\Domain\Monitoring\Enums\ProbeErrorCode;
use App\Domain\Monitoring\Jobs\ExecuteProbe;
use App\Domain\Monitoring\Models\Monitor;
use App\Domain\Monitoring\Models\MonitorCheck;
use App\Domain\Monitoring\Models\MonitorDispatch;
use App\Domain\Monitoring\Probes\Contracts\TcpConnector;
use App\Domain\Monitoring\Probes\TcpProbe;
use Tests\Support\FakeTcpConnector;

beforeEach(function () {
    $this->connector = new FakeTcpConnector;
    $this->app->instance(TcpConnector::class, $this->connector);
    config(['veyra.probes.tcp_allowed_ports' => [22, 443, 5432]]);
});

test('an accepted connection is a success pinned to the validated address', function () {
    $result = app(TcpProbe::class)->run('example.com', 5432, 5);

    expect($result->success)->toBeTrue()
        ->and($result->errorCode)->toBeNull()
        ->and($result->statusCode)->toBeNull()
        ->and($result->metadata)->toBe(['resolved_ip' => '93.184.215.14'])
        ->and($this->connector->attempts)->toBe([['ip' => '93.184.215.14', 'port' => 5432, 'timeout' => 5]]);
});

test('refused and timed out connections are failed checks', function (ProbeErrorCode $error) {
    $this->connector->result = $error;

    $result = app(TcpProbe::class)->run('example.com', 22, 5);

    expect($result->success)->toBeFalse()
        ->and($result->errorCode)->toBe($error);
})->with([ProbeErrorCode::ConnectionFailed, ProbeErrorCode::Timeout]);

test('forbidden destinations are refused without connecting', function (string $host, array $ips, ProbeErrorCode $error) {
    $this->dns->set($host, $ips);

    $result = app(TcpProbe::class)->run($host, 443, 5);

    expect($result->success)->toBeFalse()
        ->and($result->errorCode)->toBe($error)
        ->and($this->connector->attempts)->toBe([]);
})->with([
    'private answer' => ['db.example.net', ['10.0.0.5'], ProbeErrorCode::ForbiddenDestination],
    'mixed answer (rebinding)' => ['mixed.example.net', ['93.184.215.20', '127.0.0.1'], ProbeErrorCode::ForbiddenDestination],
    'metadata address' => ['169.254.169.254', [], ProbeErrorCode::ForbiddenDestination],
    'IPv6 loopback' => ['[::1]', [], ProbeErrorCode::ForbiddenDestination],
    'IPv4-mapped loopback' => ['::ffff:127.0.0.1', [], ProbeErrorCode::ForbiddenDestination],
    'localhost' => ['localhost', [], ProbeErrorCode::ForbiddenDestination],
    'disguised IP' => ['2130706433', [], ProbeErrorCode::InvalidUrl],
    'unresolvable' => ['missing.example.net', [], ProbeErrorCode::DnsFailure],
]);

test('ports outside the TCP allow-list are refused without connecting', function () {
    $result = app(TcpProbe::class)->run('example.com', 6379, 5);

    expect($result->errorCode)->toBe(ProbeErrorCode::ForbiddenDestination)
        ->and($this->connector->attempts)->toBe([]);
});

test('the endpoint is re-validated at check time, not only when saving', function () {
    $monitor = Monitor::factory()->tcp('api.example.net', 443)->create();
    $this->dns->set('api.example.net', ['93.184.215.30']);
    $dispatch = MonitorDispatch::factory()->create(['monitor_id' => $monitor->id]);

    $this->dns->set('api.example.net', ['192.168.1.10']);
    app()->call([new ExecuteProbe($dispatch->id), 'handle']);

    expect(MonitorCheck::sole())
        ->success->toBeFalse()
        ->error_code->toBe(ProbeErrorCode::ForbiddenDestination)
        ->and($this->connector->attempts)->toBe([]);
});

test('TCP dispatches go through the outbox and record one check', function () {
    $monitor = Monitor::factory()->tcp('example.com', 22)->create();
    $dispatch = MonitorDispatch::factory()->status(DispatchStatus::Dispatched)->create(['monitor_id' => $monitor->id]);

    app()->call([new ExecuteProbe($dispatch->id), 'handle']);
    app()->call([new ExecuteProbe($dispatch->id), 'handle']);

    expect(MonitorCheck::sole())
        ->success->toBeTrue()
        ->dispatch_id->toBe($dispatch->id)
        ->and($dispatch->fresh()->status)->toBe(DispatchStatus::Completed)
        ->and($this->connector->attempts)->toHaveCount(1);
});
