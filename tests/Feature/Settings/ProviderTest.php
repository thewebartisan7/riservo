<?php

use App\Models\AvailabilityException;
use App\Models\AvailabilityRule;
use App\Models\Business;
use App\Models\User;

beforeEach(function () {
    $this->business = Business::factory()->onboarded()->create();
    $this->admin = User::factory()->create();
    attachAdmin($this->business, $this->admin);

    $this->staff = User::factory()->create();
    $this->provider = attachProvider($this->business, $this->staff);
});

test('admin can update provider schedule', function () {
    $rules = collect(range(1, 7))->map(fn ($day) => [
        'day_of_week' => $day,
        'enabled' => $day <= 3,
        'windows' => $day <= 3 ? [['open_time' => '09:00', 'close_time' => '17:00']] : [],
    ])->all();

    $this->actingAs($this->admin)
        ->put("/dashboard/settings/providers/{$this->provider->id}/schedule", [
            'rules' => $rules,
        ])
        ->assertRedirect();

    expect(AvailabilityRule::where('provider_id', $this->provider->id)->count())->toBe(3);
});

test('admin can add provider exception', function () {
    $this->actingAs($this->admin)
        ->post("/dashboard/settings/providers/{$this->provider->id}/exceptions", [
            'start_date' => '2026-07-01',
            'end_date' => '2026-07-01',
            'start_time' => null,
            'end_time' => null,
            'type' => 'block',
            'reason' => 'Sick day',
        ])
        ->assertRedirect();

    $exception = AvailabilityException::where('provider_id', $this->provider->id)->first();
    expect($exception)->not->toBeNull();
    expect($exception->reason)->toBe('Sick day');
});

test('admin can delete provider exception', function () {
    $exception = AvailabilityException::factory()->create([
        'business_id' => $this->business->id,
        'provider_id' => $this->provider->id,
    ]);

    $this->actingAs($this->admin)
        ->delete("/dashboard/settings/providers/{$this->provider->id}/exceptions/{$exception->id}")
        ->assertRedirect();

    expect(AvailabilityException::find($exception->id))->toBeNull();
});

test('admin can toggle provider active status', function () {
    expect($this->provider->fresh()->trashed())->toBeFalse();

    $this->actingAs($this->admin)
        ->post("/dashboard/settings/providers/{$this->provider->id}/toggle")
        ->assertRedirect();

    expect($this->provider->fresh()->trashed())->toBeTrue();
});
