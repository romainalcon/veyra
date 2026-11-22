<?php

namespace App\Domain\StatusPages\Jobs;

use App\Domain\Localization\SupportedLocales;
use App\Domain\Notifications\Enums\DeliveryStatus;
use App\Domain\StatusPages\Mail\StatusPageAnnouncementMail;
use App\Domain\StatusPages\Models\StatusPageSubscriberDelivery;
use App\Domain\StatusPages\Support\SubscriptionLinks;
use Illuminate\Contracts\Mail\Mailer;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Throwable;

/**
 * Sends one announcement e-mail to one subscriber. Never sends a delivery
 * already marked as sent; transport errors are retried with backoff and
 * recorded without the address (class name only).
 */
class SendSubscriberAnnouncement implements ShouldBeUnique, ShouldQueue
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

    public function handle(Mailer $mailer): void
    {
        $delivery = StatusPageSubscriberDelivery::query()
            ->with(['subscriber', 'announcement.statusPage.organization:id,locale'])
            ->find($this->deliveryId);

        if ($delivery === null || $delivery->status !== DeliveryStatus::Pending) {
            return;
        }

        $subscriber = $delivery->subscriber;
        $statusPage = $delivery->announcement->statusPage;

        if ($subscriber === null || $subscriber->confirmed_at === null || ! $statusPage->is_published || ! $statusPage->subscriptions_enabled) {
            $this->finish($delivery, DeliveryStatus::Failed, 'Subscription no longer active.');

            return;
        }

        $attempts = $delivery->attempts + 1;

        try {
            $mailer->to($subscriber->email)->send(new StatusPageAnnouncementMail(
                $statusPage,
                $delivery->announcement,
                SubscriptionLinks::unsubscribe($subscriber),
                SubscriptionLinks::page($statusPage),
                SupportedLocales::resolve($statusPage->organization->locale),
            ));
        } catch (Throwable $exception) {
            $error = 'Mail transport error: '.class_basename($exception);

            if ($attempts >= self::MAX_ATTEMPTS) {
                $delivery->attempts = $attempts;
                $this->finish($delivery, DeliveryStatus::Failed, $error);

                return;
            }

            $delivery->update(['attempts' => $attempts, 'last_error' => $error]);
            $this->release(self::BACKOFF[$attempts - 1]);

            return;
        }

        $delivery->update(['status' => DeliveryStatus::Sent, 'attempts' => $attempts, 'sent_at' => now(), 'last_error' => null]);
    }

    public function failed(?Throwable $exception): void
    {
        $delivery = StatusPageSubscriberDelivery::query()->find($this->deliveryId);

        if ($delivery !== null && $delivery->status === DeliveryStatus::Pending) {
            $this->finish($delivery, DeliveryStatus::Failed, $exception ? 'Delivery job failed: '.class_basename($exception) : 'Delivery job failed.');
        }
    }

    private function finish(StatusPageSubscriberDelivery $delivery, DeliveryStatus $status, string $error): void
    {
        $delivery->update(['status' => $status, 'attempts' => $delivery->attempts, 'last_error' => mb_substr($error, 0, 255)]);
    }
}
