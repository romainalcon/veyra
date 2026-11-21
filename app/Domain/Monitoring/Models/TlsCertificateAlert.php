<?php

namespace App\Domain\Monitoring\Models;

use App\Domain\Organizations\Models\Organization;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One expiry warning for one certificate of one monitor at one threshold
 * (0 = expired). Unique per (monitor, fingerprint, threshold).
 *
 * @property string $id
 * @property string $organization_id
 * @property string $monitor_id
 * @property string $fingerprint
 * @property int $threshold_days
 * @property CarbonImmutable $not_after
 * @property CarbonImmutable $created_at
 * @property-read Monitor $monitor
 * @property-read Organization $organization
 */
#[Fillable(['organization_id', 'monitor_id', 'fingerprint', 'threshold_days', 'not_after', 'created_at'])]
class TlsCertificateAlert extends Model
{
    use HasUuids;

    public const UPDATED_AT = null;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'threshold_days' => 'integer',
            'not_after' => 'datetime',
            'created_at' => 'datetime',
        ];
    }

    public function isExpired(): bool
    {
        return $this->threshold_days === 0;
    }

    /**
     * @return BelongsTo<Monitor, $this>
     */
    public function monitor(): BelongsTo
    {
        return $this->belongsTo(Monitor::class)->withTrashed();
    }

    /**
     * @return BelongsTo<Organization, $this>
     */
    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }
}
