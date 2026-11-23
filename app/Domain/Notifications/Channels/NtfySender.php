<?php

namespace App\Domain\Notifications\Channels;

use App\Domain\Notifications\Channels\Contracts\ChannelSender;
use App\Domain\Notifications\Enums\NotificationEvent;
use App\Domain\Notifications\Models\NotificationChannel;
use App\Domain\Notifications\Rules\NtfyTopicUrl;

/**
 * Publishes to an ntfy topic with ntfy's JSON API (ADR-039), on ntfy.sh or a
 * self-hosted server allowed by the probe SSRF policy. Plain text only, so
 * untrusted values cannot inject markup; an optional access token is sent
 * as a Bearer token.
 */
class NtfySender implements ChannelSender
{
    public const TOKEN_PATTERN = '/^[A-Za-z0-9_.-]{8,128}$/';

    public function __construct(private readonly PinnedJsonPost $post) {}

    public function send(NotificationChannel $channel, NotificationMessage $message): SendResult
    {
        $url = $channel->webhookUrl();
        $topic = NtfyTopicUrl::parse($url);
        $token = $channel->token();

        if ($url === null || $topic === null || ($token !== null && preg_match(self::TOKEN_PATTERN, $token) !== 1)) {
            return SendResult::permanent('Invalid ntfy configuration.');
        }

        return $this->post->send(
            $url,
            $topic['server'].'/',
            $this->payload($message, $topic['topic']),
            $token === null ? [] : ['Authorization' => "Bearer {$token}"],
            'ntfy',
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function payload(NotificationMessage $message, string $topic): array
    {
        return [
            'topic' => $topic,
            'title' => mb_substr($message->title(), 0, 250),
            'message' => mb_substr($message->plainText()."\n\n".$message->organizationName, 0, 4000),
            'priority' => match ($message->event) {
                NotificationEvent::Opened, NotificationEvent::Reminder, NotificationEvent::Escalated, NotificationEvent::CertificateExpired, NotificationEvent::DomainExpired => 5,
                NotificationEvent::CertificateExpiring, NotificationEvent::DomainExpiring => 4,
                NotificationEvent::Resolved, NotificationEvent::Acknowledged => 3,
                NotificationEvent::Test => 2,
            },
            'tags' => [match ($message->event) {
                NotificationEvent::Opened, NotificationEvent::Reminder, NotificationEvent::Escalated => 'rotating_light',
                NotificationEvent::Resolved => 'white_check_mark',
                NotificationEvent::Acknowledged => 'eyes',
                NotificationEvent::CertificateExpiring, NotificationEvent::CertificateExpired, NotificationEvent::DomainExpiring, NotificationEvent::DomainExpired => 'warning',
                NotificationEvent::Test => 'test_tube',
            }],
            'click' => $message->url,
            'actions' => [['action' => 'view', 'label' => $message->translate('Open in Veyra'), 'url' => $message->url]],
        ];
    }
}
