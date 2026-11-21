<?php

namespace App\Domain\System;

use Illuminate\Support\Facades\Cache;

/**
 * Whether checks are currently being executed. Drives the "monitoring
 * paused" banner so that UNKNOWN states caused by a worker or scheduler
 * outage are never mistaken for service downtime.
 */
class MonitoringStatus
{
    public const CACHE_KEY = 'veyra:monitoring-status';

    public function __construct(private readonly HealthCheck $healthCheck) {}

    /**
     * @return array{paused: bool, reasons: list<string>}
     */
    public function current(): array
    {
        /** @var array{paused: bool, reasons: list<string>} $status */
        $status = Cache::remember(self::CACHE_KEY, 30, function (): array {
            $reasons = [];

            foreach ([$this->healthCheck->scheduler(), $this->healthCheck->horizon()] as $result) {
                if ($result->status === CheckResult::FAIL) {
                    $reasons[] = $result->name;
                }
            }

            return ['paused' => $reasons !== [], 'reasons' => $reasons];
        });

        return $status;
    }
}
