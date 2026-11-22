<?php

namespace App\Http\Controllers\Organizations;

use App\Domain\Organizations\Actions\CreateApiToken;
use App\Domain\Organizations\Actions\RevokeApiToken;
use App\Domain\Organizations\Enums\ApiTokenAbility;
use App\Domain\Organizations\Models\ApiToken;
use App\Domain\Organizations\Models\Organization;
use App\Http\Controllers\Controller;
use App\Http\Requests\Organizations\StoreApiTokenRequest;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Organization API tokens (ADR-044), managed by Owners and Admins. The plain
 * token is flashed once after creation and never sent again.
 */
class ApiTokenController extends Controller
{
    public function index(Organization $organization): Response
    {
        Gate::authorize('manageApiTokens', $organization);

        $tokens = $organization->apiTokens()
            ->with('creator:id,name')
            ->whereNull('revoked_at')
            ->orderByDesc('created_at')
            ->get()
            ->map(fn (ApiToken $token): array => [
                'id' => $token->id,
                'name' => $token->name,
                'ability' => $token->ability->value,
                'masked' => $token->maskedToken(),
                'creator' => $token->creator?->name,
                'created_at' => $token->created_at?->toIso8601String(),
                'last_used_at' => $token->last_used_at?->toIso8601String(),
                'expires_at' => $token->expires_at?->toIso8601String(),
                'expired' => ! $token->isUsable(),
            ]);

        return Inertia::render('organizations/ApiTokens', [
            'tokens' => $tokens,
            'abilities' => array_map(
                fn (ApiTokenAbility $ability): array => ['value' => $ability->value, 'label' => $ability->label()],
                ApiTokenAbility::cases(),
            ),
            'expiryDays' => ApiToken::EXPIRY_DAYS,
            'maxTokens' => ApiToken::MAX_PER_ORGANIZATION,
            'apiBaseUrl' => url('api/v1'),
        ]);
    }

    public function store(StoreApiTokenRequest $request, Organization $organization, CreateApiToken $createApiToken): RedirectResponse
    {
        /** @var User $user */
        $user = $request->user();

        [, $plain] = $createApiToken->handle(
            $organization,
            $user,
            (string) $request->validated('name'),
            $request->ability(),
            $request->expiresInDays(),
        );

        Inertia::flash('apiToken', $plain);
        Inertia::flash('toast', ['type' => 'success', 'message' => __('API token created. Copy it now: it is shown only once.')]);

        return to_route('organizations.api-tokens.index', $organization);
    }

    public function destroy(Organization $organization, ApiToken $apiToken, RevokeApiToken $revokeApiToken): RedirectResponse
    {
        Gate::authorize('manageApiTokens', $organization);

        $revokeApiToken->handle($apiToken);

        Inertia::flash('toast', ['type' => 'success', 'message' => __('API token revoked.')]);

        return to_route('organizations.api-tokens.index', $organization);
    }
}
