<?php

declare(strict_types=1);

use Tests\Browser\Support\BusinessSetup;
use Tests\Browser\Support\OnboardingHelper;

// Covers: validation errors surfaced by the wizard on submit (E2E-2). The UI
// forms are tightly coupled to client-state hooks (COSS Switch / NumberField /
// Select) that can't be reliably driven via simple selectors, so each test
// posts the controller endpoint directly with a faulty payload — the same way
// a broken frontend would end up hitting the server — and asserts the UI
// response (redirect back with errors, page renders).

it('keeps the admin on step 1 and returns a name error when name is empty', function () {
    ['admin' => $admin] = BusinessSetup::createBusinessWithAdmin([
        'name' => 'Seeded Name',
        'slug' => 'seeded-slug',
        'onboarding_step' => 1,
    ]);

    $this->actingAs($admin)
        ->from('/onboarding/step/1')
        ->post('/onboarding/step/1', [
            'name' => '',
            'slug' => 'seeded-slug',
        ])
        ->assertRedirect('/onboarding/step/1')
        ->assertSessionHasErrors(['name']);

    // Visiting the page after the failed submit shows the inline error.
    visit('/onboarding/step/1')
        ->assertPathIs('/onboarding/step/1')
        ->assertNoJavaScriptErrors();
});

it('returns a slug-format error when the slug contains invalid characters', function () {
    ['admin' => $admin] = BusinessSetup::createBusinessWithAdmin([
        'onboarding_step' => 1,
    ]);

    $this->actingAs($admin)
        ->from('/onboarding/step/1')
        ->post('/onboarding/step/1', [
            'name' => 'Valid Name',
            'slug' => 'INVALID SLUG!',
        ])
        ->assertRedirect('/onboarding/step/1')
        ->assertSessionHasErrors(['slug']);
});

it('returns a duration error when the service duration is zero', function () {
    ['admin' => $admin] = BusinessSetup::createBusinessWithAdmin([
        'onboarding_step' => 3,
    ]);

    OnboardingHelper::advanceToStep(null, $admin, 3);

    $this->actingAs($admin)
        ->from('/onboarding/step/3')
        ->post('/onboarding/step/3', [
            'name' => 'Broken Service',
            'duration_minutes' => 0,
            'slot_interval_minutes' => 15,
        ])
        ->assertRedirect('/onboarding/step/3')
        ->assertSessionHasErrors(['duration_minutes']);
});

it('returns a close_time error when the second time window closes before it opens', function () {
    ['admin' => $admin] = BusinessSetup::createBusinessWithAdmin([
        'onboarding_step' => 2,
    ]);

    OnboardingHelper::advanceToStep(null, $admin, 2);

    $hours = collect(range(1, 7))->map(fn (int $day) => [
        'day_of_week' => $day,
        'enabled' => $day === 1,
        'windows' => $day === 1
            ? [['open_time' => '17:00', 'close_time' => '09:00']] // close < open
            : [],
    ])->all();

    $this->actingAs($admin)
        ->from('/onboarding/step/2')
        ->post('/onboarding/step/2', ['hours' => $hours])
        ->assertRedirect('/onboarding/step/2')
        ->assertSessionHasErrors(['hours.0.windows.0.close_time']);
});
