<?php

use App\Enums\BusinessUserRole;
use App\Models\Business;
use App\Models\User;
use Illuminate\Auth\Notifications\VerifyEmail;
use Illuminate\Support\Facades\Notification;

test('registration page can be rendered', function () {
    $this->withoutVite();

    $response = $this->get('/register');

    $response->assertStatus(200);
});

test('new users can register', function () {
    Notification::fake();

    $response = $this->post('/register', [
        'name' => 'Mario Rossi',
        'email' => 'mario@example.com',
        'password' => 'password123',
        'password_confirmation' => 'password123',
        'business_name' => 'Salone Mario',
    ]);

    $this->assertAuthenticated();

    $user = User::where('email', 'mario@example.com')->first();
    expect($user)->not->toBeNull()
        ->and($user->name)->toBe('Mario Rossi');

    $business = Business::where('slug', 'salone-mario')->first();
    expect($business)->not->toBeNull()
        ->and($business->name)->toBe('Salone Mario');

    expect($user->businesses()->first()->id)->toBe($business->id);
    expect($user->businesses()->first()->pivot->role)->toBe(BusinessUserRole::Admin);

    Notification::assertSentTo($user, VerifyEmail::class);

    $response->assertRedirect('/email/verify');
});

test('registration fails with invalid data', function () {
    $response = $this->post('/register', [
        'name' => '',
        'email' => 'not-an-email',
        'password' => 'short',
        'password_confirmation' => 'mismatch',
        'business_name' => '',
    ]);

    $response->assertSessionHasErrors(['name', 'email', 'password', 'business_name']);
    $this->assertGuest();
});

test('registration fails with duplicate email', function () {
    User::factory()->create(['email' => 'taken@example.com']);

    $response = $this->post('/register', [
        'name' => 'Test User',
        'email' => 'taken@example.com',
        'password' => 'password123',
        'password_confirmation' => 'password123',
        'business_name' => 'Test Business',
    ]);

    $response->assertSessionHasErrors(['email']);
});

test('slug is generated uniquely', function () {
    Business::factory()->create(['slug' => 'test-salon']);

    Notification::fake();

    $this->post('/register', [
        'name' => 'Owner',
        'email' => 'owner@example.com',
        'password' => 'password123',
        'password_confirmation' => 'password123',
        'business_name' => 'Test Salon',
    ]);

    $business = Business::where('slug', 'test-salon-2')->first();
    expect($business)->not->toBeNull();
});

test('reserved slugs are avoided', function () {
    Notification::fake();

    $this->post('/register', [
        'name' => 'Owner',
        'email' => 'owner@example.com',
        'password' => 'password123',
        'password_confirmation' => 'password123',
        'business_name' => 'Dashboard',
    ]);

    $business = Business::where('name', 'Dashboard')->first();
    expect($business)->not->toBeNull()
        ->and($business->slug)->not->toBe('dashboard');
});
