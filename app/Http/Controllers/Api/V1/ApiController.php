<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;

/**
 * Base of the public API controllers (ADR-044). The organization is the
 * token's (ResolveApiOrganization); lists use cursor pagination.
 */
abstract class ApiController extends Controller
{
    public const DEFAULT_PER_PAGE = 25;

    public const MAX_PER_PAGE = 100;

    protected function perPage(Request $request): int
    {
        $perPage = filter_var($request->query('per_page'), FILTER_VALIDATE_INT);

        return is_int($perPage) ? max(1, min(self::MAX_PER_PAGE, $perPage)) : self::DEFAULT_PER_PAGE;
    }
}
