<?php

use App\Http\Controllers\Api\V1\IncidentController;
use App\Http\Controllers\Api\V1\MaintenanceWindowController;
use App\Http\Controllers\Api\V1\MonitorCheckController;
use App\Http\Controllers\Api\V1\MonitorController;
use App\Http\Middleware\ResolveApiOrganization;
use Illuminate\Support\Facades\Route;

/*
 * Public API v1 (ADR-044), under /api: no session, no cookies, no CSRF.
 * Requests carry an organization API token (`Authorization: Bearer veyra_…`);
 * the organization is the token's and every child identifier is resolved
 * through it (scoped bindings), so other organizations' resources are 404.
 * Policies, Form Requests, quotas and SSRF rules are the interface's.
 */
Route::prefix('v1')
    ->middleware(['auth:api', ResolveApiOrganization::class, 'throttle:api'])
    ->scopeBindings()
    ->name('api.v1.')
    ->group(function () {
        Route::get('monitors', [MonitorController::class, 'index'])->name('monitors.index');
        Route::post('monitors', [MonitorController::class, 'store'])
            ->middleware('throttle:monitors')
            ->name('monitors.store');
        Route::get('monitors/{monitor}', [MonitorController::class, 'show'])->name('monitors.show');
        Route::put('monitors/{monitor}', [MonitorController::class, 'update'])->name('monitors.update');
        Route::delete('monitors/{monitor}', [MonitorController::class, 'destroy'])->name('monitors.destroy');
        Route::post('monitors/{monitor}/pause', [MonitorController::class, 'pause'])->name('monitors.pause');
        Route::post('monitors/{monitor}/resume', [MonitorController::class, 'resume'])->name('monitors.resume');
        Route::get('monitors/{monitor}/checks', [MonitorCheckController::class, 'index'])->name('monitors.checks.index');

        Route::get('incidents', [IncidentController::class, 'index'])->name('incidents.index');
        Route::get('incidents/{incident}', [IncidentController::class, 'show'])->name('incidents.show');
        Route::post('incidents/{incident}/acknowledge', [IncidentController::class, 'acknowledge'])
            ->middleware('throttle:30,1')
            ->name('incidents.acknowledge');

        Route::get('maintenance-windows', [MaintenanceWindowController::class, 'index'])->name('maintenance-windows.index');
        Route::post('maintenance-windows', [MaintenanceWindowController::class, 'store'])
            ->middleware('throttle:30,1')
            ->name('maintenance-windows.store');
        Route::get('maintenance-windows/{maintenanceWindow}', [MaintenanceWindowController::class, 'show'])->name('maintenance-windows.show');
        Route::put('maintenance-windows/{maintenanceWindow}', [MaintenanceWindowController::class, 'update'])
            ->middleware('throttle:30,1')
            ->name('maintenance-windows.update');
        Route::post('maintenance-windows/{maintenanceWindow}/cancel', [MaintenanceWindowController::class, 'cancel'])
            ->middleware('throttle:30,1')
            ->name('maintenance-windows.cancel');
        Route::post('maintenance-windows/{maintenanceWindow}/end', [MaintenanceWindowController::class, 'end'])
            ->middleware('throttle:30,1')
            ->name('maintenance-windows.end');
    });
