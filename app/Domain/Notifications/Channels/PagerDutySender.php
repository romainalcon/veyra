<?php

namespace App\Domain\Notifications\Channels;

use App\Domain\Notifications\Channels\Contracts\ChannelSender;
use App\Domain\Notifications\Enums\NotificationEvent;
use App\Domain\Notifications\Models\NotificationChannel;
use Carbon\CarbonImmutable;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Http\Client\Response;
use Throwable;

/**
 * PagerDuty Events API v2 (ADR-039). A Veyra incident maps to one PagerDuty
 * alert through a stable dedup key: opening triggers it, acknowledgement
 * acknowledges it and recovery resolves it, so retries and replays never
 * create duplicates. Reminders are never queued for PagerDuty, which runs
 * its own escalations; an escalation step that targets PagerDuty triggers the
 * incident's alert (a no-op when it is already triggered, ADR-045). Certificate and domain warnings trigger an alert of their own;
 * a test triggers then resolves a throwaway alert.
 */
class PagerDutySender implements ChannelSender
{
    public const ENDPOINT = 'https://events.pagerduty.com/v2/enqueue';

    public const TIMEOUT_SECONDS = 5;

    public const ROUTING_KEY_PATTERN = '/^[A-Za-z0-9]{32}$/';

    public function __construct(private readonly HttpFactory $http) {}

    public static function isValidRoutingKey(mixed $key): bool
    {
        return is_string($key) && preg_match(self::ROUTING_KEY_PATTERN, $key) === 1;
    }

    public function send(NotificationChannel $channel, NotificationMessage $message): SendResult
    {
        $routingKey = $channel->token();

        if (! self::isValidRoutingKey($routingKey)) {
            return SendResult::permanent('Invalid PagerDuty configuration.');
        }

        /** @var string $routingKey */
        if ($message->event === NotificationEvent::Reminder) {
            return SendResult::permanent('Reminders are not sent to PagerDuty.');
        }

        // Messages reflect the incident at send time: a retried trigger or acknowledgement
        // arriving after recovery would otherwise open an alert that nothing resolves.
        if ($message->resolvedAt !== null && in_array($message->event, [NotificationEvent::Opened, NotificationEvent::Escalated, NotificationEvent::Acknowledged], true)) {
            return SendResult::permanent('Incident already resolved; PagerDuty event skipped.');
        }

        if ($message->event !== NotificationEvent::Test) {
            return $this->post($this->event($message, $routingKey, $this->action($message->event)), $routingKey);
        }

        $result = $this->post($this->event($message, $routingKey, 'trigger'), $routingKey);

        return $result->sent ? $this->post($this->event($message, $routingKey, 'resolve'), $routingKey) : $result;
    }

    /**
     * Dedup key, stable across retries: the incident for incident events,
     * the delivery row (one per alert and channel) otherwise.
     */
    public static function dedupKey(NotificationMessage $message): string
    {
        return $message->incidentId !== null
            ? "veyra-incident-{$message->incidentId}"
            : 'veyra-'.str_replace('_', '-', $message->event->value).'-'.$message->deliveryId;
    }

    /**
     * Event body. Acknowledge and resolve only carry the dedup key.
     *
     * @return array<string, mixed>
     */
    public function event(NotificationMessage $message, string $routingKey, string $action): array
    {
        $event = ['routing_key' => $routingKey, 'event_action' => $action, 'dedup_key' => self::dedupKey($message)];

        if ($action !== 'trigger') {
            return $event;
        }

        return $event + [
            'payload' => array_filter([
                'summary' => mb_substr($message->title(), 0, 1024),
                'source' => mb_substr($message->monitorName, 0, 255),
                'severity' => match ($message->event) {
                    NotificationEvent::Opened, NotificationEvent::Escalated => 'critical',
                    NotificationEvent::CertificateExpired, NotificationEvent::DomainExpired => 'error',
                    NotificationEvent::CertificateExpiring, NotificationEvent::DomainExpiring => 'warning',
                    default => 'info',
                },
                'timestamp' => CarbonImmutable::parse($message->occurredAt ?? 'now', 'UTC')->toIso8601ZuluString(),
                'component' => mb_substr($message->monitorName, 0, 255),
                'group' => mb_substr($message->organizationName, 0, 255),
                'class' => $message->rawCause,
                'custom_details' => [
                    'summary' => $message->summary(),
                    ...array_column($message->facts(), 'value', 'label'),
                    $message->translate('Organization') => $message->organizationName,
                ],
            ], fn (mixed $value): bool => $value !== null),
            'links' => [['href' => $message->url, 'text' => $message->translate('Open in Veyra')]],
            'client' => 'Veyra',
            'client_url' => $message->url,
        ];
    }

    private function action(NotificationEvent $event): string
    {
        return match ($event) {
            NotificationEvent::Acknowledged => 'acknowledge',
            NotificationEvent::Resolved => 'resolve',
            default => 'trigger',
        };
    }

    /**
     * @param  array<string, mixed>  $event
     */
    private function post(array $event, string $routingKey): SendResult
    {
        try {
            $response = $this->http
                ->timeout(self::TIMEOUT_SECONDS)
                ->connectTimeout(self::TIMEOUT_SECONDS)
                ->withOptions(['allow_redirects' => false, 'proxy' => '', 'protocols' => ['https']])
                ->acceptJson()
                ->post(self::ENDPOINT, $event);
        } catch (ConnectionException) {
            return SendResult::retryable('Could not reach PagerDuty.');
        } catch (Throwable $exception) {
            return SendResult::retryable('Unexpected error: '.class_basename($exception));
        }

        return $this->classify($response, $routingKey);
    }

    private function classify(Response $response, string $routingKey): SendResult
    {
        $status = $response->status();

        return match (true) {
            $response->successful() => SendResult::sent(),
            $status === 429 => SendResult::retryable('Rate limited by PagerDuty (HTTP 429).', ResponseClassifier::retryAfter($response->header('Retry-After'))),
            $status === 408, $status >= 500 => SendResult::retryable("PagerDuty error (HTTP {$status})."),
            default => SendResult::permanent("PagerDuty rejected the event (HTTP {$status}".ResponseClassifier::safeDetail($response->json('message'), [$routingKey]).').'),
        };
    }
}
