<?php

namespace App\Domain\Monitoring\Probes;

use App\Domain\Monitoring\Dns\DnsQueryFailed;
use App\Domain\Monitoring\Dns\DnsResponse;
use App\Domain\Monitoring\Dns\DnsWire;
use App\Domain\Monitoring\Dns\UnrelatedDnsResponse;
use App\Domain\Monitoring\Enums\DnsRecordType;
use App\Domain\Monitoring\Probes\Contracts\DnsQueryClient;

/**
 * DNS over UDP with a TCP fallback for truncated answers (RFC 7766), both
 * bounded by the same deadline. UDP datagrams with another ID or question
 * are ignored until the deadline (off-path spoofing); TCP answers are
 * limited to MAX_TCP_BYTES.
 */
final class SocketDnsQueryClient implements DnsQueryClient
{
    public const MAX_TCP_BYTES = 16_384;

    private const UDP_READ_BYTES = 4096;

    private const MAX_DATAGRAMS = 16;

    /**
     * @param  int  $port  53 in production; tests use an ephemeral loopback port
     */
    public function __construct(private readonly int $port = 53) {}

    public function query(string $nameserver, string $name, DnsRecordType $type, float $timeoutSeconds): DnsResponse
    {
        $deadline = microtime(true) + $timeoutSeconds;
        $id = random_int(0, 0xFFFF);
        $packet = DnsWire::query($id, $name, $type);

        $response = $this->udp($nameserver, $packet, $id, $name, $type, $deadline);

        return $response->truncated ? $this->tcp($nameserver, $packet, $id, $name, $type, $deadline) : $response;
    }

    private function udp(string $nameserver, string $packet, int $id, string $name, DnsRecordType $type, float $deadline): DnsResponse
    {
        $socket = @stream_socket_client('udp://'.self::address($nameserver).':'.$this->port, $errno, $errstr, 1);

        if ($socket === false) {
            throw new DnsQueryFailed('Could not reach the name server.');
        }

        try {
            if (@fwrite($socket, $packet) !== strlen($packet)) {
                throw new DnsQueryFailed('Could not reach the name server.');
            }

            for ($datagram = 0; $datagram < self::MAX_DATAGRAMS; $datagram++) {
                $this->arm($socket, $deadline);
                $data = @fread($socket, self::UDP_READ_BYTES);

                if ($data === false || $data === '') {
                    $meta = stream_get_meta_data($socket);

                    throw new DnsQueryFailed($meta['timed_out'] ? 'The name server did not answer in time.' : 'Could not reach the name server.', $meta['timed_out']);
                }

                try {
                    return DnsWire::parse($data, $id, $name, $type);
                } catch (UnrelatedDnsResponse) {
                    continue;
                }
            }

            throw new UnrelatedDnsResponse;
        } finally {
            fclose($socket);
        }
    }

    private function tcp(string $nameserver, string $packet, int $id, string $name, DnsRecordType $type, float $deadline): DnsResponse
    {
        $remaining = $deadline - microtime(true);

        if ($remaining <= 0) {
            throw new DnsQueryFailed('The name server did not answer in time.', true);
        }

        $socket = @stream_socket_client('tcp://'.self::address($nameserver).':'.$this->port, $errno, $errstr, $remaining);

        if ($socket === false) {
            throw new DnsQueryFailed('Could not reach the name server.', microtime(true) >= $deadline);
        }

        try {
            $framed = pack('n', strlen($packet)).$packet;

            if (@fwrite($socket, $framed) !== strlen($framed)) {
                throw new DnsQueryFailed('Could not reach the name server.');
            }

            /** @var array{length: int} $prefix */
            $prefix = unpack('nlength', $this->readExactly($socket, 2, $deadline));

            if ($prefix['length'] > self::MAX_TCP_BYTES) {
                throw new DnsQueryFailed('The DNS response is too large.');
            }

            return DnsWire::parse($this->readExactly($socket, $prefix['length'], $deadline), $id, $name, $type);
        } finally {
            fclose($socket);
        }
    }

    /**
     * @param  resource  $socket
     */
    private function readExactly($socket, int $length, float $deadline): string
    {
        $data = '';

        while (($missing = $length - strlen($data)) > 0) {
            $this->arm($socket, $deadline);
            $chunk = @fread($socket, $missing);

            if ($chunk === false || $chunk === '') {
                $meta = stream_get_meta_data($socket);

                throw new DnsQueryFailed($meta['timed_out'] ? 'The name server did not answer in time.' : 'Malformed DNS response.', $meta['timed_out']);
            }

            $data .= $chunk;
        }

        return $data;
    }

    /**
     * @param  resource  $socket
     */
    private function arm($socket, float $deadline): void
    {
        $remaining = $deadline - microtime(true);

        if ($remaining <= 0) {
            throw new DnsQueryFailed('The name server did not answer in time.', true);
        }

        $seconds = (int) floor($remaining);
        stream_set_timeout($socket, $seconds, (int) (($remaining - $seconds) * 1_000_000));
    }

    private static function address(string $ip): string
    {
        return str_contains($ip, ':') ? "[{$ip}]" : $ip;
    }
}
