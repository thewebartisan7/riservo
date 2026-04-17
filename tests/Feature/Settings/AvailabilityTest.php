<?php

use App\Models\AvailabilityException;
use App\Models\AvailabilityRule;
use App\Models\Business;
use App\Models\Service;
use App\Models\User;

beforeEach(function () {
    $this->withoutVite();
    $this->business = Business::factory()->onboarded()->create();

    $this->admin = User::factory()->create(['email_verified_at' => now()]);
    attachAdmin($this->business, $this->admin);
    $this->adminProvider = attachProvider($this->business, $this->admin);

    $this->staff = User::factory()->create(['email_verified_at' => now()]);
    attachStaff($this->business, $this->staff);
    $this->staffProvider = attachProvider($this->business, $this->staff);

    $this->staffNoProvider = User::factory()->create(['email_verified_at' => now()]);
    attachStaff($this->business, $this->staffNoProvider);
});

function validRules(): array
{
    return collect(range(1, 7))->map(fn ($day) => [
        'day_of_week' => $day,
        'enabled' => $day <= 5,
        'windows' => $day <= 5 ? [['open_time' => '09:00', 'close_time' => '17:00']] : [],
    ])->all();
}

test('admin (self-provider) can view availability page', function () {
    $service = Service::factory()->create([
        'business_id' => $this->business->id,
        'name' => 'Haircut',
    ]);
    $this->adminProvider->services()->attach($service->id);

    AvailabilityRule::create([
        'provider_id' => $this->adminProvider->id,
        'business_id' => $this->business->id,
        'day_of_week' => 1,
        'start_time' => '09:00',
        'end_time' => '17:00',
    ]);

    $this->actingAs($this->admin)
        ->get('/dashboard/settings/availability')
        ->assertSuccessful()
        ->assertInertia(fn ($page) => $page
            ->component('dashboard/settings/availability')
            ->where('canEditServices', true)
            ->where('schedule.0.enabled', true)
            ->where('schedule.0.windows.0.open_time', '09:00')
            ->where('services.0.id', $service->id)
            ->where('services.0.assigned', true)
        );
});

test('staff (self-provider) can view availability page with canEditServices=false', function () {
    $service = Service::factory()->create([
        'business_id' => $this->business->id,
        'name' => 'Trim',
    ]);
    $this->staffProvider->services()->attach($service->id);

    $this->actingAs($this->staff)
        ->get('/dashboard/settings/availability')
        ->assertSuccessful()
        ->assertInertia(fn ($page) => $page
            ->where('canEditServices', false)
            ->where('services.0.assigned', true)
        );
});

test('staff without an active provider row gets 404 on GET', function () {
    $this->actingAs($this->staffNoProvider)
        ->get('/dashboard/settings/availability')
        ->assertNotFound();
});

test('admin can update their schedule', function () {
    $this->actingAs($this->admin)
        ->put('/dashboard/settings/availability/schedule', ['rules' => validRules()])
        ->assertRedirect('/dashboard/settings/availability');

    expect(AvailabilityRule::where('provider_id', $this->adminProvider->id)->count())->toBe(5);
});

test('staff can update their own schedule', function () {
    $this->actingAs($this->staff)
        ->put('/dashboard/settings/availability/schedule', ['rules' => validRules()])
        ->assertRedirect('/dashboard/settings/availability');

    expect(AvailabilityRule::where('provider_id', $this->staffProvider->id)->count())->toBe(5);
});

test('staff without an active provider row 404s on schedule update', function () {
    $this->actingAs($this->staffNoProvider)
        ->put('/dashboard/settings/availability/schedule', ['rules' => validRules()])
        ->assertNotFound();
});

test('staff can store, update, and destroy their own exception', function () {
    $this->actingAs($this->staff)
        ->post('/dashboard/settings/availability/exceptions', [
            'start_date' => '2026-06-01',
            'end_date' => '2026-06-01',
            'start_time' => null,
            'end_time' => null,
            'type' => 'block',
            'reason' => 'Doctor appointment',
        ])
        ->assertRedirect('/dashboard/settings/availability');

    $exception = AvailabilityException::where('provider_id', $this->staffProvider->id)->firstOrFail();

    $this->actingAs($this->staff)
        ->put("/dashboard/settings/availability/exceptions/{$exception->id}", [
            'start_date' => $exception->start_date->format('Y-m-d'),
            'end_date' => $exception->end_date->format('Y-m-d'),
            'start_time' => null,
            'end_time' => null,
            'type' => 'block',
            'reason' => 'Updated reason',
        ])
        ->assertRedirect('/dashboard/settings/availability');

    expect($exception->fresh()->reason)->toBe('Updated reason');

    $this->actingAs($this->staff)
        ->delete("/dashboard/settings/availability/exceptions/{$exception->id}")
        ->assertRedirect('/dashboard/settings/availability');

    expect(AvailabilityException::find($exception->id))->toBeNull();
});

test('cannot update or destroy an exception belonging to another provider in the same business', function () {
    $exception = AvailabilityException::factory()->create([
        'business_id' => $this->business->id,
        'provider_id' => $this->adminProvider->id,
    ]);

    $this->actingAs($this->staff)
        ->put("/dashboard/settings/availability/exceptions/{$exception->id}", [
            'start_date' => $exception->start_date->format('Y-m-d'),
            'end_date' => $exception->end_date->format('Y-m-d'),
            'start_time' => null,
            'end_time' => null,
            'type' => 'block',
            'reason' => 'Hijack',
        ])
        ->assertForbidden();

    $this->actingAs($this->staff)
        ->delete("/dashboard/settings/availability/exceptions/{$exception->id}")
        ->assertForbidden();

    expect(AvailabilityException::find($exception->id))->not->toBeNull();
});

test('cannot edit an exception in another business via tenant pinning', function () {
    $otherBusiness = Business::factory()->onboarded()->create();
    $otherUser = User::factory()->create();
    $otherProvider = attachProvider($otherBusiness, $otherUser);

    $foreignException = AvailabilityException::factory()->create([
        'business_id' => $otherBusiness->id,
        'provider_id' => $otherProvider->id,
    ]);

    $this->actingAs($this->staff)
        ->delete("/dashboard/settings/availability/exceptions/{$foreignException->id}")
        ->assertForbidden();

    expect(AvailabilityException::find($foreignException->id))->not->toBeNull();
});

test('admin (self-provider) can update services they perform', function () {
    $a = Service::factory()->create(['business_id' => $this->business->id]);
    $b = Service::factory()->create(['business_id' => $this->business->id]);
    $this->adminProvider->services()->attach($a->id);

    $this->actingAs($this->admin)
        ->put('/dashboard/settings/availability/services', ['service_ids' => [$b->id]])
        ->assertRedirect('/dashboard/settings/availability');

    $this->adminProvider->refresh();
    expect($this->adminProvider->services()->where('services.id', $a->id)->exists())->toBeFalse();
    expect($this->adminProvider->services()->where('services.id', $b->id)->exists())->toBeTrue();
});

test('staff cannot update services they perform (admin-only carve-out)', function () {
    $service = Service::factory()->create(['business_id' => $this->business->id]);

    $this->actingAs($this->staff)
        ->put('/dashboard/settings/availability/services', ['service_ids' => [$service->id]])
        ->assertForbidden();
});

test('admin services update rejects ids belonging to another business', function () {
    $otherBusiness = Business::factory()->create();
    $foreignService = Service::factory()->create(['business_id' => $otherBusiness->id]);

    $this->actingAs($this->admin)
        ->put('/dashboard/settings/availability/services', ['service_ids' => [$foreignService->id]])
        ->assertSessionHasErrors('service_ids.0');
});

test('schedule store rejects an invalid window', function () {
    $bad = collect(range(1, 7))->map(fn ($day) => [
        'day_of_week' => $day,
        'enabled' => true,
        'windows' => [['open_time' => '17:00', 'close_time' => '09:00']],
    ])->all();

    $this->actingAs($this->staff)
        ->put('/dashboard/settings/availability/schedule', ['rules' => $bad])
        ->assertSessionHasErrors();
});
