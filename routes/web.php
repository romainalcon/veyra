<?php

use App\Http\Controllers\DashboardRedirectController;
use App\Http\Controllers\HomeController;
use App\Http\Controllers\Incidents\IncidentController;
use App\Http\Controllers\InvitationAcceptanceController;
use App\Http\Controllers\Maintenance\MaintenanceSeriesController;
use App\Http\Controllers\Maintenance\MaintenanceWindowController;
use App\Http\Controllers\Monitors\HeartbeatPingController;
use App\Http\Controllers\Monitors\HeartbeatTokenController;
use App\Http\Controllers\Monitors\MonitorCheckController;
use App\Http\Controllers\Monitors\MonitorController;
use App\Http\Controllers\Monitors\MonitorStatusController;
use App\Http\Controllers\Notifications\NotificationChannelController;
use App\Http\Controllers\Notifications\WebhookSecretController;
use App\Http\Controllers\Organizations\ApiTokenController;
use App\Http\Controllers\Organizations\InvitationController;
use App\Http\Controllers\Organizations\MemberController;
use App\Http\Controllers\Organizations\OrganizationController;
use App\Http\Controllers\Organizations\TwoFactorRequirementController;
use App\Http\Controllers\StatusPages\PublicStatusPageAssetController;
use App\Http\Controllers\StatusPages\PublicStatusPageController;
use App\Http\Controllers\StatusPages\StatusPageAssetController;
use App\Http\Controllers\StatusPages\StatusPageBadgeController;
use App\Http\Controllers\StatusPages\StatusPageController;
use App\Http\Controllers\StatusPages\StatusPageDomainController;
use App\Http\Controllers\StatusPages\StatusPageFeedController;
use App\Http\Controllers\StatusPages\StatusPageSubscriptionController;
use App\Http\Controllers\StatusPages\TlsCheckController;
use App\Http\Controllers\System\HealthController;
use App\Http\Middleware\EnsureOrganizationTwoFactor;
use App\Http\Middleware\HandleAppearance;
use App\Http\Middleware\HandlePublicInertiaRequests;
use App\Http\Middleware\ResolveOrganization;
use Illuminate\Support\Facades\Route;

Route::get('/', HomeController::class)->name('home');

Route::get('health', HealthController::class)
    ->middleware('throttle:60,1')
    ->withoutMiddleware(['web'])
    ->name('health');

/*
 * Public status pages: no session, no cookies, no CSRF, nothing user-related
 * shared with the page (see HandlePublicInertiaRequests).
 */
Route::get('status/{slug}', PublicStatusPageController::class)
    ->where('slug', '[a-z0-9-]{3,60}')
    ->withoutMiddleware(['web'])
    ->middleware(['throttle:status-pages', HandleAppearance::class, HandlePublicInertiaRequests::class])
    ->name('status-pages.public');

/*
 * Status page audience (ADR-036): feeds, badges, branding images and visitor
 * subscriptions, as sessionless as the page itself. Verified custom domains
 * reach the same controllers through ServeStatusPageForCustomDomain.
 */
Route::withoutMiddleware(['web'])->middleware('throttle:status-pages')->group(function () {
    Route::get('status/{slug}/feed.{format}', StatusPageFeedController::class)
        ->where(['slug' => '[a-z0-9-]{3,60}', 'format' => 'atom|rss'])
        ->name('status-pages.feed');

    Route::get('status/{slug}/badges/{component}/{badge}.svg', StatusPageBadgeController::class)
        ->where(['slug' => '[a-z0-9-]{3,60}', 'component' => '[0-9a-f-]{36}', 'badge' => implode('|', StatusPageBadgeController::BADGES)])
        ->name('status-pages.badge');

    Route::get('status/{slug}/assets/{file}', PublicStatusPageAssetController::class)
        ->where(['slug' => '[a-z0-9-]{3,60}', 'file' => PublicStatusPageAssetController::FILE_PATTERN])
        ->name('status-pages.asset');
});

Route::post('status/{slug}/subscribe', [StatusPageSubscriptionController::class, 'subscribe'])
    ->where('slug', '[a-z0-9-]{3,60}')
    ->withoutMiddleware(['web'])
    ->middleware('throttle:status-page-subscribe')
    ->name('status-pages.subscribe');

Route::withoutMiddleware(['web'])
    ->middleware(['throttle:status-pages', HandleAppearance::class, HandlePublicInertiaRequests::class])
    ->prefix('status-subscriptions/{subscriber}')
    ->where(['subscriber' => '[0-9a-f-]{36}'])
    ->group(function () {
        Route::get('confirm', [StatusPageSubscriptionController::class, 'showConfirm'])->name('status-pages.subscriptions.confirm');
        Route::post('confirm', [StatusPageSubscriptionController::class, 'confirm']);
        Route::get('unsubscribe', [StatusPageSubscriptionController::class, 'showUnsubscribe'])->name('status-pages.subscriptions.unsubscribe');
        Route::post('unsubscribe', [StatusPageSubscriptionController::class, 'unsubscribe']);
    });

/*
 * Heartbeat pings: no session, no cookies, no CSRF (cron jobs and scripts).
 */
Route::match(['GET', 'POST', 'HEAD'], 'ping/{token}', HeartbeatPingController::class)
    ->where('token', '[A-Za-z0-9]{40}')
    ->withoutMiddleware(['web'])
    ->middleware('throttle:heartbeat-pings')
    ->name('heartbeats.ping');

Route::match(['GET', 'POST', 'HEAD'], 'ping/{token}/fail', [HeartbeatPingController::class, 'fail'])
    ->where('token', '[A-Za-z0-9]{40}')
    ->withoutMiddleware(['web'])
    ->middleware('throttle:heartbeat-pings')
    ->name('heartbeats.fail');

/*
 * On-demand TLS "ask" endpoint for the reverse proxy (see docs/deployment).
 */
Route::get('status-domains/tls-check', TlsCheckController::class)
    ->middleware('throttle:60,1')
    ->withoutMiddleware(['web'])
    ->name('status-domains.tls-check');

Route::get('invitations/{token}', [InvitationAcceptanceController::class, 'show'])
    ->middleware('throttle:30,1')
    ->name('invitations.show');

Route::middleware(['auth', 'verified'])->group(function () {
    Route::get('dashboard', DashboardRedirectController::class)->name('dashboard');

    Route::post('invitations/{token}/accept', [InvitationAcceptanceController::class, 'store'])
        ->middleware('throttle:10,1')
        ->name('invitations.accept');

    Route::get('organizations/create', [OrganizationController::class, 'create'])->name('organizations.create');
    Route::post('organizations', [OrganizationController::class, 'store'])
        ->middleware('throttle:10,1')
        ->name('organizations.store');

    /*
     * Tenant routes: every route below is resolved through the
     * `{organization}` slug and requires membership (404 otherwise).
     * Child resources are scoped to the organization via scoped bindings.
     */
    Route::prefix('o/{organization}')
        ->middleware([ResolveOrganization::class, EnsureOrganizationTwoFactor::class])
        ->scopeBindings()
        ->name('organizations.')
        ->group(function () {
            Route::get('dashboard', [OrganizationController::class, 'dashboard'])->name('dashboard');

            Route::get('settings', [OrganizationController::class, 'edit'])->name('edit');
            Route::patch('settings', [OrganizationController::class, 'update'])->name('update');
            Route::put('settings/two-factor', [TwoFactorRequirementController::class, 'update'])->name('two-factor.update');
            Route::delete('/', [OrganizationController::class, 'destroy'])->name('destroy');

            Route::get('members', [MemberController::class, 'index'])->name('members.index');
            Route::patch('members/{member}', [MemberController::class, 'update'])->name('members.update');
            Route::delete('members/{member}', [MemberController::class, 'destroy'])->name('members.destroy');

            Route::post('invitations', [InvitationController::class, 'store'])
                ->middleware('throttle:invitations')
                ->name('invitations.store');
            Route::delete('invitations/{invitation}', [InvitationController::class, 'destroy'])->name('invitations.destroy');

            Route::get('api-tokens', [ApiTokenController::class, 'index'])->name('api-tokens.index');
            Route::post('api-tokens', [ApiTokenController::class, 'store'])
                ->middleware('throttle:10,1')
                ->name('api-tokens.store');
            Route::delete('api-tokens/{apiToken}', [ApiTokenController::class, 'destroy'])->name('api-tokens.destroy');

            Route::get('monitors', [MonitorController::class, 'index'])->name('monitors.index');
            Route::get('monitors/create', [MonitorController::class, 'create'])->name('monitors.create');
            Route::post('monitors', [MonitorController::class, 'store'])
                ->middleware('throttle:monitors')
                ->name('monitors.store');
            Route::get('monitors/{monitor}', [MonitorController::class, 'show'])->name('monitors.show');
            Route::get('monitors/{monitor}/edit', [MonitorController::class, 'edit'])->name('monitors.edit');
            Route::put('monitors/{monitor}', [MonitorController::class, 'update'])->name('monitors.update');
            Route::delete('monitors/{monitor}', [MonitorController::class, 'destroy'])->name('monitors.destroy');
            Route::patch('monitors/{monitor}/enabled', MonitorStatusController::class)->name('monitors.enabled');
            Route::post('monitors/{monitor}/checks', [MonitorCheckController::class, 'store'])
                ->middleware('throttle:manual-checks')
                ->name('monitors.checks.store');
            Route::post('monitors/{monitor}/ping-token', [HeartbeatTokenController::class, 'store'])
                ->middleware('throttle:ping-tokens')
                ->name('monitors.ping-token.store');

            Route::get('maintenance', [MaintenanceWindowController::class, 'index'])->name('maintenance.index');
            Route::get('maintenance/series/create', [MaintenanceSeriesController::class, 'create'])->name('maintenance.series.create');
            Route::post('maintenance/series', [MaintenanceSeriesController::class, 'store'])
                ->middleware('throttle:30,1')
                ->name('maintenance.series.store');
            Route::get('maintenance/series/{maintenanceSeries}/edit', [MaintenanceSeriesController::class, 'edit'])->name('maintenance.series.edit');
            Route::put('maintenance/series/{maintenanceSeries}', [MaintenanceSeriesController::class, 'update'])
                ->middleware('throttle:30,1')
                ->name('maintenance.series.update');
            Route::delete('maintenance/series/{maintenanceSeries}', [MaintenanceSeriesController::class, 'destroy'])
                ->middleware('throttle:30,1')
                ->name('maintenance.series.destroy');
            Route::get('maintenance/create', [MaintenanceWindowController::class, 'create'])->name('maintenance.create');
            Route::post('maintenance', [MaintenanceWindowController::class, 'store'])
                ->middleware('throttle:30,1')
                ->name('maintenance.store');
            Route::get('maintenance/{maintenanceWindow}/edit', [MaintenanceWindowController::class, 'edit'])->name('maintenance.edit');
            Route::put('maintenance/{maintenanceWindow}', [MaintenanceWindowController::class, 'update'])
                ->middleware('throttle:30,1')
                ->name('maintenance.update');
            Route::post('maintenance/{maintenanceWindow}/cancel', [MaintenanceWindowController::class, 'cancel'])
                ->middleware('throttle:30,1')
                ->name('maintenance.cancel');
            Route::post('maintenance/{maintenanceWindow}/end', [MaintenanceWindowController::class, 'end'])
                ->middleware('throttle:30,1')
                ->name('maintenance.end');

            Route::get('incidents', [IncidentController::class, 'index'])->name('incidents.index');
            Route::get('incidents/{incident}', [IncidentController::class, 'show'])->name('incidents.show');
            Route::post('incidents/{incident}/acknowledgement', [IncidentController::class, 'acknowledge'])
                ->middleware('throttle:30,1')
                ->name('incidents.acknowledge');
            Route::post('incidents/{incident}/notes', [IncidentController::class, 'addNote'])
                ->middleware('throttle:30,1')
                ->name('incidents.notes.store');
            Route::post('incidents/{incident}/public-updates', [IncidentController::class, 'postPublicUpdate'])
                ->middleware('throttle:30,1')
                ->name('incidents.public-updates.store');

            Route::get('status-pages', [StatusPageController::class, 'index'])->name('status-pages.index');
            Route::get('status-pages/create', [StatusPageController::class, 'create'])->name('status-pages.create');
            Route::post('status-pages', [StatusPageController::class, 'store'])
                ->middleware('throttle:status-pages-create')
                ->name('status-pages.store');
            Route::get('status-pages/{statusPage}/edit', [StatusPageController::class, 'edit'])->name('status-pages.edit');
            Route::put('status-pages/{statusPage}', [StatusPageController::class, 'update'])
                ->middleware('throttle:30,1')
                ->name('status-pages.update');
            Route::delete('status-pages/{statusPage}', [StatusPageController::class, 'destroy'])->name('status-pages.destroy');
            Route::put('status-pages/{statusPage}/domain', [StatusPageDomainController::class, 'update'])
                ->middleware('throttle:10,1')
                ->name('status-pages.domain.update');
            Route::delete('status-pages/{statusPage}/domain', [StatusPageDomainController::class, 'destroy'])->name('status-pages.domain.destroy');
            Route::post('status-pages/{statusPage}/domain/verify', [StatusPageDomainController::class, 'verify'])
                ->middleware('throttle:10,1')
                ->name('status-pages.domain.verify');
            Route::get('status-pages/{statusPage}/assets/{kind}', [StatusPageAssetController::class, 'show'])
                ->where('kind', 'logo|favicon')
                ->name('status-pages.assets.show');
            Route::post('status-pages/{statusPage}/assets/{kind}', [StatusPageAssetController::class, 'store'])
                ->where('kind', 'logo|favicon')
                ->middleware('throttle:20,1')
                ->name('status-pages.assets.store');
            Route::delete('status-pages/{statusPage}/assets/{kind}', [StatusPageAssetController::class, 'destroy'])
                ->where('kind', 'logo|favicon')
                ->name('status-pages.assets.destroy');

            Route::get('notification-channels', [NotificationChannelController::class, 'index'])->name('notification-channels.index');
            Route::post('notification-channels', [NotificationChannelController::class, 'store'])
                ->middleware('throttle:30,1')
                ->name('notification-channels.store');
            Route::put('notification-channels/{notificationChannel}', [NotificationChannelController::class, 'update'])->name('notification-channels.update');
            Route::delete('notification-channels/{notificationChannel}', [NotificationChannelController::class, 'destroy'])->name('notification-channels.destroy');
            Route::post('notification-channels/{notificationChannel}/test', [NotificationChannelController::class, 'test'])
                ->middleware('throttle:notification-tests')
                ->name('notification-channels.test');
            Route::post('notification-channels/{notificationChannel}/signing-secret', [WebhookSecretController::class, 'store'])
                ->middleware('throttle:webhook-secrets')
                ->name('notification-channels.signing-secret.store');
        });
});

require __DIR__.'/settings.php';
