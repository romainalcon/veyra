<?php

namespace App\Domain\Notifications\Actions;

use App\Domain\Notifications\Channels\MessageFactory;
use App\Domain\Notifications\Channels\SenderRegistry;
use App\Domain\Notifications\Channels\SendResult;
use App\Domain\Notifications\Models\NotificationChannel;

class SendTestNotification
{
    public function __construct(private readonly SenderRegistry $senders) {}

    public function handle(NotificationChannel $channel): SendResult
    {
        return $this->senders->for($channel->type)->send($channel, MessageFactory::test($channel->organization));
    }
}
