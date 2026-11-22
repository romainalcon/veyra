<?php

namespace App\Domain\Monitoring\Enums;

/**
 * Record types a DNS monitor can query, with their wire-format codes.
 */
enum DnsRecordType: string
{
    case A = 'A';
    case Aaaa = 'AAAA';
    case Cname = 'CNAME';
    case Mx = 'MX';
    case Txt = 'TXT';
    case Ns = 'NS';

    public function code(): int
    {
        return match ($this) {
            self::A => 1,
            self::Ns => 2,
            self::Cname => 5,
            self::Mx => 15,
            self::Txt => 16,
            self::Aaaa => 28,
        };
    }

    /**
     * Host-name answers are compared case-insensitively, without trailing dot.
     */
    public function hasNameAnswers(): bool
    {
        return in_array($this, [self::Cname, self::Ns, self::Mx], true);
    }
}
