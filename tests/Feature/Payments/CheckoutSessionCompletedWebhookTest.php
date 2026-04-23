<?php

use App\Enums\BookingStatus;
use App\Enums\ConfirmationMode;
use App\Enums\PaymentStatus;
use App\Models\Booking;
use App\Models\Business;
use App\Models\StripeConnectedAccount;
use App\Notifications\BookingConfirmedNotification;
use App\Notifications\BookingReceivedNotification;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Notification;

beforeEach(function () {
    Notification::fake();
    Cache::flush();
    // Empty-secret escape hatch mirrors StripeConnectWebhookTest — raw JSON
    // is parsed and treated as the canonical Stripe event.
    config(['services.stripe.connect_webhook_secret' => null]);

    $this->business = Business::factory()->onboarded()->create([
        'timezone' => 'Europe/Zurich',
        'country' => 'CH',
        'confirmation_mode' => ConfirmationMode::Auto,
    ]);
    $this->account = StripeConnectedAccount::factory()->active()->for($this->business)->create([
        'stripe_account_id' => 'acct_test_wh',
    ]);
});

function checkoutCompletedEvent(Booking $booking, array $session = [], ?string $eventId = null): array
{
    return [
        'id' => $eventId ?? 'evt_test_'.uniqid(),
        'object' => 'event',
        'type' => 'checkout.session.completed',
        'account' => 'acct_test_wh',
        'data' => [
            'object' => array_merge([
                'object' => 'checkout.session',
                'id' => $booking->stripe_checkout_session_id,
                'client_reference_id' => (string) $booking->id,
                'account' => 'acct_test_wh',
                'payment_status' => 'paid',
                'amount_total' => 5000,
                'currency' => 'chf',
                'payment_intent' => 'pi_test_wh',
            ], $session),
        ],
    ];
}

function awaitingBookingForWebhook(): Booking
{
    return Booking::factory()->awaitingPayment()->create([
        'business_id' => test()->business->id,
        'stripe_checkout_session_id' => 'cs_test_wh',
        // Codex Round 2 (D-158): minting account id pinned on the booking.
        'stripe_connected_account_id' => 'acct_test_wh',
    ]);
}

test('checkout.session.completed promotes a pending booking to confirmed + paid', function () {
    $booking = awaitingBookingForWebhook();

    $this->postJson('/webhooks/stripe-connect', checkoutCompletedEvent($booking))
        ->assertOk();

    $booking->refresh();
    expect($booking->status)->toBe(BookingStatus::Confirmed)
        ->and($booking->payment_status)->toBe(PaymentStatus::Paid)
        ->and($booking->stripe_payment_intent_id)->toBe('pi_test_wh')
        ->and($booking->paid_amount_cents)->toBe(5000)
        ->and($booking->currency)->toBe('chf')
        ->and($booking->paid_at)->not->toBeNull()
        ->and($booking->expires_at)->toBeNull();

    Notification::assertSentOnDemand(BookingConfirmedNotification::class);
});

test('stale replay (same event id) is cache-deduped with no DB writes', function () {
    $booking = awaitingBookingForWebhook();
    $event = checkoutCompletedEvent($booking, eventId: 'evt_test_same');

    $this->postJson('/webhooks/stripe-connect', $event)->assertOk();

    // Second delivery with the same event id hits the
    // stripe:connect:event: cache.
    Notification::fake(); // reset the fake to assert no re-dispatch
    $this->postJson('/webhooks/stripe-connect', $event)->assertOk();

    Notification::assertNothingSent();
});

test('fresh-event replay on an already-paid booking short-circuits via outcome guard', function () {
    $booking = Booking::factory()->paid()->create([
        'business_id' => $this->business->id,
        'stripe_checkout_session_id' => 'cs_test_wh',
        'stripe_connected_account_id' => 'acct_test_wh',
    ]);

    $this->postJson('/webhooks/stripe-connect', checkoutCompletedEvent($booking, eventId: 'evt_test_fresh'))
        ->assertOk();

    // No new notifications for an already-paid booking.
    Notification::assertNothingSent();
});

test('manual-confirmation business lands at pending + paid', function () {
    $this->business->update(['confirmation_mode' => ConfirmationMode::Manual]);
    $booking = awaitingBookingForWebhook();

    $this->postJson('/webhooks/stripe-connect', checkoutCompletedEvent($booking))
        ->assertOk();

    $booking->refresh();
    expect($booking->status)->toBe(BookingStatus::Pending)
        ->and($booking->payment_status)->toBe(PaymentStatus::Paid);

    // Paid_awaiting_confirmation context, not BookingConfirmedNotification.
    Notification::assertSentOnDemand(BookingReceivedNotification::class, function ($n) {
        return $n->context === 'paid_awaiting_confirmation';
    });
});

test('session with payment_status != paid does not promote (async hedge, decision #41)', function () {
    $booking = awaitingBookingForWebhook();

    $this->postJson('/webhooks/stripe-connect', checkoutCompletedEvent($booking, [
        'payment_status' => 'unpaid',
    ]))->assertOk();

    expect($booking->fresh()->payment_status)->toBe(PaymentStatus::AwaitingPayment);
});

test('checkout.session.async_payment_succeeded event promotes synchronously when payment_status=paid', function () {
    $booking = awaitingBookingForWebhook();

    $event = checkoutCompletedEvent($booking);
    $event['type'] = 'checkout.session.async_payment_succeeded';

    $this->postJson('/webhooks/stripe-connect', $event)->assertOk();

    expect($booking->fresh()->payment_status)->toBe(PaymentStatus::Paid);
});

test('cross-account mismatch (session.account != business account) refuses to promote', function () {
    $booking = awaitingBookingForWebhook();

    $event = checkoutCompletedEvent($booking);
    $event['data']['object']['account'] = 'acct_test_OTHER';
    $event['account'] = 'acct_test_OTHER';

    $this->postJson('/webhooks/stripe-connect', $event)->assertOk();

    expect($booking->fresh()->payment_status)->toBe(PaymentStatus::AwaitingPayment);
});

test('disconnect race: trashed connected account still reconciles via withTrashed', function () {
    $booking = awaitingBookingForWebhook();
    // Admin disconnected between Checkout creation and webhook delivery.
    // Locked decision #36: stripe_account_id is retained on the trashed
    // row so the refund / reconciliation path keeps working.
    $this->account->delete();

    $this->postJson('/webhooks/stripe-connect', checkoutCompletedEvent($booking))
        ->assertOk();

    expect($booking->fresh()->payment_status)->toBe(PaymentStatus::Paid);
});

test('unknown booking id logs and 200s without throwing', function () {
    $event = [
        'id' => 'evt_test_orphan',
        'type' => 'checkout.session.completed',
        'account' => 'acct_test_wh',
        'data' => [
            'object' => [
                'object' => 'checkout.session',
                'id' => 'cs_test_ghost',
                'client_reference_id' => '99999999',
                'account' => 'acct_test_wh',
                'payment_status' => 'paid',
            ],
        ],
    ];

    $this->postJson('/webhooks/stripe-connect', $event)->assertOk();
});

test('cache-prefix isolation: the Connect dedup does not collide with subscription dedup', function () {
    $booking = awaitingBookingForWebhook();
    $eventId = 'evt_test_isolate';

    $this->postJson('/webhooks/stripe-connect', checkoutCompletedEvent($booking, eventId: $eventId))
        ->assertOk();

    // Cache is populated under the Connect prefix only.
    expect(Cache::has('stripe:connect:event:'.$eventId))->toBeTrue()
        ->and(Cache::has('stripe:subscription:event:'.$eventId))->toBeFalse();
});

test('codex round 1 F2: session id mismatch refuses to promote', function () {
    // A session on the SAME connected account whose client_reference_id
    // happens to collide with a riservo booking id but whose session_id
    // does NOT match the one we minted. The promoter must fail closed.
    $booking = awaitingBookingForWebhook();

    $event = checkoutCompletedEvent($booking, [
        'id' => 'cs_test_HOSTILE', // different from booking's cs_test_wh
    ]);

    $this->postJson('/webhooks/stripe-connect', $event)->assertOk();

    // Booking is untouched.
    expect($booking->fresh()->payment_status)->toBe(PaymentStatus::AwaitingPayment)
        ->and($booking->fresh()->stripe_payment_intent_id)->toBeNull();
    Notification::assertNothingSent();
});

test('codex round 1 F2: amount_total mismatch refuses to promote', function () {
    // The booking factory's awaitingPayment() state captures 5000 cents.
    // A session reporting a different amount is pathological — either
    // a Stripe-side error, a coding bug, or a hostile reconciliation.
    // Fail closed; do not silently overwrite (supersedes D-152's
    // "log-and-overwrite" stance per the review Round 1 record).
    $booking = awaitingBookingForWebhook();

    $event = checkoutCompletedEvent($booking, [
        'amount_total' => 9999, // booking.paid_amount_cents = 5000
    ]);

    $this->postJson('/webhooks/stripe-connect', $event)->assertOk();

    expect($booking->fresh()->payment_status)->toBe(PaymentStatus::AwaitingPayment);
    Notification::assertNothingSent();
});

test('codex round 1 F2: currency mismatch refuses to promote', function () {
    $booking = awaitingBookingForWebhook();

    $event = checkoutCompletedEvent($booking, [
        'currency' => 'eur', // booking.currency = 'chf'
    ]);

    $this->postJson('/webhooks/stripe-connect', $event)->assertOk();

    expect($booking->fresh()->payment_status)->toBe(PaymentStatus::AwaitingPayment);
    Notification::assertNothingSent();
});
