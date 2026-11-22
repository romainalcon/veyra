<?php

namespace Database\Factories;

use App\Domain\Notifications\Channels\WebhookSignature;
use App\Domain\Notifications\Enums\ChannelType;
use App\Domain\Notifications\Models\NotificationChannel;
use App\Domain\Organizations\Models\Organization;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<NotificationChannel>
 */
class NotificationChannelFactory extends Factory
{
    protected $model = NotificationChannel::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'organization_id' => Organization::factory(),
            'type' => ChannelType::Email,
            'name' => 'On-call e-mail',
            'config_encrypted' => ['recipients' => ['oncall@example.com']],
            'enabled' => true,
            'is_default' => false,
        ];
    }

    public function discord(string $url = 'https://discord.com/api/webhooks/123456789/secret-token-abcd'): static
    {
        return $this->state(fn (): array => [
            'type' => ChannelType::Discord,
            'name' => 'Discord #alerts',
            'config_encrypted' => ['webhook_url' => $url],
        ]);
    }

    public function webhook(string $url = 'https://api.example.com/hooks/veyra?token=secret-query-token', ?string $secret = null): static
    {
        return $this->state(fn (): array => [
            'type' => ChannelType::Webhook,
            'name' => 'Automation webhook',
            'config_encrypted' => ['webhook_url' => $url, 'signing_secret' => $secret ?? WebhookSignature::generateSecret()],
        ]);
    }
}
