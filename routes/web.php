<?php

use App\Http\Controllers\DashboardRedirectController;
use App\Http\Controllers\Incidents\IncidentController;
use App\Http\Controllers\InvitationAcceptanceController;
use App\Http\Controllers\Monitors\MonitorCheckController;
use App\Http\Controllers\Monitors\MonitorController;
use App\Http\Controllers\Monitors\MonitorStatusController;
use App\Http\Controllers\Notifications\NotificationChannelController;
use App\Http\Controllers\Organizations\InvitationController;
use App\Http\Controllers\Organizations\MemberController;
use App\Http\Controllers\Organizations\OrganizationController;
use App\Http\Controllers\System\HealthController;
use App\Http\Middleware\ResolveOrganization;
use Illuminate\Support\Facades\Route;

Route::redirect('/', '/dashboard')->name('home');

Route::get('health', HealthController::class)
    ->middleware('throttle:60,1')
    ->withoutMiddleware(['web'])
    ->name('health');

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
        ->middleware(ResolveOrganization::class)
        ->scopeBindings()
        ->name('organizations.')
        ->group(function () {
            Route::get('dashboard', [OrganizationController::class, 'dashboard'])->name('dashboard');

            Route::get('settings', [OrganizationController::class, 'edit'])->name('edit');
            Route::patch('settings', [OrganizationController::class, 'update'])->name('update');
            Route::delete('/', [OrganizationController::class, 'destroy'])->name('destroy');

            Route::get('members', [MemberController::class, 'index'])->name('members.index');
            Route::patch('members/{member}', [MemberController::class, 'update'])->name('members.update');
            Route::delete('members/{member}', [MemberController::class, 'destroy'])->name('members.destroy');

            Route::post('invitations', [InvitationController::class, 'store'])
                ->middleware('throttle:invitations')
                ->name('invitations.store');
            Route::delete('invitations/{invitation}', [InvitationController::class, 'destroy'])->name('invitations.destroy');

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

            Route::get('incidents', [IncidentController::class, 'index'])->name('incidents.index');
            Route::get('incidents/{incident}', [IncidentController::class, 'show'])->name('incidents.show');
            Route::post('incidents/{incident}/acknowledgement', [IncidentController::class, 'acknowledge'])
                ->middleware('throttle:30,1')
                ->name('incidents.acknowledge');
            Route::post('incidents/{incident}/notes', [IncidentController::class, 'addNote'])
                ->middleware('throttle:30,1')
                ->name('incidents.notes.store');

            Route::get('notification-channels', [NotificationChannelController::class, 'index'])->name('notification-channels.index');
            Route::post('notification-channels', [NotificationChannelController::class, 'store'])
                ->middleware('throttle:30,1')
                ->name('notification-channels.store');
            Route::put('notification-channels/{notificationChannel}', [NotificationChannelController::class, 'update'])->name('notification-channels.update');
            Route::delete('notification-channels/{notificationChannel}', [NotificationChannelController::class, 'destroy'])->name('notification-channels.destroy');
            Route::post('notification-channels/{notificationChannel}/test', [NotificationChannelController::class, 'test'])
                ->middleware('throttle:notification-tests')
                ->name('notification-channels.test');
        });
});

require __DIR__.'/settings.php';
