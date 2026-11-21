<?php

namespace App\Domain\Monitoring\Probes;

use App\Domain\Monitoring\DTOs\ProbeResult;
use App\Domain\Monitoring\Enums\ProbeErrorCode;
use App\Domain\Monitoring\Models\Monitor;
use App\Domain\Monitoring\Probes\Contracts\MonitorProbe;
use App\Domain\System\SchedulerBeatLog;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Log;
use LogicException;

/**
 * Evaluates a heartbeat deadline (ADR-025). The expected window is
 * (deadline - period - grace, deadline], i.e. after the ping (or creation,
 * resume, previous deadline) that set this deadline:
 * - a ping inside the window means nothing is missed (pings record their own
 *   checks), so no check is written;
 * - no ping is recorded as `heartbeat_missed` only if the scheduler beat log
 *   shows this instance running during the whole window; otherwise the
 *   absence of signal may be Veyra's own outage and nothing is written
 *   (UNKNOWN, never DOWN).
 */
final class HeartbeatProbe implements MonitorProbe
{
    public function canProbe(Monitor $monitor): bool
    {
        return $monitor->heartbeatConfig !== null;
    }

    public function probe(Monitor $monitor, CarbonImmutable $scheduledFor): ?ProbeResult
    {
        $config = $monitor->heartbeatConfig ?? throw new LogicException('Heartbeat monitor without configuration.');
        $windowStart = $scheduledFor->subSeconds($monitor->interval_seconds + $config->grace_seconds);

        // A ping that pushed the deadline past this slot (it arrived after the
        // slot was claimed) means nothing was missed.
        if ($config->last_ping_at !== null && $config->last_ping_at->greaterThan($windowStart)) {
            return null;
        }

        if (! SchedulerBeatLog::covers($windowStart, $scheduledFor)) {
            Log::info('veyra.heartbeat.evaluation_skipped', [
                'monitor_id' => $monitor->id,
                'reason' => 'scheduler_gap',
            ]);

            return null;
        }

        return ProbeResult::failure(ProbeErrorCode::HeartbeatMissed, metadata: [
            'window_start' => $windowStart->toIso8601String(),
        ]);
    }
}
