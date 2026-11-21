<?php

namespace App\Domain\Monitoring\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Leaf certificate last presented to the HTTPS probe of a monitor.
 *
 * @property string $monitor_id
 * @property string $fingerprint SHA-256 of the DER certificate
 * @property string|null $subject
 * @property string|null $issuer
 * @property CarbonImmutable $not_before
 * @property CarbonImmutable $not_after
 * @property CarbonImmutable $checked_at
 * @property-read Monitor $monitor
 */
#[Fillable(['monitor_id', 'fingerprint', 'subject', 'issuer', 'not_before', 'not_after', 'checked_at'])]
class MonitorTlsCertificate extends Model
{
    protected $primaryKey = 'monitor_id';

    public $incrementing = false;

    protected $keyType = 'string';

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'not_before' => 'datetime',
            'not_after' => 'datetime',
            'checked_at' => 'datetime',
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
