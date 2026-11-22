<?php

namespace App\Domain\Organizations\Actions;

use App\Domain\Organizations\Models\ApiToken;

class RevokeApiToken
{
    /**
     * Revocation is immediate and permanent; the row is kept so that incident
     * timelines can still name the token (ADR-044). Idempotent.
     */
    public function handle(ApiToken $token): void
    {
        ApiToken::query()
            ->whereKey($token->id)
            ->whereNull('revoked_at')
            ->update(['revoked_at' => now()]);
    }
}
