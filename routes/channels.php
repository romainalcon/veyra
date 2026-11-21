<?php

use App\Models\User;
use Illuminate\Support\Facades\Broadcast;

/*
 * Private tenant channel. Reverb is only a delivery mechanism: clients must
 * still resynchronize over HTTP after reconnecting.
 */
Broadcast::channel('organizations.{organizationId}', function (User $user, string $organizationId): bool {
    return $user->memberships()->where('organization_id', $organizationId)->exists();
});
