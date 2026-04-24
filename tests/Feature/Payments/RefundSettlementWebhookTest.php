<?php

/**
 * PAYMENTS Session 3 — refund-settlement webhook coverage.
 *
 * Webhook events: `charge.refunded`, `charge.refund.updated`, `refund.updated`.
 * Each converges on `RefundService::recordStripeState` via
 * `booking_refunds.stripe_refund_id` row match (D-171), with a D-158 cross-
 * account guard.
 */

use App\Enums\BookingRefundStatus;
use App\Enums\BusinessMemberRole;
use App\Enums\PaymentStatus;
use App\Enums\PendingActionStatus;
use App\Enums\PendingActionType;
use App\Models\Booking;
use App\Models\BookingRefund;
use App\Models\Business;
use App\Models\PendingAction;
use App\Models\StripeConnectedAccount;
use App\Models\User;
use App\Notifications\Payments\RefundFailedNotification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Notification;
use Tests\Support\Billing\StripeEventBuilder;

uses(RefreshDatabase::class);

beforeEach(function () {
    Notification::fake();
    config(['services.stripe.connect_webhook_secret' => null]);
    Cache::flush();

    $this->business = Business::factory()->onboarded()->create(['country' => 'CH']);
    StripeConnectedAccount::factory()->active()->for($this->business)->create([
        'stripe_account_id' => 'acct_test_settle',
    ]);
    $this->admin = User::factory()->create();
    $this->business->attachOrRestoreMember($this->admin, BusinessMemberRole::Admin);
});

function pendingRefund(array $overrides = []): array
{
    $booking = Booking::factory()->paid()->create([
        'business_id' => test()->business->id,
        'stripe_connected_account_id' => 'acct_test_settle',
        'paid_amount_cents' => 5000,
        'currency' => 'chf',
    ]);

    $refund = BookingRefund::factory()->pending()->for($booking)->create(array_merge([
        'amount_cents' => 5000,
        'currency' => 'chf',
        'stripe_refund_id' => 're_test_settle_'.uniqid(),
        'reason' => 'business-cancelled',
    ], $overrides));

    return [$booking, $refund];
}

test('charge.refunded marks pending row Succeeded + booking Refunded', function () {
    [$booking, $refund] = pendingRefund();

    $response = $this->postJson('/webhooks/stripe-connect', StripeEventBuilder::refundEvent(
        'acct_test_settle',
        'charge.refunded',
        $refund->stripe_refund_id,
        ['amount' => 5000, 'status' => 'succeeded'],
    ));
    $response->assertOk();

    expect($refund->fresh()->status)->toBe(BookingRefundStatus::Succeeded);
    expect($booking->fresh()->payment_status)->toBe(PaymentStatus::Refunded);
});

test('charge.refund.updated with succeeded is idempotent on already-Succeeded row', function () {
    [$booking, $refund] = pendingRefund();
    $refund->forceFill(['status' => BookingRefundStatus::Succeeded])->save();
    $booking->forceFill(['payment_status' => PaymentStatus::Refunded])->save();
    $updatedAtBefore = $refund->fresh()->updated_at;

    $response = $this->postJson('/webhooks/stripe-connect', StripeEventBuilder::refundEvent(
        'acct_test_settle',
        'charge.refund.updated',
        $refund->stripe_refund_id,
        ['status' => 'succeeded'],
    ));
    $response->assertOk();

    expect($refund->fresh()->status)->toBe(BookingRefundStatus::Succeeded);
    expect($refund->fresh()->updated_at->eq($updatedAtBefore))->toBeTrue();
});

test('charge.refund.updated with failed marks row Failed + flips booking refund_failed + PA + admin email', function () {
    [$booking, $refund] = pendingRefund();

    $response = $this->postJson('/webhooks/stripe-connect', StripeEventBuilder::refundEvent(
        'acct_test_settle',
        'charge.refund.updated',
        $refund->stripe_refund_id,
        ['status' => 'failed', 'failure_reason' => 'insufficient_funds'],
    ));
    $response->assertOk();

    expect($refund->fresh()->status)->toBe(BookingRefundStatus::Failed);
    expect($refund->fresh()->failure_reason)->toBe('insufficient_funds');
    expect($booking->fresh()->payment_status)->toBe(PaymentStatus::RefundFailed);

    $pa = PendingAction::where('type', PendingActionType::PaymentRefundFailed->value)->first();
    expect($pa)->not->toBeNull();
    expect($pa->status)->toBe(PendingActionStatus::Pending);

    Notification::assertSentTo($this->admin, RefundFailedNotification::class);
});

test('refund.updated with succeeded settles via stripe_refund_id', function () {
    [$booking, $refund] = pendingRefund();

    $response = $this->postJson('/webhooks/stripe-connect', StripeEventBuilder::refundEvent(
        'acct_test_settle',
        'refund.updated',
        $refund->stripe_refund_id,
        ['status' => 'succeeded'],
    ));
    $response->assertOk();

    expect($refund->fresh()->status)->toBe(BookingRefundStatus::Succeeded);
    expect($booking->fresh()->payment_status)->toBe(PaymentStatus::Refunded);
});

test('refund event for unknown stripe_refund_id logs + 200s, no state change', function () {
    [$booking, $refund] = pendingRefund();

    $response = $this->postJson('/webhooks/stripe-connect', StripeEventBuilder::refundEvent(
        'acct_test_settle',
        'refund.updated',
        're_test_does_not_exist',
        ['status' => 'succeeded'],
    ));
    $response->assertOk();

    expect($refund->fresh()->status)->toBe(BookingRefundStatus::Pending);
    expect($booking->fresh()->payment_status)->toBe(PaymentStatus::Paid);
});

test('cross-account mismatch is refused (D-158 pin)', function () {
    [$booking, $refund] = pendingRefund();

    // Stripe reports a different account id than the booking's pin.
    $response = $this->postJson('/webhooks/stripe-connect', StripeEventBuilder::refundEvent(
        'acct_test_OTHER',
        'refund.updated',
        $refund->stripe_refund_id,
        ['status' => 'succeeded'],
    ));
    $response->assertOk();

    // Row + booking stay untouched — the guard fires.
    expect($refund->fresh()->status)->toBe(BookingRefundStatus::Pending);
    expect($booking->fresh()->payment_status)->toBe(PaymentStatus::Paid);
});

test('partial refund succeeded: booking ends at PartiallyRefunded', function () {
    [$booking, $refund] = pendingRefund(['amount_cents' => 2000]);

    $response = $this->postJson('/webhooks/stripe-connect', StripeEventBuilder::refundEvent(
        'acct_test_settle',
        'charge.refunded',
        $refund->stripe_refund_id,
        ['amount' => 2000, 'status' => 'succeeded'],
    ));
    $response->assertOk();

    expect($refund->fresh()->status)->toBe(BookingRefundStatus::Succeeded);
    expect($booking->fresh()->payment_status)->toBe(PaymentStatus::PartiallyRefunded);
});

test('response-loss fallback resolves row via payment_intent + amount when stripe_refund_id is null', function () {
    // Simulate the response-loss scenario: RefundService bubbled a
    // transient error before it could persist `stripe_refund_id`. The row
    // is Pending with `stripe_refund_id = null`; Stripe actually created
    // the refund and emits `charge.refunded` for it.
    $booking = Booking::factory()->paid()->create([
        'business_id' => $this->business->id,
        'stripe_connected_account_id' => 'acct_test_settle',
        'stripe_payment_intent_id' => 'pi_test_loss_x',
        'paid_amount_cents' => 5000,
        'currency' => 'chf',
    ]);

    $row = BookingRefund::factory()->pending()->for($booking)->create([
        'amount_cents' => 5000,
        'currency' => 'chf',
        'stripe_refund_id' => null,
        'reason' => 'customer-requested',
    ]);

    $response = $this->postJson('/webhooks/stripe-connect', StripeEventBuilder::refundEvent(
        'acct_test_settle',
        'charge.refunded',
        're_test_loss_recovered',
        ['amount' => 5000, 'status' => 'succeeded', 'payment_intent' => 'pi_test_loss_x'],
    ));
    $response->assertOk();

    // Row now carries the backfilled stripe_refund_id + Succeeded status.
    $row->refresh();
    expect($row->stripe_refund_id)->toBe('re_test_loss_recovered');
    expect($row->status)->toBe(BookingRefundStatus::Succeeded);
    expect($booking->fresh()->payment_status)->toBe(PaymentStatus::Refunded);
});

test('payment_intent fallback misses when amount does not match any pending row', function () {
    $booking = Booking::factory()->paid()->create([
        'business_id' => $this->business->id,
        'stripe_connected_account_id' => 'acct_test_settle',
        'stripe_payment_intent_id' => 'pi_test_loss_y',
        'paid_amount_cents' => 5000,
        'currency' => 'chf',
    ]);

    $row = BookingRefund::factory()->pending()->for($booking)->create([
        'amount_cents' => 5000,
        'currency' => 'chf',
        'stripe_refund_id' => null,
        'reason' => 'customer-requested',
    ]);

    // Webhook arrives with a DIFFERENT amount than the pending row.
    $response = $this->postJson('/webhooks/stripe-connect', StripeEventBuilder::refundEvent(
        'acct_test_settle',
        'charge.refunded',
        're_test_wrong_amount',
        ['amount' => 2000, 'status' => 'succeeded', 'payment_intent' => 'pi_test_loss_y'],
    ));
    $response->assertOk();

    // Row untouched — the fallback matches on amount.
    $row->refresh();
    expect($row->stripe_refund_id)->toBeNull();
    expect($row->status)->toBe(BookingRefundStatus::Pending);
});

test('canceled → Failed (D-172)', function () {
    [$booking, $refund] = pendingRefund();

    $response = $this->postJson('/webhooks/stripe-connect', StripeEventBuilder::refundEvent(
        'acct_test_settle',
        'refund.updated',
        $refund->stripe_refund_id,
        ['status' => 'canceled'],
    ));
    $response->assertOk();

    expect($refund->fresh()->status)->toBe(BookingRefundStatus::Failed);
    expect($refund->fresh()->failure_reason)->toBe('Stripe cancelled the refund');
    expect($booking->fresh()->payment_status)->toBe(PaymentStatus::RefundFailed);
});
