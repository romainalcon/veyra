<?php

namespace App\Domain\Notifications\Models;

use App\Domain\Incidents\Models\Incident;
use App\Domain\Notifications\Enums\DeliveryStatus;
use App\Domain\Notifications\Enums\NotificationEvent;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property string $id
 * @property string $organization_id
 * @property string $incident_id
 * @property string $channel_id
 * @property NotificationEvent $event_type
 * @property DeliveryStatus $status
 * @property int $attempts
 * @property string|null $last_error
 * @property CarbonImmutable|null $sent_at
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 * @property-read Incident $incident
 * @property-read NotificationChannel $channel
 */
#[Fillable(['organization_id', 'incident_id', 'channel_id', 'event_type', 'status', 'attempts', 'last_error', 'sent_at'])]
class NotificationDelivery extends Model
{
    use HasUuids;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'event_type' => NotificationEvent::class,
            'status' => DeliveryStatus::class,
            'attempts' => 'integer',
            'sent_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<Incident, $this>
     */
    public function incident(): BelongsTo
    {
        return $this->belongsTo(Incident::class);
    }

    /**
     * @return BelongsTo<NotificationChannel, $this>
     */
    public function channel(): BelongsTo
    {
        return $this->belongsTo(NotificationChannel::class);
    }
}
