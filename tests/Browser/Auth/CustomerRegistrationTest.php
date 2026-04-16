<?php

declare(strict_types=1);

use App\Models\Customer;
use App\Models\User;

// Covers: GET/POST /customer/register (customer.register) per D-074.
//
// NOTE: The roadmap mentions "(name, email, phone, password)" but the real
// customer-register page + CustomerRegisterRequest do NOT include a phone
// field as of 2026-04-16. Tests match the actual UI/validation.

it('registers a fresh customer and redirects to /my-bookings', function () {
    $page = visit('/customer/register');

    $page->assertSee('Create customer account')
        ->type('name', 'Fresh Customer')
        ->type('email', 'fresh@example.com')
        ->type('password', 'password123')
        ->type('password_confirmation', 'password123')
        ->click('button[type="submit"]')
        ->assertPathIs('/my-bookings')
        ->assertNoJavaScriptErrors();

    $user = User::where('email', 'fresh@example.com')->first();
    expect($user)->not->toBeNull()
        ->and($user->name)->toBe('Fresh Customer')
        ->and($user->hasVerifiedEmail())->toBeTrue();

    $customer = Customer::where('email', 'fresh@example.com')->first();
    expect($customer)->not->toBeNull()
        ->and($customer->user_id)->toBe($user->id);
});

it('links an existing unregistered Customer to the new User without duplication', function () {
    $existingCustomer = Customer::factory()->create([
        'email' => 'returning@example.com',
        'name' => 'Guest From Booking',
        'user_id' => null,
    ]);

    $page = visit('/customer/register');

    $page->type('name', 'Returning Customer')
        ->type('email', 'returning@example.com')
        ->type('password', 'password123')
        ->type('password_confirmation', 'password123')
        ->click('button[type="submit"]')
        ->assertPathIs('/my-bookings')
        ->assertNoJavaScriptErrors();

    // No duplicate Customer row.
    expect(Customer::where('email', 'returning@example.com')->count())->toBe(1);

    $user = User::where('email', 'returning@example.com')->first();
    expect($user)->not->toBeNull();

    // Existing Customer row is now linked to the new User.
    expect($existingCustomer->fresh()->user_id)->toBe($user->id);
});

it('registers without any prior booking (D-074)', function () {
    // No customer or booking seed data — proves registration is independent of bookings.
    $page = visit('/customer/register');

    $page->type('name', 'Brand New')
        ->type('email', 'nobookings@example.com')
        ->type('password', 'password123')
        ->type('password_confirmation', 'password123')
        ->click('button[type="submit"]')
        ->assertPathIs('/my-bookings')
        ->assertNoJavaScriptErrors();

    expect(User::where('email', 'nobookings@example.com')->exists())->toBeTrue();
    expect(Customer::where('email', 'nobookings@example.com')->exists())->toBeTrue();
});

it('shows per-field validation errors when required fields are missing', function () {
    $page = visit('/customer/register');

    // Bypass HTML5 required so server-side validation runs.
    $page->script("document.querySelectorAll('input[required]').forEach(el => el.removeAttribute('required'));");

    $page->click('button[type="submit"]')
        ->assertSee('The name field is required')
        ->assertSee('The email field is required')
        ->assertSee('The password field is required')
        ->assertPathIs('/customer/register')
        ->assertNoJavaScriptErrors();
});
