<?php

use App\Enums\ConfirmationMode;
use App\Models\Booking;
use App\Models\Business;
use App\Models\StripeConnectedAccount;

/**
 * PAYMENTS Session 2b — cancel_url landing tests.
 *
 * The controller mutates nothing; it only picks flash copy based on the
 * booking's `payment_mode_at_creation` snapshot.
 */
beforeEach(function () {
    $this->business = Business::factory()->onboarded()->create([
        'country' => 'CH',
    ]);
    $this->account = StripeConnectedAccount::factory()->active()->for($this->business)->create([
        'stripe_account_id' => 'acct_test_cancel',
    ]);
});

test('online booking cancel_url redirects to bookings.show with slot-released error flash', function () {
    $booking = Booking::factory()->awaitingPayment()->create([
        'business_id' => $this->business->id,
        'stripe_connected_account_id' => 'acct_test_cancel',
        'payment_mode_at_creation' => 'online',
    ]);

    $response = $this->get("/bookings/{$booking->cancellation_token}/payment-cancel");

    $response->assertRedirect(route('bookings.show', $booking->cancellation_token));
    expect(session('error'))->toContain('slot has been released');
});

test('customer_choice booking cancel_url flashes the confirmed-pay-at-appointment success copy', function () {
    $booking = Booking::factory()->awaitingPayment()->create([
        'business_id' => $this->business->id,
        'stripe_connected_account_id' => 'acct_test_cancel',
        'payment_mode_at_creation' => 'customer_choice',
    ]);

    $response = $this->get("/bookings/{$booking->cancellation_token}/payment-cancel");

    $response->assertRedirect(route('bookings.show', $booking->cancellation_token));
    expect(session('success'))->toContain('pay at the appointment');
});

test('customer_choice + manual-confirm: cancel_url flashes the pending-request copy, not "confirmed" (Codex Round 2 F4)', function () {
    $this->business->forceFill(['confirmation_mode' => ConfirmationMode::Manual])->save();

    $booking = Booking::factory()->awaitingPayment()->create([
        'business_id' => $this->business->id,
        'stripe_connected_account_id' => 'acct_test_cancel',
        'payment_mode_at_creation' => 'customer_choice',
    ]);

    $response = $this->get("/bookings/{$booking->cancellation_token}/payment-cancel");

    $response->assertRedirect(route('bookings.show', $booking->cancellation_token));
    // Webhook failure branch lands this booking at `Pending + Unpaid`, so
    // telling the customer "your booking is confirmed" would contradict
    // the detail page + email.
    expect(session('success'))->toContain('business will confirm');
    expect(session('success'))->not->toContain('is confirmed');
});

test('disconnected-account race: flash asks the customer to contact the business', function () {
    $booking = Booking::factory()->awaitingPayment()->create([
        'business_id' => $this->business->id,
        'stripe_connected_account_id' => 'acct_test_cancel',
        'payment_mode_at_creation' => 'online',
    ]);

    // Soft-delete the connected account to simulate a disconnect between
    // booking creation and customer return.
    $this->account->delete();

    $response = $this->get("/bookings/{$booking->cancellation_token}/payment-cancel");

    $response->assertRedirect(route('bookings.show', $booking->cancellation_token));
    expect(session('error'))->toContain('no longer accepting online payments');
});
