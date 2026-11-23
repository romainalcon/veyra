<?php

namespace App\Domain\Notifications\Jobs;

use App\Domain\Notifications\Channels\MessageFactory;
use App\Domain\Notifications\Channels\SenderRegistry;
use App\Domain\Notifications\Enums\DeliveryStatus;
use App\Domain\Notifications\Models\NotificationDelivery;
use App\Domain\Realtime\OrganizationUpdate;
use App\Domain\System\Metrics\MetricsRecorder;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Throwable;

/**
 * Delivers one notification. Retries transient failures with exponential
 * backoff (honouring Discord's Retry-After), stops on permanent failures, and
 * never sends a delivery that is already marked as sent.
 */
class SendNotificationDelivery implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public const MAX_ATTEMPTS = 5;

    /**
     * Delay before attempt n+1, in seconds.
     */
    public const BACKOFF = [30, 120, 600, 1800, 3600];

    public int $tries = self::MAX_ATTEMPTS;

    public int $timeout = 30;

    public int $uniqueFor = 300;

    public function __construct(public readonly string $deliveryId)
    {
        $this->onQueue('notifications');
    }

    public function uniqueId(): string
    {
        return $this->deliveryId;
    }

    public function handle(SenderRegistry $senders): void
    {
        $delivery = NotificationDelivery::query()->with(['channel', 'incident', 'certificateAlert', 'domainAlert'])->find($this->deliveryId);

        if ($delivery === null || $delivery->status !== DeliveryStatus::Pending) {
            return;
        }

        if (! $delivery->channel->enabled) {
            $this->finish($delivery, DeliveryStatus::Failed, 'Channel disabled before delivery.');

            return;
        }

        $message = match (true) {
            $delivery->incident !== null => MessageFactory::forIncident($delivery->incident, $delivery->event_type, $delivery->id, $delivery->escalation_step, $delivery->created_at),
            $delivery->certificateAlert !== null => MessageFactory::forCertificateAlert($delivery->certificateAlert, $delivery->id),
            $delivery->domainAlert !== null => MessageFactory::forDomainAlert($delivery->domainAlert, $delivery->id),
            default => null,
        };

        if ($message === null) {
            $this->finish($delivery, DeliveryStatus::Failed, 'Nothing to deliver.');

            return;
        }

        $result = $senders->for($delivery->channel->type)->send($delivery->channel, $message);

        $attempts = $delivery->attempts + 1;

        if ($result->sent) {
            $delivery->update(['status' => DeliveryStatus::Sent, 'attempts' => $attempts, 'sent_at' => now(), 'last_error' => null]);
            app(MetricsRecorder::class)->recordDelivery($delivery->channel->type, $delivery->event_type, sent: true);
            $this->broadcast($delivery);

            return;
        }

        if (! $result->retryable || $attempts >= self::MAX_ATTEMPTS) {
            $delivery->attempts = $attempts;
            $this->finish($delivery, DeliveryStatus::Failed, $result->error);

            return;
        }

        $delivery->update(['attempts' => $attempts, 'last_error' => $result->error]);

        $this->release($result->retryAfterSeconds ?? self::BACKOFF[$attempts - 1]);
    }

    public function failed(?Throwable $exception): void
    {
        $delivery = NotificationDelivery::query()->find($this->deliveryId);

        if ($delivery !== null && $delivery->status === DeliveryStatus::Pending) {
            $this->finish($delivery, DeliveryStatus::Failed, $exception ? 'Delivery job failed: '.class_basename($exception) : 'Delivery job failed.');
        }
    }

    private function finish(NotificationDelivery $delivery, DeliveryStatus $status, ?string $error): void
    {
        $delivery->update([
            'status' => $status,
            'attempts' => $delivery->attempts,
            'last_error' => $error === null ? null : mb_substr($error, 0, 255),
        ]);

        if ($status === DeliveryStatus::Failed) {
            app(MetricsRecorder::class)->recordDelivery($delivery->channel->type, $delivery->event_type, sent: false);
        }

        $this->broadcast($delivery);
    }

    private function broadcast(NotificationDelivery $delivery): void
    {
        OrganizationUpdate::dispatch($delivery->organization_id, OrganizationUpdate::NOTIFICATION_DELIVERED, [
            'incident_id' => $delivery->incident_id,
        ]);
    }
}
