<?php

namespace App\Domain\Monitoring\Models;

use App\Domain\Incidents\Models\Incident;
use App\Domain\Monitoring\Enums\MonitorType;
use App\Domain\Notifications\Models\NotificationChannel;
use App\Domain\Organizations\Models\Organization;
use App\Policies\MonitorPolicy;
use Carbon\CarbonImmutable;
use Database\Factories\MonitorFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\UseFactory;
use Illuminate\Database\Eloquent\Attributes\UsePolicy;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * @property string $id
 * @property string $organization_id
 * @property string $name
 * @property MonitorType $type
 * @property bool $enabled
 * @property int $interval_seconds
 * @property int $timeout_seconds
 * @property CarbonImmutable|null $next_check_at
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 * @property CarbonImmutable|null $deleted_at
 * @property-read Organization $organization
 * @property-read MonitorHttpConfig|null $httpConfig
 * @property-read MonitorCheck|null $latestCheck
 * @property-read MonitorState|null $state
 */
#[Fillable(['organization_id', 'name', 'type', 'enabled', 'interval_seconds', 'timeout_seconds', 'next_check_at'])]
#[UseFactory(MonitorFactory::class)]
#[UsePolicy(MonitorPolicy::class)]
class Monitor extends Model
{
    /** @use HasFactory<MonitorFactory> */
    use HasFactory, HasUuids, SoftDeletes;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'type' => MonitorType::class,
            'enabled' => 'boolean',
            'interval_seconds' => 'integer',
            'timeout_seconds' => 'integer',
            'next_check_at' => 'datetime',
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
     * @return HasOne<MonitorHttpConfig, $this>
     */
    public function httpConfig(): HasOne
    {
        return $this->hasOne(MonitorHttpConfig::class);
    }

    /**
     * @return HasMany<MonitorCheck, $this>
     */
    public function checks(): HasMany
    {
        return $this->hasMany(MonitorCheck::class);
    }

    /**
     * @return HasOne<MonitorCheck, $this>
     */
    public function latestCheck(): HasOne
    {
        return $this->hasOne(MonitorCheck::class)->latestOfMany('checked_at');
    }

    /**
     * @return HasOne<MonitorState, $this>
     */
    public function state(): HasOne
    {
        return $this->hasOne(MonitorState::class);
    }

    /**
     * @return HasMany<Incident, $this>
     */
    public function incidents(): HasMany
    {
        return $this->hasMany(Incident::class);
    }

    /**
     * @return BelongsToMany<NotificationChannel, $this>
     */
    public function notificationChannels(): BelongsToMany
    {
        return $this->belongsToMany(NotificationChannel::class, 'monitor_notification_channels');
    }

    /**
     * @return HasMany<MonitorDailyStat, $this>
     */
    public function dailyStats(): HasMany
    {
        return $this->hasMany(MonitorDailyStat::class);
    }

    /**
     * @return HasMany<MonitorDispatch, $this>
     */
    public function dispatches(): HasMany
    {
        return $this->hasMany(MonitorDispatch::class);
    }
}
