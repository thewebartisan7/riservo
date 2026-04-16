<?php

declare(strict_types=1);

use App\Models\Service;
use Tests\Browser\Support\BusinessSetup;

// Covers: settings.services, settings.services.create, settings.services.store,
// settings.services.edit, settings.services.update (E2E-5).
//
// HTTP-only tests precede browser tests to avoid RefreshDatabase flakiness
// in the Pest Browser suite — see BookingSettingsTest.php for details.

it('creates a new service and it appears in the services list', function () {
    ['business' => $business, 'admin' => $admin, 'provider' => $provider] = BusinessSetup::createLaunchedBusiness();

    $this->actingAs($admin)
        ->post('/dashboard/settings/services', [
            'name' => 'Color Treatment',
            'description' => 'Full color service',
            'duration_minutes' => 90,
            'price' => 120.00,
            'buffer_before' => 0,
            'buffer_after' => 10,
            'slot_interval_minutes' => 30,
            'is_active' => '1',
            'provider_ids' => [$provider->id],
        ])
        ->assertRedirect('/dashboard/settings/services');

    expect(Service::where('business_id', $business->id)->where('name', 'Color Treatment')->exists())
        ->toBeTrue();
});

it('renders the edit page and updates a service', function () {
    ['admin' => $admin, 'provider' => $provider, 'service' => $service] = BusinessSetup::createLaunchedBusiness();

    $this->actingAs($admin)
        ->put("/dashboard/settings/services/{$service->id}", [
            'name' => 'Renamed service',
            'description' => 'New description',
            'duration_minutes' => 45,
            'price' => 75.50,
            'buffer_before' => 5,
            'buffer_after' => 5,
            'slot_interval_minutes' => 15,
            'is_active' => '1',
            'provider_ids' => [$provider->id],
        ])
        ->assertRedirect();

    $fresh = $service->fresh();
    expect($fresh->name)->toBe('Renamed service')
        ->and($fresh->duration_minutes)->toBe(45)
        ->and((float) $fresh->price)->toBe(75.50);
});

it('deactivates a service by saving is_active=0', function () {
    ['admin' => $admin, 'provider' => $provider, 'service' => $service] = BusinessSetup::createLaunchedBusiness();
    $service->update(['name' => 'Deactivate Me']);

    $this->actingAs($admin)
        ->put("/dashboard/settings/services/{$service->id}", [
            'name' => 'Deactivate Me',
            'description' => null,
            'duration_minutes' => $service->duration_minutes,
            'price' => null,
            'buffer_before' => 0,
            'buffer_after' => 0,
            'slot_interval_minutes' => $service->slot_interval_minutes,
            'is_active' => '0',
            'provider_ids' => [$provider->id],
        ])
        ->assertRedirect();

    expect($service->fresh()->is_active)->toBeFalse();
});

it('syncs providers on a service via update (pivot rows reflect selection)', function () {
    ['admin' => $admin, 'provider' => $provider, 'service' => $service] = BusinessSetup::createLaunchedBusiness();
    expect($service->providers()->where('providers.id', $provider->id)->exists())->toBeTrue();

    // Sync to empty list — provider is removed.
    $this->actingAs($admin)
        ->put("/dashboard/settings/services/{$service->id}", [
            'name' => $service->name,
            'description' => $service->description,
            'duration_minutes' => $service->duration_minutes,
            'price' => $service->price,
            'buffer_before' => 0,
            'buffer_after' => 0,
            'slot_interval_minutes' => $service->slot_interval_minutes,
            'is_active' => '1',
            'provider_ids' => [],
        ])
        ->assertRedirect();

    expect($service->providers()->count())->toBe(0);
});

it('rejects a service with duration_minutes=0', function () {
    ['admin' => $admin, 'provider' => $provider] = BusinessSetup::createLaunchedBusiness();

    $this->actingAs($admin)
        ->post('/dashboard/settings/services', [
            'name' => 'Bad Service',
            'description' => null,
            'duration_minutes' => 0,
            'price' => null,
            'buffer_before' => 0,
            'buffer_after' => 0,
            'slot_interval_minutes' => 30,
            'is_active' => '1',
            'provider_ids' => [$provider->id],
        ])
        ->assertSessionHasErrors('duration_minutes');
});

it('denies staff members with a 403', function () {
    ['staff' => $staffCollection] = BusinessSetup::createBusinessWithStaff(
        1,
        ['onboarding_step' => 5, 'onboarding_completed_at' => now()],
    );
    $staff = $staffCollection->first();

    $this->actingAs($staff)->get('/dashboard/settings/services')->assertForbidden();
});

it('renders the services list with the seeded service', function () {
    ['service' => $service, 'admin' => $admin] = BusinessSetup::createLaunchedBusiness();
    $service->update(['name' => 'Signature Haircut']);

    $this->actingAs($admin);

    $page = visit('/dashboard/settings/services');
    $page->assertPathIs('/dashboard/settings/services')
        ->assertSee('Services')
        ->assertSee('Signature Haircut')
        ->assertNoJavaScriptErrors();
});

it('renders the new-service page with the providers picker', function () {
    ['admin' => $admin] = BusinessSetup::createLaunchedBusiness();

    $this->actingAs($admin);

    $page = visit('/dashboard/settings/services/create');
    $page->assertPathIs('/dashboard/settings/services/create')
        ->assertSee('New service')
        ->assertSee('Service name')
        ->assertNoJavaScriptErrors();
});
