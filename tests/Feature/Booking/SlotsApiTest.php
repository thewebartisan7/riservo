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
    $this->collaborator = User::factory()->create();
    $this->business->users()->attach($this->collaborator, ['role' => 'collaborator']);

    // Monday 09:00-18:00
    BusinessHour::factory()->create([
        'business_id' => $this->business->id,
        'day_of_week' => DayOfWeek::Monday->value,
        'open_time' => '09:00',
        'close_time' => '18:00',
    ]);

    AvailabilityRule::factory()->create([
        'collaborator_id' => $this->collaborator->id,
        'business_id' => $this->business->id,
        'day_of_week' => DayOfWeek::Monday->value,
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
    $this->service->collaborators()->attach($this->collaborator);
});

test('returns available slots for a date', function () {
    $this->travelTo(CarbonImmutable::parse('2026-04-13 08:00', 'Europe/Zurich'));

    $response = $this->getJson('/booking/'.$this->business->slug.'/slots?service_id='.$this->service->id.'&date=2026-04-13');

    $response->assertOk()
        ->assertJsonStructure(['slots', 'timezone'])
        ->assertJsonPath('timezone', 'Europe/Zurich');

    $slots = $response->json('slots');
    expect($slots)->toContain('09:00')
        ->toContain('17:00')
        ->not->toContain('18:00');
});

test('returns empty slots for a day with no availability', function () {
    $this->travelTo(CarbonImmutable::parse('2026-04-12 08:00', 'Europe/Zurich'));

    // April 12 is Sunday — no business hours
    $response = $this->getJson('/booking/'.$this->business->slug.'/slots?service_id='.$this->service->id.'&date=2026-04-12');

    $response->assertOk();
    expect($response->json('slots'))->toBeEmpty();
});

test('returns empty slots for a past date', function () {
    $this->travelTo(CarbonImmutable::parse('2026-04-14 08:00', 'Europe/Zurich'));

    $response = $this->getJson('/booking/'.$this->business->slug.'/slots?service_id='.$this->service->id.'&date=2026-04-13');

    $response->assertOk();
    expect($response->json('slots'))->toBeEmpty();
});

test('respects collaborator filter', function () {
    $this->travelTo(CarbonImmutable::parse('2026-04-13 08:00', 'Europe/Zurich'));

    // collab2 has no rules for Monday
    $collab2 = User::factory()->create();
    $this->business->users()->attach($collab2, ['role' => 'collaborator']);
    $this->service->collaborators()->attach($collab2);

    $response = $this->getJson('/booking/'.$this->business->slug.'/slots?service_id='.$this->service->id.'&date=2026-04-13&collaborator_id='.$collab2->id);

    $response->assertOk();
    expect($response->json('slots'))->toBeEmpty();
});

test('validates required parameters', function () {
    $this->getJson('/booking/'.$this->business->slug.'/slots')
        ->assertStatus(422);

    $this->getJson('/booking/'.$this->business->slug.'/slots?service_id='.$this->service->id)
        ->assertStatus(422);
});
