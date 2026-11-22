<?php

namespace App\Domain\Maintenance\Models;

use App\Domain\Monitoring\Models\Monitor;
use App\Domain\Organizations\Models\Organization;
use App\Models\User;
use App\Policies\MaintenanceWindowPolicy;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Database\Factories\MaintenanceWindowFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\UseFactory;
use Illuminate\Database\Eloquent\Attributes\UsePolicy;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

/**
 * A planned maintenance window. It covers the half-open interval
 * [starts_at, ends_at) unless cancelled (only possible before it starts).
 * Windows with a series_id are occurrences of a recurring series (ADR-041);
 * cancelling one skips that occurrence.
 *
 * @property string $id
 * @property string $organization_id
 * @property string|null $series_id
 * @property CarbonImmutable|null $occurrence_date
 * @property string $title
 * @property string|null $description
 * @property CarbonImmutable $starts_at
 * @property CarbonImmutable $ends_at
 * @property CarbonImmutable|null $cancelled_at
 * @property string|null $created_by
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 * @property-read Organization $organization
 * @property-read Collection<int, Monitor> $monitors
 * @property-read User|null $creator
 * @property-read MaintenanceSeries|null $series
 */
#[Fillable(['organization_id', 'series_id', 'occurrence_date', 'title', 'description', 'starts_at', 'ends_at', 'cancelled_at', 'created_by'])]
#[UseFactory(MaintenanceWindowFactory::class)]
#[UsePolicy(MaintenanceWindowPolicy::class)]
class MaintenanceWindow extends Model
{
    /** @use HasFactory<MaintenanceWindowFactory> */
    use HasFactory, HasUuids;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'starts_at' => 'datetime',
            'ends_at' => 'datetime',
            'cancelled_at' => 'datetime',
            'occurrence_date' => 'date',
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
     * @return BelongsToMany<Monitor, $this>
     */
    public function monitors(): BelongsToMany
    {
        return $this->belongsToMany(Monitor::class, 'maintenance_window_monitors');
    }

    /**
     * @return BelongsTo<MaintenanceSeries, $this>
     */
    public function series(): BelongsTo
    {
        return $this->belongsTo(MaintenanceSeries::class, 'series_id');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /**
     * Windows in effect at the given instant.
     *
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    public function scopeActiveAt(Builder $query, CarbonInterface $at): Builder
    {
        return $query->whereNull('cancelled_at')
            ->where('starts_at', '<=', $at)
            ->where('ends_at', '>', $at);
    }

    public function isCancelled(): bool
    {
        return $this->cancelled_at !== null;
    }

    public function isActive(?CarbonInterface $at = null): bool
    {
        $at ??= CarbonImmutable::now();

        return ! $this->isCancelled() && $this->starts_at->lessThanOrEqualTo($at) && $this->ends_at->greaterThan($at);
    }

    public function hasStarted(?CarbonInterface $at = null): bool
    {
        return $this->starts_at->lessThanOrEqualTo($at ?? CarbonImmutable::now());
    }

    public function hasEnded(?CarbonInterface $at = null): bool
    {
        return $this->ends_at->lessThanOrEqualTo($at ?? CarbonImmutable::now());
    }
}
