<?php

use App\Models\Business;
use App\Models\Service;
use App\Models\User;

test('returns collaborators for a service', function () {
    $business = Business::factory()->onboarded()->create();
    $service = Service::factory()->create(['business_id' => $business->id, 'is_active' => true]);

    $collab1 = User::factory()->create(['name' => 'Alice']);
    $collab2 = User::factory()->create(['name' => 'Bob']);
    $business->users()->attach([$collab1->id => ['role' => 'collaborator'], $collab2->id => ['role' => 'collaborator']]);
    $service->collaborators()->attach([$collab1->id, $collab2->id]);

    $response = $this->getJson('/booking/'.$business->slug.'/collaborators?service_id='.$service->id);

    $response->assertOk()
        ->assertJsonCount(2, 'collaborators')
        ->assertJsonPath('collaborators.0.name', 'Alice')
        ->assertJsonPath('collaborators.1.name', 'Bob');
});

test('returns only collaborators assigned to the service', function () {
    $business = Business::factory()->onboarded()->create();
    $service = Service::factory()->create(['business_id' => $business->id, 'is_active' => true]);

    $assigned = User::factory()->create(['name' => 'Assigned']);
    $notAssigned = User::factory()->create(['name' => 'Not Assigned']);
    $business->users()->attach([$assigned->id => ['role' => 'collaborator'], $notAssigned->id => ['role' => 'collaborator']]);
    $service->collaborators()->attach($assigned->id);

    $response = $this->getJson('/booking/'.$business->slug.'/collaborators?service_id='.$service->id);

    $response->assertOk()
        ->assertJsonCount(1, 'collaborators')
        ->assertJsonPath('collaborators.0.name', 'Assigned');
});

test('returns avatar_url when avatar is set', function () {
    $business = Business::factory()->onboarded()->create();
    $service = Service::factory()->create(['business_id' => $business->id, 'is_active' => true]);

    $collab = User::factory()->create(['avatar' => 'avatars/test.jpg']);
    $business->users()->attach($collab->id, ['role' => 'collaborator']);
    $service->collaborators()->attach($collab->id);

    $response = $this->getJson('/booking/'.$business->slug.'/collaborators?service_id='.$service->id);

    $response->assertOk();
    expect($response->json('collaborators.0.avatar_url'))->toContain('avatars/test.jpg');
});

test('returns null avatar_url when no avatar', function () {
    $business = Business::factory()->onboarded()->create();
    $service = Service::factory()->create(['business_id' => $business->id, 'is_active' => true]);

    $collab = User::factory()->create(['avatar' => null]);
    $business->users()->attach($collab->id, ['role' => 'collaborator']);
    $service->collaborators()->attach($collab->id);

    $response = $this->getJson('/booking/'.$business->slug.'/collaborators?service_id='.$service->id);

    $response->assertOk()
        ->assertJsonPath('collaborators.0.avatar_url', null);
});

test('validates service_id is required', function () {
    $business = Business::factory()->onboarded()->create();

    $response = $this->getJson('/booking/'.$business->slug.'/collaborators');

    $response->assertStatus(422);
});

test('returns 404 for non-existent business', function () {
    $response = $this->getJson('/booking/non-existent/collaborators?service_id=1');

    $response->assertStatus(404);
});
