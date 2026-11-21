<?php

use App\Domain\Monitoring\Enums\DispatchStatus;
use App\Domain\Monitoring\Enums\ProbeErrorCode;
use App\Domain\Monitoring\Jobs\ExecuteHttpProbe;
use App\Domain\Monitoring\Models\Monitor;
use App\Domain\Monitoring\Models\MonitorCheck;
use App\Domain\Monitoring\Models\MonitorDispatch;
use Illuminate\Support\Facades\Http;

test('the job records one check and completes the dispatch', function () {
    Http::fake(['*' => Http::response('', 200)]);
    $dispatch = MonitorDispatch::factory()->status(DispatchStatus::Dispatched)->create();

    app()->call([new ExecuteHttpProbe($dispatch->id), 'handle']);

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

    app()->call([new ExecuteHttpProbe($dispatch->id), 'handle']);
    app()->call([new ExecuteHttpProbe($dispatch->id), 'handle']);

    expect(MonitorCheck::count())->toBe(1);
    Http::assertSentCount(1);
});

test('failures are recorded as failed checks, not exceptions', function () {
    Http::fake(['*' => Http::response('', 500)]);
    $dispatch = MonitorDispatch::factory()->create();

    app()->call([new ExecuteHttpProbe($dispatch->id), 'handle']);

    expect(MonitorCheck::sole())
        ->success->toBeFalse()
        ->error_code->toBe(ProbeErrorCode::UnexpectedStatus);
});

test('dispatches of paused or deleted monitors are skipped without probing', function (string $state) {
    Http::fake();
    $monitor = Monitor::factory()->create();
    $state === 'paused' ? $monitor->update(['enabled' => false]) : $monitor->delete();
    $dispatch = MonitorDispatch::factory()->create(['monitor_id' => $monitor->id]);

    app()->call([new ExecuteHttpProbe($dispatch->id), 'handle']);

    expect($dispatch->fresh()->status)->toBe(DispatchStatus::Skipped)
        ->and(MonitorCheck::count())->toBe(0);
    Http::assertNothingSent();
})->with(['paused', 'deleted']);

test('the job is bounded and runs on the probes queue', function () {
    $job = new ExecuteHttpProbe('id');

    expect($job->queue)->toBe('probes')
        ->and($job->tries)->toBe(1)
        ->and($job->timeout)->toBe(45)
        ->and($job->failOnTimeout)->toBeTrue();
});
