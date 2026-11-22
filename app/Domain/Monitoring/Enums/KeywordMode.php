<?php

namespace App\Domain\Monitoring\Enums;

enum KeywordMode: string
{
    case Contains = 'contains';
    case NotContains = 'not_contains';
}
