<?php

namespace App\Domain\Monitoring\Enums;

/**
 * V1 probes only issue safe, idempotent requests.
 */
enum HttpMethod: string
{
    case Get = 'GET';
    case Head = 'HEAD';
}
