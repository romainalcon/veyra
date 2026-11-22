<?php

namespace App\Domain\Maintenance\Actions;

use App\Domain\Maintenance\Models\MaintenanceSeries;
use App\Domain\Maintenance\Models\MaintenanceWindow;
use App\Domain\Monitoring\Models\Monitor;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Materializes the occurrences of a series that start within the horizon as
 * ordinary maintenance windows (ADR-041). Idempotent and concurrency-safe:
 * the series row is locked, existing (series, date) rows — including skipped
 * ones — are never touched, and the unique key is the final guard. Only
 * future starts are created, so history is never rewritten.
 */
class MaterializeMaintenanceSeries
{
    public const HORIZON_DAYS = 14;

    public const BATCH = 100;

    /**
     * @return int number of occurrences created
     */
    public function handle(MaintenanceSeries $series, ?CarbonImmutable $now = null): int
    {
        $now ??= CarbonImmutable::now();
        $now = $now->startOfSecond();

        return DB::transaction(function () use ($series, $now): int {
            /** @var MaintenanceSeries|null $series */
            $series = MaintenanceSeries::query()->with('organization:id,timezone')->lockForUpdate()->find($series->id);

            if ($series === null) {
                return 0;
            }

            $occurrences = $series->rule()->occurrencesBetween(
                $now,
                $now->addDays(self::HORIZON_DAYS),
                $series->organization->timezone,
            );

            if ($occurrences === []) {
                return 0;
            }

            $existing = MaintenanceWindow::query()
                ->where('series_id', $series->id)
                ->whereIn('occurrence_date', array_column($occurrences, 'date'))
                ->pluck('occurrence_date')
                ->map(fn (mixed $date): string => substr((string) $date, 0, 10))
                ->all();

            $rows = [];

            foreach ($occurrences as $occurrence) {
                if (in_array($occurrence['date'], $existing, true)) {
                    continue;
                }

                $rows[] = [
                    'id' => (string) Str::uuid7(),
                    'organization_id' => $series->organization_id,
                    'series_id' => $series->id,
                    'occurrence_date' => $occurrence['date'],
                    'title' => $series->title,
                    'description' => $series->description,
                    'starts_at' => $occurrence['starts_at']->toDateTimeString(),
                    'ends_at' => $occurrence['ends_at']->toDateTimeString(),
                    'created_by' => $series->created_by,
                    'created_at' => $now->toDateTimeString(),
                    'updated_at' => $now->toDateTimeString(),
                ];
            }

            if ($rows === []) {
                return 0;
            }

            DB::table('maintenance_windows')->insertOrIgnore($rows);

            // Only rows that were actually inserted get their monitors.
            $created = MaintenanceWindow::query()->whereIn('id', array_column($rows, 'id'))->pluck('id')->all();

            $monitorIds = Monitor::query()
                ->where('organization_id', $series->organization_id)
                ->whereIn('id', $series->monitors()->pluck('monitors.id'))
                ->pluck('id')
                ->all();

            $pivot = [];

            foreach ($created as $windowId) {
                foreach ($monitorIds as $monitorId) {
                    $pivot[] = ['maintenance_window_id' => (string) $windowId, 'monitor_id' => (string) $monitorId];
                }
            }

            foreach (array_chunk($pivot, 500) as $chunk) {
                DB::table('maintenance_window_monitors')->insertOrIgnore($chunk);
            }

            return count($created);
        });
    }

    /**
     * Every series that may still produce occurrences, in batches.
     *
     * @return int number of occurrences created
     */
    public function handleAll(?CarbonImmutable $now = null): int
    {
        $now ??= CarbonImmutable::now();
        $created = 0;

        MaintenanceSeries::query()
            ->where(fn ($query) => $query->whereNull('ends_on')->orWhere('ends_on', '>=', $now->subDays(2)->toDateString()))
            ->where('starts_on', '<=', $now->addDays(self::HORIZON_DAYS + 2)->toDateString())
            ->select('id')
            ->chunkById(self::BATCH, function ($chunk) use (&$created, $now): void {
                foreach ($chunk as $series) {
                    $created += $this->handle($series, $now);
                }
            });

        return $created;
    }

    /**
     * Removes the future, not yet skipped occurrences and generates them again
     * from the current rule. Started and past occurrences are never altered;
     * skipped ones keep their date reserved so a skip survives an edit.
     */
    public function regenerate(MaintenanceSeries $series, ?CarbonImmutable $now = null): int
    {
        $now ??= CarbonImmutable::now();

        DB::transaction(function () use ($series, $now): void {
            MaintenanceSeries::query()->lockForUpdate()->find($series->id);

            MaintenanceWindow::query()
                ->where('series_id', $series->id)
                ->whereNull('cancelled_at')
                ->where('starts_at', '>', $now->startOfSecond())
                ->delete();
        });

        return $this->handle($series, $now);
    }
}
