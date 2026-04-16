<?php

declare(strict_types=1);

use App\Models\Booking;
use App\Models\Customer;
use Carbon\CarbonImmutable;
use Tests\Browser\Support\BusinessSetup;

// Covers: wall-clock rendering uses the business timezone regardless of the
// browser's system timezone (D-005, D-030, D-071, E2E-6).

it('renders a booking at 10:00 business-local time even with a non-matching browser timezone', function () {
    ['business' => $business, 'provider' => $provider, 'service' => $service]
        = BusinessSetup::createLaunchedBusiness(['timezone' => 'Europe/Zurich']);

    $customer = Customer::factory()->create(['email' => 'tz-check@example.com']);

    // 10:00 Zurich on a fixed future Monday → the booking page should display
    // 10:00 even when the browser is reporting America/New_York as its TZ.
    $startsLocal = CarbonImmutable::parse('2026-06-08 10:00', 'Europe/Zurich');
    $booking = Booking::factory()->confirmed()->create([
        'business_id' => $business->id,
        'provider_id' => $provider->id,
        'service_id' => $service->id,
        'customer_id' => $customer->id,
        'starts_at' => $startsLocal->utc(),
        'ends_at' => $startsLocal->addHour()->utc(),
    ]);

    visit('/bookings/'.$booking->cancellation_token)
        ->withTimezone('America/New_York')
        ->assertSee('10:00')
        ->assertNoJavaScriptErrors();
});
