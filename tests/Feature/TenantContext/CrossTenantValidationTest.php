<?php

use App\Models\Business;
use App\Models\BusinessInvitation;
use App\Models\Service;
use App\Models\User;

beforeEach(function () {
    $this->withoutVite();

    $this->business = Business::factory()->onboarded()->create();
    $this->admin = User::factory()->create();
    attachAdmin($this->business, $this->admin);

    $this->foreignBusiness = Business::factory()->onboarded()->create();
    $this->foreignService = Service::factory()->create(['business_id' => $this->foreignBusiness->id]);
    $this->foreignProvider = attachProvider($this->foreignBusiness, User::factory()->create());
});

test('store service rejects a foreign provider id', function () {
    $this->actingAs($this->admin)
        ->post('/dashboard/settings/services', [
            'name' => 'Haircut',
            'duration_minutes' => 30,
            'slot_interval_minutes' => 15,
            'provider_ids' => [$this->foreignProvider->id],
        ])
        ->assertSessionHasErrors('provider_ids.0');
});

test('update service rejects a mixed array containing a foreign provider id', function () {
    $ownProvider = attachProvider($this->business, User::factory()->create());
    $service = Service::factory()->create(['business_id' => $this->business->id]);

    $this->actingAs($this->admin)
        ->put("/dashboard/settings/services/{$service->id}", [
            'name' => 'Haircut',
            'duration_minutes' => 30,
            'slot_interval_minutes' => 15,
            'is_active' => true,
            'provider_ids' => [$ownProvider->id, $this->foreignProvider->id],
        ])
        ->assertSessionHasErrors('provider_ids.1');
});

test('sync provider services rejects a foreign service id', function () {
    $ownProvider = attachProvider($this->business, User::factory()->create());

    $this->actingAs($this->admin)
        ->put("/dashboard/settings/providers/{$ownProvider->id}/services", [
            'service_ids' => [$this->foreignService->id],
        ])
        ->assertSessionHasErrors('service_ids.0');
});

test('staff invite rejects a foreign service id', function () {
    $this->actingAs($this->admin)
        ->post('/dashboard/settings/staff/invite', [
            'email' => 'new@example.com',
            'service_ids' => [$this->foreignService->id],
        ])
        ->assertSessionHasErrors('service_ids.0');
});

test('manual booking rejects a foreign service id', function () {
    $ownProvider = attachProvider($this->business, User::factory()->create());

    $this->actingAs($this->admin)
        ->post('/dashboard/bookings', [
            'customer_name' => 'Jane',
            'customer_email' => 'jane@example.com',
            'service_id' => $this->foreignService->id,
            'provider_id' => $ownProvider->id,
            'date' => '2026-05-01',
            'time' => '10:00',
        ])
        ->assertSessionHasErrors('service_id');
});

test('manual booking rejects a foreign provider id', function () {
    $ownService = Service::factory()->create(['business_id' => $this->business->id]);

    $this->actingAs($this->admin)
        ->post('/dashboard/bookings', [
            'customer_name' => 'Jane',
            'customer_email' => 'jane@example.com',
            'service_id' => $ownService->id,
            'provider_id' => $this->foreignProvider->id,
            'date' => '2026-05-01',
            'time' => '10:00',
        ])
        ->assertSessionHasErrors('provider_id');
});

test('onboarding invitations reject a foreign service id', function () {
    $onboardingBusiness = Business::factory()->create(['onboarding_step' => 4]);
    $onboardingAdmin = User::factory()->create(['email_verified_at' => now()]);
    attachAdmin($onboardingBusiness, $onboardingAdmin);

    $this->actingAs($onboardingAdmin)
        ->post('/onboarding/step/4', [
            'invitations' => [
                ['email' => 'staff@example.com', 'service_ids' => [$this->foreignService->id]],
            ],
        ])
        ->assertSessionHasErrors('invitations.0.service_ids.0');

    expect(BusinessInvitation::count())->toBe(0);
});

test('staff invite soft-deleted foreign service id is still rejected', function () {
    $this->foreignService->delete();

    $this->actingAs($this->admin)
        ->post('/dashboard/settings/staff/invite', [
            'email' => 'new@example.com',
            'service_ids' => [$this->foreignService->id],
        ])
        ->assertSessionHasErrors('service_ids.0');
});
