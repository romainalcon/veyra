<?php

namespace App\Domain\Monitoring\Probes;

use App\Domain\Monitoring\Certificates\CertificateParser;
use App\Domain\Monitoring\DTOs\CheckTimings;
use App\Domain\Monitoring\DTOs\ExpectedStatuses;
use App\Domain\Monitoring\DTOs\ProbeResult;
use App\Domain\Monitoring\Enums\HttpMethod;
use App\Domain\Monitoring\Enums\ProbeErrorCode;
use App\Domain\Monitoring\Models\MonitorHttpConfig;
use App\Domain\Monitoring\Support\UrlRedactor;
use Closure;
use GuzzleHttp\Exception\ConnectTimeoutException;
use GuzzleHttp\Exception\NetworkTimeoutException;
use GuzzleHttp\Psr7\Uri;
use GuzzleHttp\Psr7\UriResolver;
use GuzzleHttp\TransferStats;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Executes one HTTP check with SSRF protections:
 * - every hop (initial URL and redirects) goes through DestinationGuard;
 * - the connection is pinned to the validated IP (CURLOPT_RESOLVE), which
 *   prevents DNS rebinding between validation and connection;
 * - redirects are followed manually and bounded;
 * - proxies from the environment are ignored;
 * - responses are capped at MAX_RESPONSE_BYTES (Content-Length and progress
 *   callbacks); the status code and, optionally, a keyword are evaluated;
 * - custom headers, basic auth and the request body are only sent to the
 *   monitored host; 303 (and 301/302 for methods with a body) become GET
 *   without body, as browsers do (ADR-037);
 * - each check carries its phase timings (DNS measured around the validated
 *   resolution, the rest from the cURL transfer info of the final hop) and a
 *   failing response carries bounded, allow-listed evidence (ADR-050).
 */
class HttpProbe
{
    public const MAX_CONNECT_TIMEOUT = 10;

    public const MAX_RESPONSE_BYTES = 1_048_576;

    public function __construct(
        private readonly HttpFactory $http,
        private readonly DestinationGuard $guard,
        private readonly ProbeSettings $settings,
    ) {}

    public function run(MonitorHttpConfig $config, int $timeoutSeconds): ProbeResult
    {
        $result = $this->probe(
            url: $config->url,
            method: $config->method,
            headers: $config->requestHeaders(),
            expectedStatuses: $config->expected_statuses,
            followRedirects: $config->follow_redirects,
            timeoutSeconds: $timeoutSeconds,
            body: $config->hasBody() ? (string) $config->body_encrypted : null,
            contentType: $config->body_content_type?->value,
            assertion: ContentAssertion::fromConfig($config),
        );

        return self::withLatencyThreshold($result, $config->latency_threshold_ms);
    }

    /**
     * A successful check slower than the threshold is flagged `slow`; it stays
     * successful, so it never counts towards DOWN.
     */
    public static function withLatencyThreshold(ProbeResult $result, ?int $thresholdMs): ProbeResult
    {
        if (! $result->success || $thresholdMs === null || $result->durationMs === null || $result->durationMs <= $thresholdMs) {
            return $result;
        }

        return new ProbeResult(true, $result->durationMs, $result->statusCode, null, $result->metadata, $result->certificate, slow: true, timings: $result->timings);
    }

    /**
     * @param  array<string, string>  $headers
     */
    public function probe(
        string $url,
        HttpMethod $method,
        array $headers,
        ExpectedStatuses $expectedStatuses,
        bool $followRedirects,
        int $timeoutSeconds,
        ?string $body = null,
        ?string $contentType = null,
        ?ContentAssertion $assertion = null,
    ): ProbeResult {
        if ($assertion !== null && ! self::hasHeader($headers, 'Accept-Encoding')) {
            // Ask for an uncompressed body; gzip/deflate are still decoded if the server ignores it.
            $headers['Accept-Encoding'] = 'identity';
        }

        $body = $method->allowsBody() ? $body : null;
        $startedAt = hrtime(true);
        $redirects = 0;
        $currentUrl = $url;
        $originHost = UrlRedactor::host($url);
        $destination = null;
        $certificate = null;
        $dnsMs = null;
        $redirectMs = null;
        /** @var array<string, mixed>|null $handlerStats */
        $handlerStats = null;
        $timings = function () use (&$handlerStats, &$dnsMs, &$redirectMs): CheckTimings {
            return $handlerStats === null
                ? new CheckTimings(dnsMs: $dnsMs, redirectMs: $redirectMs)
                : CheckTimings::fromHandlerStats($handlerStats, $dnsMs, $redirectMs);
        };

        try {
            while (true) {
                $hopStartedAt = hrtime(true);
                $redirectMs = $redirects > 0 ? (int) round(($hopStartedAt - $startedAt) / 1_000_000) : 0;
                $handlerStats = null;
                $dnsMs = null;
                $destination = $this->guard->inspect($currentUrl);
                $dnsMs = $destination->url->hostIsIp ? null : CheckTimings::elapsedMs($hopStartedAt);
                $sameHost = $destination->url->host === $originHost;

                // Custom headers and basic auth (often credentials) are never sent to another host.
                $hopHeaders = $sameHost ? $headers : [];

                // Nor is the request body: a cross-host hop becomes a plain GET.
                if (! $sameHost && $body !== null) {
                    [$method, $body] = [HttpMethod::Get, null];
                }

                // Track the certificate of the monitored host itself, not of redirect targets.
                $captureCertificate = $certificate === null
                    && $destination->url->scheme === 'https'
                    && $destination->url->host === $originHost;

                $onStats = function (TransferStats $stats) use (&$certificate, &$handlerStats, $captureCertificate): void {
                    // on_stats may fire more than once; never overwrite measured values with nothing.
                    $hopStats = $stats->getHandlerStats();
                    $handlerStats = $hopStats === [] ? $handlerStats : $hopStats;

                    if ($captureCertificate) {
                        $certificate = CertificateParser::fromCertinfo($hopStats['certinfo'] ?? null) ?? $certificate;
                    }
                };

                $response = $this->send($destination, $method, $hopHeaders, $body, $contentType, $timeoutSeconds, $onStats, $captureCertificate);
                $status = $response->status();
                $location = $response->header('Location');

                if ($followRedirects && $status >= 300 && $status < 400 && $location !== '') {
                    if ($redirects >= $this->settings->maxRedirects) {
                        return ProbeResult::failure(ProbeErrorCode::TooManyRedirects, $this->elapsed($startedAt), $status, $this->metadata($redirects, $destination, $response), $timings(),
                            FailureEvidence::fromResponse($response, ProbeErrorCode::TooManyRedirects, $method->hasResponseBody(), self::MAX_RESPONSE_BYTES));
                    }

                    $redirects++;

                    if ($status === 303 || (in_array($status, [301, 302], true) && $method->allowsBody())) {
                        $method = $method === HttpMethod::Head ? HttpMethod::Head : HttpMethod::Get;
                        $body = null;
                    }

                    $currentUrl = (string) UriResolver::resolve(new Uri($currentUrl), new Uri($location));

                    continue;
                }

                $metadata = $this->metadata($redirects, $destination, $response);

                if (! $expectedStatuses->contains($status)) {
                    return new ProbeResult(false, $this->elapsed($startedAt), $status, ProbeErrorCode::UnexpectedStatus, $metadata, $certificate, timings: $timings(),
                        evidence: FailureEvidence::fromResponse($response, ProbeErrorCode::UnexpectedStatus, $method->hasResponseBody(), self::MAX_RESPONSE_BYTES));
                }

                $assertionFailure = $method->hasResponseBody() ? $assertion?->evaluate($response, self::MAX_RESPONSE_BYTES) : null;
                $evidence = $assertionFailure === null ? null : FailureEvidence::fromResponse($response, $assertionFailure, true, self::MAX_RESPONSE_BYTES);

                return new ProbeResult($assertionFailure === null, $this->elapsed($startedAt), $status, $assertionFailure, $metadata, $certificate, timings: $timings(), evidence: $evidence);
            }
        } catch (DestinationRejected $exception) {
            return ProbeResult::failure($exception->errorCode, $this->elapsed($startedAt), null, ['redirects' => $redirects], $timings());
        } catch (Throwable $exception) {
            if (self::causedBy($exception, ResponseTooLarge::class)) {
                return ProbeResult::failure(ProbeErrorCode::ResponseTooLarge, $this->elapsed($startedAt), null, $this->metadata($redirects, $destination), $timings());
            }

            if ($exception instanceof ConnectionException) {
                return ProbeResult::failure($this->classify($exception), $this->elapsed($startedAt), null, $this->metadata($redirects, $destination), $timings());
            }

            // Never log the exception message: cURL messages contain the full URL.
            Log::warning('veyra.probe.unexpected_error', [
                'destination' => UrlRedactor::redact($currentUrl),
                'exception' => $exception::class,
            ]);

            return ProbeResult::failure(ProbeErrorCode::UnexpectedError, $this->elapsed($startedAt), null, $this->metadata($redirects, $destination), $timings());
        }
    }

    /**
     * Guzzle/cURL options for one hop. Public so the pinning and hardening
     * options can be asserted in tests (they are invisible to Http::fake).
     *
     * @return array<string, mixed>
     */
    public function requestOptions(Destination $destination, int $timeoutSeconds, ?Closure $onStats = null, bool $captureCertificate = false): array
    {
        $options = PinnedConnection::options($destination, $timeoutSeconds, self::MAX_CONNECT_TIMEOUT, self::MAX_RESPONSE_BYTES);

        if ($onStats !== null) {
            // Transfer info of the hop: phase timings and, when asked, the certificate chain.
            $options['on_stats'] = $onStats;
        }

        if ($onStats !== null && $captureCertificate) {
            // Exposes the presented chain (public data) to on_stats.
            $options['curl'][CURLOPT_CERTINFO] = true;
        }

        return $options;
    }

    /**
     * @param  array<string, string>  $headers
     */
    private function send(Destination $destination, HttpMethod $method, array $headers, ?string $body, ?string $contentType, int $timeoutSeconds, ?Closure $onStats = null, bool $captureCertificate = false): Response
    {
        $request = $this->http
            ->withOptions($this->requestOptions($destination, $timeoutSeconds, $onStats, $captureCertificate))
            ->withUserAgent($this->settings->userAgent)
            ->withHeaders($headers);

        if ($body !== null) {
            $request = $request->withBody($body, $contentType ?? 'text/plain');
        }

        return $request->send($method->value, $destination->url->url);
    }

    /**
     * @param  array<string, string>  $headers
     */
    private static function hasHeader(array $headers, string $name): bool
    {
        foreach (array_keys($headers) as $header) {
            if (strcasecmp($header, $name) === 0) {
                return true;
            }
        }

        return false;
    }

    private function classify(ConnectionException $exception): ProbeErrorCode
    {
        for ($previous = $exception->getPrevious(); $previous !== null; $previous = $previous->getPrevious()) {
            if ($previous instanceof ConnectTimeoutException || $previous instanceof NetworkTimeoutException) {
                return ProbeErrorCode::Timeout;
            }
        }

        $message = $exception->getMessage();
        $errno = preg_match('/cURL error (\d+)/', $message, $matches) === 1 ? (int) $matches[1] : null;

        return match (true) {
            $errno === 28, $errno === null && str_contains(strtolower($message), 'timed out') => ProbeErrorCode::Timeout,
            in_array($errno, [35, 51, 53, 54, 58, 59, 60, 64, 66, 77, 80, 82, 83, 90, 91], true) => ProbeErrorCode::TlsError,
            $errno === 6 => ProbeErrorCode::DnsFailure,
            $errno === 63 => ProbeErrorCode::ResponseTooLarge,
            default => ProbeErrorCode::ConnectionFailed,
        };
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

    private function elapsed(int $startedAt): int
    {
        return (int) round((hrtime(true) - $startedAt) / 1_000_000);
    }

    /**
     * @return array<string, scalar|null>
     */
    private function metadata(int $redirects, ?Destination $destination, ?Response $response = null): array
    {
        return array_filter([
            'redirects' => $redirects,
            'resolved_ip' => $destination?->ip,
            'final_host' => $destination?->url->host,
            'http_version' => $response?->toPsrResponse()->getProtocolVersion(),
        ], fn (mixed $value): bool => $value !== null);
    }
}
