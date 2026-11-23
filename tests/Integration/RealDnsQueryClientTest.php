<?php

use App\Domain\Monitoring\Dns\DnsQueryFailed;
use App\Domain\Monitoring\Enums\DnsRecordType;
use App\Domain\Monitoring\Probes\SocketDnsQueryClient;

/*
 * Exercises real UDP and TCP sockets against a loopback DNS server running
 * in a child process (tests/Support/fixtures/dns-server.php).
 */
function startDnsServer(string $mode): array
{
    $process = proc_open([PHP_BINARY, base_path('tests/Support/fixtures/dns-server.php'), $mode], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
    $port = (int) trim((string) fgets($pipes[1]));

    if ($port === 0) {
        throw new RuntimeException('The loopback DNS server could not start: '.trim((string) stream_get_contents($pipes[2])));
    }

    return [$process, $port];
}

test('a spoofed datagram is ignored and a truncated answer is retried over TCP', function () {
    [$process, $port] = startDnsServer('spoof-then-truncate');

    $response = (new SocketDnsQueryClient($port))->query('127.0.0.1', 'example.com', DnsRecordType::A, 3);

    expect($response->answers)->toBe(['93.184.215.14'])
        ->and($response->truncated)->toBeFalse();

    proc_close($process);
});

test('a silent server times out within the deadline', function () {
    [$process, $port] = startDnsServer('silent');
    $startedAt = microtime(true);

    try {
        (new SocketDnsQueryClient($port))->query('127.0.0.1', 'example.com', DnsRecordType::A, 1);
        $this->fail('The query should time out.');
    } catch (DnsQueryFailed $exception) {
        expect($exception->timedOut)->toBeTrue()
            ->and(microtime(true) - $startedAt)->toBeLessThan(2.0);
    }

    proc_terminate($process);
    proc_close($process);
});
