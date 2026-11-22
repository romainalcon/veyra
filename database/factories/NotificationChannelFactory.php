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

    public function slack(string $url = 'https://hooks.slack.com/services/T0123ABCD/B0456EFGH/abcdEFGHijklMNOPqrstUVwx'): static
    {
        return $this->state(fn (): array => [
            'type' => ChannelType::Slack,
            'name' => 'Slack #alerts',
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

    public function ntfy(string $url = 'https://ntfy.example.com/veyra-alerts', ?string $token = 'test-ntfy-token-not-real'): static
    {
        return $this->state(fn (): array => [
            'type' => ChannelType::Ntfy,
            'name' => 'ntfy alerts',
            'config_encrypted' => ['webhook_url' => $url, 'token' => $token],
        ]);
    }

    public function gotify(string $url = 'https://gotify.example.com', string $token = 'testGotifyToken'): static
    {
        return $this->state(fn (): array => [
            'type' => ChannelType::Gotify,
            'name' => 'Gotify',
            'config_encrypted' => ['webhook_url' => $url, 'token' => $token],
        ]);
    }

    public function telegram(string $token = '12345:test-token-not-real-telegram-bot', string $chatId = '-1001234567890'): static
    {
        return $this->state(fn (): array => [
            'type' => ChannelType::Telegram,
            'name' => 'Telegram on-call',
            'config_encrypted' => ['token' => $token, 'chat_id' => $chatId],
        ]);
    }

    public function teams(string $url = 'https://prod-00.westeurope.logic.azure.com:443/workflows/test-workflow/triggers/manual/paths/invoke?api-version=2016-06-01&sig=notarealsigAbcd'): static
    {
        return $this->state(fn (): array => [
            'type' => ChannelType::Teams,
            'name' => 'Teams #ops',
            'config_encrypted' => ['webhook_url' => $url],
        ]);
    }

    public function pagerDuty(string $routingKey = 'testroutingkeynotreal00000000000'): static
    {
        return $this->state(fn (): array => [
            'type' => ChannelType::PagerDuty,
            'name' => 'PagerDuty service',
            'config_encrypted' => ['token' => $routingKey],
        ]);
    }
}
