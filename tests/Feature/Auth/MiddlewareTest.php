<?php

use App\Enums\BusinessUserRole;
use App\Models\Business;
use App\Models\Customer;
use App\Models\User;

test('unauthenticated user is redirected to login', function () {
    $response = $this->get('/dashboard');

    $response->assertRedirect('/login');
});

test('admin can access dashboard', function () {
    $this->withoutVite();
    $user = User::factory()->create();
    $business = Business::factory()->create();
    $business->users()->attach($user->id, ['role' => BusinessUserRole::Admin->value]);

    $response = $this->actingAs($user)->get('/dashboard');

    $response->assertStatus(200);
});

test('collaborator can access dashboard', function () {
    $this->withoutVite();
    $user = User::factory()->create();
    $business = Business::factory()->create();
    $business->users()->attach($user->id, ['role' => BusinessUserRole::Collaborator->value]);

    $response = $this->actingAs($user)->get('/dashboard');

    $response->assertStatus(200);
});

test('customer cannot access dashboard', function () {
    $user = User::factory()->create();
    Customer::factory()->create(['user_id' => $user->id, 'email' => $user->email]);

    $response = $this->actingAs($user)->get('/dashboard');

    $response->assertStatus(403);
});

test('customer can access my-bookings', function () {
    $this->withoutVite();
    $user = User::factory()->create();
    Customer::factory()->create(['user_id' => $user->id, 'email' => $user->email]);

    $response = $this->actingAs($user)->get('/my-bookings');

    $response->assertStatus(200);
});

test('business user without customer record cannot access my-bookings', function () {
    $user = User::factory()->create();
    $business = Business::factory()->create();
    $business->users()->attach($user->id, ['role' => BusinessUserRole::Admin->value]);

    $response = $this->actingAs($user)->get('/my-bookings');

    $response->assertStatus(403);
});

test('authenticated users are redirected from guest routes', function () {
    $user = User::factory()->create();

    $response = $this->actingAs($user)->get('/login');

    $response->assertRedirect('/dashboard');
});
