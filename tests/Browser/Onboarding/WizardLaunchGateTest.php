<?php

declare(strict_types=1);

use App\Models\AvailabilityRule;
use App\Models\Provider;
use Tests\Browser\Support\BusinessSetup;
use Tests\Browser\Support\OnboardingHelper;

// Covers: POST /onboarding/step/5 launch gate + POST /onboarding/enable-owner-as-provider
// one-click recovery (D-062, E2E-2).
//
// NOTE: Step 3 reads `page.props.launchBlocked`, but the controller flashes
// the value via `redirect()->with('launchBlocked', …)` (session flash), and
// no Inertia share middleware bridges session flash → Inertia props. The
// banner therefore never appears in the browser. Until the app bug is fixed
// these tests verify the gate's *behaviour* via redirect target + DB state,
// not via banner copy. Finding logged; not fixed in this session.

it('blocks launch when an active service has zero providers and redirects to step 3', function () {
    ['business' => $business, 'admin' => $admin] = BusinessSetup::createBusinessWithAdmin([
        'onboarding_step' => 5,
    ]);

    OnboardingHelper::advanceToStep(null, $admin, 5);

    $this->actingAs($admin);

    visit('/onboarding/step/5')
        ->click('Launch your booking page →')
        ->assertPathIs('/onboarding/step/3')
        ->assertNoJavaScriptErrors();

    $business->refresh();
    expect($business->onboarding_completed_at)->toBeNull();
});

it('lets the admin recover via enable-owner-as-provider and then launch successfully', function () {
    ['business' => $business, 'admin' => $admin] = BusinessSetup::createBusinessWithAdmin([
        'onboarding_step' => 5,
    ]);

    OnboardingHelper::advanceToStep(null, $admin, 5);

    $this->actingAs($admin);

    // First attempt blocks and redirects to step 3.
    visit('/onboarding/step/5')
        ->click('Launch your booking page →')
        ->assertPathIs('/onboarding/step/3')
        ->assertNoJavaScriptErrors();

    // Hit the recovery endpoint directly. In the app UI this is the
    // "Be your own first provider" button inside the (bugged) launchBlocked
    // banner. The endpoint itself works regardless of the banner visibility.
    $this->post('/onboarding/enable-owner-as-provider')
        ->assertRedirect('/onboarding/step/5');

    $provider = Provider::where('business_id', $business->id)
        ->where('user_id', $admin->id)
        ->firstOrFail();

    $service = $business->services()->firstOrFail();

    expect($provider->trashed())->toBeFalse()
        ->and($provider->services()->where('services.id', $service->id)->exists())->toBeTrue()
        ->and(AvailabilityRule::where('provider_id', $provider->id)->count())->toBeGreaterThan(0);

    // Second launch now succeeds.
    visit('/onboarding/step/5')
        ->click('Launch your booking page →')
        ->assertPathIs('/dashboard/welcome')
        ->assertNoJavaScriptErrors();

    $business->refresh();
    expect($business->isOnboarded())->toBeTrue();
});
