<?php

namespace Tests\Support;

use App\Domain\Monitoring\Probes\Contracts\DnsResolver;

/**
 * Deterministic DNS for tests: no real network lookups.
 */
class FakeDnsResolver implements DnsResolver
{
    /**
     * @param  array<string, list<string>>  $records
     */
    public function __construct(public array $records = [])
    {
        $this->records += [
            'example.com' => ['93.184.215.14'],
            'other.example.org' => ['93.184.215.15'],
            'api.example.com' => ['93.184.215.16', '2606:2800:21f:cb07:6820:80da:af6b:8b2c'],
        ];
    }

    public function resolve(string $host): array
    {
        return $this->records[$host] ?? [];
    }

    /**
     * @param  list<string>  $ips
     */
    public function set(string $host, array $ips): self
    {
        $this->records[$host] = $ips;

        return $this;
    }
}
