<?php

use App\Enums\DayOfWeek;
use App\Models\AvailabilityRule;
use App\Models\Business;
use App\Models\BusinessHour;
use App\Models\Service;
use App\Models\User;
use App\Services\SlotGeneratorService;
use Carbon\CarbonImmutable;
use Database\Seeders\BusinessSeeder;
use Illuminate\Support\Carbon;

test('full slot calculation with seeded Salone Bella data', function () {
    // Freeze time so seeder dates are deterministic
    Carbon::setTestNow('2026-04-06 10:00:00');

    $this->seed(BusinessSeeder::class);

    $slotService = app(SlotGeneratorService::class);

    $business = Business::where('slug', 'salone-bella')->first();
    $maria = User::where('email', 'maria@salone-bella.ch')->first();
    $taglioDonna = Service::where('slug', 'taglio-donna')->first();

    // Next Monday = 2026-04-13
    // Seeder creates bookings in UTC. Business timezone is Europe/Zurich (CEST = UTC+2).
    // Maria's seeded bookings in local time:
    //   09:00 UTC = 11:00 CEST → Taglio Donna 45min (buffer_after=10) → occupied 11:00-11:55
    //   11:00 UTC = 13:00 CEST → Piega 30min (no buffers) → occupied 13:00-13:30
    $nextMonday = CarbonImmutable::parse('2026-04-13', 'Europe/Zurich');

    $slots = $slotService->getAvailableSlots($business, $taglioDonna, $nextMonday, $maria);
    $slotTimes = array_map(fn ($s) => $s->format('H:i'), $slots);

    // Taglio Donna: 45 min + 10 min buffer_after = 55 min total, slot_interval 15 min
    // Business hours: 09:00-12:30, 13:30-18:30

    // Morning: 09:00 should be available (no booking conflict at 09:00 local)
    expect($slotTimes)->toContain('09:00');

    // 10:00 available (occupied 10:00-10:55, doesn't overlap with 11:00-11:55)
    expect($slotTimes)->toContain('10:00');

    // 10:15 blocked (occupied 10:15-11:10, overlaps with 11:00-11:55)
    expect($slotTimes)->not->toContain('10:15');

    // 11:00 blocked (directly overlaps with booking 11:00-11:55)
    expect($slotTimes)->not->toContain('11:00');

    // Afternoon: 13:30 available (Piega booking 13:00-13:30 ends exactly at window start)
    expect($slotTimes)->toContain('13:30');
    expect($slotTimes)->toContain('14:00');

    Carbon::setTestNow(); // Reset
});

test('Swiss National Day has no availability', function () {
    Carbon::setTestNow('2026-04-06 10:00:00');

    $this->seed(BusinessSeeder::class);

    $slotService = app(SlotGeneratorService::class);

    $business = Business::where('slug', 'salone-bella')->first();
    $maria = User::where('email', 'maria@salone-bella.ch')->first();
    $taglioDonna = Service::where('slug', 'taglio-donna')->first();

    // Aug 1 = Swiss National Day (business-level block in seeder) — Saturday
    $nationalDay = CarbonImmutable::parse('2026-08-01', 'Europe/Zurich');

    $slots = $slotService->getAvailableSlots($business, $taglioDonna, $nationalDay, $maria);

    expect($slots)->toBeEmpty();

    Carbon::setTestNow();
});

test('slots are correct in Europe/Zurich timezone with UTC bookings', function () {
    $slotService = app(SlotGeneratorService::class);

    $business = Business::factory()->create(['timezone' => 'Europe/Zurich']);
    $collab = User::factory()->create();
    $business->users()->attach($collab, ['role' => 'collaborator']);

    // 2026-04-13 is CEST (UTC+2)
    $date = CarbonImmutable::parse('2026-04-13', 'Europe/Zurich');

    BusinessHour::factory()->create([
        'business_id' => $business->id,
        'day_of_week' => DayOfWeek::Monday->value,
        'open_time' => '09:00',
        'close_time' => '12:00',
    ]);
    AvailabilityRule::factory()->create([
        'collaborator_id' => $collab->id,
        'business_id' => $business->id,
        'day_of_week' => DayOfWeek::Monday->value,
        'start_time' => '09:00',
        'end_time' => '12:00',
    ]);

    $service = Service::factory()->create([
        'business_id' => $business->id,
        'duration_minutes' => 60,
        'buffer_before' => 0,
        'buffer_after' => 0,
        'slot_interval_minutes' => 60,
    ]);
    $service->collaborators()->attach($collab);

    $slots = $slotService->getAvailableSlots($business, $service, $date, $collab);

    // Slots should be in Europe/Zurich: 09:00, 10:00, 11:00
    expect($slots)->toHaveCount(3);
    expect($slots[0]->format('H:i'))->toBe('09:00');
    expect($slots[0]->timezone->getName())->toBe('Europe/Zurich');

    // 09:00 CEST = 07:00 UTC
    expect($slots[0]->setTimezone('UTC')->format('H:i'))->toBe('07:00');
});

test('DST spring forward does not produce duplicate or missing slots', function () {
    $slotService = app(SlotGeneratorService::class);

    $business = Business::factory()->create(['timezone' => 'Europe/Zurich']);
    $collab = User::factory()->create();
    $business->users()->attach($collab, ['role' => 'collaborator']);

    // March 30, 2026 = Monday, day after spring forward in Europe/Zurich
    // (DST transition: March 29 02:00 → 03:00)
    // On March 30, timezone is CEST (UTC+2)
    $dstDate = CarbonImmutable::parse('2026-03-30', 'Europe/Zurich');

    BusinessHour::factory()->create([
        'business_id' => $business->id,
        'day_of_week' => DayOfWeek::Monday->value,
        'open_time' => '09:00',
        'close_time' => '18:00',
    ]);
    AvailabilityRule::factory()->create([
        'collaborator_id' => $collab->id,
        'business_id' => $business->id,
        'day_of_week' => DayOfWeek::Monday->value,
        'start_time' => '09:00',
        'end_time' => '18:00',
    ]);

    $service = Service::factory()->create([
        'business_id' => $business->id,
        'duration_minutes' => 60,
        'buffer_before' => 0,
        'buffer_after' => 0,
        'slot_interval_minutes' => 60,
    ]);
    $service->collaborators()->attach($collab);

    $slots = $slotService->getAvailableSlots($business, $service, $dstDate, $collab);

    // Should still be 9 slots: 09:00 through 17:00
    expect($slots)->toHaveCount(9);
    expect($slots[0]->format('H:i'))->toBe('09:00');
    expect($slots[8]->format('H:i'))->toBe('17:00');

    // No duplicate times
    $times = array_map(fn ($s) => $s->format('H:i'), $slots);
    expect($times)->toBe(array_unique($times));

    // Verify UTC offsets are CEST (UTC+2)
    expect($slots[0]->setTimezone('UTC')->format('H:i'))->toBe('07:00');
});
