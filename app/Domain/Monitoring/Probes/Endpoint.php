<?php

namespace App\Domain\Monitoring\Probes;

/**
 * A host and port whose every resolved address was validated. `ip` is the
 * address the connection must be made to (never the host name again).
 */
final readonly class Endpoint
{
    public function __construct(
        public string $host,
        public int $port,
        public bool $hostIsIp,
        public string $ip,
    ) {}
}
