<?php

declare(strict_types=1);

use Tests\Browser\Support\BusinessSetup;

// Covers: D-040 — wizard resumes on the admin's furthest step when revisiting
// `/dashboard` or `/onboarding/step/1`.

it('lands a returning admin on their current step when they visit /dashboard', function () {
    ['admin' => $admin] = BusinessSetup::createBusinessWithAdmin([
        'onboarding_step' => 3,
    ]);

    $this->actingAs($admin);

    visit('/dashboard')
        ->assertPathIs('/onboarding/step/3')
        ->assertSee('First service')
        ->assertNoJavaScriptErrors();
});

it('rewinds /onboarding/step/1 to the admin\'s furthest completed step', function () {
    ['admin' => $admin] = BusinessSetup::createBusinessWithAdmin([
        'onboarding_step' => 2,
    ]);

    $this->actingAs($admin);

    // Requesting step 1 still renders step 1 (step 1 ≤ current 2 — allowed).
    visit('/onboarding/step/1')
        ->assertPathIs('/onboarding/step/1')
        ->assertNoJavaScriptErrors();

    // But requesting a *later* step than the admin reached rewinds them.
    visit('/onboarding/step/5')
        ->assertPathIs('/onboarding/step/2')
        ->assertNoJavaScriptErrors();
});

it('persists step-1 data so the admin sees their edits on return', function () {
    ['business' => $business, 'admin' => $admin] = BusinessSetup::createBusinessWithAdmin([
        'name' => 'Persist Me',
        'slug' => 'persist-me',
        'onboarding_step' => 1,
    ]);

    $this->actingAs($admin);

    // First session — submit step 1.
    visit('/onboarding/step/1')
        ->type('description', 'A pop-in studio on the hill.')
        ->press('Continue to hours')
        ->assertPathIs('/onboarding/step/2')
        ->assertNoJavaScriptErrors();

    $business->refresh();
    expect($business->description)->toBe('A pop-in studio on the hill.')
        ->and($business->onboarding_step)->toBe(2);

    // Return to step 1 — the description and name are pre-filled.
    visit('/onboarding/step/1')
        ->assertValue('name', 'Persist Me')
        ->assertValue('slug', 'persist-me')
        ->assertSee('A pop-in studio on the hill.')
        ->assertNoJavaScriptErrors();
});
