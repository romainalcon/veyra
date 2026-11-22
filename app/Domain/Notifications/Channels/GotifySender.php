<?php

namespace App\Domain\Notifications\Channels;

use App\Domain\Notifications\Channels\Contracts\ChannelSender;
use App\Domain\Notifications\Enums\NotificationEvent;
use App\Domain\Notifications\Models\NotificationChannel;
use App\Domain\Notifications\Rules\GotifyServerUrl;

/**
 * Creates a Gotify message (ADR-039) on a server allowed by the probe SSRF
 * policy. The application token travels in the `X-Gotify-Key` header, never
 * in the URL; the message is plain text.
 */
class GotifySender implements ChannelSender
{
    public const TOKEN_PATTERN = '/^[A-Za-z0-9_.-]{8,128}$/';

    public function __construct(private readonly PinnedJsonPost $post) {}

    public function send(NotificationChannel $channel, NotificationMessage $message): SendResult
    {
        $url = $channel->webhookUrl();
        $token = $channel->token();

        if ($url === null || ! GotifyServerUrl::isValid($url) || $token === null || preg_match(self::TOKEN_PATTERN, $token) !== 1) {
            return SendResult::permanent('Invalid Gotify configuration.');
        }

        return $this->post->send($url, GotifyServerUrl::messageUrl($url), $this->payload($message), ['X-Gotify-Key' => $token], 'Gotify');
    }

    /**
     * @return array<string, mixed>
     */
    public function payload(NotificationMessage $message): array
    {
        return [
            'title' => mb_substr($message->title(), 0, 250),
            'message' => mb_substr($message->plainText()."\n\n".$message->organizationName, 0, 4000),
            'priority' => match ($message->event) {
                NotificationEvent::Opened, NotificationEvent::Reminder, NotificationEvent::CertificateExpired, NotificationEvent::DomainExpired => 8,
                NotificationEvent::CertificateExpiring, NotificationEvent::DomainExpiring => 6,
                NotificationEvent::Resolved, NotificationEvent::Acknowledged => 5,
                NotificationEvent::Test => 2,
            },
            'extras' => [
                'client::display' => ['contentType' => 'text/plain'],
                'client::notification' => ['click' => ['url' => $message->url]],
            ],
        ];
    }
}
