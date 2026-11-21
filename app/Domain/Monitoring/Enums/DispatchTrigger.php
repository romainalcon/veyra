<?php

namespace App\Domain\Monitoring\Enums;

enum DispatchTrigger: string
{
    case Scheduled = 'scheduled';
    case Manual = 'manual';
}
