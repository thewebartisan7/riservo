<?php

use App\Enums\ExceptionType;
use App\Models\AvailabilityException;
use App\Models\Business;
use App\Models\User;

beforeEach(function () {
    $this->business = Business::factory()->onboarded()->create();
    $this->admin = User::factory()->create();
    attachAdmin($this->business, $this->admin);
});

test('admin can view business exceptions', function () {
    AvailabilityException::factory()->count(2)->create([
        'business_id' => $this->business->id,
        'provider_id' => null,
    ]);

    $this->actingAs($this->admin)
        ->get('/dashboard/settings/exceptions')
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('dashboard/settings/exceptions')
            ->has('exceptions', 2)
        );
});

test('only business-level exceptions are shown', function () {
    $staff = User::factory()->create();
    $provider = attachProvider($this->business, $staff);

    AvailabilityException::factory()->create([
        'business_id' => $this->business->id,
        'provider_id' => null,
    ]);
    AvailabilityException::factory()->create([
        'business_id' => $this->business->id,
        'provider_id' => $provider->id,
    ]);

    $this->actingAs($this->admin)
        ->get('/dashboard/settings/exceptions')
        ->assertInertia(fn ($page) => $page->has('exceptions', 1));
});

test('admin can create a full-day exception', function () {
    $this->actingAs($this->admin)
        ->post('/dashboard/settings/exceptions', [
            'start_date' => '2026-12-25',
            'end_date' => '2026-12-25',
            'start_time' => null,
            'end_time' => null,
            'type' => 'block',
            'reason' => 'Christmas',
        ])
        ->assertRedirect('/dashboard/settings/exceptions');

    expect($this->business->availabilityExceptions()->whereNull('provider_id')->count())->toBe(1);
    $exception = $this->business->availabilityExceptions()->first();
    expect($exception->reason)->toBe('Christmas');
    expect($exception->type)->toBe(ExceptionType::Block);
});

test('admin can create a partial-day exception', function () {
    $this->actingAs($this->admin)
        ->post('/dashboard/settings/exceptions', [
            'start_date' => '2026-06-15',
            'end_date' => '2026-06-15',
            'start_time' => '10:00',
            'end_time' => '12:00',
            'type' => 'open',
            'reason' => 'Special hours',
        ])
        ->assertRedirect('/dashboard/settings/exceptions');

    $exception = $this->business->availabilityExceptions()->first();
    expect($exception->start_time)->toBe('10:00');
    expect($exception->end_time)->toBe('12:00');
    expect($exception->type)->toBe(ExceptionType::Open);
});

test('admin can update an exception', function () {
    $exception = AvailabilityException::factory()->create([
        'business_id' => $this->business->id,
        'provider_id' => null,
        'reason' => 'Old reason',
    ]);

    $this->actingAs($this->admin)
        ->put("/dashboard/settings/exceptions/{$exception->id}", [
            'start_date' => '2026-12-31',
            'end_date' => '2026-12-31',
            'start_time' => null,
            'end_time' => null,
            'type' => 'block',
            'reason' => 'New Year',
        ])
        ->assertRedirect('/dashboard/settings/exceptions');

    expect($exception->fresh()->reason)->toBe('New Year');
});

test('admin can delete an exception', function () {
    $exception = AvailabilityException::factory()->create([
        'business_id' => $this->business->id,
        'provider_id' => null,
    ]);

    $this->actingAs($this->admin)
        ->delete("/dashboard/settings/exceptions/{$exception->id}")
        ->assertRedirect('/dashboard/settings/exceptions');

    expect(AvailabilityException::find($exception->id))->toBeNull();
});

test('cannot modify exception from another business', function () {
    $otherBusiness = Business::factory()->onboarded()->create();
    $exception = AvailabilityException::factory()->create([
        'business_id' => $otherBusiness->id,
        'provider_id' => null,
    ]);

    $this->actingAs($this->admin)
        ->put("/dashboard/settings/exceptions/{$exception->id}", [
            'start_date' => '2026-12-31',
            'end_date' => '2026-12-31',
            'type' => 'block',
        ])
        ->assertForbidden();
});

test('end_date must be after or equal to start_date', function () {
    $this->actingAs($this->admin)
        ->post('/dashboard/settings/exceptions', [
            'start_date' => '2026-12-25',
            'end_date' => '2026-12-24',
            'type' => 'block',
        ])
        ->assertSessionHasErrors('end_date');
});
