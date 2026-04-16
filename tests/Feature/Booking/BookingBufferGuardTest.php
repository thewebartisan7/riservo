<?php

use App\Enums\BookingStatus;
use App\Enums\DayOfWeek;
use App\Models\AvailabilityRule;
use App\Models\Booking;
use App\Models\Business;
use App\Models\BusinessHour;
use App\Models\Customer;
use App\Models\Service;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Notification;

beforeEach(function () {
    $this->business = Business::factory()->onboarded()->create(['timezone' => 'Europe/Zurich']);
    $this->staff = User::factory()->create();
    $this->provider = attachProvider($this->business, $this->staff);

    BusinessHour::factory()->create([
        'business_id' => $this->business->id,
        'day_of_week' => DayOfWeek::Friday->value,
        'open_time' => '09:00',
        'close_time' => '18:00',
    ]);

    AvailabilityRule::factory()->create([
        'provider_id' => $this->provider->id,
        'business_id' => $this->business->id,
        'day_of_week' => DayOfWeek::Friday->value,
        'start_time' => '09:00',
        'end_time' => '18:00',
    ]);

    $this->serviceA = Service::factory()->create([
        'business_id' => $this->business->id,
        'is_active' => true,
        'duration_minutes' => 30,
        'buffer_before' => 0,
        'buffer_after' => 15,
        'slot_interval_minutes' => 15,
    ]);
    $this->provider->services()->attach($this->serviceA);

    $this->serviceB = Service::factory()->create([
        'business_id' => $this->business->id,
        'is_active' => true,
        'duration_minutes' => 30,
        'buffer_before' => 0,
        'buffer_after' => 0,
        'slot_interval_minutes' => 15,
    ]);
    $this->provider->services()->attach($this->serviceB);

    $this->customer = Customer::factory()->create();

    // Friday 2026-05-01 — business open 09:00-18:00 Europe/Zurich.
    $this->travelTo(CarbonImmutable::parse('2026-05-01 07:00', 'Europe/Zurich'));
});

test('HTTP: booking inside another booking buffer window returns 409', function () {
    Notification::fake();

    // Service A at 10:00-10:30 local (CEST) with buffer_after=15 → effective ends at 10:45 local.
    Booking::factory()->create([
        'business_id' => $this->business->id,
        'provider_id' => $this->provider->id,
        'service_id' => $this->serviceA->id,
        'customer_id' => $this->customer->id,
        'starts_at' => CarbonImmutable::parse('2026-05-01 10:00', 'Europe/Zurich')->utc(),
        'ends_at' => CarbonImmutable::parse('2026-05-01 10:30', 'Europe/Zurich')->utc(),
        'buffer_before_minutes' => 0,
        'buffer_after_minutes' => 15,
        'status' => BookingStatus::Confirmed,
    ]);

    // Request Service B (zero buffers) at 10:30 local — clean-adjacent but inside the buffer.
    $response = $this->postJson('/booking/'.$this->business->slug.'/book', [
        'service_id' => $this->serviceB->id,
        'provider_id' => $this->provider->id,
        'date' => '2026-05-01',
        'time' => '10:30',
        'name' => 'Jane Doe',
        'email' => 'jane@example.com',
        'phone' => '+41 79 123 45 67',
        'website' => '',
    ]);

    $response->assertStatus(409);
    expect(Booking::count())->toBe(1);
});

test('direct insert with conflicting buffer fails with SQLSTATE 23P01', function () {
    Booking::factory()->create([
        'business_id' => $this->business->id,
        'provider_id' => $this->provider->id,
        'service_id' => $this->serviceA->id,
        'customer_id' => $this->customer->id,
        'starts_at' => CarbonImmutable::parse('2026-05-01 10:00', 'UTC'),
        'ends_at' => CarbonImmutable::parse('2026-05-01 10:30', 'UTC'),
        'buffer_before_minutes' => 0,
        'buffer_after_minutes' => 15,
        'status' => BookingStatus::Confirmed,
    ]);

    try {
        Booking::factory()->create([
            'business_id' => $this->business->id,
            'provider_id' => $this->provider->id,
            'service_id' => $this->serviceB->id,
            'customer_id' => $this->customer->id,
            'starts_at' => CarbonImmutable::parse('2026-05-01 10:30', 'UTC'),
            'ends_at' => CarbonImmutable::parse('2026-05-01 11:00', 'UTC'),
            'buffer_before_minutes' => 0,
            'buffer_after_minutes' => 0,
            'status' => BookingStatus::Confirmed,
        ]);

        throw new RuntimeException('expected QueryException not thrown');
    } catch (QueryException $e) {
        expect($e->getPrevious()?->getCode() ?? $e->getCode())->toBe('23P01');
    }
});

test('HTTP: booking immediately after buffer window succeeds', function () {
    Notification::fake();

    Booking::factory()->create([
        'business_id' => $this->business->id,
        'provider_id' => $this->provider->id,
        'service_id' => $this->serviceA->id,
        'customer_id' => $this->customer->id,
        'starts_at' => CarbonImmutable::parse('2026-05-01 10:00', 'Europe/Zurich')->utc(),
        'ends_at' => CarbonImmutable::parse('2026-05-01 10:30', 'Europe/Zurich')->utc(),
        'buffer_before_minutes' => 0,
        'buffer_after_minutes' => 15,
        'status' => BookingStatus::Confirmed,
    ]);

    // Request Service B at 10:45 local — exactly at the end of the buffer window.
    $response = $this->postJson('/booking/'.$this->business->slug.'/book', [
        'service_id' => $this->serviceB->id,
        'provider_id' => $this->provider->id,
        'date' => '2026-05-01',
        'time' => '10:45',
        'name' => 'Jane Doe',
        'email' => 'jane@example.com',
        'phone' => '+41 79 123 45 67',
        'website' => '',
    ]);

    $response->assertStatus(201);
    expect(Booking::count())->toBe(2);
});
