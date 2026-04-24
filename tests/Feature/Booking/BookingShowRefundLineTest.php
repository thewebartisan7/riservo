<?php

/**
 * PAYMENTS Session 3 — customer-facing refund status line on
 * `/bookings/{token}` (token-based guest) + `/my-bookings` (authenticated).
 *
 * The line is computed by `Booking::refundStatusLine()`. Tests assert the
 * five branches documented on the method: full-refunded, partial, pending,
 * failed (generic), failed + disconnected-account fallback.
 */

use App\Enums\PaymentStatus;
use App\Models\Booking;
use App\Models\BookingRefund;
use App\Models\Business;
use App\Models\Customer;
use App\Models\Provider;
use App\Models\Service;
use App\Models\StripeConnectedAccount;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->business = Business::factory()->onboarded()->create(['country' => 'CH']);
    $this->account = StripeConnectedAccount::factory()->active()->for($this->business)->create([
        'stripe_account_id' => 'acct_test_show',
    ]);
    $this->provider = Provider::factory()->for($this->business)->create();
    $this->service = Service::factory()->for($this->business)->create();
    $this->customer = Customer::factory()->create();
});

function showBooking(array $bookingOverrides = []): Booking
{
    return Booking::factory()->paid()->create(array_merge([
        'business_id' => test()->business->id,
        'provider_id' => test()->provider->id,
        'service_id' => test()->service->id,
        'customer_id' => test()->customer->id,
        'stripe_connected_account_id' => 'acct_test_show',
        'paid_amount_cents' => 5000,
        'currency' => 'chf',
    ], $bookingOverrides));
}

test('no refunds → refund_status_line is null', function () {
    $this->withoutVite();
    $booking = showBooking(['cancellation_token' => 'tok_none']);

    $response = $this->get('/bookings/tok_none');
    $response->assertOk();
    $response->assertInertia(
        fn ($page) => $page->where('booking.refund_status_line', null)
    );
});

test('succeeded full refund → "Refunded in full" copy', function () {
    $this->withoutVite();
    $booking = showBooking(['cancellation_token' => 'tok_full', 'payment_status' => PaymentStatus::Refunded]);
    BookingRefund::factory()->succeeded()->for($booking)->create([
        'amount_cents' => 5000,
        'currency' => 'chf',
        'reason' => 'customer-requested',
    ]);

    $response = $this->get('/bookings/tok_full');
    $response->assertOk();
    $response->assertInertia(
        fn ($page) => $page->where(
            'booking.refund_status_line',
            fn ($line) => is_string($line) && str_contains($line, 'Refunded in full'),
        )
    );
});

test('succeeded partial refund → "Partial refund issued" copy', function () {
    $this->withoutVite();
    $booking = showBooking(['cancellation_token' => 'tok_part', 'payment_status' => PaymentStatus::PartiallyRefunded]);
    BookingRefund::factory()->succeeded()->for($booking)->create([
        'amount_cents' => 2000,
        'currency' => 'chf',
        'reason' => 'admin-manual',
    ]);

    $response = $this->get('/bookings/tok_part');
    $response->assertOk();
    $response->assertInertia(
        fn ($page) => $page->where(
            'booking.refund_status_line',
            fn ($line) => is_string($line) && str_contains($line, 'Partial refund'),
        )
    );
});

test('only pending refund → "Refund initiated — processing" copy', function () {
    $this->withoutVite();
    $booking = showBooking(['cancellation_token' => 'tok_pend']);
    BookingRefund::factory()->pending()->for($booking)->create([
        'amount_cents' => 5000,
        'currency' => 'chf',
        'reason' => 'customer-requested',
    ]);

    $response = $this->get('/bookings/tok_pend');
    $response->assertOk();
    $response->assertInertia(
        fn ($page) => $page->where(
            'booking.refund_status_line',
            fn ($line) => is_string($line) && str_contains($line, 'Refund initiated'),
        )
    );
});

test('failed refund + pinned account disconnected → "the business will contact you" copy', function () {
    $this->withoutVite();
    $booking = showBooking(['cancellation_token' => 'tok_disc', 'payment_status' => PaymentStatus::RefundFailed]);
    BookingRefund::factory()->failed()->for($booking)->create([
        'amount_cents' => 5000,
        'currency' => 'chf',
        'reason' => 'customer-requested',
        'failure_reason' => 'account deauthorized',
    ]);

    // Disconnect the pinned account to trigger the fallback copy.
    $this->account->delete();

    $response = $this->get('/bookings/tok_disc');
    $response->assertOk();
    $response->assertInertia(
        fn ($page) => $page->where(
            'booking.refund_status_line',
            fn ($line) => is_string($line) && str_contains($line, 'payment setup has changed'),
        )
    );
});

test('failed refund + account still connected → generic "business has been notified" copy', function () {
    $this->withoutVite();
    $booking = showBooking(['cancellation_token' => 'tok_fail', 'payment_status' => PaymentStatus::RefundFailed]);
    BookingRefund::factory()->failed()->for($booking)->create([
        'amount_cents' => 5000,
        'currency' => 'chf',
        'reason' => 'customer-requested',
        'failure_reason' => 'charge_already_refunded',
    ]);

    $response = $this->get('/bookings/tok_fail');
    $response->assertOk();
    $response->assertInertia(
        fn ($page) => $page->where(
            'booking.refund_status_line',
            fn ($line) => is_string($line) && str_contains($line, 'business has been notified'),
        )
    );
});
