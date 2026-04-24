<?php

use App\Enums\PendingActionStatus;
use App\Enums\PendingActionType;
use App\Models\Booking;
use App\Models\Business;
use App\Models\PendingAction;
use App\Models\Service;
use App\Models\User;

/**
 * PAYMENTS Session 2b — admin payment panel + Pending-Action banner +
 * resolve endpoint (locked roadmap decisions #19 / #31 / #35 / #45).
 */
beforeEach(function () {
    $this->business = Business::factory()->onboarded()->create();
    $this->admin = User::factory()->create();
    attachAdmin($this->business, $this->admin);

    $this->staff = User::factory()->create();
    $this->provider = attachProvider($this->business, $this->staff);

    $this->service = Service::factory()->create([
        'business_id' => $this->business->id,
        'is_active' => true,
    ]);
    $this->provider->services()->attach($this->service);
});

test('admin sees payment panel data on a paid booking in the list payload', function () {
    $booking = Booking::factory()->paid()->create([
        'business_id' => $this->business->id,
        'provider_id' => $this->provider->id,
        'service_id' => $this->service->id,
        'paid_amount_cents' => 5000,
        'currency' => 'chf',
        'stripe_charge_id' => 'ch_test_panel',
        'stripe_connected_account_id' => 'acct_test_panel',
    ]);

    $this->actingAs($this->admin)
        ->get('/dashboard/bookings')
        ->assertSuccessful()
        ->assertInertia(fn ($page) => $page
            ->component('dashboard/bookings')
            ->has('bookings.data', 1)
            ->where('bookings.data.0.payment.status', 'paid')
            ->where('bookings.data.0.payment.paid_amount_cents', 5000)
            ->where('bookings.data.0.payment.currency', 'chf')
            ->where('bookings.data.0.payment.stripe_charge_id', 'ch_test_panel')
            ->where('bookings.data.0.payment.stripe_connected_account_id', 'acct_test_panel')
            ->where('bookings.data.0.pending_payment_action', null)
        );
});

test('admin sees pending_payment_action on a booking that has a cancelled_after_payment PA; resolving marks it resolved', function () {
    $booking = Booking::factory()->paid()->create([
        'business_id' => $this->business->id,
        'provider_id' => $this->provider->id,
        'service_id' => $this->service->id,
    ]);

    $action = PendingAction::create([
        'business_id' => $this->business->id,
        'booking_id' => $booking->id,
        'type' => PendingActionType::PaymentCancelledAfterPayment,
        'payload' => ['booking_id' => $booking->id],
        'status' => PendingActionStatus::Pending,
    ]);

    $this->actingAs($this->admin)
        ->get('/dashboard/bookings')
        ->assertSuccessful()
        ->assertInertia(fn ($page) => $page
            ->where('bookings.data.0.pending_payment_action.id', $action->id)
            ->where('bookings.data.0.pending_payment_action.type', 'payment.cancelled_after_payment')
        );

    $this->actingAs($this->admin)
        ->patch("/dashboard/payment-pending-actions/{$action->id}/resolve")
        ->assertRedirect();

    $action->refresh();
    expect($action->status)->toBe(PendingActionStatus::Resolved);
    expect($action->resolved_by_user_id)->toBe($this->admin->id);
});

test('cross-tenant denial: admin of Business A cannot resolve Business B PA (404)', function () {
    $otherBusiness = Business::factory()->onboarded()->create();
    $otherBooking = Booking::factory()->paid()->create([
        'business_id' => $otherBusiness->id,
    ]);
    $foreignAction = PendingAction::create([
        'business_id' => $otherBusiness->id,
        'booking_id' => $otherBooking->id,
        'type' => PendingActionType::PaymentCancelledAfterPayment,
        'payload' => ['booking_id' => $otherBooking->id],
        'status' => PendingActionStatus::Pending,
    ]);

    $this->actingAs($this->admin)
        ->patch("/dashboard/payment-pending-actions/{$foreignAction->id}/resolve")
        ->assertNotFound();

    $foreignAction->refresh();
    expect($foreignAction->status)->toBe(PendingActionStatus::Pending);
});

test('staff get null payment + pending_payment_action on their own bookings (admin-only leak fix, F2)', function () {
    // Staff sees their own bookings; a paid online booking with a pending
    // PA must NOT surface Stripe ids to them.
    $booking = Booking::factory()->paid()->create([
        'business_id' => $this->business->id,
        'provider_id' => $this->provider->id,
        'service_id' => $this->service->id,
        'stripe_charge_id' => 'ch_secret_panel',
        'stripe_payment_intent_id' => 'pi_secret_panel',
        'stripe_connected_account_id' => 'acct_secret_panel',
    ]);
    PendingAction::create([
        'business_id' => $this->business->id,
        'booking_id' => $booking->id,
        'type' => PendingActionType::PaymentCancelledAfterPayment,
        'payload' => ['booking_id' => $booking->id],
        'status' => PendingActionStatus::Pending,
    ]);

    $this->actingAs($this->staff)
        ->get('/dashboard/bookings')
        ->assertSuccessful()
        ->assertInertia(fn ($page) => $page
            ->component('dashboard/bookings')
            ->where('isAdmin', false)
            ->has('bookings.data', 1)
            ->where('bookings.data.0.payment', null)
            ->where('bookings.data.0.pending_payment_action', null)
        );
});

test('refund_failed PA sorts before cancelled_after_payment in booking payload (F3)', function () {
    // When the late-refund fails, BOTH PAs exist. Admin must see the
    // more-urgent refund_failed banner, not the cancelled_after_payment one.
    $booking = Booking::factory()->paid()->create([
        'business_id' => $this->business->id,
        'provider_id' => $this->provider->id,
        'service_id' => $this->service->id,
    ]);

    // Simulate RefundService::recordFailure creating the refund_failed PA
    // first, then applyLateWebhookRefund creating cancelled_after_payment.
    $cancelledPa = PendingAction::create([
        'business_id' => $this->business->id,
        'booking_id' => $booking->id,
        'type' => PendingActionType::PaymentCancelledAfterPayment,
        'payload' => ['booking_id' => $booking->id],
        'status' => PendingActionStatus::Pending,
    ]);
    $refundFailedPa = PendingAction::create([
        'business_id' => $this->business->id,
        'booking_id' => $booking->id,
        'type' => PendingActionType::PaymentRefundFailed,
        'payload' => ['booking_refund_id' => 1],
        'status' => PendingActionStatus::Pending,
    ]);

    // refundFailedPa.id > cancelledPa.id (was created later) BUT the
    // eager-load orders refund_failed first regardless of id order.
    $this->actingAs($this->admin)
        ->get('/dashboard/bookings')
        ->assertSuccessful()
        ->assertInertia(fn ($page) => $page
            ->where('bookings.data.0.pending_payment_action.id', $refundFailedPa->id)
            ->where('bookings.data.0.pending_payment_action.type', 'payment.refund_failed')
        );
});

test('staff cannot resolve payment Pending Actions (403)', function () {
    $action = PendingAction::create([
        'business_id' => $this->business->id,
        'type' => PendingActionType::PaymentRefundFailed,
        'payload' => ['booking_refund_id' => 1],
        'status' => PendingActionStatus::Pending,
    ]);

    $this->actingAs($this->staff)
        ->patch("/dashboard/payment-pending-actions/{$action->id}/resolve")
        ->assertForbidden();

    $action->refresh();
    expect($action->status)->toBe(PendingActionStatus::Pending);
});
