<?php

namespace App\Domain\Organizations\Notifications;

use App\Domain\Organizations\Enums\OrganizationRole;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeEncrypted;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class OrganizationInvitationNotification extends Notification implements ShouldBeEncrypted, ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    /**
     * @var list<int>
     */
    public array $backoff = [10, 60, 300];

    public function __construct(
        public readonly string $organizationName,
        public readonly string $inviterName,
        public readonly OrganizationRole $role,
        #[\SensitiveParameter] public readonly string $plainToken,
    ) {
        $this->onQueue('notifications');
    }

    /**
     * @return list<string>
     */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject(__('You have been invited to join :organization on Veyra', ['organization' => $this->organizationName]))
            ->line(__(':inviter invited you to join :organization as :role.', [
                'inviter' => $this->inviterName,
                'organization' => $this->organizationName,
                'role' => $this->role->label(),
            ]))
            ->action(__('Accept invitation'), route('invitations.show', ['token' => $this->plainToken]))
            ->line(__('This invitation expires in :hours hours. If you were not expecting it, you can ignore this e-mail.', [
                'hours' => config('veyra.invitations.ttl_hours'),
            ]));
    }
}
