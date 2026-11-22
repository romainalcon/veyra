<?php

namespace App\Domain\Notifications\Actions;

use App\Domain\Notifications\Channels\WebhookSignature;
use App\Domain\Notifications\Enums\ChannelType;
use App\Domain\Notifications\Models\NotificationChannel;
use LogicException;

/**
 * Replaces the signing secret of a webhook channel; deliveries sent from now
 * on are signed with the new one (ADR-033).
 */
class RegenerateWebhookSecret
{
    /**
     * @return string the new secret, to be shown once
     */
    public function handle(NotificationChannel $channel): string
    {
        if ($channel->type !== ChannelType::Webhook) {
            throw new LogicException('Only webhook channels have a signing secret.');
        }

        $secret = WebhookSignature::generateSecret();

        $channel->update(['config_encrypted' => [...$channel->config_encrypted, 'signing_secret' => $secret]]);

        return $secret;
    }
}
