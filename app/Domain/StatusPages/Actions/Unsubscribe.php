<?php

namespace App\Domain\StatusPages\Actions;

use App\Domain\StatusPages\Models\StatusPageSubscriber;

/**
 * Removes the subscriber (and its pending deliveries, by cascade). Unknown
 * or already removed subscribers are not an error.
 */
class Unsubscribe
{
    public function handle(string $subscriberId): void
    {
        StatusPageSubscriber::query()->whereKey($subscriberId)->delete();
    }
}
