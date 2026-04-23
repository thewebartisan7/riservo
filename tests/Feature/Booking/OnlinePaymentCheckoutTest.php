<?php

use App\Enums\BookingSource;
use App\Enums\BookingStatus;
use App\Enums\DayOfWeek;
use App\Enums\PaymentMode;
use App\Enums\PaymentStatus;
use App\Models\AvailabilityRule;
use App\Models\Booking;
use App\Models\Business;
use App\Models\BusinessHour;
use App\Models\Service;
use App\Models\StripeConnectedAccount;
use App\Models\User;
use Carbon\CarbonImmutable;
use Stripe\Exception\ApiConnectionException;
use Tests\Support\Billing\FakeStripeClient;

beforeEach(function () {
    $this->business = Business::factory()->onboarded()->create([
        'timezone' => 'Europe/Zurich',
        'country' => 'CH',
        'payment_mode' => PaymentMode::Online,
    ]);
    $this->staff = User::factory()->create(['name' => 'Alice']);
    $this->provider = attachProvider($this->business, $this->staff);

    BusinessHour::factory()->create([
        'business_id' => $this->business->id,
        'day_of_week' => DayOfWeek::Monday->value,
        'open_time' => '09:00',
        'close_time' => '18:00',
    ]);

    AvailabilityRule::factory()->create([
        'provider_id' => $this->provider->id,
        'business_id' => $this->business->id,
        'day_of_week' => DayOfWeek::Monday->value,
        'start_time' => '09:00',
        'end_time' => '18:00',
    ]);

    $this->service = Service::factory()->create([
        'business_id' => $this->business->id,
        'is_active' => true,
        'duration_minutes' => 60,
        'buffer_before' => 0,
        'buffer_after' => 0,
        'slot_interval_minutes' => 60,
        'price' => 50.00,
    ]);
    $this->provider->services()->attach($this->service);

    $this->connectedAccount = StripeConnectedAccount::factory()->active()->for($this->business)->create([
        'stripe_account_id' => 'acct_test_2a',
        'country' => 'CH',
        'default_currency' => 'chf',
    ]);

    $this->travelTo(CarbonImmutable::parse('2026-04-13 08:00', 'Europe/Zurich'));
});

function book2a(array $overrides = []): array
{
    return array_merge([
        'service_id' => test()->service->id,
        'provider_id' => test()->provider->id,
        'date' => '2026-04-13',
        'time' => '10:00',
        'name' => 'Jane Doe',
        'email' => 'jane@example.test',
        'phone' => '+41 79 000 00 00',
    ], $overrides);
}

test('online mode creates pending + awaiting_payment booking and returns the Stripe URL', function () {
    FakeStripeClient::for($this)->mockCheckoutSessionCreateOnAccount('acct_test_2a', [
        'url' => 'https://checkout.stripe.com/c/pay/cs_test_happy',
        'id' => 'cs_test_happy',
    ]);

    $response = $this->postJson("/booking/{$this->business->slug}/book", book2a());

    $response->assertCreated()
        ->assertJson([
            'redirect_url' => 'https://checkout.stripe.com/c/pay/cs_test_happy',
            // Codex Round 2 (D-161): explicit external-redirect flag.
            'external_redirect' => true,
            'status' => BookingStatus::Pending->value,
        ]);

    $booking = Booking::firstOrFail();
    expect($booking->status)->toBe(BookingStatus::Pending)
        ->and($booking->payment_status)->toBe(PaymentStatus::AwaitingPayment)
        ->and($booking->payment_mode_at_creation)->toBe('online')
        ->and($booking->stripe_checkout_session_id)->toBe('cs_test_happy')
        // Codex Round 2 (D-158): minting connected-account id pinned on
        // the booking so the webhook + success-page cross-check against
        // the ORIGINAL account even after disconnect+reconnect history.
        ->and($booking->stripe_connected_account_id)->toBe('acct_test_2a')
        ->and($booking->paid_amount_cents)->toBe(5000)
        ->and($booking->currency)->toBe('chf')
        ->and($booking->expires_at)->not->toBeNull();
});

test('codex round 2 D-158: reconnect history does not break webhook cross-account guard', function () {
    // Simulate a disconnect + reconnect: the business has one trashed
    // historical account AND the current active one. The booking's
    // minting account id must point at the active one (or whichever
    // was active at creation), and the webhook's cross-account guard
    // must read that pinned value — NOT a withTrashed() lookup that
    // could match either row non-deterministically.
    StripeConnectedAccount::factory()->for($this->business)->create([
        'stripe_account_id' => 'acct_test_OLD',
        'deleted_at' => now()->subDay(),
    ]);

    FakeStripeClient::for($this)->mockCheckoutSessionCreateOnAccount('acct_test_2a', [
        'id' => 'cs_test_reconnect',
        'url' => 'https://checkout.stripe.com/c/pay/cs_test_reconnect',
    ]);

    $this->postJson("/booking/{$this->business->slug}/book", book2a())
        ->assertCreated();

    $booking = Booking::firstOrFail();
    expect($booking->stripe_connected_account_id)->toBe('acct_test_2a');
});

test('codex round 2 D-161: offline path returns external_redirect=false', function () {
    // Any offline-branch booking (Business payment_mode=offline, price
    // null/0, or customer_choice + pay-on-site) returns an internal
    // `redirect_url` with `external_redirect=false`. The React summary
    // component's dispatch on this boolean prevents a false
    // window.location.href for HTTPS-deployed riservo internal URLs.
    $this->business->update(['payment_mode' => PaymentMode::Offline]);

    $response = $this->postJson("/booking/{$this->business->slug}/book", book2a());

    $response->assertCreated()
        ->assertJson(['external_redirect' => false]);
});

test('customer_choice + pay-now snapshots customer_choice and mints Checkout', function () {
    $this->business->update(['payment_mode' => PaymentMode::CustomerChoice]);

    FakeStripeClient::for($this)->mockCheckoutSessionCreateOnAccount('acct_test_2a', [
        'id' => 'cs_test_cc_online',
        'url' => 'https://checkout.stripe.com/c/pay/cs_test_cc_online',
    ]);

    $this->postJson("/booking/{$this->business->slug}/book", book2a(['payment_choice' => 'online']))
        ->assertCreated()
        ->assertJsonFragment(['redirect_url' => 'https://checkout.stripe.com/c/pay/cs_test_cc_online']);

    expect(Booking::firstOrFail()->payment_mode_at_creation)->toBe('customer_choice');
});

test('customer_choice + pay-on-site snapshots customer_choice without Checkout', function () {
    $this->business->update(['payment_mode' => PaymentMode::CustomerChoice]);

    // No mock — a call through Stripe would fail the Mockery expectation.
    $response = $this->postJson("/booking/{$this->business->slug}/book", book2a(['payment_choice' => 'offline']));

    $response->assertCreated();
    $booking = Booking::firstOrFail();

    expect($booking->status)->toBe(BookingStatus::Confirmed)
        ->and($booking->payment_status)->toBe(PaymentStatus::NotApplicable)
        // Snapshot invariant: customer_choice + pay-on-site still records
        // 'customer_choice', NOT 'offline'. Dashboard analytics rely on this.
        ->and($booking->payment_mode_at_creation)->toBe('customer_choice')
        ->and($booking->stripe_checkout_session_id)->toBeNull()
        ->and($booking->expires_at)->toBeNull();
});

test('service.price = null forces offline path regardless of Business payment_mode', function () {
    $this->service->update(['price' => null]);

    $this->postJson("/booking/{$this->business->slug}/book", book2a())
        ->assertCreated();

    $booking = Booking::firstOrFail();
    expect($booking->payment_status)->toBe(PaymentStatus::NotApplicable)
        ->and($booking->stripe_checkout_session_id)->toBeNull()
        // The snapshot still mirrors Business.payment_mode ('online'); the
        // only thing that changed is "is there anything to charge?".
        ->and($booking->payment_mode_at_creation)->toBe('online');
});

test('service.price = 0 forces offline path', function () {
    $this->service->update(['price' => 0]);

    $this->postJson("/booking/{$this->business->slug}/book", book2a())
        ->assertCreated();

    expect(Booking::firstOrFail()->payment_status)->toBe(PaymentStatus::NotApplicable);
});

test('offline business never attempts a Checkout session', function () {
    $this->business->update(['payment_mode' => PaymentMode::Offline]);

    $this->postJson("/booking/{$this->business->slug}/book", book2a())
        ->assertCreated();

    $booking = Booking::firstOrFail();
    expect($booking->payment_status)->toBe(PaymentStatus::NotApplicable)
        ->and($booking->payment_mode_at_creation)->toBe('offline')
        ->and($booking->stripe_checkout_session_id)->toBeNull();
});

test('expanded supported_countries lets a matching account succeed (proves seams open)', function () {
    // Force the account into DE.
    $this->connectedAccount->update(['country' => 'DE']);
    config(['payments.supported_countries' => ['CH', 'DE']]);
    config(['payments.twint_countries' => ['CH']]); // non-TWINT fallback

    // Because DE is not in twint_countries, only 'card' is enabled. The
    // Mockery matcher doesn't assert payment_method_types directly here —
    // the CheckoutSessionFactory unit test covers that — but the mock must
    // be in place so the call succeeds.
    FakeStripeClient::for($this)->mockCheckoutSessionCreateOnAccount('acct_test_2a', [
        'id' => 'cs_test_de',
        'url' => 'https://checkout.stripe.com/c/pay/cs_test_de',
    ]);

    $this->postJson("/booking/{$this->business->slug}/book", book2a())
        ->assertCreated();
});

test('Stripe API failure on Checkout create cancels the booking and returns 422', function () {
    // Wire a failing mock by hand via the FakeStripeClient plumbing — the
    // helper methods don't expose a "throw" variant, so we hand-assemble
    // the mock chain. The fake binds itself into the container on
    // construction, so Stripe calls route through Mockery.
    $fake = new FakeStripeClient;
    $sessions = Mockery::mock();
    $sessions->shouldReceive('create')
        ->andThrow(new ApiConnectionException('network'));
    $fake->client->checkout = (object) ['sessions' => $sessions];

    $response = $this->postJson("/booking/{$this->business->slug}/book", book2a());

    $response->assertStatus(422);
    expect(Booking::firstOrFail()->status)->toBe(BookingStatus::Cancelled);
});

test('can_accept_online_payments false drops the online branch even when payment_mode = online', function () {
    // Unsupported-market drift (D-150): account still 'active' on Stripe
    // but country not in supported_countries. canAcceptOnlinePayments()
    // already returns false; the controller must fall through to offline
    // rather than attempt Checkout.
    $this->connectedAccount->update(['country' => 'DE']);
    // No Stripe mock — a call would fail the expectation.

    $this->postJson("/booking/{$this->business->slug}/book", book2a())
        ->assertCreated();

    $booking = Booking::firstOrFail();
    // Offline-path outcome: no checkout, payment_status = not_applicable,
    // status follows the Business's confirmation_mode (default auto).
    expect($booking->payment_status)->toBe(PaymentStatus::NotApplicable)
        ->and($booking->stripe_checkout_session_id)->toBeNull();
});

test('snapshot column does not change when Business.payment_mode changes after booking creation', function () {
    FakeStripeClient::for($this)->mockCheckoutSessionCreateOnAccount('acct_test_2a', [
        'id' => 'cs_test_snap',
        'url' => 'https://checkout.stripe.com/c/pay/cs_test_snap',
    ]);

    $this->postJson("/booking/{$this->business->slug}/book", book2a());
    $booking = Booking::firstOrFail();
    expect($booking->payment_mode_at_creation)->toBe('online');

    // Admin flips the Business's mode post-booking. The snapshot column
    // must NOT reinterpret.
    $this->business->update(['payment_mode' => PaymentMode::Offline]);

    expect($booking->fresh()->payment_mode_at_creation)->toBe('online');
});

test('manual booking writes payment_mode_at_creation=offline + payment_status=not_applicable (decision #30)', function () {
    // Direct factory call mirrors the Dashboard\BookingController::store
    // invariant — the carve-out is enforced at every writer.
    $booking = Booking::factory()->manual()->create([
        'business_id' => $this->business->id,
        'provider_id' => $this->provider->id,
        'service_id' => $this->service->id,
    ]);

    expect($booking->source)->toBe(BookingSource::Manual)
        ->and($booking->payment_mode_at_creation)->toBe('offline')
        ->and($booking->payment_status)->toBe(PaymentStatus::NotApplicable);
});

test('google_calendar booking writes payment_mode_at_creation=offline (decision #30)', function () {
    $booking = Booking::factory()->external()->create([
        'business_id' => $this->business->id,
        'provider_id' => $this->provider->id,
    ]);

    expect($booking->source)->toBe(BookingSource::GoogleCalendar)
        ->and($booking->payment_mode_at_creation)->toBe('offline')
        ->and($booking->payment_status)->toBe(PaymentStatus::NotApplicable);
});

test('GIST constraint holds the slot while the booking sits pending + awaiting_payment', function () {
    // Locked roadmap decision #12: the reserve-then-pay pattern relies on
    // the existing D-065/D-066 GIST exclusion constraint
    // (status IN ('pending', 'confirmed')) to block double-booking during
    // the Checkout window. AwaitingPayment bookings live at status=Pending
    // and therefore participate in the exclusion.
    FakeStripeClient::for($this)->mockCheckoutSessionCreateOnAccount('acct_test_2a', [
        'id' => 'cs_test_gist',
        'url' => 'https://checkout.stripe.com/c/pay/cs_test_gist',
    ]);

    $this->postJson("/booking/{$this->business->slug}/book", book2a())
        ->assertCreated();

    // The first booking is now pending + awaiting_payment. A concurrent
    // attempt on the SAME slot must be rejected with 409 by the GIST
    // invariant before it reaches any Stripe call.
    $response = $this->postJson("/booking/{$this->business->slug}/book", book2a([
        'email' => 'second-customer@example.test',
        'name' => 'Second Customer',
    ]));

    $response->assertStatus(409);
});
