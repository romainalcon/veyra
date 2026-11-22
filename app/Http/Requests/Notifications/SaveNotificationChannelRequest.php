<?php

namespace App\Http\Requests\Notifications;

use App\Domain\Monitoring\Probes\UrlPolicy;
use App\Domain\Notifications\Channels\GotifySender;
use App\Domain\Notifications\Channels\NtfySender;
use App\Domain\Notifications\Channels\PagerDutySender;
use App\Domain\Notifications\Channels\TelegramSender;
use App\Domain\Notifications\Enums\ChannelType;
use App\Domain\Notifications\Models\NotificationChannel;
use App\Domain\Notifications\Rules\DiscordWebhookUrl;
use App\Domain\Notifications\Rules\GotifyServerUrl;
use App\Domain\Notifications\Rules\NtfyTopicUrl;
use App\Domain\Notifications\Rules\SlackWebhookUrl;
use App\Domain\Notifications\Rules\TeamsWorkflowUrl;
use App\Domain\Notifications\Rules\WebhookUrl;
use App\Domain\Organizations\Models\Organization;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Authorization runs before validation so that non-managers get a 403
 * (the controller re-checks the same NotificationChannelPolicy abilities).
 */
class SaveNotificationChannelRequest extends FormRequest
{
    public function authorize(): bool
    {
        $organization = $this->route('organization');

        if (! $organization instanceof Organization) {
            return false;
        }

        $channel = $this->channel();

        return $channel === null
            ? $this->user()?->can('create', [NotificationChannel::class, $organization]) === true
            : $this->user()?->can('update', [$channel, $organization]) === true;
    }

    protected function prepareForValidation(): void
    {
        $recipients = $this->input('recipients');

        if (is_string($recipients)) {
            $recipients = preg_split('/[\s,;]+/', $recipients, -1, PREG_SPLIT_NO_EMPTY) ?: [];
        }

        $this->merge([
            'recipients' => is_array($recipients) ? array_values(array_map(fn ($value) => strtolower(trim((string) $value)), $recipients)) : [],
            'enabled' => $this->boolean('enabled', true),
            'is_default' => $this->boolean('is_default'),
            'webhook_url' => $this->trimmed('webhook_url'),
            'token' => $this->trimmed('token'),
            'chat_id' => $this->trimmed('chat_id'),
        ]);
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        $type = $this->channelType();
        $required = $this->channel() ? 'nullable' : 'required';
        $serverUrl = [$required, 'bail', 'string', 'max:'.UrlPolicy::MAX_LENGTH];

        return [
            'type' => [$this->channel() ? 'nullable' : 'required', Rule::enum(ChannelType::class)],
            'name' => ['required', 'string', 'min:2', 'max:100'],
            'enabled' => ['boolean'],
            'is_default' => ['boolean'],
            'recipients' => $type === ChannelType::Email
                ? ['required', 'array', 'min:1', 'max:'.config('veyra.notifications.max_email_recipients')]
                : ['array', 'max:0'],
            'recipients.*' => ['email:rfc', 'max:255', 'distinct'],
            'webhook_url' => match ($type) {
                ChannelType::Discord => [$required, 'string', new DiscordWebhookUrl],
                ChannelType::Slack => [$required, 'string', new SlackWebhookUrl],
                ChannelType::Teams => [$required, 'string', new TeamsWorkflowUrl],
                ChannelType::Webhook => [...$serverUrl, app(WebhookUrl::class)],
                // Shape first, then the probe SSRF policy on the destination (ADR-039).
                ChannelType::Ntfy => [...$serverUrl, new NtfyTopicUrl, app(WebhookUrl::class)],
                ChannelType::Gotify => [...$serverUrl, new GotifyServerUrl, app(WebhookUrl::class)],
                default => ['nullable', 'prohibited'],
            },
            'token' => match ($type) {
                ChannelType::Ntfy => ['nullable', 'string', 'regex:'.NtfySender::TOKEN_PATTERN],
                ChannelType::Gotify => [$required, 'string', 'regex:'.GotifySender::TOKEN_PATTERN],
                ChannelType::Telegram => [$required, 'string', 'regex:'.TelegramSender::TOKEN_PATTERN],
                ChannelType::PagerDuty => [$required, 'string', 'regex:'.PagerDutySender::ROUTING_KEY_PATTERN],
                default => ['nullable', 'prohibited'],
            },
            'chat_id' => $type === ChannelType::Telegram
                ? [$required, 'string', 'regex:'.TelegramSender::CHAT_ID_PATTERN]
                : ['nullable', 'prohibited'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'token.regex' => (string) match ($this->channelType()) {
                ChannelType::Telegram => __('Provide the bot token given by @BotFather (123456:ABC…).'),
                ChannelType::PagerDuty => __('Provide the 32-character integration key of a PagerDuty Events API v2 integration.'),
                default => __('The token may only contain letters, digits, ".", "-" and "_".'),
            },
            'chat_id.regex' => (string) __('Provide a numeric chat id (e.g. -1001234567890) or a public channel username (@channel).'),
        ];
    }

    private function trimmed(string $key): ?string
    {
        $value = $this->input($key);

        return is_string($value) ? trim($value) : null;
    }

    public function channelType(): ?ChannelType
    {
        return $this->channel()->type ?? ChannelType::tryFrom((string) $this->input('type'));
    }

    public function channel(): ?NotificationChannel
    {
        $channel = $this->route('notificationChannel');

        return $channel instanceof NotificationChannel ? $channel : null;
    }

    /**
     * @return array{name: string, enabled: bool, is_default: bool, recipients: list<string>, webhook_url: string|null, token: string|null, chat_id: string|null}
     */
    public function payload(): array
    {
        /** @var list<string> $recipients */
        $recipients = array_values((array) $this->validated('recipients', []));

        return [
            'name' => trim((string) $this->validated('name')),
            'enabled' => (bool) $this->validated('enabled'),
            'is_default' => (bool) $this->validated('is_default'),
            'recipients' => $recipients,
            'webhook_url' => $this->validated('webhook_url') ?: null,
            'token' => $this->validated('token') ?: null,
            'chat_id' => $this->validated('chat_id') ?: null,
        ];
    }
}
