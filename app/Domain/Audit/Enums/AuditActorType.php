<?php

namespace App\Domain\Audit\Enums;

/**
 * Who performed an audited action: a member through the interface, an
 * organization API token (ADR-044), or the system (scheduled jobs).
 */
enum AuditActorType: string
{
    case User = 'user';
    case ApiToken = 'api_token';
    case System = 'system';
}
