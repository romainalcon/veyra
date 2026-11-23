<?php

namespace App\Domain\Reports\Actions;

use App\Domain\Incidents\Models\Incident;
use App\Domain\Localization\SupportedLocales;
use App\Domain\Monitoring\Models\Monitor;
use App\Domain\Monitoring\Stats\UptimeCalculator;
use App\Domain\Monitoring\Stats\UptimeSummary;
use App\Domain\Notifications\Channels\NotificationMessage;
use App\Domain\Organizations\Models\Organization;
use App\Domain\Reports\WeeklyReport;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Computes a weekly report over `[start, end)` (UTC instants of the local
 * week boundaries). Uptime comes from raw checks of that exact window, with
 * planned maintenance excluded from observed and expected checks like the
 * dashboard; a monitor without checks has no uptime (never 100 %).
 * `lowestUptime` lists monitors below 100 % only, worst first.
 */
class BuildWeeklyReport
{
    public const LOWEST_UPTIME_LIMIT = 10;

    public const SLOWEST_LIMIT = 5;

    public const INCIDENTS_LIMIT = 10;

    public function handle(Organization $organization, CarbonImmutable $start, CarbonImmutable $end): WeeklyReport
    {
        $locale = SupportedLocales::resolve($organization->locale);
        $timezone = $organization->timezone;

        $monitors = Monitor::query()
            ->where('organization_id', $organization->id)
            ->where('created_at', '<', $end)
            ->orderBy('name')
            ->get(['id', 'name', 'interval_seconds', 'created_at']);

        $counts = $monitors->isEmpty() ? collect() : DB::table('monitor_checks')
            ->selectRaw('monitor_id, SUM(in_maintenance = 0) as checks, SUM(success = 1 AND in_maintenance = 0) as successes, SUM(in_maintenance = 1) as maintenance, AVG(CASE WHEN success = 1 AND in_maintenance = 0 THEN duration_ms END) as latency')
            ->whereIn('monitor_id', $monitors->pluck('id')->all())
            ->where('checked_at', '>=', $start)
            ->where('checked_at', '<', $end)
            ->groupBy('monitor_id')
            ->get()
            ->keyBy('monitor_id');

        $summaries = [];
        $rows = [];
        $latencies = [];

        foreach ($monitors as $monitor) {
            $row = $counts->get($monitor->id);
            $activeFrom = $monitor->created_at !== null && $monitor->created_at->greaterThan($start) ? $monitor->created_at : $start;
            $expected = (int) floor(max(0, $activeFrom->diffInSeconds($end, absolute: true)) / max(1, $monitor->interval_seconds));

            $summary = new UptimeSummary(
                checks: (int) ($row->checks ?? 0),
                successes: (int) ($row->successes ?? 0),
                expected: max(0, $expected - (int) ($row->maintenance ?? 0)),
            );
            $summaries[$monitor->id] = $summary;

            // Only monitors that missed checks: a list of 100 % rows says nothing.
            if ($summary->checks > 0 && $summary->successes < $summary->checks) {
                $rows[] = ['name' => $monitor->name, ...$summary->toArray()];
            }

            if (isset($row->latency)) {
                $latencies[] = ['name' => $monitor->name, 'latency_ms' => (int) round((float) $row->latency)];
            }
        }

        usort($rows, fn (array $a, array $b): int => [$a['uptime'], $a['name']] <=> [$b['uptime'], $b['name']]);
        usort($latencies, fn (array $a, array $b): int => [$b['latency_ms'], $a['name']] <=> [$a['latency_ms'], $b['name']]);

        $incidents = Incident::query()
            ->where('organization_id', $organization->id)
            ->where('started_at', '<', $end)
            ->where(fn ($query) => $query->whereNull('resolved_at')->orWhere('resolved_at', '>', $start))
            ->with('monitor:id,name')
            ->orderByDesc('started_at')
            ->get();

        $downtime = 0;
        $listed = [];

        foreach ($incidents as $incident) {
            $from = $incident->started_at->greaterThan($start) ? $incident->started_at : $start;
            $until = $incident->resolved_at !== null && $incident->resolved_at->lessThan($end) ? $incident->resolved_at : $end;
            $seconds = (int) max(0, $from->diffInSeconds($until, absolute: true));
            $downtime += $seconds;

            if (count($listed) < self::INCIDENTS_LIMIT) {
                $listed[] = [
                    'monitor' => $incident->monitor->name ?? (string) __('Deleted monitor', [], $locale),
                    'started_at' => $incident->started_at->setTimezone($timezone)->settings(['locale' => $locale])->isoFormat('ddd D MMM, HH:mm'),
                    'duration' => NotificationMessage::humanDuration($seconds, $locale),
                    'open' => $incident->resolved_at === null || $incident->resolved_at->greaterThanOrEqualTo($end),
                ];
            }
        }

        $localStart = $start->setTimezone($timezone)->settings(['locale' => $locale]);
        $localLastDay = $end->setTimezone($timezone)->subDay()->settings(['locale' => $locale]);

        return new WeeklyReport(
            organizationName: $organization->name,
            organizationSlug: $organization->slug,
            locale: $locale,
            timezone: $timezone,
            periodLabel: $localStart->isoFormat('D MMM').' – '.$localLastDay->isoFormat('D MMM YYYY'),
            monitorsCount: $monitors->count(),
            overall: UptimeCalculator::combine($summaries)->toArray(),
            lowestUptime: array_slice($rows, 0, self::LOWEST_UPTIME_LIMIT),
            slowest: array_slice($latencies, 0, self::SLOWEST_LIMIT),
            incidentsCount: $incidents->count(),
            downtimeSeconds: $downtime,
            incidents: $listed,
        );
    }
}
