<?php

namespace App\Domain\Notifications\Models;

use App\Domain\Incidents\Models\Incident;
use App\Domain\Monitoring\Models\DomainExpiryAlert;
use App\Domain\Monitoring\Models\TlsCertificateAlert;
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
 * @property string|null $incident_id set for incident events
 * @property string|null $certificate_alert_id set for certificate warnings
 * @property string|null $domain_alert_id set for domain warnings (exactly one subject is set)
 * @property string $channel_id
 * @property NotificationEvent $event_type
 * @property int $escalation_step escalation step of an `escalated` delivery, 0 otherwise
 * @property DeliveryStatus $status
 * @property int $attempts
 * @property string|null $last_error
 * @property CarbonImmutable|null $sent_at
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 * @property-read Incident|null $incident
 * @property-read TlsCertificateAlert|null $certificateAlert
 * @property-read DomainExpiryAlert|null $domainAlert
 * @property-read NotificationChannel $channel
 */
#[Fillable(['organization_id', 'incident_id', 'certificate_alert_id', 'domain_alert_id', 'channel_id', 'event_type', 'escalation_step', 'status', 'attempts', 'last_error', 'sent_at'])]
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
            'escalation_step' => 'integer',
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
     * @return BelongsTo<TlsCertificateAlert, $this>
     */
    public function certificateAlert(): BelongsTo
    {
        return $this->belongsTo(TlsCertificateAlert::class);
    }

    /**
     * @return BelongsTo<DomainExpiryAlert, $this>
     */
    public function domainAlert(): BelongsTo
    {
        return $this->belongsTo(DomainExpiryAlert::class, 'domain_alert_id');
    }

    /**
     * Name of the monitor the delivery is about, for delivery history.
     */
    public function monitorName(): string
    {
        return ($this->incident->monitor ?? $this->certificateAlert->monitor ?? $this->domainAlert->monitor ?? null)->name ?? 'Deleted monitor';
    }

    /**
     * @return BelongsTo<NotificationChannel, $this>
     */
    public function channel(): BelongsTo
    {
        return $this->belongsTo(NotificationChannel::class);
    }
}
