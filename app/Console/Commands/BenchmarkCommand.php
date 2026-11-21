<?php

namespace App\Console\Commands;

use App\Domain\Monitoring\Actions\PublishPendingDispatches;
use App\Domain\Monitoring\Actions\ScheduleDueMonitors;
use App\Domain\Monitoring\Jobs\PurgeExpiredHistory;
use App\Domain\Monitoring\Models\Monitor;
use App\Domain\Monitoring\Stats\AggregateDailyStats;
use App\Domain\Organizations\Models\Organization;
use Carbon\CarbonImmutable;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Redis;
use Illuminate\Support\Str;
use Symfony\Component\Process\Process;

#[Signature('veyra:benchmark
    {--monitors=500 : Number of monitors (interval 60 s)}
    {--duration=120 : Seconds of scheduling to simulate}
    {--workers=4 : queue:work processes for probes and incidents}
    {--latency=150 : Simulated probe latency in ms}
    {--failure-rate=0.02 : Simulated failure ratio}
    {--synthetic-checks=100000 : Rows for the aggregation/purge benchmark}
    {--database=veyra_bench : Dedicated database (dropped and recreated)}
    {--redis-db=6 : Dedicated Redis database (flushed)}
    {--report= : Write a Markdown report to this path}
    {--child : Internal: run inside the isolated benchmark environment}')]
#[Description('Load-test baseline of the scheduling pipeline with a simulated probe (local/testing only).')]
class BenchmarkCommand extends Command
{
    public function handle(): int
    {
        if (! app()->environment(['local', 'testing'])) {
            $this->components->error('veyra:benchmark only runs in local or testing environments.');

            return self::FAILURE;
        }

        $database = (string) $this->option('database');
        $redisDb = (int) $this->option('redis-db');

        if (! str_ends_with($database, '_bench')) {
            $this->components->error('The benchmark database must be a dedicated "*_bench" database.');

            return self::FAILURE;
        }

        if (! $this->option('child')) {
            return $this->runIsolated($database, $redisDb);
        }

        if (config('database.connections.mysql.database') !== $database || (int) config('database.redis.default.database') !== $redisDb) {
            $this->components->error('Isolation check failed: refusing to run outside the benchmark database.');

            return self::FAILURE;
        }

        $this->prepare($database, $redisDb);

        $monitors = (int) $this->option('monitors');
        $duration = (int) $this->option('duration');
        $this->seed($monitors);

        $this->components->info("Running {$duration}s with {$monitors} monitors and {$this->option('workers')} workers…");
        $workers = $this->startWorkers($database, $redisDb);
        $timings = $this->schedule($duration);
        $drained = $this->waitForDrain(90);
        array_walk($workers, fn (Process $process) => $process->stop(5));

        $pipeline = $this->pipelineMetrics($duration, $timings, $drained);
        $maintenance = $this->maintenanceMetrics((int) $this->option('synthetic-checks'));

        $this->table(['Metric', 'Value'], array_map(fn ($key, $value) => [$key, $value], array_keys($pipeline + $maintenance), $pipeline + $maintenance));

        if ($path = $this->option('report')) {
            file_put_contents((string) $path, $this->report($pipeline, $maintenance));
            $this->components->info("Report written to {$path}");
        }

        return self::SUCCESS;
    }

    /**
     * Re-run this command in a child process whose whole configuration
     * (database, Redis, queue, cache) points at the benchmark resources from
     * boot, so nothing can leak into the application's data or queues.
     */
    private function runIsolated(string $database, int $redisDb): int
    {
        if ($redisDb === (int) config('database.redis.default.database') || $redisDb === (int) config('database.redis.cache.database')) {
            $this->components->error('The benchmark Redis database must differ from the application ones.');

            return self::FAILURE;
        }

        DB::statement("CREATE DATABASE IF NOT EXISTS `{$database}` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");

        $arguments = [];
        foreach (['monitors', 'duration', 'workers', 'latency', 'failure-rate', 'synthetic-checks', 'database', 'redis-db', 'report'] as $option) {
            if ($this->option($option) !== null) {
                $arguments[] = "--{$option}=".$this->option($option);
            }
        }

        $process = new Process(
            [PHP_BINARY, base_path('artisan'), 'veyra:benchmark', ...$arguments, '--child'],
            base_path(),
            $this->environment($database, $redisDb),
            null,
            null,
        );

        return $process->run(fn ($type, $output) => $this->output->write($output));
    }

    /**
     * @return array<string, string>
     */
    private function environment(string $database, int $redisDb): array
    {
        return [
            'DB_DATABASE' => $database,
            'REDIS_DB' => (string) $redisDb,
            'REDIS_CACHE_DB' => (string) ($redisDb + 1),
            'QUEUE_CONNECTION' => 'redis',
            'CACHE_STORE' => 'redis',
            'BROADCAST_CONNECTION' => 'null',
            'MAIL_MAILER' => 'array',
            'VEYRA_BENCHMARK_PROBE' => $this->option('latency').':'.$this->option('failure-rate'),
        ];
    }

    private function prepare(string $database, int $redisDb): void
    {
        Redis::connection()->flushdb();
        Redis::connection('cache')->flushdb();

        Artisan::call('migrate:fresh', ['--force' => true]);
    }

    private function seed(int $count): void
    {
        $organization = Organization::factory()->create(['name' => 'Benchmark', 'slug' => 'benchmark-'.Str::lower(Str::random(6))]);
        $now = CarbonImmutable::now()->startOfSecond();

        // Spread first checks over one interval, like a real fleet.
        Monitor::factory()->count($count)
            ->sequence(fn ($sequence) => ['next_check_at' => $now->addSeconds($sequence->index % 60)])
            ->create(['organization_id' => $organization->id, 'interval_seconds' => 60, 'timeout_seconds' => 10]);
    }

    /**
     * @return list<Process>
     */
    private function startWorkers(string $database, int $redisDb): array
    {
        $environment = $this->environment($database, $redisDb);

        $workers = [];

        for ($i = 0; $i < (int) $this->option('workers'); $i++) {
            $process = new Process([PHP_BINARY, base_path('artisan'), 'queue:work', 'redis', '--queue=probes,incidents', '--sleep=0', '--tries=1', '--timeout=45', '--quiet'], base_path(), $environment, null, null);
            // Unread output pipes would fill up and block the workers.
            $process->disableOutput();
            $process->start();
            $workers[] = $process;
        }

        return $workers;
    }

    /**
     * @return list<array{schedule_ms: float, publish_ms: float, claimed: int, published: int}>
     */
    private function schedule(int $duration): array
    {
        $timings = [];
        $end = microtime(true) + $duration;

        while (microtime(true) < $end) {
            $tick = microtime(true);

            $claimed = app(ScheduleDueMonitors::class)->handle();
            $scheduledAt = microtime(true);
            $published = app(PublishPendingDispatches::class)->handle()['published'];
            $publishedAt = microtime(true);

            $timings[] = [
                'schedule_ms' => ($scheduledAt - $tick) * 1000,
                'publish_ms' => ($publishedAt - $scheduledAt) * 1000,
                'claimed' => $claimed,
                'published' => $published,
            ];

            usleep((int) max(0, (10 - (microtime(true) - $tick)) * 1_000_000));
        }

        return $timings;
    }

    private function waitForDrain(int $timeout): bool
    {
        $end = microtime(true) + $timeout;

        while (microtime(true) < $end) {
            if (Queue::connection('redis')->size('probes') === 0 && Queue::connection('redis')->size('incidents') === 0) {
                sleep(2);

                return true;
            }

            sleep(1);
        }

        return false;
    }

    /**
     * @param  list<array{schedule_ms: float, publish_ms: float, claimed: int, published: int}>  $timings
     * @return array<string, string>
     */
    private function pipelineMetrics(int $duration, array $timings, bool $drained): array
    {
        $checks = DB::table('monitor_checks')->count();
        $lags = DB::table('monitor_checks')
            ->selectRaw('TIMESTAMPDIFF(SECOND, scheduled_for, checked_at) as lag')
            ->orderBy('lag')
            ->pluck('lag')
            ->map(fn ($lag): int => (int) $lag)
            ->all();

        $percentile = fn (float $p): string => $lags === [] ? '—' : $lags[(int) floor((count($lags) - 1) * $p)].' s';
        $dispatches = DB::table('monitor_dispatches')->selectRaw('status, COUNT(*) as total')->groupBy('status')->pluck('total', 'status');
        $scheduleMs = array_column($timings, 'schedule_ms');
        $publishMs = array_column($timings, 'publish_ms');

        return [
            'Monitors' => (string) $this->option('monitors'),
            'Duration' => "{$duration} s",
            'Workers' => (string) $this->option('workers'),
            'Simulated probe' => $this->option('latency').' ms, '.((float) $this->option('failure-rate') * 100).' % failures',
            'Checks recorded' => (string) $checks,
            'Throughput' => round($checks / max(1, $duration), 1).' checks/s',
            'Lag scheduled → recorded p50' => $percentile(0.5),
            'Lag scheduled → recorded p95' => $percentile(0.95),
            'Lag scheduled → recorded max' => $lags === [] ? '—' : end($lags).' s',
            'Schedule batch avg / max' => round(array_sum($scheduleMs) / max(1, count($scheduleMs)), 1).' / '.round(max($scheduleMs ?: [0]), 1).' ms',
            'Publish batch avg / max' => round(array_sum($publishMs) / max(1, count($publishMs)), 1).' / '.round(max($publishMs ?: [0]), 1).' ms',
            'Dispatch outcomes' => $dispatches->map(fn ($total, $status) => "{$status}={$total}")->implode(', '),
            'Monitor states' => DB::table('monitor_states')->selectRaw('status, COUNT(*) as total')->groupBy('status')->pluck('total', 'status')->map(fn ($t, $s) => "{$s}={$t}")->implode(', '),
            'Open incidents' => (string) DB::table('incidents')->where('status', 'open')->count(),
            'Queues drained' => $drained ? 'yes' : 'NO (backlog remained)',
        ];
    }

    /**
     * @return array<string, string>
     */
    private function maintenanceMetrics(int $rows): array
    {
        $monitorIds = Monitor::query()->pluck('id')->all();
        $day = CarbonImmutable::now()->subDays(40)->startOfDay();

        foreach (array_chunk(range(0, $rows - 1), 2000) as $chunk) {
            DB::table('monitor_checks')->insert(array_map(fn (int $i): array => [
                'id' => (string) Str::uuid7(),
                'monitor_id' => $monitorIds[$i % count($monitorIds)],
                'dispatch_id' => (string) Str::uuid7(),
                'scheduled_for' => $day->addSeconds(intdiv($i, count($monitorIds)) * 60)->toDateTimeString(),
                'checked_at' => $day->addSeconds(intdiv($i, count($monitorIds)) * 60 + 1)->toDateTimeString(),
                'success' => $i % 40 !== 0,
                'duration_ms' => 100 + $i % 50,
                'status_code' => 200,
                'created_at' => $day->toDateTimeString(),
            ], $chunk));
        }

        $started = microtime(true);
        app(AggregateDailyStats::class)->handle($day);
        $aggregateMs = (microtime(true) - $started) * 1000;

        $started = microtime(true);
        (new PurgeExpiredHistory)->handle();
        $purgeMs = (microtime(true) - $started) * 1000;

        return [
            'Synthetic checks' => number_format($rows),
            'Aggregate one day' => round($aggregateMs).' ms',
            'Purge expired checks' => round($purgeMs).' ms',
            'Checks left after purge' => (string) DB::table('monitor_checks')->where('checked_at', '<', $day->addDays(2))->count(),
        ];
    }

    /**
     * @param  array<string, string>  $pipeline
     * @param  array<string, string>  $maintenance
     */
    private function report(array $pipeline, array $maintenance): string
    {
        $row = fn ($key, $value) => "| {$key} | {$value} |";
        $version = (string) DB::selectOne('select version() as version')->version;

        return implode("\n", [
            '# Veyra — load baseline',
            '',
            '- Date: '.CarbonImmutable::now()->toDateTimeString().' UTC',
            '- Host: '.php_uname('s').' '.php_uname('m').', PHP '.PHP_VERSION.', '.$version.', Redis '.(Redis::connection()->info('server')['redis_version'] ?? '?'),
            '- Command: `php artisan veyra:benchmark --monitors='.$this->option('monitors').' --duration='.$this->option('duration').' --workers='.$this->option('workers').' --latency='.$this->option('latency').' --failure-rate='.$this->option('failure-rate').'`',
            '- Probe: simulated (no network); the SSRF policy and real HTTP latency are not part of this measurement.',
            '',
            '## Scheduling pipeline',
            '',
            '| Metric | Value |',
            '|---|---|',
            ...array_map($row, array_keys($pipeline), $pipeline),
            '',
            '## Maintenance',
            '',
            '| Metric | Value |',
            '|---|---|',
            ...array_map($row, array_keys($maintenance), $maintenance),
            '',
        ]);
    }
}
