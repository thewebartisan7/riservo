<?php

use App\Enums\BookingStatus;
use App\Enums\PaymentStatus;
use App\Models\Booking;
use App\Models\Business;
use App\Models\StripeConnectedAccount;
use Illuminate\Support\Facades\Artisan;
use Stripe\Checkout\Session as StripeCheckoutSession;
use Stripe\Exception\ApiConnectionException;
use Stripe\Exception\InvalidRequestException;
use Stripe\Exception\RateLimitException;
use Tests\Support\Billing\FakeStripeClient;

/**
 * PAYMENTS Session 2b — reaper tests (locked decisions #13 + #31).
 *
 * Invariants exercised:
 *  - filter on `payment_mode_at_creation = 'online'` — customer_choice
 *    bookings never touched;
 *  - 5-minute grace buffer;
 *  - pre-flight retrieve promotes paid-but-webhook-delayed bookings and
 *    SKIPS the cancel;
 *  - Stripe 5xx leaves the booking for next tick;
 *  - Stripe 4xx (session not found) proceeds with the cancel.
 */
beforeEach(function () {
    $this->business = Business::factory()->onboarded()->create([
        'country' => 'CH',
    ]);
    $this->account = StripeConnectedAccount::factory()->active()->for($this->business)->create([
        'stripe_account_id' => 'acct_test_reaper',
    ]);
});

function reaperBooking(array $overrides = []): Booking
{
    return Booking::factory()->awaitingPayment()->create(array_merge([
        'business_id' => test()->business->id,
        'stripe_checkout_session_id' => 'cs_test_reaper',
        'stripe_connected_account_id' => 'acct_test_reaper',
        'payment_mode_at_creation' => 'online',
        'expires_at' => now()->subMinutes(10), // past the 5-min grace
    ], $overrides));
}

function bindStripeCheckoutRetrieve(callable $withArgs): FakeStripeClient
{
    $fake = FakeStripeClient::bind();
    // The reaper only calls `$stripe->checkout->sessions->retrieve(...)`,
    // so building the chain directly is cleaner than going through the
    // fake's ensureCheckoutSessions() (which is private).
    $checkout = Mockery::mock();
    $sessions = Mockery::mock();
    $checkout->sessions = $sessions;
    $fake->client->checkout = $checkout;

    $withArgs($sessions);

    return $fake;
}

test('reaper cancels an online booking past the grace buffer when Stripe reports expired', function () {
    $booking = reaperBooking();

    bindStripeCheckoutRetrieve(function ($sessions) {
        // Codex Round 3 (F1): assert the Stripe-Account header rides the
        // 3rd positional argument (options), NOT the 2nd (params) —
        // passing as params drops the header and makes Stripe look the
        // session up on the platform account.
        $sessions->shouldReceive('retrieve')
            ->withArgs(fn ($id, $params = null, $opts = null) => $id === 'cs_test_reaper'
                && $params === null
                && is_array($opts)
                && ($opts['stripe_account'] ?? null) === 'acct_test_reaper')
            ->andReturn(StripeCheckoutSession::constructFrom([
                'id' => 'cs_test_reaper',
                'status' => 'expired',
                'payment_status' => 'unpaid',
            ]));
    });

    Artisan::call('bookings:expire-unpaid');

    $booking->refresh();
    expect($booking->status)->toBe(BookingStatus::Cancelled);
    expect($booking->payment_status)->toBe(PaymentStatus::NotApplicable);
    expect($booking->expires_at)->toBeNull();
});

test('reaper leaves customer_choice bookings alone (filter on online-only)', function () {
    $booking = reaperBooking(['payment_mode_at_creation' => 'customer_choice']);

    // No Stripe mock — if the reaper tried to pre-flight, the test
    // would fail with "method not expected".
    FakeStripeClient::bind();

    Artisan::call('bookings:expire-unpaid');

    $booking->refresh();
    expect($booking->status)->toBe(BookingStatus::Pending);
    expect($booking->payment_status)->toBe(PaymentStatus::AwaitingPayment);
});

test('reaper promotes a paid-but-webhook-delayed booking and SKIPS the cancel', function () {
    $booking = reaperBooking();

    bindStripeCheckoutRetrieve(function ($sessions) {
        $sessions->shouldReceive('retrieve')
            ->andReturn(StripeCheckoutSession::constructFrom([
                'id' => 'cs_test_reaper',
                'status' => 'complete',
                'payment_status' => 'paid',
                'amount_total' => 5000,
                'currency' => 'chf',
                'payment_intent' => 'pi_test_reaper',
                'account' => 'acct_test_reaper',
            ]));
    });

    Artisan::call('bookings:expire-unpaid');

    $booking->refresh();
    expect($booking->status)->toBe(BookingStatus::Confirmed);
    expect($booking->payment_status)->toBe(PaymentStatus::Paid);
    expect($booking->stripe_payment_intent_id)->toBe('pi_test_reaper');
});

test('reaper leaves the booking untouched on Stripe 5xx / connection error', function () {
    $booking = reaperBooking();

    bindStripeCheckoutRetrieve(function ($sessions) {
        $sessions->shouldReceive('retrieve')
            ->andThrow(new ApiConnectionException('Network unreachable'));
    });

    Artisan::call('bookings:expire-unpaid');

    $booking->refresh();
    expect($booking->status)->toBe(BookingStatus::Pending);
    expect($booking->payment_status)->toBe(PaymentStatus::AwaitingPayment);
});

test('reaper cancels on Stripe 4xx (session not found)', function () {
    $booking = reaperBooking();

    bindStripeCheckoutRetrieve(function ($sessions) {
        $sessions->shouldReceive('retrieve')
            ->andThrow(new InvalidRequestException('No such checkout session: cs_test_reaper'));
    });

    Artisan::call('bookings:expire-unpaid');

    $booking->refresh();
    expect($booking->status)->toBe(BookingStatus::Cancelled);
    expect($booking->payment_status)->toBe(PaymentStatus::NotApplicable);
});

test('reaper leaves the booking untouched on Stripe 429 rate-limit (Codex Round 2 F1)', function () {
    $booking = reaperBooking();

    bindStripeCheckoutRetrieve(function ($sessions) {
        $sessions->shouldReceive('retrieve')
            ->andThrow(new RateLimitException('Too many requests'));
    });

    Artisan::call('bookings:expire-unpaid');

    $booking->refresh();
    // Rate-limit is transient; cancelling here would release a slot for a
    // booking that's still legitimately awaiting payment.
    expect($booking->status)->toBe(BookingStatus::Pending);
    expect($booking->payment_status)->toBe(PaymentStatus::AwaitingPayment);
});

test('reaper ignores bookings whose expires_at is within the grace buffer', function () {
    $booking = reaperBooking(['expires_at' => now()->subMinutes(2)]); // inside 5-min buffer

    FakeStripeClient::bind();

    Artisan::call('bookings:expire-unpaid');

    $booking->refresh();
    expect($booking->status)->toBe(BookingStatus::Pending);
    expect($booking->payment_status)->toBe(PaymentStatus::AwaitingPayment);
});
