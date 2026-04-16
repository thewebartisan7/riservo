<?php

use App\Models\Business;
use App\Models\User;

beforeEach(function () {
    $this->user = User::factory()->create(['email_verified_at' => now()]);
    $this->business = Business::factory()->create([
        'slug' => 'my-business',
        'onboarding_step' => 1,
    ]);
    attachAdmin($this->business, $this->user);
});

test('available slug returns true', function () {
    $response = $this->actingAs($this->user)->postJson('/onboarding/slug-check', [
        'slug' => 'unique-slug',
    ]);

    $response->assertSuccessful();
    $response->assertJson(['available' => true]);
});

test('taken slug returns false', function () {
    Business::factory()->create(['slug' => 'taken-slug']);

    $response = $this->actingAs($this->user)->postJson('/onboarding/slug-check', [
        'slug' => 'taken-slug',
    ]);

    $response->assertSuccessful();
    $response->assertJson(['available' => false]);
});

test('reserved slug returns false', function () {
    $response = $this->actingAs($this->user)->postJson('/onboarding/slug-check', [
        'slug' => 'dashboard',
    ]);

    $response->assertSuccessful();
    $response->assertJson(['available' => false]);
});

test('own slug returns true', function () {
    $response = $this->actingAs($this->user)->postJson('/onboarding/slug-check', [
        'slug' => 'my-business',
    ]);

    $response->assertSuccessful();
    $response->assertJson(['available' => true]);
});

test('invalid format returns false', function () {
    $response = $this->actingAs($this->user)->postJson('/onboarding/slug-check', [
        'slug' => 'INVALID FORMAT',
    ]);

    $response->assertSuccessful();
    $response->assertJson(['available' => false]);
});
