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
        // convention (D-091) — but the SCA / IncompletePayment recovery flow
        // and the ConfirmPayment notification both expect `route('cashier.payment')`
        // to resolve, so re-register the payment route explicitly with the
        // original path + name.
        Cashier::ignoreRoutes();

        Route::get('stripe/payment/{id}', [CashierPaymentController::class, 'show'])
            ->middleware(['web', 'auth'])
            ->name('cashier.payment');
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
