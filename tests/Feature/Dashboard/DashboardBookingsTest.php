<?php

use App\Models\Booking;
use App\Models\Business;
use App\Models\Customer;
use App\Models\Service;
use App\Models\User;
use Carbon\CarbonImmutable;

beforeEach(function () {
    $this->business = Business::factory()->onboarded()->create(['timezone' => 'Europe/Zurich']);
    $this->admin = User::factory()->create();
    attachAdmin($this->business, $this->admin);

    $this->staff = User::factory()->create();
    $this->provider = attachProvider($this->business, $this->staff);

    $this->service = Service::factory()->create([
        'business_id' => $this->business->id,
        'is_active' => true,
        'name' => 'Haircut',
    ]);
    $this->provider->services()->attach($this->service);

    $this->customer = Customer::factory()->create();

    $this->travelTo(CarbonImmutable::parse('2026-04-15 10:00', 'Europe/Zurich'));
});

test('admin sees all business bookings', function () {
    Booking::factory()->count(3)->create([
        'business_id' => $this->business->id,
        'provider_id' => $this->provider->id,
        'service_id' => $this->service->id,
        'customer_id' => $this->customer->id,
    ]);

    $response = $this->actingAs($this->admin)->get('/dashboard/bookings');

    $response->assertSuccessful()
        ->assertInertia(fn ($page) => $page
            ->component('dashboard/bookings')
            ->has('bookings.data', 3)
            ->where('isAdmin', true)
            ->has('services')
            ->has('providers')
        );
});

test('staff sees only own bookings', function () {
    $otherStaff = User::factory()->create();
    $otherProvider = attachProvider($this->business, $otherStaff);

    Booking::factory()->create([
        'business_id' => $this->business->id,
        'provider_id' => $this->provider->id,
        'service_id' => $this->service->id,
        'customer_id' => $this->customer->id,
    ]);

    Booking::factory()->create([
        'business_id' => $this->business->id,
        'provider_id' => $otherProvider->id,
        'service_id' => $this->service->id,
        'customer_id' => $this->customer->id,
    ]);

    $response = $this->actingAs($this->staff)->get('/dashboard/bookings');

    $response->assertSuccessful()
        ->assertInertia(fn ($page) => $page
            ->has('bookings.data', 1)
            ->where('isAdmin', false)
        );
});

test('filter by status', function () {
    Booking::factory()->confirmed()->create([
        'business_id' => $this->business->id,
        'provider_id' => $this->provider->id,
        'service_id' => $this->service->id,
        'customer_id' => $this->customer->id,
    ]);

    Booking::factory()->pending()->create([
        'business_id' => $this->business->id,
        'provider_id' => $this->provider->id,
        'service_id' => $this->service->id,
        'customer_id' => $this->customer->id,
    ]);

    $response = $this->actingAs($this->admin)->get('/dashboard/bookings?status=pending');

    $response->assertInertia(fn ($page) => $page
        ->has('bookings.data', 1)
        ->where('bookings.data.0.status', 'pending')
    );
});

test('filter by service', function () {
    $otherService = Service::factory()->create([
        'business_id' => $this->business->id,
        'is_active' => true,
        'name' => 'Massage',
    ]);

    Booking::factory()->create([
        'business_id' => $this->business->id,
        'provider_id' => $this->provider->id,
        'service_id' => $this->service->id,
        'customer_id' => $this->customer->id,
    ]);

    Booking::factory()->create([
        'business_id' => $this->business->id,
        'provider_id' => $this->provider->id,
        'service_id' => $otherService->id,
        'customer_id' => $this->customer->id,
    ]);

    $response = $this->actingAs($this->admin)->get('/dashboard/bookings?service_id='.$this->service->id);

    $response->assertInertia(fn ($page) => $page
        ->has('bookings.data', 1)
    );
});

test('filter by date range', function () {
    Booking::factory()->create([
        'business_id' => $this->business->id,
        'provider_id' => $this->provider->id,
        'service_id' => $this->service->id,
        'customer_id' => $this->customer->id,
        'starts_at' => CarbonImmutable::parse('2026-04-15 14:00', 'UTC'),
        'ends_at' => CarbonImmutable::parse('2026-04-15 15:00', 'UTC'),
    ]);

    Booking::factory()->create([
        'business_id' => $this->business->id,
        'provider_id' => $this->provider->id,
        'service_id' => $this->service->id,
        'customer_id' => $this->customer->id,
        'starts_at' => CarbonImmutable::parse('2026-04-20 14:00', 'UTC'),
        'ends_at' => CarbonImmutable::parse('2026-04-20 15:00', 'UTC'),
    ]);

    $response = $this->actingAs($this->admin)->get('/dashboard/bookings?date_from=2026-04-14&date_to=2026-04-16');

    $response->assertInertia(fn ($page) => $page
        ->has('bookings.data', 1)
    );
});

test('filter by provider (admin only)', function () {
    $otherStaff = User::factory()->create();
    $otherProvider = attachProvider($this->business, $otherStaff);

    Booking::factory()->create([
        'business_id' => $this->business->id,
        'provider_id' => $this->provider->id,
        'service_id' => $this->service->id,
        'customer_id' => $this->customer->id,
    ]);

    Booking::factory()->create([
        'business_id' => $this->business->id,
        'provider_id' => $otherProvider->id,
        'service_id' => $this->service->id,
        'customer_id' => $this->customer->id,
    ]);

    $response = $this->actingAs($this->admin)->get('/dashboard/bookings?provider_id='.$this->provider->id);

    $response->assertInertia(fn ($page) => $page
        ->has('bookings.data', 1)
    );
});

test('bookings include related data', function () {
    Booking::factory()->create([
        'business_id' => $this->business->id,
        'provider_id' => $this->provider->id,
        'service_id' => $this->service->id,
        'customer_id' => $this->customer->id,
    ]);

    $response = $this->actingAs($this->admin)->get('/dashboard/bookings');

    $response->assertInertia(fn ($page) => $page
        ->has('bookings.data.0.service.name')
        ->has('bookings.data.0.provider.name')
        ->has('bookings.data.0.customer.name')
        ->has('bookings.data.0.customer.email')
        ->has('bookings.data.0.status')
        ->has('bookings.data.0.source')
    );
});

test('bookings from another business not visible', function () {
    $otherBusiness = Business::factory()->onboarded()->create();
    Booking::factory()->create(['business_id' => $otherBusiness->id]);

    $response = $this->actingAs($this->admin)->get('/dashboard/bookings');

    $response->assertInertia(fn ($page) => $page
        ->has('bookings.data', 0)
    );
});

test('default sort is starts_at desc', function () {
    Booking::factory()->create([
        'business_id' => $this->business->id,
        'provider_id' => $this->provider->id,
        'service_id' => $this->service->id,
        'customer_id' => $this->customer->id,
        'starts_at' => CarbonImmutable::parse('2026-04-20 10:00', 'UTC'),
        'ends_at' => CarbonImmutable::parse('2026-04-20 11:00', 'UTC'),
    ]);

    Booking::factory()->create([
        'business_id' => $this->business->id,
        'provider_id' => $this->provider->id,
        'service_id' => $this->service->id,
        'customer_id' => $this->customer->id,
        'starts_at' => CarbonImmutable::parse('2026-04-25 10:00', 'UTC'),
        'ends_at' => CarbonImmutable::parse('2026-04-25 11:00', 'UTC'),
    ]);

    $response = $this->actingAs($this->admin)->get('/dashboard/bookings');

    $response->assertInertia(fn ($page) => $page
        ->has('bookings.data', 2)
        // Latest booking first
        ->where('bookings.data.0.starts_at', fn ($val) => str_contains($val, '2026-04-25'))
    );
});

test('pagination works', function () {
    for ($i = 0; $i < 25; $i++) {
        $day = CarbonImmutable::parse('2026-05-01 09:00', 'UTC')->addDays($i);
        Booking::factory()->create([
            'business_id' => $this->business->id,
            'provider_id' => $this->provider->id,
            'service_id' => $this->service->id,
            'customer_id' => $this->customer->id,
            'starts_at' => $day,
            'ends_at' => $day->addMinutes(30),
        ]);
    }

    $response = $this->actingAs($this->admin)->get('/dashboard/bookings');

    $response->assertInertia(fn ($page) => $page
        ->has('bookings.data', 20)
        ->where('bookings.last_page', 2)
    );
});

test('bookings list renders bookings for a deactivated provider with is_active=false', function () {
    $staff = User::factory()->create(['name' => 'Trashed Staff']);
    $provider = attachProvider($this->business, $staff);
    $provider->services()->attach($this->service);

    Booking::factory()->confirmed()->create([
        'business_id' => $this->business->id,
        'provider_id' => $provider->id,
        'service_id' => $this->service->id,
        'customer_id' => $this->customer->id,
        'starts_at' => CarbonImmutable::parse('2026-04-20 10:00', 'UTC'),
        'ends_at' => CarbonImmutable::parse('2026-04-20 11:00', 'UTC'),
    ]);

    $provider->delete();

    $response = $this->actingAs($this->admin)->get('/dashboard/bookings?provider_id='.$provider->id);

    $response->assertSuccessful()
        ->assertInertia(fn ($page) => $page
            ->has('bookings.data', 1)
            ->where('bookings.data.0.provider.is_active', false)
            ->where('bookings.data.0.provider.name', 'Trashed Staff')
        );
});
