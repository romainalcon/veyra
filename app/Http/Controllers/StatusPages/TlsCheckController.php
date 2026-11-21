<?php

namespace App\Http\Controllers\StatusPages;

use App\Domain\StatusPages\Services\CustomDomainRegistry;
use App\Domain\StatusPages\Support\DomainName;
use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * "ask" endpoint for on-demand TLS (e.g. Caddy): 200 only for verified
 * domains of published status pages, so certificates are never issued for
 * arbitrary host names pointed at the instance.
 */
class TlsCheckController extends Controller
{
    public function __invoke(Request $request, CustomDomainRegistry $registry): Response
    {
        $domain = DomainName::normalize((string) $request->query('domain', ''));

        abort_if($domain === null || ! $registry->isServed($domain), 404);

        return response('', 200);
    }
}
