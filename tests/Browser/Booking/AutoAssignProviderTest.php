<?php

declare(strict_types=1);

use App\Models\Booking;
use Carbon\CarbonImmutable;
use Tests\Browser\Support\BookingFlowHelper;
use Tests\Browser\Support\BusinessSetup;

// Covers: D-068 server-side enforcement of allow_provider_choice=false.
// The provider-selection step is not rendered; the server auto-assigns.

beforeEach(function () {
    $next = CarbonImmutable::now('Europe/Zurich');
    if ($next->dayOfWeekIso !== 1) {
        $next = $next->next(Carbon\Carbon::MONDAY);
    }
    $this->travelTo($next->setTime(7, 30));
});

it('skips the provider step entirely when allow_provider_choice is false', function () {
    ['business' => $business, 'service' => $service, 'providers' => $providers]
        = BusinessSetup::createBusinessWithProviders(2);
    $business->update(['allow_provider_choice' => false]);

    $page = visit('/'.$business->slug);

    $page->assertSee($service->name)
        ->click($service->name);

    // The provider picker heading must NOT appear — we should jump straight to date/time.
    $page->assertDontSee('Who would you like to see?')
        ->assertSee('When works for you?')
        ->assertNoJavaScriptErrors();
});

it('creates a booking silently assigned to one of the business providers', function () {
    ['business' => $business, 'service' => $service, 'providers' => $providers]
        = BusinessSetup::createBusinessWithProviders(2);
    $business->update(['allow_provider_choice' => false]);

    $providerIds = $providers->pluck('id')->all();

    $token = BookingFlowHelper::bookAsGuest(visit('/'), $business, $service, [
        'email' => 'auto-assign@example.com',
    ]);

    $booking = Booking::where('cancellation_token', $token)->firstOrFail();
    expect($booking->provider_id)->toBeIn($providerIds);
});
