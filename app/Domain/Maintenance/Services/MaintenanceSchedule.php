<?php

namespace App\Domain\Maintenance\Services;

use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;

/**
 * Answers "is this monitor under planned maintenance at that instant?".
 * Checks are classified by their `scheduled_for` slot, so replays and late
 * results always get the same answer.
 */
class MaintenanceSchedule
{
    public function covers(string $monitorId, CarbonInterface $at): bool
    {
        return DB::table('maintenance_window_monitors as wm')
            ->join('maintenance_windows as w', 'w.id', '=', 'wm.maintenance_window_id')
            ->where('wm.monitor_id', $monitorId)
            ->whereNull('w.cancelled_at')
            ->where('w.starts_at', '<=', $at->toDateTimeString())
            ->where('w.ends_at', '>', $at->toDateTimeString())
            ->exists();
    }

    /**
     * Enabled, non-deleted monitors with a window in effect at the instant.
     *
     * @return list<string>
     */
    public function monitorsUnderMaintenance(CarbonInterface $at): array
    {
        $ids = DB::table('maintenance_window_monitors as wm')
            ->join('maintenance_windows as w', 'w.id', '=', 'wm.maintenance_window_id')
            ->join('monitors as m', 'm.id', '=', 'wm.monitor_id')
            ->whereNull('w.cancelled_at')
            ->where('w.starts_at', '<=', $at->toDateTimeString())
            ->where('w.ends_at', '>', $at->toDateTimeString())
            ->where('m.enabled', true)
            ->whereNull('m.deleted_at')
            ->distinct()
            ->pluck('wm.monitor_id')
            ->map(fn ($id): string => (string) $id)
            ->all();

        return array_values($ids);
    }

    /**
     * End of the most recent window that ended for the monitor at or before
     * the instant, used to restart the streaks after maintenance.
     */
    public function lastEndedAt(string $monitorId, CarbonInterface $at): ?string
    {
        $end = DB::table('maintenance_window_monitors as wm')
            ->join('maintenance_windows as w', 'w.id', '=', 'wm.maintenance_window_id')
            ->where('wm.monitor_id', $monitorId)
            ->whereNull('w.cancelled_at')
            ->where('w.ends_at', '<=', $at->toDateTimeString())
            ->max('w.ends_at');

        return $end === null ? null : (string) $end;
    }
}
