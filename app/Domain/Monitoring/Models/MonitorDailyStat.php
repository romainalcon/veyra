<?php

namespace App\Domain\Monitoring\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;

/**
 * Daily aggregate of raw checks (UTC days). Kept after raw checks expire.
 *
 * @property string $monitor_id
 * @property CarbonImmutable $day
 * @property int $checks_count
 * @property int $success_count
 * @property int $failure_count
 * @property int $maintenance_count
 * @property int|null $latency_avg_ms
 * @property int|null $latency_max_ms
 * @property int $expected_checks
 */
class MonitorDailyStat extends Model
{
    public const CREATED_AT = null;

    public $incrementing = false;

    protected $guarded = [];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'day' => 'date',
            'checks_count' => 'integer',
            'success_count' => 'integer',
            'failure_count' => 'integer',
            'maintenance_count' => 'integer',
            'latency_avg_ms' => 'integer',
            'latency_max_ms' => 'integer',
            'expected_checks' => 'integer',
        ];
    }
}
