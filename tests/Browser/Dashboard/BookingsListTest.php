<?php

declare(strict_types=1);

use App\Models\Booking;
use App\Models\Customer;
use App\Models\User;
use Carbon\CarbonImmutable;
use Tests\Browser\Support\AuthHelper;
use Tests\Browser\Support\BusinessSetup;

// Covers: GET /dashboard/bookings (dashboard/bookings.tsx) — role scoping,
// filters (status, provider, date range), pagination (E2E-4).

beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2026-04-15 10:00', 'Europe/Zurich'));
});

it('admin sees bookings across every provider', function () {
    ['business' => $business, 'admin' => $admin, 'provider' => $adminProvider, 'service' => $service]
        = BusinessSetup::createLaunchedBusiness();

    $staffUser = User::factory()->create();
    $staffProvider = attachProvider($business, $staffUser);

    $jane = Customer::factory()->create(['name' => 'Jane Bookings']);
    $john = Customer::factory()->create(['name' => 'John Listed']);

    Booking::factory()->confirmed()->create([
        'business_id' => $business->id,
        'provider_id' => $adminProvider->id,
        'service_id' => $service->id,
        'customer_id' => $jane->id,
        'starts_at' => CarbonImmutable::parse('2026-04-20 10:00', 'Europe/Zurich')->utc(),
        'ends_at' => CarbonImmutable::parse('2026-04-20 11:00', 'Europe/Zurich')->utc(),
    ]);

    Booking::factory()->confirmed()->create([
        'business_id' => $business->id,
        'provider_id' => $staffProvider->id,
        'service_id' => $service->id,
        'customer_id' => $john->id,
        'starts_at' => CarbonImmutable::parse('2026-04-21 10:00', 'Europe/Zurich')->utc(),
        'ends_at' => CarbonImmutable::parse('2026-04-21 11:00', 'Europe/Zurich')->utc(),
    ]);

    $page = AuthHelper::loginAs(visit('/'), $admin);

    $page->navigate('/dashboard/bookings')
        ->assertPathIs('/dashboard/bookings')
        ->assertSee('All appointments')
        ->assertSee('Jane Bookings')
        ->assertSee('John Listed')
        ->assertNoJavaScriptErrors();
});

it('staff sees only bookings tied to their own provider row', function () {
    ['business' => $business, 'provider' => $adminProvider, 'service' => $service]
        = BusinessSetup::createLaunchedBusiness();

    $staffUser = User::factory()->create();
    $staffProvider = attachProvider($business, $staffUser);

    $mine = Customer::factory()->create(['name' => 'Mine Only']);
    $theirs = Customer::factory()->create(['name' => 'Not Mine']);

    Booking::factory()->confirmed()->create([
        'business_id' => $business->id,
        'provider_id' => $staffProvider->id,
        'service_id' => $service->id,
        'customer_id' => $mine->id,
        'starts_at' => CarbonImmutable::parse('2026-04-21 10:00', 'Europe/Zurich')->utc(),
        'ends_at' => CarbonImmutable::parse('2026-04-21 11:00', 'Europe/Zurich')->utc(),
    ]);

    Booking::factory()->confirmed()->create([
        'business_id' => $business->id,
        'provider_id' => $adminProvider->id,
        'service_id' => $service->id,
        'customer_id' => $theirs->id,
        'starts_at' => CarbonImmutable::parse('2026-04-22 10:00', 'Europe/Zurich')->utc(),
        'ends_at' => CarbonImmutable::parse('2026-04-22 11:00', 'Europe/Zurich')->utc(),
    ]);

    $page = AuthHelper::loginAs(visit('/'), $staffUser);

    $page->navigate('/dashboard/bookings')
        ->assertSee('Mine Only')
        ->assertDontSee('Not Mine')
        ->assertNoJavaScriptErrors();
});

it('filters by status via the URL query string', function () {
    ['business' => $business, 'admin' => $admin, 'provider' => $provider, 'service' => $service]
        = BusinessSetup::createLaunchedBusiness();

    $pendingCustomer = Customer::factory()->create(['name' => 'Pending Pat']);
    $confirmedCustomer = Customer::factory()->create(['name' => 'Confirmed Chris']);

    Booking::factory()->pending()->create([
        'business_id' => $business->id,
        'provider_id' => $provider->id,
        'service_id' => $service->id,
        'customer_id' => $pendingCustomer->id,
        'starts_at' => CarbonImmutable::parse('2026-04-21 10:00', 'Europe/Zurich')->utc(),
        'ends_at' => CarbonImmutable::parse('2026-04-21 11:00', 'Europe/Zurich')->utc(),
    ]);

    Booking::factory()->confirmed()->create([
        'business_id' => $business->id,
        'provider_id' => $provider->id,
        'service_id' => $service->id,
        'customer_id' => $confirmedCustomer->id,
        'starts_at' => CarbonImmutable::parse('2026-04-22 10:00', 'Europe/Zurich')->utc(),
        'ends_at' => CarbonImmutable::parse('2026-04-22 11:00', 'Europe/Zurich')->utc(),
    ]);

    $page = AuthHelper::loginAs(visit('/'), $admin);

    $page->navigate('/dashboard/bookings?status=pending')
        ->assertQueryStringHas('status', 'pending')
        ->assertSee('Pending Pat')
        ->assertDontSee('Confirmed Chris')
        ->assertNoJavaScriptErrors();
});

it('filters by a date range via the URL query string', function () {
    ['business' => $business, 'admin' => $admin, 'provider' => $provider, 'service' => $service]
        = BusinessSetup::createLaunchedBusiness();

    $early = Customer::factory()->create(['name' => 'Early Emma']);
    $late = Customer::factory()->create(['name' => 'Late Larry']);

    Booking::factory()->confirmed()->create([
        'business_id' => $business->id,
        'provider_id' => $provider->id,
        'service_id' => $service->id,
        'customer_id' => $early->id,
        'starts_at' => CarbonImmutable::parse('2026-04-16 10:00', 'Europe/Zurich')->utc(),
        'ends_at' => CarbonImmutable::parse('2026-04-16 11:00', 'Europe/Zurich')->utc(),
    ]);

    Booking::factory()->confirmed()->create([
        'business_id' => $business->id,
        'provider_id' => $provider->id,
        'service_id' => $service->id,
        'customer_id' => $late->id,
        'starts_at' => CarbonImmutable::parse('2026-04-30 10:00', 'Europe/Zurich')->utc(),
        'ends_at' => CarbonImmutable::parse('2026-04-30 11:00', 'Europe/Zurich')->utc(),
    ]);

    $page = AuthHelper::loginAs(visit('/'), $admin);

    $page->navigate('/dashboard/bookings?date_from=2026-04-15&date_to=2026-04-20')
        ->assertSee('Early Emma')
        ->assertDontSee('Late Larry')
        ->assertNoJavaScriptErrors();
});

it('filters by provider when an admin selects a single provider', function () {
    ['business' => $business, 'admin' => $admin, 'provider' => $adminProvider, 'service' => $service]
        = BusinessSetup::createLaunchedBusiness();

    $staffUser = User::factory()->create();
    $staffProvider = attachProvider($business, $staffUser);

    $viaAdmin = Customer::factory()->create(['name' => 'Via Admin']);
    $viaStaff = Customer::factory()->create(['name' => 'Via Staff']);

    Booking::factory()->confirmed()->create([
        'business_id' => $business->id,
        'provider_id' => $adminProvider->id,
        'service_id' => $service->id,
        'customer_id' => $viaAdmin->id,
        'starts_at' => CarbonImmutable::parse('2026-04-21 10:00', 'Europe/Zurich')->utc(),
        'ends_at' => CarbonImmutable::parse('2026-04-21 11:00', 'Europe/Zurich')->utc(),
    ]);

    Booking::factory()->confirmed()->create([
        'business_id' => $business->id,
        'provider_id' => $staffProvider->id,
        'service_id' => $service->id,
        'customer_id' => $viaStaff->id,
        'starts_at' => CarbonImmutable::parse('2026-04-22 10:00', 'Europe/Zurich')->utc(),
        'ends_at' => CarbonImmutable::parse('2026-04-22 11:00', 'Europe/Zurich')->utc(),
    ]);

    $page = AuthHelper::loginAs(visit('/'), $admin);

    $page->navigate('/dashboard/bookings?provider_id='.$staffProvider->id)
        ->assertSee('Via Staff')
        ->assertDontSee('Via Admin')
        ->assertNoJavaScriptErrors();
});

it('paginates to the second page when there are more than 20 bookings', function () {
    ['business' => $business, 'admin' => $admin, 'provider' => $provider, 'service' => $service]
        = BusinessSetup::createLaunchedBusiness();

    $first = Customer::factory()->create(['name' => 'First Page Fiona']);
    $last = Customer::factory()->create(['name' => 'Last Page Lucas']);

    // Create 20 placeholder bookings (one per day) plus two distinct ones
    // on either end of the date range so each appears on a different page.
    Booking::factory()->confirmed()->create([
        'business_id' => $business->id,
        'provider_id' => $provider->id,
        'service_id' => $service->id,
        'customer_id' => $first->id,
        'starts_at' => CarbonImmutable::parse('2026-05-31 10:00', 'Europe/Zurich')->utc(),
        'ends_at' => CarbonImmutable::parse('2026-05-31 11:00', 'Europe/Zurich')->utc(),
    ]);

    for ($i = 0; $i < 19; $i++) {
        Booking::factory()->confirmed()->create([
            'business_id' => $business->id,
            'provider_id' => $provider->id,
            'service_id' => $service->id,
            'customer_id' => Customer::factory()->create()->id,
            'starts_at' => CarbonImmutable::parse('2026-05-10 10:00', 'Europe/Zurich')->utc()->addDays($i),
            'ends_at' => CarbonImmutable::parse('2026-05-10 11:00', 'Europe/Zurich')->utc()->addDays($i),
        ]);
    }

    Booking::factory()->confirmed()->create([
        'business_id' => $business->id,
        'provider_id' => $provider->id,
        'service_id' => $service->id,
        'customer_id' => $last->id,
        'starts_at' => CarbonImmutable::parse('2026-04-10 10:00', 'Europe/Zurich')->utc(),
        'ends_at' => CarbonImmutable::parse('2026-04-10 11:00', 'Europe/Zurich')->utc(),
    ]);

    $page = AuthHelper::loginAs(visit('/'), $admin);

    // Default sort is starts_at DESC — the latest booking lands on page 1.
    $page->navigate('/dashboard/bookings')
        ->assertSee('First Page Fiona')
        ->assertDontSee('Last Page Lucas')
        ->assertNoJavaScriptErrors();

    $page->navigate('/dashboard/bookings?page=2')
        ->assertSee('Last Page Lucas')
        ->assertDontSee('First Page Fiona')
        ->assertNoJavaScriptErrors();
});

it('shows an empty state when there are no bookings', function () {
    ['admin' => $admin] = BusinessSetup::createLaunchedBusiness();

    $page = AuthHelper::loginAs(visit('/'), $admin);

    $page->navigate('/dashboard/bookings')
        ->assertSee('Nothing to show here yet.')
        ->assertSee('New bookings will appear as they come in.')
        ->assertNoJavaScriptErrors();
});
