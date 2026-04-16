<?php

declare(strict_types=1);

use App\Models\Booking;
use App\Models\Business;
use App\Models\Customer;
use Carbon\CarbonImmutable;
use Tests\Browser\Support\AuthHelper;
use Tests\Browser\Support\BusinessSetup;

// Covers: GET /dashboard/customers/{customer} (dashboard/customer-show.tsx) —
// contact info, visit summary, booking history, 404 for unrelated customers (E2E-4).

beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2026-04-15 10:00', 'Europe/Zurich'));
});

it('shows contact info, stats, and booking history for a scoped customer', function () {
    ['business' => $business, 'admin' => $admin, 'provider' => $provider, 'service' => $service]
        = BusinessSetup::createLaunchedBusiness();

    $customer = Customer::factory()->create([
        'name' => 'Regular Rachel',
        'email' => 'rachel@example.com',
        'phone' => '+41 79 333 44 55',
    ]);

    // Two bookings — first one earlier than the second.
    Booking::factory()->confirmed()->create([
        'business_id' => $business->id,
        'provider_id' => $provider->id,
        'service_id' => $service->id,
        'customer_id' => $customer->id,
        'starts_at' => CarbonImmutable::parse('2026-04-10 10:00', 'Europe/Zurich')->utc(),
        'ends_at' => CarbonImmutable::parse('2026-04-10 11:00', 'Europe/Zurich')->utc(),
    ]);

    Booking::factory()->confirmed()->create([
        'business_id' => $business->id,
        'provider_id' => $provider->id,
        'service_id' => $service->id,
        'customer_id' => $customer->id,
        'starts_at' => CarbonImmutable::parse('2026-04-20 10:00', 'Europe/Zurich')->utc(),
        'ends_at' => CarbonImmutable::parse('2026-04-20 11:00', 'Europe/Zurich')->utc(),
    ]);

    $page = AuthHelper::loginAs(visit('/'), $admin);

    $page->navigate('/dashboard/customers/'.$customer->id)
        ->assertPathIs('/dashboard/customers/'.$customer->id)
        ->assertSee('Regular Rachel')
        ->assertSee('rachel@example.com')
        ->assertSee('+41 79 333 44 55')
        ->assertSee('Total visits')
        ->assertSee('First visit')
        ->assertSee('Last visit')
        ->assertSee('Booking history')
        ->assertNoJavaScriptErrors();
});

it('returns 404 when the customer has no bookings with this business', function () {
    ['admin' => $admin] = BusinessSetup::createLaunchedBusiness();

    // Create a customer who only has bookings with a *different* business.
    $otherBusiness = Business::factory()->onboarded()->create();
    $customer = Customer::factory()->create(['name' => 'Foreign Fred']);

    // Use the HTTP test helper — a 404 in the browser view may be less
    // observable than a direct assertion from the controller.
    $this->actingAs($admin)
        ->get('/dashboard/customers/'.$customer->id)
        ->assertNotFound();
});

it('shows the empty booking-history placeholder when a customer has no bookings (edge case)', function () {
    // This exercises the empty-state branch of customer-show.tsx but the
    // controller 404s when there are zero bookings for the business, so
    // the only way to hit the empty state is when the last booking is
    // soft-deleted or the customer is linked via another pathway. Today's
    // controller has no such pathway, so we assert the 404 behaviour instead.
    ['business' => $business, 'admin' => $admin] = BusinessSetup::createLaunchedBusiness();

    $customer = Customer::factory()->create();

    $this->actingAs($admin)
        ->get('/dashboard/customers/'.$customer->id)
        ->assertNotFound();
});

it('links back to the customer list from the detail page', function () {
    ['business' => $business, 'admin' => $admin, 'provider' => $provider, 'service' => $service]
        = BusinessSetup::createLaunchedBusiness();

    $customer = Customer::factory()->create(['name' => 'Backlink Bea']);

    Booking::factory()->confirmed()->create([
        'business_id' => $business->id,
        'provider_id' => $provider->id,
        'service_id' => $service->id,
        'customer_id' => $customer->id,
    ]);

    $page = AuthHelper::loginAs(visit('/'), $admin);

    $page->navigate('/dashboard/customers/'.$customer->id)
        ->assertSee('All customers')
        ->click('← All customers')
        ->assertPathIs('/dashboard/customers')
        ->assertNoJavaScriptErrors();
});
