<?php

declare(strict_types=1);

use App\Models\Booking;
use App\Models\Customer;
use App\Models\User;
use Carbon\CarbonImmutable;
use Tests\Browser\Support\BusinessSetup;

// Covers: GET /dashboard (dashboard.tsx) — stat cards, today's schedule, nav link (E2E-4).
//
// NOTE: Tests drive login via the /login form directly. The E2E-0 AuthHelper
// helper's loginAs() chains $page->visit() after the first page resolves —
// Pest's Webpage has no visit() method, so the helper is currently broken.
// See the implementing-agent report for the open follow-up.

beforeEach(function () {
    // Freeze the clock to a predictable Wednesday 10:00 in Zurich so that
    // the test knows which bookings count toward "today" regardless of when
    // the CI host runs the suite.
    $this->travelTo(CarbonImmutable::parse('2026-04-15 10:00', 'Europe/Zurich'));
});

it('shows four stat cards and the heading greeting', function () {
    ['admin' => $admin, 'business' => $business, 'provider' => $provider, 'service' => $service]
        = BusinessSetup::createLaunchedBusiness();

    $customer = Customer::factory()->create(['name' => 'Ada Lovelace']);

    foreach (['11:00', '14:00'] as $time) {
        Booking::factory()->confirmed()->create([
            'business_id' => $business->id,
            'provider_id' => $provider->id,
            'service_id' => $service->id,
            'customer_id' => $customer->id,
            'starts_at' => CarbonImmutable::parse("2026-04-15 {$time}", 'Europe/Zurich')->utc(),
            'ends_at' => CarbonImmutable::parse("2026-04-15 {$time}", 'Europe/Zurich')->utc()->addHour(),
        ]);
    }

    Booking::factory()->pending()->create([
        'business_id' => $business->id,
        'provider_id' => $provider->id,
        'service_id' => $service->id,
        'customer_id' => $customer->id,
        'starts_at' => CarbonImmutable::parse('2026-04-15 16:00', 'Europe/Zurich')->utc(),
        'ends_at' => CarbonImmutable::parse('2026-04-15 17:00', 'Europe/Zurich')->utc(),
    ]);

    $page = visit('/login')
        ->type('email', $admin->email)
        ->type('password', 'password')
        ->click('button[type="submit"]');

    $page->navigate('/dashboard')
        ->assertPathIs('/dashboard')
        ->assertSee('Today')
        ->assertSee('This week')
        ->assertSee('Upcoming')
        ->assertSee('Awaiting confirmation')
        ->assertSee('Ada Lovelace')
        ->assertNoJavaScriptErrors();
});

it('shows the quiet-day empty state when there are no bookings today', function () {
    ['admin' => $admin] = BusinessSetup::createLaunchedBusiness();

    $page = visit('/login')
        ->type('email', $admin->email)
        ->type('password', 'password')
        ->click('button[type="submit"]');

    $page->navigate('/dashboard')
        ->assertPathIs('/dashboard')
        ->assertSee('A quiet day.')
        ->assertSee('New bookings will appear here as they come in.')
        ->assertNoJavaScriptErrors();
});

it('navigates to the bookings list when clicking the All bookings link', function () {
    ['admin' => $admin] = BusinessSetup::createLaunchedBusiness();

    $page = visit('/login')
        ->type('email', $admin->email)
        ->type('password', 'password')
        ->click('button[type="submit"]');

    $page->navigate('/dashboard')
        ->click('All bookings')
        ->assertPathIs('/dashboard/bookings')
        ->assertNoJavaScriptErrors();
});

it('renders the dashboard home for a staff member without errors', function () {
    ['business' => $business, 'service' => $service] = BusinessSetup::createLaunchedBusiness();

    $staffUser = User::factory()->create();
    $staffProvider = attachProvider($business, $staffUser);

    $customer = Customer::factory()->create(['name' => 'Staffie Client']);

    Booking::factory()->confirmed()->create([
        'business_id' => $business->id,
        'provider_id' => $staffProvider->id,
        'service_id' => $service->id,
        'customer_id' => $customer->id,
        'starts_at' => CarbonImmutable::parse('2026-04-15 11:00', 'Europe/Zurich')->utc(),
        'ends_at' => CarbonImmutable::parse('2026-04-15 12:00', 'Europe/Zurich')->utc(),
    ]);

    $page = visit('/login')
        ->type('email', $staffUser->email)
        ->type('password', 'password')
        ->click('button[type="submit"]');

    $page->navigate('/dashboard')
        ->assertPathIs('/dashboard')
        ->assertSee('Today')
        ->assertSee('Staffie Client')
        ->assertNoJavaScriptErrors();
});
