<?php

use App\Models\Business;
use App\Models\Customer;
use App\Models\User;

beforeEach(function () {
    $this->withoutVite();
    $this->user = User::factory()->create(['email_verified_at' => now()]);
    $this->business = Business::factory()->create(['onboarding_step' => 3]);
    attachAdmin($this->business, $this->user);
});

test('cannot skip ahead past current step', function () {
    $response = $this->actingAs($this->user)->get('/onboarding/step/4');

    $response->assertRedirect('/onboarding/step/3');
});

test('can navigate back to completed step', function () {
    $response = $this->actingAs($this->user)->get('/onboarding/step/1');

    $response->assertSuccessful();
    $response->assertInertia(fn ($page) => $page->component('onboarding/step-1'));
});

test('can navigate back to step 2', function () {
    $response = $this->actingAs($this->user)->get('/onboarding/step/2');

    $response->assertSuccessful();
    $response->assertInertia(fn ($page) => $page->component('onboarding/step-2'));
});

test('can access current step', function () {
    $response = $this->actingAs($this->user)->get('/onboarding/step/3');

    $response->assertSuccessful();
    $response->assertInertia(fn ($page) => $page->component('onboarding/step-3'));
});

test('unauthenticated user cannot access onboarding', function () {
    $response = $this->get('/onboarding/step/1');

    $response->assertRedirect('/login');
});

test('customer cannot access onboarding', function () {
    $user = User::factory()->create(['email_verified_at' => now()]);
    Customer::factory()->create(['user_id' => $user->id, 'email' => $user->email]);

    $response = $this->actingAs($user)->get('/onboarding/step/1');

    $response->assertForbidden();
});

test('step advances but does not go backwards on resubmit', function () {
    // Business is at step 3, resubmit step 1 — step should remain 3
    $this->actingAs($this->user)->post('/onboarding/step/1', [
        'name' => 'Updated',
        'slug' => $this->business->slug,
    ]);

    $this->business->refresh();
    expect($this->business->onboarding_step)->toBe(3);
});
