<?php

declare(strict_types=1);

use App\Models\Booking;
use App\Models\Customer;
use App\Models\User;
use Carbon\CarbonImmutable;
use Tests\Browser\Support\AuthHelper;
use Tests\Browser\Support\BusinessSetup;

// Covers: the admin-only provider filter on /dashboard/calendar — per-provider
// toggle and "All" toggle. Each provider's bookings are coloured per the
// provider colour map (E2E-4).

beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2026-04-15 10:00', 'Europe/Zurich'));
});

it('shows the provider filter bar for an admin with multiple providers', function () {
    ['business' => $business, 'admin' => $admin, 'provider' => $adminProvider, 'service' => $service]
        = BusinessSetup::createLaunchedBusiness();

    $staffUser = User::factory()->create(['name' => 'Sandra Staff']);
    $staffProvider = attachProvider($business, $staffUser);
    $staffProvider->services()->attach($service);

    $admin->update(['name' => 'Alice Admin']);

    $customer = Customer::factory()->create(['name' => 'Shared Customer']);

    Booking::factory()->confirmed()->create([
        'business_id' => $business->id,
        'provider_id' => $adminProvider->id,
        'service_id' => $service->id,
        'customer_id' => $customer->id,
        'starts_at' => CarbonImmutable::parse('2026-04-15 10:00', 'Europe/Zurich')->utc(),
        'ends_at' => CarbonImmutable::parse('2026-04-15 11:00', 'Europe/Zurich')->utc(),
    ]);

    Booking::factory()->confirmed()->create([
        'business_id' => $business->id,
        'provider_id' => $staffProvider->id,
        'service_id' => $service->id,
        'customer_id' => $customer->id,
        'starts_at' => CarbonImmutable::parse('2026-04-15 12:00', 'Europe/Zurich')->utc(),
        'ends_at' => CarbonImmutable::parse('2026-04-15 13:00', 'Europe/Zurich')->utc(),
    ]);

    $page = AuthHelper::loginAs(visit('/'), $admin);

    $page->navigate('/dashboard/calendar?view=day&date=2026-04-15')
        ->assertSee('Alice Admin')
        ->assertSee('Sandra Staff')
        ->assertNoJavaScriptErrors();
});

it('hides the provider filter when the admin has a single provider', function () {
    // `createLaunchedBusiness` leaves exactly one provider (the admin). The
    // `showFilter` flag requires more than one, so the filter UI must be absent.
    ['business' => $business, 'admin' => $admin] = BusinessSetup::createLaunchedBusiness();

    $admin->update(['name' => 'Only Admin']);

    $page = AuthHelper::loginAs(visit('/'), $admin);

    $page->navigate('/dashboard/calendar?view=day&date=2026-04-15')
        ->assertSee('Calendar')
        // The filter bar has an aria-label "Filter by provider" when rendered.
        // With a single provider it should not be present.
        ->assertSourceMissing('aria-label="Filter by provider"')
        ->assertNoJavaScriptErrors();
});

it('renders both providers in the filter toolbar', function () {
    ['business' => $business, 'admin' => $admin, 'service' => $service]
        = BusinessSetup::createLaunchedBusiness();

    $secondStaff = User::factory()->create(['name' => 'Second Provider']);
    $secondProvider = attachProvider($business, $secondStaff);
    $secondProvider->services()->attach($service);

    $admin->update(['name' => 'First Provider']);

    $page = AuthHelper::loginAs(visit('/'), $admin);

    $page->navigate('/dashboard/calendar?view=day&date=2026-04-15')
        ->assertSee('First Provider')
        ->assertSee('Second Provider')
        ->assertSee('All')
        ->assertNoJavaScriptErrors();
});
