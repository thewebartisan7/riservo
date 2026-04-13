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

test('calendar page loads with default week view and today date', function () {
    $response = $this->actingAs($this->admin)->get('/dashboard/calendar');

    $response->assertSuccessful()
        ->assertInertia(fn ($page) => $page
            ->component('dashboard/calendar')
            ->where('view', 'week')
            ->where('date', '2026-04-15')
            ->where('isAdmin', true)
            ->has('bookings')
            ->has('collaborators')
            ->has('services')
            ->has('timezone')
        );
});

test('calendar returns bookings within the visible week range', function () {
    // Within the week of April 13–19, 2026
    $inRange = Booking::factory()->confirmed()->create([
        'business_id' => $this->business->id,
        'collaborator_id' => $this->collaborator->id,
        'service_id' => $this->service->id,
        'customer_id' => $this->customer->id,
        'starts_at' => CarbonImmutable::parse('2026-04-16 09:00', 'Europe/Zurich')->utc(),
        'ends_at' => CarbonImmutable::parse('2026-04-16 10:00', 'Europe/Zurich')->utc(),
    ]);

    // Outside the week
    Booking::factory()->confirmed()->create([
        'business_id' => $this->business->id,
        'collaborator_id' => $this->collaborator->id,
        'service_id' => $this->service->id,
        'customer_id' => $this->customer->id,
        'starts_at' => CarbonImmutable::parse('2026-04-22 09:00', 'Europe/Zurich')->utc(),
        'ends_at' => CarbonImmutable::parse('2026-04-22 10:00', 'Europe/Zurich')->utc(),
    ]);

    $response = $this->actingAs($this->admin)->get('/dashboard/calendar?view=week&date=2026-04-15');

    $response->assertSuccessful()
        ->assertInertia(fn ($page) => $page
            ->has('bookings', 1)
            ->where('bookings.0.id', $inRange->id)
        );
});

test('view parameter switches between day, week, and month', function () {
    $response = $this->actingAs($this->admin)->get('/dashboard/calendar?view=day&date=2026-04-15');
    $response->assertSuccessful()
        ->assertInertia(fn ($page) => $page->where('view', 'day'));

    $response = $this->actingAs($this->admin)->get('/dashboard/calendar?view=month&date=2026-04-15');
    $response->assertSuccessful()
        ->assertInertia(fn ($page) => $page->where('view', 'month'));
});

test('date parameter navigates to specified date', function () {
    $response = $this->actingAs($this->admin)->get('/dashboard/calendar?date=2026-05-01');

    $response->assertSuccessful()
        ->assertInertia(fn ($page) => $page->where('date', '2026-05-01'));
});

test('day view returns only bookings for that day', function () {
    $todayBooking = Booking::factory()->confirmed()->create([
        'business_id' => $this->business->id,
        'collaborator_id' => $this->collaborator->id,
        'service_id' => $this->service->id,
        'customer_id' => $this->customer->id,
        'starts_at' => CarbonImmutable::parse('2026-04-15 09:00', 'Europe/Zurich')->utc(),
        'ends_at' => CarbonImmutable::parse('2026-04-15 10:00', 'Europe/Zurich')->utc(),
    ]);

    Booking::factory()->confirmed()->create([
        'business_id' => $this->business->id,
        'collaborator_id' => $this->collaborator->id,
        'service_id' => $this->service->id,
        'customer_id' => $this->customer->id,
        'starts_at' => CarbonImmutable::parse('2026-04-16 09:00', 'Europe/Zurich')->utc(),
        'ends_at' => CarbonImmutable::parse('2026-04-16 10:00', 'Europe/Zurich')->utc(),
    ]);

    $response = $this->actingAs($this->admin)->get('/dashboard/calendar?view=day&date=2026-04-15');

    $response->assertSuccessful()
        ->assertInertia(fn ($page) => $page
            ->has('bookings', 1)
            ->where('bookings.0.id', $todayBooking->id)
        );
});

test('month view returns bookings for the padded month range', function () {
    $booking = Booking::factory()->confirmed()->create([
        'business_id' => $this->business->id,
        'collaborator_id' => $this->collaborator->id,
        'service_id' => $this->service->id,
        'customer_id' => $this->customer->id,
        'starts_at' => CarbonImmutable::parse('2026-04-30 09:00', 'Europe/Zurich')->utc(),
        'ends_at' => CarbonImmutable::parse('2026-04-30 10:00', 'Europe/Zurich')->utc(),
    ]);

    $response = $this->actingAs($this->admin)->get('/dashboard/calendar?view=month&date=2026-04-15');

    $response->assertSuccessful()
        ->assertInertia(fn ($page) => $page->has('bookings', 1));
});

test('admin sees all business bookings on calendar', function () {
    $otherCollaborator = User::factory()->create();
    $this->business->users()->attach($otherCollaborator, ['role' => 'collaborator']);

    Booking::factory()->confirmed()->create([
        'business_id' => $this->business->id,
        'collaborator_id' => $this->collaborator->id,
        'service_id' => $this->service->id,
        'customer_id' => $this->customer->id,
        'starts_at' => CarbonImmutable::parse('2026-04-15 09:00', 'Europe/Zurich')->utc(),
        'ends_at' => CarbonImmutable::parse('2026-04-15 10:00', 'Europe/Zurich')->utc(),
    ]);

    Booking::factory()->confirmed()->create([
        'business_id' => $this->business->id,
        'collaborator_id' => $otherCollaborator->id,
        'service_id' => $this->service->id,
        'customer_id' => $this->customer->id,
        'starts_at' => CarbonImmutable::parse('2026-04-15 11:00', 'Europe/Zurich')->utc(),
        'ends_at' => CarbonImmutable::parse('2026-04-15 12:00', 'Europe/Zurich')->utc(),
    ]);

    $response = $this->actingAs($this->admin)->get('/dashboard/calendar');

    $response->assertSuccessful()
        ->assertInertia(fn ($page) => $page->has('bookings', 2));
});

test('collaborator sees only own bookings on calendar', function () {
    $otherCollaborator = User::factory()->create();
    $this->business->users()->attach($otherCollaborator, ['role' => 'collaborator']);

    Booking::factory()->confirmed()->create([
        'business_id' => $this->business->id,
        'collaborator_id' => $this->collaborator->id,
        'service_id' => $this->service->id,
        'customer_id' => $this->customer->id,
        'starts_at' => CarbonImmutable::parse('2026-04-15 09:00', 'Europe/Zurich')->utc(),
        'ends_at' => CarbonImmutable::parse('2026-04-15 10:00', 'Europe/Zurich')->utc(),
    ]);

    Booking::factory()->confirmed()->create([
        'business_id' => $this->business->id,
        'collaborator_id' => $otherCollaborator->id,
        'service_id' => $this->service->id,
        'customer_id' => $this->customer->id,
        'starts_at' => CarbonImmutable::parse('2026-04-15 11:00', 'Europe/Zurich')->utc(),
        'ends_at' => CarbonImmutable::parse('2026-04-15 12:00', 'Europe/Zurich')->utc(),
    ]);

    $response = $this->actingAs($this->collaborator)->get('/dashboard/calendar');

    $response->assertSuccessful()
        ->assertInertia(fn ($page) => $page
            ->has('bookings', 1)
            ->where('isAdmin', false)
        );
});

test('unauthenticated user is redirected from calendar', function () {
    $response = $this->get('/dashboard/calendar');

    $response->assertRedirect('/login');
});

test('invalid view parameter falls back to week', function () {
    $response = $this->actingAs($this->admin)->get('/dashboard/calendar?view=year');

    $response->assertSuccessful()
        ->assertInertia(fn ($page) => $page->where('view', 'week'));
});
