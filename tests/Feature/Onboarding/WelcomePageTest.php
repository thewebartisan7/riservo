<?php

use App\Models\Business;
use App\Models\User;

test('welcome page renders after onboarding', function () {
    $this->withoutVite();
    $user = User::factory()->create(['email_verified_at' => now()]);
    $business = Business::factory()->onboarded()->create();
    attachAdmin($business, $user);

    $response = $this->actingAs($user)->get('/dashboard/welcome');

    $response->assertSuccessful();
    $response->assertInertia(fn ($page) => $page
        ->component('dashboard/welcome')
        ->has('publicUrl')
        ->has('businessName')
    );
});

test('welcome page shows correct public url', function () {
    $this->withoutVite();
    $user = User::factory()->create(['email_verified_at' => now()]);
    $business = Business::factory()->onboarded()->create(['slug' => 'test-biz']);
    attachAdmin($business, $user);

    $response = $this->actingAs($user)->get('/dashboard/welcome');

    $response->assertInertia(fn ($page) => $page
        ->where('publicUrl', url('test-biz'))
    );
});

test('welcome page next-step links resolve to existing routes', function () {
    $this->withoutVite();
    $user = User::factory()->create(['email_verified_at' => now()]);
    $business = Business::factory()->onboarded()->create();
    attachAdmin($business, $user);

    $this->actingAs($user)->get('/dashboard/welcome')->assertSuccessful();

    foreach (['settings.services', 'settings.staff', 'settings.booking'] as $name) {
        $this->actingAs($user)->get(route($name))->assertSuccessful();
    }
});
