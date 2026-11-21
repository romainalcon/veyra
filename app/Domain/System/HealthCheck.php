<?php

namespace App\Domain\System;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Redis;
use Laravel\Horizon\Contracts\MasterSupervisorRepository;
use Throwable;

/**
 * Runtime health of the instance. The database is critical (down without
 * it); Redis, Horizon and the scheduler make the instance degraded: checks
 * stop, and monitor states turn UNKNOWN, but the application still serves.
 */
class HealthCheck
{
    public const MIN_MARIADB_VERSION = '11.4';

    public const QUEUE_BACKLOG_WARNING = 1000;

    public const MONITORED_QUEUES = ['probes', 'incidents', 'notifications'];

    /**
     * @return list<CheckResult>
     */
    public function run(): array
    {
        return [
            $this->database(),
            $this->redis(),
            $this->horizon(),
            $this->scheduler(),
            $this->probes(),
            ...$this->queues(),
        ];
    }

    /**
     * @param  list<CheckResult>  $results
     */
    public static function overall(array $results): string
    {
        foreach ($results as $result) {
            if ($result->critical && $result->status === CheckResult::FAIL) {
                return 'down';
            }
        }

        foreach ($results as $result) {
            if ($result->status === CheckResult::FAIL) {
                return 'degraded';
            }
        }

        return 'ok';
    }

    public function database(): CheckResult
    {
        try {
            $version = (string) DB::selectOne('select version() as version')->version;
        } catch (Throwable $exception) {
            return CheckResult::fail('database', 'Database unreachable ('.class_basename($exception).').', critical: true);
        }

        $release = preg_match('/^(\d+\.\d+)/', $version, $matches) === 1 ? $matches[1] : '0';
        $isMariaDb = str_contains(strtolower($version), 'mariadb');

        if (! $isMariaDb || version_compare($release, self::MIN_MARIADB_VERSION, '<')) {
            return CheckResult::warn('database', "Connected to {$version}; MariaDB ".self::MIN_MARIADB_VERSION.'+ is required.', critical: true);
        }

        return CheckResult::ok('database', "MariaDB {$release}", critical: true);
    }

    public function redis(): CheckResult
    {
        try {
            Redis::connection()->ping();
        } catch (Throwable $exception) {
            return CheckResult::fail('redis', 'Redis unreachable ('.class_basename($exception).').');
        }

        return CheckResult::ok('redis', 'Reachable');
    }

    public function horizon(): CheckResult
    {
        try {
            $masters = app(MasterSupervisorRepository::class)->all();
        } catch (Throwable $exception) {
            return CheckResult::fail('horizon', 'Horizon status unavailable ('.class_basename($exception).').');
        }

        if ($masters === []) {
            return CheckResult::fail('horizon', 'No Horizon master supervisor is running: probes are not executed.');
        }

        $paused = collect($masters)->contains(fn ($master) => ($master->status ?? 'running') === 'paused');

        return $paused
            ? CheckResult::warn('horizon', 'Horizon is paused.')
            : CheckResult::ok('horizon', count($masters).' master supervisor(s) running');
    }

    public function scheduler(): CheckResult
    {
        $last = SchedulerHeartbeat::lastBeat();

        if ($last === null) {
            return CheckResult::fail('scheduler', 'No scheduler heartbeat recorded: monitors are not being scheduled.');
        }

        return SchedulerHeartbeat::isAlive()
            ? CheckResult::ok('scheduler', 'Last heartbeat '.$last->diffForHumans())
            : CheckResult::fail('scheduler', 'Last heartbeat '.$last->diffForHumans().': the scheduler seems stopped.');
    }

    public function probes(): CheckResult
    {
        try {
            $hasActiveMonitors = DB::table('monitors')->where('enabled', true)->whereNull('deleted_at')->exists();

            if (! $hasActiveMonitors) {
                return CheckResult::ok('probes', 'No active monitors');
            }

            $last = DB::table('monitor_checks')->max('created_at');
        } catch (Throwable $exception) {
            return CheckResult::warn('probes', 'Unable to read probe activity ('.class_basename($exception).').');
        }

        if ($last === null) {
            return CheckResult::warn('probes', 'No check recorded yet.');
        }

        $lastAt = CarbonImmutable::parse((string) $last, 'UTC');

        return $lastAt->greaterThan(CarbonImmutable::now()->subMinutes(10))
            ? CheckResult::ok('probes', 'Last check '.$lastAt->diffForHumans())
            : CheckResult::fail('probes', 'No check recorded since '.$lastAt->diffForHumans().'.');
    }

    /**
     * @return list<CheckResult>
     */
    public function queues(): array
    {
        $results = [];

        foreach (self::MONITORED_QUEUES as $queue) {
            try {
                $size = Queue::connection('redis')->size($queue);
            } catch (Throwable) {
                $results[] = CheckResult::warn("queue:{$queue}", 'Queue size unavailable.');

                continue;
            }

            $results[] = $size > self::QUEUE_BACKLOG_WARNING
                ? CheckResult::warn("queue:{$queue}", "{$size} jobs waiting")
                : CheckResult::ok("queue:{$queue}", "{$size} jobs waiting");
        }

        return $results;
    }
}
