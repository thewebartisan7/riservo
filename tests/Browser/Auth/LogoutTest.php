<?php

declare(strict_types=1);

use Tests\Browser\Support\BusinessSetup;

// Covers: POST /logout (logout) via the dashboard layout "Log out" control.
//
// NOTE: The buggy AuthHelper (see RegistrationTest / LoginTest note) uses
// `$page->visit(...)` which does not exist on Pest's Webpage API. Tests log
// in inline via the /login form.

it('logs an admin out and cannot re-enter /dashboard without logging back in', function () {
    ['admin' => $admin] = BusinessSetup::createLaunchedBusiness();

    $page = visit('/login')
        ->type('email', $admin->email)
        ->type('password', 'password')
        ->click('button[type="submit"]');

    $page->assertPathIs('/dashboard')
        ->click('button:has-text("Log out")')
        ->assertNoJavaScriptErrors();

    // After logout, /dashboard should redirect to /login.
    $page->navigate('/dashboard')
        ->assertPathIs('/login')
        ->assertSee('Welcome back')
        ->assertNoJavaScriptErrors();
});
