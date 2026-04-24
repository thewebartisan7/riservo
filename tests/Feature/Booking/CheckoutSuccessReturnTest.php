<?php

use App\Enums\BookingStatus;
use App\Enums\ConfirmationMode;
use App\Enums\PaymentStatus;
use App\Models\Booking;
use App\Models\Business;
use App\Models\StripeConnectedAccount;
use App\Notifications\BookingConfirmedNotification;
use App\Notifications\BookingReceivedNotification;
use Illuminate\Support\Facades\Notification;
use Stripe\Exception\ApiConnectionException;
use Tests\Support\Billing\FakeStripeClient;

beforeEach(function () {
    Notification::fake();

    $this->business = Business::factory()->onboarded()->create([
        'timezone' => 'Europe/Zurich',
        'country' => 'CH',
        'confirmation_mode' => ConfirmationMode::Auto,
    ]);
    $this->account = StripeConnectedAccount::factory()->active()->for($this->business)->create([
        'stripe_account_id' => 'acct_test_sr',
    ]);
});

function awaitingBooking(): Booking
{
    return Booking::factory()->awaitingPayment()->create([
        'business_id' => test()->business->id,
        'stripe_checkout_session_id' => 'cs_test_return',
        // Codex Round 2 (D-158): pinned minting account id on the booking.
        'stripe_connected_account_id' => 'acct_test_sr',
    ]);
}

test('success page promotes inline when the webhook has not yet run', function () {
    $booking = awaitingBooking();

    FakeStripeClient::for($this)->mockCheckoutSessionRetrieveOnAccount(
        'acct_test_sr',
        'cs_test_return',
        [
            'payment_status' => 'paid',
            'amount_total' => 5000,
            'currency' => 'chf',
            'payment_intent' => 'pi_test_return',
            'client_reference_id' => (string) $booking->id,
            'account' => 'acct_test_sr',
        ],
    );

    $this->get("/bookings/{$booking->cancellation_token}/payment-success?session_id=cs_test_return")
        ->assertRedirect("/bookings/{$booking->cancellation_token}");

    $booking->refresh();
    expect($booking->status)->toBe(BookingStatus::Confirmed)
        ->and($booking->payment_status)->toBe(PaymentStatus::Paid)
        ->and($booking->stripe_payment_intent_id)->toBe('pi_test_return')
        ->and($booking->paid_at)->not->toBeNull();

    Notification::assertSentOnDemand(BookingConfirmedNotification::class);
});

test('success page is a no-op when the webhook already ran', function () {
    $booking = Booking::factory()->paid()->create([
        'business_id' => $this->business->id,
        'stripe_checkout_session_id' => 'cs_test_return',
        'stripe_connected_account_id' => 'acct_test_sr',
    ]);

    // No Stripe retrieve mock — the outcome-level fast path must short-
    // circuit BEFORE hitting Stripe. A wired call would fail the
    // expectation.
    $this->get("/bookings/{$booking->cancellation_token}/payment-success?session_id=cs_test_return")
        ->assertRedirect("/bookings/{$booking->cancellation_token}");

    // No promotion side effects: no fresh notifications.
    Notification::assertNothingSent();
});

test('success page renders processing when Stripe retrieve returns non-paid', function () {
    $booking = awaitingBooking();

    FakeStripeClient::for($this)->mockCheckoutSessionRetrieveOnAccount(
        'acct_test_sr',
        'cs_test_return',
        [
            'payment_status' => 'unpaid',
            'client_reference_id' => (string) $booking->id,
            'account' => 'acct_test_sr',
        ],
    );

    $this->get("/bookings/{$booking->cancellation_token}/payment-success?session_id=cs_test_return")
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('booking/payment-success')
            ->where('state', 'processing'));

    expect($booking->fresh()->payment_status)->toBe(PaymentStatus::AwaitingPayment);
});

test('success page ignores a mismatched session_id and redirects to the booking page', function () {
    $booking = awaitingBooking();

    $this->get("/bookings/{$booking->cancellation_token}/payment-success?session_id=cs_hostile")
        ->assertRedirect("/bookings/{$booking->cancellation_token}");

    expect($booking->fresh()->payment_status)->toBe(PaymentStatus::AwaitingPayment);
});

test('success page without session_id redirects back neutrally', function () {
    $booking = awaitingBooking();

    $this->get("/bookings/{$booking->cancellation_token}/payment-success")
        ->assertRedirect("/bookings/{$booking->cancellation_token}");

    expect($booking->fresh()->payment_status)->toBe(PaymentStatus::AwaitingPayment);
});

test('success page handles Stripe retrieve failure with a processing flash', function () {
    $booking = awaitingBooking();

    $fake = new FakeStripeClient;
    $sessions = Mockery::mock();
    $sessions->shouldReceive('retrieve')->andThrow(new ApiConnectionException('net'));
    $fake->client->checkout = (object) ['sessions' => $sessions];

    $this->get("/bookings/{$booking->cancellation_token}/payment-success?session_id=cs_test_return")
        ->assertRedirect("/bookings/{$booking->cancellation_token}");

    expect($booking->fresh()->payment_status)->toBe(PaymentStatus::AwaitingPayment);
});

test('success page resolves the connected account via withTrashed (disconnect race)', function () {
    $booking = awaitingBooking();
    // Admin disconnected between Checkout creation and customer return.
    // The booking pins the minting account id (D-158), so retrieve
    // reliably targets that account even after the row is trashed.
    $this->account->delete();

    FakeStripeClient::for($this)->mockCheckoutSessionRetrieveOnAccount(
        'acct_test_sr',
        'cs_test_return',
        [
            'payment_status' => 'paid',
            'amount_total' => 5000,
            'currency' => 'chf',
            'payment_intent' => 'pi_test_return',
            'client_reference_id' => (string) $booking->id,
            'account' => 'acct_test_sr',
        ],
    );

    $this->get("/bookings/{$booking->cancellation_token}/payment-success?session_id=cs_test_return")
        ->assertRedirect("/bookings/{$booking->cancellation_token}");

    expect($booking->fresh()->payment_status)->toBe(PaymentStatus::Paid);
});

test('codex round 2 D-160: manual-confirmation flash copy reflects pending, not confirmed', function () {
    $this->business->update(['confirmation_mode' => ConfirmationMode::Manual]);
    $booking = awaitingBooking();

    FakeStripeClient::for($this)->mockCheckoutSessionRetrieveOnAccount(
        'acct_test_sr',
        'cs_test_return',
        [
            'payment_status' => 'paid',
            'amount_total' => 5000,
            'currency' => 'chf',
            'payment_intent' => 'pi_test_return',
            'client_reference_id' => (string) $booking->id,
            'account' => 'acct_test_sr',
        ],
    );

    $response = $this->get("/bookings/{$booking->cancellation_token}/payment-success?session_id=cs_test_return");

    $response->assertRedirect("/bookings/{$booking->cancellation_token}")
        ->assertSessionHas('success', __('Payment received — your booking is pending confirmation from the business.'));

    $booking->refresh();
    expect($booking->status)->toBe(BookingStatus::Pending)
        ->and($booking->payment_status)->toBe(PaymentStatus::Paid);

    // Customer receives the paid_awaiting_confirmation variant, NOT the
    // standard BookingConfirmedNotification (booking is not yet confirmed).
    Notification::assertCount(2); // 1 on-demand (customer), 1 to admins
    Notification::assertSentOnDemand(BookingReceivedNotification::class, function ($notification) {
        return $notification->context === 'paid_awaiting_confirmation';
    });
});

test('codex round 2 D-160: auto-confirmation flash copy says confirmed', function () {
    // Auto-confirmation is the default in this test's beforeEach.
    $booking = awaitingBooking();

    FakeStripeClient::for($this)->mockCheckoutSessionRetrieveOnAccount(
        'acct_test_sr',
        'cs_test_return',
        [
            'payment_status' => 'paid',
            'amount_total' => 5000,
            'currency' => 'chf',
            'payment_intent' => 'pi_test_return',
            'client_reference_id' => (string) $booking->id,
            'account' => 'acct_test_sr',
        ],
    );

    $response = $this->get("/bookings/{$booking->cancellation_token}/payment-success?session_id=cs_test_return");

    $response->assertRedirect("/bookings/{$booking->cancellation_token}")
        ->assertSessionHas('success', __('Payment received — your booking is confirmed.'));
});

test('Cancelled booking landing on the success page does NOT re-activate the slot (Codex Round 3 F2)', function () {
    // Scenario: reaper already cancelled (`Cancelled + NotApplicable`), or
    // late-webhook refund already ran (`Cancelled + Refunded`). Stripe
    // still redirects the customer to the success URL with the same
    // session_id. Without the Cancelled guard, CheckoutPromoter::promote
    // would forceFill status=Confirmed and reopen the slot.
    $booking = Booking::factory()->create([
        'business_id' => $this->business->id,
        'stripe_checkout_session_id' => 'cs_test_return',
        'stripe_connected_account_id' => 'acct_test_sr',
        'payment_mode_at_creation' => 'online',
        'status' => BookingStatus::Cancelled,
        'payment_status' => PaymentStatus::NotApplicable,
    ]);

    // No Stripe mock set up — if the controller tried to call retrieve,
    // Mockery would surface it as "method not expected".
    FakeStripeClient::for($this);

    $response = $this->get("/bookings/{$booking->cancellation_token}/payment-success?session_id=cs_test_return");

    $response->assertRedirect("/bookings/{$booking->cancellation_token}");

    $booking->refresh();
    // Critical: status stays Cancelled, payment_status stays NotApplicable.
    // The promoter was not called; the slot remains released.
    expect($booking->status)->toBe(BookingStatus::Cancelled);
    expect($booking->payment_status)->toBe(PaymentStatus::NotApplicable);

    Notification::assertNothingSent();
});
