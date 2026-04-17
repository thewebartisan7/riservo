<?php

use App\Enums\BookingStatus;
use App\Models\AvailabilityRule;
use App\Models\Booking;
use App\Models\Business;
use App\Models\BusinessHour;
use App\Models\Provider;
use App\Models\Service;
use App\Models\User;
use App\Services\SlotGeneratorService;
use Carbon\CarbonImmutable;

beforeEach(function () {
    $this->business = Business::factory()->onboarded()->create([
        'timezone' => 'Europe/Zurich',
    ]);

    // Business open 09:00 – 17:00 every day.
    foreach (range(1, 7) as $day) {
        BusinessHour::factory()->create([
            'business_id' => $this->business->id,
            'day_of_week' => $day,
            'open_time' => '09:00',
            'close_time' => '17:00',
        ]);
    }

    $this->user = User::factory()->create();
    $this->provider = Provider::factory()->create([
        'business_id' => $this->business->id,
        'user_id' => $this->user->id,
    ]);

    // Provider available 09:00 – 17:00 every day.
    foreach (range(1, 7) as $day) {
        AvailabilityRule::factory()->create([
            'provider_id' => $this->provider->id,
            'business_id' => $this->business->id,
            'day_of_week' => $day,
            'start_time' => '09:00',
            'end_time' => '17:00',
        ]);
    }

    $this->service = Service::factory()->create([
        'business_id' => $this->business->id,
        'duration_minutes' => 60,
        'slot_interval_minutes' => 60,
        'buffer_before' => 0,
        'buffer_after' => 0,
    ]);
    $this->service->providers()->attach($this->provider->id);
});

it('an external booking with null service blocks its time window without crashing', function () {
    // Pick a date explicitly in the business TZ to avoid DST weirdness.
    $date = CarbonImmutable::create(2026, 5, 4, 0, 0, 0, 'Europe/Zurich');

    // External booking occupying 10:00 – 11:00 local time, stored UTC.
    Booking::factory()->external()->create([
        'business_id' => $this->business->id,
        'provider_id' => $this->provider->id,
        'customer_id' => null,
        'service_id' => null,
        'starts_at' => $date->setTime(10, 0)->utc(),
        'ends_at' => $date->setTime(11, 0)->utc(),
        'buffer_before_minutes' => 0,
        'buffer_after_minutes' => 0,
        'status' => BookingStatus::Confirmed,
    ]);

    $generator = app(SlotGeneratorService::class);

    $slots = $generator->getAvailableSlots(
        $this->business,
        $this->service,
        $date,
        $this->provider,
    );

    // Slots expected: 09, 11, 12, 13, 14, 15, 16. 10:00 is blocked.
    $slotStrings = array_map(fn (CarbonImmutable $s) => $s->format('H:i'), $slots);
    expect($slotStrings)->not->toContain('10:00');
    expect($slotStrings)->toContain('09:00');
    expect($slotStrings)->toContain('11:00');
});
