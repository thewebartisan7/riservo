<?php

use App\Enums\DayOfWeek;
use App\Models\AvailabilityRule;
use App\Models\Business;
use App\Models\BusinessHour;
use App\Models\Service;
use App\Models\User;
use Carbon\CarbonImmutable;

beforeEach(function () {
    $this->business = Business::factory()->onboarded()->create(['timezone' => 'Europe/Zurich']);
    $this->staff = User::factory()->create();
    $this->provider = attachProvider($this->business, $this->staff);

    // Business open Mon-Fri 09:00-18:00
    foreach ([DayOfWeek::Monday, DayOfWeek::Tuesday, DayOfWeek::Wednesday, DayOfWeek::Thursday, DayOfWeek::Friday] as $day) {
        BusinessHour::factory()->create([
            'business_id' => $this->business->id,
            'day_of_week' => $day->value,
            'open_time' => '09:00',
            'close_time' => '18:00',
        ]);

        AvailabilityRule::factory()->create([
            'provider_id' => $this->provider->id,
            'business_id' => $this->business->id,
            'day_of_week' => $day->value,
            'start_time' => '09:00',
            'end_time' => '18:00',
        ]);
    }

    $this->service = Service::factory()->create([
        'business_id' => $this->business->id,
        'is_active' => true,
        'duration_minutes' => 60,
        'buffer_before' => 0,
        'buffer_after' => 0,
        'slot_interval_minutes' => 60,
    ]);
    $this->provider->services()->attach($this->service);
});

test('returns available and unavailable dates for a month', function () {
    // Use a future month to avoid "past dates" filtering
    $this->travelTo(CarbonImmutable::parse('2026-04-01 08:00', 'Europe/Zurich'));

    $response = $this->getJson('/booking/'.$this->business->slug.'/available-dates?service_id='.$this->service->id.'&month=2026-04');

    $response->assertOk()
        ->assertJsonStructure(['dates']);

    $dates = $response->json('dates');

    // Monday 2026-04-06 should be available (weekday with hours)
    expect($dates['2026-04-06'])->toBeTrue();

    // Sunday 2026-04-05 should be unavailable (no business hours)
    expect($dates['2026-04-05'])->toBeFalse();

    // Saturday 2026-04-04 should be unavailable
    expect($dates['2026-04-04'])->toBeFalse();
});

test('past dates are always unavailable', function () {
    $this->travelTo(CarbonImmutable::parse('2026-04-15 08:00', 'Europe/Zurich'));

    $response = $this->getJson('/booking/'.$this->business->slug.'/available-dates?service_id='.$this->service->id.'&month=2026-04');

    $dates = $response->json('dates');

    // April 14 is in the past
    expect($dates['2026-04-14'])->toBeFalse();

    // April 15 (today, a Wednesday) should be available
    expect($dates['2026-04-15'])->toBeTrue();
});

test('respects provider filter', function () {
    $this->travelTo(CarbonImmutable::parse('2026-04-01 08:00', 'Europe/Zurich'));

    // Create a second provider with no availability
    $staff2 = User::factory()->create();
    $provider2 = attachProvider($this->business, $staff2);
    $provider2->services()->attach($this->service);
    // provider2 has no availability rules → no slots

    $response = $this->getJson('/booking/'.$this->business->slug.'/available-dates?service_id='.$this->service->id.'&provider_id='.$provider2->id.'&month=2026-04');

    $dates = $response->json('dates');

    // All dates should be unavailable for provider2
    expect($dates['2026-04-06'])->toBeFalse();
});

test('validates required parameters', function () {
    $this->getJson('/booking/'.$this->business->slug.'/available-dates')
        ->assertStatus(422);

    $this->getJson('/booking/'.$this->business->slug.'/available-dates?service_id='.$this->service->id)
        ->assertStatus(422);
});

test('returns 404 for non-existent business', function () {
    $this->getJson('/booking/non-existent/available-dates?service_id=1&month=2026-04')
        ->assertStatus(404);
});
