<?php

namespace App\Domain\Monitoring\Models;

use App\Domain\Incidents\Models\Incident;
use App\Domain\Monitoring\Enums\MonitorStatus;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Confirmed state of a monitor. Only modified under a row lock.
 *
 * @property string $monitor_id
 * @property MonitorStatus $status
 * @property int $consecutive_failures
 * @property int $consecutive_successes
 * @property CarbonImmutable|null $last_checked_at
 * @property CarbonImmutable|null $last_changed_at
 * @property CarbonImmutable|null $last_applied_scheduled_for
 * @property CarbonImmutable|null $streak_since
 * @property string|null $active_incident_id
 * @property CarbonImmutable|null $updated_at
 * @property-read Incident|null $activeIncident
 */
#[Fillable(['monitor_id', 'status', 'consecutive_failures', 'consecutive_successes', 'last_checked_at', 'last_changed_at', 'last_applied_scheduled_for', 'streak_since', 'active_incident_id'])]
class MonitorState extends Model
{
    public const CREATED_AT = null;

    protected $primaryKey = 'monitor_id';

    public $incrementing = false;

    protected $keyType = 'string';

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => MonitorStatus::class,
            'consecutive_failures' => 'integer',
            'consecutive_successes' => 'integer',
            'last_checked_at' => 'datetime',
            'last_changed_at' => 'datetime',
            'last_applied_scheduled_for' => 'datetime',
            'streak_since' => 'datetime',
        ];
    }

    /**
     * Ensure a state row exists (idempotent, safe under concurrency).
     */
    public static function ensureFor(string $monitorId): void
    {
        static::query()->insertOrIgnore([
            'monitor_id' => $monitorId,
            'status' => MonitorStatus::Unknown->value,
            'updated_at' => now(),
        ]);
    }

    /**
     * @return BelongsTo<Incident, $this>
     */
    public function activeIncident(): BelongsTo
    {
        return $this->belongsTo(Incident::class, 'active_incident_id');
    }
}
