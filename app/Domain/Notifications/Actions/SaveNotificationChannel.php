<?php

namespace App\Domain\Notifications\Actions;

use App\Domain\Notifications\Enums\ChannelType;
use App\Domain\Notifications\Models\NotificationChannel;
use App\Domain\Organizations\Models\Organization;

class SaveNotificationChannel
{
    /**
     * @param  array{name: string, enabled: bool, is_default: bool, recipients?: list<string>, webhook_url?: string|null}  $data
     */
    public function create(Organization $organization, ChannelType $type, array $data): NotificationChannel
    {
        return $organization->notificationChannels()->create([
            'type' => $type,
            'name' => $data['name'],
            'enabled' => $data['enabled'],
            'is_default' => $data['is_default'],
            'config_encrypted' => $this->config($type, $data, []),
        ]);
    }

    /**
     * Secrets are only replaced when a new value is provided.
     *
     * @param  array{name: string, enabled: bool, is_default: bool, recipients?: list<string>, webhook_url?: string|null}  $data
     */
    public function update(NotificationChannel $channel, array $data): NotificationChannel
    {
        $channel->update([
            'name' => $data['name'],
            'enabled' => $data['enabled'],
            'is_default' => $data['is_default'],
            'config_encrypted' => $this->config($channel->type, $data, $channel->config_encrypted),
        ]);

        return $channel;
    }

    /**
     * @param  array{recipients?: list<string>, webhook_url?: string|null}  $data
     * @param  array<string, mixed>  $current
     * @return array<string, mixed>
     */
    private function config(ChannelType $type, array $data, array $current): array
    {
        return match ($type) {
            ChannelType::Email => ['recipients' => array_values(array_unique(array_map('strtolower', $data['recipients'] ?? [])))],
            ChannelType::Discord => ['webhook_url' => ($data['webhook_url'] ?? null) ?: ($current['webhook_url'] ?? null)],
        };
    }
}
