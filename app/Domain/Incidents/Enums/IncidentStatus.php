<?php

namespace App\Domain\Incidents\Enums;

enum IncidentStatus: string
{
    case Open = 'open';
    case Resolved = 'resolved';
}
