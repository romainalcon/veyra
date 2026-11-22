<?php

use App\Domain\Maintenance\Actions\ApplyMaintenanceTransitions;
use App\Domain\Maintenance\Actions\CancelMaintenanceWindow;
use App\Domain\Maintenance\Actions\DeleteMaintenanceSeries;
use App\Domain\Maintenance\Actions\MaterializeMaintenanceSeries;
use App\Domain\Maintenance\Actions\SaveMaintenanceSeries;
use App\Domain\Maintenance\Actions\SaveMaintenanceWindow;
use App\Domain\Maintenance\DTOs\MaintenanceSeriesData;
use App\Domain\Maintenance\DTOs\MaintenanceWindowData;
use App\Domain\Maintenance\Enums\MaintenanceFrequency;
use App\Domain\Maintenance\Events\MaintenanceWindowScheduled;
use App\Domain\Maintenance\Models\MaintenanceSeries;
use App\Domain\Maintenance\Models\MaintenanceWindow;
use App\Domain\Maintenance\Services\MaintenanceSchedule;
use App\Domain\Monitoring\Enums\MonitorStatus;
use App\Domain\Monitoring\Models\Monitor;
use App\Domain\Monitoring\Models\MonitorState;
use App\Domain\Organizations\Actions\UpdateOrganization;
use App\Domain\Organizations\DTOs\OrganizationData;
use App\Domain\Organizations\Models\Organization;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Validation\ValidationException;

beforeEach(function () {
    // Sunday 22 November 2026, 12:00 UTC (13:00 in Paris).
    $this->travelTo('2026-11-22 12:00:00');
    $this->organization = Organization::factory()->create(['timezone' => 'Europe/Paris']);
    $this->monitor = Monitor::factory()->create(['organization_id' => $this->organization->id]);
});

function seriesData(array $monitorIds, array $overrides = []): MaintenanceSeriesData
{
    $values = [
        'title' => 'Nightly backup',
        'description' => null,
        'frequency' => MaintenanceFrequency::Daily,
        'weekdays' => [],
        'monthDay' => null,
        'startTime' => '03:00',
        'durationMinutes' => 60,
        'startsOn' => '2026-11-22',
        'endsOn' => null,
        'monitorIds' => $monitorIds,
        ...$overrides,
    ];

    return new MaintenanceSeriesData(...$values);
}

/**
 * @return list<string> UTC starts of the series' occurrences
 */
function occurrenceStarts(MaintenanceSeries $series, bool $includeCancelled = false): array
{
    return $series->occurrences()
        ->when(! $includeCancelled, fn ($query) => $query->whereNull('cancelled_at'))
        ->orderBy('starts_at')
        ->get()
        ->map(fn (MaintenanceWindow $window): string => $window->starts_at->toDateTimeString())
        ->all();
}

test('saving a series materializes the occurrences of the horizon in the organization time zone', function () {
    $series = app(SaveMaintenanceSeries::class)->handle($this->organization, seriesData([$this->monitor->id]));

    $starts = occurrenceStarts($series);

    // 03:00 in Paris (CET) = 02:00 UTC; today's 03:00 is already past, so the first is tomorrow.
    expect($starts)->toHaveCount(MaterializeMaintenanceSeries::HORIZON_DAYS)
        ->and($starts[0])->toBe('2026-11-23 02:00:00')
        ->and(end($starts))->toBe('2026-12-06 02:00:00');

    $window = $series->occurrences()->orderBy('starts_at')->first();
    expect($window)
        ->organization_id->toBe($this->organization->id)
        ->title->toBe('Nightly backup')
        ->and($window->occurrence_date->toDateString())->toBe('2026-11-23')
        ->and($window->ends_at->toDateTimeString())->toBe('2026-11-23 03:00:00')
        ->and($window->monitors()->pluck('monitors.id')->all())->toBe([$this->monitor->id]);
});

test('occurrences are ordinary windows: monitors enter and leave maintenance on time', function () {
    app(SaveMaintenanceSeries::class)->handle($this->organization, seriesData([$this->monitor->id]));
    $schedule = app(MaintenanceSchedule::class);

    expect($schedule->covers($this->monitor->id, CarbonImmutable::parse('2026-11-23 02:30:00')))->toBeTrue()
        ->and($schedule->covers($this->monitor->id, CarbonImmutable::parse('2026-11-23 03:00:00')))->toBeFalse();

    MonitorState::whereKey($this->monitor->id)->update(['status' => MonitorStatus::Up->value]);
    app(ApplyMaintenanceTransitions::class)->handle(CarbonImmutable::parse('2026-11-23 02:00:00'));

    expect(MonitorState::find($this->monitor->id)->status)->toBe(MonitorStatus::Maintenance);
});

test('generation is idempotent and never duplicates an occurrence', function () {
    $series = app(SaveMaintenanceSeries::class)->handle($this->organization, seriesData([$this->monitor->id]));
    $materialize = app(MaterializeMaintenanceSeries::class);

    expect($materialize->handle($series))->toBe(0)
        ->and($materialize->handleAll())->toBe(0);

    $this->travel(1)->days();

    expect($materialize->handleAll())->toBe(1)
        ->and($materialize->handleAll())->toBe(0)
        ->and($series->occurrences()->count())->toBe(MaterializeMaintenanceSeries::HORIZON_DAYS + 1)
        ->and(DB::table('maintenance_window_monitors')->count())->toBe(MaterializeMaintenanceSeries::HORIZON_DAYS + 1);
});

test('an occurrence inserted by a concurrent worker is kept, not duplicated', function () {
    $series = MaintenanceSeries::factory()->create([
        'organization_id' => $this->organization->id,
        'start_time' => '03:00:00',
    ]);
    $series->monitors()->attach($this->monitor->id);

    $concurrent = MaintenanceWindow::factory()->create([
        'organization_id' => $this->organization->id,
        'series_id' => $series->id,
        'occurrence_date' => '2026-11-24',
        'starts_at' => '2026-11-24 02:00:00',
        'ends_at' => '2026-11-24 03:00:00',
    ]);

    $created = app(MaterializeMaintenanceSeries::class)->handle($series);

    expect($created)->toBe(MaterializeMaintenanceSeries::HORIZON_DAYS - 1)
        ->and($series->occurrences()->where('occurrence_date', '2026-11-24')->pluck('id')->all())->toBe([$concurrent->id]);
});

test('editing a series never alters past or running occurrences and keeps skips', function () {
    $series = app(SaveMaintenanceSeries::class)->handle($this->organization, seriesData([$this->monitor->id]));

    // Tomorrow's occurrence is skipped.
    $tomorrow = $series->occurrences()->where('occurrence_date', '2026-11-23')->sole();
    app(CancelMaintenanceWindow::class)->handle($tomorrow);

    // Two days later, the occurrence of 25 November is running.
    $this->travelTo('2026-11-25 02:30:00');
    $running = $series->occurrences()->where('occurrence_date', '2026-11-25')->sole();
    $past = $series->occurrences()->where('occurrence_date', '2026-11-24')->sole();

    app(SaveMaintenanceSeries::class)->handle($this->organization, seriesData([$this->monitor->id], [
        'startTime' => '05:00',
        'durationMinutes' => 30,
    ]), series: $series);

    expect($past->fresh())->not->toBeNull()
        ->and($past->fresh()->starts_at->toDateTimeString())->toBe('2026-11-24 02:00:00')
        ->and($running->fresh()->ends_at->toDateTimeString())->toBe('2026-11-25 03:00:00')
        ->and($tomorrow->fresh()->cancelled_at)->not->toBeNull();

    $next = $series->occurrences()->where('occurrence_date', '2026-11-26')->sole();
    expect($next->starts_at->toDateTimeString())->toBe('2026-11-26 04:00:00')
        ->and($next->ends_at->toDateTimeString())->toBe('2026-11-26 04:30:00');
});

test('removing a monitor from a series only affects future occurrences', function () {
    $other = Monitor::factory()->create(['organization_id' => $this->organization->id]);
    $series = app(SaveMaintenanceSeries::class)->handle($this->organization, seriesData([$this->monitor->id, $other->id]));

    $this->travelTo('2026-11-23 02:30:00');
    $running = $series->occurrences()->where('occurrence_date', '2026-11-23')->sole();

    app(SaveMaintenanceSeries::class)->handle($this->organization, seriesData([$this->monitor->id]), series: $series);

    expect($running->monitors()->count())->toBe(2)
        ->and($series->occurrences()->where('occurrence_date', '2026-11-24')->sole()->monitors()->pluck('monitors.id')->all())
        ->toBe([$this->monitor->id]);
});

test('deleting a series removes future occurrences and keeps the history', function () {
    $series = app(SaveMaintenanceSeries::class)->handle($this->organization, seriesData([$this->monitor->id]));

    $this->travelTo('2026-11-25 02:30:00');
    $kept = $series->occurrences()->whereIn('occurrence_date', ['2026-11-23', '2026-11-24', '2026-11-25'])->pluck('id')->all();

    app(DeleteMaintenanceSeries::class)->handle($series);

    expect(MaintenanceSeries::find($series->id))->toBeNull()
        ->and(MaintenanceWindow::query()->orderBy('starts_at')->pluck('id')->all())->toBe($kept)
        ->and(MaintenanceWindow::query()->whereNotNull('series_id')->count())->toBe(0)
        ->and(MaintenanceWindow::find($kept[2])->isActive())->toBeTrue();
});

test('weekly and monthly series honour their days', function () {
    $weekly = app(SaveMaintenanceSeries::class)->handle($this->organization, seriesData([$this->monitor->id], [
        'frequency' => MaintenanceFrequency::Weekly,
        'weekdays' => [2, 4], // Tuesday and Thursday
    ]));

    expect($weekly->occurrences()->orderBy('starts_at')->pluck('occurrence_date')->map(fn ($date) => $date->toDateString())->all())
        ->toBe(['2026-11-24', '2026-11-26', '2026-12-01', '2026-12-03']);

    $monthly = app(SaveMaintenanceSeries::class)->handle($this->organization, seriesData([$this->monitor->id], [
        'frequency' => MaintenanceFrequency::Monthly,
        'monthDay' => 30,
    ]));

    expect($monthly->occurrences()->pluck('occurrence_date')->map(fn ($date) => $date->toDateString())->all())
        ->toBe(['2026-11-30']);
});

test('an end date stops the series', function () {
    $series = app(SaveMaintenanceSeries::class)->handle($this->organization, seriesData([$this->monitor->id], ['endsOn' => '2026-11-25']));

    expect(occurrenceStarts($series))->toBe(['2026-11-23 02:00:00', '2026-11-24 02:00:00', '2026-11-25 02:00:00']);
});

test('changing the organization time zone regenerates future occurrences only', function () {
    $series = app(SaveMaintenanceSeries::class)->handle($this->organization, seriesData([$this->monitor->id]));

    $this->travelTo('2026-11-23 02:30:00');

    app(UpdateOrganization::class)->handle($this->organization, new OrganizationData('Acme', timezone: 'America/New_York'));

    // The running occurrence keeps its Paris instants; the next one is 03:00 in New York (08:00 UTC).
    expect($series->occurrences()->where('occurrence_date', '2026-11-23')->sole()->starts_at->toDateTimeString())->toBe('2026-11-23 02:00:00')
        ->and($series->occurrences()->where('occurrence_date', '2026-11-24')->sole()->starts_at->toDateTimeString())->toBe('2026-11-24 08:00:00');
});

test('only monitors of the same organization are attached to a series and its occurrences', function () {
    $foreign = Monitor::factory()->create();

    $series = app(SaveMaintenanceSeries::class)->handle($this->organization, seriesData([$this->monitor->id, $foreign->id]));

    expect($series->monitors()->pluck('monitors.id')->all())->toBe([$this->monitor->id])
        ->and(DB::table('maintenance_window_monitors')->where('monitor_id', $foreign->id)->exists())->toBeFalse();
});

test('creating a series announces its first occurrence only; generated ones are not announced', function () {
    Event::fake([MaintenanceWindowScheduled::class]);

    $series = app(SaveMaintenanceSeries::class)->handle($this->organization, seriesData([$this->monitor->id]));
    $first = $series->occurrences()->orderBy('starts_at')->first();

    Event::assertDispatchedTimes(MaintenanceWindowScheduled::class, 1);
    Event::assertDispatched(MaintenanceWindowScheduled::class, fn ($event) => $event->maintenanceWindowId === $first->id);

    app(SaveMaintenanceSeries::class)->handle($this->organization, seriesData([$this->monitor->id], ['startTime' => '04:00']), series: $series);
    $this->travel(1)->days();
    app(MaterializeMaintenanceSeries::class)->handleAll();

    Event::assertDispatchedTimes(MaintenanceWindowScheduled::class, 1);
});

test('an occurrence cannot be edited on its own', function () {
    $series = app(SaveMaintenanceSeries::class)->handle($this->organization, seriesData([$this->monitor->id]));
    $occurrence = $series->occurrences()->orderBy('starts_at')->first();

    app(SaveMaintenanceWindow::class)->handle(
        $this->organization,
        new MaintenanceWindowData('Changed', null, $occurrence->starts_at, $occurrence->ends_at->addHour(), [$this->monitor->id]),
        window: $occurrence,
    );
})->throws(ValidationException::class);

test('the hourly command materializes upcoming occurrences', function () {
    $series = MaintenanceSeries::factory()->create(['organization_id' => $this->organization->id, 'start_time' => '03:00:00']);
    $series->monitors()->attach($this->monitor->id);

    $this->artisan('veyra:maintenance:materialize')->assertSuccessful();

    expect($series->occurrences()->count())->toBe(MaterializeMaintenanceSeries::HORIZON_DAYS);
});
