<?php

use App\Http\Controllers\DashboardRedirectController;
use App\Http\Controllers\InvitationAcceptanceController;
use App\Http\Controllers\Organizations\InvitationController;
use App\Http\Controllers\Organizations\MemberController;
use App\Http\Controllers\Organizations\OrganizationController;
use App\Http\Middleware\ResolveOrganization;
use Illuminate\Support\Facades\Route;

Route::redirect('/', '/dashboard')->name('home');

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
        });
});

require __DIR__.'/settings.php';
