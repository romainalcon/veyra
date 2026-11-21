<?php

use App\Domain\Incidents\Enums\IncidentStatus;
use App\Domain\Incidents\Models\Incident;
use App\Domain\Maintenance\Models\MaintenanceWindow;
use App\Domain\Monitoring\Actions\DetectStaleMonitors;
use App\Domain\Monitoring\Actions\PublishPendingDispatches;
use App\Domain\Monitoring\Actions\ScheduleDueMonitors;
use App\Domain\Monitoring\Enums\DispatchStatus;
use App\Domain\Monitoring\Enums\MonitorStatus;
use App\Domain\Monitoring\Enums\ProbeErrorCode;
use App\Domain\Monitoring\Heartbeats\RecordHeartbeatPing;
use App\Domain\Monitoring\Jobs\ExecuteProbe;
use App\Domain\Monitoring\Jobs\PurgeExpiredHistory;
use App\Domain\Monitoring\Models\Monitor;
use App\Domain\Monitoring\Models\MonitorCheck;
use App\Domain\Monitoring\Models\MonitorDispatch;
use App\Domain\Monitoring\Models\MonitorState;
use App\Domain\System\SchedulerBeatLog;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

const EVAL_TOKEN = 'Zy9xWv8uTs7rQp6oNm5lKj4iHg3fEd2cBa1zYx0w';

/**
 * Record one scheduler beat per minute over [from, to], optionally leaving a hole.
 */
function beatBetween(CarbonImmutable $from, CarbonImmutable $to, ?array $hole = null): void
{
    for ($at = $from->startOfMinute(); $at->lessThanOrEqualTo($to); $at = $at->addMinute()) {
        if ($hole !== null && $at->greaterThanOrEqualTo($hole[0]) && $at->lessThan($hole[1])) {
            continue;
        }

        SchedulerBeatLog::record($at);
    }
}

/**
 * Run the outbox like the scheduler and Horizon would at the current time.
 */
function runOutbox(): void
{
    app(ScheduleDueMonitors::class)->handle(CarbonImmutable::now());
    app(PublishPendingDispatches::class)->handle(CarbonImmutable::now());
}

beforeEach(function () {
    $this->start = CarbonImmutable::parse('2026-11-23 10:00:00');
    $this->travelTo($this->start);
    // Period 1 h, grace 5 min: first deadline at 11:05.
    $this->monitor = Monitor::factory()->heartbeat(EVAL_TOKEN, 3600, 300)->create();
});

test('a missed deadline is recorded when the scheduler ran during the whole window', function () {
    app(RecordHeartbeatPing::class)->handle(EVAL_TOKEN);
    expect(MonitorState::find($this->monitor->id)->status)->toBe(MonitorStatus::Up);

    $this->travelTo($this->start->addSeconds(3900));
    beatBetween($this->start, now());
    runOutbox();

    $miss = MonitorCheck::query()->where('success', false)->sole();

    expect($miss->error_code)->toBe(ProbeErrorCode::HeartbeatMissed)
        ->and($miss->scheduled_for->equalTo($this->start->addSeconds(3900)))->toBeTrue()
        ->and(MonitorState::find($this->monitor->id)->status)->toBe(MonitorStatus::Down)
        ->and(Incident::sole()->status)->toBe(IncidentStatus::Open)
        ->and(Incident::sole()->cause)->toBe('No ping received in time')
        // The next deadline is one period later.
        ->and($this->monitor->fresh()->next_check_at->equalTo($this->start->addSeconds(3900 + 3600)))->toBeTrue();
});

test('a ping after the miss resolves the incident', function () {
    app(RecordHeartbeatPing::class)->handle(EVAL_TOKEN);
    $this->travelTo($this->start->addSeconds(3900));
    beatBetween($this->start, now());
    runOutbox();

    $this->travel(2)->minutes();
    app(RecordHeartbeatPing::class)->handle(EVAL_TOKEN);

    expect(MonitorState::find($this->monitor->id)->status)->toBe(MonitorStatus::Up)
        ->and(Incident::sole()->status)->toBe(IncidentStatus::Resolved);
});

test('a ping inside the window moves the deadline and nothing is evaluated', function () {
    $this->travel(50)->minutes();
    app(RecordHeartbeatPing::class)->handle(EVAL_TOKEN);

    $this->travelTo($this->start->addSeconds(3900));
    beatBetween($this->start, now());
    runOutbox();

    expect(MonitorDispatch::query()->where('trigger', 'scheduled')->count())->toBe(0)
        ->and(MonitorCheck::query()->where('success', false)->count())->toBe(0);
});

test('a ping arriving after the deadline was claimed skips the evaluation', function () {
    $this->travelTo($this->start->addSeconds(3900));
    beatBetween($this->start, now());
    app(ScheduleDueMonitors::class)->handle(CarbonImmutable::now());
    $dispatch = MonitorDispatch::sole();

    $this->travel(5)->seconds();
    app(RecordHeartbeatPing::class)->handle(EVAL_TOKEN);
    app()->call([new ExecuteProbe($dispatch->id), 'handle']);

    expect($dispatch->fresh()->status)->toBe(DispatchStatus::Skipped)
        ->and(MonitorCheck::query()->where('success', false)->count())->toBe(0)
        ->and(MonitorState::find($this->monitor->id)->status)->toBe(MonitorStatus::Up);
});

test('a scheduler gap during the window never records a miss', function (string $gap) {
    $deadline = $this->start->addSeconds(3900);
    $this->travelTo($deadline);

    match ($gap) {
        'middle' => beatBetween($this->start, $deadline, [$this->start->addMinutes(20), $this->start->addMinutes(30)]),
        'start' => beatBetween($this->start->addMinutes(10), $deadline),
        'end' => beatBetween($this->start, $deadline->subMinutes(10)),
        'none' => null,
    };

    runOutbox();

    expect(MonitorDispatch::sole()->status)->toBe(DispatchStatus::Skipped)
        ->and(MonitorCheck::count())->toBe(0)
        ->and(MonitorState::find($this->monitor->id)->status)->toBe(MonitorStatus::Unknown)
        ->and(Incident::count())->toBe(0);
})->with(['middle', 'start', 'end', 'none']);

test('short scheduler hiccups are tolerated', function () {
    $deadline = $this->start->addSeconds(3900);
    $this->travelTo($deadline);
    beatBetween($this->start, $deadline, [$this->start->addMinutes(20), $this->start->addMinutes(22)]);

    runOutbox();

    expect(MonitorCheck::sole()->error_code)->toBe(ProbeErrorCode::HeartbeatMissed);
});

test('a monitor that never pinged is reported once its first window has elapsed', function () {
    $this->travelTo($this->start->addSeconds(3900));
    beatBetween($this->start, now());
    runOutbox();

    expect(MonitorState::find($this->monitor->id)->status)->toBe(MonitorStatus::Down);
});

test('a missed deadline during maintenance never opens an incident', function () {
    app(RecordHeartbeatPing::class)->handle(EVAL_TOKEN);
    $window = MaintenanceWindow::factory()->create([
        'organization_id' => $this->monitor->organization_id,
        'starts_at' => $this->start->addMinutes(30),
        'ends_at' => $this->start->addHours(3),
    ]);
    $window->monitors()->attach($this->monitor->id);

    $this->travelTo($this->start->addSeconds(3900));
    beatBetween($this->start, now());
    runOutbox();

    expect(MonitorCheck::query()->where('success', false)->sole()->in_maintenance)->toBeTrue()
        ->and(MonitorState::find($this->monitor->id)->status)->toBe(MonitorStatus::Maintenance)
        ->and(Incident::count())->toBe(0);
});

test('stale detection waits for the grace time of heartbeats', function () {
    $monitor = Monitor::factory()->heartbeat('Qw1eRt2yUi3oPa4sDf5gHj6kLz7xCv8bNm9qWe0r', 600, 1800)->create();
    app(RecordHeartbeatPing::class)->handle('Qw1eRt2yUi3oPa4sDf5gHj6kLz7xCv8bNm9qWe0r');
    expect(MonitorState::find($monitor->id)->status)->toBe(MonitorStatus::Up);

    // 2 × period + stale grace (120 s) has passed, but not the heartbeat grace.
    $this->travel(2 * 600 + 121)->seconds();
    app(DetectStaleMonitors::class)->handle(CarbonImmutable::now());
    expect(MonitorState::find($monitor->id)->status)->toBe(MonitorStatus::Up);

    $this->travel(1800)->seconds();
    app(DetectStaleMonitors::class)->handle(CarbonImmutable::now());
    expect(MonitorState::find($monitor->id)->status)->toBe(MonitorStatus::Unknown);
});

test('the beat log is idempotent per minute and purged after its retention', function () {
    SchedulerBeatLog::record($this->start->addSeconds(5));
    SchedulerBeatLog::record($this->start->addSeconds(50));
    SchedulerBeatLog::record($this->start->subDays(SchedulerBeatLog::RETENTION_DAYS + 1));

    expect(DB::table('scheduler_beats')->count())->toBe(2);

    (new PurgeExpiredHistory)->handle();

    expect(DB::table('scheduler_beats')->pluck('beat_at')->all())->toBe(['2026-11-23 10:00:00']);
});

test('the scheduler heartbeat command records a beat', function () {
    $this->artisan('veyra:heartbeat')->assertSuccessful();

    expect(SchedulerBeatLog::covers(now(), now()))->toBeTrue();
});
