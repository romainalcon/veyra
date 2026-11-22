<?php

namespace App\Domain\StatusPages\Models;

use App\Domain\Notifications\Enums\DeliveryStatus;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One announcement e-mail to one subscriber; unique per pair (ADR-017 style).
 *
 * @property string $id
 * @property string $announcement_id
 * @property string $subscriber_id
 * @property DeliveryStatus $status
 * @property int $attempts
 * @property CarbonImmutable|null $sent_at
 * @property string|null $last_error
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 * @property-read StatusPageAnnouncement $announcement
 * @property-read StatusPageSubscriber|null $subscriber
 */
#[Fillable(['announcement_id', 'subscriber_id', 'status', 'attempts', 'sent_at', 'last_error'])]
class StatusPageSubscriberDelivery extends Model
{
    use HasUuids;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => DeliveryStatus::class,
            'attempts' => 'integer',
            'sent_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<StatusPageAnnouncement, $this>
     */
    public function announcement(): BelongsTo
    {
        return $this->belongsTo(StatusPageAnnouncement::class);
    }

    /**
     * @return BelongsTo<StatusPageSubscriber, $this>
     */
    public function subscriber(): BelongsTo
    {
        return $this->belongsTo(StatusPageSubscriber::class);
    }
}
