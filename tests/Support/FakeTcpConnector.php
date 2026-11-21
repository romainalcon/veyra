<?php

namespace Tests\Support;

use App\Domain\Monitoring\Enums\ProbeErrorCode;
use App\Domain\Monitoring\Probes\Contracts\TcpConnector;

/**
 * Records connection attempts instead of opening sockets.
 */
class FakeTcpConnector implements TcpConnector
{
    /**
     * @var list<array{ip: string, port: int, timeout: int}>
     */
    public array $attempts = [];

    public function __construct(public ?ProbeErrorCode $result = null) {}

    public function connect(string $ip, int $port, int $timeoutSeconds): ?ProbeErrorCode
    {
        $this->attempts[] = ['ip' => $ip, 'port' => $port, 'timeout' => $timeoutSeconds];

        return $this->result;
    }
}
