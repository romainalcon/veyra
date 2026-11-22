<?php

namespace App\Domain\Monitoring\Dns;

/**
 * Decoded answer to one DNS query. `answers` only holds records of the
 * queried type, normalized (see DnsWire), at most DnsWire::MAX_ANSWERS.
 */
final readonly class DnsResponse
{
    public const NOERROR = 0;

    public const SERVFAIL = 2;

    public const NXDOMAIN = 3;

    /**
     * @param  list<string>  $answers
     */
    public function __construct(
        public int $rcode,
        public array $answers,
        public bool $truncated = false,
    ) {}

    public function rcodeName(): string
    {
        return match ($this->rcode) {
            0 => 'NOERROR',
            1 => 'FORMERR',
            2 => 'SERVFAIL',
            3 => 'NXDOMAIN',
            4 => 'NOTIMP',
            5 => 'REFUSED',
            default => "RCODE{$this->rcode}",
        };
    }
}
