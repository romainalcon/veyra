<?php

namespace App\Domain\Monitoring\Dns;

use RuntimeException;

/**
 * No usable answer from one name server: timeout, network error or a
 * malformed, oversized or unrelated response. Messages never contain the
 * response content.
 */
class DnsQueryFailed extends RuntimeException
{
    public function __construct(string $message, public readonly bool $timedOut = false)
    {
        parent::__construct($message);
    }
}
