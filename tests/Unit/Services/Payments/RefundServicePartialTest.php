<?php

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
use App\Services\Payments\RefundService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Illuminate\Validation\ValidationException;
use Tests\Support\Billing\FakeStripeClient;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

/**
 * PAYMENTS Session 3 — RefundService partial-refund + settlement tests.
 *
 * Covers:
 *  - `$amountCents` non-null partial refund branch (D-169 422-on-overflow);
 *  - `$initiatedByUserId` persistence on the `booking_refunds` row;
 *  - `recordSettlementSuccess` / `recordSettlementFailure` idempotency;
 *  - `recordStripeState` vocabulary mapping (D-171 + D-172).
 */
beforeEach(function () {
    Notification::fake();

    $this->business = Business::factory()->onboarded()->create(['country' => 'CH']);
    $this->account = StripeConnectedAccount::factory()->active()->for($this->business)->create([
        'stripe_account_id' => 'acct_test_partial',
    ]);
    $this->admin = User::factory()->create();
    $this->business->attachOrRestoreMember($this->admin, BusinessMemberRole::Admin);
});

function partialBooking(): Booking
{
    return Booking::factory()->paid()->create([
        'business_id' => test()->business->id,
        'stripe_connected_account_id' => 'acct_test_partial',
        'paid_amount_cents' => 5000,
        'currency' => 'chf',
    ]);
}

test('partial refund: 2000 of 5000 succeeds and marks booking PartiallyRefunded', function () {
    $booking = partialBooking();

    FakeStripeClient::bind()->mockRefundCreate('acct_test_partial', null, [
        'amount' => 2000,
    ]);

    $result = app(RefundService::class)->refund(
        $booking,
        2000,
        'admin-manual',
        $this->admin->id,
    );

    expect($result->outcome)->toBe('succeeded');
    expect($result->bookingRefund->amount_cents)->toBe(2000);
    expect($result->bookingRefund->status)->toBe(BookingRefundStatus::Succeeded);
    expect($result->bookingRefund->reason)->toBe('admin-manual');
    expect($result->bookingRefund->initiated_by_user_id)->toBe($this->admin->id);
    expect($booking->fresh()->payment_status)->toBe(PaymentStatus::PartiallyRefunded);
    expect($booking->fresh()->remainingRefundableCents())->toBe(3000);
});

test('partial refunds compose: 2000 then 3000 ends at Refunded', function () {
    $booking = partialBooking();

    FakeStripeClient::bind()
        ->mockRefundCreate('acct_test_partial', null, ['id' => 're_test_partial_one', 'amount' => 2000])
        ->mockRefundCreate('acct_test_partial', null, ['id' => 're_test_partial_two', 'amount' => 3000]);

    $svc = app(RefundService::class);
    $svc->refund($booking, 2000, 'admin-manual', $this->admin->id);
    $booking = $booking->fresh();
    $svc->refund($booking, 3000, 'admin-manual', $this->admin->id);

    $booking = $booking->fresh();
    expect($booking->payment_status)->toBe(PaymentStatus::Refunded);
    expect($booking->remainingRefundableCents())->toBe(0);
    expect($booking->bookingRefunds()->count())->toBe(2);
});

test('D-169 overflow: partial refund exceeding remaining throws ValidationException', function () {
    $booking = partialBooking();

    // First partial takes 2000 of 5000.
    FakeStripeClient::bind()->mockRefundCreate('acct_test_partial', null, ['id' => 're_test_overflow_one', 'amount' => 2000]);
    app(RefundService::class)->refund($booking, 2000, 'admin-manual', $this->admin->id);

    $booking = $booking->fresh();
    expect($booking->remainingRefundableCents())->toBe(3000);

    // Second attempt requests 4000 of remaining 3000 → 422.
    $threw = false;
    try {
        app(RefundService::class)->refund($booking, 4000, 'admin-manual', $this->admin->id);
    } catch (ValidationException $e) {
        $threw = true;
        expect($e->errors())->toHaveKey('amount_cents');
        expect($e->errors()['amount_cents'][0])->toContain('30.00'); // max echoed back
    }
    expect($threw)->toBeTrue();

    // No second row written (transaction rolled back).
    expect($booking->bookingRefunds()->count())->toBe(1);
});

test('D-169 does NOT fire for $amountCents = null (full refund); the clamp consumes remaining', function () {
    $booking = partialBooking();

    FakeStripeClient::bind()
        ->mockRefundCreate('acct_test_partial', null, ['id' => 're_test_null_one', 'amount' => 2000])
        ->mockRefundCreate('acct_test_partial', null, ['id' => 're_test_null_two', 'amount' => 3000]);

    app(RefundService::class)->refund($booking, 2000, 'admin-manual', $this->admin->id);

    // Second call with $amountCents = null on a partially-refunded booking
    // refunds exactly the remainder — no exception.
    $result = app(RefundService::class)->refund($booking->fresh(), null, 'business-cancelled');

    expect($result->outcome)->toBe('succeeded');
    expect($result->bookingRefund->amount_cents)->toBe(3000);
    expect($booking->fresh()->payment_status)->toBe(PaymentStatus::Refunded);
});

test('recordSettlementSuccess on already-Succeeded row is a no-op', function () {
    $booking = partialBooking();

    FakeStripeClient::bind()->mockRefundCreate('acct_test_partial');
    $result = app(RefundService::class)->refund($booking, null, 'cancelled-after-payment');

    expect($result->bookingRefund->status)->toBe(BookingRefundStatus::Succeeded);

    // Second call (as if a replayed webhook arrived) with a different id should
    // not re-stamp the row or re-run reconcilePaymentStatus.
    $statusBefore = $booking->fresh()->payment_status;
    $originalStripeId = $result->bookingRefund->stripe_refund_id;

    app(RefundService::class)->recordSettlementSuccess($result->bookingRefund->fresh(), 're_test_should_not_overwrite');

    expect($result->bookingRefund->fresh()->stripe_refund_id)->toBe($originalStripeId);
    expect($booking->fresh()->payment_status)->toBe($statusBefore);
});

test('recordSettlementFailure from Pending: flips row Failed + booking refund_failed + PA + email', function () {
    $booking = partialBooking();
    $row = BookingRefund::factory()->pending()->for($booking)->create([
        'amount_cents' => 5000,
        'currency' => 'chf',
        'reason' => 'business-cancelled',
    ]);

    app(RefundService::class)->recordSettlementFailure($row, 'Stripe reported failed');

    expect($row->fresh()->status)->toBe(BookingRefundStatus::Failed);
    expect($row->fresh()->failure_reason)->toBe('Stripe reported failed');
    expect($booking->fresh()->payment_status)->toBe(PaymentStatus::RefundFailed);

    $pa = PendingAction::where('business_id', $booking->business_id)
        ->where('type', PendingActionType::PaymentRefundFailed->value)
        ->first();
    expect($pa)->not->toBeNull();
    expect($pa->status)->toBe(PendingActionStatus::Pending);
    expect($pa->payload['booking_refund_id'])->toBe($row->id);

    Notification::assertSentTo($this->admin, RefundFailedNotification::class);
});

test('recordSettlementFailure on already-Failed row is a no-op', function () {
    $booking = partialBooking();
    $row = BookingRefund::factory()->failed()->for($booking)->create([
        'amount_cents' => 5000,
        'currency' => 'chf',
        'failure_reason' => 'first failure',
        'reason' => 'business-cancelled',
    ]);

    app(RefundService::class)->recordSettlementFailure($row, 'second failure');

    expect($row->fresh()->failure_reason)->toBe('first failure');
});

test('recordStripeState maps succeeded → Succeeded', function () {
    $booking = partialBooking();
    $row = BookingRefund::factory()->pending()->for($booking)->create([
        'amount_cents' => 5000,
        'currency' => 'chf',
        'stripe_refund_id' => 're_test_abc',
        'reason' => 'business-cancelled',
    ]);

    app(RefundService::class)->recordStripeState($row, 'succeeded');

    expect($row->fresh()->status)->toBe(BookingRefundStatus::Succeeded);
    expect($booking->fresh()->payment_status)->toBe(PaymentStatus::Refunded);
});

test('recordStripeState maps canceled → Failed (D-172)', function () {
    $booking = partialBooking();
    $row = BookingRefund::factory()->pending()->for($booking)->create([
        'amount_cents' => 5000,
        'currency' => 'chf',
        'reason' => 'business-cancelled',
    ]);

    app(RefundService::class)->recordStripeState($row, 'canceled');

    expect($row->fresh()->status)->toBe(BookingRefundStatus::Failed);
    expect($row->fresh()->failure_reason)->toBe('Stripe cancelled the refund');
    expect($booking->fresh()->payment_status)->toBe(PaymentStatus::RefundFailed);
});

test('recordStripeState on requires_action/pending leaves row Pending', function () {
    $booking = partialBooking();
    $row = BookingRefund::factory()->pending()->for($booking)->create([
        'amount_cents' => 5000,
        'currency' => 'chf',
        'reason' => 'business-cancelled',
    ]);

    app(RefundService::class)->recordStripeState($row, 'requires_action');
    expect($row->fresh()->status)->toBe(BookingRefundStatus::Pending);

    app(RefundService::class)->recordStripeState($row, 'pending');
    expect($row->fresh()->status)->toBe(BookingRefundStatus::Pending);
});

test('recordStripeState failed → Failed with failure_reason from Stripe', function () {
    $booking = partialBooking();
    $row = BookingRefund::factory()->pending()->for($booking)->create([
        'amount_cents' => 5000,
        'currency' => 'chf',
        'reason' => 'business-cancelled',
    ]);

    app(RefundService::class)->recordStripeState($row, 'failed', 'insufficient_funds');

    expect($row->fresh()->status)->toBe(BookingRefundStatus::Failed);
    expect($row->fresh()->failure_reason)->toBe('insufficient_funds');
});
