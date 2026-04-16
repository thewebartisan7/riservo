<?php

use App\Models\Booking;
use App\Models\Business;
use App\Models\Customer;
use App\Models\Service;
use App\Models\User;

beforeEach(function () {
    $this->business = Business::factory()->onboarded()->create();
    $this->admin = User::factory()->create();
    attachAdmin($this->business, $this->admin);

    $this->staff = User::factory()->create();
    $this->provider = attachProvider($this->business, $this->staff);

    $this->service = Service::factory()->create(['business_id' => $this->business->id]);
});

test('lists customers with booking counts', function () {
    $customer = Customer::factory()->create(['name' => 'Jane Doe']);
    Booking::factory()->count(3)->create([
        'business_id' => $this->business->id,
        'provider_id' => $this->provider->id,
        'service_id' => $this->service->id,
        'customer_id' => $customer->id,
    ]);

    $response = $this->actingAs($this->admin)->get('/dashboard/customers');

    $response->assertSuccessful()
        ->assertInertia(fn ($page) => $page
            ->component('dashboard/customers')
            ->has('customers.data', 1)
            ->where('customers.data.0.name', 'Jane Doe')
            ->where('customers.data.0.bookings_count', 3)
        );
});

test('search by name', function () {
    $jane = Customer::factory()->create(['name' => 'Jane Doe']);
    $john = Customer::factory()->create(['name' => 'John Smith']);

    Booking::factory()->create([
        'business_id' => $this->business->id,
        'provider_id' => $this->provider->id,
        'service_id' => $this->service->id,
        'customer_id' => $jane->id,
    ]);
    Booking::factory()->create([
        'business_id' => $this->business->id,
        'provider_id' => $this->provider->id,
        'service_id' => $this->service->id,
        'customer_id' => $john->id,
    ]);

    $response = $this->actingAs($this->admin)->get('/dashboard/customers?search=Jane');

    $response->assertInertia(fn ($page) => $page
        ->has('customers.data', 1)
        ->where('customers.data.0.name', 'Jane Doe')
    );
});

test('search by email', function () {
    $customer = Customer::factory()->create(['email' => 'specific@test.com']);
    Booking::factory()->create([
        'business_id' => $this->business->id,
        'provider_id' => $this->provider->id,
        'service_id' => $this->service->id,
        'customer_id' => $customer->id,
    ]);

    $response = $this->actingAs($this->admin)->get('/dashboard/customers?search=specific@test');

    $response->assertInertia(fn ($page) => $page
        ->has('customers.data', 1)
    );
});

test('search by phone', function () {
    $customer = Customer::factory()->create(['phone' => '+41 79 999 00 00']);
    Booking::factory()->create([
        'business_id' => $this->business->id,
        'provider_id' => $this->provider->id,
        'service_id' => $this->service->id,
        'customer_id' => $customer->id,
    ]);

    $response = $this->actingAs($this->admin)->get('/dashboard/customers?search=999 00');

    $response->assertInertia(fn ($page) => $page
        ->has('customers.data', 1)
    );
});

test('customer from another business not shown', function () {
    $otherBusiness = Business::factory()->onboarded()->create();
    $customer = Customer::factory()->create();
    Booking::factory()->create([
        'business_id' => $otherBusiness->id,
        'customer_id' => $customer->id,
    ]);

    $response = $this->actingAs($this->admin)->get('/dashboard/customers');

    $response->assertInertia(fn ($page) => $page
        ->has('customers.data', 0)
    );
});

test('customer detail shows booking history', function () {
    $customer = Customer::factory()->create(['name' => 'Jane Doe']);
    Booking::factory()->count(2)->create([
        'business_id' => $this->business->id,
        'provider_id' => $this->provider->id,
        'service_id' => $this->service->id,
        'customer_id' => $customer->id,
    ]);

    $response = $this->actingAs($this->admin)->get("/dashboard/customers/{$customer->id}");

    $response->assertSuccessful()
        ->assertInertia(fn ($page) => $page
            ->component('dashboard/customer-show')
            ->where('customer.name', 'Jane Doe')
            ->where('stats.total_bookings', 2)
            ->has('bookings', 2)
        );
});

test('customer detail for unrelated customer returns 404', function () {
    $customer = Customer::factory()->create();
    // No bookings for this business

    $response = $this->actingAs($this->admin)->get("/dashboard/customers/{$customer->id}");

    $response->assertNotFound();
});

test('staff cannot access customer directory', function () {
    $response = $this->actingAs($this->staff)->get('/dashboard/customers');

    $response->assertForbidden();
});

test('customer search API returns results', function () {
    $customer = Customer::factory()->create(['name' => 'Jane Doe', 'email' => 'jane@test.com']);
    Booking::factory()->create([
        'business_id' => $this->business->id,
        'provider_id' => $this->provider->id,
        'service_id' => $this->service->id,
        'customer_id' => $customer->id,
    ]);

    $response = $this->actingAs($this->admin)->getJson('/dashboard/api/customers/search?q=Jane');

    $response->assertSuccessful()
        ->assertJsonCount(1, 'customers')
        ->assertJsonPath('customers.0.name', 'Jane Doe');
});

test('pagination works for customers', function () {
    $customers = Customer::factory()->count(25)->create();
    foreach ($customers as $customer) {
        Booking::factory()->create([
            'business_id' => $this->business->id,
            'provider_id' => $this->provider->id,
            'service_id' => $this->service->id,
            'customer_id' => $customer->id,
        ]);
    }

    $response = $this->actingAs($this->admin)->get('/dashboard/customers');

    $response->assertInertia(fn ($page) => $page
        ->has('customers.data', 20)
        ->where('customers.last_page', 2)
    );
});
