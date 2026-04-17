<?php

namespace Tests\Support\Billing;

use Mockery;
use Mockery\MockInterface;
use Stripe\BillingPortal\Session as StripeBillingPortalSession;
use Stripe\Checkout\Session as StripeCheckoutSession;
use Stripe\StripeClient;
use Tests\TestCase;

/**
 * Programmable double for the Stripe SDK client. Bound into the Laravel
 * container so Cashier::stripe() resolves to this mock for the duration of
 * the test (D-095).
 *
 * Mirrors the MVPC-2 FakeCalendarProvider pattern: chain the mocks-you-need
 * methods, leave the rest unset so unexpected Stripe calls explode loudly.
 *
 * Usage:
 *   $stripe = FakeStripeClient::for($this);
 *   $stripe->mockCheckoutSession(['url' => 'https://checkout.stripe.com/c/pay/cs_test_123']);
 *   ...
 */
class FakeStripeClient
{
    public MockInterface $client;

    private ?MockInterface $checkoutSessions = null;

    private ?MockInterface $billingPortalSessions = null;

    private ?MockInterface $subscriptions = null;

    private ?MockInterface $customers = null;

    public function __construct()
    {
        $this->client = Mockery::mock(StripeClient::class);

        // `app()->instance(...)` is bypassed when the resolver passes parameters
        // (Cashier::stripe() does — `app(StripeClient::class, ['config' => …])`).
        // `bind()` with a closure ignores the parameters and returns the mock
        // every time, which is the seam D-095 relies on.
        app()->bind(StripeClient::class, fn () => $this->client);
    }

    public static function for(TestCase $test): self
    {
        return new self;
    }

    public static function bind(): self
    {
        return new self;
    }

    /**
     * Stub Stripe's `$stripe->checkout->sessions->create([...])` chain to return
     * the given response object. The session URL is what Cashier redirects to.
     *
     * @param  array<string, mixed>  $response
     */
    public function mockCheckoutSession(array $response = []): self
    {
        $this->ensureCheckoutSessions();

        $session = StripeCheckoutSession::constructFrom(array_merge([
            'id' => 'cs_test_'.uniqid(),
            'url' => 'https://checkout.stripe.com/c/pay/cs_test_123',
        ], $response));

        $this->checkoutSessions
            ->shouldReceive('create')
            ->andReturn($session);

        return $this;
    }

    /**
     * Stub `$stripe->billingPortal->sessions->create([...])` for the portal flow.
     *
     * @param  array<string, mixed>  $response
     */
    public function mockBillingPortalSession(array $response = []): self
    {
        $this->ensureBillingPortalSessions();

        $session = StripeBillingPortalSession::constructFrom(array_merge([
            'id' => 'bps_test_'.uniqid(),
            'url' => 'https://billing.stripe.com/p/session/cs_test_123',
        ], $response));

        $this->billingPortalSessions
            ->shouldReceive('create')
            ->andReturn($session);

        return $this;
    }

    /**
     * Stub `$stripe->subscriptions->update($id, [...])` used by cancel + resume
     * (and address syncs from automatic_tax flow).
     *
     * @param  array<string, mixed>  $response
     */
    public function mockSubscriptionUpdate(array $response = []): self
    {
        $this->ensureSubscriptions();

        $this->subscriptions
            ->shouldReceive('update')
            ->andReturn((object) array_merge([
                'id' => 'sub_test_'.uniqid(),
                'status' => 'active',
                'cancel_at_period_end' => true,
            ], $response));

        return $this;
    }

    /**
     * Stub `$stripe->customers->update($id, [...])` for the address-sync writes
     * Cashier issues when checkout()/redirectToBillingPortal() runs with
     * automatic_tax enabled.
     */
    public function mockCustomerUpdate(): self
    {
        $this->ensureCustomers();

        $this->customers
            ->shouldReceive('update')
            ->andReturn((object) ['id' => 'cus_test_'.uniqid()]);

        return $this;
    }

    /**
     * Stub `$stripe->customers->create([...])` used when Cashier upserts a
     * Stripe customer for a billable that does not yet have a `stripe_id`.
     */
    public function mockCustomerCreate(string $stripeId = 'cus_test_new'): self
    {
        $this->ensureCustomers();

        $this->customers
            ->shouldReceive('create')
            ->andReturn((object) ['id' => $stripeId]);

        return $this;
    }

    private function ensureCheckoutSessions(): void
    {
        if ($this->checkoutSessions !== null) {
            return;
        }

        $checkoutService = Mockery::mock();
        $this->checkoutSessions = Mockery::mock();
        $checkoutService->sessions = $this->checkoutSessions;
        $this->client->checkout = $checkoutService;
    }

    private function ensureBillingPortalSessions(): void
    {
        if ($this->billingPortalSessions !== null) {
            return;
        }

        $portalService = Mockery::mock();
        $this->billingPortalSessions = Mockery::mock();
        $portalService->sessions = $this->billingPortalSessions;
        $this->client->billingPortal = $portalService;
    }

    private function ensureSubscriptions(): void
    {
        if ($this->subscriptions !== null) {
            return;
        }

        $this->subscriptions = Mockery::mock();
        $this->client->subscriptions = $this->subscriptions;
    }

    private function ensureCustomers(): void
    {
        if ($this->customers !== null) {
            return;
        }

        $this->customers = Mockery::mock();
        $this->client->customers = $this->customers;
    }
}
