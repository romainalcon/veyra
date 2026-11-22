<?php

namespace App\Domain\StatusPages\Support;

use App\Domain\StatusPages\Models\StatusPage;
use App\Domain\StatusPages\Models\StatusPageSubscriber;
use Illuminate\Support\Facades\URL;

/**
 * Signed subscription links, always on the instance host (APP_URL): the
 * confirmation link expires, the unsubscribe link does not.
 */
final class SubscriptionLinks
{
    public static function confirm(StatusPageSubscriber $subscriber): string
    {
        return URL::temporarySignedRoute(
            'status-pages.subscriptions.confirm',
            now()->addHours((int) config('veyra.status_pages.confirmation_valid_hours', 48)),
            ['subscriber' => $subscriber->id],
        );
    }

    public static function unsubscribe(StatusPageSubscriber $subscriber): string
    {
        return URL::signedRoute('status-pages.subscriptions.unsubscribe', ['subscriber' => $subscriber->id]);
    }

    /**
     * Public address of the page: its verified custom domain, else /status/{slug}.
     */
    public static function page(StatusPage $statusPage): string
    {
        return $statusPage->hasVerifiedDomain()
            ? 'https://'.$statusPage->custom_domain
            : route('status-pages.public', $statusPage->slug);
    }
}
