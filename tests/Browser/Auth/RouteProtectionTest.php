<?php

declare(strict_types=1);

use App\Models\Business;
use App\Models\Customer;
use App\Models\User;
use Tests\Browser\Support\BusinessSetup;

// Covers: middleware boundaries for /dashboard, /dashboard/settings/profile,
// /my-bookings, /dashboard/customers and the onboarded middleware round-trip.

/**
 * Log a user in via the real /login form so the session cookie is established
 * in the browser context. Returns the landing page after the redirect.
 */
function loginViaForm(User $user, string $password = 'password'): mixed
{
    return visit('/login')
        ->type('email', $user->email)
        ->type('password', $password)
        ->click('button[type="submit"]');
}

// ---- Guest redirects ----

it('redirects a guest away from /dashboard to /login', function () {
    $page = visit('/dashboard');

    $page->assertPathIs('/login')
        ->assertNoJavaScriptErrors();
});

it('redirects a guest away from /dashboard/settings/profile to /login', function () {
    $page = visit('/dashboard/settings/profile');

    $page->assertPathIs('/login')
        ->assertNoJavaScriptErrors();
});

it('redirects a guest away from /my-bookings to /login', function () {
    $page = visit('/my-bookings');

    $page->assertPathIs('/login')
        ->assertNoJavaScriptErrors();
});

// ---- Role-based protection (admin-only routes) ----

it('forbids staff from accessing /dashboard/settings/profile (admin-only)', function () {
    ['staff' => $staffCollection] = BusinessSetup::createBusinessWithStaff(1, [
        'onboarding_step' => 5,
        'onboarding_completed_at' => now(),
    ]);
    $staff = $staffCollection->first();

    $page = loginViaForm($staff);

    $page->navigate('/dashboard/settings/profile')
        ->assertSee('403');
});

it('forbids staff from accessing /dashboard/customers (admin-only)', function () {
    ['staff' => $staffCollection] = BusinessSetup::createBusinessWithStaff(1, [
        'onboarding_step' => 5,
        'onboarding_completed_at' => now(),
    ]);
    $staff = $staffCollection->first();

    $page = loginViaForm($staff);

    $page->navigate('/dashboard/customers')
        ->assertSee('403');
});

it('forbids a customer-role user from accessing /dashboard', function () {
    $user = User::factory()->create();
    Customer::factory()->create([
        'user_id' => $user->id,
        'email' => $user->email,
    ]);

    $page = loginViaForm($user);

    $page->navigate('/dashboard')
        ->assertSee('403');
});

// ---- Onboarded middleware round-trip ----

it('redirects an admin with unfinished onboarding from /dashboard to the current onboarding step', function () {
    $admin = User::factory()->create();
    $business = Business::factory()->create([
        'onboarding_step' => 3,
        'onboarding_completed_at' => null,
    ]);
    attachAdmin($business, $admin);

    $page = loginViaForm($admin);

    $page->navigate('/dashboard')
        ->assertPathIs('/onboarding/step/3')
        ->assertNoJavaScriptErrors();
});

it('redirects an onboarded admin away from /onboarding/step/1 to /dashboard', function () {
    ['admin' => $admin] = BusinessSetup::createLaunchedBusiness();

    $page = loginViaForm($admin);

    $page->navigate('/onboarding/step/1')
        ->assertPathIs('/dashboard')
        ->assertNoJavaScriptErrors();
});
