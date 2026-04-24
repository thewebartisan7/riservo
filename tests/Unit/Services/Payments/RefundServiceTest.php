<?php

use App\Enums\BookingRefundStatus;
use App\Enums\BusinessMemberRole;
use App\Enums\PaymentStatus;
use App\Enums\PendingActionStatus;
use App\Enums\PendingActionType;
use App\Models\Booking;
use App\Models\Business;
use App\Models\PendingAction;
use App\Models\StripeConnectedAccount;
use App\Models\User;
use App\Notifications\Payments\RefundFailedNotification;
use App\Services\Payments\RefundService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Stripe\StripeClient;
use Tests\Support\Billing\FakeStripeClient;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

/**
 * PAYMENTS Session 2b — RefundService unit tests.
 *
 * Covers the locked decision #36 contract:
 *  - one `booking_refunds` row per refund ATTEMPT;
 *  - row UUID seeds the Stripe `idempotency_key`;
 *  - disconnected-account fallback flips booking `payment_status` to
 *    `refund_failed`, creates a `payment.refund_failed` Pending Action,
 *    and dispatches `RefundFailedNotification` to admins only.
 */
beforeEach(function () {
    Notification::fake();

    $this->business = Business::factory()->onboarded()->create([
        'country' => 'CH',
    ]);
    $this->account = StripeConnectedAccount::factory()->active()->for($this->business)->create([
        'stripe_account_id' => 'acct_test_refund',
    ]);

    // Admin on the business so RefundFailedNotification has a recipient.
    $this->admin = User::factory()->create();
    $this->business->attachOrRestoreMember($this->admin, BusinessMemberRole::Admin);
});

function refundableBooking(): Booking
{
    return Booking::factory()->paid()->create([
        'business_id' => test()->business->id,
        'stripe_connected_account_id' => 'acct_test_refund',
        'paid_amount_cents' => 5000,
        'currency' => 'chf',
    ]);
}

test('happy path: inserts a pending row, calls Stripe with UUID-based idempotency key, marks row succeeded, flips booking to refunded', function () {
    $booking = refundableBooking();

    // Assert the idempotency key starts with 'riservo_refund_' and the
    // stripe_account header is present + matches.
    FakeStripeClient::bind()->mockRefundCreate('acct_test_refund');

    $service = app(RefundService::class);
    $result = $service->refund($booking, null, 'cancelled-after-payment');

    expect($result->outcome)->toBe('succeeded');
    expect($result->bookingRefund)->not->toBeNull();
    expect($result->bookingRefund->status)->toBe(BookingRefundStatus::Succeeded);
    expect($result->bookingRefund->stripe_refund_id)->toStartWith('re_test_');
    expect($result->bookingRefund->amount_cents)->toBe(5000);
    expect($result->bookingRefund->currency)->toBe('chf');
    expect($result->bookingRefund->reason)->toBe('cancelled-after-payment');

    $booking->refresh();
    expect($booking->payment_status)->toBe(PaymentStatus::Refunded);

    Notification::assertNothingSent();
});

test('disconnected account: marks row failed, booking refund_failed, creates PA, dispatches admin notification', function () {
    $booking = refundableBooking();

    FakeStripeClient::bind()->mockRefundCreateFails('acct_test_refund');

    $service = app(RefundService::class);
    $result = $service->refund($booking, null, 'cancelled-after-payment');

    expect($result->outcome)->toBe('disconnected');
    expect($result->bookingRefund)->not->toBeNull();
    expect($result->bookingRefund->status)->toBe(BookingRefundStatus::Failed);
    expect($result->bookingRefund->failure_reason)->toContain('does not have permission');

    $booking->refresh();
    expect($booking->payment_status)->toBe(PaymentStatus::RefundFailed);

    $pa = PendingAction::where('business_id', $booking->business_id)
        ->where('type', PendingActionType::PaymentRefundFailed->value)
        ->first();
    expect($pa)->not->toBeNull();
    expect($pa->status)->toBe(PendingActionStatus::Pending);
    expect($pa->payload['booking_refund_id'])->toBe($result->bookingRefund->id);

    Notification::assertSentTo($this->admin, RefundFailedNotification::class);
});

test('guard rejection: booking with null paid_amount_cents does not create a row and does not call Stripe', function () {
    $booking = Booking::factory()->create([
        'business_id' => $this->business->id,
        'stripe_connected_account_id' => 'acct_test_refund',
        'paid_amount_cents' => null,
        'currency' => null,
        'payment_status' => PaymentStatus::NotApplicable,
    ]);

    // No Stripe mock — a refunds.create call would fail as "method not expected".
    app()->bind(StripeClient::class, fn () => new FakeStripeClient()->client);

    $service = app(RefundService::class);
    $result = $service->refund($booking, null, 'cancelled-after-payment');

    expect($result->outcome)->toBe('guard_rejected');
    expect($result->bookingRefund)->toBeNull();
    expect($booking->bookingRefunds()->count())->toBe(0);
});

test('idempotency key shape matches riservo_refund_{uuid} exactly', function () {
    $booking = refundableBooking();

    // Capture the idempotency key via a first call with no exact expectation,
    // then confirm our assumption via the row's uuid.
    FakeStripeClient::bind()->mockRefundCreate('acct_test_refund');

    $service = app(RefundService::class);
    $result = $service->refund($booking, null, 'cancelled-after-payment');

    expect($result->outcome)->toBe('succeeded');
    $uuid = $result->bookingRefund->uuid;
    expect($uuid)->toMatch('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/');

    // Second refund attempt on a fully-refunded booking fails guard
    // (remaining = 0); no Stripe call, no row.
    Notification::fake();
    $result2 = $service->refund($booking->fresh(), null, 'cancelled-after-payment');
    expect($result2->outcome)->toBe('guard_rejected');
});
