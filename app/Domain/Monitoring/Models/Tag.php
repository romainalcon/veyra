<?php

namespace App\Domain\Monitoring\Models;

use App\Domain\Organizations\Models\Organization;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

/**
 * A monitor tag (ADR-047): a lower-case label unique per organization,
 * created when first assigned and removed when no monitor carries it.
 *
 * @property string $id
 * @property string $organization_id
 * @property string $name
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 * @property-read Organization $organization
 */
#[Fillable(['organization_id', 'name'])]
class Tag extends Model
{
    use HasUuids;

    public const MAX_LENGTH = 32;

    public const MAX_PER_MONITOR = 10;

    public const MAX_PER_ORGANIZATION = 100;

    /**
     * Letters, digits, spaces and . _ : / - ; starting with a letter or a digit.
     */
    public const PATTERN = '/^[\p{L}\p{N}][\p{L}\p{N} ._:\/-]*$/u';

    /**
     * Lower case, trimmed, inner whitespace collapsed to one space.
     */
    public static function normalize(string $name): string
    {
        return mb_strtolower((string) preg_replace('/\s+/u', ' ', trim($name)));
    }

    /**
     * @return BelongsTo<Organization, $this>
     */
    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    /**
     * @return BelongsToMany<Monitor, $this>
     */
    public function monitors(): BelongsToMany
    {
        return $this->belongsToMany(Monitor::class, 'monitor_tag');
    }
}
