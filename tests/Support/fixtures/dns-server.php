<?php

/*
 * Loopback DNS server for tests/Integration/RealDnsQueryClientTest.php.
 * Usage: php dns-server.php <mode>; prints the port on stdout, then answers
 * one query. Modes:
 * - spoof-then-truncate: a datagram with another ID, then a truncated UDP
 *   answer; the full answer is served over TCP on the same port.
 * - silent: never answers.
 */

$mode = $argv[1] ?? 'spoof-then-truncate';

/*
 * The UDP port is chosen by the system; the TCP port must be the same one and
 * may already be taken by another process (parallel test runs), so try again
 * with a new UDP port until both are bound. Prints 0 when that never happens.
 */
$udp = $tcp = false;
$port = 0;

for ($attempt = 0; $attempt < 20 && $tcp === false; $attempt++) {
    $udp = stream_socket_server('udp://127.0.0.1:0', $errno, $errstr, STREAM_SERVER_BIND);

    if ($udp === false) {
        continue;
    }

    $port = (int) substr((string) strrchr((string) stream_socket_get_name($udp, false), ':'), 1);
    $tcp = @stream_socket_server("tcp://127.0.0.1:{$port}", $errno, $errstr);

    if ($tcp === false) {
        fclose($udp);
        $udp = false;
    }
}

if ($udp === false || $tcp === false) {
    fwrite(STDERR, "Could not bind UDP and TCP on the same loopback port.\n");
    fwrite(STDOUT, "0\n");
    exit(1);
}

fwrite(STDOUT, $port."\n");
fflush(STDOUT);

$query = stream_socket_recvfrom($udp, 512, 0, $peer);

if ($mode === 'silent') {
    sleep(5);
    exit(0);
}

$id = unpack('nid', $query)['id'];
$question = substr($query, 12, strlen($query) - 12 - 11); // without the OPT record
$answer = fn (int $id, int $flags, int $count, string $records) => pack('nnnnnn', $id, $flags, 1, $count, 0, 0).$question.$records;
$record = "\xC0\x0C".pack('nnNn', 1, 1, 60, 4).inet_pton('93.184.215.14');

stream_socket_sendto($udp, $answer(($id + 1) & 0xFFFF, 0x8180, 1, $record), 0, $peer);
stream_socket_sendto($udp, $answer($id, 0x8380, 0, ''), 0, $peer);

$connection = stream_socket_accept($tcp, 5);
$length = unpack('nlength', fread($connection, 2))['length'];
$tcpQuery = fread($connection, $length);
$full = $answer(unpack('nid', $tcpQuery)['id'], 0x8180, 1, $record);
fwrite($connection, pack('n', strlen($full)).$full);
fclose($connection);
