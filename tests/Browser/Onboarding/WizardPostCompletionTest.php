<?php

declare(strict_types=1);

use Tests\Browser\Support\BusinessSetup;

// Covers: post-launch wizard behaviour — `onboarding_completed_at` admins are
// redirected away from `/onboarding/step/*` (D-040, E2E-2).

it('redirects a completed admin away from /onboarding/step/1 to /dashboard', function () {
    ['admin' => $admin] = BusinessSetup::createLaunchedBusiness();

    $this->actingAs($admin);

    visit('/onboarding/step/1')
        ->assertPathIs('/dashboard')
        ->assertNoJavaScriptErrors();
});

it('redirects a completed admin away from /onboarding/step/5 to /dashboard', function () {
    ['admin' => $admin] = BusinessSetup::createLaunchedBusiness();

    $this->actingAs($admin);

    visit('/onboarding/step/5')
        ->assertPathIs('/dashboard')
        ->assertNoJavaScriptErrors();
});
