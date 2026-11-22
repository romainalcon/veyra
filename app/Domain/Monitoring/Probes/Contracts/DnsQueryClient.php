<?php

namespace App\Domain\Monitoring\Probes\Contracts;

use App\Domain\Monitoring\Dns\DnsQueryFailed;
use App\Domain\Monitoring\Dns\DnsResponse;
use App\Domain\Monitoring\Enums\DnsRecordType;

/**
 * Sends one DNS query to one name server IP address (port 53) and decodes
 * the answer. Never resolves the name server itself.
 */
interface DnsQueryClient
{
    /**
     * @throws DnsQueryFailed
     */
    public function query(string $nameserver, string $name, DnsRecordType $type, float $timeoutSeconds): DnsResponse;
}
