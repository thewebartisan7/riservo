<?php

namespace Tests\Support\Billing;

use Illuminate\Support\Str;
use Mockery;
use Mockery\MockInterface;
use Stripe\Account as StripeAccount;
use Stripe\AccountLink as StripeAccountLink;
use Stripe\Balance as StripeBalance;
use Stripe\BillingPortal\Session as StripeBillingPortalSession;
use Stripe\Checkout\Session as StripeCheckoutSession;
use Stripe\Collection as StripeCollection;
use Stripe\Exception\PermissionException;
use Stripe\LoginLink as StripeLoginLink;
use Stripe\Payout as StripePayout;
use Stripe\Refund as StripeRefund;
use Stripe\StripeClient;
use Stripe\Tax\Settings as StripeTaxSettings;
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

    private ?MockInterface $refunds = null;

    private ?MockInterface $balance = null;

    private ?MockInterface $payouts = null;

    private ?MockInterface $taxSettings = null;

    /** @var array<string, list<string>> */
    private array $externalUrls = [];

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

    public static function forBrowser(TestCase $test): self
    {
        $fake = new self;
        $fake->registerBrowserDefaults();

        return $fake;
    }

    public static function bind(): self
    {
        return new self;
    }

    /**
     * @return list<string>
     */
    public function externalUrls(?string $bucket = null): array
    {
        if ($bucket !== null) {
            return $this->externalUrls[$bucket] ?? [];
        }

        return array_merge(...array_values($this->externalUrls ?: [[]]));
    }

    public function lastExternalUrl(?string $bucket = null): ?string
    {
        $urls = $this->externalUrls($bucket);

        return $urls === [] ? null : $urls[array_key_last($urls)];
    }

    public static function isExternalStripeStubUrl(string $url): bool
    {
        return (bool) preg_match('#^https://stripe\.test/external/[0-9a-f-]{36}$#', $url);
    }

    private function fakeExternalUrl(string $bucket): string
    {
        $url = 'https://stripe.test/external/'.(string) Str::uuid();
        $this->externalUrls[$bucket][] = $url;

        return $url;
    }

    private function registerBrowserDefaults(): void
    {
        $this->ensureAccounts();
        $this->ensureAccountLinks();
        $this->ensureCheckoutSessions();

        // Browser-mode defaults keep later E2E tests on fake hosted-Stripe
        // URLs and tolerate webhook-triggered account refreshes. Feature-test
        // mode stays strict unless individual tests opt into these mocks.
        $this->accounts
            ->shouldReceive('createLoginLink')
            ->byDefault()
            ->andReturnUsing(fn (string $accountId) => StripeLoginLink::constructFrom([
                'object' => 'login_link',
                'created' => time(),
                'url' => $this->fakeExternalUrl('login_link'),
            ]));

        $this->accounts
            ->shouldReceive('retrieve')
            ->byDefault()
            ->andReturnUsing(fn (string $accountId) => StripeAccount::constructFrom([
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
            ]));

        $this->accountLinks
            ->shouldReceive('create')
            ->byDefault()
            ->andReturnUsing(fn () => StripeAccountLink::constructFrom([
                'object' => 'account_link',
                'created' => time(),
                'expires_at' => time() + 300,
                'url' => $this->fakeExternalUrl('account_link'),
            ]));

        $this->checkoutSessions
            ->shouldReceive('create')
            ->byDefault()
            ->andReturnUsing(fn () => StripeCheckoutSession::constructFrom([
                'id' => 'cs_test_'.Str::ulid(),
                'url' => $this->fakeExternalUrl('checkout_session'),
            ]));
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
            'url' => $this->fakeExternalUrl('checkout_session'),
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
    // Connected-account-level methods (PAYMENTS Session 2a+) live below this
    // bucket. They enforce the inverse contract: `['stripe_account' => $acct]`
    // MUST be present and match the account id the test wires. A call that
    // crosses categories (e.g. accounts.create with a stripe_account header,
    // or checkout.sessions.create without one) is a test failure by
    // construction.
    //
    // Session 2b added mockRefundCreate / mockRefundCreateFails. Session 4
    // adds mockBalanceRetrieve / mockPayoutsList / mockTaxSettingsRetrieve
    // (connected-account-level, header asserted PRESENT) and
    // mockLoginLinkCreate (platform-level, header asserted ABSENT).
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
            'url' => $this->fakeExternalUrl('account_link'),
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

    /**
     * Stub `$stripe->accounts->createLoginLink($acct, ...)`. Platform-level —
     * asserts the `stripe_account` per-request option is absent (PAYMENTS
     * Session 4: the Stripe Express dashboard login link is minted on the
     * platform, with the connected account id passed as the first POSITIONAL
     * arg, not as a header).
     *
     * @param  array<string, mixed>  $response
     */
    public function mockLoginLinkCreate(string $expectedAccountId, array $response = []): self
    {
        $this->ensureAccounts();

        $link = StripeLoginLink::constructFrom(array_merge([
            'object' => 'login_link',
            'created' => time(),
            'url' => $this->fakeExternalUrl('login_link'),
        ], $response));

        $this->accounts
            ->shouldReceive('createLoginLink')
            ->withArgs(function ($parentId, $params = null, $opts = null) use ($expectedAccountId) {
                return $parentId === $expectedAccountId
                    && $this->assertPlatformLevel((array) ($opts ?? []));
            })
            ->andReturn($link);

        return $this;
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

    // ================================================================
    // Connected-account-level methods — PAYMENTS Session 2a surface
    // (locked roadmap decision #5). Every call here MUST carry
    // ['stripe_account' => $expectedAccountId] in the per-request options;
    // a missing key or mismatched value fails the withArgs matcher.
    // ================================================================

    /**
     * Stub `$stripe->checkout->sessions->create([...], ['stripe_account' =>
     * $acct])`. Asserts the `stripe_account` header is PRESENT and matches
     * the given account id.
     *
     * Returns a Checkout Session object carrying `id`, `url`, and any extra
     * keys the caller passes in `$response`. The `id` and `url` default to
     * unique test strings so multiple calls within one test produce distinct
     * sessions.
     *
     * @param  array<string, mixed>  $response
     */
    public function mockCheckoutSessionCreateOnAccount(
        string $expectedAccountId,
        array $response = [],
    ): self {
        $this->ensureCheckoutSessions();

        $id = (string) ($response['id'] ?? 'cs_test_'.uniqid());
        $session = StripeCheckoutSession::constructFrom(array_merge([
            'id' => $id,
            'url' => $this->fakeExternalUrl('checkout_session'),
        ], $response));

        $this->checkoutSessions
            ->shouldReceive('create')
            ->withArgs(function ($params, $opts = []) use ($expectedAccountId) {
                return $this->assertConnectedAccountLevel((array) $opts, $expectedAccountId);
            })
            ->andReturn($session);

        return $this;
    }

    /**
     * Stub `$stripe->checkout->sessions->retrieve($id, ['stripe_account' =>
     * $acct])`. Asserts the header is PRESENT and matches, AND asserts the
     * requested session id matches.
     *
     * Defaults to a happy-path (paid, CHF 50, sync) session so most Session 2a
     * tests need only pass the account id + session id. Tests that exercise
     * the async branch, a non-paid state, or an account-mismatch scenario
     * override via `$response`.
     *
     * @param  array<string, mixed>  $response
     */
    public function mockCheckoutSessionRetrieveOnAccount(
        string $expectedAccountId,
        string $sessionId,
        array $response = [],
    ): self {
        $this->ensureCheckoutSessions();

        $session = StripeCheckoutSession::constructFrom(array_merge([
            'id' => $sessionId,
            'payment_status' => 'paid',
            'amount_total' => 5000,
            'currency' => 'chf',
            'payment_intent' => 'pi_test_'.uniqid(),
            'account' => $expectedAccountId,
        ], $response));

        $this->checkoutSessions
            ->shouldReceive('retrieve')
            ->withArgs(function ($id, $params = null, $opts = null) use ($expectedAccountId, $sessionId) {
                // Codex Round 3 (F1): Stripe SDK signature is
                // `retrieve($id, $params, $opts)`. The `stripe_account`
                // header MUST ride on the 3rd arg. Passing it as the 2nd
                // arg drops the header in production. Assert strictly:
                // $params === null and $opts carries the header.
                return $id === $sessionId
                    && $params === null
                    && is_array($opts)
                    && $this->assertConnectedAccountLevel($opts, $expectedAccountId);
            })
            ->andReturn($session);

        return $this;
    }

    /**
     * Stub `$stripe->refunds->create([...], ['stripe_account' => $acct,
     * 'idempotency_key' => 'riservo_refund_{uuid}'])` per locked roadmap
     * decision #36.
     *
     * Asserts:
     *  - the `stripe_account` per-request option is PRESENT and matches;
     *  - `$opts['idempotency_key']` starts with `'riservo_refund_'`;
     *  - when `$expectedIdempotencyKeyExact !== null`, the key matches
     *    exactly (use this in tests that need to tie the call back to a
     *    specific `booking_refunds.uuid`).
     *
     * @param  array<string, mixed>  $response
     */
    public function mockRefundCreate(
        string $expectedAccountId,
        ?string $expectedIdempotencyKeyExact = null,
        array $response = [],
    ): self {
        $this->ensureRefunds();

        $id = (string) ($response['id'] ?? 're_test_'.uniqid());
        $refund = StripeRefund::constructFrom(array_merge([
            'id' => $id,
            'status' => 'succeeded',
            'amount' => 5000,
            'currency' => 'chf',
        ], $response));

        // PAYMENTS Session 3: capping each registered expectation at `->once()`
        // lets tests stack multiple `mockRefundCreate` calls in a row (e.g.
        // two partial refunds on the same booking) and see them consumed in
        // registration order. Without `once()`, Mockery's default "0 or more"
        // makes the first-registered expectation win every match — breaking
        // the "two consecutive refunds return distinct `re_test_...` ids"
        // contract that RefundServicePartialTest and AdminManualRefundTest
        // rely on.
        $this->refunds
            ->shouldReceive('create')
            ->once()
            ->withArgs(function ($params, $opts = []) use ($expectedAccountId, $expectedIdempotencyKeyExact) {
                if (! $this->assertConnectedAccountLevel((array) $opts, $expectedAccountId)) {
                    return false;
                }
                $key = $opts['idempotency_key'] ?? null;
                if (! is_string($key) || ! str_starts_with($key, 'riservo_refund_')) {
                    return false;
                }
                if ($expectedIdempotencyKeyExact !== null && $key !== $expectedIdempotencyKeyExact) {
                    return false;
                }

                return true;
            })
            ->andReturn($refund);

        return $this;
    }

    /**
     * Stub `$stripe->refunds->create(...)` to throw a Stripe permission
     * error — used to exercise the disconnected-account fallback path in
     * `RefundService::refund` (locked roadmap decision #36).
     */
    public function mockRefundCreateFails(
        string $expectedAccountId,
        string $message = 'This account does not have permission to perform this operation.',
    ): self {
        $this->ensureRefunds();

        $this->refunds
            ->shouldReceive('create')
            ->withArgs(function ($params, $opts = []) use ($expectedAccountId) {
                return $this->assertConnectedAccountLevel((array) $opts, $expectedAccountId);
            })
            ->andThrow(new PermissionException($message));

        return $this;
    }

    /**
     * Stub `$stripe->balance->retrieve(null, ['stripe_account' => $acct])`
     * (PAYMENTS Session 4 — locked roadmap decision #24). Connected-account-
     * level — asserts the header is PRESENT and matches.
     *
     * Default response carries one available + one pending arm denominated in
     * CHF so the happy-path tests don't need to override anything.
     *
     * @param  array<string, mixed>  $response
     */
    public function mockBalanceRetrieve(string $expectedAccountId, array $response = []): self
    {
        $this->ensureBalance();

        $balance = StripeBalance::constructFrom(array_merge([
            'object' => 'balance',
            'available' => [
                ['amount' => 31200, 'currency' => 'chf', 'source_types' => (object) ['card' => 31200]],
            ],
            'pending' => [
                ['amount' => 5400, 'currency' => 'chf', 'source_types' => (object) ['card' => 5400]],
            ],
        ], $response));

        $this->balance
            ->shouldReceive('retrieve')
            ->withArgs(function ($params = null, $opts = null) use ($expectedAccountId) {
                return $params === null
                    && is_array($opts)
                    && $this->assertConnectedAccountLevel($opts, $expectedAccountId);
            })
            ->andReturn($balance);

        return $this;
    }

    /**
     * Stub `$stripe->payouts->all(['limit' => …], ['stripe_account' => $acct])`
     * (PAYMENTS Session 4 — locked roadmap decision #24). Connected-account-
     * level — asserts the header is PRESENT and matches; the matcher is
     * permissive on `$params` because the controller may pass `limit` or
     * other pagination args.
     *
     * Default response = three realistic payout rows. Override `data` to test
     * empty-state / many-row branches.
     *
     * @param  array<string, mixed>  $response
     */
    public function mockPayoutsList(string $expectedAccountId, array $response = []): self
    {
        $this->ensurePayouts();

        $defaultData = [
            StripePayout::constructFrom([
                'id' => 'po_test_'.uniqid(),
                'amount' => 12500,
                'currency' => 'chf',
                'status' => 'paid',
                'arrival_date' => time() + 86400,
                'created' => time() - 3600,
            ]),
            StripePayout::constructFrom([
                'id' => 'po_test_'.uniqid(),
                'amount' => 8700,
                'currency' => 'chf',
                'status' => 'in_transit',
                'arrival_date' => time() + 172800,
                'created' => time() - 7200,
            ]),
            StripePayout::constructFrom([
                'id' => 'po_test_'.uniqid(),
                'amount' => 21000,
                'currency' => 'chf',
                'status' => 'paid',
                'arrival_date' => time() - 86400,
                'created' => time() - 172800,
            ]),
        ];

        $collection = StripeCollection::constructFrom(array_merge([
            'object' => 'list',
            'data' => $defaultData,
            'has_more' => false,
            'url' => '/v1/payouts',
        ], $response));

        $this->payouts
            ->shouldReceive('all')
            ->withArgs(function ($params = null, $opts = null) use ($expectedAccountId) {
                return is_array($opts)
                    && $this->assertConnectedAccountLevel($opts, $expectedAccountId);
            })
            ->andReturn($collection);

        return $this;
    }

    /**
     * Stub `$stripe->tax->settings->retrieve(null, ['stripe_account' => $acct])`
     * (PAYMENTS Session 4 — locked roadmap decision #11: Stripe Tax not
     * configured surfaces a warning banner, never a hard block).
     * Connected-account-level — asserts the header is PRESENT and matches.
     *
     * Default response uses `status = 'active'`; pass `['status' => 'pending']`
     * to exercise the not-configured banner.
     *
     * @param  array<string, mixed>  $response
     */
    public function mockTaxSettingsRetrieve(string $expectedAccountId, array $response = []): self
    {
        $this->ensureTaxSettings();

        $settings = StripeTaxSettings::constructFrom(array_merge([
            'object' => 'tax.settings',
            'status' => 'active',
            'defaults' => (object) ['tax_behavior' => 'inclusive', 'tax_code' => null],
        ], $response));

        $this->taxSettings
            ->shouldReceive('retrieve')
            ->withArgs(function ($params = null, $opts = null) use ($expectedAccountId) {
                return $params === null
                    && is_array($opts)
                    && $this->assertConnectedAccountLevel($opts, $expectedAccountId);
            })
            ->andReturn($settings);

        return $this;
    }

    /**
     * Connected-account-level calls MUST carry `stripe_account => $acct` in
     * the per-request options. A missing key or mismatched value fails the
     * Mockery `withArgs` matcher, surfacing as a "method not expected"
     * diagnostic that names the errant call.
     */
    private function assertConnectedAccountLevel(array $opts, string $expectedAccountId): bool
    {
        $header = $opts['stripe_account'] ?? null;

        return is_string($header) && $header === $expectedAccountId;
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

    private function ensureRefunds(): void
    {
        if ($this->refunds !== null) {
            return;
        }

        $this->refunds = Mockery::mock();
        $this->client->refunds = $this->refunds;
    }

    private function ensureBalance(): void
    {
        if ($this->balance !== null) {
            return;
        }

        $this->balance = Mockery::mock();
        $this->client->balance = $this->balance;
    }

    private function ensurePayouts(): void
    {
        if ($this->payouts !== null) {
            return;
        }

        $this->payouts = Mockery::mock();
        $this->client->payouts = $this->payouts;
    }

    private function ensureTaxSettings(): void
    {
        if ($this->taxSettings !== null) {
            return;
        }

        // The SDK exposes Tax\Settings via `$stripe->tax->settings->retrieve`.
        // Mock the chain: tax service factory carries a `settings` property
        // that responds to `retrieve`.
        $taxFactory = Mockery::mock();
        $this->taxSettings = Mockery::mock();
        $taxFactory->settings = $this->taxSettings;
        $this->client->tax = $taxFactory;
    }
}
