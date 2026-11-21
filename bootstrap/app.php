<?php

use App\Domain\Entitlements\LimitReached;
use App\Http\Middleware\HandleAppearance;
use App\Http\Middleware\HandleInertiaRequests;
use App\Http\Middleware\SecurityHeaders;
use App\Http\Middleware\ServeStatusPageForCustomDomain;
use App\Http\Middleware\SetRequestLocale;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Middleware\AddLinkHeadersForPreloadedAssets;
use Illuminate\Http\Request;
use Inertia\Inertia;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        channels: __DIR__.'/../routes/channels.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->encryptCookies(except: ['appearance', 'sidebar_state']);

        $middleware->append(SecurityHeaders::class);

        // Verified custom domains only serve their status page (before any session starts).
        $middleware->append(ServeStatusPageForCustomDomain::class);

        $middleware->web(append: [
            SetRequestLocale::class,
            HandleAppearance::class,
            HandleInertiaRequests::class,
            AddLinkHeadersForPreloadedAssets::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->expectsJson(),
        );

        // Organization limits (ADR-029): back to the form with a `limit` error and a toast.
        $exceptions->render(function (LimitReached $exception, Request $request) {
            $message = $exception->translatedMessage();

            if ($request->expectsJson()) {
                return response()->json(['message' => $message, 'errors' => ['limit' => [$message]]], 422);
            }

            Inertia::flash('toast', ['type' => 'error', 'message' => $message]);

            return back()->withErrors(['limit' => $message]);
        });
    })->create();
