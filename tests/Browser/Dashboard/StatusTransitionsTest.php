<?php

declare(strict_types=1);

use App\Enums\BookingStatus;
use App\Models\Booking;
use App\Models\Customer;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Notification;
use Tests\Browser\Support\AuthHelper;
use Tests\Browser\Support\BusinessSetup;

// Covers: PATCH /dashboard/bookings/{booking}/status via the booking detail
// panel's action buttons (E2E-4).

beforeEach(function () {
    Notification::fake();
    $this->travelTo(CarbonImmutable::parse('2026-04-15 10:00', 'Europe/Zurich'));
});

it('confirms a pending booking from the detail panel', function () {
    ['business' => $business, 'admin' => $admin, 'provider' => $provider, 'service' => $service]
        = BusinessSetup::createLaunchedBusiness();

    $customer = Customer::factory()->create(['name' => 'Pending Pete']);

    $booking = Booking::factory()->pending()->create([
        'business_id' => $business->id,
        'provider_id' => $provider->id,
        'service_id' => $service->id,
        'customer_id' => $customer->id,
        'starts_at' => CarbonImmutable::parse('2026-04-20 10:00', 'Europe/Zurich')->utc(),
        'ends_at' => CarbonImmutable::parse('2026-04-20 11:00', 'Europe/Zurich')->utc(),
    ]);

    $page = AuthHelper::loginAs(visit('/'), $admin);

    $page->navigate('/dashboard/bookings')
        ->click('Pending Pete')
        ->pressAndWaitFor('Confirm', 2);

    expect($booking->fresh()->status)->toBe(BookingStatus::Confirmed);
});

it('cancels a confirmed booking from the detail panel', function () {
    ['business' => $business, 'admin' => $admin, 'provider' => $provider, 'service' => $service]
        = BusinessSetup::createLaunchedBusiness();

    $customer = Customer::factory()->create(['name' => 'Cancel Cassie']);

    $booking = Booking::factory()->confirmed()->create([
        'business_id' => $business->id,
        'provider_id' => $provider->id,
        'service_id' => $service->id,
        'customer_id' => $customer->id,
        'starts_at' => CarbonImmutable::parse('2026-04-20 10:00', 'Europe/Zurich')->utc(),
        'ends_at' => CarbonImmutable::parse('2026-04-20 11:00', 'Europe/Zurich')->utc(),
    ]);

    $page = AuthHelper::loginAs(visit('/'), $admin);

    $page->navigate('/dashboard/bookings')
        ->click('Cancel Cassie')
        ->pressAndWaitFor('Cancel', 2);

    expect($booking->fresh()->status)->toBe(BookingStatus::Cancelled);
});

it('marks a confirmed booking as completed from the detail panel', function () {
    ['business' => $business, 'admin' => $admin, 'provider' => $provider, 'service' => $service]
        = BusinessSetup::createLaunchedBusiness();

    $customer = Customer::factory()->create(['name' => 'Complete Connor']);

    $booking = Booking::factory()->confirmed()->create([
        'business_id' => $business->id,
        'provider_id' => $provider->id,
        'service_id' => $service->id,
        'customer_id' => $customer->id,
        'starts_at' => CarbonImmutable::parse('2026-04-20 10:00', 'Europe/Zurich')->utc(),
        'ends_at' => CarbonImmutable::parse('2026-04-20 11:00', 'Europe/Zurich')->utc(),
    ]);

    $page = AuthHelper::loginAs(visit('/'), $admin);

    $page->navigate('/dashboard/bookings')
        ->click('Complete Connor')
        ->pressAndWaitFor('Mark complete', 2);

    expect($booking->fresh()->status)->toBe(BookingStatus::Completed);
});

it('marks a confirmed booking as no-show from the detail panel', function () {
    ['business' => $business, 'admin' => $admin, 'provider' => $provider, 'service' => $service]
        = BusinessSetup::createLaunchedBusiness();

    $customer = Customer::factory()->create(['name' => 'Noshow Nadia']);

    $booking = Booking::factory()->confirmed()->create([
        'business_id' => $business->id,
        'provider_id' => $provider->id,
        'service_id' => $service->id,
        'customer_id' => $customer->id,
        'starts_at' => CarbonImmutable::parse('2026-04-20 10:00', 'Europe/Zurich')->utc(),
        'ends_at' => CarbonImmutable::parse('2026-04-20 11:00', 'Europe/Zurich')->utc(),
    ]);

    $page = AuthHelper::loginAs(visit('/'), $admin);

    $page->navigate('/dashboard/bookings')
        ->click('Noshow Nadia')
        ->pressAndWaitFor('No show', 2);

    expect($booking->fresh()->status)->toBe(BookingStatus::NoShow);
});

it('does not show status action buttons for a cancelled booking', function () {
    ['business' => $business, 'admin' => $admin, 'provider' => $provider, 'service' => $service]
        = BusinessSetup::createLaunchedBusiness();

    $customer = Customer::factory()->create(['name' => 'Already Gone']);

    Booking::factory()->cancelled()->create([
        'business_id' => $business->id,
        'provider_id' => $provider->id,
        'service_id' => $service->id,
        'customer_id' => $customer->id,
        'starts_at' => CarbonImmutable::parse('2026-04-20 10:00', 'Europe/Zurich')->utc(),
        'ends_at' => CarbonImmutable::parse('2026-04-20 11:00', 'Europe/Zurich')->utc(),
    ]);

    $page = AuthHelper::loginAs(visit('/'), $admin);

    $page->navigate('/dashboard/bookings')
        ->click('Already Gone')
        ->assertSee('Already Gone')
        // A cancelled booking has no transitions (see allowedTransitions() on
        // BookingStatus), so none of the action labels should render in the panel footer.
        ->assertDontSee('Mark complete')
        ->assertNoJavaScriptErrors();
});
