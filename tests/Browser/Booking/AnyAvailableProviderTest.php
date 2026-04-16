<?php

declare(strict_types=1);

use App\Models\Booking;
use Carbon\CarbonImmutable;
use Tests\Browser\Support\BookingFlowHelper;
use Tests\Browser\Support\BusinessSetup;

// Covers: "Any specialist" selection on the provider step (allow_provider_choice=true)
// — booking.store auto-assignment.

beforeEach(function () {
    $next = CarbonImmutable::now('Europe/Zurich');
    if ($next->dayOfWeekIso !== 1) {
        $next = $next->next(Carbon\Carbon::MONDAY);
    }
    $this->travelTo($next->setTime(7, 30));
});

it('completes a booking with "Any specialist" when provider choice is enabled and 2+ providers exist', function () {
    ['business' => $business, 'service' => $service, 'providers' => $providers]
        = BusinessSetup::createBusinessWithProviders(2);

    $providerIds = $providers->pluck('id')->all();

    $token = BookingFlowHelper::bookAsGuest(visit('/'), $business, $service, [
        'email' => 'any-available@example.com',
    ]);

    // Confirmation stays on booking/show. Booking was assigned to one of the providers.
    $booking = Booking::where('cancellation_token', $token)->firstOrFail();

    expect($booking->provider_id)->toBeIn($providerIds)
        ->and($booking->source->value)->toBe('riservo');
});
