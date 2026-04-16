<?php

declare(strict_types=1);

use App\Enums\BookingStatus;
use App\Models\Booking;
use App\Models\Customer;
use Carbon\CarbonImmutable;
use Tests\Browser\Support\BookingFlowHelper;
use Tests\Browser\Support\BusinessSetup;

// Covers: GET/POST /bookings/{token} — bookings.show, bookings.cancel — D-016 cancellation window.

beforeEach(function () {
    $next = CarbonImmutable::now('Europe/Zurich');
    if ($next->dayOfWeekIso !== 1) {
        $next = $next->next(Carbon\Carbon::MONDAY);
    }
    $this->targetMonday = $next;
    $this->travelTo($next->setTime(7, 30));
});

it('displays booking details at /bookings/{token}', function () {
    ['business' => $business, 'service' => $service] = BusinessSetup::createLaunchedBusiness();

    $token = BookingFlowHelper::bookAsGuest(visit('/'), $business, $service, [
        'email' => 'details-view@example.com',
    ]);

    $page = visit('/bookings/'.$token);

    $page->assertSee('Booking details')
        ->assertSee($business->name)
        ->assertSee($service->name)
        ->assertNoJavaScriptErrors();
});

it('shows and acts on the Cancel button when within the cancellation window', function () {
    // cancellation_window_hours = 0 disables the window entirely so the button
    // always renders — controller-side travelTo() does not cross the HTTP
    // request boundary in Pest Browser, so we verify the happy-path action
    // separately from the window logic (covered below).
    ['business' => $business, 'service' => $service, 'provider' => $provider]
        = BusinessSetup::createLaunchedBusiness(['cancellation_window_hours' => 0]);

    $customer = Customer::factory()->create(['email' => 'cancel-me@example.com']);

    $startsAt = $this->targetMonday->setTime(10, 0)->setTimezone('UTC');
    $booking = Booking::factory()->confirmed()->create([
        'business_id' => $business->id,
        'provider_id' => $provider->id,
        'service_id' => $service->id,
        'customer_id' => $customer->id,
        'starts_at' => $startsAt,
        'ends_at' => $startsAt->addHour(),
    ]);

    $page = visit('/bookings/'.$booking->cancellation_token);

    $page->assertSee('Booking details')
        ->assertSee('Cancel booking')
        ->press('Cancel booking')
        ->assertSee('Booking cancelled successfully.')
        ->assertNoJavaScriptErrors();

    expect($booking->fresh()->status)->toBe(BookingStatus::Cancelled);
});

it('hides the Cancel button when outside the cancellation window', function () {
    // cancellation_window_hours = 24 — the 10:00 Monday slot is only 2.5h away, so cancel is not allowed.
    ['business' => $business, 'service' => $service, 'provider' => $provider]
        = BusinessSetup::createLaunchedBusiness(['cancellation_window_hours' => 24]);

    $customer = Customer::factory()->create(['email' => 'too-late@example.com']);

    $startsAt = $this->targetMonday->setTime(10, 0)->setTimezone('UTC');
    $booking = Booking::factory()->confirmed()->create([
        'business_id' => $business->id,
        'provider_id' => $provider->id,
        'service_id' => $service->id,
        'customer_id' => $customer->id,
        'starts_at' => $startsAt,
        'ends_at' => $startsAt->addHour(),
    ]);

    $page = visit('/bookings/'.$booking->cancellation_token);

    $page->assertSee('Booking details')
        ->assertDontSee('Cancel booking')
        ->assertNoJavaScriptErrors();
});
