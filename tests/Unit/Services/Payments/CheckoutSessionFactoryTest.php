<?php

use App\Exceptions\Payments\UnsupportedCountryForCheckout;
use App\Models\Booking;
use App\Models\Business;
use App\Models\Service;
use App\Models\StripeConnectedAccount;
use App\Services\Payments\CheckoutSessionFactory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Stripe\Checkout\Session;
use Tests\Support\Billing\FakeStripeClient;
use Tests\TestCase;

// Pest's default Unit testcase is plain PHPUnit\Framework\TestCase — no
// DB resolver. This file uses factories, so extend like the existing
// Unit/Models tests do (see tests/Unit/Models/BusinessConnectedAccountTest).
uses(TestCase::class, RefreshDatabase::class);

beforeEach(function () {
    $this->business = Business::factory()->create([
        'country' => 'CH',
        'timezone' => 'Europe/Zurich',
    ]);
    $this->service = Service::factory()->create([
        'business_id' => $this->business->id,
        'price' => 50.00,
        'duration_minutes' => 60,
    ]);
    $this->account = StripeConnectedAccount::factory()->active()->for($this->business)->create([
        'stripe_account_id' => 'acct_test_factory',
        'country' => 'CH',
        'default_currency' => 'chf',
    ]);
});

function factoryBooking(): Booking
{
    return Booking::factory()->awaitingPayment()->create([
        'business_id' => test()->business->id,
        'service_id' => test()->service->id,
    ]);
}

test('assertSupportedCountry passes for an account in the supported set', function () {
    $factory = app(CheckoutSessionFactory::class);

    $factory->assertSupportedCountry($this->account);
})->throwsNoExceptions();

test('assertSupportedCountry throws UnsupportedCountryForCheckout for a drifted account', function () {
    $this->account->update(['country' => 'DE']);
    $factory = app(CheckoutSessionFactory::class);

    $factory->assertSupportedCountry($this->account);
})->throws(UnsupportedCountryForCheckout::class);

test('assertSupportedCountry passes after supported_countries is expanded', function () {
    $this->account->update(['country' => 'DE']);
    config(['payments.supported_countries' => ['CH', 'DE']]);
    $factory = app(CheckoutSessionFactory::class);

    $factory->assertSupportedCountry($this->account);
})->throwsNoExceptions();

test('create includes twint in payment_method_types on CH accounts', function () {
    $booking = factoryBooking();

    $capturedParams = null;
    $fake = new FakeStripeClient;
    $sessions = Mockery::mock();
    $sessions->shouldReceive('create')
        ->withArgs(function ($params, $opts) use (&$capturedParams) {
            $capturedParams = $params;

            return ($opts['stripe_account'] ?? null) === 'acct_test_factory';
        })
        ->andReturn(Session::constructFrom([
            'id' => 'cs_test',
            'url' => 'https://checkout.stripe.com/c/pay/cs_test',
        ]));
    $fake->client->checkout = (object) ['sessions' => $sessions];

    app(CheckoutSessionFactory::class)->create($booking, $this->service, $this->business, $this->account);

    expect($capturedParams['payment_method_types'])->toBe(['card', 'twint']);
});

test('create falls back to card-only when the country is not in twint_countries', function () {
    config(['payments.supported_countries' => ['CH', 'DE']]);
    config(['payments.twint_countries' => ['CH']]); // DE falls back to card-only
    $this->account->update(['country' => 'DE']);

    $booking = factoryBooking();

    $capturedParams = null;
    $fake = new FakeStripeClient;
    $sessions = Mockery::mock();
    $sessions->shouldReceive('create')
        ->withArgs(function ($params, $opts) use (&$capturedParams) {
            $capturedParams = $params;

            return true;
        })
        ->andReturn(Session::constructFrom([
            'id' => 'cs_test',
            'url' => 'https://checkout.stripe.com/c/pay/cs_test',
        ]));
    $fake->client->checkout = (object) ['sessions' => $sessions];

    app(CheckoutSessionFactory::class)->create($booking, $this->service, $this->business, $this->account);

    expect($capturedParams['payment_method_types'])->toBe(['card']);
});

test('create passes the app locale to Stripe for every supported language (decision #39)', function (string $locale) {
    app()->setLocale($locale);
    $booking = factoryBooking();

    $capturedParams = null;
    $fake = new FakeStripeClient;
    $sessions = Mockery::mock();
    $sessions->shouldReceive('create')
        ->withArgs(function ($params, $opts) use (&$capturedParams) {
            $capturedParams = $params;

            return true;
        })
        ->andReturn(Session::constructFrom([
            'id' => 'cs_test',
            'url' => 'https://checkout.stripe.com/c/pay/cs_test',
        ]));
    $fake->client->checkout = (object) ['sessions' => $sessions];

    app(CheckoutSessionFactory::class)->create($booking, $this->service, $this->business, $this->account);

    expect($capturedParams['locale'])->toBe($locale);
})->with(['it', 'de', 'fr', 'en']);

test('create falls back to auto for an unsupported locale', function () {
    app()->setLocale('ja');
    $booking = factoryBooking();

    $capturedParams = null;
    $fake = new FakeStripeClient;
    $sessions = Mockery::mock();
    $sessions->shouldReceive('create')
        ->withArgs(function ($params, $opts) use (&$capturedParams) {
            $capturedParams = $params;

            return true;
        })
        ->andReturn(Session::constructFrom([
            'id' => 'cs_test',
            'url' => 'https://checkout.stripe.com/c/pay/cs_test',
        ]));
    $fake->client->checkout = (object) ['sessions' => $sessions];

    app(CheckoutSessionFactory::class)->create($booking, $this->service, $this->business, $this->account);

    expect($capturedParams['locale'])->toBe('auto');
});

test('create embeds booking metadata so webhook handlers can branch pre-DB', function () {
    $booking = factoryBooking();

    $capturedParams = null;
    $fake = new FakeStripeClient;
    $sessions = Mockery::mock();
    $sessions->shouldReceive('create')
        ->withArgs(function ($params, $opts) use (&$capturedParams) {
            $capturedParams = $params;

            return true;
        })
        ->andReturn(Session::constructFrom([
            'id' => 'cs_test',
            'url' => 'https://checkout.stripe.com/c/pay/cs_test',
        ]));
    $fake->client->checkout = (object) ['sessions' => $sessions];

    app(CheckoutSessionFactory::class)->create($booking, $this->service, $this->business, $this->account);

    expect($capturedParams['metadata'])->toBe([
        'riservo_booking_id' => (string) $booking->id,
        'riservo_business_id' => (string) $this->business->id,
        'riservo_payment_mode_at_creation' => $booking->payment_mode_at_creation,
    ])
        ->and($capturedParams['client_reference_id'])->toBe((string) $booking->id);
});
