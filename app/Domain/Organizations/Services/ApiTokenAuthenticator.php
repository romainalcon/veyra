<?php

namespace App\Domain\Organizations\Services;

use App\Domain\Organizations\Models\ApiToken;
use App\Domain\Organizations\Support\ApiTokenSecret;
use Illuminate\Http\Request;

/**
 * Resolves the `api` guard (ADR-044) from `Authorization: Bearer veyra_…`.
 * Tokens are looked up by their SHA-256 hash only; malformed, unknown,
 * revoked and expired tokens all authenticate nobody (401). `last_used_at`
 * is written at most once a minute so that reads do not turn into writes.
 */
final class ApiTokenAuthenticator
{
    public function __invoke(Request $request): ?ApiToken
    {
        $plain = $request->bearerToken();

        if (! is_string($plain) || ! ApiTokenSecret::isWellFormed($plain)) {
            return null;
        }

        $token = ApiToken::query()
            ->usable()
            ->with('organization')
            ->where('token_hash', ApiTokenSecret::hash($plain))
            ->first();

        if ($token === null) {
            return null;
        }

        $this->recordUse($token);

        return $token;
    }

    private function recordUse(ApiToken $token): void
    {
        $now = now();

        if ($token->last_used_at !== null && $token->last_used_at->diffInSeconds($now, absolute: true) < ApiToken::LAST_USED_RESOLUTION_SECONDS) {
            return;
        }

        ApiToken::query()->whereKey($token->id)->update(['last_used_at' => $now]);
        $token->last_used_at = $now->toImmutable();
        $token->syncOriginalAttribute('last_used_at');
    }
}
