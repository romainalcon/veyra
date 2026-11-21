<?php

namespace App\Domain\Monitoring\Probes;

use App\Domain\Monitoring\DTOs\ProbeResult;
use App\Domain\Monitoring\Models\Monitor;
use App\Domain\Monitoring\Probes\Contracts\MonitorProbe;
use App\Domain\Monitoring\Probes\Contracts\TcpConnector;
use LogicException;

/**
 * Checks that a TCP port accepts connections. The endpoint is re-validated
 * before every check (host rules, TCP port allow-list, every DNS answer) and
 * the connection is made to the validated IP, never to the host name, so a
 * DNS answer cannot change between validation and connection.
 */
final class TcpProbe implements MonitorProbe
{
    public function __construct(
        private readonly DestinationGuard $guard,
        private readonly TcpConnector $connector,
    ) {}

    public function canProbe(Monitor $monitor): bool
    {
        return $monitor->tcpConfig !== null;
    }

    public function probe(Monitor $monitor): ProbeResult
    {
        $config = $monitor->tcpConfig ?? throw new LogicException('TCP monitor without configuration.');

        return $this->run($config->host, $config->port, $monitor->timeout_seconds);
    }

    public function run(string $host, int $port, int $timeoutSeconds): ProbeResult
    {
        $startedAt = hrtime(true);

        try {
            $endpoint = $this->guard->inspectEndpoint($host, $port);
        } catch (DestinationRejected $exception) {
            return ProbeResult::failure($exception->errorCode, $this->elapsed($startedAt));
        }

        $error = $this->connector->connect($endpoint->ip, $endpoint->port, $timeoutSeconds);
        $metadata = ['resolved_ip' => $endpoint->ip];

        return $error === null
            ? new ProbeResult(true, $this->elapsed($startedAt), null, null, $metadata)
            : ProbeResult::failure($error, $this->elapsed($startedAt), null, $metadata);
    }

    private function elapsed(int $startedAt): int
    {
        return (int) round((hrtime(true) - $startedAt) / 1_000_000);
    }
}
