<?php

namespace App\Domain\Reports\Actions;

use App\Domain\Organizations\Models\Organization;
use App\Domain\Reports\Jobs\SendWeeklyReport;
use App\Domain\Reports\WeeklyReportPeriod;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Creates the due weekly report of every organization with at least one
 * opted-in member and one monitor. The unique (organization, period_start)
 * row is the idempotency key: hourly runs, overlapping runs and catch-up
 * runs later in the week queue each report once.
 */
class ScheduleWeeklyReports
{
    /**
     * @return int number of reports queued
     */
    public function handle(?CarbonImmutable $now = null): int
    {
        $now ??= CarbonImmutable::now();
        $queued = 0;

        Organization::query()
            ->whereHas('members', fn ($query) => $query->where('weekly_report', true))
            ->whereHas('monitors')
            ->select(['id', 'timezone'])
            ->chunkById(200, function ($organizations) use ($now, &$queued): void {
                foreach ($organizations as $organization) {
                    $period = WeeklyReportPeriod::dueAt($now, $organization->timezone);

                    if ($period === null) {
                        continue;
                    }

                    $id = (string) Str::uuid7();

                    $inserted = DB::table('weekly_reports')->insertOrIgnore([
                        'id' => $id,
                        'organization_id' => $organization->id,
                        'period_start' => $period->localStartDate,
                        'recipients_count' => 0,
                        'created_at' => $now,
                    ]);

                    if ($inserted === 1) {
                        SendWeeklyReport::dispatch($id, $period->start->toIso8601String(), $period->end->toIso8601String());
                        $queued++;
                    }
                }
            });

        return $queued;
    }
}
