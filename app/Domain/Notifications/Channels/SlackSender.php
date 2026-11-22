<?php

namespace App\Domain\Notifications\Channels;

use App\Domain\Notifications\Channels\Contracts\ChannelSender;
use App\Domain\Notifications\Enums\NotificationEvent;
use App\Domain\Notifications\Models\NotificationChannel;
use App\Domain\Notifications\Rules\SlackWebhookUrl;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Factory as HttpFactory;
use Throwable;

/**
 * Posts a Block Kit message to a Slack incoming webhook (ADR-034).
 */
class SlackSender implements ChannelSender
{
    public const TIMEOUT_SECONDS = 5;

    public function __construct(private readonly HttpFactory $http) {}

    public function send(NotificationChannel $channel, NotificationMessage $message): SendResult
    {
        $url = $channel->webhookUrl();

        // Re-validated at send time: stored configuration is never trusted blindly.
        if (! SlackWebhookUrl::isValid($url)) {
            return SendResult::permanent('Invalid Slack webhook configuration.');
        }

        try {
            $response = $this->http
                ->timeout(self::TIMEOUT_SECONDS)
                ->connectTimeout(self::TIMEOUT_SECONDS)
                ->withOptions(['allow_redirects' => false, 'proxy' => '', 'protocols' => ['https']])
                ->post((string) $url, $this->payload($message));
        } catch (ConnectionException) {
            return SendResult::retryable('Could not reach Slack.');
        } catch (Throwable $exception) {
            return SendResult::retryable('Unexpected error: '.class_basename($exception));
        }

        $status = $response->status();

        return match (true) {
            $response->successful() => SendResult::sent(),
            $status === 429 => SendResult::retryable('Rate limited by Slack (HTTP 429).', $this->retryAfter($response->header('Retry-After'))),
            $status >= 500 => SendResult::retryable("Slack error (HTTP {$status})."),
            default => SendResult::permanent("Slack rejected the webhook (HTTP {$status}{$this->errorCode($response->body())})."),
        };
    }

    /**
     * Every untrusted value (monitor and organization names, causes, issuers)
     * is escaped so it can never mention anyone or forge a link.
     *
     * @return array<string, mixed>
     */
    public function payload(NotificationMessage $message): array
    {
        $fields = array_values(array_filter([
            $this->field($message->translate('Monitor'), $message->monitorName),
            $message->cause ? $this->field($message->translate('Cause'), mb_substr($message->cause, 0, 256)) : null,
            $message->durationSeconds !== null && ! in_array($message->event, [NotificationEvent::Test, NotificationEvent::CertificateExpiring, NotificationEvent::CertificateExpired], true)
                ? $this->field($message->translate('Duration'), NotificationMessage::humanDuration($message->durationSeconds, $message->locale))
                : null,
            $message->certificateExpiresAt ? $this->field($message->translate('Expires'), $message->certificateExpiresAt.' UTC') : null,
            $message->certificateIssuer ? $this->field($message->translate('Issuer'), mb_substr($message->certificateIssuer, 0, 256)) : null,
        ]));

        return [
            'text' => mb_substr(self::escape($message->title()), 0, 3000),
            'attachments' => [[
                'color' => sprintf('#%06x', $message->event->color()),
                'blocks' => [
                    ['type' => 'section', 'text' => ['type' => 'mrkdwn', 'text' => '*'.mb_substr(self::escape($message->title()), 0, 2900).'*']],
                    ['type' => 'section', 'text' => ['type' => 'mrkdwn', 'text' => mb_substr(self::escape($message->summary()), 0, 3000)], 'fields' => $fields],
                    ['type' => 'context', 'elements' => [['type' => 'mrkdwn', 'text' => mb_substr(self::escape($message->organizationName), 0, 2000)]]],
                    ['type' => 'actions', 'elements' => [[
                        'type' => 'button',
                        'text' => ['type' => 'plain_text', 'text' => $message->translate('Open in Veyra')],
                        'url' => $message->url,
                    ]]],
                ],
            ]],
        ];
    }

    /**
     * Slack mrkdwn control characters; `<…>` would otherwise allow `<!channel>`, `<@U…>` or links.
     */
    public static function escape(string $text): string
    {
        return str_replace(['&', '<', '>'], ['&amp;', '&lt;', '&gt;'], $text);
    }

    /**
     * @return array{type: string, text: string}
     */
    private function field(string $label, string $value): array
    {
        return ['type' => 'mrkdwn', 'text' => mb_substr('*'.self::escape($label)."*\n".self::escape($value), 0, 2000)];
    }

    /**
     * Slack answers errors with a short code (`no_service`, `channel_is_archived`…); anything else is not stored.
     */
    private function errorCode(string $body): string
    {
        $body = trim($body);

        return preg_match('/^[a-z_]{1,64}$/', $body) === 1 ? ": {$body}" : '';
    }

    private function retryAfter(string $header): int
    {
        $seconds = is_numeric($header) ? (float) $header : 30.0;

        return (int) min(3600, max(1, ceil($seconds)));
    }
}
