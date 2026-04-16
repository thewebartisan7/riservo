<?php

declare(strict_types=1);

use App\Models\Booking;
use App\Models\Customer;
use App\Models\User;
use Carbon\CarbonImmutable;
use Tests\Browser\Support\AuthHelper;
use Tests\Browser\Support\BusinessSetup;

// Covers: GET /dashboard/customers (dashboard/customers.tsx) + search API
// + admin-only authorization (E2E-4).

beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2026-04-15 10:00', 'Europe/Zurich'));
});

it('lists only customers who have bookings with this business', function () {
    ['business' => $business, 'admin' => $admin, 'provider' => $provider, 'service' => $service]
        = BusinessSetup::createLaunchedBusiness();

    $mine = Customer::factory()->create(['name' => 'Local Leah']);
    $strangerBusiness = Customer::factory()->create(['name' => 'Other Otto']);

    Booking::factory()->confirmed()->create([
        'business_id' => $business->id,
        'provider_id' => $provider->id,
        'service_id' => $service->id,
        'customer_id' => $mine->id,
        'starts_at' => CarbonImmutable::parse('2026-04-10 10:00', 'Europe/Zurich')->utc(),
        'ends_at' => CarbonImmutable::parse('2026-04-10 11:00', 'Europe/Zurich')->utc(),
    ]);

    // $strangerBusiness has no bookings with this business — must not appear.

    $page = AuthHelper::loginAs(visit('/'), $admin);

    $page->navigate('/dashboard/customers')
        ->assertSee('Your regulars')
        ->assertSee('Local Leah')
        ->assertDontSee('Other Otto')
        ->assertNoJavaScriptErrors();
});

it('filters customers via the search query parameter', function () {
    ['business' => $business, 'admin' => $admin, 'provider' => $provider, 'service' => $service]
        = BusinessSetup::createLaunchedBusiness();

    $jane = Customer::factory()->create(['name' => 'Jane Searchable']);
    $john = Customer::factory()->create(['name' => 'John Elsewhere']);

    foreach ([$jane, $john] as $customer) {
        Booking::factory()->confirmed()->create([
            'business_id' => $business->id,
            'provider_id' => $provider->id,
            'service_id' => $service->id,
            'customer_id' => $customer->id,
        ]);
    }

    $page = AuthHelper::loginAs(visit('/'), $admin);

    $page->navigate('/dashboard/customers?search=Searchable')
        ->assertQueryStringHas('search', 'Searchable')
        ->assertSee('Jane Searchable')
        ->assertDontSee('John Elsewhere')
        ->assertNoJavaScriptErrors();
});

it('shows the empty state when no customers match the search', function () {
    ['admin' => $admin] = BusinessSetup::createLaunchedBusiness();

    $page = AuthHelper::loginAs(visit('/'), $admin);

    $page->navigate('/dashboard/customers?search=nobodymatchesthiseverever')
        ->assertSee('No one matches that yet.')
        ->assertNoJavaScriptErrors();
});

it('navigates to the customer detail page when a row is clicked', function () {
    ['business' => $business, 'admin' => $admin, 'provider' => $provider, 'service' => $service]
        = BusinessSetup::createLaunchedBusiness();

    $linkTarget = Customer::factory()->create(['name' => 'Clickable Chris']);

    Booking::factory()->confirmed()->create([
        'business_id' => $business->id,
        'provider_id' => $provider->id,
        'service_id' => $service->id,
        'customer_id' => $linkTarget->id,
    ]);

    $page = AuthHelper::loginAs(visit('/'), $admin);

    $page->navigate('/dashboard/customers')
        ->click('Clickable Chris')
        ->assertPathIs('/dashboard/customers/'.$linkTarget->id)
        ->assertSee('Clickable Chris')
        ->assertNoJavaScriptErrors();
});

it('forbids staff members from accessing the customer directory', function () {
    ['business' => $business] = BusinessSetup::createLaunchedBusiness();

    $staffUser = User::factory()->create();
    attachStaff($business, $staffUser);

    // Hit the endpoint directly with the staff user — the role:admin
    // middleware should 403. Browser-test note: a forbidden response in this
    // app is rendered as a 403 page.
    $this->actingAs($staffUser)->get('/dashboard/customers')->assertForbidden();
});

it('exposes the customer search API to admins', function () {
    ['business' => $business, 'admin' => $admin, 'provider' => $provider, 'service' => $service]
        = BusinessSetup::createLaunchedBusiness();

    $customer = Customer::factory()->create([
        'name' => 'Searchy Sam',
        'email' => 'sam@example.com',
    ]);

    Booking::factory()->confirmed()->create([
        'business_id' => $business->id,
        'provider_id' => $provider->id,
        'service_id' => $service->id,
        'customer_id' => $customer->id,
    ]);

    $this->actingAs($admin)
        ->getJson('/dashboard/api/customers/search?q=Searchy')
        ->assertSuccessful()
        ->assertJsonPath('customers.0.name', 'Searchy Sam');
});
