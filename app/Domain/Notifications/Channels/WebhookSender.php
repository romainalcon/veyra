<?php

namespace App\Domain\Notifications\Channels;

use App\Domain\Monitoring\Enums\ProbeErrorCode;
use App\Domain\Monitoring\Probes\Destination;
use App\Domain\Monitoring\Probes\DestinationRejected;
use App\Domain\Monitoring\Probes\PinnedConnection;
use App\Domain\Monitoring\Probes\ProbeSettings;
use App\Domain\Monitoring\Probes\ResponseTooLarge;
use App\Domain\Notifications\Channels\Contracts\ChannelSender;
use App\Domain\Notifications\Models\NotificationChannel;
use Carbon\CarbonImmutable;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Support\Str;
use Throwable;

/**
 * Posts a signed, versioned JSON payload to an organization endpoint
 * (ADR-033). The destination goes through the probe SSRF policy before each
 * send and the connection is pinned to the validated IP; redirects are never
 * followed and the response body is ignored. Errors never contain the URL.
 */
class WebhookSender implements ChannelSender
{
    public const PAYLOAD_VERSION = 1;

    public const TIMEOUT_SECONDS = 5;

    public const MAX_RESPONSE_BYTES = 65_536;

    public function __construct(
        private readonly HttpFactory $http,
        private readonly WebhookDestination $destinations,
        private readonly ProbeSettings $settings,
    ) {}

    public function send(NotificationChannel $channel, NotificationMessage $message): SendResult
    {
        $url = $channel->webhookUrl();
        $secret = $channel->signingSecret();

        if ($url === null || ! WebhookSignature::isValidSecret($secret)) {
            return SendResult::permanent('Invalid webhook configuration.');
        }

        try {
            $destination = $this->destinations->inspect($url);
        } catch (DestinationRejected $exception) {
            return $exception->errorCode === ProbeErrorCode::DnsFailure
                ? SendResult::retryable('Could not resolve the webhook host.')
                : SendResult::permanent('Webhook destination rejected: '.$exception->getMessage());
        }

        $id = $message->deliveryId ?? (string) Str::uuid7();
        $timestamp = CarbonImmutable::now()->getTimestamp();
        $body = (string) json_encode($this->payload($message, $id), JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

        try {
            $response = $this->http
                ->withOptions($this->requestOptions($destination))
                ->withUserAgent($this->settings->userAgent)
                ->withHeaders([
                    'webhook-id' => $id,
                    'webhook-timestamp' => (string) $timestamp,
                    'webhook-signature' => WebhookSignature::sign((string) $secret, $id, $timestamp, $body),
                ])
                ->withBody($body, 'application/json')
                ->post($destination->url->url);
        } catch (Throwable $exception) {
            return match (true) {
                self::causedBy($exception, ResponseTooLarge::class) => SendResult::permanent('Webhook response exceeds the size limit.'),
                $exception instanceof ConnectionException => SendResult::retryable('Could not reach the webhook endpoint.'),
                default => SendResult::retryable('Unexpected error: '.class_basename($exception)),
            };
        }

        $status = $response->status();

        return match (true) {
            $response->successful() => SendResult::sent(),
            $status === 429 => SendResult::retryable('Rate limited by the webhook endpoint (HTTP 429).', $this->retryAfter($response->header('Retry-After'))),
            $status === 408, $status >= 500 => SendResult::retryable("Webhook endpoint error (HTTP {$status})."),
            $status >= 300 && $status < 400 => SendResult::permanent("Webhook endpoint redirected (HTTP {$status}); redirects are not followed."),
            default => SendResult::permanent("Webhook endpoint rejected the notification (HTTP {$status})."),
        };
    }

    /**
     * Public so the pinning and hardening options can be asserted in tests
     * (they are invisible to Http::fake).
     *
     * @return array<string, mixed>
     */
    public function requestOptions(Destination $destination): array
    {
        return PinnedConnection::options($destination, self::TIMEOUT_SECONDS, self::TIMEOUT_SECONDS, self::MAX_RESPONSE_BYTES, [$destination->url->scheme]);
    }

    /**
     * Machine-readable fields are stable and in English; only `title` and
     * `summary` follow the organization language. Never contains the
     * monitored URL or host, request headers, recipients or secrets.
     *
     * @return array<string, mixed>
     */
    public function payload(NotificationMessage $message, string $id): array
    {
        return [
            'version' => self::PAYLOAD_VERSION,
            'id' => $id,
            'event' => $message->event->webhookName(),
            'occurred_at' => self::iso($message->occurredAt) ?? CarbonImmutable::now()->toIso8601ZuluString(),
            'organization' => [
                'id' => $message->organizationId,
                'slug' => $message->organizationSlug,
                'name' => $message->organizationName,
            ],
            'monitor' => $message->monitorId === null ? null : [
                'id' => $message->monitorId,
                'name' => $message->monitorName,
                'type' => $message->monitorType,
            ],
            'incident' => $message->incidentId === null ? null : [
                'id' => $message->incidentId,
                'started_at' => self::iso($message->startedAt),
                'resolved_at' => self::iso($message->resolvedAt),
                'acknowledged_at' => self::iso($message->acknowledgedAt),
                'acknowledged_by' => $message->acknowledgedBy,
                'duration_seconds' => $message->durationSeconds,
                'cause' => $message->rawCause,
            ],
            'escalation' => $message->escalationStep === null ? null : [
                'step' => $message->escalationStep,
            ],
            'certificate' => $message->certificateExpiresAt === null ? null : [
                'expires_at' => self::iso($message->certificateExpiresAt),
                'issuer' => $message->certificateIssuer,
            ],
            'domain' => $message->domainExpiresAt === null ? null : [
                'name' => $message->domainName,
                'expires_at' => self::iso($message->domainExpiresAt),
            ],
            'title' => $message->title(),
            'summary' => $message->summary(),
            'url' => $message->url,
        ];
    }

    /**
     * Stored timestamps are UTC (`Y-m-d H:i:s`).
     */
    private static function iso(?string $dateTime): ?string
    {
        return $dateTime === null ? null : CarbonImmutable::parse($dateTime, 'UTC')->toIso8601ZuluString();
    }

    private function retryAfter(string $header): int
    {
        return (int) min(3600, max(1, is_numeric($header) ? ceil((float) $header) : 30));
    }

    /**
     * @param  class-string<Throwable>  $class
     */
    private static function causedBy(Throwable $exception, string $class): bool
    {
        for ($current = $exception; $current !== null; $current = $current->getPrevious()) {
            if ($current instanceof $class) {
                return true;
            }
        }

        return false;
    }
}
