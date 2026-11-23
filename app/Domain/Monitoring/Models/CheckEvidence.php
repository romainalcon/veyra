<?php

namespace App\Domain\Monitoring\Models;

use App\Domain\Incidents\Models\Incident;
use App\Domain\Monitoring\Enums\ProbeErrorCode;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * What the server answered on a failing HTTP check (ADR-050). Written with
 * the check while no incident is active; the evidence of the first failing
 * check is attached to the incident it confirms, the others are discarded.
 * Shown only to members on the incident page.
 *
 * @property string $id
 * @property string $organization_id
 * @property string $monitor_id
 * @property string $check_id
 * @property string|null $incident_id
 * @property int $status_code
 * @property string|null $http_version
 * @property ProbeErrorCode $error_code
 * @property string|null $content_type
 * @property array<string, string> $headers allow-listed, never credentials or cookies
 * @property string|null $body_excerpt
 * @property bool $body_truncated
 * @property string|null $body_omitted_reason
 * @property CarbonImmutable $checked_at
 * @property CarbonImmutable $created_at
 */
#[Fillable(['organization_id', 'monitor_id', 'check_id', 'incident_id', 'status_code', 'http_version', 'error_code', 'content_type', 'headers', 'body_excerpt', 'body_truncated', 'body_omitted_reason', 'checked_at'])]
class CheckEvidence extends Model
{
    use HasUuids;

    protected $table = 'check_evidence';

    public const UPDATED_AT = null;

    /**
     * Evidence of failures that were never confirmed is kept this long.
     */
    public const UNATTACHED_RETENTION_HOURS = 24;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status_code' => 'integer',
            'error_code' => ProbeErrorCode::class,
            'headers' => 'array',
            'body_truncated' => 'boolean',
            'checked_at' => 'datetime',
            'created_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<Incident, $this>
     */
    public function incident(): BelongsTo
    {
        return $this->belongsTo(Incident::class);
    }
}
