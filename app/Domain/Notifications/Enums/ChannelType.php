<?php

namespace App\Domain\Notifications\Enums;

enum ChannelType: string
{
    case Email = 'email';
    case Discord = 'discord';

    public function label(): string
    {
        return (string) __(match ($this) {
            self::Email => 'E-mail',
            self::Discord => 'Discord',
        });
    }
}
