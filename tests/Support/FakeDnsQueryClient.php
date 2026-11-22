<?php

namespace Tests\Support;

use App\Domain\Monitoring\Dns\DnsQueryFailed;
use App\Domain\Monitoring\Dns\DnsResponse;
use App\Domain\Monitoring\Enums\DnsRecordType;
use App\Domain\Monitoring\Probes\Contracts\DnsQueryClient;

/**
 * Deterministic DNS queries for tests: no network. Answers are keyed by
 * name server (or any server), name and type; unknown queries fail.
 */
class FakeDnsQueryClient implements DnsQueryClient
{
    /**
     * @var list<array{nameserver: string, name: string, type: string, timeout: float}>
     */
    public array $queries = [];

    /**
     * @var array<string, DnsResponse|DnsQueryFailed>
     */
    private array $outcomes = [];

    /**
     * @param  list<string>  $answers
     */
    public function answer(string $name, DnsRecordType $type, array $answers, int $rcode = DnsResponse::NOERROR, string $nameserver = '*'): self
    {
        $this->outcomes[self::key($nameserver, $name, $type)] = new DnsResponse($rcode, $answers);

        return $this;
    }

    public function fail(string $name, DnsRecordType $type, bool $timedOut = false, string $nameserver = '*'): self
    {
        $this->outcomes[self::key($nameserver, $name, $type)] = new DnsQueryFailed($timedOut ? 'The name server did not answer in time.' : 'Could not reach the name server.', $timedOut);

        return $this;
    }

    public function query(string $nameserver, string $name, DnsRecordType $type, float $timeoutSeconds): DnsResponse
    {
        $this->queries[] = ['nameserver' => $nameserver, 'name' => $name, 'type' => $type->value, 'timeout' => $timeoutSeconds];
        $outcome = $this->outcomes[self::key($nameserver, $name, $type)]
            ?? $this->outcomes[self::key('*', $name, $type)]
            ?? new DnsQueryFailed('Could not reach the name server.');

        if ($outcome instanceof DnsQueryFailed) {
            throw $outcome;
        }

        return $outcome;
    }

    private static function key(string $nameserver, string $name, DnsRecordType $type): string
    {
        return "{$nameserver}|{$name}|{$type->value}";
    }
}
