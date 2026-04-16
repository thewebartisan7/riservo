<?php

use App\Models\Business;
use App\Models\BusinessHour;
use App\Models\User;

beforeEach(function () {
    $this->withoutVite();
    $this->user = User::factory()->create(['email_verified_at' => now()]);
    $this->business = Business::factory()->create(['onboarding_step' => 2]);
    attachAdmin($this->business, $this->user);
});

test('step 2 page renders with default hours', function () {
    $response = $this->actingAs($this->user)->get('/onboarding/step/2');

    $response->assertSuccessful();
    $response->assertInertia(fn ($page) => $page
        ->component('onboarding/step-2')
        ->has('hours', 7)
    );
});

test('step 2 page renders with existing hours', function () {
    BusinessHour::factory()->create([
        'business_id' => $this->business->id,
        'day_of_week' => 1,
        'open_time' => '08:00',
        'close_time' => '12:00',
    ]);

    $response = $this->actingAs($this->user)->get('/onboarding/step/2');

    $response->assertSuccessful();
    $response->assertInertia(fn ($page) => $page
        ->component('onboarding/step-2')
        ->has('hours', 7)
    );
});

test('step 2 saves working hours', function () {
    $hours = collect(range(1, 7))->map(fn ($day) => [
        'day_of_week' => $day,
        'enabled' => $day <= 5,
        'windows' => $day <= 5 ? [['open_time' => '09:00', 'close_time' => '17:00']] : [],
    ])->all();

    $response = $this->actingAs($this->user)->postJson('/onboarding/step/2', [
        'hours' => $hours,
    ]);

    $response->assertRedirect('/onboarding/step/3');

    expect(BusinessHour::where('business_id', $this->business->id)->count())->toBe(5);
    $this->business->refresh();
    expect($this->business->onboarding_step)->toBe(3);
});

test('step 2 saves multiple windows per day', function () {
    $hours = collect(range(1, 7))->map(fn ($day) => [
        'day_of_week' => $day,
        'enabled' => $day === 1,
        'windows' => $day === 1
            ? [
                ['open_time' => '09:00', 'close_time' => '12:00'],
                ['open_time' => '14:00', 'close_time' => '18:00'],
            ]
            : [],
    ])->all();

    $this->actingAs($this->user)->postJson('/onboarding/step/2', [
        'hours' => $hours,
    ]);

    expect(BusinessHour::where('business_id', $this->business->id)->count())->toBe(2);
    expect(BusinessHour::where('business_id', $this->business->id)
        ->where('day_of_week', 1)->count())->toBe(2);
});

test('step 2 replaces existing hours on resubmit', function () {
    BusinessHour::factory()->create([
        'business_id' => $this->business->id,
        'day_of_week' => 1,
        'open_time' => '08:00',
        'close_time' => '12:00',
    ]);

    $hours = collect(range(1, 7))->map(fn ($day) => [
        'day_of_week' => $day,
        'enabled' => $day === 1,
        'windows' => $day === 1 ? [['open_time' => '10:00', 'close_time' => '16:00']] : [],
    ])->all();

    $this->actingAs($this->user)->postJson('/onboarding/step/2', [
        'hours' => $hours,
    ]);

    expect(BusinessHour::where('business_id', $this->business->id)->count())->toBe(1);
    expect(BusinessHour::first()->open_time)->toBe('10:00');
});
