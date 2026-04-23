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

test('PUT payment_mode=online is rejected when the business has no verified Stripe Connect account (D-130, codex Round 4)', function () {
    // Codex Round 4 finding: the UI hide is cosmetic; the server validator
    // used to accept any PaymentMode enum value. A direct PUT could bypass
    // the rollout gate. Now non-offline values require the business to pass
    // canAcceptOnlinePayments() (which folds in Stripe capabilities + the
    // supported-country gate per D-127).
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

test('PUT payment_mode=customer_choice is rejected without a verified Stripe Connect account (D-130)', function () {
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

test('PUT payment_mode=online is rejected even when the business has a verified Stripe Connect account (D-132, codex Round 5)', function () {
    // Codex Round 5 finding: the prior carve-out that allowed verified
    // Stripe businesses to set non-offline values was a false-ready
    // surface — no booking flow consumes `payment_mode` yet (Session 2a
    // wires Checkout). Verified Stripe is a prerequisite, not a green
    // light. The hard-block applies regardless of Stripe verification
    // until Session 5 ships.
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
        ->assertSessionHasErrors('payment_mode');

    expect($this->business->fresh()->payment_mode)->toBe(PaymentMode::Offline);
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
