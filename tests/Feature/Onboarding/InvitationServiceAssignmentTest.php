<?php

use App\Models\Business;
use App\Models\BusinessInvitation;
use App\Models\Service;
use App\Models\User;

test('accepted invitation with service_ids assigns services to collaborator', function () {
    $business = Business::factory()->onboarded()->create();
    $service1 = Service::factory()->create(['business_id' => $business->id]);
    $service2 = Service::factory()->create(['business_id' => $business->id]);

    $invitation = BusinessInvitation::factory()->create([
        'business_id' => $business->id,
        'email' => 'collab@example.com',
        'service_ids' => [$service1->id, $service2->id],
    ]);

    $response = $this->post("/invite/{$invitation->token}", [
        'name' => 'Collaborator',
        'password' => 'password',
        'password_confirmation' => 'password',
    ]);

    $user = User::where('email', 'collab@example.com')->first();
    expect($user)->not->toBeNull();
    expect($user->services()->count())->toBe(2);
    expect($user->services()->pluck('services.id')->sort()->values()->all())
        ->toBe(collect([$service1->id, $service2->id])->sort()->values()->all());
});

test('accepted invitation without service_ids does not assign services', function () {
    $business = Business::factory()->onboarded()->create();
    Service::factory()->create(['business_id' => $business->id]);

    $invitation = BusinessInvitation::factory()->create([
        'business_id' => $business->id,
        'email' => 'collab2@example.com',
        'service_ids' => null,
    ]);

    $this->post("/invite/{$invitation->token}", [
        'name' => 'Collaborator 2',
        'password' => 'password',
        'password_confirmation' => 'password',
    ]);

    $user = User::where('email', 'collab2@example.com')->first();
    expect($user->services()->count())->toBe(0);
});

test('accepted invitation ignores deleted service ids', function () {
    $business = Business::factory()->onboarded()->create();
    $service = Service::factory()->create(['business_id' => $business->id]);

    $invitation = BusinessInvitation::factory()->create([
        'business_id' => $business->id,
        'email' => 'collab3@example.com',
        'service_ids' => [$service->id, 99999],
    ]);

    $this->post("/invite/{$invitation->token}", [
        'name' => 'Collaborator 3',
        'password' => 'password',
        'password_confirmation' => 'password',
    ]);

    $user = User::where('email', 'collab3@example.com')->first();
    expect($user->services()->count())->toBe(1);
});
