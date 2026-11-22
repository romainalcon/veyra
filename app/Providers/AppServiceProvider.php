<?php

namespace App\Providers;

use App\Domain\Entitlements\CommunityEntitlements;
use App\Domain\Entitlements\Contracts\Entitlements;
use App\Domain\Extensions\OrganizationNavigation;
use App\Domain\Extensions\PublicNavigation;
use App\Domain\Extensions\UserNavigation;
use App\Domain\Identity\Contracts\Impersonation;
use App\Domain\Identity\NoImpersonation;
use App\Domain\Incidents\Events\IncidentAcknowledged;
use App\Domain\Incidents\Events\IncidentOpened;
use App\Domain\Incidents\Events\IncidentResolved;
use App\Domain\Incidents\Events\PublicIncidentUpdatePosted;
use App\Domain\Maintenance\Events\MaintenanceWindowScheduled;
use App\Domain\Monitoring\Models\Monitor;
use App\Domain\Monitoring\Probes\Contracts\DnsQueryClient;
use App\Domain\Monitoring\Probes\Contracts\DnsResolver;
use App\Domain\Monitoring\Probes\Contracts\TcpConnector;
use App\Domain\Monitoring\Probes\HttpProbe;
use App\Domain\Monitoring\Probes\ProbeSettings;
use App\Domain\Monitoring\Probes\SocketDnsQueryClient;
use App\Domain\Monitoring\Probes\StreamTcpConnector;
use App\Domain\Monitoring\Probes\SystemDnsResolver;
use App\Domain\Notifications\Listeners\QueueNotificationsOnIncidentEvent;
use App\Domain\Notifications\Models\NotificationChannel;
use App\Domain\Organizations\Models\ApiToken;
use App\Domain\Organizations\Services\ApiTokenAuthenticator;
use App\Domain\StatusPages\Contracts\TxtResolver;
use App\Domain\StatusPages\Listeners\QueueAnnouncementsOnPublicEvent;
use App\Domain\StatusPages\Support\SystemTxtResolver;
use App\Domain\System\Benchmark\SimulatedProbe;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Middleware\TrustProxies;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Gate;
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
        $this->app->bind(Entitlements::class, CommunityEntitlements::class);
        $this->app->bind(Impersonation::class, NoImpersonation::class);
        $this->app->singleton(OrganizationNavigation::class);
        $this->app->singleton(PublicNavigation::class);
        $this->app->singleton(UserNavigation::class);
        $this->app->bind(DnsResolver::class, SystemDnsResolver::class);
        $this->app->bind(TcpConnector::class, StreamTcpConnector::class);
        $this->app->bind(DnsQueryClient::class, SocketDnsQueryClient::class);
        $this->app->bind(TxtResolver::class, SystemTxtResolver::class);
        $this->app->bind(ProbeSettings::class, fn (): ProbeSettings => ProbeSettings::fromConfig());

        // Load benchmarks only (veyra:benchmark): never available in production.
        $simulatedProbe = config('veyra.benchmark_probe');
        if (is_string($simulatedProbe) && $simulatedProbe !== '' && ! $this->app->environment('production')) {
            $this->app->bind(HttpProbe::class, fn (): SimulatedProbe => SimulatedProbe::fromSpec($simulatedProbe));
        }
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        $this->configureTrustedProxies();

        Event::listen([IncidentOpened::class, IncidentResolved::class, IncidentAcknowledged::class], QueueNotificationsOnIncidentEvent::class);
        Event::listen([IncidentOpened::class, IncidentResolved::class, PublicIncidentUpdatePosted::class, MaintenanceWindowScheduled::class], QueueAnnouncementsOnPublicEvent::class);

        // Instance administrators (VEYRA_ADMIN_EMAILS): Horizon and extension operator pages.
        Gate::define('administerInstance', fn (User $user): bool => $user->isInstanceAdministrator());

        // Organization API tokens (ADR-044): the `api` guard of config/auth.php.
        Auth::viaRequest('api-token', fn (Request $request): ?ApiToken => app(ApiTokenAuthenticator::class)($request));

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

    /**
     * Reverse proxies (Docker nginx, Vito/Nginx, load balancers) allowed to set X-Forwarded-*.
     */
    protected function configureTrustedProxies(): void
    {
        $proxies = config('veyra.trusted_proxies');

        if (is_string($proxies) && $proxies !== '') {
            TrustProxies::at($proxies === '*' ? '*' : array_map('trim', explode(',', $proxies)));
        }
    }

    protected function configureRateLimiting(): void
    {
        RateLimiter::for('invitations', function (Request $request): Limit {
            $user = $request->user();

            return Limit::perHour((int) config('veyra.invitations.max_per_hour'))
                ->by($user instanceof User ? $user->id : (string) $request->ip());
        });

        RateLimiter::for('monitors', fn (Request $request): Limit => Limit::perHour((int) config('veyra.monitors.max_per_hour'))
            ->by('monitors:'.self::actorKey($request)));

        // Per token (ADR-044); every API request also counts towards the IP limit.
        RateLimiter::for('api', function (Request $request): array {
            $token = $request->user('api');

            return [
                Limit::perMinute((int) config('veyra.api.requests_per_minute'))
                    ->by('api:token:'.($token instanceof ApiToken ? $token->id : (string) $request->ip())),
                Limit::perMinute((int) config('veyra.api.requests_per_minute_per_ip'))->by('api:ip:'.$request->ip()),
            ];
        });

        RateLimiter::for('heartbeat-pings', fn (Request $request): array => [
            Limit::perMinute(30)->by('heartbeat-pings:'.hash('sha256', (string) $request->route('token'))),
            Limit::perMinute(600)->by('heartbeat-pings-ip:'.$request->ip()),
        ]);

        RateLimiter::for('ping-tokens', function (Request $request): Limit {
            $user = $request->user();

            return Limit::perMinute(10)->by('ping-tokens:'.($user instanceof User ? $user->id : (string) $request->ip()));
        });

        RateLimiter::for('status-pages', fn (Request $request): Limit => Limit::perMinute(120)->by('status-pages:'.$request->ip()));

        RateLimiter::for('status-page-subscribe', fn (Request $request): Limit => Limit::perHour((int) config('veyra.status_pages.subscriptions_per_ip_per_hour', 5))
            ->by('status-page-subscribe:'.$request->ip())
            ->response(fn () => response()->json(['message' => __('Too many subscription requests. Please try again later.')], 429)));

        RateLimiter::for('status-pages-create', function (Request $request): Limit {
            $user = $request->user();

            return Limit::perHour(10)->by('status-pages-create:'.($user instanceof User ? $user->id : (string) $request->ip()));
        });

        RateLimiter::for('webhook-secrets', function (Request $request): Limit {
            $user = $request->user();

            return Limit::perMinute(10)->by('webhook-secrets:'.($user instanceof User ? $user->id : (string) $request->ip()));
        });

        RateLimiter::for('notification-tests', function (Request $request): Limit {
            $channel = $request->route('notificationChannel');

            return Limit::perMinute(5)->by('notification-tests:'.($channel instanceof NotificationChannel ? $channel->id : (string) $request->ip()));
        });

        RateLimiter::for('manual-checks', function (Request $request): array {
            $monitor = $request->route('monitor');

            return [
                Limit::perMinute(6)->by('manual-checks:user:'.self::actorKey($request)),
                Limit::perSecond(1, 30)->by('manual-checks:monitor:'.($monitor instanceof Monitor ? $monitor->id : 'unknown')),
            ];
        });
    }

    /**
     * Members are limited per user, API tokens per token (ADR-044), anyone else per IP.
     */
    private static function actorKey(Request $request): string
    {
        $actor = $request->user() ?? $request->user('api');

        return match (true) {
            $actor instanceof User => $actor->id,
            $actor instanceof ApiToken => 'token:'.$actor->id,
            default => (string) $request->ip(),
        };
    }
}
