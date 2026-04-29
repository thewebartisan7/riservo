<?php

/**
 * PAYMENTS Hardening Round 2 — D-184 / G-001 verification.
 *
 * Three admin-only redirect endpoints replace the prior pattern of riding
 * raw Stripe object IDs through the booking-detail Inertia prop. Tests:
 *  - happy path 302 (each kind: payment / refund / dispute);
 *  - admin-only role gate (staff → 403);
 *  - tenant scoping (cross-tenant booking → 404);
 *  - missing-id 404 (booking has no Stripe handle);
 *  - cross-booking refund row → 404;
 *  - dispute id is read from the PA, never from the URL.
 */

use App\Enums\BookingStatus;
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
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->business = Business::factory()->onboarded()->create(['country' => 'CH']);
    StripeConnectedAccount::factory()->active()->for($this->business)->create([
        'stripe_account_id' => 'acct_test_dl',
    ]);
    $this->admin = User::factory()->create();
    $this->business->attachOrRestoreMember($this->admin, BusinessMemberRole::Admin);
});

function paidBookingWithCharge(Business $business, array $overrides = []): Booking
{
    return Booking::factory()->paid()->create(array_merge([
        'business_id' => $business->id,
        'stripe_connected_account_id' => 'acct_test_dl',
        'stripe_charge_id' => 'ch_test_'.uniqid(),
        'stripe_payment_intent_id' => 'pi_test_'.uniqid(),
        'paid_amount_cents' => 5000,
        'currency' => 'chf',
    ], $overrides));
}

test('payment deeplink: 302 to Stripe payments page using charge id', function () {
    $booking = paidBookingWithCharge($this->business, ['stripe_charge_id' => 'ch_test_payment_deeplink']);

    $this->actingAs($this->admin)
        ->withSession(['current_business_id' => $this->business->id])
        ->get("/dashboard/bookings/{$booking->id}/stripe-link/payment")
        ->assertRedirect('https://dashboard.stripe.com/acct_test_dl/payments/ch_test_payment_deeplink');
});

test('payment deeplink: falls back to payment_intent when charge id is missing', function () {
    $booking = paidBookingWithCharge($this->business, [
        'stripe_charge_id' => null,
        'stripe_payment_intent_id' => 'pi_test_intent_fallback',
    ]);

    $this->actingAs($this->admin)
        ->withSession(['current_business_id' => $this->business->id])
        ->get("/dashboard/bookings/{$booking->id}/stripe-link/payment")
        ->assertRedirect('https://dashboard.stripe.com/acct_test_dl/payments/pi_test_intent_fallback');
});

test('payment deeplink: 404 when the booking has no Stripe handle yet', function () {
    $booking = Booking::factory()->create([
        'business_id' => $this->business->id,
        'status' => BookingStatus::Confirmed,
        'payment_status' => PaymentStatus::NotApplicable,
    ]);

    $this->actingAs($this->admin)
        ->withSession(['current_business_id' => $this->business->id])
        ->get("/dashboard/bookings/{$booking->id}/stripe-link/payment")
        ->assertNotFound();
});

test('payment deeplink: 403 for staff', function () {
    $booking = paidBookingWithCharge($this->business);
    $staff = User::factory()->create();
    $this->business->attachOrRestoreMember($staff, BusinessMemberRole::Staff);

    $this->actingAs($staff)
        ->withSession(['current_business_id' => $this->business->id])
        ->get("/dashboard/bookings/{$booking->id}/stripe-link/payment")
        ->assertForbidden();
});

test('payment deeplink: 404 for cross-tenant access', function () {
    $other = Business::factory()->onboarded()->create(['country' => 'CH']);
    $otherAdmin = User::factory()->create();
    $other->attachOrRestoreMember($otherAdmin, BusinessMemberRole::Admin);

    $bookingInOurs = paidBookingWithCharge($this->business);

    $this->actingAs($otherAdmin)
        ->withSession(['current_business_id' => $other->id])
        ->get("/dashboard/bookings/{$bookingInOurs->id}/stripe-link/payment")
        ->assertNotFound();
});

test('refund deeplink: 302 to Stripe refunds page', function () {
    $booking = paidBookingWithCharge($this->business);
    $refund = BookingRefund::factory()->for($booking)->create([
        'amount_cents' => 5000,
        'currency' => 'chf',
        'stripe_refund_id' => 're_test_deeplink',
        'reason' => 'admin-manual',
    ]);

    $this->actingAs($this->admin)
        ->withSession(['current_business_id' => $this->business->id])
        ->get("/dashboard/bookings/{$booking->id}/stripe-link/refund/{$refund->id}")
        ->assertRedirect('https://dashboard.stripe.com/acct_test_dl/refunds/re_test_deeplink');
});

test('refund deeplink: 404 when nesting a refund row from a different booking', function () {
    $bookingA = paidBookingWithCharge($this->business);
    $bookingB = paidBookingWithCharge($this->business);
    $refundForB = BookingRefund::factory()->for($bookingB)->create([
        'amount_cents' => 1000,
        'currency' => 'chf',
        'stripe_refund_id' => 're_test_other',
        'reason' => 'admin-manual',
    ]);

    // URL nests bookingA + refund row that belongs to bookingB.
    $this->actingAs($this->admin)
        ->withSession(['current_business_id' => $this->business->id])
        ->get("/dashboard/bookings/{$bookingA->id}/stripe-link/refund/{$refundForB->id}")
        ->assertNotFound();
});

test('refund deeplink: 404 when stripe_refund_id is null (response-loss row)', function () {
    $booking = paidBookingWithCharge($this->business);
    $refund = BookingRefund::factory()->for($booking)->create([
        'amount_cents' => 5000,
        'currency' => 'chf',
        'stripe_refund_id' => null,
        'reason' => 'admin-manual',
    ]);

    $this->actingAs($this->admin)
        ->withSession(['current_business_id' => $this->business->id])
        ->get("/dashboard/bookings/{$booking->id}/stripe-link/refund/{$refund->id}")
        ->assertNotFound();
});

test('dispute deeplink: 302 to Stripe disputes page using id from the PA payload', function () {
    $booking = paidBookingWithCharge($this->business);

    PendingAction::create([
        'business_id' => $this->business->id,
        'booking_id' => $booking->id,
        'type' => PendingActionType::PaymentDisputeOpened,
        'payload' => ['dispute_id' => 'dp_test_deeplink', 'reason' => 'fraudulent'],
        'status' => PendingActionStatus::Pending,
    ]);

    $this->actingAs($this->admin)
        ->withSession(['current_business_id' => $this->business->id])
        ->get("/dashboard/bookings/{$booking->id}/stripe-link/dispute")
        ->assertRedirect('https://dashboard.stripe.com/acct_test_dl/disputes/dp_test_deeplink');
});

test('dispute deeplink: 404 when the booking has no dispute PA', function () {
    $booking = paidBookingWithCharge($this->business);

    $this->actingAs($this->admin)
        ->withSession(['current_business_id' => $this->business->id])
        ->get("/dashboard/bookings/{$booking->id}/stripe-link/dispute")
        ->assertNotFound();
});

test('dispute deeplink: 404 when the only dispute PA is resolved (H-001)', function () {
    // H-001 (Codex Round 3): the prior implementation matched on `type` only
    // and would redirect using a resolved historical PA. The fix adds a
    // `status = pending` filter; a resolved-only history must 404.
    $booking = paidBookingWithCharge($this->business);

    PendingAction::create([
        'business_id' => $this->business->id,
        'booking_id' => $booking->id,
        'type' => PendingActionType::PaymentDisputeOpened,
        'payload' => ['dispute_id' => 'dp_resolved_history'],
        'status' => PendingActionStatus::Resolved,
        'resolved_at' => now(),
        'resolution_note' => 'closed:won',
    ]);

    $this->actingAs($this->admin)
        ->withSession(['current_business_id' => $this->business->id])
        ->get("/dashboard/bookings/{$booking->id}/stripe-link/dispute")
        ->assertNotFound();
});

test('dispute deeplink: pending PA is selected even when a newer resolved PA exists (H-001)', function () {
    // H-001 follow-up: with both rows present, the pending row is the right
    // selection regardless of insertion order. The pending PA represents the
    // currently-actionable dispute the operator should land on in Stripe.
    $booking = paidBookingWithCharge($this->business);

    // Older pending row (lower id).
    PendingAction::create([
        'business_id' => $this->business->id,
        'booking_id' => $booking->id,
        'type' => PendingActionType::PaymentDisputeOpened,
        'payload' => ['dispute_id' => 'dp_pending_target'],
        'status' => PendingActionStatus::Pending,
    ]);
    // Newer resolved historical row (higher id) — must NOT win the lookup.
    PendingAction::create([
        'business_id' => $this->business->id,
        'booking_id' => $booking->id,
        'type' => PendingActionType::PaymentDisputeOpened,
        'payload' => ['dispute_id' => 'dp_resolved_newer'],
        'status' => PendingActionStatus::Resolved,
        'resolved_at' => now(),
        'resolution_note' => 'closed:won',
    ]);

    $this->actingAs($this->admin)
        ->withSession(['current_business_id' => $this->business->id])
        ->get("/dashboard/bookings/{$booking->id}/stripe-link/dispute")
        ->assertRedirect('https://dashboard.stripe.com/acct_test_dl/disputes/dp_pending_target');
});

test('dispute deeplink: 403 for staff', function () {
    $booking = paidBookingWithCharge($this->business);
    PendingAction::create([
        'business_id' => $this->business->id,
        'booking_id' => $booking->id,
        'type' => PendingActionType::PaymentDisputeOpened,
        'payload' => ['dispute_id' => 'dp_test_staff'],
        'status' => PendingActionStatus::Pending,
    ]);
    $staff = User::factory()->create();
    $this->business->attachOrRestoreMember($staff, BusinessMemberRole::Staff);

    $this->actingAs($staff)
        ->withSession(['current_business_id' => $this->business->id])
        ->get("/dashboard/bookings/{$booking->id}/stripe-link/dispute")
        ->assertForbidden();
});

test('Inertia booking-detail prop carries no raw Stripe object IDs (G-001 regression guard)', function () {
    $booking = paidBookingWithCharge($this->business);

    BookingRefund::factory()->for($booking)->create([
        'amount_cents' => 1000,
        'currency' => 'chf',
        'stripe_refund_id' => 're_test_prop',
        'reason' => 'admin-manual',
    ]);

    PendingAction::create([
        'business_id' => $this->business->id,
        'booking_id' => $booking->id,
        'type' => PendingActionType::PaymentDisputeOpened,
        'payload' => [
            'dispute_id' => 'dp_test_prop',
            'reason' => 'fraudulent',
            'amount' => 5000,
            'currency' => 'chf',
            'status' => 'needs_response',
            // Keys outside the whitelist must NOT ride to the React side.
            'leaked_stripe_charge_id' => 'ch_should_not_be_visible',
            'leaked_payment_intent' => 'pi_should_not_be_visible',
        ],
        'status' => PendingActionStatus::Pending,
    ]);

    $this->actingAs($this->admin)
        ->withSession(['current_business_id' => $this->business->id])
        ->get('/dashboard/bookings')
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('dashboard/bookings')
            ->has('bookings.data.0', fn ($entry) => $entry
                // payment block: NO raw Stripe ids; only display + boolean.
                ->has('payment', fn ($p) => $p
                    ->where('has_stripe_payment_link', true)
                    ->missing('stripe_charge_id')
                    ->missing('stripe_payment_intent_id')
                    ->missing('stripe_connected_account_id')
                    ->etc()
                )
                // refunds: stripe_refund_id_last4 only.
                ->has('refunds.0', fn ($r) => $r
                    ->where('stripe_refund_id_last4', 'prop')
                    ->where('has_stripe_link', true)
                    ->missing('stripe_refund_id')
                    ->etc()
                )
                // dispute PA payload: whitelisted; raw dispute_id absent;
                // leaked_* absent; truncated last4 present.
                ->has('dispute_payment_action.payload', fn ($payload) => $payload
                    ->where('dispute_id_last4', 'prop')
                    ->where('reason', 'fraudulent')
                    ->where('amount', 5000)
                    ->where('currency', 'chf')
                    ->where('status', 'needs_response')
                    ->missing('dispute_id')
                    ->missing('leaked_stripe_charge_id')
                    ->missing('leaked_payment_intent')
                )
                ->where('dispute_payment_action.has_dispute_link', true)
                ->etc()
            )
        );
});
