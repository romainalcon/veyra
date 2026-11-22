<?php

namespace App\Domain\StatusPages\Actions;

use App\Domain\StatusPages\Models\StatusPageSubscriber;

/**
 * Idempotent: confirming twice keeps the first confirmation time.
 */
class ConfirmSubscription
{
    public function handle(StatusPageSubscriber $subscriber): void
    {
        StatusPageSubscriber::query()
            ->whereKey($subscriber->id)
            ->whereNull('confirmed_at')
            ->update(['confirmed_at' => now()]);
    }
}
