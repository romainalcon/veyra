<?php

namespace App\Domain\Maintenance\Models;

use App\Domain\Maintenance\Enums\MaintenanceFrequency;
use App\Domain\Maintenance\Services\RecurrenceRule;
use App\Domain\Monitoring\Models\Monitor;
use App\Domain\Organizations\Models\Organization;
use App\Models\User;
use App\Policies\MaintenanceSeriesPolicy;
use Carbon\CarbonImmutable;
use Database\Factories\MaintenanceSeriesFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Table;
use Illuminate\Database\Eloquent\Attributes\UseFactory;
use Illuminate\Database\Eloquent\Attributes\UsePolicy;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A recurring maintenance rule (ADR-041), expressed in the organization time
 * zone. Its occurrences are materialized ahead of time as ordinary
 * MaintenanceWindow rows by MaterializeMaintenanceSeries.
 *
 * @property string $id
 * @property string $organization_id
 * @property string $title
 * @property string|null $description
 * @property MaintenanceFrequency $frequency
 * @property list<int>|null $weekdays ISO-8601 days (1 = Monday … 7 = Sunday)
 * @property int|null $month_day 1–31; clamped to the last day of shorter months
 * @property string $start_time local wall-clock time, HH:MM:SS
 * @property int $duration_minutes
 * @property CarbonImmutable $starts_on
 * @property CarbonImmutable|null $ends_on
 * @property string|null $created_by
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 * @property-read Organization $organization
 * @property-read Collection<int, Monitor> $monitors
 * @property-read Collection<int, MaintenanceWindow> $occurrences
 * @property-read User|null $creator
 */
#[Table('maintenance_series')]
#[Fillable(['organization_id', 'title', 'description', 'frequency', 'weekdays', 'month_day', 'start_time', 'duration_minutes', 'starts_on', 'ends_on', 'created_by'])]
#[UseFactory(MaintenanceSeriesFactory::class)]
#[UsePolicy(MaintenanceSeriesPolicy::class)]
class MaintenanceSeries extends Model
{
    /** @use HasFactory<MaintenanceSeriesFactory> */
    use HasFactory, HasUuids;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'frequency' => MaintenanceFrequency::class,
            'weekdays' => 'array',
            'month_day' => 'integer',
            'duration_minutes' => 'integer',
            'starts_on' => 'date',
            'ends_on' => 'date',
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
        return $this->belongsToMany(Monitor::class, 'maintenance_series_monitors');
    }

    /**
     * @return HasMany<MaintenanceWindow, $this>
     */
    public function occurrences(): HasMany
    {
        return $this->hasMany(MaintenanceWindow::class, 'series_id');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function rule(): RecurrenceRule
    {
        return new RecurrenceRule(
            frequency: $this->frequency,
            weekdays: array_map('intval', $this->weekdays ?? []),
            monthDay: $this->month_day,
            startTime: substr($this->start_time, 0, 5),
            durationMinutes: $this->duration_minutes,
            startsOn: $this->starts_on->toDateString(),
            endsOn: $this->ends_on?->toDateString(),
        );
    }
}
