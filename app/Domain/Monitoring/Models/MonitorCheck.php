<?php

namespace App\Domain\Monitoring\Models;

use App\Domain\Monitoring\DTOs\CheckTimings;
use App\Domain\Monitoring\Enums\ProbeErrorCode;
use Carbon\CarbonImmutable;
use Database\Factories\MonitorCheckFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\UseFactory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One raw probe result (append-only, high volume).
 *
 * @property string $id
 * @property string $monitor_id
 * @property string $dispatch_id
 * @property CarbonImmutable $scheduled_for
 * @property CarbonImmutable $checked_at
 * @property bool $success
 * @property bool $slow successful but above the latency threshold (ADR-037)
 * @property int|null $duration_ms
 * @property int|null $status_code
 * @property ProbeErrorCode|null $error_code
 * @property array<string, scalar|null>|null $metadata
 * @property bool $in_maintenance
 * @property int|null $dns_ms phase timings (ADR-050), null for older checks and phases not reached
 * @property int|null $connect_ms
 * @property int|null $tls_ms
 * @property int|null $ttfb_ms
 * @property int|null $transfer_ms
 * @property int|null $redirect_ms
 * @property CarbonImmutable|null $created_at
 */
#[Fillable(['monitor_id', 'dispatch_id', 'scheduled_for', 'checked_at', 'success', 'slow', 'duration_ms', 'status_code', 'error_code', 'metadata', 'in_maintenance', 'dns_ms', 'connect_ms', 'tls_ms', 'ttfb_ms', 'transfer_ms', 'redirect_ms'])]
#[UseFactory(MonitorCheckFactory::class)]
class MonitorCheck extends Model
{
    /** @use HasFactory<MonitorCheckFactory> */
    use HasFactory, HasUuids;

    public const UPDATED_AT = null;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'scheduled_for' => 'datetime',
            'checked_at' => 'datetime',
            'success' => 'boolean',
            'slow' => 'boolean',
            'duration_ms' => 'integer',
            'status_code' => 'integer',
            'error_code' => ProbeErrorCode::class,
            'metadata' => 'array',
            'in_maintenance' => 'boolean',
            'dns_ms' => 'integer',
            'connect_ms' => 'integer',
            'tls_ms' => 'integer',
            'ttfb_ms' => 'integer',
            'transfer_ms' => 'integer',
            'redirect_ms' => 'integer',
        ];
    }

    public function timings(): CheckTimings
    {
        return CheckTimings::fromRow($this->getAttributes());
    }

    /**
     * @return BelongsTo<Monitor, $this>
     */
    public function monitor(): BelongsTo
    {
        return $this->belongsTo(Monitor::class)->withTrashed();
    }
}
