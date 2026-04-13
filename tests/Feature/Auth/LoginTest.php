<?php

use App\Enums\BusinessUserRole;
use App\Models\Business;
use App\Models\Customer;
use App\Models\User;

test('login page can be rendered', function () {
    $this->withoutVite();

    $response = $this->get('/login');

    $response->assertStatus(200);
});

test('users can login with valid credentials', function () {
    $user = User::factory()->create();

    $response = $this->post('/login', [
        'email' => $user->email,
        'password' => 'password',
    ]);

    $this->assertAuthenticated();
});

test('users cannot login with invalid credentials', function () {
    $user = User::factory()->create();

    $this->post('/login', [
        'email' => $user->email,
        'password' => 'wrong-password',
    ]);

    $this->assertGuest();
});

test('business users are redirected to dashboard', function () {
    $this->withoutVite();
    $user = User::factory()->create();
    $business = Business::factory()->create();
    $business->users()->attach($user->id, ['role' => BusinessUserRole::Admin->value]);

    $response = $this->post('/login', [
        'email' => $user->email,
        'password' => 'password',
    ]);

    $response->assertRedirect('/dashboard');
});

test('customer users are redirected to my-bookings', function () {
    $this->withoutVite();
    $user = User::factory()->create();
    Customer::factory()->create(['user_id' => $user->id, 'email' => $user->email]);

    $response = $this->post('/login', [
        'email' => $user->email,
        'password' => 'password',
    ]);

    $response->assertRedirect('/my-bookings');
});

test('users can logout', function () {
    $user = User::factory()->create();

    $this->actingAs($user)->post('/logout');

    $this->assertGuest();
});

test('login is rate limited', function () {
    $user = User::factory()->create();

    for ($i = 0; $i < 5; $i++) {
        $this->post('/login', [
            'email' => $user->email,
            'password' => 'wrong-password',
        ]);
    }

    $response = $this->post('/login', [
        'email' => $user->email,
        'password' => 'wrong-password',
    ]);

    $response->assertSessionHasErrors(['email']);
});
