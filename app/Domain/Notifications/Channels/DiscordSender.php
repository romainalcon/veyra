<?php

namespace App\Domain\Notifications\Channels;

use App\Domain\Notifications\Channels\Contracts\ChannelSender;
use App\Domain\Notifications\Enums\NotificationEvent;
use App\Domain\Notifications\Models\NotificationChannel;
use App\Domain\Notifications\Rules\DiscordWebhookUrl;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Factory as HttpFactory;
use Throwable;

class DiscordSender implements ChannelSender
{
    public const TIMEOUT_SECONDS = 5;

    public function __construct(private readonly HttpFactory $http) {}

    public function send(NotificationChannel $channel, NotificationMessage $message): SendResult
    {
        $url = $channel->webhookUrl();

        // Re-validated at send time: stored configuration is never trusted blindly.
        if (! DiscordWebhookUrl::isValid($url)) {
            return SendResult::permanent('Invalid Discord webhook configuration.');
        }

        try {
            $response = $this->http
                ->timeout(self::TIMEOUT_SECONDS)
                ->connectTimeout(self::TIMEOUT_SECONDS)
                ->withOptions(['allow_redirects' => false, 'proxy' => '', 'protocols' => ['https']])
                ->acceptJson()
                ->post((string) $url, $this->payload($message));
        } catch (ConnectionException) {
            return SendResult::retryable('Could not reach Discord.');
        } catch (Throwable $exception) {
            return SendResult::retryable('Unexpected error: '.class_basename($exception));
        }

        $status = $response->status();

        return match (true) {
            $response->successful() => SendResult::sent(),
            $status === 429 => SendResult::retryable('Rate limited by Discord (HTTP 429).', $this->retryAfter($response->json('retry_after'), $response->header('Retry-After'))),
            $status >= 500 => SendResult::retryable("Discord error (HTTP {$status})."),
            default => SendResult::permanent("Discord rejected the webhook (HTTP {$status})."),
        };
    }

    /**
     * @return array<string, mixed>
     */
    public function payload(NotificationMessage $message): array
    {
        $fields = array_values(array_filter([
            ['name' => 'Monitor', 'value' => $message->monitorName, 'inline' => true],
            $message->cause ? ['name' => 'Cause', 'value' => mb_substr($message->cause, 0, 256), 'inline' => true] : null,
            $message->durationSeconds !== null && $message->event !== NotificationEvent::Test
                ? ['name' => 'Duration', 'value' => NotificationMessage::humanDuration($message->durationSeconds), 'inline' => true]
                : null,
        ]));

        return [
            'username' => 'Veyra',
            // Never ping anyone from untrusted content (monitor names, causes).
            'allowed_mentions' => ['parse' => []],
            'embeds' => [[
                'title' => mb_substr($message->title(), 0, 256),
                'description' => mb_substr($message->summary(), 0, 2048),
                'url' => $message->url,
                'color' => $message->event->color(),
                'fields' => $fields,
                'footer' => ['text' => $message->organizationName],
                'timestamp' => now()->toIso8601String(),
            ]],
        ];
    }

    private function retryAfter(mixed $bodyValue, string $header): int
    {
        $seconds = is_numeric($bodyValue) ? (float) $bodyValue : (is_numeric($header) ? (float) $header : 30.0);

        return (int) min(3600, max(1, ceil($seconds)));
    }
}
