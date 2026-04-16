<?php

use App\Enums\BookingSource;
use App\Enums\BookingStatus;
use App\Enums\DayOfWeek;
use App\Models\AvailabilityRule;
use App\Models\Booking;
use App\Models\Business;
use App\Models\BusinessHour;
use App\Models\Customer;
use App\Models\Service;
use App\Models\User;
use App\Notifications\BookingConfirmedNotification;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Notification;

beforeEach(function () {
    $this->business = Business::factory()->onboarded()->create(['timezone' => 'Europe/Zurich']);
    $this->admin = User::factory()->create();
    attachAdmin($this->business, $this->admin);

    $this->staff = User::factory()->create(['name' => 'Bob']);
    $this->provider = attachProvider($this->business, $this->staff);

    BusinessHour::factory()->create([
        'business_id' => $this->business->id,
        'day_of_week' => DayOfWeek::Wednesday->value,
        'open_time' => '09:00',
        'close_time' => '18:00',
    ]);

    AvailabilityRule::factory()->create([
        'provider_id' => $this->provider->id,
        'business_id' => $this->business->id,
        'day_of_week' => DayOfWeek::Wednesday->value,
        'start_time' => '09:00',
        'end_time' => '18:00',
    ]);

    $this->service = Service::factory()->create([
        'business_id' => $this->business->id,
        'is_active' => true,
        'duration_minutes' => 60,
        'buffer_before' => 0,
        'buffer_after' => 0,
        'slot_interval_minutes' => 60,
    ]);
    $this->provider->services()->attach($this->service);

    // Fix time to Wednesday
    $this->travelTo(CarbonImmutable::parse('2026-04-15 08:00', 'Europe/Zurich'));
});

test('manual booking creation succeeds', function () {
    Notification::fake();

    $response = $this->actingAs($this->admin)->post('/dashboard/bookings', [
        'customer_name' => 'John Smith',
        'customer_email' => 'john@example.com',
        'customer_phone' => '+41 79 000 00 00',
        'service_id' => $this->service->id,
        'provider_id' => $this->provider->id,
        'date' => '2026-04-15',
        'time' => '10:00',
        'notes' => 'Walk-in customer',
    ]);

    $response->assertRedirect('/dashboard/bookings');
    $response->assertSessionHas('success');

    $booking = Booking::first();
    expect($booking->status)->toBe(BookingStatus::Confirmed)
        ->and($booking->source)->toBe(BookingSource::Manual)
        ->and($booking->notes)->toBe('Walk-in customer')
        ->and($booking->customer->name)->toBe('John Smith')
        ->and($booking->customer->email)->toBe('john@example.com');

    Notification::assertSentOnDemand(BookingConfirmedNotification::class);
});

test('customer find-or-create works for existing customer', function () {
    Notification::fake();

    $existingCustomer = Customer::factory()->create([
        'email' => 'existing@example.com',
        'name' => 'Old Name',
    ]);

    $this->actingAs($this->admin)->post('/dashboard/bookings', [
        'customer_name' => 'New Name',
        'customer_email' => 'existing@example.com',
        'service_id' => $this->service->id,
        'provider_id' => $this->provider->id,
        'date' => '2026-04-15',
        'time' => '10:00',
    ]);

    expect(Customer::count())->toBe(1);
    expect($existingCustomer->fresh()->name)->toBe('New Name');
});

test('slot conflict returns redirect with error', function () {
    // Create an existing booking at 10:00
    Booking::factory()->create([
        'business_id' => $this->business->id,
        'provider_id' => $this->provider->id,
        'service_id' => $this->service->id,
        'customer_id' => Customer::factory()->create()->id,
        'starts_at' => CarbonImmutable::parse('2026-04-15 10:00', 'Europe/Zurich')->utc(),
        'ends_at' => CarbonImmutable::parse('2026-04-15 11:00', 'Europe/Zurich')->utc(),
        'status' => BookingStatus::Confirmed,
    ]);

    $response = $this->actingAs($this->admin)->post('/dashboard/bookings', [
        'customer_name' => 'Jane',
        'customer_email' => 'jane@example.com',
        'service_id' => $this->service->id,
        'provider_id' => $this->provider->id,
        'date' => '2026-04-15',
        'time' => '10:00',
    ]);

    $response->assertRedirect();
    $response->assertSessionHas('error');
    expect(Booking::count())->toBe(1);
});

test('validation errors for missing fields', function () {
    $response = $this->actingAs($this->admin)->post('/dashboard/bookings', []);

    $response->assertSessionHasErrors(['customer_name', 'customer_email', 'service_id', 'date', 'time']);
});

test('staff can create booking for themselves', function () {
    Notification::fake();

    $response = $this->actingAs($this->staff)->post('/dashboard/bookings', [
        'customer_name' => 'Staff Customer',
        'customer_email' => 'staff.cust@example.com',
        'service_id' => $this->service->id,
        'provider_id' => $this->provider->id,
        'date' => '2026-04-15',
        'time' => '11:00',
    ]);

    $response->assertRedirect('/dashboard/bookings');
    expect(Booking::count())->toBe(1);
});

test('available dates API returns correct data', function () {
    $response = $this->actingAs($this->admin)
        ->getJson('/dashboard/api/available-dates?service_id='.$this->service->id.'&month=2026-04');

    $response->assertSuccessful()
        ->assertJsonStructure(['dates']);

    $dates = $response->json('dates');
    // April 15 is a Wednesday with availability
    expect($dates['2026-04-15'])->toBeTrue();
    // April 14 is past (we're on April 15)
    expect($dates['2026-04-14'])->toBeFalse();
});

test('slots API returns correct data', function () {
    $response = $this->actingAs($this->admin)
        ->getJson('/dashboard/api/slots?service_id='.$this->service->id.'&date=2026-04-15');

    $response->assertSuccessful()
        ->assertJsonStructure(['slots', 'timezone']);

    $slots = $response->json('slots');
    expect($slots)->toContain('09:00')
        ->toContain('10:00');
});
