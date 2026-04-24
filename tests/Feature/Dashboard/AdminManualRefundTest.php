<?php

/**
 * PAYMENTS Session 3 — admin-manual refund endpoint coverage.
 *
 * Controller: `Dashboard\BookingRefundController::store`.
 * Contract:
 *  - Admin-only (locked decision #19) + tenant-scoped (locked #45).
 *  - Full vs partial branch via `kind`; partial bounded by
 *    `remainingRefundableCents` server-side per D-169 (422 on overflow).
 *  - Success / disconnected / failed / guard_rejected outcomes surface as
 *    distinct flash copy.
 */

use App\Enums\BookingRefundStatus;
use App\Enums\BusinessMemberRole;
use App\Enums\PaymentStatus;
use App\Enums\PendingActionType;
use App\Models\Booking;
use App\Models\BookingRefund;
use App\Models\Business;
use App\Models\Customer;
use App\Models\PendingAction;
use App\Models\Provider;
use App\Models\Service;
use App\Models\StripeConnectedAccount;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Stripe\StripeClient;
use Tests\Support\Billing\FakeStripeClient;

uses(RefreshDatabase::class);

beforeEach(function () {
    Notification::fake();

    $this->business = Business::factory()->onboarded()->create(['country' => 'CH']);
    StripeConnectedAccount::factory()->active()->for($this->business)->create([
        'stripe_account_id' => 'acct_test_manual_refund',
    ]);
    $this->admin = User::factory()->create();
    $this->business->attachOrRestoreMember($this->admin, BusinessMemberRole::Admin);

    $this->provider = Provider::factory()->for($this->business)->create();
    $this->service = Service::factory()->for($this->business)->create();
    $this->customer = Customer::factory()->create();
});

function manualRefundBooking(array $overrides = []): Booking
{
    return Booking::factory()->paid()->create(array_merge([
        'business_id' => test()->business->id,
        'provider_id' => test()->provider->id,
        'service_id' => test()->service->id,
        'customer_id' => test()->customer->id,
        'stripe_connected_account_id' => 'acct_test_manual_refund',
        'paid_amount_cents' => 5000,
        'currency' => 'chf',
    ], $overrides));
}

test('admin full refund dispatches RefundService with admin-manual + initiator = admin id', function () {
    $booking = manualRefundBooking();
    FakeStripeClient::bind()->mockRefundCreate('acct_test_manual_refund');

    $response = $this->actingAs($this->admin)
        ->withSession(['current_business_id' => $this->business->id])
        ->post("/dashboard/bookings/{$booking->id}/refunds", ['kind' => 'full']);

    $response->assertRedirect();
    $response->assertSessionHas('success', fn ($msg) => str_contains($msg, 'Refund issued'));

    $booking->refresh();
    expect($booking->payment_status)->toBe(PaymentStatus::Refunded);
    $refund = $booking->bookingRefunds()->first();
    expect($refund->reason)->toBe('admin-manual');
    expect($refund->initiated_by_user_id)->toBe($this->admin->id);
    expect($refund->status)->toBe(BookingRefundStatus::Succeeded);
});

test('admin partial refund (2000 of 5000) leaves booking PartiallyRefunded', function () {
    $booking = manualRefundBooking();
    FakeStripeClient::bind()->mockRefundCreate('acct_test_manual_refund', null, ['amount' => 2000]);

    $response = $this->actingAs($this->admin)
        ->withSession(['current_business_id' => $this->business->id])
        ->post("/dashboard/bookings/{$booking->id}/refunds", [
            'kind' => 'partial',
            'amount_cents' => 2000,
        ]);

    $response->assertRedirect();

    $booking->refresh();
    expect($booking->payment_status)->toBe(PaymentStatus::PartiallyRefunded);
    expect($booking->remainingRefundableCents())->toBe(3000);
});

test('partial refund overflow (>= remaining) surfaces 422 with amount_cents error', function () {
    $booking = manualRefundBooking();

    // Seed an existing 2000 refund so remaining is 3000.
    FakeStripeClient::bind()->mockRefundCreate('acct_test_manual_refund', null, ['id' => 're_test_seed_one', 'amount' => 2000]);
    $this->actingAs($this->admin)
        ->withSession(['current_business_id' => $this->business->id])
        ->post("/dashboard/bookings/{$booking->id}/refunds", [
            'kind' => 'partial',
            'amount_cents' => 2000,
        ])->assertRedirect();

    // Now request 4000 with only 3000 remaining → 422 + no Stripe call.
    // Deliberately no additional mockRefundCreate — any call would fail.
    $response = $this->actingAs($this->admin)
        ->withSession(['current_business_id' => $this->business->id])
        ->post("/dashboard/bookings/{$booking->id}/refunds", [
            'kind' => 'partial',
            'amount_cents' => 4000,
        ]);

    $response->assertStatus(302);
    $response->assertSessionHasErrors(['amount_cents']);

    $booking->refresh();
    expect($booking->payment_status)->toBe(PaymentStatus::PartiallyRefunded);
    expect($booking->bookingRefunds()->count())->toBe(1);
});

test('staff cannot access the refund endpoint (403)', function () {
    $staff = User::factory()->create();
    $this->business->attachOrRestoreMember($staff, BusinessMemberRole::Staff);

    $booking = manualRefundBooking();

    $response = $this->actingAs($staff)
        ->withSession(['current_business_id' => $this->business->id])
        ->post("/dashboard/bookings/{$booking->id}/refunds", ['kind' => 'full']);

    $response->assertForbidden();

    expect($booking->fresh()->bookingRefunds()->count())->toBe(0);
});

test('cross-tenant refund returns 404', function () {
    $otherBusiness = Business::factory()->onboarded()->create();
    $otherAdmin = User::factory()->create();
    $otherBusiness->attachOrRestoreMember($otherAdmin, BusinessMemberRole::Admin);

    $booking = manualRefundBooking();

    $response = $this->actingAs($otherAdmin)
        ->withSession(['current_business_id' => $otherBusiness->id])
        ->post("/dashboard/bookings/{$booking->id}/refunds", ['kind' => 'full']);

    $response->assertNotFound();

    expect($booking->fresh()->bookingRefunds()->count())->toBe(0);
});

test('disconnected account fallback flips payment_status and surfaces Pending Action', function () {
    $booking = manualRefundBooking();
    FakeStripeClient::bind()->mockRefundCreateFails('acct_test_manual_refund');

    $response = $this->actingAs($this->admin)
        ->withSession(['current_business_id' => $this->business->id])
        ->post("/dashboard/bookings/{$booking->id}/refunds", ['kind' => 'full']);

    $response->assertRedirect();
    $response->assertSessionHas('error', fn ($msg) => str_contains($msg, 'pending action'));

    $booking->refresh();
    expect($booking->payment_status)->toBe(PaymentStatus::RefundFailed);

    $pa = PendingAction::where('type', PendingActionType::PaymentRefundFailed->value)->first();
    expect($pa)->not->toBeNull();
});

test('admin reason is persisted on booking_refunds.admin_note', function () {
    $booking = manualRefundBooking();
    FakeStripeClient::bind()->mockRefundCreate('acct_test_manual_refund');

    $response = $this->actingAs($this->admin)
        ->withSession(['current_business_id' => $this->business->id])
        ->post("/dashboard/bookings/{$booking->id}/refunds", [
            'kind' => 'full',
            'reason' => 'Customer requested refund via email; appointment no longer needed.',
        ]);

    $response->assertRedirect();

    $refund = $booking->fresh()->bookingRefunds()->first();
    expect($refund->reason)->toBe('admin-manual');
    expect($refund->admin_note)->toBe('Customer requested refund via email; appointment no longer needed.');
});

test('empty reason persists as null admin_note', function () {
    $booking = manualRefundBooking();
    FakeStripeClient::bind()->mockRefundCreate('acct_test_manual_refund');

    $response = $this->actingAs($this->admin)
        ->withSession(['current_business_id' => $this->business->id])
        ->post("/dashboard/bookings/{$booking->id}/refunds", [
            'kind' => 'full',
            'reason' => '',
        ]);

    $response->assertRedirect();

    $refund = $booking->fresh()->bookingRefunds()->first();
    expect($refund->admin_note)->toBeNull();
});

test('admin-manual retry with different amount while one is pending returns 422', function () {
    $booking = manualRefundBooking();

    // Seed a Pending admin-manual row of 2000 (simulating a refund that's
    // still settling or whose Stripe response was lost).
    BookingRefund::factory()->pending()->for($booking)->create([
        'amount_cents' => 2000,
        'currency' => 'chf',
        'reason' => 'admin-manual',
        'initiated_by_user_id' => $this->admin->id,
    ]);

    // No Stripe mock — any call would fail "method not expected".
    app()->bind(StripeClient::class, fn () => (new FakeStripeClient)->client);

    $response = $this->actingAs($this->admin)
        ->withSession(['current_business_id' => $this->business->id])
        ->post("/dashboard/bookings/{$booking->id}/refunds", [
            'kind' => 'partial',
            'amount_cents' => 3000,
        ]);

    $response->assertRedirect();
    $response->assertSessionHasErrors(['amount_cents']);

    // The pending row is untouched; no new row, no Stripe call.
    expect($booking->fresh()->bookingRefunds()->count())->toBe(1);
    expect($booking->fresh()->bookingRefunds()->first()->amount_cents)->toBe(2000);
});

test('admin-manual retry with same amount while pending reuses row (retry idempotency)', function () {
    $booking = manualRefundBooking();

    // Seed a Pending admin-manual row of 2000.
    $existing = BookingRefund::factory()->pending()->for($booking)->create([
        'amount_cents' => 2000,
        'currency' => 'chf',
        'reason' => 'admin-manual',
        'initiated_by_user_id' => $this->admin->id,
    ]);

    $expectedKey = 'riservo_refund_'.$existing->uuid;
    FakeStripeClient::bind()->mockRefundCreate('acct_test_manual_refund', $expectedKey, ['amount' => 2000]);

    $response = $this->actingAs($this->admin)
        ->withSession(['current_business_id' => $this->business->id])
        ->post("/dashboard/bookings/{$booking->id}/refunds", [
            'kind' => 'partial',
            'amount_cents' => 2000,
        ]);

    $response->assertRedirect();
    $response->assertSessionHas('success');

    // Still just one row — the pending was reused.
    expect($booking->fresh()->bookingRefunds()->count())->toBe(1);
});

test('refund on NotApplicable booking is guard-rejected', function () {
    $booking = manualRefundBooking();
    $booking->update([
        'payment_status' => PaymentStatus::NotApplicable,
        'paid_amount_cents' => null,
        'currency' => null,
    ]);

    app()->bind(StripeClient::class, fn () => (new FakeStripeClient)->client);

    $response = $this->actingAs($this->admin)
        ->withSession(['current_business_id' => $this->business->id])
        ->post("/dashboard/bookings/{$booking->id}/refunds", ['kind' => 'full']);

    $response->assertRedirect();
    $response->assertSessionHas('error', fn ($msg) => str_contains($msg, 'no longer refundable'));

    expect($booking->fresh()->bookingRefunds()->count())->toBe(0);
});
