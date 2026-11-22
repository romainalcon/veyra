<?php

namespace App\Domain\Monitoring\Enums;

/**
 * Content types a probe request body may declare (closed list, ADR-037).
 */
enum RequestContentType: string
{
    case Json = 'application/json';
    case Form = 'application/x-www-form-urlencoded';
    case Xml = 'application/xml';
    case Text = 'text/plain';
}
