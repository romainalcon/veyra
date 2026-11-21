<?php

namespace App\Domain\Monitoring\Heartbeats;

use App\Domain\Monitoring\Actions\RecordProbeResult;
use App\Domain\Monitoring\DTOs\ProbeResult;
use App\Domain\Monitoring\Enums\DispatchStatus;
use App\Domain\Monitoring\Enums\DispatchTrigger;
use App\Domain\Monitoring\Enums\ProbeErrorCode;
use App\Domain\Monitoring\Models\Monitor;
use App\Domain\Monitoring\Models\MonitorCheck;
use App\Domain\Monitoring\Models\MonitorDispatch;
use App\Domain\Monitoring\Models\MonitorHeartbeatConfig;
use App\Domain\Monitoring\Models\MonitorState;
use App\Domain\Monitoring\Support\HeartbeatToken;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Handles one ping of a heartbeat monitor (ADR-025).
 *
 * Every ping moves the deadline to `ping + period + grace`. A check is
 * recorded through the regular dispatch → check → state pipeline only when it
 * carries information: the first successful ping of a period, a ping after a
 * failure (recovery), or an explicit failure signal. Frequent pings therefore
 * produce about one check per period, which keeps uptime and coverage
 * comparable with probed monitors.
 */
class RecordHeartbeatPing
{
    public function __construct(private readonly RecordProbeResult $recordProbeResult) {}

    public function handle(string $token, bool $failed = false, ?CarbonImmutable $now = null): PingOutcome
    {
        if (! HeartbeatToken::isWellFormed($token)) {
            return PingOutcome::NotFound;
        }

        $now = ($now ?? CarbonImmutable::now())->startOfSecond();
        $hash = HeartbeatToken::hash($token);

        return DB::transaction(function () use ($hash, $failed, $now): PingOutcome {
            $config = MonitorHeartbeatConfig::query()->where('token_hash', $hash)->lockForUpdate()->first();
            $monitor = $config === null ? null : Monitor::query()->find($config->monitor_id);

            if ($config === null || $monitor === null) {
                return PingOutcome::NotFound;
            }

            if (! $monitor->enabled) {
                return PingOutcome::Ignored;
            }

            $config->update(['last_ping_at' => $now, 'last_ping_failed' => $failed]);
            $monitor->update(['next_check_at' => $now->addSeconds($monitor->interval_seconds + $config->grace_seconds)]);

            if (! $failed && ! $this->needsCheck($monitor, $now)) {
                return PingOutcome::Accepted;
            }

            $dispatchId = (string) Str::uuid7();

            $inserted = DB::table('monitor_dispatches')->insertOrIgnore([
                'id' => $dispatchId,
                'monitor_id' => $monitor->id,
                'scheduled_for' => $now->toDateTimeString(),
                'trigger' => DispatchTrigger::Ping->value,
                'status' => DispatchStatus::Dispatched->value,
                'attempts' => 1,
                'dispatched_at' => $now->toDateTimeString(),
                'created_at' => $now->toDateTimeString(),
                'updated_at' => $now->toDateTimeString(),
            ]);

            // Another ping (or a deadline evaluation) already took this second.
            if ($inserted === 0) {
                return PingOutcome::Accepted;
            }

            $result = $failed
                ? ProbeResult::failure(ProbeErrorCode::HeartbeatFailed)
                : new ProbeResult(true, null, null, null);

            $this->recordProbeResult->handle(MonitorDispatch::query()->findOrFail($dispatchId), $result, $now);

            return PingOutcome::Recorded;
        });
    }

    /**
     * A successful ping is recorded unless the current period already has a
     * successful check (since the streak start, i.e. not before a resume).
     */
    private function needsCheck(Monitor $monitor, CarbonImmutable $now): bool
    {
        $streakSince = MonitorState::query()->whereKey($monitor->id)->value('streak_since');

        $latest = MonitorCheck::query()
            ->where('monitor_id', $monitor->id)
            ->where('scheduled_for', '>', $now->subSeconds($monitor->interval_seconds))
            ->when($streakSince, fn ($query, $since) => $query->where('scheduled_for', '>=', $since))
            ->orderByDesc('scheduled_for')
            ->orderByDesc('id')
            ->first(['success']);

        return $latest === null || ! $latest->success;
    }
}
