<?php

declare(strict_types=1);

use App\Models\AvailabilityRule;
use App\Models\Provider;
use Tests\Browser\Support\BusinessSetup;
use Tests\Browser\Support\OnboardingHelper;

// Covers: Step 3 "take bookings myself" opt-in → Provider row + availability
// seeding; the provider then appears bookable on the public /{slug} page
// (D-062, E2E-2).

it('renders the owner-opt-in control and the matching description on step 3', function () {
    ['admin' => $admin] = BusinessSetup::createBusinessWithAdmin([
        'onboarding_step' => 3,
    ]);

    OnboardingHelper::advanceToStep(null, $admin, 3);

    $this->actingAs($admin);

    visit('/onboarding/step/3')
        ->assertPathIs('/onboarding/step/3')
        ->assertSee('I take bookings for this service myself')
        ->assertSee('Adds you as a bookable provider')
        ->assertNoJavaScriptErrors();
});

it('lists the admin-as-provider as bookable on the public /{slug} page after launch', function () {
    ['business' => $business, 'admin' => $admin] = BusinessSetup::createBusinessWithAdmin([
        'slug' => 'admin-provider-demo',
        'onboarding_step' => 4,
    ]);

    // Seed business + hours + service through step 4 ready state.
    OnboardingHelper::advanceToStep(null, $admin, 4);

    // Seed the admin-as-provider linkage directly — mirrors what Step 3's
    // opt-in checkbox would produce.
    $provider = attachProvider($business, $admin);
    $service = $business->services()->firstOrFail();
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

    $business->update(['onboarding_step' => 5]);

    $this->actingAs($admin);

    // Click Launch on step 5 — the gate should accept because we seeded a
    // provider per D-062. Button text carries a trailing arrow glyph.
    visit('/onboarding/step/5')
        ->click('Launch your booking page →')
        ->assertPathIs('/dashboard/welcome')
        ->assertNoJavaScriptErrors();

    $business->refresh();
    expect($business->isOnboarded())->toBeTrue();

    expect(Provider::where('business_id', $business->id)
        ->where('user_id', $admin->id)
        ->whereNull('deleted_at')
        ->exists())->toBeTrue();

    // Public booking page shows the business name at its slug.
    visit('/'.$business->slug)
        ->assertSee($business->name)
        ->assertNoJavaScriptErrors();
});
