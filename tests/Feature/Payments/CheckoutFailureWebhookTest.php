<?php

use App\Enums\BookingStatus;
use App\Enums\ConfirmationMode;
use App\Enums\PaymentStatus;
use App\Jobs\Calendar\PushBookingToCalendarJob;
use App\Models\Booking;
use App\Models\Business;
use App\Models\CalendarIntegration;
use App\Models\Provider;
use App\Models\Service;
use App\Models\StripeConnectedAccount;
use App\Models\User;
use App\Notifications\BookingConfirmedNotification;
use App\Notifications\BookingReceivedNotification;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Notification;

/**
 * PAYMENTS Session 2b — failure-branching webhook tests.
 *
 * Locked decision #14 binds:
 *   - online → Cancelled + NotApplicable (slot released).
 *   - customer_choice → Confirmed + Unpaid (or Pending + Unpaid under
 *     manual-confirm) — slot stays held, standard booking-confirmed
 *     notifications fire.
 */
beforeEach(function () {
    Notification::fake();
    Bus::fake();
    Cache::flush();
    config(['services.stripe.connect_webhook_secret' => null]);

    $this->business = Business::factory()->onboarded()->create([
        'timezone' => 'Europe/Zurich',
        'country' => 'CH',
        'confirmation_mode' => ConfirmationMode::Auto,
    ]);
    $this->account = StripeConnectedAccount::factory()->active()->for($this->business)->create([
        'stripe_account_id' => 'acct_test_fail',
    ]);
});

function checkoutFailureEvent(Booking $booking, string $type = 'checkout.session.expired', ?string $eventId = null): array
{
    return [
        'id' => $eventId ?? 'evt_fail_'.uniqid(),
        'object' => 'event',
        'type' => $type,
        'account' => 'acct_test_fail',
        'data' => [
            'object' => [
                'object' => 'checkout.session',
                'id' => $booking->stripe_checkout_session_id,
                'client_reference_id' => (string) $booking->id,
                'account' => 'acct_test_fail',
                'payment_status' => 'unpaid',
                'status' => 'expired',
            ],
        ],
    ];
}

function onlineAwaitingBooking(array $overrides = []): Booking
{
    return Booking::factory()->awaitingPayment()->create(array_merge([
        'business_id' => test()->business->id,
        'stripe_checkout_session_id' => 'cs_test_fail',
        'stripe_connected_account_id' => 'acct_test_fail',
        'payment_mode_at_creation' => 'online',
    ], $overrides));
}

function customerChoiceAwaitingBooking(array $overrides = []): Booking
{
    return Booking::factory()->awaitingPayment()->create(array_merge([
        'business_id' => test()->business->id,
        'stripe_checkout_session_id' => 'cs_test_fail',
        'stripe_connected_account_id' => 'acct_test_fail',
        'payment_mode_at_creation' => 'customer_choice',
    ], $overrides));
}

test('checkout.session.expired for online booking cancels and releases the slot', function () {
    $booking = onlineAwaitingBooking();

    $this->postJson('/webhooks/stripe-connect', checkoutFailureEvent($booking))
        ->assertOk();

    $booking->refresh();
    expect($booking->status)->toBe(BookingStatus::Cancelled);
    expect($booking->payment_status)->toBe(PaymentStatus::NotApplicable);
    expect($booking->expires_at)->toBeNull();

    Notification::assertNothingSent();
});

test('checkout.session.expired for customer_choice auto-confirm promotes to Confirmed + Unpaid with notifications', function () {
    $this->business->forceFill(['confirmation_mode' => ConfirmationMode::Auto])->save();
    $booking = customerChoiceAwaitingBooking();

    $this->postJson('/webhooks/stripe-connect', checkoutFailureEvent($booking))
        ->assertOk();

    $booking->refresh();
    expect($booking->status)->toBe(BookingStatus::Confirmed);
    expect($booking->payment_status)->toBe(PaymentStatus::Unpaid);
    expect($booking->expires_at)->toBeNull();

    Notification::assertSentOnDemand(BookingConfirmedNotification::class);
});

test('checkout.session.expired for customer_choice manual-confirm lands at Pending + Unpaid with pending-awaiting-confirmation notification', function () {
    $this->business->forceFill(['confirmation_mode' => ConfirmationMode::Manual])->save();
    $booking = customerChoiceAwaitingBooking();

    $this->postJson('/webhooks/stripe-connect', checkoutFailureEvent($booking))
        ->assertOk();

    $booking->refresh();
    expect($booking->status)->toBe(BookingStatus::Pending);
    expect($booking->payment_status)->toBe(PaymentStatus::Unpaid);

    Notification::assertSentOnDemand(
        BookingReceivedNotification::class,
        fn (BookingReceivedNotification $n) => $n->context === 'pending_unpaid_awaiting_confirmation'
    );
});

test('checkout.session.async_payment_failed mirrors the expired branch', function () {
    $booking = onlineAwaitingBooking();

    $this->postJson(
        '/webhooks/stripe-connect',
        checkoutFailureEvent($booking, 'checkout.session.async_payment_failed'),
    )->assertOk();

    $booking->refresh();
    expect($booking->status)->toBe(BookingStatus::Cancelled);
    expect($booking->payment_status)->toBe(PaymentStatus::NotApplicable);
});

test('replaying the same expired event id dedupes via cache and does not touch state', function () {
    $booking = onlineAwaitingBooking();
    $event = checkoutFailureEvent($booking, eventId: 'evt_same_fail');

    $this->postJson('/webhooks/stripe-connect', $event)->assertOk();

    Notification::fake();
    $this->postJson('/webhooks/stripe-connect', $event)->assertOk();

    Notification::assertNothingSent();
});

test('fresh event id on an already-cancelled booking short-circuits via DB guard', function () {
    $booking = Booking::factory()->create([
        'business_id' => $this->business->id,
        'stripe_checkout_session_id' => 'cs_test_fail',
        'stripe_connected_account_id' => 'acct_test_fail',
        'payment_mode_at_creation' => 'online',
        'status' => BookingStatus::Cancelled,
        'payment_status' => PaymentStatus::NotApplicable,
    ]);

    $this->postJson('/webhooks/stripe-connect', checkoutFailureEvent($booking))
        ->assertOk();

    $booking->refresh();
    expect($booking->status)->toBe(BookingStatus::Cancelled);
    expect($booking->payment_status)->toBe(PaymentStatus::NotApplicable);

    Notification::assertNothingSent();
});

test('cross-account mismatch refuses to act (session account != pinned account)', function () {
    $booking = onlineAwaitingBooking();

    $event = checkoutFailureEvent($booking);
    $event['account'] = 'acct_foreign';
    $event['data']['object']['account'] = 'acct_foreign';

    $this->postJson('/webhooks/stripe-connect', $event)->assertOk();

    $booking->refresh();
    expect($booking->status)->toBe(BookingStatus::Pending);
    expect($booking->payment_status)->toBe(PaymentStatus::AwaitingPayment);
});

test('unknown client_reference_id returns 200 with no state change', function () {
    $event = [
        'id' => 'evt_unknown_fail',
        'object' => 'event',
        'type' => 'checkout.session.expired',
        'account' => 'acct_test_fail',
        'data' => [
            'object' => [
                'object' => 'checkout.session',
                'id' => 'cs_phantom',
                'client_reference_id' => '999999',
                'account' => 'acct_test_fail',
                'payment_status' => 'unpaid',
                'status' => 'expired',
            ],
        ],
    ];

    $this->postJson('/webhooks/stripe-connect', $event)->assertOk();
});

test('failure webhook refuses to act when session id does not match the booking (Codex Round 4 F3)', function () {
    $booking = onlineAwaitingBooking();
    $event = checkoutFailureEvent($booking);
    // Swap the session id — simulates a cross-integration collision on
    // the same connected account.
    $event['data']['object']['id'] = 'cs_other_failure';

    $this->postJson('/webhooks/stripe-connect', $event)->assertOk();

    $booking->refresh();
    expect($booking->status)->toBe(BookingStatus::Pending);
    expect($booking->payment_status)->toBe(PaymentStatus::AwaitingPayment);
});

test('replay of customer_choice failure with a fresh event id does NOT re-dispatch notifications (Codex Round 4 F4)', function () {
    $this->business->forceFill(['confirmation_mode' => ConfirmationMode::Auto])->save();
    $booking = customerChoiceAwaitingBooking();

    // First failure delivery: lands the booking at Confirmed + Unpaid
    // with notifications.
    $this->postJson('/webhooks/stripe-connect', checkoutFailureEvent($booking, eventId: 'evt_first_fail'))
        ->assertOk();

    $booking->refresh();
    expect($booking->status)->toBe(BookingStatus::Confirmed);
    expect($booking->payment_status)->toBe(PaymentStatus::Unpaid);

    // Simulate a fresh-id replay AFTER the cache TTL has elapsed
    // (different event id, so the cache dedup misses). Outcome guard
    // must treat `Unpaid` as terminal.
    Notification::fake();
    Bus::fake();
    $this->postJson('/webhooks/stripe-connect', checkoutFailureEvent($booking, eventId: 'evt_replay_fail'))
        ->assertOk();

    Notification::assertNothingSent();
});

test('customer_choice + Confirmed failure dispatches PushBookingToCalendarJob when provider has a configured integration (Codex Round 3 F4)', function () {
    $this->business->forceFill(['confirmation_mode' => ConfirmationMode::Auto])->save();

    $owner = User::factory()->create(['email_verified_at' => now()]);
    attachAdmin($this->business, $owner);
    $provider = Provider::factory()->create([
        'business_id' => $this->business->id,
        'user_id' => $owner->id,
    ]);
    CalendarIntegration::factory()->configured($this->business->id)->create([
        'user_id' => $owner->id,
    ]);
    $service = Service::factory()->create(['business_id' => $this->business->id]);

    $booking = Booking::factory()->awaitingPayment()->create([
        'business_id' => $this->business->id,
        'provider_id' => $provider->id,
        'service_id' => $service->id,
        'stripe_checkout_session_id' => 'cs_test_fail',
        'stripe_connected_account_id' => 'acct_test_fail',
        'payment_mode_at_creation' => 'customer_choice',
    ]);

    $this->postJson('/webhooks/stripe-connect', checkoutFailureEvent($booking))
        ->assertOk();

    $booking->refresh();
    expect($booking->status)->toBe(BookingStatus::Confirmed);
    expect($booking->payment_status)->toBe(PaymentStatus::Unpaid);

    Bus::assertDispatched(PushBookingToCalendarJob::class, fn ($job) => $job->bookingId === $booking->id && $job->action === 'create');
});

test('expired event for a booking already Paid short-circuits via outcome guard', function () {
    $booking = Booking::factory()->paid()->create([
        'business_id' => $this->business->id,
        'stripe_checkout_session_id' => 'cs_test_fail',
        'stripe_connected_account_id' => 'acct_test_fail',
        'payment_mode_at_creation' => 'online',
    ]);

    $this->postJson('/webhooks/stripe-connect', checkoutFailureEvent($booking))
        ->assertOk();

    $booking->refresh();
    expect($booking->payment_status)->toBe(PaymentStatus::Paid);
    expect($booking->status)->toBe(BookingStatus::Confirmed);

    Notification::assertNothingSent();
});
