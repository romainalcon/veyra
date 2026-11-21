<?php

namespace App\Domain\Notifications\Channels\Contracts;

use App\Domain\Notifications\Channels\NotificationMessage;
use App\Domain\Notifications\Channels\SendResult;
use App\Domain\Notifications\Models\NotificationChannel;

interface ChannelSender
{
    public function send(NotificationChannel $channel, NotificationMessage $message): SendResult;
}
