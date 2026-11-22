<?php

namespace App\Domain\Monitoring\Enums;

/**
 * How the answers of a DNS monitor are compared with its expected values.
 */
enum DnsMatchMode: string
{
    /** Every expected value is among the answers (others may exist). */
    case Contains = 'contains';
    /** The answers are exactly the expected values (order ignored). */
    case Exact = 'exact';
}
