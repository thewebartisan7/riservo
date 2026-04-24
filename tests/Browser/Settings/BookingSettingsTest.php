<?php

declare(strict_types=1);

use App\Enums\ConfirmationMode;
use App\Models\StripeConnectedAccount;
use Tests\Browser\Support\BusinessSetup;

// Covers: settings.booking, settings.booking.update (E2E-5).
//
// HTTP-only tests are placed before the browser test to avoid RefreshDatabase
// flakiness with the Pest Browser plugin: a browser `visit()` call after
// several `$this->actingAs()->put()` calls can leave the test DB connection
// in a non-transacting state, forcing migrate:fresh to re-run on the next
// test. Ordering HTTP-first keeps the schema stable for the browser test.

it('updates confirmation_mode and persists the change', function () {
    ['business' => $business, 'admin' => $admin] = BusinessSetup::createLaunchedBusiness([
        'confirmation_mode' => ConfirmationMode::Auto,
    ]);

    $this->actingAs($admin)
        ->put('/dashboard/settings/booking', [
            'confirmation_mode' => 'manual',
            'allow_provider_choice' => '1',
            'cancellation_window_hours' => 24,
            'payment_mode' => 'offline',
            'assignment_strategy' => 'first_available',
            'reminder_hours' => [24, 1],
        ])
        ->assertRedirect('/dashboard/settings/booking');

    expect($business->fresh()->confirmation_mode)->toBe(ConfirmationMode::Manual);
});

it('turns off allow_provider_choice so the public page skips the provider step', function () {
    ['business' => $business, 'admin' => $admin] = BusinessSetup::createLaunchedBusiness([
        'allow_provider_choice' => true,
    ]);

    $this->actingAs($admin)
        ->put('/dashboard/settings/booking', [
            'confirmation_mode' => 'auto',
            'allow_provider_choice' => '0',
            'cancellation_window_hours' => 24,
            'payment_mode' => 'offline',
            'assignment_strategy' => 'first_available',
            'reminder_hours' => [24, 1],
        ])
        ->assertRedirect('/dashboard/settings/booking');

    expect($business->fresh()->allow_provider_choice)->toBeFalse();
});

it('updates cancellation_window_hours and persists the change', function () {
    ['business' => $business, 'admin' => $admin] = BusinessSetup::createLaunchedBusiness([
        'cancellation_window_hours' => 24,
    ]);

    $this->actingAs($admin)
        ->put('/dashboard/settings/booking', [
            'confirmation_mode' => 'auto',
            'allow_provider_choice' => '1',
            'cancellation_window_hours' => 4,
            'payment_mode' => 'offline',
            'assignment_strategy' => 'first_available',
            'reminder_hours' => [24, 1],
        ])
        ->assertRedirect('/dashboard/settings/booking');

    expect($business->fresh()->cancellation_window_hours)->toBe(4);
});

it('updates reminder_hours to a narrower set', function () {
    ['business' => $business, 'admin' => $admin] = BusinessSetup::createLaunchedBusiness([
        'reminder_hours' => [24, 1],
    ]);

    $this->actingAs($admin)
        ->put('/dashboard/settings/booking', [
            'confirmation_mode' => 'auto',
            'allow_provider_choice' => '1',
            'cancellation_window_hours' => 24,
            'payment_mode' => 'offline',
            'assignment_strategy' => 'first_available',
            'reminder_hours' => [24],
        ])
        ->assertRedirect('/dashboard/settings/booking');

    expect($business->fresh()->reminder_hours)->toBe([24]);
});

it('updates payment_mode to online when the business has a verified CH connected account', function () {
    // PAYMENTS Session 5 gate-lift: the `online` option requires the
    // business to pass canAcceptOnlinePayments(). Seed a CH-active
    // connected account so the validator accepts the change.
    ['business' => $business, 'admin' => $admin] = BusinessSetup::createLaunchedBusiness();
    StripeConnectedAccount::factory()->active()->for($business)->create();

    $this->actingAs($admin)
        ->put('/dashboard/settings/booking', [
            'confirmation_mode' => 'auto',
            'allow_provider_choice' => '1',
            'cancellation_window_hours' => 24,
            'payment_mode' => 'online',
            'assignment_strategy' => 'first_available',
            'reminder_hours' => [24, 1],
        ])
        ->assertRedirect('/dashboard/settings/booking');

    expect($business->fresh()->payment_mode->value)->toBe('online');
});

it('rejects invalid cancellation_window_hours with a validation error', function () {
    ['admin' => $admin] = BusinessSetup::createLaunchedBusiness();

    $this->actingAs($admin)
        ->put('/dashboard/settings/booking', [
            'confirmation_mode' => 'auto',
            'allow_provider_choice' => '1',
            'cancellation_window_hours' => 999,
            'payment_mode' => 'offline',
            'assignment_strategy' => 'first_available',
            'reminder_hours' => [24, 1],
        ])
        ->assertSessionHasErrors('cancellation_window_hours');
});

it('denies staff members with a 403', function () {
    ['staff' => $staffCollection] = BusinessSetup::createBusinessWithStaff(
        1,
        ['onboarding_step' => 5, 'onboarding_completed_at' => now()],
    );
    $staff = $staffCollection->first();

    $this->actingAs($staff)->get('/dashboard/settings/booking')->assertForbidden();
});

it('renders the booking settings page with the current values', function () {
    ['admin' => $admin] = BusinessSetup::createLaunchedBusiness([
        'cancellation_window_hours' => 24,
        'allow_provider_choice' => true,
    ]);

    $this->actingAs($admin);

    $page = visit('/dashboard/settings/booking');
    $page->assertPathIs('/dashboard/settings/booking')
        ->assertSee('Booking rules')
        ->assertSee('Confirmation mode')
        ->assertSee('Cancellation window')
        ->assertNoJavaScriptErrors();
});
