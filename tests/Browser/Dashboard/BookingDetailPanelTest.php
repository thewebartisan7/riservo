<?php

declare(strict_types=1);

use App\Enums\BookingSource;
use App\Models\Booking;
use App\Models\Customer;
use App\Models\User;
use Carbon\CarbonImmutable;
use Tests\Browser\Support\AuthHelper;
use Tests\Browser\Support\BusinessSetup;

// Covers: booking detail panel (booking-detail-sheet.tsx) opened from the
// bookings list — customer fields, soft-deleted provider display per D-067,
// external-source badge (E2E-4).

beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2026-04-15 10:00', 'Europe/Zurich'));
});

it('opens the detail panel with customer contact info when clicking a row', function () {
    ['business' => $business, 'admin' => $admin, 'provider' => $provider, 'service' => $service]
        = BusinessSetup::createLaunchedBusiness();

    $customer = Customer::factory()->create([
        'name' => 'Dora Detail',
        'email' => 'dora.detail@example.com',
        'phone' => '+41 79 111 22 33',
    ]);

    Booking::factory()->confirmed()->create([
        'business_id' => $business->id,
        'provider_id' => $provider->id,
        'service_id' => $service->id,
        'customer_id' => $customer->id,
        'starts_at' => CarbonImmutable::parse('2026-04-20 10:00', 'Europe/Zurich')->utc(),
        'ends_at' => CarbonImmutable::parse('2026-04-20 11:00', 'Europe/Zurich')->utc(),
        'notes' => 'Please use vegan wax.',
    ]);

    $page = AuthHelper::loginAs(visit('/'), $admin);

    $page->navigate('/dashboard/bookings')
        ->click('Dora Detail')
        ->assertSee('Dora Detail')
        ->assertSee('dora.detail@example.com')
        ->assertSee('+41 79 111 22 33')
        ->assertSee('Please use vegan wax.')
        ->assertNoJavaScriptErrors();
});

it('appends (deactivated) to a soft-deleted provider name in the panel (D-067)', function () {
    ['business' => $business, 'admin' => $admin, 'service' => $service]
        = BusinessSetup::createLaunchedBusiness();

    $ghostUser = User::factory()->create(['name' => 'Ghost Provider']);
    $ghostProvider = attachProvider($business, $ghostUser);

    $customer = Customer::factory()->create(['name' => 'Historical Hal']);

    Booking::factory()->confirmed()->create([
        'business_id' => $business->id,
        'provider_id' => $ghostProvider->id,
        'service_id' => $service->id,
        'customer_id' => $customer->id,
        'starts_at' => CarbonImmutable::parse('2026-04-20 10:00', 'Europe/Zurich')->utc(),
        'ends_at' => CarbonImmutable::parse('2026-04-20 11:00', 'Europe/Zurich')->utc(),
    ]);

    // Soft-delete the provider after the booking exists — the historical record
    // must still resolve the provider with the `(deactivated)` suffix.
    $ghostProvider->delete();

    $page = AuthHelper::loginAs(visit('/'), $admin);

    $page->navigate('/dashboard/bookings')
        ->assertSee('Ghost Provider (deactivated)')
        ->click('Historical Hal')
        ->assertSee('Ghost Provider (deactivated)')
        ->assertNoJavaScriptErrors();
});

it('renders google_calendar-sourced bookings with the Google source badge', function () {
    ['business' => $business, 'admin' => $admin, 'provider' => $provider, 'service' => $service]
        = BusinessSetup::createLaunchedBusiness();

    $customer = Customer::factory()->create(['name' => 'External Eve']);

    Booking::factory()->confirmed()->create([
        'business_id' => $business->id,
        'provider_id' => $provider->id,
        'service_id' => $service->id,
        'customer_id' => $customer->id,
        'source' => BookingSource::GoogleCalendar,
        'starts_at' => CarbonImmutable::parse('2026-04-20 10:00', 'Europe/Zurich')->utc(),
        'ends_at' => CarbonImmutable::parse('2026-04-20 11:00', 'Europe/Zurich')->utc(),
    ]);

    $page = AuthHelper::loginAs(visit('/'), $admin);

    $page->navigate('/dashboard/bookings')
        ->assertSee('External Eve')
        ->assertSee('Google')
        ->assertNoJavaScriptErrors();
});
