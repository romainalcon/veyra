<?php

use App\Domain\Organizations\Models\Organization;
use App\Models\User;
use Illuminate\Support\Facades\Broadcast;

/*
 * Private tenant channel. Reverb is only a delivery mechanism: clients must
 * still resynchronize over HTTP after reconnecting.
 */
Broadcast::channel('organizations.{organizationId}', function (User $user, string $organizationId): bool {
    $organization = Organization::query()
        ->whereKey($organizationId)
        ->whereHas('members', fn ($query) => $query->where('user_id', $user->id))
        ->first(['id', 'require_two_factor']);

    // Same rule as the HTTP routes: no second factor, no tenant data (ADR-040).
    return $organization !== null && (! $organization->require_two_factor || $user->hasEnabledTwoFactorAuthentication());
});
