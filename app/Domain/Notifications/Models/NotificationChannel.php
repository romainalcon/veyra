<?php

namespace App\Domain\Notifications\Models;

use App\Domain\Monitoring\Models\Monitor;
use App\Domain\Notifications\Enums\ChannelType;
use App\Domain\Organizations\Models\Organization;
use App\Policies\NotificationChannelPolicy;
use Carbon\CarbonImmutable;
use Database\Factories\NotificationChannelFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Attributes\UseFactory;
use Illuminate\Database\Eloquent\Attributes\UsePolicy;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property string $id
 * @property string $organization_id
 * @property ChannelType $type
 * @property string $name
 * @property array{recipients?: list<string>, webhook_url?: string} $config_encrypted
 * @property bool $enabled
 * @property bool $is_default
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 * @property-read Organization $organization
 */
#[Fillable(['organization_id', 'type', 'name', 'config_encrypted', 'enabled', 'is_default'])]
#[Hidden(['config_encrypted'])]
#[UseFactory(NotificationChannelFactory::class)]
#[UsePolicy(NotificationChannelPolicy::class)]
class NotificationChannel extends Model
{
    /** @use HasFactory<NotificationChannelFactory> */
    use HasFactory, HasUuids;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'type' => ChannelType::class,
            'config_encrypted' => 'encrypted:array',
            'enabled' => 'boolean',
            'is_default' => 'boolean',
        ];
    }

    /**
     * @return list<string>
     */
    public function recipients(): array
    {
        return $this->config_encrypted['recipients'] ?? [];
    }

    public function webhookUrl(): ?string
    {
        return $this->config_encrypted['webhook_url'] ?? null;
    }

    /**
     * Non-secret description shown in the UI.
     */
    public function summary(): string
    {
        return match ($this->type) {
            ChannelType::Email => count($this->recipients()).' recipient(s): '.implode(', ', $this->recipients()),
            ChannelType::Discord => 'Webhook …'.substr((string) $this->webhookUrl(), -4),
        };
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
        return $this->belongsToMany(Monitor::class, 'monitor_notification_channels');
    }

    /**
     * @return HasMany<NotificationDelivery, $this>
     */
    public function deliveries(): HasMany
    {
        return $this->hasMany(NotificationDelivery::class, 'channel_id');
    }
}
