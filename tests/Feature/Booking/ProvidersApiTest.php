<?php

use App\Models\Business;
use App\Models\Service;
use App\Models\User;

test('returns providers for a service', function () {
    $business = Business::factory()->onboarded()->create();
    $service = Service::factory()->create(['business_id' => $business->id, 'is_active' => true]);

    $alice = User::factory()->create(['name' => 'Alice']);
    $bob = User::factory()->create(['name' => 'Bob']);
    $provider1 = attachProvider($business, $alice);
    $provider2 = attachProvider($business, $bob);
    $service->providers()->attach([$provider1->id, $provider2->id]);

    $response = $this->getJson('/booking/'.$business->slug.'/providers?service_id='.$service->id);

    $response->assertOk()
        ->assertJsonCount(2, 'providers')
        ->assertJsonPath('providers.0.name', 'Alice')
        ->assertJsonPath('providers.1.name', 'Bob');
});

test('returns only providers assigned to the service', function () {
    $business = Business::factory()->onboarded()->create();
    $service = Service::factory()->create(['business_id' => $business->id, 'is_active' => true]);

    $assignedUser = User::factory()->create(['name' => 'Assigned']);
    $notAssignedUser = User::factory()->create(['name' => 'Not Assigned']);
    $assignedProvider = attachProvider($business, $assignedUser);
    attachProvider($business, $notAssignedUser);
    $service->providers()->attach($assignedProvider->id);

    $response = $this->getJson('/booking/'.$business->slug.'/providers?service_id='.$service->id);

    $response->assertOk()
        ->assertJsonCount(1, 'providers')
        ->assertJsonPath('providers.0.name', 'Assigned');
});

test('returns avatar_url when avatar is set', function () {
    $business = Business::factory()->onboarded()->create();
    $service = Service::factory()->create(['business_id' => $business->id, 'is_active' => true]);

    $user = User::factory()->create(['avatar' => 'avatars/test.jpg']);
    $provider = attachProvider($business, $user);
    $service->providers()->attach($provider->id);

    $response = $this->getJson('/booking/'.$business->slug.'/providers?service_id='.$service->id);

    $response->assertOk();
    expect($response->json('providers.0.avatar_url'))->toContain('avatars/test.jpg');
});

test('returns null avatar_url when no avatar', function () {
    $business = Business::factory()->onboarded()->create();
    $service = Service::factory()->create(['business_id' => $business->id, 'is_active' => true]);

    $user = User::factory()->create(['avatar' => null]);
    $provider = attachProvider($business, $user);
    $service->providers()->attach($provider->id);

    $response = $this->getJson('/booking/'.$business->slug.'/providers?service_id='.$service->id);

    $response->assertOk()
        ->assertJsonPath('providers.0.avatar_url', null);
});

test('validates service_id is required', function () {
    $business = Business::factory()->onboarded()->create();

    $response = $this->getJson('/booking/'.$business->slug.'/providers');

    $response->assertStatus(422);
});

test('returns 404 for non-existent business', function () {
    $response = $this->getJson('/booking/non-existent/providers?service_id=1');

    $response->assertStatus(404);
});

test('soft-deleted provider is not returned', function () {
    $business = Business::factory()->onboarded()->create();
    $service = Service::factory()->create(['business_id' => $business->id, 'is_active' => true]);

    $activeUser = User::factory()->create(['name' => 'Active']);
    $trashedUser = User::factory()->create(['name' => 'Trashed']);
    $activeProvider = attachProvider($business, $activeUser);
    $trashedProvider = attachProvider($business, $trashedUser, active: false);
    $service->providers()->attach([$activeProvider->id, $trashedProvider->id]);

    $response = $this->getJson('/booking/'.$business->slug.'/providers?service_id='.$service->id);

    $response->assertOk()
        ->assertJsonCount(1, 'providers')
        ->assertJsonPath('providers.0.name', 'Active');
});

test('returns empty list when allow_provider_choice is false', function () {
    $business = Business::factory()->onboarded()->noProviderChoice()->create();
    $service = Service::factory()->create(['business_id' => $business->id, 'is_active' => true]);

    $user = User::factory()->create(['name' => 'Alice']);
    $provider = attachProvider($business, $user);
    $service->providers()->attach($provider->id);

    $response = $this->getJson('/booking/'.$business->slug.'/providers?service_id='.$service->id);

    $response->assertOk()
        ->assertJsonCount(0, 'providers')
        ->assertJsonPath('providers', []);
});
