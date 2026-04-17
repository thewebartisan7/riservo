<?php

use App\Http\Middleware\EnsureOnboardingComplete;
use App\Http\Middleware\EnsureUserHasRole;
use App\Http\Middleware\HandleInertiaRequests;
use App\Http\Middleware\ResolveTenantContext;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->web(append: [
            ResolveTenantContext::class,
            HandleInertiaRequests::class,
        ]);

        // Google Calendar push-notification webhook cannot carry a CSRF token.
        // Authenticity is enforced by X-Goog-Channel-Id + X-Goog-Channel-Token
        // inside GoogleCalendarWebhookController (D-086).
        $middleware->preventRequestForgery(except: [
            'webhooks/google-calendar',
        ]);

        $middleware->alias([
            'role' => EnsureUserHasRole::class,
            'onboarded' => EnsureOnboardingComplete::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        //
    })->create();
