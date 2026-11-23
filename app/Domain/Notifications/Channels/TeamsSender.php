<?php

namespace App\Domain\Notifications\Channels;

use App\Domain\Notifications\Channels\Contracts\ChannelSender;
use App\Domain\Notifications\Enums\NotificationEvent;
use App\Domain\Notifications\Models\NotificationChannel;
use App\Domain\Notifications\Rules\TeamsWorkflowUrl;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Factory as HttpFactory;
use Throwable;

/**
 * Posts an Adaptive Card to a Microsoft Teams workflow webhook (ADR-039).
 * Untrusted values are rendered with `TextRun` elements, which Teams never
 * parses as Markdown, so names cannot forge links or formatting.
 */
class TeamsSender implements ChannelSender
{
    public const TIMEOUT_SECONDS = 5;

    public function __construct(private readonly HttpFactory $http) {}

    public function send(NotificationChannel $channel, NotificationMessage $message): SendResult
    {
        $url = $channel->webhookUrl();

        // Re-validated at send time: stored configuration is never trusted blindly.
        if (! TeamsWorkflowUrl::isValid($url)) {
            return SendResult::permanent('Invalid Microsoft Teams workflow configuration.');
        }

        try {
            $response = $this->http
                ->timeout(self::TIMEOUT_SECONDS)
                ->connectTimeout(self::TIMEOUT_SECONDS)
                ->withOptions(['allow_redirects' => false, 'proxy' => '', 'protocols' => ['https']])
                ->post((string) $url, $this->payload($message));
        } catch (ConnectionException) {
            return SendResult::retryable('Could not reach Microsoft Teams.');
        } catch (Throwable $exception) {
            return SendResult::retryable('Unexpected error: '.class_basename($exception));
        }

        return ResponseClassifier::classify($response, 'Microsoft Teams');
    }

    /**
     * @return array<string, mixed>
     */
    public function payload(NotificationMessage $message): array
    {
        $facts = array_map(fn (array $fact): array => [
            'type' => 'RichTextBlock',
            'spacing' => 'Small',
            'inlines' => [
                ['type' => 'TextRun', 'text' => $message->translate(':label:', ['label' => $fact['label']]).' ', 'weight' => 'Bolder'],
                ['type' => 'TextRun', 'text' => $fact['value']],
            ],
        ], $message->facts());

        return [
            'type' => 'message',
            'attachments' => [[
                'contentType' => 'application/vnd.microsoft.card.adaptive',
                'content' => [
                    '$schema' => 'http://adaptivecards.io/schemas/adaptive-card.json',
                    'type' => 'AdaptiveCard',
                    'version' => '1.4',
                    'msteams' => ['width' => 'Full'],
                    'body' => [
                        ['type' => 'RichTextBlock', 'inlines' => [[
                            'type' => 'TextRun',
                            'text' => mb_substr($message->title(), 0, 300),
                            'weight' => 'Bolder',
                            'size' => 'Medium',
                            'color' => $this->color($message->event),
                        ]]],
                        ['type' => 'RichTextBlock', 'inlines' => [['type' => 'TextRun', 'text' => mb_substr($message->summary(), 0, 1000)]]],
                        ...$facts,
                        ['type' => 'RichTextBlock', 'separator' => true, 'inlines' => [[
                            'type' => 'TextRun', 'text' => mb_substr($message->organizationName, 0, 200), 'isSubtle' => true, 'size' => 'Small',
                        ]]],
                    ],
                    'actions' => [['type' => 'Action.OpenUrl', 'title' => $message->translate('Open in Veyra'), 'url' => $message->url]],
                ],
            ]],
        ];
    }

    private function color(NotificationEvent $event): string
    {
        return match ($event) {
            NotificationEvent::Opened, NotificationEvent::Reminder, NotificationEvent::Escalated, NotificationEvent::CertificateExpired, NotificationEvent::DomainExpired => 'Attention',
            NotificationEvent::Resolved => 'Good',
            NotificationEvent::Acknowledged, NotificationEvent::CertificateExpiring, NotificationEvent::DomainExpiring => 'Warning',
            NotificationEvent::Test => 'Default',
        };
    }
}
