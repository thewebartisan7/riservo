<?php

declare(strict_types=1);

use App\Models\Business;
use App\Models\Customer;
use App\Models\User;
use Tests\Browser\Support\BusinessSetup;

// Covers: GET/POST /login, auth/login.tsx (E2E-1).
//
// NOTE: The E2E-0 AuthHelper::loginAs uses `$page->visit(...)` which does not
// exist on Pest's Webpage (only `navigate` / top-level `visit(url)` exist).
// That bug is logged in the final report. These tests submit the login form
// directly with `click('button[type="submit"]')` because `press('Sign in')`
// is ambiguous — the login page renders "Sign in" both as a card label and
// on the submit button, so text-only locators can hit the label.

it('logs an onboarded admin into the dashboard', function () {
    ['admin' => $admin] = BusinessSetup::createLaunchedBusiness();

    $page = visit('/login');

    $page->type('email', $admin->email)
        ->type('password', 'password')
        ->click('button[type="submit"]')
        ->assertSee('Dashboard')
        ->assertPathIs('/dashboard')
        ->assertNoJavaScriptErrors();
});

it('redirects an admin without completed onboarding to their current onboarding step', function () {
    $admin = User::factory()->create();
    $business = Business::factory()->create([
        'onboarding_step' => 2,
        'onboarding_completed_at' => null,
    ]);
    attachAdmin($business, $admin);

    $page = visit('/login')
        ->type('email', $admin->email)
        ->type('password', 'password')
        ->click('button[type="submit"]');

    $page->assertPathIs('/onboarding/step/2')
        ->assertNoJavaScriptErrors();
});

it('redirects a staff member to the dashboard', function () {
    $staff = User::factory()->create();
    $business = Business::factory()->onboarded()->create();
    attachStaff($business, $staff);

    $page = visit('/login')
        ->type('email', $staff->email)
        ->type('password', 'password')
        ->click('button[type="submit"]');

    $page->assertPathIs('/dashboard')
        ->assertNoJavaScriptErrors();
});

it('redirects a customer-role user to /my-bookings', function () {
    $user = User::factory()->create();
    Customer::factory()->create([
        'user_id' => $user->id,
        'email' => $user->email,
    ]);

    $page = visit('/login')
        ->type('email', $user->email)
        ->type('password', 'password')
        ->click('button[type="submit"]');

    $page->assertPathIs('/my-bookings')
        ->assertNoJavaScriptErrors();
});

it('shows an email-scoped error when the password is wrong', function () {
    $user = User::factory()->create();

    $page = visit('/login')
        ->type('email', $user->email)
        ->type('password', 'wrong-password')
        ->click('button[type="submit"]');

    $page->assertSee('credentials do not match')
        ->assertPathIs('/login')
        ->assertNoJavaScriptErrors();
});

it('redirects an unverified admin to /email/verify after login', function () {
    $admin = User::factory()->unverified()->create();
    $business = Business::factory()->onboarded()->create();
    attachAdmin($business, $admin);

    $page = visit('/login')
        ->type('email', $admin->email)
        ->type('password', 'password')
        ->click('button[type="submit"]');

    // The admin is authenticated; the 'verified' middleware then bounces them.
    $page->navigate('/dashboard')
        ->assertPathIs('/email/verify')
        ->assertSee('Verify your email')
        ->assertNoJavaScriptErrors();
});

it('throttles login after five failed attempts', function () {
    $user = User::factory()->create();

    $page = visit('/login');

    for ($i = 0; $i < 5; $i++) {
        $page->type('email', $user->email)
            ->type('password', 'wrong-password')
            ->click('button[type="submit"]');
    }

    // Sixth attempt — throttle message should appear on the email field.
    $page->type('email', $user->email)
        ->type('password', 'wrong-password')
        ->click('button[type="submit"]');

    $page->assertSee('Too many login attempts')
        ->assertPathIs('/login')
        ->assertNoJavaScriptErrors();
});
