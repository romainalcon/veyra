<?php

namespace App\Domain\Notifications\Channels;

use App\Domain\Monitoring\Enums\ProbeErrorCode;
use App\Domain\Monitoring\Probes\DestinationRejected;
use App\Domain\Monitoring\Probes\PinnedConnection;
use App\Domain\Monitoring\Probes\ProbeSettings;
use App\Domain\Monitoring\Probes\ResponseTooLarge;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Factory as HttpFactory;
use Throwable;

/**
 * JSON POST to a server chosen by the organization (ntfy, Gotify — ADR-039),
 * under the same rules as outgoing webhooks (ADR-033): the configured URL
 * goes through the probe SSRF policy before each send, the connection is
 * pinned to the validated IP, redirects are not followed, proxies are
 * ignored and the response is capped. `$path` is appended to the validated
 * origin, so the pinned host and port never change.
 */
class PinnedJsonPost
{
    public const TIMEOUT_SECONDS = 5;

    public const MAX_RESPONSE_BYTES = 65_536;

    /**
     * Options of the last request, for tests (they are invisible to Http::fake).
     *
     * @var array<string, mixed>
     */
    public array $lastOptions = [];

    public function __construct(
        private readonly HttpFactory $http,
        private readonly WebhookDestination $destinations,
        private readonly ProbeSettings $settings,
    ) {}

    /**
     * @param  array<string, mixed>  $payload
     * @param  array<string, string>  $headers
     */
    public function send(string $validatedUrl, string $targetUrl, array $payload, array $headers, string $service): SendResult
    {
        try {
            $destination = $this->destinations->inspect($validatedUrl);
        } catch (DestinationRejected $exception) {
            return $exception->errorCode === ProbeErrorCode::DnsFailure
                ? SendResult::retryable("Could not resolve the {$service} host.")
                : SendResult::permanent("{$service} destination rejected: ".$exception->getMessage());
        }

        $this->lastOptions = PinnedConnection::options($destination, self::TIMEOUT_SECONDS, self::TIMEOUT_SECONDS, self::MAX_RESPONSE_BYTES, [$destination->url->scheme]);

        try {
            $response = $this->http
                ->withOptions($this->lastOptions)
                ->withUserAgent($this->settings->userAgent)
                ->withHeaders($headers)
                ->asJson()
                ->post($targetUrl, $payload);
        } catch (Throwable $exception) {
            return match (true) {
                ResponseClassifier::causedBy($exception, ResponseTooLarge::class) => SendResult::permanent("{$service} response exceeds the size limit."),
                $exception instanceof ConnectionException => SendResult::retryable("Could not reach the {$service} server."),
                default => SendResult::retryable('Unexpected error: '.class_basename($exception)),
            };
        }

        return ResponseClassifier::classify($response, $service);
    }

    /**
     * Origin (`scheme://host[:port]`) of an absolute URL, as written.
     */
    public static function origin(string $url): string
    {
        $schemeEnd = strpos($url, '://');

        if ($schemeEnd === false) {
            return $url;
        }

        $pathStart = strpos($url, '/', $schemeEnd + 3);

        return $pathStart === false ? $url : substr($url, 0, $pathStart);
    }
}
