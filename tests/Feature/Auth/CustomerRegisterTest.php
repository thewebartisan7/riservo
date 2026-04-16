<?php

use App\Models\Customer;
use App\Models\User;

test('customer register page can be rendered', function () {
    $this->withoutVite();

    $response = $this->get('/customer/register');

    $response->assertStatus(200);
});

test('a customer can register with an unknown email and is logged in', function () {
    $response = $this->post('/customer/register', [
        'name' => 'Fresh Customer',
        'email' => 'fresh@example.com',
        'password' => 'password123',
        'password_confirmation' => 'password123',
    ]);

    $response->assertRedirect('/my-bookings');
    $this->assertAuthenticated();

    $user = User::where('email', 'fresh@example.com')->first();
    expect($user)->not->toBeNull()
        ->and($user->name)->toBe('Fresh Customer')
        ->and($user->hasVerifiedEmail())->toBeTrue();

    $customer = Customer::where('email', 'fresh@example.com')->first();
    expect($customer)->not->toBeNull()
        ->and($customer->name)->toBe('Fresh Customer')
        ->and($customer->user_id)->toBe($user->id);
});

test('registration with an existing-customer email links the customer to the new user', function () {
    $customer = Customer::factory()->create([
        'email' => 'guest@example.com',
        'name' => 'Guest From Booking',
        'user_id' => null,
    ]);

    $response = $this->post('/customer/register', [
        'name' => 'Guest Registrant',
        'email' => 'guest@example.com',
        'password' => 'password123',
        'password_confirmation' => 'password123',
    ]);

    $response->assertRedirect('/my-bookings');
    $this->assertAuthenticated();

    $user = User::where('email', 'guest@example.com')->first();
    expect($user)->not->toBeNull()
        ->and($user->name)->toBe('Guest Registrant')
        ->and($user->hasVerifiedEmail())->toBeTrue();

    expect(Customer::where('email', 'guest@example.com')->count())->toBe(1);
    expect($customer->fresh()->user_id)->toBe($user->id);
});

test('registration with an already-registered user email returns a unique-validation error', function () {
    User::factory()->create(['email' => 'taken@example.com']);

    $response = $this->post('/customer/register', [
        'name' => 'Duplicate',
        'email' => 'taken@example.com',
        'password' => 'password123',
        'password_confirmation' => 'password123',
    ]);

    $response->assertSessionHasErrors(['email']);
    $this->assertGuest();

    expect(User::where('email', 'taken@example.com')->count())->toBe(1);
    expect(Customer::where('email', 'taken@example.com')->count())->toBe(0);
});
