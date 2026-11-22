<?php

namespace App\Domain\Monitoring\Probes;

use Psr\Http\Message\ResponseInterface;

/**
 * Hardened Guzzle/cURL options for one request to a validated destination,
 * shared by HTTP probes and webhook notifications:
 * - the connection is pinned to the validated IP (CURLOPT_RESOLVE), which
 *   prevents DNS rebinding between validation and connection;
 * - redirects are never followed by the client and environment proxies are ignored;
 * - responses are capped (Content-Length and progress callbacks).
 */
final class PinnedConnection
{
    /**
     * @param  list<string>  $protocols
     * @return array<string, mixed>
     */
    public static function options(
        Destination $destination,
        int $timeoutSeconds,
        int $connectTimeoutSeconds,
        int $maxResponseBytes,
        array $protocols = ['http', 'https'],
    ): array {
        $curl = [];

        if (! $destination->url->hostIsIp) {
            $ip = str_contains($destination->ip, ':') ? "[{$destination->ip}]" : $destination->ip;
            $curl[CURLOPT_RESOLVE] = ["{$destination->url->host}:{$destination->url->port}:{$ip}"];
        }

        return [
            'allow_redirects' => false,
            'protocols' => $protocols,
            'connect_timeout' => min($timeoutSeconds, $connectTimeoutSeconds),
            'timeout' => $timeoutSeconds,
            'verify' => true,
            'proxy' => '',
            'http_errors' => false,
            'decode_content' => false,
            'curl' => $curl,
            'on_headers' => function (ResponseInterface $response) use ($maxResponseBytes): void {
                if ((int) $response->getHeaderLine('Content-Length') > $maxResponseBytes) {
                    throw new ResponseTooLarge('Response exceeds the size limit.');
                }
            },
            'progress' => function (int $downloadTotal, int $downloaded) use ($maxResponseBytes): void {
                if ($downloaded > $maxResponseBytes) {
                    throw new ResponseTooLarge('Response exceeds the size limit.');
                }
            },
        ];
    }
}
