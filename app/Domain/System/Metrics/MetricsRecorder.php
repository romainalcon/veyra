<?php

namespace App\Domain\System\Metrics;

use App\Domain\Monitoring\Enums\MonitorType;
use App\Domain\Notifications\Enums\ChannelType;
use App\Domain\Notifications\Enums\NotificationEvent;
use Illuminate\Support\Facades\Redis;
use Throwable;

/**
 * Event counters for /metrics, kept in one Redis hash shared by every web and
 * Horizon process (ADR-048). Labels only take enum values, so cardinality is
 * bounded and no tenant data is ever recorded. Recording is a no-op while
 * metrics are disabled and never fails the calling job: a Redis flush only
 * resets the counters, which Prometheus handles.
 */
class MetricsRecorder
{
    public const KEY = 'veyra:metrics:counters';

    /**
     * Upper bounds of the probe duration histogram, in seconds.
     */
    public const PROBE_DURATION_BUCKETS = [0.05, 0.1, 0.25, 0.5, 1, 2.5, 5, 10, 30];

    public static function enabled(): bool
    {
        return (bool) config('veyra.metrics.enabled');
    }

    /**
     * Heartbeat checks are not timed (a missed ping has no duration), so only
     * probes that contact a target are recorded.
     */
    public function recordProbe(MonitorType $type, bool $success, ?int $durationMs): void
    {
        if (! self::enabled() || $durationMs === null || $type === MonitorType::Heartbeat) {
            return;
        }

        $seconds = max(0, $durationMs) / 1000;
        $series = 'probe|'.$type->value.'|'.($success ? 'success' : 'failure');
        $bucket = 'inf';

        foreach (self::PROBE_DURATION_BUCKETS as $index => $bound) {
            if ($seconds <= $bound) {
                $bucket = (string) $index;

                break;
            }
        }

        $this->write(function ($redis) use ($series, $bucket, $seconds): void {
            $redis->hincrby(self::KEY, $series.'|bucket|'.$bucket, 1);
            $redis->hincrby(self::KEY, $series.'|count', 1);
            $redis->hincrbyfloat(self::KEY, $series.'|sum', $seconds);
        });
    }

    public function recordDelivery(ChannelType $channel, NotificationEvent $event, bool $sent): void
    {
        if (! self::enabled()) {
            return;
        }

        $field = 'delivery|'.$channel->value.'|'.$event->value.'|'.($sent ? 'sent' : 'failed');

        $this->write(fn ($redis) => $redis->hincrby(self::KEY, $field, 1));
    }

    /**
     * @return array<string, string>
     */
    public function all(): array
    {
        /** @var array<string, string>|false $values */
        $values = Redis::connection()->hgetall(self::KEY);

        return is_array($values) ? $values : [];
    }

    private function write(callable $commands): void
    {
        try {
            Redis::connection()->transaction($commands);
        } catch (Throwable) {
            // Metrics are best effort: losing an increment must never fail a probe or a delivery.
        }
    }
}
