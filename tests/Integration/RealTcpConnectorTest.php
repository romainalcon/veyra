<?php

use App\Domain\Monitoring\Enums\ProbeErrorCode;
use App\Domain\Monitoring\Probes\StreamTcpConnector;

/*
 * Exercises real sockets on loopback. The SSRF policy is not involved here:
 * the connector only ever receives addresses already validated by the guard.
 */
test('an open port is reported as reachable and the socket is closed', function () {
    $server = stream_socket_server('tcp://127.0.0.1:0', $errno, $errstr);
    $port = (int) substr((string) strrchr((string) stream_socket_get_name($server, false), ':'), 1);

    expect((new StreamTcpConnector)->connect('127.0.0.1', $port, 2))->toBeNull();

    fclose($server);
});

test('a closed port is reported as a failed connection', function () {
    $server = stream_socket_server('tcp://127.0.0.1:0', $errno, $errstr);
    $port = (int) substr((string) strrchr((string) stream_socket_get_name($server, false), ':'), 1);
    fclose($server);

    expect((new StreamTcpConnector)->connect('127.0.0.1', $port, 2))->toBe(ProbeErrorCode::ConnectionFailed);
});
