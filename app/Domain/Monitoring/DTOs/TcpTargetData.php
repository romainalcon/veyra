<?php

namespace App\Domain\Monitoring\DTOs;

final readonly class TcpTargetData
{
    public function __construct(
        public string $host,
        public int $port,
    ) {}

    /**
     * @return array{host: string, port: int}
     */
    public function toConfig(): array
    {
        return ['host' => $this->host, 'port' => $this->port];
    }
}
