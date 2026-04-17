<?php

use App\Models\AvailabilityRule;
use App\Models\Booking;
use App\Models\Business;
use App\Models\BusinessHour;
use App\Models\Customer;
use App\Models\Provider;
use App\Models\Service;
use App\Models\User;
use App\Services\SlotGeneratorService;
use Carbon\CarbonImmutable;

/**
 * Covers the `$excluding` parameter added to
 * SlotGeneratorService::getAvailableSlots in MVPC-5. The reschedule flow
 * (D-105) uses this to prevent a booking from blocking its own move —
 * matches D-066's "the booking being rescheduled is the one we're freeing
 * up".
 */
beforeEach(function () {
    $this->business = Business::factory()->onboarded()->create([
        'timezone' => 'Europe/Zurich',
    ]);

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

it('returns the booking own slot as available when excluding that booking', function () {
    $date = CarbonImmutable::create(2026, 5, 4, 0, 0, 0, 'Europe/Zurich');

    $booking = Booking::factory()->confirmed()->create([
        'business_id' => $this->business->id,
        'provider_id' => $this->provider->id,
        'service_id' => $this->service->id,
        'customer_id' => Customer::factory()->create()->id,
        'starts_at' => $date->setTime(10, 0)->utc(),
        'ends_at' => $date->setTime(11, 0)->utc(),
    ]);

    $generator = app(SlotGeneratorService::class);

    // Without excluding: 10:00 is blocked by the booking itself.
    $slotsWithoutExcluding = $generator->getAvailableSlots(
        $this->business,
        $this->service,
        $date,
        $this->provider,
    );
    expect(collect($slotsWithoutExcluding)->contains(
        fn (CarbonImmutable $s) => $s->format('H:i') === '10:00',
    ))->toBeFalse();

    // With excluding: 10:00 should be back in the candidate set.
    $slotsWithExcluding = $generator->getAvailableSlots(
        $this->business,
        $this->service,
        $date,
        $this->provider,
        excluding: $booking,
    );
    expect(collect($slotsWithExcluding)->contains(
        fn (CarbonImmutable $s) => $s->format('H:i') === '10:00',
    ))->toBeTrue();
});

it('still blocks slots occupied by other bookings when excluding one', function () {
    $date = CarbonImmutable::create(2026, 5, 4, 0, 0, 0, 'Europe/Zurich');

    $movedBooking = Booking::factory()->confirmed()->create([
        'business_id' => $this->business->id,
        'provider_id' => $this->provider->id,
        'service_id' => $this->service->id,
        'customer_id' => Customer::factory()->create()->id,
        'starts_at' => $date->setTime(10, 0)->utc(),
        'ends_at' => $date->setTime(11, 0)->utc(),
    ]);

    // A different, untouched booking at 14:00 – 15:00.
    Booking::factory()->confirmed()->create([
        'business_id' => $this->business->id,
        'provider_id' => $this->provider->id,
        'service_id' => $this->service->id,
        'customer_id' => Customer::factory()->create()->id,
        'starts_at' => $date->setTime(14, 0)->utc(),
        'ends_at' => $date->setTime(15, 0)->utc(),
    ]);

    $generator = app(SlotGeneratorService::class);

    $slots = $generator->getAvailableSlots(
        $this->business,
        $this->service,
        $date,
        $this->provider,
        excluding: $movedBooking,
    );

    expect(collect($slots)->contains(
        fn (CarbonImmutable $s) => $s->format('H:i') === '10:00',
    ))->toBeTrue();
    expect(collect($slots)->contains(
        fn (CarbonImmutable $s) => $s->format('H:i') === '14:00',
    ))->toBeFalse();
});
