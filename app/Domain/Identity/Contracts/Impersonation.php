<?php

namespace App\Domain\Identity\Contracts;

use Illuminate\Http\Request;

/**
 * Whether the request is an operator acting as another user. The Community
 * core binds NoImpersonation (never); an optional extension that offers
 * impersonation binds its own implementation and owns starting, stopping,
 * guarding and auditing it (ADR-038).
 */
interface Impersonation
{
    /**
     * The impersonation in progress, shown as a banner on every application
     * page: the operator's e-mail and the URL that ends it (POST).
     *
     * @return array{operator: string, stopUrl: string}|null
     */
    public function current(Request $request): ?array;
}
