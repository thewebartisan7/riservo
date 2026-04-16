<?php

declare(strict_types=1);

use App\Models\AvailabilityRule;
use Tests\Browser\Support\BusinessSetup;
use Tests\Browser\Support\OnboardingHelper;

// Covers: GET/POST /onboarding/step/{1..5} — end-to-end wizard walk-through
// (E2E-2). The complex widgets on steps 2, 3 and 4 (COSS Switch, COSS Select
// for time windows, Input without `name` for invitations) cannot be driven
// reliably via Playwright selectors, so the helper seeds equivalent state and
// the browser exercises the observable boundaries — Step 1 submission,
// Step 5 summary, and the final Launch click.

it('walks an admin from step 1 through launch', function () {
    ['business' => $business, 'admin' => $admin] = BusinessSetup::createBusinessWithAdmin([
        'name' => 'Atelier Zurich',
        'slug' => 'atelier-zurich',
        'onboarding_step' => 1,
    ]);

    $this->actingAs($admin);

    // Step 1 — drive via UI.
    $page = visit('/onboarding/step/1');

    $page->assertPathIs('/onboarding/step/1')
        ->assertSee('Business profile')
        ->assertSee('Set the stage for your studio')
        ->assertValue('name', 'Atelier Zurich')
        ->assertValue('slug', 'atelier-zurich')
        ->type('description', 'A cosy corner for personal services.')
        ->type('phone', '+41 79 123 4567')
        ->type('email', 'hello@atelier-zurich.ch')
        ->type('address', 'Bahnhofstrasse 1, 8001 Zurich')
        ->press('Continue to hours')
        ->assertPathIs('/onboarding/step/2')
        ->assertSee('Working hours')
        ->assertSee('When are you open?')
        ->assertNoJavaScriptErrors();

    $business->refresh();
    expect($business->description)->toBe('A cosy corner for personal services.')
        ->and($business->phone)->toBe('+41 79 123 4567')
        ->and($business->onboarding_step)->toBe(2);

    // Steps 2–4 — seed via the helper. attachProvider() pre-creates the
    // owner-as-provider linkage so Step 5 launch will succeed.
    OnboardingHelper::advanceToStep(null, $admin, 5);

    $service = $business->services()->firstOrFail();
    $provider = attachProvider($business, $admin);
    $provider->services()->attach($service);

    foreach ([1, 2, 3, 4, 5] as $day) {
        AvailabilityRule::create([
            'provider_id' => $provider->id,
            'business_id' => $business->id,
            'day_of_week' => $day,
            'start_time' => '09:00',
            'end_time' => '18:00',
        ]);
    }

    // Step 5 — review page renders business URL + summary, Launch succeeds.
    $step5 = visit('/onboarding/step/5')
        ->assertPathIs('/onboarding/step/5')
        ->assertSee('Review & launch')
        ->assertSee('atelier-zurich')
        ->assertSee('Copy link');

    $step5->click('Launch your booking page →')
        ->assertPathIs('/dashboard/welcome')
        ->assertSee('Atelier Zurich')
        ->assertSee('is live.')
        ->assertNoJavaScriptErrors();

    $business->refresh();
    expect($business->onboarding_step)->toBe(5)
        ->and($business->isOnboarded())->toBeTrue()
        ->and($business->onboarding_completed_at)->not->toBeNull();
});

it('keeps the admin anchored to step 1 when the business has no prior progress', function () {
    ['admin' => $admin] = BusinessSetup::createBusinessWithAdmin([
        'onboarding_step' => 1,
    ]);

    $this->actingAs($admin);

    visit('/onboarding/step/1')
        ->assertPathIs('/onboarding/step/1')
        ->assertSee('Business profile')
        ->assertNoJavaScriptErrors();
});

it('redirects forward requests to the furthest completed step', function () {
    ['admin' => $admin] = BusinessSetup::createBusinessWithAdmin([
        'onboarding_step' => 2,
    ]);

    $this->actingAs($admin);

    // Admin only reached step 2; requesting step 4 rewinds to step 2.
    visit('/onboarding/step/4')
        ->assertPathIs('/onboarding/step/2')
        ->assertNoJavaScriptErrors();
});
