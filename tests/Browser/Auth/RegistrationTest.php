<?php

declare(strict_types=1);

use App\Enums\BusinessMemberRole;
use App\Models\Business;
use App\Models\User;

// Covers: GET/POST /register, auth/register.tsx (E2E-1).

it('registers a business owner and redirects to email verification', function () {
    $page = visit('/register');

    $page->assertPathIs('/register')
        ->assertSee('Start with riservo')
        ->type('name', 'Mario Rossi')
        ->type('business_name', 'Salone Mario')
        ->type('email', 'mario@example.com')
        ->type('password', 'password123')
        ->type('password_confirmation', 'password123')
        ->click('button[type="submit"]')
        ->assertSee('Verify your email')
        ->assertPathIs('/email/verify')
        ->assertNoJavaScriptErrors();

    $user = User::where('email', 'mario@example.com')->first();
    expect($user)->not->toBeNull()
        ->and($user->name)->toBe('Mario Rossi')
        ->and($user->hasVerifiedEmail())->toBeFalse();

    $business = Business::where('name', 'Salone Mario')->first();
    expect($business)->not->toBeNull()
        ->and($business->onboarding_step)->toBe(1)
        ->and($business->isOnboarded())->toBeFalse();

    $membership = $business->members()->where('users.id', $user->id)->first();
    expect($membership)->not->toBeNull()
        ->and($membership->pivot->role)->toBe(BusinessMemberRole::Admin);
});

it('shows an email-already-taken error when the email is already registered', function () {
    User::factory()->create(['email' => 'taken@example.com']);

    $page = visit('/register');

    $page->type('name', 'Dupe')
        ->type('business_name', 'Dupe Studio')
        ->type('email', 'taken@example.com')
        ->type('password', 'password123')
        ->type('password_confirmation', 'password123')
        ->click('button[type="submit"]')
        ->assertSee('has already been taken')
        ->assertPathIs('/register')
        ->assertNoJavaScriptErrors();
});

it('shows per-field errors when required fields are missing', function () {
    $page = visit('/register');

    // Bypass HTML5 required so server-side validation runs.
    $page->script("document.querySelectorAll('input[required]').forEach(el => el.removeAttribute('required'));");

    $page->click('button[type="submit"]')
        ->assertSee('The name field is required')
        ->assertSee('The email field is required')
        ->assertSee('The password field is required')
        ->assertSee('The business name field is required')
        ->assertPathIs('/register')
        ->assertNoJavaScriptErrors();
});

it('shows an error when the password confirmation does not match', function () {
    $page = visit('/register');

    $page->type('name', 'Mismatch User')
        ->type('business_name', 'Mismatch Studio')
        ->type('email', 'mismatch@example.com')
        ->type('password', 'password123')
        ->type('password_confirmation', 'different456')
        ->click('button[type="submit"]')
        ->assertSee('password')
        ->assertPathIs('/register')
        ->assertNoJavaScriptErrors();

    expect(User::where('email', 'mismatch@example.com')->exists())->toBeFalse();
});
