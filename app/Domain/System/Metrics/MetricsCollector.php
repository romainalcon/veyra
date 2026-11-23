<?php

namespace App\Domain\System\Metrics;

use App\Domain\Monitoring\Enums\DispatchStatus;
use App\Domain\Monitoring\Enums\MonitorStatus;
use App\Domain\System\SchedulerHeartbeat;
use Carbon\CarbonImmutable;
use Illuminate\Queue\RedisQueue;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Laravel\Horizon\Contracts\JobRepository;
use Laravel\Horizon\Contracts\MasterSupervisorRepository;
use Throwable;

/**
 * Builds the instance metrics served by /metrics (ADR-048). Everything is an
 * aggregate over the whole instance: no organization, monitor, URL or user
 * ever appears in a name or a label. Each collector fails on its own and is
 * reported by `veyra_metrics_collector_up`, so a Redis outage still leaves the
 * MariaDB metrics readable (and the reverse).
 *
 * Metric names are a public contract (dashboards, alerts): renaming one is a
 * breaking change, see docs/operations/METRICS.md.
 */
class MetricsCollector
{
    public const QUEUES = ['probes', 'incidents', 'notifications', 'maintenance', 'default'];

    /**
     * Families always present in a scrape, in output order.
     */
    public const FAMILIES = [
        'veyra_info',
        'veyra_metrics_collector_up',
        'veyra_queue_jobs',
        'veyra_outbox_dispatches',
        'veyra_outbox_oldest_age_seconds',
        'veyra_outbox_failed_dispatches_last_hour',
        'veyra_scheduler_heartbeat_age_seconds',
        'veyra_horizon_master_supervisors',
        'veyra_horizon_recent_failed_jobs',
        'veyra_monitors',
        'veyra_probe_duration_seconds',
        'veyra_notification_deliveries_total',
    ];

    /**
     * @var array<string, bool>
     */
    private array $collectorUp = [];

    public function __construct(private readonly MetricsRecorder $recorder) {}

    /**
     * @return list<MetricFamily>
     */
    public function collect(): array
    {
        $this->collectorUp = [];
        $now = CarbonImmutable::now();

        $info = (new MetricFamily('veyra_info', 'gauge', 'Veyra version running this instance.'))
            ->add(1, ['version' => (string) config('veyra.version')]);

        $queues = new MetricFamily('veyra_queue_jobs', 'gauge', 'Jobs in a Redis queue by state (pending, delayed, reserved).');
        $this->guard('queues', function () use ($queues): void {
            $connection = Queue::connection('redis');

            foreach (self::QUEUES as $queue) {
                if ($connection instanceof RedisQueue) {
                    $queues->add($connection->pendingSize($queue), ['queue' => $queue, 'state' => 'pending']);
                    $queues->add($connection->delayedSize($queue), ['queue' => $queue, 'state' => 'delayed']);
                    $queues->add($connection->reservedSize($queue), ['queue' => $queue, 'state' => 'reserved']);
                } else {
                    $queues->add($connection->size($queue), ['queue' => $queue, 'state' => 'pending']);
                }
            }
        });

        $outbox = new MetricFamily('veyra_outbox_dispatches', 'gauge', 'Outbox dispatches not completed yet: pending (not pushed to Redis) or dispatched (waiting for a probe result).');
        $outboxAge = new MetricFamily('veyra_outbox_oldest_age_seconds', 'gauge', 'Age of the oldest pending dispatch (since its scheduled time) and of the oldest dispatched one (since it was pushed); 0 when there is none.');
        $outboxFailed = new MetricFamily('veyra_outbox_failed_dispatches_last_hour', 'gauge', 'Dispatches given up during the last hour (no check was recorded for them).');
        $this->guard('outbox', function () use ($outbox, $outboxAge, $outboxFailed, $now): void {
            $pending = DB::table('monitor_dispatches')->where('status', DispatchStatus::Pending->value)
                ->selectRaw('COUNT(*) AS total, MIN(scheduled_for) AS oldest')->first();
            $dispatched = DB::table('monitor_dispatches')->where('status', DispatchStatus::Dispatched->value)
                ->selectRaw('COUNT(*) AS total, MIN(dispatched_at) AS oldest')->first();

            foreach (['pending' => $pending, 'dispatched' => $dispatched] as $status => $row) {
                $outbox->add((int) ($row->total ?? 0), ['status' => $status]);
                $outboxAge->add(self::ageSeconds($row->oldest ?? null, $now), ['status' => $status]);
            }

            $outboxFailed->add(DB::table('monitor_dispatches')
                ->where('status', DispatchStatus::Failed->value)
                ->where('completed_at', '>=', $now->subHour()->toDateTimeString())
                ->count());
        });

        $scheduler = new MetricFamily('veyra_scheduler_heartbeat_age_seconds', 'gauge', 'Seconds since the last scheduler heartbeat (+Inf when none was ever recorded). Above 180 the scheduler is considered stopped.');
        $this->guard('scheduler', function () use ($scheduler, $now): void {
            $last = SchedulerHeartbeat::lastBeat();
            $scheduler->add($last === null ? INF : max(0, $now->getTimestamp() - $last->getTimestamp()));
        });

        $masters = new MetricFamily('veyra_horizon_master_supervisors', 'gauge', 'Horizon master supervisors by status.');
        $failedJobs = new MetricFamily('veyra_horizon_recent_failed_jobs', 'gauge', 'Jobs failed within Horizon\'s recent failed jobs retention window.');
        $this->guard('horizon', function () use ($masters, $failedJobs): void {
            $counts = ['running' => 0, 'paused' => 0];

            foreach (app(MasterSupervisorRepository::class)->all() as $master) {
                $status = ($master->status ?? 'running') === 'paused' ? 'paused' : 'running';
                $counts[$status]++;
            }

            foreach ($counts as $status => $count) {
                $masters->add($count, ['status' => $status]);
            }

            $failedJobs->add((int) app(JobRepository::class)->countRecentlyFailed());
        });

        $monitors = new MetricFamily('veyra_monitors', 'gauge', 'Enabled monitors of the whole instance by confirmed state.');
        $this->guard('monitors', function () use ($monitors): void {
            $counts = array_fill_keys(array_map(fn (MonitorStatus $status): string => $status->value, MonitorStatus::cases()), 0);

            $rows = DB::table('monitors')
                ->leftJoin('monitor_states', 'monitor_states.monitor_id', '=', 'monitors.id')
                ->where('monitors.enabled', true)
                ->whereNull('monitors.deleted_at')
                ->selectRaw('monitor_states.status AS state, COUNT(*) AS total')
                ->groupBy('monitor_states.status')
                ->get();

            foreach ($rows as $row) {
                $state = is_string($row->state) && array_key_exists($row->state, $counts) ? $row->state : MonitorStatus::Unknown->value;
                $counts[$state] += (int) $row->total;
            }

            foreach ($counts as $state => $count) {
                $monitors->add($count, ['state' => $state]);
            }
        });

        $probes = new MetricFamily('veyra_probe_duration_seconds', 'histogram', 'Duration of recorded HTTP, TCP and DNS probes by monitor type and result.');
        $deliveries = new MetricFamily('veyra_notification_deliveries_total', 'counter', 'Finished notification deliveries by channel type, event and result (sent or failed after its last attempt).');
        $this->guard('counters', function () use ($probes, $deliveries): void {
            $this->addCounters($this->recorder->all(), $probes, $deliveries);
        });

        $up = new MetricFamily('veyra_metrics_collector_up', 'gauge', 'Whether a metrics collector could read its source during this scrape (1) or not (0).');
        foreach ($this->collectorUp as $collector => $isUp) {
            $up->add($isUp ? 1 : 0, ['collector' => $collector]);
        }

        return [$info, $up, $queues, $outbox, $outboxAge, $outboxFailed, $scheduler, $masters, $failedJobs, $monitors, $probes, $deliveries];
    }

    /**
     * @param  list<MetricFamily>  $families
     */
    public static function render(array $families): string
    {
        return implode('', array_map(fn (MetricFamily $family): string => $family->render(), $families));
    }

    /**
     * @param  array<string, string>  $values
     */
    private function addCounters(array $values, MetricFamily $probes, MetricFamily $deliveries): void
    {
        /** @var array<string, array{buckets: array<int|string, int>, count: int, sum: float}> $series */
        $series = [];
        $deliveryRows = [];

        foreach ($values as $field => $value) {
            $parts = explode('|', $field);

            if ($parts[0] === 'probe' && count($parts) >= 4) {
                $key = $parts[1].'|'.$parts[2];
                $series[$key] ??= ['buckets' => [], 'count' => 0, 'sum' => 0.0];

                match (true) {
                    $parts[3] === 'bucket' && isset($parts[4]) => $series[$key]['buckets'][$parts[4]] = (int) $value,
                    $parts[3] === 'count' => $series[$key]['count'] = (int) $value,
                    $parts[3] === 'sum' => $series[$key]['sum'] = (float) $value,
                    default => null,
                };
            } elseif ($parts[0] === 'delivery' && count($parts) === 4) {
                $deliveryRows[] = [['channel' => $parts[1], 'event' => $parts[2], 'result' => $parts[3]], (int) $value];
            }
        }

        ksort($series);

        foreach ($series as $key => $data) {
            [$type, $result] = explode('|', $key);
            $labels = ['type' => $type, 'result' => $result];
            $cumulative = 0;

            foreach (MetricsRecorder::PROBE_DURATION_BUCKETS as $index => $bound) {
                $cumulative += $data['buckets'][$index] ?? 0;
                $probes->add($cumulative, [...$labels, 'le' => MetricFamily::number((float) $bound)], '_bucket');
            }

            $probes->add($data['count'], [...$labels, 'le' => '+Inf'], '_bucket');
            $probes->add($data['sum'], $labels, '_sum');
            $probes->add($data['count'], $labels, '_count');
        }

        usort($deliveryRows, fn (array $a, array $b): int => implode('|', $a[0]) <=> implode('|', $b[0]));

        foreach ($deliveryRows as [$labels, $count]) {
            $deliveries->add($count, $labels);
        }
    }

    private function guard(string $collector, callable $collect): void
    {
        try {
            $collect();
            $this->collectorUp[$collector] = true;
        } catch (Throwable) {
            $this->collectorUp[$collector] = false;
        }
    }

    private static function ageSeconds(mixed $oldest, CarbonImmutable $now): int
    {
        if ($oldest === null) {
            return 0;
        }

        return max(0, $now->getTimestamp() - CarbonImmutable::parse((string) $oldest, 'UTC')->getTimestamp());
    }
}
