<?php

use App\Enums\BookingStatus;
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

    $this->service = Service::factory()->create(['business_id' => $this->business->id, 'is_active' => true]);
    $this->customer = Customer::factory()->create();

    // Fix time to a known point
    $this->travelTo(CarbonImmutable::parse('2026-04-15 10:00', 'Europe/Zurich'));
});

test('admin sees business-wide stats', function () {
    // Create bookings for today
    Booking::factory()->count(3)->create([
        'business_id' => $this->business->id,
        'collaborator_id' => $this->collaborator->id,
        'service_id' => $this->service->id,
        'customer_id' => $this->customer->id,
        'starts_at' => CarbonImmutable::parse('2026-04-15 14:00', 'UTC'),
        'ends_at' => CarbonImmutable::parse('2026-04-15 15:00', 'UTC'),
        'status' => BookingStatus::Confirmed,
    ]);

    // Create a pending booking
    Booking::factory()->pending()->create([
        'business_id' => $this->business->id,
        'collaborator_id' => $this->collaborator->id,
        'service_id' => $this->service->id,
        'customer_id' => $this->customer->id,
        'starts_at' => CarbonImmutable::parse('2026-04-15 16:00', 'UTC'),
        'ends_at' => CarbonImmutable::parse('2026-04-15 17:00', 'UTC'),
    ]);

    $response = $this->actingAs($this->admin)->get('/dashboard');

    $response->assertSuccessful()
        ->assertInertia(fn ($page) => $page
            ->component('dashboard')
            ->has('stats')
            ->where('stats.today_count', 4)
            ->where('stats.pending_count', 1)
            ->has('todayBookings', 4)
            ->has('timezone')
        );
});

test('collaborator sees only own bookings in stats', function () {
    $otherCollaborator = User::factory()->create();
    $this->business->users()->attach($otherCollaborator, ['role' => 'collaborator']);

    // Booking for this collaborator
    Booking::factory()->create([
        'business_id' => $this->business->id,
        'collaborator_id' => $this->collaborator->id,
        'service_id' => $this->service->id,
        'customer_id' => $this->customer->id,
        'starts_at' => CarbonImmutable::parse('2026-04-15 14:00', 'UTC'),
        'ends_at' => CarbonImmutable::parse('2026-04-15 15:00', 'UTC'),
        'status' => BookingStatus::Confirmed,
    ]);

    // Booking for other collaborator
    Booking::factory()->create([
        'business_id' => $this->business->id,
        'collaborator_id' => $otherCollaborator->id,
        'service_id' => $this->service->id,
        'customer_id' => $this->customer->id,
        'starts_at' => CarbonImmutable::parse('2026-04-15 16:00', 'UTC'),
        'ends_at' => CarbonImmutable::parse('2026-04-15 17:00', 'UTC'),
        'status' => BookingStatus::Confirmed,
    ]);

    $response = $this->actingAs($this->collaborator)->get('/dashboard');

    $response->assertSuccessful()
        ->assertInertia(fn ($page) => $page
            ->component('dashboard')
            ->where('stats.today_count', 1)
            ->has('todayBookings', 1)
        );
});

test('empty state when no appointments today', function () {
    $response = $this->actingAs($this->admin)->get('/dashboard');

    $response->assertSuccessful()
        ->assertInertia(fn ($page) => $page
            ->component('dashboard')
            ->where('stats.today_count', 0)
            ->has('todayBookings', 0)
        );
});

test('unauthenticated user redirected to login', function () {
    $this->get('/dashboard')->assertRedirect('/login');
});

test('cancelled bookings not included in today count', function () {
    Booking::factory()->cancelled()->create([
        'business_id' => $this->business->id,
        'collaborator_id' => $this->collaborator->id,
        'service_id' => $this->service->id,
        'customer_id' => $this->customer->id,
        'starts_at' => CarbonImmutable::parse('2026-04-15 14:00', 'UTC'),
        'ends_at' => CarbonImmutable::parse('2026-04-15 15:00', 'UTC'),
    ]);

    $response = $this->actingAs($this->admin)->get('/dashboard');

    $response->assertInertia(fn ($page) => $page
        ->where('stats.today_count', 0)
    );
});
