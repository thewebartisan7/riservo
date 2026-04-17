<?php

namespace App\Services\Calendar;

use App\Models\CalendarIntegration;
use App\Services\Calendar\Exceptions\UnsupportedCalendarProviderException;
use Illuminate\Contracts\Container\Container;

/**
 * Resolves a CalendarProvider implementation for a given CalendarIntegration
 * (D-082). Singleton-bound in AppServiceProvider.
 *
 * A future provider (Outlook / Apple via CalDAV) is purely additive: one case
 * here + one class registered in the container. The rest of the codebase
 * depends on CalendarProvider (the interface), never on a concrete class.
 */
class CalendarProviderFactory
{
    public function __construct(
        private readonly Container $container,
    ) {}

    public function for(CalendarIntegration $integration): CalendarProvider
    {
        return match ($integration->provider) {
            'google' => $this->container->make(GoogleCalendarProvider::class),
            default => throw UnsupportedCalendarProviderException::forProvider($integration->provider),
        };
    }
}
