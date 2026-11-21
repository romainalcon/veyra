<?php

namespace App\Domain\Monitoring\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property string $monitor_id
 * @property string $token_hash SHA-256 of the ping token; the token itself is never stored
 * @property int $grace_seconds
 * @property CarbonImmutable|null $last_ping_at
 * @property bool $last_ping_failed
 * @property-read Monitor $monitor
 */
#[Fillable(['monitor_id', 'token_hash', 'grace_seconds', 'last_ping_at', 'last_ping_failed'])]
#[Hidden(['token_hash'])]
class MonitorHeartbeatConfig extends Model
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
            'grace_seconds' => 'integer',
            'last_ping_at' => 'datetime',
            'last_ping_failed' => 'boolean',
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
