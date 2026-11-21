<?php

namespace App\Domain\Monitoring\Probes;

use App\Domain\Monitoring\Certificates\CertificateParser;
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
use Psr\Http\Message\ResponseInterface;
use Throwable;

/**
 * Executes one HTTP check with SSRF protections:
 * - every hop (initial URL and redirects) goes through DestinationGuard;
 * - the connection is pinned to the validated IP (CURLOPT_RESOLVE), which
 *   prevents DNS rebinding between validation and connection;
 * - redirects are followed manually and bounded;
 * - proxies from the environment are ignored;
 * - responses are capped at MAX_RESPONSE_BYTES (Content-Length and progress
 *   callbacks); V1 checks only evaluate the status code.
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
        return $this->probe(
            url: $config->url,
            method: $config->method,
            headers: $config->headers(),
            expectedStatuses: $config->expected_statuses,
            followRedirects: $config->follow_redirects,
            timeoutSeconds: $timeoutSeconds,
        );
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
    ): ProbeResult {
        $startedAt = hrtime(true);
        $redirects = 0;
        $currentUrl = $url;
        $originHost = UrlRedactor::host($url);
        $destination = null;
        $certificate = null;

        try {
            while (true) {
                $destination = $this->guard->inspect($currentUrl);

                // Custom headers (often credentials) are never sent to another host.
                $hopHeaders = $destination->url->host === $originHost ? $headers : [];

                // Track the certificate of the monitored host itself, not of redirect targets.
                $captureCertificate = $certificate === null
                    && $destination->url->scheme === 'https'
                    && $destination->url->host === $originHost;

                $response = $this->send($destination, $method, $hopHeaders, $timeoutSeconds, $captureCertificate
                    ? function (TransferStats $stats) use (&$certificate): void {
                        // on_stats may fire more than once; never overwrite a parsed certificate with nothing.
                        $certificate = CertificateParser::fromCertinfo($stats->getHandlerStats()['certinfo'] ?? null) ?? $certificate;
                    }
                    : null);
                $status = $response->status();
                $location = $response->header('Location');

                if ($followRedirects && $status >= 300 && $status < 400 && $location !== '') {
                    if ($redirects >= $this->settings->maxRedirects) {
                        return ProbeResult::failure(ProbeErrorCode::TooManyRedirects, $this->elapsed($startedAt), $status, $this->metadata($redirects, $destination, $response));
                    }

                    $redirects++;
                    $currentUrl = (string) UriResolver::resolve(new Uri($currentUrl), new Uri($location));

                    continue;
                }

                $metadata = $this->metadata($redirects, $destination, $response);

                return $expectedStatuses->contains($status)
                    ? new ProbeResult(true, $this->elapsed($startedAt), $status, null, $metadata, $certificate)
                    : new ProbeResult(false, $this->elapsed($startedAt), $status, ProbeErrorCode::UnexpectedStatus, $metadata, $certificate);
            }
        } catch (DestinationRejected $exception) {
            return ProbeResult::failure($exception->errorCode, $this->elapsed($startedAt), null, ['redirects' => $redirects]);
        } catch (Throwable $exception) {
            if (self::causedBy($exception, ResponseTooLarge::class)) {
                return ProbeResult::failure(ProbeErrorCode::ResponseTooLarge, $this->elapsed($startedAt), null, $this->metadata($redirects, $destination));
            }

            if ($exception instanceof ConnectionException) {
                return ProbeResult::failure($this->classify($exception), $this->elapsed($startedAt), null, $this->metadata($redirects, $destination));
            }

            // Never log the exception message: cURL messages contain the full URL.
            Log::warning('veyra.probe.unexpected_error', [
                'destination' => UrlRedactor::redact($currentUrl),
                'exception' => $exception::class,
            ]);

            return ProbeResult::failure(ProbeErrorCode::UnexpectedError, $this->elapsed($startedAt), null, $this->metadata($redirects, $destination));
        }
    }

    /**
     * Guzzle/cURL options for one hop. Public so the pinning and hardening
     * options can be asserted in tests (they are invisible to Http::fake).
     *
     * @return array<string, mixed>
     */
    public function requestOptions(Destination $destination, int $timeoutSeconds, ?Closure $onStats = null): array
    {
        $curl = [];

        if ($onStats !== null) {
            // Exposes the presented chain (public data) to on_stats.
            $curl[CURLOPT_CERTINFO] = true;
        }

        if (! $destination->url->hostIsIp) {
            $ip = str_contains($destination->ip, ':') ? "[{$destination->ip}]" : $destination->ip;
            $curl[CURLOPT_RESOLVE] = ["{$destination->url->host}:{$destination->url->port}:{$ip}"];
        }

        return [
            'allow_redirects' => false,
            'protocols' => ['http', 'https'],
            'connect_timeout' => min($timeoutSeconds, self::MAX_CONNECT_TIMEOUT),
            'timeout' => $timeoutSeconds,
            'verify' => true,
            'proxy' => '',
            'http_errors' => false,
            'decode_content' => false,
            'curl' => $curl,
            ...($onStats !== null ? ['on_stats' => $onStats] : []),
            'on_headers' => function (ResponseInterface $response): void {
                if ((int) $response->getHeaderLine('Content-Length') > self::MAX_RESPONSE_BYTES) {
                    throw new ResponseTooLarge('Response exceeds the size limit.');
                }
            },
            'progress' => function (int $downloadTotal, int $downloaded): void {
                if ($downloaded > self::MAX_RESPONSE_BYTES) {
                    throw new ResponseTooLarge('Response exceeds the size limit.');
                }
            },
        ];
    }

    /**
     * @param  array<string, string>  $headers
     */
    private function send(Destination $destination, HttpMethod $method, array $headers, int $timeoutSeconds, ?Closure $onStats = null): Response
    {
        return $this->http
            ->withOptions($this->requestOptions($destination, $timeoutSeconds, $onStats))
            ->withUserAgent($this->settings->userAgent)
            ->withHeaders($headers)
            ->send($method->value, $destination->url->url);
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
