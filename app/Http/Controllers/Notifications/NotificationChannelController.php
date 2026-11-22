<?php

namespace App\Http\Controllers\Notifications;

use App\Domain\Notifications\Actions\SaveNotificationChannel;
use App\Domain\Notifications\Actions\SendTestNotification;
use App\Domain\Notifications\Enums\ChannelType;
use App\Domain\Notifications\Models\NotificationChannel;
use App\Domain\Notifications\Models\NotificationDelivery;
use App\Domain\Organizations\Models\Organization;
use App\Http\Controllers\Controller;
use App\Http\Requests\Notifications\SaveNotificationChannelRequest;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

class NotificationChannelController extends Controller
{
    public function index(Request $request, Organization $organization): Response
    {
        Gate::authorize('viewAny', [NotificationChannel::class, $organization]);

        /** @var User $user */
        $user = $request->user();
        $canManage = $user->can('create', [NotificationChannel::class, $organization]);

        $channels = $organization->notificationChannels()
            ->withCount('monitors')
            ->orderBy('name')
            ->get()
            ->map(fn (NotificationChannel $channel): array => [
                'id' => $channel->id,
                'type' => $channel->type->value,
                'name' => $channel->name,
                'summary' => $channel->summary(),
                'enabled' => $channel->enabled,
                'is_default' => $channel->is_default,
                'monitors_count' => $channel->monitors_count,
                // Recipients are only visible to channel managers; webhook URLs never.
                'recipients' => $canManage ? $channel->recipients() : [],
            ]);

        $deliveries = NotificationDelivery::query()
            ->where('organization_id', $organization->id)
            ->with(['channel:id,name,type', 'incident.monitor:id,name,deleted_at', 'certificateAlert.monitor:id,name,deleted_at'])
            ->latest()
            ->limit(50)
            ->get()
            ->map(fn (NotificationDelivery $delivery): array => [
                'id' => $delivery->id,
                'channel' => $delivery->channel->name,
                'event' => $delivery->event_type->value,
                'status' => $delivery->status->value,
                'attempts' => $delivery->attempts,
                'last_error' => $delivery->last_error,
                'monitor' => $delivery->monitorName(),
                'incident_id' => $delivery->incident_id,
                'created_at' => $delivery->created_at?->toIso8601String(),
                'sent_at' => $delivery->sent_at?->toIso8601String(),
            ]);

        return Inertia::render('notifications/Index', [
            'channels' => $channels,
            'deliveries' => $deliveries,
            'canManage' => $canManage,
            'types' => array_map(fn (ChannelType $type): array => ['value' => $type->value, 'label' => $type->label()], ChannelType::cases()),
            'maxRecipients' => (int) config('veyra.notifications.max_email_recipients'),
        ]);
    }

    public function store(SaveNotificationChannelRequest $request, Organization $organization, SaveNotificationChannel $saveNotificationChannel): RedirectResponse
    {
        Gate::authorize('create', [NotificationChannel::class, $organization]);

        $channel = $saveNotificationChannel->create($organization, ChannelType::from((string) $request->validated('type')), $request->payload());

        if ($channel->type === ChannelType::Webhook) {
            // Shown once; the browser never receives it again (ADR-033).
            Inertia::flash('webhookSecret', ['channel_id' => $channel->id, 'secret' => $channel->signingSecret()]);
        }

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Notification channel created.')]);

        return to_route('organizations.notification-channels.index', $organization);
    }

    public function update(SaveNotificationChannelRequest $request, Organization $organization, NotificationChannel $notificationChannel, SaveNotificationChannel $saveNotificationChannel): RedirectResponse
    {
        Gate::authorize('update', [$notificationChannel, $organization]);

        $saveNotificationChannel->update($notificationChannel, $request->payload());

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Notification channel updated.')]);

        return to_route('organizations.notification-channels.index', $organization);
    }

    public function destroy(Organization $organization, NotificationChannel $notificationChannel): RedirectResponse
    {
        Gate::authorize('delete', [$notificationChannel, $organization]);

        $notificationChannel->delete();

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Notification channel deleted.')]);

        return to_route('organizations.notification-channels.index', $organization);
    }

    public function test(Organization $organization, NotificationChannel $notificationChannel, SendTestNotification $sendTestNotification): RedirectResponse
    {
        Gate::authorize('test', [$notificationChannel, $organization]);

        $result = $sendTestNotification->handle($notificationChannel);

        Inertia::flash('toast', $result->sent
            ? ['type' => 'success', 'message' => __('Test notification sent.')]
            : ['type' => 'error', 'message' => __('Test failed: :error', ['error' => (string) $result->error])]);

        return back();
    }
}
