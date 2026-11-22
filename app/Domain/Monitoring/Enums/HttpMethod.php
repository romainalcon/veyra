<?php

namespace App\Domain\Monitoring\Enums;

/**
 * Methods a probe may send. POST/PUT/PATCH carry an optional, size-limited
 * body (ADR-037); the SSRF rules are the same for every method.
 */
enum HttpMethod: string
{
    case Get = 'GET';
    case Head = 'HEAD';
    case Post = 'POST';
    case Put = 'PUT';
    case Patch = 'PATCH';

    public function allowsBody(): bool
    {
        return in_array($this, [self::Post, self::Put, self::Patch], true);
    }

    /**
     * HEAD responses have no body to assert on.
     */
    public function hasResponseBody(): bool
    {
        return $this !== self::Head;
    }
}
