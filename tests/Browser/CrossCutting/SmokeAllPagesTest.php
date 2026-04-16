<?php

declare(strict_types=1);

use Tests\Browser\Support\BusinessSetup;

// Covers: `assertNoSmoke()` (no JS errors, no console logs) on every top-level
// named route reachable by the applicable role (E2E-6).

it('smokes the public landing page', function () {
    visit('/')->assertNoSmoke();
});

it('smokes the auth pages as a guest', function () {
    visit('/login')->assertNoSmoke();
    visit('/register')->assertNoSmoke();
    visit('/magic-link')->assertNoSmoke();
    visit('/forgot-password')->assertNoSmoke();
    visit('/customer-register')->assertNoSmoke();
});

it('smokes the public booking landing for a launched business', function () {
    ['business' => $business] = BusinessSetup::createLaunchedBusiness();

    visit('/'.$business->slug)->assertNoSmoke();
});

it('smokes the admin dashboard pages', function () {
    ['admin' => $admin] = BusinessSetup::createLaunchedBusiness();
    $this->actingAs($admin);

    visit('/dashboard')->assertNoSmoke();
    visit('/dashboard/bookings')->assertNoSmoke();
    visit('/dashboard/calendar')->assertNoSmoke();
    visit('/dashboard/customers')->assertNoSmoke();
});

it('smokes the dashboard settings pages', function () {
    ['admin' => $admin] = BusinessSetup::createLaunchedBusiness();
    $this->actingAs($admin);

    visit('/dashboard/settings/profile')->assertNoSmoke();
    visit('/dashboard/settings/booking')->assertNoSmoke();
    visit('/dashboard/settings/hours')->assertNoSmoke();
    visit('/dashboard/settings/exceptions')->assertNoSmoke();
    visit('/dashboard/settings/services')->assertNoSmoke();
    visit('/dashboard/settings/staff')->assertNoSmoke();
    visit('/dashboard/settings/providers')->assertNoSmoke();
    visit('/dashboard/settings/account')->assertNoSmoke();
    visit('/dashboard/settings/embed')->assertNoSmoke();
});
