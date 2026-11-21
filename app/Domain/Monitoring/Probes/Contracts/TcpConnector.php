<?php

namespace App\Domain\Monitoring\Probes\Contracts;

use App\Domain\Monitoring\Enums\ProbeErrorCode;

/**
 * Opens (and immediately closes) a TCP connection to an already validated IP
 * address. Never resolves host names.
 */
interface TcpConnector
{
    /**
     * @return ProbeErrorCode|null null when the connection was established
     */
    public function connect(string $ip, int $port, int $timeoutSeconds): ?ProbeErrorCode;
}
