<?php

namespace App\Domain\StatusPages\Jobs;

use App\Domain\Localization\SupportedLocales;
use App\Domain\StatusPages\Mail\SubscriptionConfirmationMail;
use App\Domain\StatusPages\Models\StatusPageSubscriber;
use App\Domain\StatusPages\Support\SubscriptionLinks;
use Illuminate\Contracts\Mail\Mailer;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Sends the double opt-in e-mail. Only the subscriber id is queued, never the
 * address; nothing is sent once the subscriber is confirmed or removed.
 */
class SendSubscriptionConfirmation implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public int $timeout = 30;

    /**
     * @var list<int>
     */
    public array $backoff = [30, 120];

    public function __construct(public readonly string $subscriberId)
    {
        $this->onQueue('notifications');
    }

    public function handle(Mailer $mailer): void
    {
        $subscriber = StatusPageSubscriber::query()->with('statusPage.organization:id,locale')->find($this->subscriberId);

        if ($subscriber === null || $subscriber->confirmed_at !== null) {
            return;
        }

        $statusPage = $subscriber->statusPage;

        if (! $statusPage->is_published || ! $statusPage->subscriptions_enabled) {
            return;
        }

        $mailer->to($subscriber->email)->send(new SubscriptionConfirmationMail(
            $statusPage,
            SubscriptionLinks::confirm($subscriber),
            SubscriptionLinks::page($statusPage),
            SupportedLocales::resolve($statusPage->organization->locale),
        ));
    }
}
