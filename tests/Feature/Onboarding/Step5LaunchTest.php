<?php

use App\Enums\BusinessUserRole;
use App\Models\Business;
use App\Models\BusinessHour;
use App\Models\Service;
use App\Models\User;

beforeEach(function () {
    $this->withoutVite();
    $this->user = User::factory()->create(['email_verified_at' => now()]);
    $this->business = Business::factory()->create(['onboarding_step' => 5]);
    $this->business->users()->attach($this->user->id, ['role' => BusinessUserRole::Admin->value]);
    Service::factory()->create(['business_id' => $this->business->id]);
    BusinessHour::factory()->create(['business_id' => $this->business->id, 'day_of_week' => 1]);
});

test('step 5 page renders summary', function () {
    $response = $this->actingAs($this->user)->get('/onboarding/step/5');

    $response->assertSuccessful();
    $response->assertInertia(fn ($page) => $page
        ->component('onboarding/step-5')
        ->has('business')
        ->has('hours')
        ->has('service')
        ->has('publicUrl')
    );
});

test('step 5 launch sets onboarding_completed_at', function () {
    $response = $this->actingAs($this->user)->post('/onboarding/step/5');

    $response->assertRedirect(route('dashboard.welcome'));

    $this->business->refresh();
    expect($this->business->onboarding_completed_at)->not->toBeNull();
    expect($this->business->isOnboarded())->toBeTrue();
});

test('after launch dashboard is accessible', function () {
    $this->actingAs($this->user)->post('/onboarding/step/5');

    $response = $this->actingAs($this->user)->get('/dashboard');

    $response->assertSuccessful();
});

test('after launch onboarding redirects to dashboard', function () {
    $this->actingAs($this->user)->post('/onboarding/step/5');

    $response = $this->actingAs($this->user)->get('/onboarding/step/1');

    $response->assertRedirect(route('dashboard'));
});
