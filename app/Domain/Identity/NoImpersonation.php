<?php

namespace App\Domain\Identity;

use App\Domain\Identity\Contracts\Impersonation;
use Illuminate\Http\Request;

/**
 * Community edition: nobody acts as another user (ADR-038).
 */
class NoImpersonation implements Impersonation
{
    public function current(Request $request): ?array
    {
        return null;
    }
}
