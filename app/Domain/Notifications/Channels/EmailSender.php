<?php

namespace App\Domain\Notifications\Channels;

use App\Domain\Notifications\Channels\Contracts\ChannelSender;
use App\Domain\Notifications\Mail\IncidentNotificationMail;
use App\Domain\Notifications\Models\NotificationChannel;
use Illuminate\Contracts\Mail\Mailer;
use Throwable;

class EmailSender implements ChannelSender
{
    public function __construct(private readonly Mailer $mailer) {}

    public function send(NotificationChannel $channel, NotificationMessage $message): SendResult
    {
        $recipients = $channel->recipients();

        if ($recipients === []) {
            return SendResult::permanent('No recipients configured.');
        }

        try {
            $this->mailer->to($recipients)->send(new IncidentNotificationMail($message));
        } catch (Throwable $exception) {
            // Transport messages can include addresses; keep only the class name.
            return SendResult::retryable('Mail transport error: '.class_basename($exception));
        }

        return SendResult::sent();
    }
}
