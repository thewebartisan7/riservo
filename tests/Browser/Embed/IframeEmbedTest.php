<?php

declare(strict_types=1);

use App\Models\Booking;
use Tests\Browser\Support\BookingFlowHelper;
use Tests\Browser\Support\BusinessSetup;

// Covers: GET /{slug}?embed=1 and /{slug}/{service}?embed=1 (D-054, D-070, E2E-6).

it('strips the main navigation when ?embed=1 is set', function () {
    ['business' => $business] = BusinessSetup::createLaunchedBusiness();

    $page = visit('/'.$business->slug.'?embed=1');

    $page->assertSee($business->name)
        ->assertDontSee('Appointments, quietly handled')
        ->assertDontSee('Crafted in Switzerland')
        ->assertNoJavaScriptErrors();
});

it('renders the identity and booking funnel in embed mode', function () {
    ['business' => $business, 'service' => $service] = BusinessSetup::createLaunchedBusiness();

    $page = visit('/'.$business->slug.'?embed=1');

    $page->assertSee($business->name)
        ->assertSee($service->name)
        ->assertNoJavaScriptErrors();
});

it('completes a full guest booking through the iframe embed', function () {
    ['business' => $business, 'service' => $service] = BusinessSetup::createLaunchedBusiness();

    BookingFlowHelper::bookAsGuest(
        visit('/'.$business->slug.'?embed=1'),
        $business,
        $service,
        ['email' => 'embed-booker@example.com'],
    );

    expect(Booking::query()->whereRelation('customer', 'email', 'embed-booker@example.com')->exists())
        ->toBeTrue();
});

it('auto-selects a service when /{slug}/{service}?embed=1 is used (D-070)', function () {
    ['business' => $business, 'service' => $service] = BusinessSetup::createLaunchedBusiness();

    $page = visit('/'.$business->slug.'/'.$service->slug.'?embed=1');

    // Pre-selected service skips the service-picker step and lands on
    // provider or datetime. The "Service" step label must therefore be gone.
    $page->assertSee($business->name)
        ->assertDontSee('Choose a service')
        ->assertNoJavaScriptErrors();
});
