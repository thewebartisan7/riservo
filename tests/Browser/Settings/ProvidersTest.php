<?php

declare(strict_types=1);

use App\Models\AvailabilityException;
use App\Models\AvailabilityRule;
use App\Models\User;
use Tests\Browser\Support\BusinessSetup;

// Covers: settings.providers.toggle, settings.providers.update-schedule,
// settings.providers.sync-services, settings.providers.store-exception,
// settings.providers.update-exception, settings.providers.destroy-exception (E2E-5).

it('toggles a provider off (soft-deletes) via the toggle endpoint', function () {
    ['business' => $business, 'admin' => $admin] = BusinessSetup::createLaunchedBusiness();
    $staffUser = User::factory()->create();
    $provider = attachProvider($business, $staffUser);

    $this->actingAs($admin)
        ->post("/dashboard/settings/providers/{$provider->id}/toggle")
        ->assertRedirect();

    expect($provider->fresh()->trashed())->toBeTrue();
});

it('toggles a soft-deleted provider back on (restore)', function () {
    ['business' => $business, 'admin' => $admin] = BusinessSetup::createLaunchedBusiness();
    $staffUser = User::factory()->create();
    $provider = attachProvider($business, $staffUser);
    $provider->delete();

    $this->actingAs($admin)
        ->post("/dashboard/settings/providers/{$provider->id}/toggle")
        ->assertRedirect();

    expect($provider->fresh()->trashed())->toBeFalse();
});

it('updates a provider weekly schedule (AvailabilityRule rows reflect the change)', function () {
    ['business' => $business, 'admin' => $admin] = BusinessSetup::createLaunchedBusiness();
    $staffUser = User::factory()->create();
    $provider = attachProvider($business, $staffUser);

    $rules = collect(range(1, 7))->map(function (int $day) {
        if ($day >= 1 && $day <= 3) {
            return [
                'day_of_week' => $day,
                'enabled' => true,
                'windows' => [['open_time' => '10:00', 'close_time' => '17:00']],
            ];
        }

        return ['day_of_week' => $day, 'enabled' => false, 'windows' => []];
    })->all();

    $this->actingAs($admin)
        ->put("/dashboard/settings/providers/{$provider->id}/schedule", ['rules' => $rules])
        ->assertRedirect();

    $ruleRows = AvailabilityRule::where('provider_id', $provider->id)->get();
    expect($ruleRows)->toHaveCount(3)
        ->and($ruleRows->first()->start_time)->toContain('10:00');
});

it('syncs services for a provider (pivot updates)', function () {
    ['business' => $business, 'admin' => $admin, 'service' => $service] = BusinessSetup::createLaunchedBusiness();
    $staffUser = User::factory()->create();
    $provider = attachProvider($business, $staffUser);

    expect($provider->services()->count())->toBe(0);

    $this->actingAs($admin)
        ->put("/dashboard/settings/providers/{$provider->id}/services", [
            'service_ids' => [$service->id],
        ])
        ->assertRedirect();

    expect($provider->services()->count())->toBe(1)
        ->and($provider->services()->first()->id)->toBe($service->id);
});

it('creates a provider-level exception via store-exception', function () {
    ['business' => $business, 'admin' => $admin] = BusinessSetup::createLaunchedBusiness();
    $staffUser = User::factory()->create();
    $provider = attachProvider($business, $staffUser);

    $this->actingAs($admin)
        ->post("/dashboard/settings/providers/{$provider->id}/exceptions", [
            'start_date' => now()->addDays(3)->format('Y-m-d'),
            'end_date' => now()->addDays(3)->format('Y-m-d'),
            'type' => 'block',
            'reason' => 'Provider sick',
        ])
        ->assertRedirect();

    expect(AvailabilityException::where('business_id', $business->id)
        ->where('provider_id', $provider->id)
        ->where('reason', 'Provider sick')
        ->exists())->toBeTrue();
});

it('updates a provider-level exception', function () {
    ['business' => $business, 'admin' => $admin] = BusinessSetup::createLaunchedBusiness();
    $staffUser = User::factory()->create();
    $provider = attachProvider($business, $staffUser);

    $exception = AvailabilityException::factory()
        ->for($business)
        ->create([
            'provider_id' => $provider->id,
            'reason' => 'Original',
        ]);

    $this->actingAs($admin)
        ->put("/dashboard/settings/providers/{$provider->id}/exceptions/{$exception->id}", [
            'start_date' => $exception->start_date->format('Y-m-d'),
            'end_date' => $exception->end_date->format('Y-m-d'),
            'type' => 'block',
            'reason' => 'Updated',
        ])
        ->assertRedirect();

    expect($exception->fresh()->reason)->toBe('Updated');
});

it('deletes a provider-level exception', function () {
    ['business' => $business, 'admin' => $admin] = BusinessSetup::createLaunchedBusiness();
    $staffUser = User::factory()->create();
    $provider = attachProvider($business, $staffUser);
    $exception = AvailabilityException::factory()
        ->for($business)
        ->create(['provider_id' => $provider->id]);

    $this->actingAs($admin)
        ->delete("/dashboard/settings/providers/{$provider->id}/exceptions/{$exception->id}")
        ->assertRedirect();

    expect(AvailabilityException::find($exception->id))->toBeNull();
});

it('forbids toggling a provider of a different business (tenant scope, D-063)', function () {
    ['admin' => $admin] = BusinessSetup::createLaunchedBusiness();
    ['provider' => $otherProvider] = BusinessSetup::createLaunchedBusiness();

    $this->actingAs($admin)
        ->post("/dashboard/settings/providers/{$otherProvider->id}/toggle")
        ->assertForbidden();
});

it('denies staff members with a 403 on the provider toggle route', function () {
    ['business' => $business, 'staff' => $staffCollection] = BusinessSetup::createBusinessWithStaff(
        1,
        ['onboarding_step' => 5, 'onboarding_completed_at' => now()],
    );
    $staff = $staffCollection->first();
    $provider = attachProvider($business, $staff);

    // role:admin middleware guards the endpoint; staff posting hits 403.
    $this->actingAs($staff)
        ->post("/dashboard/settings/providers/{$provider->id}/toggle")
        ->assertForbidden();
});
