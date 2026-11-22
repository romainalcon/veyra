<?php

namespace App\Domain\Notifications\Enums;

enum ChannelType: string
{
    case Email = 'email';
    case Discord = 'discord';
    /** Signed JSON POST to any allowed HTTPS endpoint (ADR-033). */
    case Webhook = 'webhook';
    /** Slack incoming webhook, host allow-listed like Discord (ADR-034). */
    case Slack = 'slack';

    public function label(): string
    {
        return (string) __(match ($this) {
            self::Email => 'E-mail',
            self::Discord => 'Discord',
            self::Webhook => 'Webhook',
            self::Slack => 'Slack',
        });
    }
}
