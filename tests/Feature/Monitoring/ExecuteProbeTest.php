<?php

use App\Domain\Monitoring\Enums\DispatchStatus;
use App\Domain\Monitoring\Enums\MonitorType;
use App\Domain\Monitoring\Enums\ProbeErrorCode;
use App\Domain\Monitoring\Jobs\ExecuteHttpProbe;
use App\Domain\Monitoring\Jobs\ExecuteProbe;
use App\Domain\Monitoring\Models\Monitor;
use App\Domain\Monitoring\Models\MonitorCheck;
use App\Domain\Monitoring\Models\MonitorDispatch;
use App\Domain\Monitoring\Probes\HttpMonitorProbe;
use App\Domain\Monitoring\Probes\ProbeRegistry;
use App\Domain\Monitoring\Probes\TcpProbe;
use Illuminate\Support\Facades\Http;

test('the job records one check and completes the dispatch', function () {
    Http::fake(['*' => Http::response('', 200)]);
    $dispatch = MonitorDispatch::factory()->status(DispatchStatus::Dispatched)->create();

    app()->call([new ExecuteProbe($dispatch->id), 'handle']);

    $check = MonitorCheck::sole();
    expect($check->dispatch_id)->toBe($dispatch->id)
        ->and($check->monitor_id)->toBe($dispatch->monitor_id)
        ->and($check->success)->toBeTrue()
        ->and($check->status_code)->toBe(200)
        ->and($check->scheduled_for->equalTo($dispatch->scheduled_for))->toBeTrue()
        ->and($dispatch->fresh()->status)->toBe(DispatchStatus::Completed);
});

test('duplicate or replayed jobs record nothing twice', function () {
    Http::fake(['*' => Http::response('', 200)]);
    $dispatch = MonitorDispatch::factory()->status(DispatchStatus::Dispatched)->create();

    app()->call([new ExecuteProbe($dispatch->id), 'handle']);
    app()->call([new ExecuteProbe($dispatch->id), 'handle']);

    expect(MonitorCheck::count())->toBe(1);
    Http::assertSentCount(1);
});

test('failures are recorded as failed checks, not exceptions', function () {
    Http::fake(['*' => Http::response('', 500)]);
    $dispatch = MonitorDispatch::factory()->create();

    app()->call([new ExecuteProbe($dispatch->id), 'handle']);

    expect(MonitorCheck::sole())
        ->success->toBeFalse()
        ->error_code->toBe(ProbeErrorCode::UnexpectedStatus);
});

test('dispatches of paused or deleted monitors are skipped without probing', function (string $state) {
    Http::fake();
    $monitor = Monitor::factory()->create();
    $state === 'paused' ? $monitor->update(['enabled' => false]) : $monitor->delete();
    $dispatch = MonitorDispatch::factory()->create(['monitor_id' => $monitor->id]);

    app()->call([new ExecuteProbe($dispatch->id), 'handle']);

    expect($dispatch->fresh()->status)->toBe(DispatchStatus::Skipped)
        ->and(MonitorCheck::count())->toBe(0);
    Http::assertNothingSent();
})->with(['paused', 'deleted']);

test('the job is bounded and runs on the probes queue', function () {
    $job = new ExecuteProbe('id');

    expect($job->queue)->toBe('probes')
        ->and($job->tries)->toBe(1)
        ->and($job->timeout)->toBe(45)
        ->and($job->failOnTimeout)->toBeTrue();
});

test('jobs queued before the upgrade under the legacy class name still run', function () {
    Http::fake(['*' => Http::response('', 200)]);
    $dispatch = MonitorDispatch::factory()->status(DispatchStatus::Dispatched)->create();

    $legacy = unserialize(serialize(new ExecuteHttpProbe($dispatch->id)));
    app()->call([$legacy, 'handle']);

    expect($legacy)->toBeInstanceOf(ExecuteProbe::class)
        ->and($legacy->queue)->toBe('probes')
        ->and(MonitorCheck::sole()->dispatch_id)->toBe($dispatch->id)
        ->and($dispatch->fresh()->status)->toBe(DispatchStatus::Completed);
});

test('dispatches of a type without a probe are skipped, never recorded as failures', function (MonitorType $type) {
    Http::fake();
    $monitor = Monitor::factory()->ofType($type)->create();
    $dispatch = MonitorDispatch::factory()->create(['monitor_id' => $monitor->id]);

    app()->call([new ExecuteProbe($dispatch->id), 'handle']);

    expect($dispatch->fresh()->status)->toBe(DispatchStatus::Skipped)
        ->and(MonitorCheck::count())->toBe(0);
    Http::assertNothingSent();
})->with([MonitorType::Heartbeat]);

test('a monitor without its configuration is skipped without probing', function (MonitorType $type) {
    Http::fake();
    $monitor = Monitor::factory()->ofType($type)->create();
    $monitor->httpConfig()->delete();
    $monitor->tcpConfig()->delete();
    $dispatch = MonitorDispatch::factory()->create(['monitor_id' => $monitor->id]);

    app()->call([new ExecuteProbe($dispatch->id), 'handle']);

    expect($dispatch->fresh()->status)->toBe(DispatchStatus::Skipped)
        ->and(MonitorCheck::count())->toBe(0);
    Http::assertNothingSent();
})->with([MonitorType::Http, MonitorType::Tcp]);

test('the probe registry resolves the probe of each type', function () {
    expect(app(ProbeRegistry::class)->for(MonitorType::Http))->toBeInstanceOf(HttpMonitorProbe::class)
        ->and(app(ProbeRegistry::class)->for(MonitorType::Tcp))->toBeInstanceOf(TcpProbe::class)
        ->and(app(ProbeRegistry::class)->for(MonitorType::Heartbeat))->toBeNull();
});
