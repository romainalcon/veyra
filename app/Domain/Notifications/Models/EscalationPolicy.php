<?php

namespace App\Domain\Notifications\Models;

use App\Domain\Monitoring\Models\Monitor;
use App\Domain\Organizations\Models\Organization;
use App\Policies\EscalationPolicyPolicy;
use Carbon\CarbonImmutable;
use Database\Factories\EscalationPolicyFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\UseFactory;
use Illuminate\Database\Eloquent\Attributes\UsePolicy;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Ordered escalation steps of an organization (ADR-045). A monitor uses its
 * own policy or, when it has none, the organization default (`is_default`,
 * at most one per organization).
 *
 * @property string $id
 * @property string $organization_id
 * @property string $name
 * @property bool $is_default
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 * @property-read Organization $organization
 * @property-read Collection<int, EscalationPolicyStep> $steps
 * @property-read Collection<int, Monitor> $monitors
 */
#[Fillable(['organization_id', 'name', 'is_default'])]
#[UseFactory(EscalationPolicyFactory::class)]
#[UsePolicy(EscalationPolicyPolicy::class)]
class EscalationPolicy extends Model
{
    /** @use HasFactory<EscalationPolicyFactory> */
    use HasFactory, HasUuids;

    public const MAX_STEPS = 5;

    public const MAX_DELAY_MINUTES = 1440;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'is_default' => 'boolean',
        ];
    }

    /**
     * @return BelongsTo<Organization, $this>
     */
    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    /**
     * @return HasMany<EscalationPolicyStep, $this>
     */
    public function steps(): HasMany
    {
        return $this->hasMany(EscalationPolicyStep::class, 'policy_id')->orderBy('position');
    }

    /**
     * @return HasMany<Monitor, $this>
     */
    public function monitors(): HasMany
    {
        return $this->hasMany(Monitor::class);
    }
}
