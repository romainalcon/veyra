<?php

namespace App\Domain\Notifications\Channels;

use App\Domain\Notifications\Channels\Contracts\ChannelSender;
use App\Domain\Notifications\Enums\ChannelType;
use Illuminate\Contracts\Container\Container;

final class SenderRegistry
{
    public function __construct(private readonly Container $container) {}

    public function for(ChannelType $type): ChannelSender
    {
        return match ($type) {
            ChannelType::Email => $this->container->make(EmailSender::class),
            ChannelType::Discord => $this->container->make(DiscordSender::class),
            ChannelType::Webhook => $this->container->make(WebhookSender::class),
        };
    }
}
