<?php

declare(strict_types=1);

use Tests\Browser\Support\BusinessSetup;

// Covers: automated a11y sweep of booking & onboarding pages (E2E-6).
//
// Uses `assertNoAccessibilityIssues(0)` → axe-core "critical" impact only.
// Several "serious" issues exist today (color-contrast on muted text and the
// primary button) — tracked as follow-ups in the E2E implementation report
// rather than fixed here (test-only session).

it('has no critical accessibility issues on the landing page', function () {
    visit('/')
        ->assertNoAccessibilityIssues(0);
});

it('has no critical accessibility issues on the login page', function () {
    visit('/login')
        ->assertNoAccessibilityIssues(0);
});

it('has no critical accessibility issues on the register page', function () {
    visit('/register')
        ->assertNoAccessibilityIssues(0);
});

it('has no critical accessibility issues on the public booking page', function () {
    ['business' => $business] = BusinessSetup::createLaunchedBusiness();

    visit('/'.$business->slug)
        ->assertNoAccessibilityIssues(0);
});
