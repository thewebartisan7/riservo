<?php

use App\Enums\BookingRefundStatus;
use App\Enums\BookingStatus;
use App\Enums\BusinessMemberRole;
use App\Enums\PaymentStatus;
use App\Enums\PendingActionStatus;
use App\Enums\PendingActionType;
use App\Models\Booking;
use App\Models\Business;
use App\Models\PendingAction;
use App\Models\StripeConnectedAccount;
use App\Models\User;
use App\Notifications\Payments\CancelledAfterPaymentNotification;
use App\Notifications\Payments\RefundFailedNotification;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Notification;
use Stripe\Exception\ApiConnectionException;
use Stripe\Refund;
use Tests\Support\Billing\FakeStripeClient;

/**
 * PAYMENTS Session 2b — late-webhook refund tests (locked decision #31.3).
 *
 * `checkout.session.completed` or `payment_intent.succeeded` arriving for
 * a Cancelled booking (reaper already ran) → mark Paid, dispatch auto-
 * refund, flag admins with a Pending Action + email.
 */
beforeEach(function () {
    Notification::fake();
    Cache::flush();
    config(['services.stripe.connect_webhook_secret' => null]);

    $this->business = Business::factory()->onboarded()->create([
        'timezone' => 'Europe/Zurich',
        'country' => 'CH',
    ]);
    $this->account = StripeConnectedAccount::factory()->active()->for($this->business)->create([
        'stripe_account_id' => 'acct_test_late',
    ]);

    $this->admin = User::factory()->create();
    $this->business->attachOrRestoreMember($this->admin, BusinessMemberRole::Admin);
});

function cancelledByReaperBooking(): Booking
{
    return Booking::factory()->create([
        'business_id' => test()->business->id,
        'stripe_checkout_session_id' => 'cs_test_late',
        'stripe_connected_account_id' => 'acct_test_late',
        'payment_mode_at_creation' => 'online',
        'status' => BookingStatus::Cancelled,
        'payment_status' => PaymentStatus::NotApplicable,
        'paid_amount_cents' => 5000,
        'currency' => 'chf',
    ]);
}

function checkoutCompletedLateEvent(Booking $booking, string $piId = 'pi_test_late', ?string $eventId = null): array
{
    return [
        'id' => $eventId ?? 'evt_late_cc_'.uniqid(),
        'object' => 'event',
        'type' => 'checkout.session.completed',
        'account' => 'acct_test_late',
        'data' => [
            'object' => [
                'object' => 'checkout.session',
                'id' => $booking->stripe_checkout_session_id,
                'client_reference_id' => (string) $booking->id,
                'account' => 'acct_test_late',
                'payment_status' => 'paid',
                'amount_total' => 5000,
                'currency' => 'chf',
                'payment_intent' => $piId,
                'latest_charge' => 'ch_test_late',
            ],
        ],
    ];
}

test('checkout.session.completed for a reaper-cancelled booking flips to Paid, dispatches refund, creates PA + admin email', function () {
    $booking = cancelledByReaperBooking();

    FakeStripeClient::bind()->mockRefundCreate('acct_test_late');

    $this->postJson('/webhooks/stripe-connect', checkoutCompletedLateEvent($booking))
        ->assertOk();

    $booking->refresh();
    expect($booking->status)->toBe(BookingStatus::Cancelled); // stays cancelled (slot may be re-booked)
    expect($booking->payment_status)->toBe(PaymentStatus::Refunded);
    expect($booking->stripe_charge_id)->toBe('ch_test_late');
    expect($booking->stripe_payment_intent_id)->toBe('pi_test_late');
    expect($booking->paid_amount_cents)->toBe(5000);
    expect($booking->paid_at)->not->toBeNull();

    $refund = $booking->bookingRefunds()->first();
    expect($refund)->not->toBeNull();
    expect($refund->status)->toBe(BookingRefundStatus::Succeeded);
    expect($refund->stripe_refund_id)->toStartWith('re_test_');
    expect($refund->reason)->toBe('cancelled-after-payment');

    $pa = PendingAction::where('business_id', $booking->business_id)
        ->where('type', PendingActionType::PaymentCancelledAfterPayment->value)
        ->first();
    expect($pa)->not->toBeNull();
    expect($pa->status)->toBe(PendingActionStatus::Pending);
    expect($pa->payload['booking_id'])->toBe($booking->id);

    Notification::assertSentTo($this->admin, CancelledAfterPaymentNotification::class);
});

test('disconnected-account: Stripe permission error lands row failed + PA refund_failed + admin email', function () {
    $booking = cancelledByReaperBooking();

    FakeStripeClient::bind()->mockRefundCreateFails('acct_test_late');

    $this->postJson('/webhooks/stripe-connect', checkoutCompletedLateEvent($booking))
        ->assertOk();

    $booking->refresh();
    expect($booking->status)->toBe(BookingStatus::Cancelled);
    expect($booking->payment_status)->toBe(PaymentStatus::RefundFailed);

    $refund = $booking->bookingRefunds()->first();
    expect($refund->status)->toBe(BookingRefundStatus::Failed);

    $pa = PendingAction::where('business_id', $booking->business_id)
        ->where('type', PendingActionType::PaymentRefundFailed->value)
        ->first();
    expect($pa)->not->toBeNull();

    Notification::assertSentTo($this->admin, RefundFailedNotification::class);
    // The CancelledAfterPayment PA + email also fire — admins get one of each.
    Notification::assertSentTo($this->admin, CancelledAfterPaymentNotification::class);
});

test('replay same checkout.session.completed event id dedupes; no second BookingRefund row', function () {
    $booking = cancelledByReaperBooking();

    FakeStripeClient::bind()->mockRefundCreate('acct_test_late');

    $event = checkoutCompletedLateEvent($booking, eventId: 'evt_same_late');
    $this->postJson('/webhooks/stripe-connect', $event)->assertOk();
    expect($booking->bookingRefunds()->count())->toBe(1);

    // Replay — cache dedup returns the same response without re-running.
    $this->postJson('/webhooks/stripe-connect', $event)->assertOk();
    expect($booking->bookingRefunds()->count())->toBe(1);
});

test('fresh event id after refund already processed short-circuits via outcome guard', function () {
    $booking = cancelledByReaperBooking();

    FakeStripeClient::bind()->mockRefundCreate('acct_test_late');

    $this->postJson('/webhooks/stripe-connect', checkoutCompletedLateEvent($booking))->assertOk();

    // Second delivery with a NEW event id; guard should catch it
    // (payment_status = Refunded is terminal for this path).
    Notification::fake();
    $this->postJson(
        '/webhooks/stripe-connect',
        checkoutCompletedLateEvent($booking, eventId: 'evt_different_late'),
    )->assertOk();

    $booking->refresh();
    expect($booking->bookingRefunds()->count())->toBe(1);
    Notification::assertNothingSent();
});

test('transient Stripe error: row stays pending, booking stays Paid (no refund_failed), webhook returns 503 for retry (Codex Round 2 F2)', function () {
    $booking = cancelledByReaperBooking();

    // Set up a connection-error mock by going through the Mockery chain
    // directly — `FakeStripeClient` doesn't have a built-in "transient
    // failure" helper, but the shape is the same pattern as
    // mockRefundCreateFails for permission errors.
    $fake = FakeStripeClient::bind();
    $refunds = Mockery::mock();
    $refunds->shouldReceive('create')
        ->andThrow(new ApiConnectionException('Connection reset by peer'));
    $fake->client->refunds = $refunds;

    $this->postJson('/webhooks/stripe-connect', checkoutCompletedLateEvent($booking))
        ->assertStatus(503);

    $booking->refresh();
    // Booking flipped to Paid (the state transition ran in its own DB
    // transaction BEFORE the Stripe call) — that's the correct
    // persistent state; the pending refund row is the "retry me" signal.
    expect($booking->payment_status)->toBe(PaymentStatus::Paid);
    expect($booking->status)->toBe(BookingStatus::Cancelled);

    $refund = $booking->bookingRefunds()->first();
    expect($refund)->not->toBeNull();
    expect($refund->status)->toBe(BookingRefundStatus::Pending);
    expect($refund->stripe_refund_id)->toBeNull();

    // No PA, no admin email — transient errors are not terminal.
    expect(PendingAction::where('booking_id', $booking->id)->count())->toBe(0);
    Notification::assertNothingSent();
});

test('transient-error retry: re-delivery reuses the same row UUID so Stripe idempotency collapses the duplicate (Codex Round 2 F2 follow-up)', function () {
    $booking = cancelledByReaperBooking();

    // Single Stripe mock across both posts: first `create` call throws
    // (transient), second call returns a succeeded Refund. Laravel caches
    // controller + RefundService across the test's posts so we can't swap
    // the bound StripeClient between calls — the injected dependency is
    // already wired. Stacking responses on one mock is the reliable
    // pattern.
    $fake = FakeStripeClient::bind();
    $refunds = Mockery::mock();
    $refunds->shouldReceive('create')
        ->once()
        ->andThrow(new ApiConnectionException('transport error'));
    $refunds->shouldReceive('create')
        ->once()
        ->withArgs(function ($params, $opts = []) use (&$capturedIdempotencyKey) {
            $capturedIdempotencyKey = $opts['idempotency_key'] ?? null;

            return ($opts['stripe_account'] ?? null) === 'acct_test_late'
                && is_string($opts['idempotency_key'] ?? null)
                && str_starts_with($opts['idempotency_key'], 'riservo_refund_');
        })
        ->andReturn(Refund::constructFrom([
            'id' => 're_test_retry',
            'status' => 'succeeded',
            'amount' => 5000,
            'currency' => 'chf',
        ]));
    $fake->client->refunds = $refunds;

    // First delivery: transient error, row lands at pending.
    $this->postJson('/webhooks/stripe-connect', checkoutCompletedLateEvent($booking, eventId: 'evt_retry_1'))
        ->assertStatus(503);

    $booking->refresh();
    $pendingRow = $booking->bookingRefunds()->first();
    expect($pendingRow->status)->toBe(BookingRefundStatus::Pending);
    $originalUuid = $pendingRow->uuid;

    // Second delivery: fresh event id → dedup cache misses → retry path
    // finds the pending row, reuses its UUID, Stripe now returns success.
    $this->postJson('/webhooks/stripe-connect', checkoutCompletedLateEvent($booking, eventId: 'evt_retry_2'))
        ->assertOk();

    $booking->refresh();
    expect($booking->payment_status)->toBe(PaymentStatus::Refunded);
    expect($booking->bookingRefunds()->count())->toBe(1); // same row, updated
    expect($booking->bookingRefunds()->first()->uuid)->toBe($originalUuid);
    expect($booking->bookingRefunds()->first()->status)->toBe(BookingRefundStatus::Succeeded);
    // Stripe received the SAME idempotency key on the retry.
    expect($capturedIdempotencyKey)->toBe('riservo_refund_'.$originalUuid);
});

test('late-refund retry after pre-Stripe crash (Paid + no pending row) still dispatches the refund (Codex Round 4 F1)', function () {
    // Simulate the crash-window state: booking was flipped to
    // `Cancelled + Paid` in the first transaction, but the process died
    // before RefundService::refund inserted its booking_refunds row.
    $booking = Booking::factory()->create([
        'business_id' => $this->business->id,
        'stripe_checkout_session_id' => 'cs_test_late',
        'stripe_connected_account_id' => 'acct_test_late',
        'payment_mode_at_creation' => 'online',
        'status' => BookingStatus::Cancelled,
        'payment_status' => PaymentStatus::Paid,
        'paid_amount_cents' => 5000,
        'currency' => 'chf',
        'stripe_payment_intent_id' => 'pi_test_late',
        'stripe_charge_id' => 'ch_test_late',
        'paid_at' => now(),
    ]);

    expect($booking->bookingRefunds()->count())->toBe(0); // crash-window state

    FakeStripeClient::bind()->mockRefundCreate('acct_test_late');

    $this->postJson('/webhooks/stripe-connect', checkoutCompletedLateEvent($booking, eventId: 'evt_crash_recovery'))
        ->assertOk();

    $booking->refresh();
    expect($booking->payment_status)->toBe(PaymentStatus::Refunded);
    expect($booking->bookingRefunds()->count())->toBe(1);
    expect($booking->bookingRefunds()->first()->status)->toBe(BookingRefundStatus::Succeeded);
});

test('Cancelled-branch refuses to refund when the session id does not match the booking (Codex Round 4 F2)', function () {
    $booking = cancelledByReaperBooking();

    // No Stripe mock — Mockery would raise "method not expected" if the
    // handler reached refunds.create.
    FakeStripeClient::bind();

    $event = checkoutCompletedLateEvent($booking);
    // Swap the session id to simulate a different Checkout session on
    // the same connected account reusing this booking's
    // client_reference_id.
    $event['data']['object']['id'] = 'cs_other_session';

    $this->postJson('/webhooks/stripe-connect', $event)->assertOk();

    $booking->refresh();
    // Critical: booking stays Cancelled + NotApplicable; no refund
    // dispatched; no Paid-flip.
    expect($booking->status)->toBe(BookingStatus::Cancelled);
    expect($booking->payment_status)->toBe(PaymentStatus::NotApplicable);
    expect($booking->bookingRefunds()->count())->toBe(0);

    Notification::assertNothingSent();
});

test('late webhook for a booking with a terminal refund outcome (Refunded) no-ops', function () {
    // Codex Round 4 (F1) changed the semantics: only Refunded / RefundFailed
    // are terminal for the late-refund dispatch. `Paid + no pending row`
    // now routes to retry to cover the pre-Stripe crash window (see
    // "late-refund retry after pre-Stripe crash" above).
    $booking = cancelledByReaperBooking();
    $booking->update([
        'payment_status' => PaymentStatus::Refunded,
        'stripe_payment_intent_id' => 'pi_test_late',
        'stripe_charge_id' => 'ch_test_late',
        'paid_at' => now(),
    ]);

    // No Stripe mock — if the handler tried to call refunds.create it
    // would fail the test.
    FakeStripeClient::bind();

    $this->postJson('/webhooks/stripe-connect', checkoutCompletedLateEvent($booking))->assertOk();

    $booking->refresh();
    expect($booking->payment_status)->toBe(PaymentStatus::Refunded);
    expect($booking->bookingRefunds()->count())->toBe(0);
});
