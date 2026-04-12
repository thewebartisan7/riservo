<?php

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
    $this->mondayDate = '2026-04-13';

    $this->business = Business::factory()->create(['timezone' => 'Europe/Zurich']);
    $this->collaborator = User::factory()->create();
    $this->business->users()->attach($this->collaborator, ['role' => 'collaborator']);

    // Standard business hours: 09:00-18:00
    BusinessHour::factory()->create([
        'business_id' => $this->business->id,
        'day_of_week' => DayOfWeek::Monday->value,
        'open_time' => '09:00',
        'close_time' => '18:00',
    ]);

    // Collaborator works full business hours
    AvailabilityRule::factory()->create([
        'collaborator_id' => $this->collaborator->id,
        'business_id' => $this->business->id,
        'day_of_week' => DayOfWeek::Monday->value,
        'start_time' => '09:00',
        'end_time' => '18:00',
    ]);
});

test('generates slots at correct intervals for a simple schedule', function () {
    $service = Service::factory()->create([
        'business_id' => $this->business->id,
        'duration_minutes' => 60,
        'buffer_before' => 0,
        'buffer_after' => 0,
        'slot_interval_minutes' => 30,
    ]);
    $service->collaborators()->attach($this->collaborator);

    $slots = $this->slotService->getAvailableSlots($this->business, $service, $this->monday, $this->collaborator);

    // 09:00-18:00, 60 min service, 30 min interval
    // Last slot at 17:00 (ends 18:00). 17:30 would end at 18:30 — past close.
    // Slots: 09:00, 09:30, 10:00, ..., 17:00 = 17 slots
    expect($slots)->toHaveCount(17);
    expect($slots[0]->format('H:i'))->toBe('09:00');
    expect($slots[16]->format('H:i'))->toBe('17:00');
});

test('generates slots with 15-minute interval', function () {
    $service = Service::factory()->create([
        'business_id' => $this->business->id,
        'duration_minutes' => 30,
        'buffer_before' => 0,
        'buffer_after' => 0,
        'slot_interval_minutes' => 15,
    ]);
    $service->collaborators()->attach($this->collaborator);

    $slots = $this->slotService->getAvailableSlots($this->business, $service, $this->monday, $this->collaborator);

    // 09:00-18:00, 30 min service, 15 min interval
    // Last slot at 17:30 (ends 18:00). 17:45 would end at 18:15 — past close.
    // Slots: 09:00, 09:15, ..., 17:30 = 35 slots
    expect($slots)->toHaveCount(35);
    expect($slots[0]->format('H:i'))->toBe('09:00');
    expect($slots[34]->format('H:i'))->toBe('17:30');
});

test('generates slots with 60-minute interval', function () {
    $service = Service::factory()->create([
        'business_id' => $this->business->id,
        'duration_minutes' => 60,
        'buffer_before' => 0,
        'buffer_after' => 0,
        'slot_interval_minutes' => 60,
    ]);
    $service->collaborators()->attach($this->collaborator);

    $slots = $this->slotService->getAvailableSlots($this->business, $service, $this->monday, $this->collaborator);

    // 09:00-18:00, 60 min service, 60 min interval
    // Slots: 09:00, 10:00, ..., 17:00 = 9 slots
    expect($slots)->toHaveCount(9);
    expect($slots[0]->format('H:i'))->toBe('09:00');
    expect($slots[8]->format('H:i'))->toBe('17:00');
});

test('buffer_before prevents slots where buffer extends before window', function () {
    $service = Service::factory()->create([
        'business_id' => $this->business->id,
        'duration_minutes' => 60,
        'buffer_before' => 15,
        'buffer_after' => 0,
        'slot_interval_minutes' => 30,
    ]);
    $service->collaborators()->attach($this->collaborator);

    $slots = $this->slotService->getAvailableSlots($this->business, $service, $this->monday, $this->collaborator);

    // buffer_before=15: slot at 09:00 would need buffer starting at 08:45 (outside 09:00)
    // First valid: 09:30 (buffer starts at 09:15, within window)
    expect($slots[0]->format('H:i'))->toBe('09:30');
});

test('buffer_after prevents slots where service plus buffer extends past window', function () {
    $service = Service::factory()->create([
        'business_id' => $this->business->id,
        'duration_minutes' => 60,
        'buffer_before' => 0,
        'buffer_after' => 15,
        'slot_interval_minutes' => 30,
    ]);
    $service->collaborators()->attach($this->collaborator);

    $slots = $this->slotService->getAvailableSlots($this->business, $service, $this->monday, $this->collaborator);

    // buffer_after=15: slot at 17:00 ends at 18:00 + 15 = 18:15 (past close 18:00)
    // Last valid: 16:30 (ends at 17:30 + buffer 17:45, within window)
    $lastSlot = end($slots);
    expect($lastSlot->format('H:i'))->toBe('16:30');
});

test('both buffers combined correctly restrict available slots', function () {
    $service = Service::factory()->create([
        'business_id' => $this->business->id,
        'duration_minutes' => 60,
        'buffer_before' => 30,
        'buffer_after' => 30,
        'slot_interval_minutes' => 30,
    ]);
    $service->collaborators()->attach($this->collaborator);

    $slots = $this->slotService->getAvailableSlots($this->business, $service, $this->monday, $this->collaborator);

    // buffer_before=30: first slot at 09:30 (buffer starts at 09:00)
    // buffer_after=30: last slot where end+30 <= 18:00 → end <= 17:30 → start <= 16:30
    expect($slots[0]->format('H:i'))->toBe('09:30');
    $lastSlot = end($slots);
    expect($lastSlot->format('H:i'))->toBe('16:30');
});

test('confirmed booking blocks overlapping slots', function () {
    $service = Service::factory()->create([
        'business_id' => $this->business->id,
        'duration_minutes' => 60,
        'buffer_before' => 0,
        'buffer_after' => 0,
        'slot_interval_minutes' => 60,
    ]);
    $service->collaborators()->attach($this->collaborator);

    $customer = Customer::factory()->create();

    // Confirmed booking 10:00-11:00
    Booking::factory()->confirmed()->create([
        'business_id' => $this->business->id,
        'collaborator_id' => $this->collaborator->id,
        'service_id' => $service->id,
        'customer_id' => $customer->id,
        'starts_at' => $this->monday->setTimeFromTimeString('10:00')->setTimezone('UTC'),
        'ends_at' => $this->monday->setTimeFromTimeString('11:00')->setTimezone('UTC'),
    ]);

    $slots = $this->slotService->getAvailableSlots($this->business, $service, $this->monday, $this->collaborator);

    $slotTimes = array_map(fn ($s) => $s->format('H:i'), $slots);

    expect($slotTimes)->toContain('09:00');
    expect($slotTimes)->not->toContain('10:00');
    expect($slotTimes)->toContain('11:00');
});

test('pending booking blocks overlapping slots', function () {
    $service = Service::factory()->create([
        'business_id' => $this->business->id,
        'duration_minutes' => 60,
        'buffer_before' => 0,
        'buffer_after' => 0,
        'slot_interval_minutes' => 60,
    ]);
    $service->collaborators()->attach($this->collaborator);

    $customer = Customer::factory()->create();

    Booking::factory()->pending()->create([
        'business_id' => $this->business->id,
        'collaborator_id' => $this->collaborator->id,
        'service_id' => $service->id,
        'customer_id' => $customer->id,
        'starts_at' => $this->monday->setTimeFromTimeString('14:00')->setTimezone('UTC'),
        'ends_at' => $this->monday->setTimeFromTimeString('15:00')->setTimezone('UTC'),
    ]);

    $slots = $this->slotService->getAvailableSlots($this->business, $service, $this->monday, $this->collaborator);
    $slotTimes = array_map(fn ($s) => $s->format('H:i'), $slots);

    expect($slotTimes)->not->toContain('14:00');
    expect($slotTimes)->toContain('13:00');
    expect($slotTimes)->toContain('15:00');
});

test('cancelled and completed bookings do not block slots', function () {
    $service = Service::factory()->create([
        'business_id' => $this->business->id,
        'duration_minutes' => 60,
        'buffer_before' => 0,
        'buffer_after' => 0,
        'slot_interval_minutes' => 60,
    ]);
    $service->collaborators()->attach($this->collaborator);

    $customer = Customer::factory()->create();

    // Cancelled booking at 10:00
    Booking::factory()->cancelled()->create([
        'business_id' => $this->business->id,
        'collaborator_id' => $this->collaborator->id,
        'service_id' => $service->id,
        'customer_id' => $customer->id,
        'starts_at' => $this->monday->setTimeFromTimeString('10:00')->setTimezone('UTC'),
        'ends_at' => $this->monday->setTimeFromTimeString('11:00')->setTimezone('UTC'),
    ]);

    // Completed booking at 14:00
    Booking::factory()->completed()->create([
        'business_id' => $this->business->id,
        'collaborator_id' => $this->collaborator->id,
        'service_id' => $service->id,
        'customer_id' => $customer->id,
        'starts_at' => $this->monday->setTimeFromTimeString('14:00')->setTimezone('UTC'),
        'ends_at' => $this->monday->setTimeFromTimeString('15:00')->setTimezone('UTC'),
    ]);

    // No-show booking at 16:00
    Booking::factory()->noShow()->create([
        'business_id' => $this->business->id,
        'collaborator_id' => $this->collaborator->id,
        'service_id' => $service->id,
        'customer_id' => $customer->id,
        'starts_at' => $this->monday->setTimeFromTimeString('16:00')->setTimezone('UTC'),
        'ends_at' => $this->monday->setTimeFromTimeString('17:00')->setTimezone('UTC'),
    ]);

    $slots = $this->slotService->getAvailableSlots($this->business, $service, $this->monday, $this->collaborator);
    $slotTimes = array_map(fn ($s) => $s->format('H:i'), $slots);

    // All three times should still be available
    expect($slotTimes)->toContain('10:00');
    expect($slotTimes)->toContain('14:00');
    expect($slotTimes)->toContain('16:00');
});

test('existing booking buffers expand the blocked window', function () {
    $service = Service::factory()->create([
        'business_id' => $this->business->id,
        'duration_minutes' => 60,
        'buffer_before' => 15,
        'buffer_after' => 15,
        'slot_interval_minutes' => 30,
    ]);
    $service->collaborators()->attach($this->collaborator);

    $customer = Customer::factory()->create();

    // Booking 12:00-13:00 with buffer_before=15, buffer_after=15
    // Occupied window: 11:45-13:15
    Booking::factory()->confirmed()->create([
        'business_id' => $this->business->id,
        'collaborator_id' => $this->collaborator->id,
        'service_id' => $service->id,
        'customer_id' => $customer->id,
        'starts_at' => $this->monday->setTimeFromTimeString('12:00')->setTimezone('UTC'),
        'ends_at' => $this->monday->setTimeFromTimeString('13:00')->setTimezone('UTC'),
    ]);

    $slots = $this->slotService->getAvailableSlots($this->business, $service, $this->monday, $this->collaborator);
    $slotTimes = array_map(fn ($s) => $s->format('H:i'), $slots);

    // A slot at 11:30 with buffer_before=15 occupies 11:15-12:45 → overlaps with 11:45-13:15 → blocked
    expect($slotTimes)->not->toContain('11:30');
    // A slot at 11:00 with buffer_before=15 occupies 10:45-12:15 → overlaps with 11:45-13:15 → blocked
    expect($slotTimes)->not->toContain('11:00');
    // A slot at 10:30 with buffer_before=15 occupies 10:15-11:45 → ends exactly at 11:45, no overlap → available
    expect($slotTimes)->toContain('10:30');
});

test('back-to-back bookings leave no slot between them', function () {
    $service = Service::factory()->create([
        'business_id' => $this->business->id,
        'duration_minutes' => 60,
        'buffer_before' => 0,
        'buffer_after' => 0,
        'slot_interval_minutes' => 30,
    ]);
    $service->collaborators()->attach($this->collaborator);

    $customer = Customer::factory()->create();

    // Back-to-back: 10:00-11:00 and 11:00-12:00
    Booking::factory()->confirmed()->create([
        'business_id' => $this->business->id,
        'collaborator_id' => $this->collaborator->id,
        'service_id' => $service->id,
        'customer_id' => $customer->id,
        'starts_at' => $this->monday->setTimeFromTimeString('10:00')->setTimezone('UTC'),
        'ends_at' => $this->monday->setTimeFromTimeString('11:00')->setTimezone('UTC'),
    ]);
    Booking::factory()->confirmed()->create([
        'business_id' => $this->business->id,
        'collaborator_id' => $this->collaborator->id,
        'service_id' => $service->id,
        'customer_id' => $customer->id,
        'starts_at' => $this->monday->setTimeFromTimeString('11:00')->setTimezone('UTC'),
        'ends_at' => $this->monday->setTimeFromTimeString('12:00')->setTimezone('UTC'),
    ]);

    $slots = $this->slotService->getAvailableSlots($this->business, $service, $this->monday, $this->collaborator);
    $slotTimes = array_map(fn ($s) => $s->format('H:i'), $slots);

    expect($slotTimes)->not->toContain('10:00');
    expect($slotTimes)->not->toContain('10:30');
    expect($slotTimes)->not->toContain('11:00');
    expect($slotTimes)->not->toContain('11:30');
    expect($slotTimes)->toContain('09:00');
    expect($slotTimes)->toContain('12:00');
});

test('last slot ends exactly at window close time', function () {
    $service = Service::factory()->create([
        'business_id' => $this->business->id,
        'duration_minutes' => 30,
        'buffer_before' => 0,
        'buffer_after' => 0,
        'slot_interval_minutes' => 30,
    ]);
    $service->collaborators()->attach($this->collaborator);

    $slots = $this->slotService->getAvailableSlots($this->business, $service, $this->monday, $this->collaborator);

    // 30 min service, last slot at 17:30 (ends exactly at 18:00)
    $lastSlot = end($slots);
    expect($lastSlot->format('H:i'))->toBe('17:30');
});

test('no slots when service does not fit in available window', function () {
    // Short business hours
    $shortBusiness = Business::factory()->create(['timezone' => 'Europe/Zurich']);
    $collab = User::factory()->create();
    $shortBusiness->users()->attach($collab, ['role' => 'collaborator']);

    BusinessHour::factory()->create([
        'business_id' => $shortBusiness->id,
        'day_of_week' => DayOfWeek::Monday->value,
        'open_time' => '09:00',
        'close_time' => '09:30',
    ]);
    AvailabilityRule::factory()->create([
        'collaborator_id' => $collab->id,
        'business_id' => $shortBusiness->id,
        'day_of_week' => DayOfWeek::Monday->value,
        'start_time' => '09:00',
        'end_time' => '09:30',
    ]);

    $service = Service::factory()->create([
        'business_id' => $shortBusiness->id,
        'duration_minutes' => 60,
        'buffer_before' => 0,
        'buffer_after' => 0,
        'slot_interval_minutes' => 30,
    ]);
    $service->collaborators()->attach($collab);

    $slots = $this->slotService->getAvailableSlots($shortBusiness, $service, $this->monday, $collab);

    expect($slots)->toBeEmpty();
});

test('generates slots from multiple windows independently', function () {
    // Override: business with lunch break
    $lunchBusiness = Business::factory()->create(['timezone' => 'Europe/Zurich']);
    $collab = User::factory()->create();
    $lunchBusiness->users()->attach($collab, ['role' => 'collaborator']);

    BusinessHour::factory()->morning()->create([
        'business_id' => $lunchBusiness->id,
        'day_of_week' => DayOfWeek::Monday->value,
    ]);
    BusinessHour::factory()->afternoon()->create([
        'business_id' => $lunchBusiness->id,
        'day_of_week' => DayOfWeek::Monday->value,
    ]);
    AvailabilityRule::factory()->create([
        'collaborator_id' => $collab->id,
        'business_id' => $lunchBusiness->id,
        'day_of_week' => DayOfWeek::Monday->value,
        'start_time' => '09:00',
        'end_time' => '13:00',
    ]);
    AvailabilityRule::factory()->create([
        'collaborator_id' => $collab->id,
        'business_id' => $lunchBusiness->id,
        'day_of_week' => DayOfWeek::Monday->value,
        'start_time' => '14:00',
        'end_time' => '18:00',
    ]);

    $service = Service::factory()->create([
        'business_id' => $lunchBusiness->id,
        'duration_minutes' => 60,
        'buffer_before' => 0,
        'buffer_after' => 0,
        'slot_interval_minutes' => 60,
    ]);
    $service->collaborators()->attach($collab);

    $slots = $this->slotService->getAvailableSlots($lunchBusiness, $service, $this->monday, $collab);
    $slotTimes = array_map(fn ($s) => $s->format('H:i'), $slots);

    // Morning: 09:00, 10:00, 11:00, 12:00 (4 slots)
    // Afternoon: 14:00, 15:00, 16:00, 17:00 (4 slots)
    expect($slots)->toHaveCount(8);
    expect($slotTimes)->toContain('12:00');
    expect($slotTimes)->not->toContain('13:00');
    expect($slotTimes)->toContain('14:00');
});

test('slot times are in business timezone', function () {
    $service = Service::factory()->create([
        'business_id' => $this->business->id,
        'duration_minutes' => 60,
        'buffer_before' => 0,
        'buffer_after' => 0,
        'slot_interval_minutes' => 60,
    ]);
    $service->collaborators()->attach($this->collaborator);

    $slots = $this->slotService->getAvailableSlots($this->business, $service, $this->monday, $this->collaborator);

    // Verify timezone is Europe/Zurich, not UTC
    expect($slots[0]->timezone->getName())->toBe('Europe/Zurich');
    expect($slots[0]->format('H:i'))->toBe('09:00');
});
