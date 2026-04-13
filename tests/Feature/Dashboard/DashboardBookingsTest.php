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
    $this->business->users()->attach($this->admin, ['role' => 'admin']);

    $this->collaborator = User::factory()->create();
    $this->business->users()->attach($this->collaborator, ['role' => 'collaborator']);

    $this->service = Service::factory()->create([
        'business_id' => $this->business->id,
        'is_active' => true,
        'name' => 'Haircut',
    ]);
    $this->service->collaborators()->attach($this->collaborator);

    $this->customer = Customer::factory()->create();

    $this->travelTo(CarbonImmutable::parse('2026-04-15 10:00', 'Europe/Zurich'));
});

test('admin sees all business bookings', function () {
    Booking::factory()->count(3)->create([
        'business_id' => $this->business->id,
        'collaborator_id' => $this->collaborator->id,
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
            ->has('collaborators')
        );
});

test('collaborator sees only own bookings', function () {
    $otherCollaborator = User::factory()->create();
    $this->business->users()->attach($otherCollaborator, ['role' => 'collaborator']);

    Booking::factory()->create([
        'business_id' => $this->business->id,
        'collaborator_id' => $this->collaborator->id,
        'service_id' => $this->service->id,
        'customer_id' => $this->customer->id,
    ]);

    Booking::factory()->create([
        'business_id' => $this->business->id,
        'collaborator_id' => $otherCollaborator->id,
        'service_id' => $this->service->id,
        'customer_id' => $this->customer->id,
    ]);

    $response = $this->actingAs($this->collaborator)->get('/dashboard/bookings');

    $response->assertSuccessful()
        ->assertInertia(fn ($page) => $page
            ->has('bookings.data', 1)
            ->where('isAdmin', false)
        );
});

test('filter by status', function () {
    Booking::factory()->confirmed()->create([
        'business_id' => $this->business->id,
        'collaborator_id' => $this->collaborator->id,
        'service_id' => $this->service->id,
        'customer_id' => $this->customer->id,
    ]);

    Booking::factory()->pending()->create([
        'business_id' => $this->business->id,
        'collaborator_id' => $this->collaborator->id,
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
        'collaborator_id' => $this->collaborator->id,
        'service_id' => $this->service->id,
        'customer_id' => $this->customer->id,
    ]);

    Booking::factory()->create([
        'business_id' => $this->business->id,
        'collaborator_id' => $this->collaborator->id,
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
        'collaborator_id' => $this->collaborator->id,
        'service_id' => $this->service->id,
        'customer_id' => $this->customer->id,
        'starts_at' => CarbonImmutable::parse('2026-04-15 14:00', 'UTC'),
        'ends_at' => CarbonImmutable::parse('2026-04-15 15:00', 'UTC'),
    ]);

    Booking::factory()->create([
        'business_id' => $this->business->id,
        'collaborator_id' => $this->collaborator->id,
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

test('filter by collaborator (admin only)', function () {
    $otherCollaborator = User::factory()->create();
    $this->business->users()->attach($otherCollaborator, ['role' => 'collaborator']);

    Booking::factory()->create([
        'business_id' => $this->business->id,
        'collaborator_id' => $this->collaborator->id,
        'service_id' => $this->service->id,
        'customer_id' => $this->customer->id,
    ]);

    Booking::factory()->create([
        'business_id' => $this->business->id,
        'collaborator_id' => $otherCollaborator->id,
        'service_id' => $this->service->id,
        'customer_id' => $this->customer->id,
    ]);

    $response = $this->actingAs($this->admin)->get('/dashboard/bookings?collaborator_id='.$this->collaborator->id);

    $response->assertInertia(fn ($page) => $page
        ->has('bookings.data', 1)
    );
});

test('bookings include related data', function () {
    Booking::factory()->create([
        'business_id' => $this->business->id,
        'collaborator_id' => $this->collaborator->id,
        'service_id' => $this->service->id,
        'customer_id' => $this->customer->id,
    ]);

    $response = $this->actingAs($this->admin)->get('/dashboard/bookings');

    $response->assertInertia(fn ($page) => $page
        ->has('bookings.data.0.service.name')
        ->has('bookings.data.0.collaborator.name')
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
        'collaborator_id' => $this->collaborator->id,
        'service_id' => $this->service->id,
        'customer_id' => $this->customer->id,
        'starts_at' => CarbonImmutable::parse('2026-04-20 10:00', 'UTC'),
        'ends_at' => CarbonImmutable::parse('2026-04-20 11:00', 'UTC'),
    ]);

    Booking::factory()->create([
        'business_id' => $this->business->id,
        'collaborator_id' => $this->collaborator->id,
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
    Booking::factory()->count(25)->create([
        'business_id' => $this->business->id,
        'collaborator_id' => $this->collaborator->id,
        'service_id' => $this->service->id,
        'customer_id' => $this->customer->id,
    ]);

    $response = $this->actingAs($this->admin)->get('/dashboard/bookings');

    $response->assertInertia(fn ($page) => $page
        ->has('bookings.data', 20)
        ->where('bookings.last_page', 2)
    );
});
