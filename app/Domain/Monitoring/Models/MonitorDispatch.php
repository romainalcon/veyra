<?php

namespace App\Domain\Monitoring\Models;

use App\Domain\Monitoring\Enums\DispatchStatus;
use App\Domain\Monitoring\Enums\DispatchTrigger;
use Carbon\CarbonImmutable;
use Database\Factories\MonitorDispatchFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\UseFactory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Durable outbox row: one scheduled (or manual) execution of a monitor.
 *
 * @property string $id
 * @property string $monitor_id
 * @property CarbonImmutable $scheduled_for
 * @property DispatchTrigger $trigger
 * @property DispatchStatus $status
 * @property int $attempts
 * @property CarbonImmutable|null $dispatched_at
 * @property CarbonImmutable|null $completed_at
 * @property-read Monitor|null $monitor
 */
#[Fillable(['monitor_id', 'scheduled_for', 'trigger', 'status', 'attempts', 'dispatched_at', 'completed_at'])]
#[UseFactory(MonitorDispatchFactory::class)]
class MonitorDispatch extends Model
{
    /** @use HasFactory<MonitorDispatchFactory> */
    use HasFactory, HasUuids;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'scheduled_for' => 'datetime',
            'trigger' => DispatchTrigger::class,
            'status' => DispatchStatus::class,
            'attempts' => 'integer',
            'dispatched_at' => 'datetime',
            'completed_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<Monitor, $this>
     */
    public function monitor(): BelongsTo
    {
        return $this->belongsTo(Monitor::class)->withTrashed();
    }
}
