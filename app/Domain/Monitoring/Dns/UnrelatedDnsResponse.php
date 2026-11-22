<?php

namespace App\Domain\Monitoring\Dns;

/**
 * A response whose ID or question does not match the query: ignored on UDP
 * (possibly spoofed or late), fatal otherwise.
 */
class UnrelatedDnsResponse extends DnsQueryFailed
{
    public function __construct()
    {
        parent::__construct('Unrelated DNS response.');
    }
}
