<?php

namespace App\Providers;

use App\Services\Calendar\CalendarProviderFactory;
use App\Support\TenantContext;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->scoped(TenantContext::class);
        $this->app->singleton(CalendarProviderFactory::class);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        RateLimiter::for('booking-api', fn (Request $request) => Limit::perMinute(60)->by($request->ip()));

        RateLimiter::for('booking-create', fn (Request $request) => Limit::perMinute(5)->by($request->ip()));
    }
}
