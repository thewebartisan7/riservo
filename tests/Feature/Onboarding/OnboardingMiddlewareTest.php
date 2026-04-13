<?php

use App\Enums\BusinessUserRole;
use App\Models\Business;
use App\Models\User;

test('admin of non-onboarded business is redirected to onboarding', function () {
    $user = User::factory()->create();
    $business = Business::factory()->create(['onboarding_step' => 1]);
    $business->users()->attach($user->id, ['role' => BusinessUserRole::Admin->value]);

    $response = $this->actingAs($user)->get('/dashboard');

    $response->assertRedirect('/onboarding/step/1');
});

test('admin of non-onboarded business is redirected to correct step', function () {
    $user = User::factory()->create();
    $business = Business::factory()->create(['onboarding_step' => 3]);
    $business->users()->attach($user->id, ['role' => BusinessUserRole::Admin->value]);

    $response = $this->actingAs($user)->get('/dashboard');

    $response->assertRedirect('/onboarding/step/3');
});

test('admin of onboarded business can access dashboard', function () {
    $this->withoutVite();
    $user = User::factory()->create();
    $business = Business::factory()->onboarded()->create();
    $business->users()->attach($user->id, ['role' => BusinessUserRole::Admin->value]);

    $response = $this->actingAs($user)->get('/dashboard');

    $response->assertSuccessful();
});

test('collaborator of non-onboarded business can access dashboard', function () {
    $this->withoutVite();
    $user = User::factory()->create();
    $business = Business::factory()->create(['onboarding_step' => 1]);
    $business->users()->attach($user->id, ['role' => BusinessUserRole::Collaborator->value]);

    $response = $this->actingAs($user)->get('/dashboard');

    // Collaborators pass through the onboarded middleware
    $response->assertSuccessful();
});

test('already onboarded admin visiting onboarding is redirected to dashboard', function () {
    $user = User::factory()->create();
    $business = Business::factory()->onboarded()->create();
    $business->users()->attach($user->id, ['role' => BusinessUserRole::Admin->value]);

    $response = $this->actingAs($user)->get('/onboarding/step/1');

    $response->assertRedirect(route('dashboard'));
});
