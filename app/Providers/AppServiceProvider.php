<?php

namespace App\Providers;

use App\Domain\Monitoring\Models\Monitor;
use App\Domain\Monitoring\Probes\Contracts\DnsResolver;
use App\Domain\Monitoring\Probes\ProbeSettings;
use App\Domain\Monitoring\Probes\SystemDnsResolver;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;
use Illuminate\Validation\Rules\Password;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->bind(DnsResolver::class, SystemDnsResolver::class);
        $this->app->bind(ProbeSettings::class, fn (): ProbeSettings => ProbeSettings::fromConfig());
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        $this->configureDefaults();
        $this->configureRateLimiting();
    }

    /**
     * Configure default behaviors for production-ready applications.
     */
    protected function configureDefaults(): void
    {
        Date::use(CarbonImmutable::class);

        DB::prohibitDestructiveCommands(
            app()->isProduction(),
        );

        Password::defaults(fn (): ?Password => app()->isProduction()
            ? Password::min(12)
                ->mixedCase()
                ->letters()
                ->numbers()
                ->symbols()
                ->uncompromised()
            : null,
        );
    }

    protected function configureRateLimiting(): void
    {
        RateLimiter::for('invitations', function (Request $request): Limit {
            $user = $request->user();

            return Limit::perHour((int) config('veyra.invitations.max_per_hour'))
                ->by($user instanceof User ? $user->id : (string) $request->ip());
        });

        RateLimiter::for('monitors', function (Request $request): Limit {
            $user = $request->user();

            return Limit::perHour((int) config('veyra.monitors.max_per_hour'))
                ->by('monitors:'.($user instanceof User ? $user->id : (string) $request->ip()));
        });

        RateLimiter::for('manual-checks', function (Request $request): array {
            $user = $request->user();
            $monitor = $request->route('monitor');

            return [
                Limit::perMinute(6)->by('manual-checks:user:'.($user instanceof User ? $user->id : (string) $request->ip())),
                Limit::perSecond(1, 30)->by('manual-checks:monitor:'.($monitor instanceof Monitor ? $monitor->id : 'unknown')),
            ];
        });
    }
}
