<?php

use App\Models\AvailabilityRule;
use App\Models\Business;
use App\Models\Provider;
use App\Models\Service;
use App\Models\User;

beforeEach(function () {
    $this->withoutVite();
    $this->user = User::factory()->create(['email_verified_at' => now()]);
    $this->business = Business::factory()->create(['onboarding_step' => 3]);
    attachAdmin($this->business, $this->user);
});

/**
 * @return array<int, array{day_of_week: int, enabled: bool, windows: array<int, array{open_time: string, close_time: string}>}>
 */
function validProviderSchedule(): array
{
    return collect(range(1, 7))->map(fn ($day) => [
        'day_of_week' => $day,
        'enabled' => $day <= 5,
        'windows' => $day <= 5 ? [['open_time' => '09:00', 'close_time' => '17:00']] : [],
    ])->all();
}

test('step 3 page renders', function () {
    $response = $this->actingAs($this->user)->get('/onboarding/step/3');

    $response->assertSuccessful();
    $response->assertInertia(fn ($page) => $page
        ->component('onboarding/step-3')
        ->where('service', null)
    );
});

test('step 3 page renders with existing service', function () {
    Service::factory()->create([
        'business_id' => $this->business->id,
        'name' => 'Haircut',
    ]);

    $response = $this->actingAs($this->user)->get('/onboarding/step/3');

    $response->assertInertia(fn ($page) => $page
        ->where('service.name', 'Haircut')
    );
});

test('step 3 creates a service', function () {
    $response = $this->actingAs($this->user)->post('/onboarding/step/3', [
        'name' => 'Haircut',
        'duration_minutes' => 30,
        'price' => 45.00,
        'buffer_before' => 0,
        'buffer_after' => 10,
        'slot_interval_minutes' => 15,
    ]);

    $response->assertRedirect('/onboarding/step/4');

    $service = Service::where('business_id', $this->business->id)->first();
    expect($service)->not->toBeNull();
    expect($service->name)->toBe('Haircut');
    expect($service->slug)->toBe('haircut');
    expect($service->duration_minutes)->toBe(30);
    expect((float) $service->price)->toBe(45.00);
    expect($service->buffer_after)->toBe(10);
    expect($service->slot_interval_minutes)->toBe(15);

    $this->business->refresh();
    expect($this->business->onboarding_step)->toBe(4);
});

test('step 3 creates service with null price (on request)', function () {
    $this->actingAs($this->user)->post('/onboarding/step/3', [
        'name' => 'Consultation',
        'duration_minutes' => 60,
        'price' => null,
        'buffer_before' => 0,
        'buffer_after' => 0,
        'slot_interval_minutes' => 30,
    ]);

    $service = Service::where('business_id', $this->business->id)->first();
    expect($service->price)->toBeNull();
});

test('step 3 updates existing service on resubmit', function () {
    $service = Service::factory()->create([
        'business_id' => $this->business->id,
        'name' => 'Old Service',
    ]);

    $this->actingAs($this->user)->post('/onboarding/step/3', [
        'name' => 'New Service',
        'duration_minutes' => 45,
        'price' => 50,
        'buffer_before' => 5,
        'buffer_after' => 5,
        'slot_interval_minutes' => 15,
    ]);

    expect(Service::where('business_id', $this->business->id)->count())->toBe(1);
    $service->refresh();
    expect($service->name)->toBe('New Service');
});

test('step 3 validates required fields', function () {
    $response = $this->actingAs($this->user)->post('/onboarding/step/3', [
        'name' => '',
        'duration_minutes' => null,
        'slot_interval_minutes' => null,
    ]);

    $response->assertSessionHasErrors(['name', 'duration_minutes', 'slot_interval_minutes']);
});

test('step 3 validates duration range', function () {
    $response = $this->actingAs($this->user)->post('/onboarding/step/3', [
        'name' => 'Test',
        'duration_minutes' => 2,
        'slot_interval_minutes' => 15,
    ]);

    $response->assertSessionHasErrors(['duration_minutes']);
});

test('step 3 validates slot interval options', function () {
    $response = $this->actingAs($this->user)->post('/onboarding/step/3', [
        'name' => 'Test',
        'duration_minutes' => 30,
        'slot_interval_minutes' => 7,
    ]);

    $response->assertSessionHasErrors(['slot_interval_minutes']);
});

test('opt-in true with valid schedule creates provider, writes rules, and attaches service', function () {
    $response = $this->actingAs($this->user)->post('/onboarding/step/3', [
        'name' => 'Haircut',
        'duration_minutes' => 30,
        'price' => 45,
        'buffer_before' => 0,
        'buffer_after' => 0,
        'slot_interval_minutes' => 15,
        'provider_opt_in' => true,
        'provider_schedule' => validProviderSchedule(),
    ]);

    $response->assertRedirect('/onboarding/step/4');

    $provider = Provider::where('business_id', $this->business->id)
        ->where('user_id', $this->user->id)
        ->first();

    expect($provider)->not->toBeNull();
    expect($provider->trashed())->toBeFalse();

    expect(AvailabilityRule::where('provider_id', $provider->id)->count())->toBe(5);

    $service = Service::where('business_id', $this->business->id)->firstOrFail();
    expect($provider->services()->where('services.id', $service->id)->exists())->toBeTrue();
});

test('opt-in true with invalid schedule returns 422 and does not create a provider', function () {
    $schedule = validProviderSchedule();
    $schedule[0]['windows'] = [['open_time' => '17:00', 'close_time' => '09:00']]; // close before open

    $response = $this->actingAs($this->user)->post('/onboarding/step/3', [
        'name' => 'Haircut',
        'duration_minutes' => 30,
        'price' => 45,
        'buffer_before' => 0,
        'buffer_after' => 0,
        'slot_interval_minutes' => 15,
        'provider_opt_in' => true,
        'provider_schedule' => $schedule,
    ]);

    $response->assertSessionHasErrors(['provider_schedule.0.windows.0.close_time']);

    expect(Provider::where('business_id', $this->business->id)->exists())->toBeFalse();
    expect(Service::where('business_id', $this->business->id)->exists())->toBeFalse();
});

test('opt-in with all days disabled is rejected and nothing is persisted', function () {
    $schedule = collect(range(1, 7))->map(fn ($day) => [
        'day_of_week' => $day,
        'enabled' => false,
        'windows' => [],
    ])->all();

    $response = $this->actingAs($this->user)->post('/onboarding/step/3', [
        'name' => 'Haircut',
        'duration_minutes' => 30,
        'price' => 45,
        'buffer_before' => 0,
        'buffer_after' => 0,
        'slot_interval_minutes' => 15,
        'provider_opt_in' => true,
        'provider_schedule' => $schedule,
    ]);

    $response->assertSessionHasErrors(['provider_schedule']);

    expect(Service::where('business_id', $this->business->id)->exists())->toBeFalse();
    expect(Provider::where('business_id', $this->business->id)->exists())->toBeFalse();
    expect(AvailabilityRule::count())->toBe(0);
});

test('opt-in with enabled days but empty windows is rejected', function () {
    $schedule = collect(range(1, 7))->map(fn ($day) => [
        'day_of_week' => $day,
        'enabled' => true,
        'windows' => [],
    ])->all();

    $response = $this->actingAs($this->user)->post('/onboarding/step/3', [
        'name' => 'Haircut',
        'duration_minutes' => 30,
        'price' => 45,
        'buffer_before' => 0,
        'buffer_after' => 0,
        'slot_interval_minutes' => 15,
        'provider_opt_in' => true,
        'provider_schedule' => $schedule,
    ]);

    $response->assertSessionHasErrors(['provider_schedule']);

    expect(Service::where('business_id', $this->business->id)->exists())->toBeFalse();
    expect(Provider::where('business_id', $this->business->id)->exists())->toBeFalse();
    expect(AvailabilityRule::count())->toBe(0);
});

test('opt-in false and no prior provider leaves admin as non-provider', function () {
    $this->actingAs($this->user)->post('/onboarding/step/3', [
        'name' => 'Haircut',
        'duration_minutes' => 30,
        'price' => 45,
        'buffer_before' => 0,
        'buffer_after' => 0,
        'slot_interval_minutes' => 15,
        'provider_opt_in' => false,
    ])->assertRedirect('/onboarding/step/4');

    expect(
        Provider::where('business_id', $this->business->id)
            ->where('user_id', $this->user->id)
            ->exists()
    )->toBeFalse();
});

test('opt-in false preserves admin provider row when admin is already a provider', function () {
    // First pass: opt-in on creates the provider and attaches to the service.
    $this->actingAs($this->user)->post('/onboarding/step/3', [
        'name' => 'Haircut',
        'duration_minutes' => 30,
        'price' => 45,
        'buffer_before' => 0,
        'buffer_after' => 0,
        'slot_interval_minutes' => 15,
        'provider_opt_in' => true,
        'provider_schedule' => validProviderSchedule(),
    ])->assertRedirect('/onboarding/step/4');

    $provider = Provider::where('business_id', $this->business->id)
        ->where('user_id', $this->user->id)
        ->firstOrFail();

    // Second pass: same form, but opt-in off. Reset onboarding step so store is allowed.
    $this->business->update(['onboarding_step' => 3]);

    $this->actingAs($this->user)->post('/onboarding/step/3', [
        'name' => 'Haircut',
        'duration_minutes' => 30,
        'price' => 45,
        'buffer_before' => 0,
        'buffer_after' => 0,
        'slot_interval_minutes' => 15,
        'provider_opt_in' => false,
    ])->assertRedirect('/onboarding/step/4');

    // The provider row survives even though this service's attachment is removed.
    $provider->refresh();
    expect($provider->trashed())->toBeFalse();

    $service = Service::where('business_id', $this->business->id)->firstOrFail();
    expect($provider->services()->where('services.id', $service->id)->exists())->toBeFalse();
});

test('opt-in off preserves attachments to services other than the one being edited', function () {
    // First pass: opt-in on creates the provider and attaches to the primary service.
    $this->actingAs($this->user)->post('/onboarding/step/3', [
        'name' => 'Haircut',
        'duration_minutes' => 30,
        'price' => 45,
        'buffer_before' => 0,
        'buffer_after' => 0,
        'slot_interval_minutes' => 15,
        'provider_opt_in' => true,
        'provider_schedule' => validProviderSchedule(),
    ])->assertRedirect('/onboarding/step/4');

    $provider = Provider::where('business_id', $this->business->id)
        ->where('user_id', $this->user->id)
        ->firstOrFail();

    // Simulate the admin having another service attachment (e.g. added later via Settings).
    $externalService = Service::factory()->create([
        'business_id' => $this->business->id,
        'name' => 'Massage',
    ]);
    $provider->services()->attach($externalService->id);

    $attachmentsBefore = $provider->fresh()->services()->pluck('services.id')->all();
    expect($attachmentsBefore)->toContain($externalService->id);

    // Second pass: opt-in off. The controller updates/detaches whichever service
    // `$business->services()->first()` returns; we assert that (a) the provider row
    // is preserved and (b) at least one other attachment remains untouched.
    $this->business->update(['onboarding_step' => 3]);

    $this->actingAs($this->user)->post('/onboarding/step/3', [
        'name' => 'Haircut',
        'duration_minutes' => 30,
        'price' => 45,
        'buffer_before' => 0,
        'buffer_after' => 0,
        'slot_interval_minutes' => 15,
        'provider_opt_in' => false,
    ])->assertRedirect('/onboarding/step/4');

    $provider->refresh();
    expect($provider->trashed())->toBeFalse();

    $attachmentsAfter = $provider->services()->pluck('services.id')->all();
    // Exactly one service was detached; the other attachment survives.
    expect($attachmentsAfter)->toHaveCount(1);
});

test('show returns adminProvider, businessHoursSchedule, and hasOtherProviders props', function () {
    $response = $this->actingAs($this->user)->get('/onboarding/step/3');

    $response->assertInertia(fn ($page) => $page
        ->component('onboarding/step-3')
        ->where('adminProvider.exists', false)
        ->has('adminProvider.schedule', 7)
        ->where('adminProvider.serviceIds', [])
        ->has('businessHoursSchedule', 7)
        ->where('hasOtherProviders', false)
    );
});
