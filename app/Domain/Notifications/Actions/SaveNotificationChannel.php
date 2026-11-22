<?php

namespace App\Domain\Notifications\Actions;

use App\Domain\Entitlements\Enums\Feature;
use App\Domain\Entitlements\Services\Quota;
use App\Domain\Notifications\Channels\WebhookSignature;
use App\Domain\Notifications\Enums\ChannelType;
use App\Domain\Notifications\Models\NotificationChannel;
use App\Domain\Organizations\Models\Organization;
use Illuminate\Support\Facades\DB;

class SaveNotificationChannel
{
    public function __construct(private readonly Quota $quota) {}

    /**
     * @param  array{name: string, enabled: bool, is_default: bool, recipients?: list<string>, webhook_url?: string|null, token?: string|null, chat_id?: string|null}  $data
     */
    public function create(Organization $organization, ChannelType $type, array $data): NotificationChannel
    {
        return DB::transaction(function () use ($organization, $type, $data): NotificationChannel {
            $this->quota->ensureCanAdd($organization, Feature::NotificationChannels);

            return $organization->notificationChannels()->create([
                'type' => $type,
                'name' => $data['name'],
                'enabled' => $data['enabled'],
                'is_default' => $data['is_default'],
                'config_encrypted' => $this->config($type, $data, []),
            ]);
        });
    }

    /**
     * Secrets (URLs, tokens) and the Telegram chat are only replaced when a
     * new value is provided.
     *
     * @param  array{name: string, enabled: bool, is_default: bool, recipients?: list<string>, webhook_url?: string|null, token?: string|null, chat_id?: string|null}  $data
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
     * @param  array{recipients?: list<string>, webhook_url?: string|null, token?: string|null, chat_id?: string|null}  $data
     * @param  array<string, mixed>  $current
     * @return array<string, mixed>
     */
    private function config(ChannelType $type, array $data, array $current): array
    {
        return match ($type) {
            ChannelType::Email => ['recipients' => array_values(array_unique(array_map('strtolower', $data['recipients'] ?? [])))],
            ChannelType::Discord, ChannelType::Slack => ['webhook_url' => ($data['webhook_url'] ?? null) ?: ($current['webhook_url'] ?? null)],
            // The signing secret is generated once and only replaced by RegenerateWebhookSecret.
            ChannelType::Webhook => [
                'webhook_url' => ($data['webhook_url'] ?? null) ?: ($current['webhook_url'] ?? null),
                'signing_secret' => $current['signing_secret'] ?? WebhookSignature::generateSecret(),
            ],
            ChannelType::Teams => ['webhook_url' => self::keep('webhook_url', $data, $current)],
            ChannelType::Ntfy, ChannelType::Gotify => [
                'webhook_url' => self::keep('webhook_url', $data, $current),
                'token' => self::keep('token', $data, $current),
            ],
            ChannelType::Telegram => [
                'token' => self::keep('token', $data, $current),
                'chat_id' => self::keep('chat_id', $data, $current),
            ],
            ChannelType::PagerDuty => ['token' => self::keep('token', $data, $current)],
        };
    }

    /**
     * @param  array<string, mixed>  $data
     * @param  array<string, mixed>  $current
     */
    private static function keep(string $key, array $data, array $current): ?string
    {
        $value = $data[$key] ?? null;

        return is_string($value) && $value !== '' ? $value : (is_string($current[$key] ?? null) ? $current[$key] : null);
    }
}
