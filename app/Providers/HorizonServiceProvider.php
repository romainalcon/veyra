<?php

namespace App\Providers;

use App\Models\User;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Laravel\Horizon\HorizonApplicationServiceProvider;

class HorizonServiceProvider extends HorizonApplicationServiceProvider
{
    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        parent::boot();
    }

    /**
     * Register the Horizon gate.
     *
     * Only configured instance administrators (VEYRA_ADMIN_EMAILS) may open
     * Horizon outside the local environment.
     */
    protected function gate(): void
    {
        Gate::define('viewHorizon', function (?User $user = null): bool {
            return $user !== null
                && $user->hasVerifiedEmail()
                && in_array(Str::lower($user->email), array_map(Str::lower(...), (array) config('veyra.admin_emails')), true);
        });
    }
}
