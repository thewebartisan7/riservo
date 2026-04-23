<?php

use App\Http\Middleware\EnsureBusinessCanWrite;
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

        // Third-party webhooks cannot carry a CSRF token.
        //   - Google Calendar push: authenticity via X-Goog-Channel-Id +
        //     X-Goog-Channel-Token inside GoogleCalendarWebhookController (D-086).
        //   - Stripe subscription: signature verification via Cashier's
        //     VerifyWebhookSignature middleware on the route (D-091).
        //   - Stripe Connect: signature verification inside
        //     StripeConnectWebhookController against STRIPE_CONNECT_WEBHOOK_SECRET
        //     (PAYMENTS Session 1, D-109).
        $middleware->preventRequestForgery(except: [
            'webhooks/google-calendar',
            'webhooks/stripe',
            'webhooks/stripe-connect',
        ]);

        $middleware->alias([
            'role' => EnsureUserHasRole::class,
            'onboarded' => EnsureOnboardingComplete::class,
            'billing.writable' => EnsureBusinessCanWrite::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        //
    })->create();
