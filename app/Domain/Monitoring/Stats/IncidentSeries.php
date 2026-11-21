<?php

namespace App\Domain\Monitoring\Stats;

use App\Domain\Organizations\Models\Organization;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

class IncidentSeries
{
    /**
     * Incidents started per day (UTC) and their cumulated downtime.
     *
     * @return array{days: list<array{day: string, incidents: int, downtime_minutes: int}>, mttr_minutes: int|null}
     */
    public function forOrganization(Organization $organization, int $days = 30, ?CarbonImmutable $now = null): array
    {
        $now ??= CarbonImmutable::now();
        $first = $now->startOfDay()->subDays($days - 1);

        $rows = DB::table('incidents')
            ->where('organization_id', $organization->id)
            ->where('started_at', '>=', $first)
            ->selectRaw('DATE(started_at) as day, COUNT(*) as incidents')
            ->selectRaw('SUM(TIMESTAMPDIFF(SECOND, started_at, COALESCE(resolved_at, ?))) as downtime', [$now->toDateTimeString()])
            ->groupBy('day')
            ->get()
            ->keyBy(fn ($row) => substr((string) $row->day, 0, 10));

        $mttr = DB::table('incidents')
            ->where('organization_id', $organization->id)
            ->where('started_at', '>=', $first)
            ->whereNotNull('resolved_at')
            ->selectRaw('AVG(TIMESTAMPDIFF(SECOND, started_at, resolved_at)) as mttr')
            ->value('mttr');

        $series = [];

        for ($day = $first; $day->lessThanOrEqualTo($now); $day = $day->addDay()) {
            $row = $rows->get($day->toDateString());
            $series[] = [
                'day' => $day->toDateString(),
                'incidents' => (int) ($row->incidents ?? 0),
                'downtime_minutes' => (int) round(((int) ($row->downtime ?? 0)) / 60),
            ];
        }

        return [
            'days' => $series,
            'mttr_minutes' => $mttr === null ? null : (int) round(((float) $mttr) / 60),
        ];
    }
}
