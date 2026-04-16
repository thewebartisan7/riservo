<?php

declare(strict_types=1);

use App\Enums\BusinessMemberRole;
use App\Models\BusinessInvitation;
use App\Notifications\InvitationNotification;
use Illuminate\Support\Facades\Notification;
use Tests\Browser\Support\BusinessSetup;
use Tests\Browser\Support\OnboardingHelper;

// Covers: Step 4 staff invitations with pre-assigned service_ids (D-041, E2E-2).
// Driving Step 4's email inputs via Playwright is unreliable because the
// components render Input/Field without a `name` attribute (COSS UI pattern
// for unnamed-controlled inputs). These tests combine a UI-level render check
// with HTTP-level endpoint tests that mirror what the browser POSTs.

beforeEach(function () {
    Notification::fake();
});

it('renders step 4 with a team-invite form and service chips', function () {
    ['business' => $business, 'admin' => $admin] = BusinessSetup::createBusinessWithAdmin([
        'onboarding_step' => 4,
    ]);

    OnboardingHelper::advanceToStep(null, $admin, 4);

    $service = $business->services()->firstOrFail();

    $this->actingAs($admin);

    visit('/onboarding/step/4')
        ->assertPathIs('/onboarding/step/4')
        ->assertSee('Invite team')
        ->assertSee('Team member 1')
        ->assertSee('Continue without inviting')
        ->assertSee('Add another person')
        ->assertSee($service->name)
        ->assertNoJavaScriptErrors();
});

it('creates a BusinessInvitation row when the admin submits a staff email', function () {
    ['business' => $business, 'admin' => $admin] = BusinessSetup::createBusinessWithAdmin([
        'onboarding_step' => 4,
    ]);

    OnboardingHelper::advanceToStep(null, $admin, 4);

    $this->actingAs($admin)
        ->post('/onboarding/step/4', [
            'invitations' => [
                ['email' => 'first-staff@example.com', 'service_ids' => []],
            ],
        ])
        ->assertRedirect('/onboarding/step/5');

    $invitation = BusinessInvitation::where('business_id', $business->id)
        ->where('email', 'first-staff@example.com')
        ->first();

    expect($invitation)->not->toBeNull()
        ->and($invitation->role)->toBe(BusinessMemberRole::Staff)
        ->and($invitation->accepted_at)->toBeNull()
        ->and($invitation->expires_at->isFuture())->toBeTrue();

    Notification::assertSentOnDemand(InvitationNotification::class);
});

it('supports inviting multiple staff members in a single submission', function () {
    ['business' => $business, 'admin' => $admin] = BusinessSetup::createBusinessWithAdmin([
        'onboarding_step' => 4,
    ]);

    OnboardingHelper::advanceToStep(null, $admin, 4);

    $this->actingAs($admin)
        ->post('/onboarding/step/4', [
            'invitations' => [
                ['email' => 'alice@example.com', 'service_ids' => []],
                ['email' => 'bob@example.com', 'service_ids' => []],
            ],
        ])
        ->assertRedirect('/onboarding/step/5');

    expect(BusinessInvitation::where('business_id', $business->id)->count())->toBe(2);
});

it('pre-assigns services to an invitation via service_ids (D-041)', function () {
    ['business' => $business, 'admin' => $admin] = BusinessSetup::createBusinessWithAdmin([
        'onboarding_step' => 4,
    ]);

    OnboardingHelper::advanceToStep(null, $admin, 4);

    $service = $business->services()->firstOrFail();

    $this->actingAs($admin)
        ->post('/onboarding/step/4', [
            'invitations' => [
                ['email' => 'provider-hopeful@example.com', 'service_ids' => [$service->id]],
            ],
        ])
        ->assertRedirect('/onboarding/step/5');

    $invitation = BusinessInvitation::where('business_id', $business->id)
        ->where('email', 'provider-hopeful@example.com')
        ->firstOrFail();

    expect($invitation->service_ids)->toBe([$service->id]);
});
