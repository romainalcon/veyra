<?php

namespace App\Domain\Monitoring\Transfer;

enum ImportRowStatus: string
{
    case Valid = 'valid';
    case Duplicate = 'duplicate';
    case Invalid = 'invalid';
}
