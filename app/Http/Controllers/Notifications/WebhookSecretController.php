<?php

namespace App\Http\Controllers\Notifications;

use App\Domain\Notifications\Actions\RegenerateWebhookSecret;
use App\Domain\Notifications\Enums\ChannelType;
use App\Domain\Notifications\Models\NotificationChannel;
use App\Domain\Organizations\Models\Organization;
use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;

/**
 * Issues a new signing secret for a webhook channel; it is flashed once and
 * never sent to the browser again.
 */
class WebhookSecretController extends Controller
{
    public function store(Organization $organization, NotificationChannel $notificationChannel, RegenerateWebhookSecret $regenerateWebhookSecret): RedirectResponse
    {
        Gate::authorize('update', [$notificationChannel, $organization]);
        abort_unless($notificationChannel->type === ChannelType::Webhook, 404);

        $secret = $regenerateWebhookSecret->handle($notificationChannel);

        Inertia::flash('webhookSecret', ['channel_id' => $notificationChannel->id, 'secret' => $secret]);
        Inertia::flash('toast', ['type' => 'success', 'message' => __('New signing secret issued. Update your endpoint: the previous one no longer signs deliveries.')]);

        return to_route('organizations.notification-channels.index', $organization);
    }
}
