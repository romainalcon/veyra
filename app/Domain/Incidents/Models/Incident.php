<?php

namespace App\Domain\Incidents\Models;

use App\Domain\Incidents\Enums\IncidentStatus;
use App\Domain\Monitoring\Models\Monitor;
use App\Domain\Notifications\Models\NotificationDelivery;
use App\Domain\Organizations\Models\Organization;
use App\Models\User;
use App\Policies\IncidentPolicy;
use Carbon\CarbonImmutable;
use Database\Factories\IncidentFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\UseFactory;
use Illuminate\Database\Eloquent\Attributes\UsePolicy;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property string $id
 * @property string $organization_id
 * @property string $monitor_id
 * @property IncidentStatus $status
 * @property CarbonImmutable $started_at
 * @property CarbonImmutable|null $resolved_at
 * @property CarbonImmutable|null $acknowledged_at
 * @property string|null $acknowledged_by
 * @property string|null $cause
 * @property CarbonImmutable|null $reminder_sent_at
 * @property int $escalation_step last escalation step queued (0 = none, ADR-045)
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 * @property-read Monitor|null $monitor
 * @property-read Organization $organization
 * @property-read User|null $acknowledger
 */
#[Fillable(['organization_id', 'monitor_id', 'status', 'started_at', 'resolved_at', 'acknowledged_at', 'acknowledged_by', 'cause', 'reminder_sent_at', 'escalation_step'])]
#[UseFactory(IncidentFactory::class)]
#[UsePolicy(IncidentPolicy::class)]
class Incident extends Model
{
    /** @use HasFactory<IncidentFactory> */
    use HasFactory, HasUuids;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => IncidentStatus::class,
            'started_at' => 'datetime',
            'resolved_at' => 'datetime',
            'acknowledged_at' => 'datetime',
            'reminder_sent_at' => 'datetime',
            'escalation_step' => 'integer',
        ];
    }

    public function isOpen(): bool
    {
        return $this->status === IncidentStatus::Open;
    }

    public function durationSeconds(): int
    {
        return (int) $this->started_at->diffInSeconds($this->resolved_at ?? now(), absolute: true);
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

    /**
     * @return BelongsTo<User, $this>
     */
    public function acknowledger(): BelongsTo
    {
        return $this->belongsTo(User::class, 'acknowledged_by');
    }

    /**
     * @return HasMany<NotificationDelivery, $this>
     */
    public function deliveries(): HasMany
    {
        return $this->hasMany(NotificationDelivery::class);
    }

    /**
     * @return HasMany<IncidentEvent, $this>
     */
    public function events(): HasMany
    {
        return $this->hasMany(IncidentEvent::class);
    }
}
