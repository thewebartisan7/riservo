<?php

/**
 * PAYMENTS Session 3 — in-window cancel + business cancel paths dispatch
 * automatic refunds (locked decisions #15 / #16 / #17 / #29). Supersedes
 * the Session 2b PaidCancellationGuardTest which asserted the D-157 /
 * D-159 blocks. The blocks are gone; the dispatches are tested here.
 */

use App\Enums\BookingRefundStatus;
use App\Enums\BookingStatus;
use App\Enums\BusinessMemberRole;
use App\Enums\PaymentStatus;
use App\Models\Booking;
use App\Models\Business;
use App\Models\Customer;
use App\Models\Provider;
use App\Models\Service;
use App\Models\StripeConnectedAccount;
use App\Models\User;
use App\Notifications\BookingCancelledNotification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Stripe\StripeClient;
use Tests\Support\Billing\FakeStripeClient;

uses(RefreshDatabase::class);

beforeEach(function () {
    Notification::fake();

    $this->business = Business::factory()->onboarded()->create([
        'country' => 'CH',
        'cancellation_window_hours' => 0,
    ]);
    StripeConnectedAccount::factory()->active()->for($this->business)->create([
        'stripe_account_id' => 'acct_test_paid_cancel',
    ]);
    $this->provider = Provider::factory()->for($this->business)->create();
    $this->service = Service::factory()->for($this->business)->create();
});

function paidBooking(?User $userForCustomer = null): Booking
{
    $customer = $userForCustomer !== null
        ? Customer::factory()->for($userForCustomer)->create()
        : Customer::factory()->create();

    return Booking::factory()->paid()->create([
        'business_id' => test()->business->id,
        'provider_id' => test()->provider->id,
        'service_id' => test()->service->id,
        'customer_id' => $customer->id,
        'stripe_connected_account_id' => 'acct_test_paid_cancel',
        'paid_amount_cents' => 5000,
        'currency' => 'chf',
        'starts_at' => now()->addDays(7),
        'ends_at' => now()->addDays(7)->addHour(),
    ]);
}

// -------------------------------------------------------------------
// BookingManagementController::cancel — token-based (guest) customer
// -------------------------------------------------------------------

test('customer in-window cancel on paid booking dispatches customer-requested refund + flash', function () {
    $booking = paidBooking();
    FakeStripeClient::bind()->mockRefundCreate('acct_test_paid_cancel');

    $response = $this->post("/bookings/{$booking->cancellation_token}/cancel");

    $response->assertRedirect();
    $response->assertSessionHas('success', fn ($msg) => str_contains($msg, 'Refund initiated'));

    $booking->refresh();
    expect($booking->status)->toBe(BookingStatus::Cancelled);
    expect($booking->payment_status)->toBe(PaymentStatus::Refunded);

    $refund = $booking->bookingRefunds()->first();
    expect($refund)->not->toBeNull();
    expect($refund->reason)->toBe('customer-requested');
    expect($refund->initiated_by_user_id)->toBeNull();
    expect($refund->status)->toBe(BookingRefundStatus::Succeeded);
});

test('customer cancel on unpaid booking cancels without dispatching refund', function () {
    $booking = paidBooking();
    $booking->update(['payment_status' => PaymentStatus::Unpaid]);

    // No Stripe mock — any call would fail as "method not expected".
    app()->bind(StripeClient::class, fn () => (new FakeStripeClient)->client);

    $response = $this->post("/bookings/{$booking->cancellation_token}/cancel");

    $response->assertRedirect();
    $response->assertSessionHas('success', fn ($msg) => str_contains($msg, 'cancelled successfully'));

    $booking->refresh();
    expect($booking->status)->toBe(BookingStatus::Cancelled);
    expect($booking->payment_status)->toBe(PaymentStatus::Unpaid);
    expect($booking->bookingRefunds()->count())->toBe(0);
});

test('customer out-of-window cancel on paid booking is still blocked; no refund dispatch', function () {
    $this->business->update(['cancellation_window_hours' => 24]);
    $booking = paidBooking();
    $booking->update(['starts_at' => now()->addHour(), 'ends_at' => now()->addHour()->addMinutes(30)]);

    app()->bind(StripeClient::class, fn () => (new FakeStripeClient)->client);

    $response = $this->post("/bookings/{$booking->cancellation_token}/cancel");

    $response->assertRedirect();
    $response->assertSessionHas('error', fn ($msg) => str_contains($msg, 'cancellation window'));

    $booking->refresh();
    expect($booking->status)->toBe(BookingStatus::Confirmed);
    expect($booking->payment_status)->toBe(PaymentStatus::Paid);
    expect($booking->bookingRefunds()->count())->toBe(0);
});

test('customer cancel disconnected-account: booking still Cancelled; payment_status=refund_failed; fallback flash', function () {
    $booking = paidBooking();
    FakeStripeClient::bind()->mockRefundCreateFails('acct_test_paid_cancel');

    // Admin on business so the RefundFailedNotification has a recipient.
    $admin = User::factory()->create();
    $this->business->attachOrRestoreMember($admin, BusinessMemberRole::Admin);

    $response = $this->post("/bookings/{$booking->cancellation_token}/cancel");

    $response->assertRedirect();
    $response->assertSessionHas('success', fn ($msg) => str_contains($msg, "couldn't process the refund"));

    $booking->refresh();
    expect($booking->status)->toBe(BookingStatus::Cancelled);
    expect($booking->payment_status)->toBe(PaymentStatus::RefundFailed);
});

// -------------------------------------------------------------------
// Customer\BookingController::cancel — authenticated customer
// -------------------------------------------------------------------

test('authenticated customer in-window cancel on paid booking dispatches refund', function () {
    $user = User::factory()->create();
    $booking = paidBooking($user);
    FakeStripeClient::bind()->mockRefundCreate('acct_test_paid_cancel');

    $response = $this->actingAs($user)->post("/my-bookings/{$booking->id}/cancel");

    $response->assertRedirect();

    $booking->refresh();
    expect($booking->status)->toBe(BookingStatus::Cancelled);
    expect($booking->payment_status)->toBe(PaymentStatus::Refunded);

    expect($booking->bookingRefunds()->first()?->reason)->toBe('customer-requested');
});

// -------------------------------------------------------------------
// Dashboard\BookingController::updateStatus — admin cancel paths
// -------------------------------------------------------------------

test('admin cancel on Confirmed+Paid dispatches business-cancelled refund; customer email carries refund clause', function () {
    $admin = User::factory()->create();
    $this->business->attachOrRestoreMember($admin, BusinessMemberRole::Admin);
    $booking = paidBooking();

    FakeStripeClient::bind()->mockRefundCreate('acct_test_paid_cancel');

    $response = $this->actingAs($admin)
        ->withSession(['current_business_id' => $this->business->id])
        ->patch("/dashboard/bookings/{$booking->id}/status", ['status' => 'cancelled']);

    $response->assertRedirect();
    $response->assertSessionHas('success', fn ($msg) => str_contains($msg, 'Full refund issued'));

    $booking->refresh();
    expect($booking->status)->toBe(BookingStatus::Cancelled);
    expect($booking->payment_status)->toBe(PaymentStatus::Refunded);

    expect($booking->bookingRefunds()->first()?->reason)->toBe('business-cancelled');

    Notification::assertSentOnDemand(
        BookingCancelledNotification::class,
        fn (BookingCancelledNotification $n) => $n->cancelledBy === 'business' && $n->refundIssued === true,
    );
});

test('admin reject of Pending+Paid (manual-confirm) dispatches business-rejected-pending refund', function () {
    $this->business->update(['confirmation_mode' => 'manual']);

    $admin = User::factory()->create();
    $this->business->attachOrRestoreMember($admin, BusinessMemberRole::Admin);

    $booking = paidBooking();
    $booking->update(['status' => BookingStatus::Pending]);

    FakeStripeClient::bind()->mockRefundCreate('acct_test_paid_cancel');

    $response = $this->actingAs($admin)
        ->withSession(['current_business_id' => $this->business->id])
        ->patch("/dashboard/bookings/{$booking->id}/status", ['status' => 'cancelled']);

    $response->assertRedirect();

    $booking->refresh();
    expect($booking->status)->toBe(BookingStatus::Cancelled);
    expect($booking->payment_status)->toBe(PaymentStatus::Refunded);
    expect($booking->bookingRefunds()->first()?->reason)->toBe('business-rejected-pending');

    Notification::assertSentOnDemand(
        BookingCancelledNotification::class,
        fn (BookingCancelledNotification $n) => $n->cancelledBy === 'business' && $n->refundIssued === true,
    );
});

test('admin reject of Pending+Unpaid cancels without refund; customer email omits refund clause', function () {
    $this->business->update(['confirmation_mode' => 'manual']);

    $admin = User::factory()->create();
    $this->business->attachOrRestoreMember($admin, BusinessMemberRole::Admin);

    $booking = paidBooking();
    $booking->update([
        'status' => BookingStatus::Pending,
        'payment_status' => PaymentStatus::Unpaid,
    ]);

    app()->bind(StripeClient::class, fn () => (new FakeStripeClient)->client);

    $response = $this->actingAs($admin)
        ->withSession(['current_business_id' => $this->business->id])
        ->patch("/dashboard/bookings/{$booking->id}/status", ['status' => 'cancelled']);

    $response->assertRedirect();

    $booking->refresh();
    expect($booking->status)->toBe(BookingStatus::Cancelled);
    expect($booking->payment_status)->toBe(PaymentStatus::Unpaid);
    expect($booking->bookingRefunds()->count())->toBe(0);

    Notification::assertSentOnDemand(
        BookingCancelledNotification::class,
        fn (BookingCancelledNotification $n) => $n->cancelledBy === 'business' && $n->refundIssued === false,
    );
});

test('admin reject of Pending+AwaitingPayment cancels without refund; no Stripe call', function () {
    $this->business->update(['confirmation_mode' => 'manual']);

    $admin = User::factory()->create();
    $this->business->attachOrRestoreMember($admin, BusinessMemberRole::Admin);

    $booking = paidBooking();
    $booking->update([
        'status' => BookingStatus::Pending,
        'payment_status' => PaymentStatus::AwaitingPayment,
    ]);

    app()->bind(StripeClient::class, fn () => (new FakeStripeClient)->client);

    $response = $this->actingAs($admin)
        ->withSession(['current_business_id' => $this->business->id])
        ->patch("/dashboard/bookings/{$booking->id}/status", ['status' => 'cancelled']);

    $response->assertRedirect();

    $booking->refresh();
    expect($booking->status)->toBe(BookingStatus::Cancelled);
    expect($booking->payment_status)->toBe(PaymentStatus::AwaitingPayment);
    expect($booking->bookingRefunds()->count())->toBe(0);
});

test('staff cannot trigger a refund via updateStatus cancel (locked decision #19)', function () {
    // Staff attached to the provider so they pass the provider-scoped
    // updateStatus gate. Prior to the P1 fix, this request would have
    // dispatched RefundService::refund() for the staff user, bypassing
    // the admin-only gate on BookingRefundController::store.
    $staff = User::factory()->create();
    $this->business->attachOrRestoreMember($staff, BusinessMemberRole::Staff);
    $this->provider->update(['user_id' => $staff->id]);

    $booking = paidBooking();

    // Deliberately NO mockRefundCreate — any Stripe call would fail.
    app()->bind(StripeClient::class, fn () => (new FakeStripeClient)->client);

    $response = $this->actingAs($staff)
        ->withSession(['current_business_id' => $this->business->id])
        ->patch("/dashboard/bookings/{$booking->id}/status", ['status' => 'cancelled']);

    $response->assertRedirect();
    $response->assertSessionHas('error', fn ($msg) => str_contains($msg, 'Ask your admin'));

    $booking->refresh();
    expect($booking->status)->toBe(BookingStatus::Confirmed);
    expect($booking->payment_status)->toBe(PaymentStatus::Paid);
    expect($booking->bookingRefunds()->count())->toBe(0);
});

test('admin cancel disconnected-account: booking flips Cancelled; payment_status=refund_failed; error flash', function () {
    $admin = User::factory()->create();
    $this->business->attachOrRestoreMember($admin, BusinessMemberRole::Admin);
    $booking = paidBooking();

    FakeStripeClient::bind()->mockRefundCreateFails('acct_test_paid_cancel');

    $response = $this->actingAs($admin)
        ->withSession(['current_business_id' => $this->business->id])
        ->patch("/dashboard/bookings/{$booking->id}/status", ['status' => 'cancelled']);

    $response->assertRedirect();
    $response->assertSessionHas('error', fn ($msg) => str_contains($msg, 'Automatic refund failed'));

    $booking->refresh();
    expect($booking->status)->toBe(BookingStatus::Cancelled);
    expect($booking->payment_status)->toBe(PaymentStatus::RefundFailed);

    Notification::assertSentOnDemand(
        BookingCancelledNotification::class,
        fn (BookingCancelledNotification $n) => $n->cancelledBy === 'business' && $n->refundIssued === false,
    );
});
