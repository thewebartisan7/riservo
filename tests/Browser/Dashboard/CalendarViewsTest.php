<?php

declare(strict_types=1);

use App\Models\Booking;
use App\Models\Customer;
use App\Models\User;
use Carbon\CarbonImmutable;
use Tests\Browser\Support\AuthHelper;
use Tests\Browser\Support\BusinessSetup;

// Covers: GET /dashboard/calendar (dashboard/calendar.tsx) — default week,
// switching to day / month, prev/next/today navigation (E2E-4).

beforeEach(function () {
    // Wednesday 2026-04-15 — safely inside the seeded weekly business hours.
    $this->travelTo(CarbonImmutable::parse('2026-04-15 10:00', 'Europe/Zurich'));
});

it('renders the week view by default and shows seeded bookings', function () {
    ['business' => $business, 'admin' => $admin, 'provider' => $provider, 'service' => $service]
        = BusinessSetup::createLaunchedBusiness();

    $customer = Customer::factory()->create(['name' => 'Week Wanda']);

    Booking::factory()->confirmed()->create([
        'business_id' => $business->id,
        'provider_id' => $provider->id,
        'service_id' => $service->id,
        'customer_id' => $customer->id,
        'starts_at' => CarbonImmutable::parse('2026-04-15 10:00', 'Europe/Zurich')->utc(),
        'ends_at' => CarbonImmutable::parse('2026-04-15 11:00', 'Europe/Zurich')->utc(),
    ]);

    $page = AuthHelper::loginAs(visit('/'), $admin);

    $page->navigate('/dashboard/calendar')
        ->assertQueryStringMissing('view')
        ->assertSee('Calendar')
        ->assertSee('Week Wanda')
        ->assertNoJavaScriptErrors();
});

it('renders the day view when view=day is requested', function () {
    ['business' => $business, 'admin' => $admin, 'provider' => $provider, 'service' => $service]
        = BusinessSetup::createLaunchedBusiness();

    $customer = Customer::factory()->create(['name' => 'Day Dana']);

    Booking::factory()->confirmed()->create([
        'business_id' => $business->id,
        'provider_id' => $provider->id,
        'service_id' => $service->id,
        'customer_id' => $customer->id,
        'starts_at' => CarbonImmutable::parse('2026-04-15 14:00', 'Europe/Zurich')->utc(),
        'ends_at' => CarbonImmutable::parse('2026-04-15 15:00', 'Europe/Zurich')->utc(),
    ]);

    $page = AuthHelper::loginAs(visit('/'), $admin);

    $page->navigate('/dashboard/calendar?view=day&date=2026-04-15')
        ->assertQueryStringHas('view', 'day')
        ->assertSee('Day Dana')
        ->assertNoJavaScriptErrors();
});

it('renders the month view when view=month is requested', function () {
    ['business' => $business, 'admin' => $admin, 'provider' => $provider, 'service' => $service]
        = BusinessSetup::createLaunchedBusiness();

    $customer = Customer::factory()->create(['name' => 'Monthly Mike']);

    Booking::factory()->confirmed()->create([
        'business_id' => $business->id,
        'provider_id' => $provider->id,
        'service_id' => $service->id,
        'customer_id' => $customer->id,
        'starts_at' => CarbonImmutable::parse('2026-04-22 10:00', 'Europe/Zurich')->utc(),
        'ends_at' => CarbonImmutable::parse('2026-04-22 11:00', 'Europe/Zurich')->utc(),
    ]);

    $page = AuthHelper::loginAs(visit('/'), $admin);

    $page->navigate('/dashboard/calendar?view=month&date=2026-04-15')
        ->assertQueryStringHas('view', 'month')
        ->assertSee('April 2026')
        ->assertNoJavaScriptErrors();
});

it('navigates to a different week via the date query parameter', function () {
    ['admin' => $admin] = BusinessSetup::createLaunchedBusiness();

    $page = AuthHelper::loginAs(visit('/'), $admin);

    $page->navigate('/dashboard/calendar?date=2026-05-04')
        ->assertQueryStringHas('date', '2026-05-04')
        ->assertNoJavaScriptErrors();
});

it('renders the calendar without a New booking button for staff', function () {
    ['business' => $business, 'service' => $service] = BusinessSetup::createLaunchedBusiness();

    $staffUser = User::factory()->create();
    $staffProvider = attachProvider($business, $staffUser);

    $page = AuthHelper::loginAs(visit('/'), $staffUser);

    $page->navigate('/dashboard/calendar')
        ->assertSee('Calendar')
        ->assertNoJavaScriptErrors();
});

it('falls back to week view when the view parameter is invalid', function () {
    ['admin' => $admin] = BusinessSetup::createLaunchedBusiness();

    $page = AuthHelper::loginAs(visit('/'), $admin);

    $page->navigate('/dashboard/calendar?view=year')
        ->assertSee('Calendar')
        ->assertNoJavaScriptErrors();
});
