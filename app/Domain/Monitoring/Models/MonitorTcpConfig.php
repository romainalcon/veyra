<?php

namespace App\Domain\Monitoring\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property string $monitor_id
 * @property string $host
 * @property int $port
 * @property-read Monitor $monitor
 */
#[Fillable(['monitor_id', 'host', 'port'])]
class MonitorTcpConfig extends Model
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
            'port' => 'integer',
        ];
    }

    /**
     * @return BelongsTo<Monitor, $this>
     */
    public function monitor(): BelongsTo
    {
        return $this->belongsTo(Monitor::class);
    }

    public function target(): string
    {
        $host = str_contains($this->host, ':') ? "[{$this->host}]" : $this->host;

        return "{$host}:{$this->port}";
    }
}
