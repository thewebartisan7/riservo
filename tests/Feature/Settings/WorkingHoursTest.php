<?php

use App\Models\Business;
use App\Models\BusinessHour;
use App\Models\User;

beforeEach(function () {
    $this->business = Business::factory()->onboarded()->create();
    $this->admin = User::factory()->create();
    attachAdmin($this->business, $this->admin);
});

test('admin can view working hours', function () {
    $this->actingAs($this->admin)
        ->get('/dashboard/settings/hours')
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('dashboard/settings/hours')
            ->has('hours', 7)
        );
});

test('admin can update working hours', function () {
    $hours = collect(range(1, 7))->map(fn ($day) => [
        'day_of_week' => $day,
        'enabled' => $day <= 5,
        'windows' => $day <= 5 ? [['open_time' => '09:00', 'close_time' => '17:00']] : [],
    ])->all();

    $this->actingAs($this->admin)
        ->put('/dashboard/settings/hours', ['hours' => $hours])
        ->assertRedirect('/dashboard/settings/hours');

    expect($this->business->businessHours()->count())->toBe(5);
});

test('update replaces all existing hours', function () {
    BusinessHour::factory()->count(3)->create(['business_id' => $this->business->id]);

    $hours = collect(range(1, 7))->map(fn ($day) => [
        'day_of_week' => $day,
        'enabled' => $day === 1,
        'windows' => $day === 1 ? [['open_time' => '10:00', 'close_time' => '14:00']] : [],
    ])->all();

    $this->actingAs($this->admin)
        ->put('/dashboard/settings/hours', ['hours' => $hours])
        ->assertRedirect('/dashboard/settings/hours');

    expect($this->business->businessHours()->count())->toBe(1);
    $hour = $this->business->businessHours()->first();
    expect($hour->open_time)->toBe('10:00:00');
    expect($hour->close_time)->toBe('14:00:00');
});

test('closing time must be after opening time', function () {
    $hours = collect(range(1, 7))->map(fn ($day) => [
        'day_of_week' => $day,
        'enabled' => $day === 1,
        'windows' => $day === 1 ? [['open_time' => '18:00', 'close_time' => '09:00']] : [],
    ])->all();

    $this->actingAs($this->admin)
        ->put('/dashboard/settings/hours', ['hours' => $hours])
        ->assertSessionHasErrors();
});
