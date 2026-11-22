<?php

namespace App\Domain\Monitoring\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Registration expiry of the domain monitored by an HTTP or DNS monitor,
 * read through RDAP at most once a day (ADR-043). `host` is the monitored
 * host the row was computed for: a row for another host is stale.
 *
 * @property string $monitor_id
 * @property string $host
 * @property string|null $domain registered domain, as known by its registry
 * @property CarbonImmutable|null $expires_at
 * @property string|null $registrar
 * @property CarbonImmutable|null $checked_at last lookup attempt
 * @property string|null $last_error English reason of the last failed lookup
 * @property-read Monitor $monitor
 */
#[Fillable(['monitor_id', 'host', 'domain', 'expires_at', 'registrar', 'checked_at', 'last_error'])]
class MonitorDomain extends Model
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
            'expires_at' => 'datetime',
            'checked_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<Monitor, $this>
     */
    public function monitor(): BelongsTo
    {
        return $this->belongsTo(Monitor::class);
    }
}
