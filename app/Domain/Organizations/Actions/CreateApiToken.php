<?php

namespace App\Domain\Organizations\Actions;

use App\Domain\Organizations\Enums\ApiTokenAbility;
use App\Domain\Organizations\Models\ApiToken;
use App\Domain\Organizations\Models\Organization;
use App\Domain\Organizations\Support\ApiTokenSecret;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class CreateApiToken
{
    /**
     * Creates a token and returns it with its plain value, which is never
     * stored and must be shown once (ADR-044). The organization row is
     * locked so that concurrent requests cannot exceed the token cap.
     *
     * @return array{0: ApiToken, 1: string}
     *
     * @throws ValidationException
     */
    public function handle(Organization $organization, User $creator, string $name, ApiTokenAbility $ability, ?int $expiresInDays): array
    {
        return DB::transaction(function () use ($organization, $creator, $name, $ability, $expiresInDays): array {
            Organization::query()->whereKey($organization->id)->lockForUpdate()->first();

            if ($organization->apiTokens()->whereNull('revoked_at')->count() >= ApiToken::MAX_PER_ORGANIZATION) {
                throw ValidationException::withMessages([
                    'name' => __('An organization can have at most :count active API tokens.', ['count' => ApiToken::MAX_PER_ORGANIZATION]),
                ]);
            }

            $plain = ApiTokenSecret::generate();

            $token = $organization->apiTokens()->create([
                'name' => $name,
                'ability' => $ability,
                'token_hash' => ApiTokenSecret::hash($plain),
                'last_four' => substr($plain, -4),
                'created_by' => $creator->id,
                'expires_at' => $expiresInDays === null ? null : CarbonImmutable::now()->addDays($expiresInDays)->startOfSecond(),
            ]);

            return [$token, $plain];
        });
    }
}
