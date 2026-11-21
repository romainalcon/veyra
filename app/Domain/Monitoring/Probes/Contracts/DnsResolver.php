<?php

namespace App\Domain\Monitoring\Probes\Contracts;

interface DnsResolver
{
    /**
     * Resolve A and AAAA records for the host.
     *
     * @return list<string> IP addresses (empty when the name does not resolve)
     */
    public function resolve(string $host): array;
}
