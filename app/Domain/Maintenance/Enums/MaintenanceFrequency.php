<?php

namespace App\Domain\Maintenance\Enums;

enum MaintenanceFrequency: string
{
    case Daily = 'daily';
    case Weekly = 'weekly';
    case Monthly = 'monthly';
}
