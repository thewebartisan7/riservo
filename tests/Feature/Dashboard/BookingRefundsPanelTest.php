<?php

/**
 * PAYMENTS Session 3 — admin booking-detail Payment & refunds panel.
 *
 * Asserts the server-side payload shape that `BookingDetailSheet.tsx`
 * consumes (remaining_refundable_cents, refunds[], dispute PA).
 * Admin-only per locked decision #19 — staff receive a nulled payment
 * sub-object and no refund list.
 */

use App\Enums\BusinessMemberRole;
use App\Enums\PaymentStatus;
use App\Enums\PendingActionStatus;
use App\Enums\PendingActionType;
use App\Models\Booking;
use App\Models\BookingRefund;
use App\Models\Business;
use App\Models\PendingAction;
use App\Models\Provider;
use App\Models\Service;
use App\Models\StripeConnectedAccount;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->business = Business::factory()->onboarded()->create(['country' => 'CH']);
    StripeConnectedAccount::factory()->active()->for($this->business)->create([
        'stripe_account_id' => 'acct_test_panel',
    ]);
    $this->admin = User::factory()->create();
    $this->business->attachOrRestoreMember($this->admin, BusinessMemberRole::Admin);

    $provider = Provider::factory()->for($this->business)->create();
    $service = Service::factory()->for($this->business)->create();

    $this->booking = Booking::factory()->paid()->create([
        'business_id' => $this->business->id,
        'provider_id' => $provider->id,
        'service_id' => $service->id,
        'stripe_connected_account_id' => 'acct_test_panel',
        'paid_amount_cents' => 5000,
        'currency' => 'chf',
    ]);
});

test('admin payload exposes remaining_refundable_cents + refunds list sorted latest-first', function () {
    $first = BookingRefund::factory()->succeeded()->for($this->booking)->create([
        'amount_cents' => 2000,
        'currency' => 'chf',
        'reason' => 'customer-requested',
        'stripe_refund_id' => 're_test_one',
    ]);
    $second = BookingRefund::factory()->pending()->for($this->booking)->create([
        'amount_cents' => 1000,
        'currency' => 'chf',
        'reason' => 'admin-manual',
        'initiated_by_user_id' => $this->admin->id,
    ]);

    $response = $this->actingAs($this->admin)
        ->withSession(['current_business_id' => $this->business->id])
        ->get('/dashboard/bookings');

    $response->assertOk();
    $response->assertInertia(
        fn ($page) => $page
            ->where('bookings.data.0.payment.remaining_refundable_cents', 2000) // 5000 - 2000 - 1000
            ->where('bookings.data.0.refunds.0.id', $second->id)  // latest first
            ->where('bookings.data.0.refunds.1.id', $first->id)
            ->where('bookings.data.0.refunds.0.reason', 'admin-manual')
            ->where('bookings.data.0.refunds.1.reason', 'customer-requested')
            ->where('bookings.data.0.refunds.0.initiator_name', $this->admin->name)
            ->where('bookings.data.0.refunds.1.initiator_name', null)
    );
});

test('staff receives null payment + null refunds (locked decision #19)', function () {
    $staffUser = User::factory()->create();
    $this->business->attachOrRestoreMember($staffUser, BusinessMemberRole::Staff);
    // Make staff eligible to see this booking by attaching them as the provider's user.
    $this->booking->provider->update(['user_id' => $staffUser->id]);

    BookingRefund::factory()->succeeded()->for($this->booking)->create([
        'amount_cents' => 1000,
        'currency' => 'chf',
        'reason' => 'admin-manual',
    ]);

    $response = $this->actingAs($staffUser)
        ->withSession(['current_business_id' => $this->business->id])
        ->get('/dashboard/bookings');

    $response->assertOk();
    $response->assertInertia(
        fn ($page) => $page
            ->where('bookings.data.0.payment', null)
            ->where('bookings.data.0.refunds', null)
    );
});

test('dispute PA surfaces in dispute_payment_action when Pending', function () {
    $pa = PendingAction::create([
        'business_id' => $this->business->id,
        'booking_id' => $this->booking->id,
        'type' => PendingActionType::PaymentDisputeOpened,
        'payload' => ['dispute_id' => 'dp_test_x', 'reason' => 'fraudulent'],
        'status' => PendingActionStatus::Pending,
    ]);

    $response = $this->actingAs($this->admin)
        ->withSession(['current_business_id' => $this->business->id])
        ->get('/dashboard/bookings');

    $response->assertOk();
    $response->assertInertia(
        fn ($page) => $page
            ->where('bookings.data.0.dispute_payment_action.id', $pa->id)
            ->where('bookings.data.0.dispute_payment_action.type', 'payment.dispute_opened')
            ->where('bookings.data.0.pending_payment_action', null)
    );
});

test('dispute + refund_failed PAs both surface independently on the booking payload', function () {
    $refundFailed = PendingAction::create([
        'business_id' => $this->business->id,
        'booking_id' => $this->booking->id,
        'type' => PendingActionType::PaymentRefundFailed,
        'payload' => ['booking_refund_id' => 42],
        'status' => PendingActionStatus::Pending,
    ]);

    $dispute = PendingAction::create([
        'business_id' => $this->business->id,
        'booking_id' => $this->booking->id,
        'type' => PendingActionType::PaymentDisputeOpened,
        'payload' => ['dispute_id' => 'dp_both'],
        'status' => PendingActionStatus::Pending,
    ]);

    $response = $this->actingAs($this->admin)
        ->withSession(['current_business_id' => $this->business->id])
        ->get('/dashboard/bookings');

    $response->assertOk();
    $response->assertInertia(
        fn ($page) => $page
            ->where('bookings.data.0.pending_payment_action.id', $refundFailed->id)
            ->where('bookings.data.0.pending_payment_action.type', 'payment.refund_failed')
            ->where('bookings.data.0.dispute_payment_action.id', $dispute->id)
            ->where('bookings.data.0.dispute_payment_action.type', 'payment.dispute_opened')
    );
});

test('remaining_refundable_cents reflects the clamp when a booking is fully refunded', function () {
    BookingRefund::factory()->succeeded()->for($this->booking)->create([
        'amount_cents' => 5000,
        'currency' => 'chf',
        'reason' => 'business-cancelled',
    ]);
    $this->booking->forceFill(['payment_status' => PaymentStatus::Refunded])->save();

    $response = $this->actingAs($this->admin)
        ->withSession(['current_business_id' => $this->business->id])
        ->get('/dashboard/bookings');

    $response->assertOk();
    $response->assertInertia(
        fn ($page) => $page
            ->where('bookings.data.0.payment.remaining_refundable_cents', 0)
    );
});

test('refund-failed PA takes precedence over cancelled-after-payment within the urgent banner bucket', function () {
    PendingAction::create([
        'business_id' => $this->business->id,
        'booking_id' => $this->booking->id,
        'type' => PendingActionType::PaymentCancelledAfterPayment,
        'payload' => ['booking_refund_id' => 1],
        'status' => PendingActionStatus::Pending,
    ]);

    $urgent = PendingAction::create([
        'business_id' => $this->business->id,
        'booking_id' => $this->booking->id,
        'type' => PendingActionType::PaymentRefundFailed,
        'payload' => ['booking_refund_id' => 999],
        'status' => PendingActionStatus::Pending,
    ]);

    $response = $this->actingAs($this->admin)
        ->withSession(['current_business_id' => $this->business->id])
        ->get('/dashboard/bookings');

    $response->assertOk();
    $response->assertInertia(
        fn ($page) => $page->where('bookings.data.0.pending_payment_action.id', $urgent->id)
    );
});
