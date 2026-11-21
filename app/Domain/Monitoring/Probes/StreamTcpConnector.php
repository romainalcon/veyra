<?php

namespace App\Domain\Monitoring\Probes;

use App\Domain\Monitoring\Enums\ProbeErrorCode;
use App\Domain\Monitoring\Probes\Contracts\TcpConnector;

/**
 * Plain TCP handshake with PHP streams: no proxy, no TLS, nothing written or
 * read. The socket is closed as soon as it is established.
 */
final class StreamTcpConnector implements TcpConnector
{
    /**
     * ETIMEDOUT on Linux (110) and BSD/macOS (60).
     */
    private const TIMEOUT_ERRNOS = [60, 110];

    public function connect(string $ip, int $port, int $timeoutSeconds): ?ProbeErrorCode
    {
        $address = str_contains($ip, ':') ? "[{$ip}]" : $ip;
        $startedAt = microtime(true);

        $socket = @stream_socket_client(
            "tcp://{$address}:{$port}",
            $errno,
            $errstr,
            $timeoutSeconds,
            STREAM_CLIENT_CONNECT,
        );

        if ($socket !== false) {
            fclose($socket);

            return null;
        }

        $timedOut = in_array($errno, self::TIMEOUT_ERRNOS, true)
            || str_contains(strtolower($errstr), 'timed out')
            || microtime(true) - $startedAt >= $timeoutSeconds;

        return $timedOut ? ProbeErrorCode::Timeout : ProbeErrorCode::ConnectionFailed;
    }
}
