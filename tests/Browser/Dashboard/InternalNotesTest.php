<?php

declare(strict_types=1);

use App\Models\Booking;
use App\Models\Customer;
use Carbon\CarbonImmutable;
use Tests\Browser\Support\AuthHelper;
use Tests\Browser\Support\BusinessSetup;

// Covers: PATCH /dashboard/bookings/{booking}/notes — internal-only note
// editing from the detail panel (D-049, E2E-4).

beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2026-04-15 10:00', 'Europe/Zurich'));
});

it('saves an internal note via the detail panel', function () {
    ['business' => $business, 'admin' => $admin, 'provider' => $provider, 'service' => $service]
        = BusinessSetup::createLaunchedBusiness();

    $customer = Customer::factory()->create(['name' => 'Noteable Nora']);

    $booking = Booking::factory()->confirmed()->create([
        'business_id' => $business->id,
        'provider_id' => $provider->id,
        'service_id' => $service->id,
        'customer_id' => $customer->id,
        'starts_at' => CarbonImmutable::parse('2026-04-20 10:00', 'Europe/Zurich')->utc(),
        'ends_at' => CarbonImmutable::parse('2026-04-20 11:00', 'Europe/Zurich')->utc(),
        'internal_notes' => null,
    ]);

    $page = AuthHelper::loginAs(visit('/'), $admin);

    $page->navigate('/dashboard/bookings')
        ->click('Noteable Nora')
        ->assertSee('No internal notes yet.')
        ->press('Add')
        ->type('textarea[placeholder]', 'Likes oat milk, allergic to peanuts')
        ->pressAndWaitFor('Save note', 2);

    expect($booking->fresh()->internal_notes)
        ->toBe('Likes oat milk, allergic to peanuts');
});

it('clears an existing internal note', function () {
    ['business' => $business, 'admin' => $admin, 'provider' => $provider, 'service' => $service]
        = BusinessSetup::createLaunchedBusiness();

    $customer = Customer::factory()->create(['name' => 'Clear Cleo']);

    $booking = Booking::factory()->confirmed()->create([
        'business_id' => $business->id,
        'provider_id' => $provider->id,
        'service_id' => $service->id,
        'customer_id' => $customer->id,
        'starts_at' => CarbonImmutable::parse('2026-04-20 10:00', 'Europe/Zurich')->utc(),
        'ends_at' => CarbonImmutable::parse('2026-04-20 11:00', 'Europe/Zurich')->utc(),
        'internal_notes' => 'Previous context',
    ]);

    $page = AuthHelper::loginAs(visit('/'), $admin);

    $page->navigate('/dashboard/bookings')
        ->click('Clear Cleo')
        ->assertSee('Previous context')
        ->press('Edit')
        ->clear('textarea[placeholder]')
        ->pressAndWaitFor('Save note', 2);

    // Empty textarea POSTs either null or ''; the controller nullable rule
    // accepts both, so either is acceptable in the database.
    expect($booking->fresh()->internal_notes)->toBeIn([null, '']);
});
