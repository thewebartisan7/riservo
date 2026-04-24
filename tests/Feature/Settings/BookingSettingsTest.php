<?php

use App\Enums\AssignmentStrategy;
use App\Enums\ConfirmationMode;
use App\Enums\PaymentMode;
use App\Models\Business;
use App\Models\StripeConnectedAccount;
use App\Models\User;

beforeEach(function () {
    $this->business = Business::factory()->onboarded()->create();
    $this->admin = User::factory()->create();
    attachAdmin($this->business, $this->admin);
});

test('admin can view booking settings', function () {
    $this->actingAs($this->admin)
        ->get('/dashboard/settings/booking')
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('dashboard/settings/booking')
            ->has('settings')
            // PAYMENTS Session 5: eligibility prop drives the React UI
            // priority-ordered tooltip for disabled non-offline options.
            ->has('paymentEligibility', fn ($block) => $block
                ->where('has_verified_account', false)
                ->where('country_supported', false)
                ->where('can_accept_online_payments', false)
                ->where('connected_account_country', null)
                ->where('supported_countries', ['CH'])
            )
        );
});

test('Settings → Booking eligibility prop reports an active CH account as eligible (Session 5)', function () {
    StripeConnectedAccount::factory()
        ->active()
        ->for($this->business)
        ->create();

    $this->actingAs($this->admin)
        ->get('/dashboard/settings/booking')
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->has('paymentEligibility', fn ($block) => $block
                ->where('has_verified_account', true)
                ->where('country_supported', true)
                ->where('can_accept_online_payments', true)
                ->where('connected_account_country', 'CH')
                ->where('supported_countries', ['CH'])
            )
        );
});

test('Settings → Booking eligibility prop reports a DE active account as ineligible (Session 5, locked decision #43)', function () {
    StripeConnectedAccount::factory()
        ->active()
        ->for($this->business)
        ->create(['country' => 'DE']);

    $this->actingAs($this->admin)
        ->get('/dashboard/settings/booking')
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->has('paymentEligibility', fn ($block) => $block
                ->where('has_verified_account', true)
                ->where('country_supported', false)
                ->where('can_accept_online_payments', false)
                ->where('connected_account_country', 'DE')
                ->where('supported_countries', ['CH'])
            )
        );
});

test('admin can update booking settings', function () {
    $this->actingAs($this->admin)
        ->put('/dashboard/settings/booking', [
            'confirmation_mode' => 'manual',
            'allow_provider_choice' => true,
            'cancellation_window_hours' => 24,
            'payment_mode' => 'offline',
            'assignment_strategy' => 'round_robin',
            'reminder_hours' => [24, 1],
        ])
        ->assertRedirect('/dashboard/settings/booking');

    $this->business->refresh();
    expect($this->business->confirmation_mode)->toBe(ConfirmationMode::Manual);
    expect($this->business->allow_provider_choice)->toBeTrue();
    expect($this->business->cancellation_window_hours)->toBe(24);
    expect($this->business->payment_mode)->toBe(PaymentMode::Offline);
    expect($this->business->assignment_strategy)->toBe(AssignmentStrategy::RoundRobin);
    expect($this->business->reminder_hours)->toBe([24, 1]);
});

test('invalid enum values are rejected', function () {
    $this->actingAs($this->admin)
        ->put('/dashboard/settings/booking', [
            'confirmation_mode' => 'invalid',
            'allow_provider_choice' => true,
            'cancellation_window_hours' => 24,
            'payment_mode' => 'offline',
            'assignment_strategy' => 'first_available',
            'reminder_hours' => [],
        ])
        ->assertSessionHasErrors('confirmation_mode');
});

test('reminder_hours only accepts valid values', function () {
    $this->actingAs($this->admin)
        ->put('/dashboard/settings/booking', [
            'confirmation_mode' => 'auto',
            'allow_provider_choice' => false,
            'cancellation_window_hours' => 0,
            'payment_mode' => 'offline',
            'assignment_strategy' => 'first_available',
            'reminder_hours' => [24, 1],
        ])
        ->assertSessionDoesntHaveErrors();
});

test('empty reminder_hours clears reminders', function () {
    $this->business->update(['reminder_hours' => [24, 1]]);

    $this->actingAs($this->admin)
        ->put('/dashboard/settings/booking', [
            'confirmation_mode' => 'auto',
            'allow_provider_choice' => false,
            'cancellation_window_hours' => 0,
            'payment_mode' => 'offline',
            'assignment_strategy' => 'first_available',
            'reminder_hours' => [],
        ])
        ->assertSessionDoesntHaveErrors();

    expect($this->business->fresh()->reminder_hours)->toBe([]);
});

test('admin CANNOT PUT payment_mode=online when the business has no connected Stripe account (Session 5)', function () {
    // Session 5 gate-lift: non-offline is accepted iff canAcceptOnlinePayments()
    // returns true. No connected account = the helper returns false.
    $this->actingAs($this->admin)
        ->put('/dashboard/settings/booking', [
            'confirmation_mode' => 'auto',
            'allow_provider_choice' => true,
            'cancellation_window_hours' => 24,
            'payment_mode' => 'online',
            'assignment_strategy' => 'first_available',
            'reminder_hours' => [],
        ])
        ->assertSessionHasErrors('payment_mode');

    expect($this->business->fresh()->payment_mode)->toBe(PaymentMode::Offline);
});

test('admin CANNOT PUT payment_mode=customer_choice without a verified connected account (Session 5)', function () {
    $this->actingAs($this->admin)
        ->put('/dashboard/settings/booking', [
            'confirmation_mode' => 'auto',
            'allow_provider_choice' => true,
            'cancellation_window_hours' => 24,
            'payment_mode' => 'customer_choice',
            'assignment_strategy' => 'first_available',
            'reminder_hours' => [],
        ])
        ->assertSessionHasErrors('payment_mode');
});

test('admin can PUT payment_mode=online when the business has a verified CH connected account (Session 5 gate-lift)', function () {
    // D-132's transitional hard-block is retired. Verified CH account =
    // canAcceptOnlinePayments() returns true = non-offline is accepted.
    StripeConnectedAccount::factory()
        ->active()
        ->for($this->business)
        ->create();

    $this->actingAs($this->admin)
        ->put('/dashboard/settings/booking', [
            'confirmation_mode' => 'auto',
            'allow_provider_choice' => true,
            'cancellation_window_hours' => 24,
            'payment_mode' => 'online',
            'assignment_strategy' => 'first_available',
            'reminder_hours' => [],
        ])
        ->assertSessionDoesntHaveErrors();

    expect($this->business->fresh()->payment_mode)->toBe(PaymentMode::Online);
});

test('admin can PUT payment_mode=customer_choice when the business has a verified CH connected account (Session 5)', function () {
    StripeConnectedAccount::factory()
        ->active()
        ->for($this->business)
        ->create();

    $this->actingAs($this->admin)
        ->put('/dashboard/settings/booking', [
            'confirmation_mode' => 'auto',
            'allow_provider_choice' => true,
            'cancellation_window_hours' => 24,
            'payment_mode' => 'customer_choice',
            'assignment_strategy' => 'first_available',
            'reminder_hours' => [],
        ])
        ->assertSessionDoesntHaveErrors();

    expect($this->business->fresh()->payment_mode)->toBe(PaymentMode::CustomerChoice);
});

test('admin CANNOT PUT payment_mode=online when the connected account country is not in config payments.supported_countries (Session 5, locked decision #43)', function () {
    // Defense-in-depth: the client-side UI disables the option with a
    // tooltip, but the server-side validator is the enforcement edge. A
    // direct PUT with a non-CH account is rejected.
    StripeConnectedAccount::factory()
        ->active()
        ->for($this->business)
        ->create(['country' => 'DE']);

    $response = $this->actingAs($this->admin)
        ->put('/dashboard/settings/booking', [
            'confirmation_mode' => 'auto',
            'allow_provider_choice' => true,
            'cancellation_window_hours' => 24,
            'payment_mode' => 'online',
            'assignment_strategy' => 'first_available',
            'reminder_hours' => [],
        ]);

    $response->assertSessionHasErrors('payment_mode');

    // Round-3 P3: the message must match the actual blocker. A verified
    // Stripe account in the wrong country is NOT "Connect Stripe…" — it
    // is the non-CH blocker.
    $errors = session('errors')->get('payment_mode');
    expect($errors[0])->toBe(__('Online payments in MVP support CH-located businesses only.'));

    expect($this->business->fresh()->payment_mode)->toBe(PaymentMode::Offline);
});

test('config flip opens the seam for non-CH accounts (Session 5 proves the gate is config-driven, no hardcoded CH)', function () {
    // Locked decision #43 / D-112 seam-open contract: flipping
    // `config('payments.supported_countries')` is the SINGLE switch. No
    // hardcoded 'CH' literal anywhere. A DE account becomes eligible when
    // DE is added to the supported set.
    config(['payments.supported_countries' => ['CH', 'DE']]);

    StripeConnectedAccount::factory()
        ->active()
        ->for($this->business)
        ->create(['country' => 'DE']);

    $this->actingAs($this->admin)
        ->put('/dashboard/settings/booking', [
            'confirmation_mode' => 'auto',
            'allow_provider_choice' => true,
            'cancellation_window_hours' => 24,
            'payment_mode' => 'online',
            'assignment_strategy' => 'first_available',
            'reminder_hours' => [],
        ])
        ->assertSessionDoesntHaveErrors();

    expect($this->business->fresh()->payment_mode)->toBe(PaymentMode::Online);
});

test('PUT payment_mode passthrough is allowed for already-persisted non-offline values (D-130)', function () {
    // Idempotent passthrough: a PUT that re-sends the currently-persisted
    // value is allowed even if the business is no longer eligible. Keeps
    // the form usable when other fields are edited but the persisted
    // payment_mode is round-tripped from the hidden form input.
    $this->business->forceFill(['payment_mode' => PaymentMode::Online->value])->save();

    $this->actingAs($this->admin)
        ->put('/dashboard/settings/booking', [
            'confirmation_mode' => 'manual',
            'allow_provider_choice' => true,
            'cancellation_window_hours' => 24,
            'payment_mode' => 'online', // same as currently persisted
            'assignment_strategy' => 'first_available',
            'reminder_hours' => [],
        ])
        ->assertSessionDoesntHaveErrors();

    expect($this->business->fresh()->confirmation_mode)->toBe(ConfirmationMode::Manual);
});

test('persisted online payment_mode reads back without error (hidden but round-tripped)', function () {
    // PAYMENTS Session 1 hides `online` / `customer_choice` from the Settings →
    // Booking select until Session 5 lifts the ban (locked roadmap decision #27).
    // A Business row that already carries one of the hidden values — seeded, or
    // set via DB console for dogfooding Session 2a — must still render the
    // settings page without error. The frontend Select silently shows the
    // persisted value as its trigger label even though the popup only lists
    // `offline`. The settings prop round-trips the persisted string.
    $this->business->forceFill(['payment_mode' => PaymentMode::Online->value])->save();

    $this->actingAs($this->admin)
        ->get('/dashboard/settings/booking')
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('dashboard/settings/booking')
            ->where('settings.payment_mode', 'online')
        );
});

test('missing reminder_hours clears reminders', function () {
    $this->business->update(['reminder_hours' => [24]]);

    // When no checkboxes are checked, the field is absent from form data
    $this->actingAs($this->admin)
        ->put('/dashboard/settings/booking', [
            'confirmation_mode' => 'auto',
            'allow_provider_choice' => false,
            'cancellation_window_hours' => 0,
            'payment_mode' => 'offline',
            'assignment_strategy' => 'first_available',
        ])
        ->assertSessionDoesntHaveErrors();

    expect($this->business->fresh()->reminder_hours)->toBe([]);
});
