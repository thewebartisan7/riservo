<?php

use App\Enums\AssignmentStrategy;
use App\Enums\DayOfWeek;
use App\Models\AvailabilityRule;
use App\Models\Booking;
use App\Models\Business;
use App\Models\BusinessHour;
use App\Models\Customer;
use App\Models\Service;
use App\Models\User;
use App\Services\SlotGeneratorService;
use Carbon\CarbonImmutable;

beforeEach(function () {
    $this->slotService = app(SlotGeneratorService::class);
    $this->monday = CarbonImmutable::parse('2026-04-13', 'Europe/Zurich');

    $this->business = Business::factory()->create([
        'timezone' => 'Europe/Zurich',
        'assignment_strategy' => AssignmentStrategy::FirstAvailable,
    ]);

    BusinessHour::factory()->create([
        'business_id' => $this->business->id,
        'day_of_week' => DayOfWeek::Monday->value,
        'open_time' => '09:00',
        'close_time' => '18:00',
    ]);

    // Create 3 staff members and their providers
    $userA = User::factory()->create(['name' => 'Alice']);
    $userB = User::factory()->create(['name' => 'Bob']);
    $userC = User::factory()->create(['name' => 'Charlie']);

    $this->providerA = attachProvider($this->business, $userA);
    $this->providerB = attachProvider($this->business, $userB);
    $this->providerC = attachProvider($this->business, $userC);

    foreach ([$this->providerA, $this->providerB, $this->providerC] as $provider) {
        AvailabilityRule::factory()->create([
            'provider_id' => $provider->id,
            'business_id' => $this->business->id,
            'day_of_week' => DayOfWeek::Monday->value,
            'start_time' => '09:00',
            'end_time' => '18:00',
        ]);
    }

    $this->service = Service::factory()->create([
        'business_id' => $this->business->id,
        'duration_minutes' => 60,
        'buffer_before' => 0,
        'buffer_after' => 0,
        'slot_interval_minutes' => 60,
    ]);

    foreach ([$this->providerA, $this->providerB, $this->providerC] as $provider) {
        $this->service->providers()->attach($provider);
    }
});

test('first_available returns first provider by ID with open slot', function () {
    $startsAt = $this->monday->setTimeFromTimeString('10:00');

    $assigned = $this->slotService->assignProvider($this->business, $this->service, $startsAt);

    expect($assigned)->not->toBeNull();
    expect($assigned->id)->toBe($this->providerA->id);
});

test('first_available skips providers not assigned to service', function () {
    // Remove Alice's provider from service
    $this->service->providers()->detach($this->providerA);

    $startsAt = $this->monday->setTimeFromTimeString('10:00');
    $assigned = $this->slotService->assignProvider($this->business, $this->service, $startsAt);

    expect($assigned)->not->toBeNull();
    expect($assigned->id)->toBe($this->providerB->id);
});

test('first_available skips provider whose slot is booked', function () {
    $customer = Customer::factory()->create();

    // Alice has a booking at 10:00
    Booking::factory()->confirmed()->create([
        'business_id' => $this->business->id,
        'provider_id' => $this->providerA->id,
        'service_id' => $this->service->id,
        'customer_id' => $customer->id,
        'starts_at' => $this->monday->setTimeFromTimeString('10:00')->setTimezone('UTC'),
        'ends_at' => $this->monday->setTimeFromTimeString('11:00')->setTimezone('UTC'),
    ]);

    $startsAt = $this->monday->setTimeFromTimeString('10:00');
    $assigned = $this->slotService->assignProvider($this->business, $this->service, $startsAt);

    expect($assigned)->not->toBeNull();
    expect($assigned->id)->toBe($this->providerB->id);
});

test('round_robin returns provider with fewest upcoming bookings', function () {
    $this->business->update(['assignment_strategy' => AssignmentStrategy::RoundRobin]);

    $customer = Customer::factory()->create();

    // Alice: 3 upcoming bookings
    foreach ([1, 2, 3] as $day) {
        Booking::factory()->confirmed()->create([
            'business_id' => $this->business->id,
            'provider_id' => $this->providerA->id,
            'service_id' => $this->service->id,
            'customer_id' => $customer->id,
            'starts_at' => now()->addDays($day)->setTime(10, 0),
            'ends_at' => now()->addDays($day)->setTime(11, 0),
        ]);
    }

    // Bob: 1 upcoming booking
    Booking::factory()->confirmed()->create([
        'business_id' => $this->business->id,
        'provider_id' => $this->providerB->id,
        'service_id' => $this->service->id,
        'customer_id' => $customer->id,
        'starts_at' => now()->addDay()->setTime(14, 0),
        'ends_at' => now()->addDay()->setTime(15, 0),
    ]);

    // Charlie: 0 upcoming bookings

    $startsAt = $this->monday->setTimeFromTimeString('10:00');
    $assigned = $this->slotService->assignProvider($this->business, $this->service, $startsAt);

    // Charlie has fewest bookings (0), so should be assigned
    expect($assigned->id)->toBe($this->providerC->id);
});

test('round_robin tie-breaking uses lowest ID', function () {
    $this->business->update(['assignment_strategy' => AssignmentStrategy::RoundRobin]);

    // All providers have 0 upcoming bookings — should pick first by ID
    $startsAt = $this->monday->setTimeFromTimeString('10:00');
    $assigned = $this->slotService->assignProvider($this->business, $this->service, $startsAt);

    expect($assigned->id)->toBe($this->providerA->id);
});

test('returns null when no provider is available', function () {
    $customer = Customer::factory()->create();

    // Book all three providers at 10:00
    foreach ([$this->providerA, $this->providerB, $this->providerC] as $provider) {
        Booking::factory()->confirmed()->create([
            'business_id' => $this->business->id,
            'provider_id' => $provider->id,
            'service_id' => $this->service->id,
            'customer_id' => $customer->id,
            'starts_at' => $this->monday->setTimeFromTimeString('10:00')->setTimezone('UTC'),
            'ends_at' => $this->monday->setTimeFromTimeString('11:00')->setTimezone('UTC'),
        ]);
    }

    $startsAt = $this->monday->setTimeFromTimeString('10:00');
    $assigned = $this->slotService->assignProvider($this->business, $this->service, $startsAt);

    expect($assigned)->toBeNull();
});

test('getAvailableSlots with null provider returns union of all slots', function () {
    $customer = Customer::factory()->create();

    // Alice booked at 10:00, Bob and Charlie free
    Booking::factory()->confirmed()->create([
        'business_id' => $this->business->id,
        'provider_id' => $this->providerA->id,
        'service_id' => $this->service->id,
        'customer_id' => $customer->id,
        'starts_at' => $this->monday->setTimeFromTimeString('10:00')->setTimezone('UTC'),
        'ends_at' => $this->monday->setTimeFromTimeString('11:00')->setTimezone('UTC'),
    ]);

    $slotsAll = $this->slotService->getAvailableSlots($this->business, $this->service, $this->monday);
    $slotsAlice = $this->slotService->getAvailableSlots($this->business, $this->service, $this->monday, $this->providerA);

    $allTimes = array_map(fn ($s) => $s->format('H:i'), $slotsAll);
    $aliceTimes = array_map(fn ($s) => $s->format('H:i'), $slotsAlice);

    // 10:00 is not available for Alice, but is available via "any" (Bob/Charlie have it)
    expect($aliceTimes)->not->toContain('10:00');
    expect($allTimes)->toContain('10:00');

    // Union should have all 9 hourly slots (09-17)
    expect($slotsAll)->toHaveCount(9);
});
