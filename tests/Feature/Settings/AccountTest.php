<?php

use App\Models\AvailabilityException;
use App\Models\AvailabilityRule;
use App\Models\Business;
use App\Models\Provider;
use App\Models\Service;
use App\Models\User;

beforeEach(function () {
    $this->withoutVite();
    $this->business = Business::factory()->onboarded()->create();
    $this->admin = User::factory()->create(['email_verified_at' => now()]);
    attachAdmin($this->business, $this->admin);
});

function validScheduleRules(): array
{
    return collect(range(1, 7))->map(fn ($day) => [
        'day_of_week' => $day,
        'enabled' => $day <= 5,
        'windows' => $day <= 5 ? [['open_time' => '09:00', 'close_time' => '17:00']] : [],
    ])->all();
}

test('admin can view account page', function () {
    $response = $this->actingAs($this->admin)->get('/dashboard/settings/account');

    $response->assertSuccessful();
    $response->assertInertia(fn ($page) => $page
        ->component('dashboard/settings/account')
        ->where('isProvider', false)
        ->where('hasProviderRow', false)
        ->has('schedule', 7)
        ->has('services')
        ->where('user.name', $this->admin->name)
        ->where('user.email', $this->admin->email)
    );
});

test('admin who is a provider sees isProvider = true with their schedule and services', function () {
    $provider = attachProvider($this->business, $this->admin);
    $service = Service::factory()->create([
        'business_id' => $this->business->id,
        'name' => 'Haircut',
    ]);
    $provider->services()->attach($service->id);
    AvailabilityRule::create([
        'provider_id' => $provider->id,
        'business_id' => $this->business->id,
        'day_of_week' => 1,
        'start_time' => '09:00',
        'end_time' => '17:00',
    ]);

    $response = $this->actingAs($this->admin)->get('/dashboard/settings/account');

    $response->assertInertia(fn ($page) => $page
        ->where('isProvider', true)
        ->where('hasProviderRow', true)
        ->where('schedule.0.enabled', true)
        ->where('schedule.0.windows.0.open_time', '09:00')
        ->where('services.0.id', $service->id)
        ->where('services.0.assigned', true)
    );
});

test('staff cannot access account page', function () {
    $staff = User::factory()->create();
    attachStaff($this->business, $staff);

    $this->actingAs($staff)->get('/dashboard/settings/account')->assertForbidden();
});

test('toggle from off to on creates provider and attaches active services', function () {
    Service::factory()->create(['business_id' => $this->business->id, 'is_active' => true]);
    Service::factory()->create(['business_id' => $this->business->id, 'is_active' => true]);
    Service::factory()->inactive()->create(['business_id' => $this->business->id]);

    $this->actingAs($this->admin)
        ->post('/dashboard/settings/account/toggle-provider')
        ->assertRedirect('/dashboard/settings/account');

    $provider = Provider::where('business_id', $this->business->id)
        ->where('user_id', $this->admin->id)
        ->firstOrFail();

    expect($provider->trashed())->toBeFalse();
    expect($provider->services()->count())->toBe(2);
});

test('toggle from on to off soft-deletes the provider', function () {
    $provider = attachProvider($this->business, $this->admin);

    $this->actingAs($this->admin)
        ->post('/dashboard/settings/account/toggle-provider')
        ->assertRedirect('/dashboard/settings/account');

    $provider->refresh();
    expect($provider->trashed())->toBeTrue();
});

test('toggle off warns when leaving an active service without a provider', function () {
    $provider = attachProvider($this->business, $this->admin);
    $service = Service::factory()->create([
        'business_id' => $this->business->id,
        'is_active' => true,
    ]);
    $provider->services()->attach($service->id);

    $this->actingAs($this->admin)
        ->post('/dashboard/settings/account/toggle-provider')
        ->assertRedirect('/dashboard/settings/account')
        ->assertSessionHas('warning');
});

test('toggle restores a soft-deleted provider with prior attachments', function () {
    $provider = attachProvider($this->business, $this->admin);
    $service = Service::factory()->create(['business_id' => $this->business->id]);
    $provider->services()->attach($service->id);
    $provider->delete();

    $this->actingAs($this->admin)
        ->post('/dashboard/settings/account/toggle-provider')
        ->assertRedirect('/dashboard/settings/account');

    $provider->refresh();
    expect($provider->trashed())->toBeFalse();
    expect($provider->services()->where('services.id', $service->id)->exists())->toBeTrue();
});

test('admin can update their schedule', function () {
    attachProvider($this->business, $this->admin);

    $this->actingAs($this->admin)
        ->put('/dashboard/settings/account/schedule', ['rules' => validScheduleRules()])
        ->assertRedirect('/dashboard/settings/account');

    $provider = Provider::where('business_id', $this->business->id)
        ->where('user_id', $this->admin->id)
        ->firstOrFail();
    expect(AvailabilityRule::where('provider_id', $provider->id)->count())->toBe(5);
});

test('schedule update fails when admin is not a provider', function () {
    $this->actingAs($this->admin)
        ->put('/dashboard/settings/account/schedule', ['rules' => validScheduleRules()])
        ->assertStatus(409);
});

test('admin can store an exception', function () {
    attachProvider($this->business, $this->admin);

    $this->actingAs($this->admin)
        ->post('/dashboard/settings/account/exceptions', [
            'start_date' => '2026-06-01',
            'end_date' => '2026-06-01',
            'start_time' => null,
            'end_time' => null,
            'type' => 'block',
            'reason' => 'Vacation',
        ])
        ->assertRedirect('/dashboard/settings/account');

    $provider = Provider::where('business_id', $this->business->id)
        ->where('user_id', $this->admin->id)
        ->firstOrFail();
    expect(AvailabilityException::where('provider_id', $provider->id)->count())->toBe(1);
});

test('admin can update their exception', function () {
    $provider = attachProvider($this->business, $this->admin);
    $exception = AvailabilityException::factory()->create([
        'business_id' => $this->business->id,
        'provider_id' => $provider->id,
        'reason' => 'Old',
    ]);

    $this->actingAs($this->admin)
        ->put("/dashboard/settings/account/exceptions/{$exception->id}", [
            'start_date' => $exception->start_date->format('Y-m-d'),
            'end_date' => $exception->end_date->format('Y-m-d'),
            'start_time' => null,
            'end_time' => null,
            'type' => $exception->type->value,
            'reason' => 'Updated',
        ])
        ->assertRedirect('/dashboard/settings/account');

    expect($exception->fresh()->reason)->toBe('Updated');
});

test('admin cannot touch exceptions belonging to another provider', function () {
    attachProvider($this->business, $this->admin);

    $otherBusiness = Business::factory()->onboarded()->create();
    $otherUser = User::factory()->create();
    $otherProvider = attachProvider($otherBusiness, $otherUser);

    $exception = AvailabilityException::factory()->create([
        'business_id' => $otherBusiness->id,
        'provider_id' => $otherProvider->id,
    ]);

    $this->actingAs($this->admin)
        ->delete("/dashboard/settings/account/exceptions/{$exception->id}")
        ->assertForbidden();

    expect(AvailabilityException::find($exception->id))->not->toBeNull();
});

test('admin can destroy their exception', function () {
    $provider = attachProvider($this->business, $this->admin);
    $exception = AvailabilityException::factory()->create([
        'business_id' => $this->business->id,
        'provider_id' => $provider->id,
    ]);

    $this->actingAs($this->admin)
        ->delete("/dashboard/settings/account/exceptions/{$exception->id}")
        ->assertRedirect('/dashboard/settings/account');

    expect(AvailabilityException::find($exception->id))->toBeNull();
});

test('admin can update their service assignments', function () {
    $provider = attachProvider($this->business, $this->admin);
    $a = Service::factory()->create(['business_id' => $this->business->id]);
    $b = Service::factory()->create(['business_id' => $this->business->id]);
    $provider->services()->attach($a->id);

    $this->actingAs($this->admin)
        ->put('/dashboard/settings/account/services', ['service_ids' => [$b->id]])
        ->assertRedirect('/dashboard/settings/account');

    $provider->refresh();
    expect($provider->services()->where('services.id', $a->id)->exists())->toBeFalse();
    expect($provider->services()->where('services.id', $b->id)->exists())->toBeTrue();
});

test('service update rejects ids belonging to another business', function () {
    attachProvider($this->business, $this->admin);

    $otherBusiness = Business::factory()->create();
    $foreignService = Service::factory()->create(['business_id' => $otherBusiness->id]);

    $this->actingAs($this->admin)
        ->put('/dashboard/settings/account/services', ['service_ids' => [$foreignService->id]])
        ->assertSessionHasErrors('service_ids.0');
});
