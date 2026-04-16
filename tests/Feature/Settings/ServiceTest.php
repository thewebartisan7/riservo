<?php

use App\Models\Business;
use App\Models\Service;
use App\Models\User;

beforeEach(function () {
    $this->business = Business::factory()->onboarded()->create();
    $this->admin = User::factory()->create();
    attachAdmin($this->business, $this->admin);
});

test('admin can view services list', function () {
    Service::factory()->count(3)->create(['business_id' => $this->business->id]);

    $this->actingAs($this->admin)
        ->get('/dashboard/settings/services')
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('dashboard/settings/services/index')
            ->has('services', 3)
        );
});

test('admin can view create service page', function () {
    $this->actingAs($this->admin)
        ->get('/dashboard/settings/services/create')
        ->assertOk()
        ->assertInertia(fn ($page) => $page->component('dashboard/settings/services/create'));
});

test('admin can create a service', function () {
    $this->actingAs($this->admin)
        ->post('/dashboard/settings/services', [
            'name' => 'Haircut',
            'description' => 'A fresh haircut',
            'duration_minutes' => 30,
            'price' => 50.00,
            'buffer_before' => 5,
            'buffer_after' => 10,
            'slot_interval_minutes' => 15,
            'is_active' => true,
            'provider_ids' => [],
        ])
        ->assertRedirect('/dashboard/settings/services');

    $service = $this->business->services()->first();
    expect($service->name)->toBe('Haircut');
    expect($service->slug)->toBe('haircut');
    expect($service->duration_minutes)->toBe(30);
    expect((float) $service->price)->toBe(50.00);
});

test('service slug is unique within business', function () {
    Service::factory()->create(['business_id' => $this->business->id, 'slug' => 'haircut']);

    $this->actingAs($this->admin)
        ->post('/dashboard/settings/services', [
            'name' => 'Haircut',
            'duration_minutes' => 30,
            'slot_interval_minutes' => 15,
            'provider_ids' => [],
        ])
        ->assertRedirect();

    $slugs = $this->business->services()->pluck('slug')->sort()->values()->all();
    expect($slugs)->toBe(['haircut', 'haircut-2']);
});

test('admin can edit a service', function () {
    $service = Service::factory()->create(['business_id' => $this->business->id]);

    $this->actingAs($this->admin)
        ->get("/dashboard/settings/services/{$service->id}")
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('dashboard/settings/services/edit')
            ->has('service')
        );
});

test('admin can update a service', function () {
    $service = Service::factory()->create([
        'business_id' => $this->business->id,
        'name' => 'Old Name',
    ]);

    $this->actingAs($this->admin)
        ->put("/dashboard/settings/services/{$service->id}", [
            'name' => 'New Name',
            'duration_minutes' => 60,
            'price' => null,
            'buffer_before' => 0,
            'buffer_after' => 0,
            'slot_interval_minutes' => 30,
            'is_active' => true,
            'provider_ids' => [],
        ])
        ->assertRedirect();

    expect($service->fresh()->name)->toBe('New Name');
});

test('admin can assign providers to a service', function () {
    $service = Service::factory()->create(['business_id' => $this->business->id]);
    $staff = User::factory()->create();
    $provider = attachProvider($this->business, $staff);

    $this->actingAs($this->admin)
        ->put("/dashboard/settings/services/{$service->id}", [
            'name' => $service->name,
            'duration_minutes' => $service->duration_minutes,
            'slot_interval_minutes' => $service->slot_interval_minutes,
            'provider_ids' => [$provider->id],
        ])
        ->assertRedirect();

    expect($service->providers()->count())->toBe(1);
    expect($service->providers()->first()->id)->toBe($provider->id);
});

test('cannot edit service from another business', function () {
    $otherBusiness = Business::factory()->onboarded()->create();
    $service = Service::factory()->create(['business_id' => $otherBusiness->id]);

    $this->actingAs($this->admin)
        ->get("/dashboard/settings/services/{$service->id}")
        ->assertForbidden();
});

test('service name is required', function () {
    $this->actingAs($this->admin)
        ->post('/dashboard/settings/services', [
            'duration_minutes' => 30,
            'slot_interval_minutes' => 15,
            'provider_ids' => [],
        ])
        ->assertSessionHasErrors('name');
});
