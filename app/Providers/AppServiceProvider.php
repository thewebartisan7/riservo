<?php

namespace App\Providers;

use App\Models\Business;
use App\Services\Calendar\CalendarProviderFactory;
use App\Support\TenantContext;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;
use Laravel\Cashier\Cashier;
use Laravel\Cashier\Http\Controllers\PaymentController as CashierPaymentController;
use Stripe\StripeClient;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->scoped(TenantContext::class);
        $this->app->singleton(CalendarProviderFactory::class);

        // Cashier auto-registers `/stripe/webhook` AND `/stripe/payment/{id}`.
        // Suppress both so we can mount the webhook under our `/webhooks/*`
        // convention (D-091).
        //
        // Re-register both Cashier-named routes to preserve the `cashier.*`
        // route name contract (Codex Round-6, D-135):
        //  - `cashier.payment` is needed by the SCA / IncompletePayment
        //    recovery flow and the ConfirmPayment notification, both of
        //    which call `route('cashier.payment')` on Cashier's billable.
        //    The path uses `config('cashier.path')` (default 'stripe',
        //    env-tunable via CASHIER_PATH) so deployments that rebrand the
        //    Cashier path don't break the URL.
        //  - `cashier.webhook` is referenced by Cashier's own internals
        //    (e.g. `cashier:webhook` artisan command). We point it at our
        //    `/webhooks/stripe` controller so the route name resolves
        //    while traffic still routes through our event-id-deduped
        //    handler.
        Cashier::ignoreRoutes();

        $cashierPath = trim((string) config('cashier.path', 'stripe'), '/');

        Route::get($cashierPath.'/payment/{id}', [CashierPaymentController::class, 'show'])
            ->middleware(['web', 'auth'])
            ->name('cashier.payment');

        // Default-bind StripeClient so controllers (Connect onboarding and
        // webhook controllers in PAYMENTS) that type-hint the class resolve a
        // configured client in production. In tests, FakeStripeClient replaces
        // this binding with a Mockery double (D-095). Do NOT re-enter
        // Cashier::stripe() here — Cashier itself calls
        // `app(StripeClient::class, ['config' => $config])`, and a closure that
        // ignores parameters would infinite-loop through Cashier's resolver.
        // Instantiating directly with the configured api key matches the
        // options Cashier would pass and keeps the resolver cycle broken.
        $this->app->bind(StripeClient::class, function () {
            return new StripeClient([
                'api_key' => config('cashier.secret'),
                'stripe_version' => Cashier::STRIPE_VERSION,
            ]);
        });
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        RateLimiter::for('booking-api', fn (Request $request) => Limit::perMinute(60)->by($request->ip()));

        RateLimiter::for('booking-create', fn (Request $request) => Limit::perMinute(5)->by($request->ip()));

        // Billable model is the Business, not the User (D-007).
        Cashier::useCustomerModel(Business::class);

        // Stripe Tax handles Swiss VAT automatically on Checkout + invoices (D-094).
        Cashier::calculateTaxes();
    }
}
