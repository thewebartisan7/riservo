<?php

namespace Tests\Support\Billing;

use Mockery;
use Mockery\MockInterface;
use Stripe\Account as StripeAccount;
use Stripe\AccountLink as StripeAccountLink;
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

    private ?MockInterface $accounts = null;

    private ?MockInterface $accountLinks = null;

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

    // ================================================================
    // Platform-level methods — PAYMENTS Session 1 surface (D-109, #38).
    // Every call here MUST be invoked WITHOUT a `stripe_account` per-request
    // option. The header-absent assertion is enforced via `withArgs` on the
    // Mockery chain: a call that carries `stripe_account` in the options
    // array fails the expectation match, and Mockery surfaces the failure
    // as a clear test error.
    //
    // Session 2+ contract — DO NOT IMPLEMENT IN SESSION 1.
    // Connected-account-level methods (per-request option
    //     ['stripe_account' => $accountId]
    // MUST be present and asserted) will live alongside this bucket:
    //   - mockCheckoutSessionCreateOnAccount   (Session 2a)
    //   - mockCheckoutSessionRetrieveOnAccount (Session 2a)
    //   - mockRefundCreate                      (Session 2b — also asserts
    //       idempotency_key = 'riservo_refund_'.{booking_refund_uuid}
    //       per locked roadmap decision #36)
    //   - mockTaxSettingsRetrieve               (Session 4)
    //   - mockBalanceRetrieve                   (Session 4)
    //   - mockPayoutsList                       (Session 4)
    //
    // A call that crosses categories (e.g. accounts.create with a
    // stripe_account header, or checkout.sessions.create without one) is a
    // test failure by construction.
    // ================================================================

    /**
     * Stub `$stripe->accounts->create([...])`. Platform-level — asserts the
     * `stripe_account` per-request option is absent. Codex Round-2 fix
     * (D-124): also asserts that an `idempotency_key` per-request option is
     * present — the controller MUST pass one so a crash between the Stripe
     * response and the local DB insert does not orphan a duplicate Express
     * account on retry.
     *
     * Pass `expectedIdempotencyKey` to assert the exact key (default: any
     * non-empty string).
     *
     * @param  array<string, mixed>  $response
     */
    public function mockAccountCreate(array $response = [], ?string $expectedIdempotencyKey = null): self
    {
        $this->ensureAccounts();

        $account = StripeAccount::constructFrom(array_merge([
            'id' => 'acct_test_'.uniqid(),
            'country' => 'CH',
            'default_currency' => null,
            'charges_enabled' => false,
            'payouts_enabled' => false,
            'details_submitted' => false,
        ], $response));

        $this->accounts
            ->shouldReceive('create')
            ->withArgs(function ($params, $opts = []) use ($expectedIdempotencyKey) {
                if (! $this->assertPlatformLevel((array) $opts)) {
                    return false;
                }

                $key = $opts['idempotency_key'] ?? null;
                if (! is_string($key) || $key === '') {
                    return false;
                }

                if ($expectedIdempotencyKey !== null && $key !== $expectedIdempotencyKey) {
                    return false;
                }

                return true;
            })
            ->andReturn($account);

        return $this;
    }

    /**
     * Stub `$stripe->accounts->retrieve($accountId)`. Platform-level — asserts
     * the `stripe_account` per-request option is absent.
     *
     * @param  array<string, mixed>  $response
     */
    public function mockAccountRetrieve(string $accountId, array $response = []): self
    {
        $this->ensureAccounts();

        $account = StripeAccount::constructFrom(array_merge([
            'id' => $accountId,
            'country' => 'CH',
            'default_currency' => 'chf',
            'charges_enabled' => true,
            'payouts_enabled' => true,
            'details_submitted' => true,
            'requirements' => (object) [
                'currently_due' => [],
                'disabled_reason' => null,
            ],
        ], $response));

        $this->accounts
            ->shouldReceive('retrieve')
            ->withArgs(function ($id, $opts = []) use ($accountId) {
                return $id === $accountId && $this->assertPlatformLevel((array) $opts);
            })
            ->andReturn($account);

        return $this;
    }

    /**
     * Stub `$stripe->accountLinks->create([...])`. Platform-level — asserts
     * the `stripe_account` per-request option is absent.
     *
     * Codex Round-10 (D-144): when `$expectedSignedAccountParam` is passed,
     * the matcher also enforces that the `refresh_url` and `return_url`
     * params are `URL::temporarySignedRoute` outputs carrying the given
     * `account=…` query param — i.e. they carry both a `signature=` query
     * and the expected `account=` value. This proves the controller is
     * sending Stripe URLs the corresponding `signed`-middleware routes
     * will accept on return/refresh; a plain `route(...)` URL fails the
     * matcher here, not silently in production.
     *
     * @param  array<string, mixed>  $response
     */
    public function mockAccountLinkCreate(array $response = [], ?string $expectedSignedAccountParam = null): self
    {
        $this->ensureAccountLinks();

        $link = StripeAccountLink::constructFrom(array_merge([
            'object' => 'account_link',
            'created' => time(),
            'expires_at' => time() + 300,
            'url' => 'https://connect.stripe.com/setup/c/acct_test/link_'.uniqid(),
        ], $response));

        $this->accountLinks
            ->shouldReceive('create')
            ->withArgs(function ($params, $opts = []) use ($expectedSignedAccountParam) {
                if (! $this->assertPlatformLevel((array) $opts)) {
                    return false;
                }

                if ($expectedSignedAccountParam === null) {
                    return true;
                }

                $refresh = is_array($params) ? ($params['refresh_url'] ?? null) : null;
                $return = is_array($params) ? ($params['return_url'] ?? null) : null;

                return $this->assertSignedReturnUrl($refresh, $expectedSignedAccountParam)
                    && $this->assertSignedReturnUrl($return, $expectedSignedAccountParam);
            })
            ->andReturn($link);

        return $this;
    }

    /**
     * A URL matches when it carries both a `signature=` query param (proof
     * it came from `URL::temporarySignedRoute`) and an `account=` query
     * param equal to the expected acct_id. Rejection here surfaces as a
     * Mockery "method not expected" failure naming the bad call — matching
     * the D-144 contract: a plain `route(...)` URL would never pass the
     * `signed` middleware on return, so it must not pass in tests either.
     */
    private function assertSignedReturnUrl(mixed $url, string $expectedAccount): bool
    {
        if (! is_string($url) || $url === '') {
            return false;
        }

        $query = parse_url($url, PHP_URL_QUERY);
        if (! is_string($query) || $query === '') {
            return false;
        }

        parse_str($query, $parts);

        $signatureOk = isset($parts['signature']) && is_string($parts['signature']) && $parts['signature'] !== '';
        $accountOk = isset($parts['account']) && $parts['account'] === $expectedAccount;

        return $signatureOk && $accountOk;
    }

    private function assertPlatformLevel(array $opts): bool
    {
        // Platform-level calls MUST NOT carry a stripe_account header. Returning
        // false here causes Mockery's withArgs to reject the match; the test
        // then fails with a "method not expected" diagnostic naming the errant
        // call — exactly the signal the FakeStripeClient contract (D-109 / #38)
        // is engineered to produce.
        return ! array_key_exists('stripe_account', $opts);
    }

    private function ensureAccounts(): void
    {
        if ($this->accounts !== null) {
            return;
        }

        $this->accounts = Mockery::mock();
        $this->client->accounts = $this->accounts;
    }

    private function ensureAccountLinks(): void
    {
        if ($this->accountLinks !== null) {
            return;
        }

        $this->accountLinks = Mockery::mock();
        $this->client->accountLinks = $this->accountLinks;
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
