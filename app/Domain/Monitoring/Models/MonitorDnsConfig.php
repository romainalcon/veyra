<?php

namespace App\Domain\Monitoring\Models;

use App\Domain\Monitoring\Enums\DnsMatchMode;
use App\Domain\Monitoring\Enums\DnsRecordType;
use App\Domain\Monitoring\Enums\DnsResolverChoice;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Query of a `dns` monitor and the last answers received (bounded, ADR-042).
 *
 * @property string $monitor_id
 * @property string $record_name
 * @property DnsRecordType $record_type
 * @property DnsResolverChoice $resolver
 * @property list<string>|null $expected_values
 * @property DnsMatchMode $match_mode
 * @property list<string>|null $last_answers
 * @property CarbonImmutable|null $last_resolved_at
 * @property-read Monitor $monitor
 */
#[Fillable(['monitor_id', 'record_name', 'record_type', 'resolver', 'expected_values', 'match_mode', 'last_answers', 'last_resolved_at'])]
class MonitorDnsConfig extends Model
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
            'record_type' => DnsRecordType::class,
            'resolver' => DnsResolverChoice::class,
            'match_mode' => DnsMatchMode::class,
            'expected_values' => 'array',
            'last_answers' => 'array',
            'last_resolved_at' => 'datetime',
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
        return "{$this->record_type->value} {$this->record_name}";
    }
}
