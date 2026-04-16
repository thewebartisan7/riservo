<?php

declare(strict_types=1);

use App\Models\Booking;
use App\Models\Customer;
use App\Models\User;
use Carbon\CarbonImmutable;
use Tests\Browser\Support\AuthHelper;
use Tests\Browser\Support\BusinessSetup;

// Covers: staff scope on /dashboard/calendar — staff linked to a provider see
// only their own bookings; staff with no provider see an empty calendar (E2E-4).

beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2026-04-15 10:00', 'Europe/Zurich'));
});

it('limits the calendar to the staff member\'s own bookings when they are also a provider', function () {
    ['business' => $business, 'provider' => $adminProvider, 'service' => $service]
        = BusinessSetup::createLaunchedBusiness();

    $staffUser = User::factory()->create();
    $staffProvider = attachProvider($business, $staffUser);
    $staffProvider->services()->attach($service);

    $mine = Customer::factory()->create(['name' => 'My Calendar Client']);
    $theirs = Customer::factory()->create(['name' => 'Admin Calendar Client']);

    Booking::factory()->confirmed()->create([
        'business_id' => $business->id,
        'provider_id' => $staffProvider->id,
        'service_id' => $service->id,
        'customer_id' => $mine->id,
        'starts_at' => CarbonImmutable::parse('2026-04-15 10:00', 'Europe/Zurich')->utc(),
        'ends_at' => CarbonImmutable::parse('2026-04-15 11:00', 'Europe/Zurich')->utc(),
    ]);

    Booking::factory()->confirmed()->create([
        'business_id' => $business->id,
        'provider_id' => $adminProvider->id,
        'service_id' => $service->id,
        'customer_id' => $theirs->id,
        'starts_at' => CarbonImmutable::parse('2026-04-15 12:00', 'Europe/Zurich')->utc(),
        'ends_at' => CarbonImmutable::parse('2026-04-15 13:00', 'Europe/Zurich')->utc(),
    ]);

    $page = AuthHelper::loginAs(visit('/'), $staffUser);

    $page->navigate('/dashboard/calendar?view=day&date=2026-04-15')
        ->assertSee('My Calendar Client')
        ->assertDontSee('Admin Calendar Client')
        ->assertNoJavaScriptErrors();
});

it('does not render the admin provider filter for staff', function () {
    ['business' => $business, 'service' => $service] = BusinessSetup::createLaunchedBusiness();

    $staffUser = User::factory()->create(['name' => 'Just Staff']);
    $staffProvider = attachProvider($business, $staffUser);
    $staffProvider->services()->attach($service);

    $page = AuthHelper::loginAs(visit('/'), $staffUser);

    $page->navigate('/dashboard/calendar?view=day&date=2026-04-15')
        // Filter toolbar has an aria-label "Filter by provider" when rendered.
        ->assertSourceMissing('aria-label="Filter by provider"')
        ->assertNoJavaScriptErrors();
});

it('shows an empty calendar to a staff member who is not linked to any provider', function () {
    ['business' => $business, 'admin' => $admin, 'provider' => $adminProvider, 'service' => $service]
        = BusinessSetup::createLaunchedBusiness();

    // Attach a staff user with NO Provider row — createBusinessWithStaff uses
    // attachStaff which only creates a BusinessMember pivot.
    $staffUser = User::factory()->create();
    attachStaff($business, $staffUser);

    // Create a booking for the admin that the staff member must not see.
    Booking::factory()->confirmed()->create([
        'business_id' => $business->id,
        'provider_id' => $adminProvider->id,
        'service_id' => $service->id,
        'customer_id' => Customer::factory()->create(['name' => 'Invisible Ivan'])->id,
        'starts_at' => CarbonImmutable::parse('2026-04-15 10:00', 'Europe/Zurich')->utc(),
        'ends_at' => CarbonImmutable::parse('2026-04-15 11:00', 'Europe/Zurich')->utc(),
    ]);

    $page = AuthHelper::loginAs(visit('/'), $staffUser);

    $page->navigate('/dashboard/calendar?view=day&date=2026-04-15')
        ->assertDontSee('Invisible Ivan')
        ->assertNoJavaScriptErrors();
});
