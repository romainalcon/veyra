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
    /** ntfy topic on any server allowed by the probe SSRF policy (ADR-039). */
    case Ntfy = 'ntfy';
    /** Gotify server allowed by the probe SSRF policy (ADR-039). */
    case Gotify = 'gotify';
    /** Telegram bot message, fixed host (ADR-039). */
    case Telegram = 'telegram';
    /** Microsoft Teams workflow webhook, Microsoft hosts only (ADR-039). */
    case Teams = 'teams';
    /** PagerDuty Events v2, fixed host, trigger / acknowledge / resolve (ADR-039). */
    case PagerDuty = 'pagerduty';

    public function label(): string
    {
        return (string) __(match ($this) {
            self::Email => 'E-mail',
            self::Discord => 'Discord',
            self::Webhook => 'Webhook',
            self::Slack => 'Slack',
            self::Ntfy => 'ntfy',
            self::Gotify => 'Gotify',
            self::Telegram => 'Telegram',
            self::Teams => 'Microsoft Teams',
            self::PagerDuty => 'PagerDuty',
        });
    }
}
