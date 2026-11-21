<?php

namespace App\Http\Requests\Notifications;

use App\Domain\Notifications\Enums\ChannelType;
use App\Domain\Notifications\Models\NotificationChannel;
use App\Domain\Notifications\Rules\DiscordWebhookUrl;
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
            'webhook_url' => is_string($this->input('webhook_url')) ? trim($this->input('webhook_url')) : null,
        ]);
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        $type = $this->channelType();

        return [
            'type' => [$this->channel() ? 'nullable' : 'required', Rule::enum(ChannelType::class)],
            'name' => ['required', 'string', 'min:2', 'max:100'],
            'enabled' => ['boolean'],
            'is_default' => ['boolean'],
            'recipients' => $type === ChannelType::Email
                ? ['required', 'array', 'min:1', 'max:'.config('veyra.notifications.max_email_recipients')]
                : ['array', 'max:0'],
            'recipients.*' => ['email:rfc', 'max:255', 'distinct'],
            'webhook_url' => $type === ChannelType::Discord
                ? [$this->channel() ? 'nullable' : 'required', 'string', new DiscordWebhookUrl]
                : ['nullable', 'prohibited'],
        ];
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
     * @return array{name: string, enabled: bool, is_default: bool, recipients: list<string>, webhook_url: string|null}
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
        ];
    }
}
