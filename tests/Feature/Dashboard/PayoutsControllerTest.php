<?php

/**
 * PAYMENTS Session 4 — admin-only Payouts surface.
 *
 * Asserts:
 *  - admin sees balance + payouts list + tax-not-configured banner trigger
 *    + non-CH banner trigger (locked decisions #11 / #43);
 *  - staff cannot reach the page (locked decision #19 — money is admin-only);
 *  - cross-tenant access is impossible (locked decision #45 + tenant scoping);
 *  - Stripe API failure falls back to the cached state with a stale flag,
 *    or to an unreachable banner when the cache is empty;
 *  - the Stripe Express dashboard login link is minted on click (single-use)
 *    via the platform-level `accounts.createLoginLink` API (no stripe_account
 *    header — the FakeStripeClient mock asserts the absence);
 *  - the cache key isolates data per business so admins of different
 *    Businesses never see each other's payout data.
 */

use App\Enums\BusinessMemberRole;
use App\Http\Controllers\Dashboard\PayoutsController;
use App\Models\Business;
use App\Models\StripeConnectedAccount;
use App\Models\User;
use Illuminate\Support\Facades\Cache;
use Stripe\Exception\ApiConnectionException;
use Tests\Support\Billing\FakeStripeClient;

beforeEach(function () {
    config(['payments.supported_countries' => ['CH']]);
    Cache::flush();

    $this->business = Business::factory()->onboarded()->create(['country' => 'CH']);
    $this->admin = User::factory()->create();
    attachAdmin($this->business, $this->admin);
});

test('admin sees the payouts page with balance + last payouts + schedule (happy path)', function () {
    StripeConnectedAccount::factory()
        ->active()
        ->for($this->business)
        ->create(['stripe_account_id' => 'acct_test_happy']);

    FakeStripeClient::for($this)
        ->mockBalanceRetrieve('acct_test_happy')
        ->mockPayoutsList('acct_test_happy')
        ->mockAccountRetrieve('acct_test_happy', [
            'settings' => (object) [
                'payouts' => (object) [
                    'schedule' => (object) [
                        'interval' => 'daily',
                        'delay_days' => 2,
                        'weekly_anchor' => null,
                        'monthly_anchor' => null,
                    ],
                ],
            ],
        ])
        ->mockTaxSettingsRetrieve('acct_test_happy');

    $this->actingAs($this->admin)
        ->withSession(['current_business_id' => $this->business->id])
        ->get('/dashboard/payouts')
        ->assertOk()
        ->assertInertia(
            fn ($page) => $page
                ->component('dashboard/payouts')
                ->where('account.status', 'active')
                ->where('account.country', 'CH')
                ->where('account.chargesEnabled', true)
                ->where('payouts.available.0.amount', 31200)
                ->where('payouts.available.0.currency', 'chf')
                ->where('payouts.pending.0.amount', 5400)
                ->where('payouts.schedule.interval', 'daily')
                ->where('payouts.schedule.delay_days', 2)
                ->where('payouts.tax_status', 'active')
                ->where('payouts.stale', false)
                ->where('payouts.error', null)
                ->where('supportedCountries', ['CH'])
                ->has('payouts.payouts', 3)
        );
});

test('staff cannot reach the payouts page', function () {
    StripeConnectedAccount::factory()
        ->active()
        ->for($this->business)
        ->create(['stripe_account_id' => 'acct_test_staff_block']);

    $staff = User::factory()->create();
    $this->business->members()->attach($staff, ['role' => BusinessMemberRole::Staff->value]);

    $this->actingAs($staff)
        ->withSession(['current_business_id' => $this->business->id])
        ->get('/dashboard/payouts')
        ->assertForbidden();
});

test('admin pinned to a foreign business id is silently re-pinned to their own and sees their own data (cross-tenant attack neutralised by ResolveTenantContext + tenant scoping)', function () {
    // Attack vector: admin of A manually sets `current_business_id` to
    // Business B's id (cookie tampering / session injection). The
    // ResolveTenantContext middleware's rule 1 only honors the pin when
    // the user has an active membership in that business; otherwise it
    // falls through to rule 2 (oldest membership) and rewrites the
    // session value (rule 3, self-healing). The PayoutsController then
    // resolves `tenant()->business()` and reads `stripeConnectedAccount`
    // from THAT business — never the foreign id from the (silently
    // discarded) session value.
    StripeConnectedAccount::factory()
        ->active()
        ->for($this->business)
        ->create(['stripe_account_id' => 'acct_test_a_real']);

    $businessB = Business::factory()->onboarded()->create(['country' => 'CH']);
    StripeConnectedAccount::factory()
        ->active()
        ->for($businessB)
        ->create(['stripe_account_id' => 'acct_test_b_attack_target']);

    // Only A's mocks registered; if cross-tenant scoping leaks, the
    // controller would call B's endpoint and Mockery would throw "method
    // not expected" — the test would fail loudly rather than silently
    // returning B's data.
    FakeStripeClient::for($this)
        ->mockBalanceRetrieve('acct_test_a_real', ['available' => [['amount' => 1, 'currency' => 'chf']]])
        ->mockPayoutsList('acct_test_a_real', ['data' => []])
        ->mockAccountRetrieve('acct_test_a_real')
        ->mockTaxSettingsRetrieve('acct_test_a_real');

    $this->actingAs($this->admin)
        ->withSession(['current_business_id' => $businessB->id]) // attack: pinned to B
        ->get('/dashboard/payouts')
        ->assertOk()
        ->assertInertia(
            fn ($page) => $page
                ->where('payouts.available.0.amount', 1) // A's seeded value
        );
});

test('admin of another business sees only their own payouts (cross-tenant isolation)', function () {
    // Business A has a connected account, Business B has a different one.
    // Admin of B logs in (pinned to B) and hits /dashboard/payouts; the
    // controller resolves the row via tenant()->business()->stripeConnectedAccount,
    // so B's row is returned — never A's. The mock for A's account is
    // registered but never consumed; Mockery would surface a missed
    // expectation only if the test asserted strict-mode, which it does
    // not. The assertion that proves isolation is the data shape:
    // available.0.amount === 999 (B's seeded mock) NOT 31200 (the default).
    StripeConnectedAccount::factory()
        ->active()
        ->for($this->business)
        ->create(['stripe_account_id' => 'acct_test_a']);

    $businessB = Business::factory()->onboarded()->create(['country' => 'CH']);
    StripeConnectedAccount::factory()
        ->active()
        ->for($businessB)
        ->create(['stripe_account_id' => 'acct_test_b']);

    $adminB = User::factory()->create();
    attachAdmin($businessB, $adminB);

    FakeStripeClient::for($this)
        ->mockBalanceRetrieve('acct_test_b', [
            'available' => [['amount' => 999, 'currency' => 'chf']],
            'pending' => [['amount' => 0, 'currency' => 'chf']],
        ])
        ->mockPayoutsList('acct_test_b', ['data' => []])
        ->mockAccountRetrieve('acct_test_b')
        ->mockTaxSettingsRetrieve('acct_test_b');

    $this->actingAs($adminB)
        ->withSession(['current_business_id' => $businessB->id])
        ->get('/dashboard/payouts')
        ->assertOk()
        ->assertInertia(
            fn ($page) => $page
                ->where('account.country', 'CH')
                ->where('payouts.available.0.amount', 999) // B's seeded value, not A's
        );
});

test('Stripe API failure falls back to cached state with stale flag', function () {
    StripeConnectedAccount::factory()
        ->active()
        ->for($this->business)
        ->create(['stripe_account_id' => 'acct_test_stale']);

    // Pre-seed the cache with a payload whose `fetched_at` is older than
    // the 60s freshness window — the controller will skip the fresh-cache
    // short-circuit, attempt to re-fetch, see Stripe explode, and fall
    // back to this cached payload with `stale: true`.
    Cache::put(PayoutsController::cacheKey($this->business->id, 'acct_test_stale'), [
        'available' => [['amount' => 12345, 'currency' => 'chf']],
        'pending' => [['amount' => 0, 'currency' => 'chf']],
        'payouts' => [],
        'schedule' => ['interval' => 'daily', 'delay_days' => 2, 'weekly_anchor' => null, 'monthly_anchor' => null],
        'tax_status' => 'active',
        'fetched_at' => now()->subMinutes(10)->toIso8601String(), // outside 60s freshness window
        'stale' => false,
        'error' => null,
    ], now()->addDay());

    // Mock balance.retrieve to throw — simulates Stripe unavailable.
    $stripe = FakeStripeClient::for($this);
    $stripe->client->balance = Mockery::mock();
    $stripe->client->balance
        ->shouldReceive('retrieve')
        ->andThrow(new ApiConnectionException('Network failure'));

    $this->actingAs($this->admin)
        ->withSession(['current_business_id' => $this->business->id])
        ->get('/dashboard/payouts')
        ->assertOk()
        ->assertInertia(
            fn ($page) => $page
                ->where('payouts.stale', true)
                ->where('payouts.available.0.amount', 12345)
                ->where('payouts.error', null)
        );
});

test('Stripe API failure with empty cache renders the unreachable banner', function () {
    StripeConnectedAccount::factory()
        ->active()
        ->for($this->business)
        ->create(['stripe_account_id' => 'acct_test_unreachable']);

    Cache::flush();

    $stripe = FakeStripeClient::for($this);
    $stripe->client->balance = Mockery::mock();
    $stripe->client->balance
        ->shouldReceive('retrieve')
        ->andThrow(new ApiConnectionException('Network failure'));

    $this->actingAs($this->admin)
        ->withSession(['current_business_id' => $this->business->id])
        ->get('/dashboard/payouts')
        ->assertOk()
        ->assertInertia(
            fn ($page) => $page
                ->where('payouts.stale', true)
                ->where('payouts.error', 'unreachable')
                ->where('payouts.available', [])
        );
});

test('unverified pending account renders the resume-onboarding prompt with no Stripe calls', function () {
    StripeConnectedAccount::factory()
        ->pending()
        ->for($this->business)
        ->create(['stripe_account_id' => 'acct_test_pending']);

    // No FakeStripeClient mocks registered — any Stripe call here would
    // explode at the un-mocked StripeClient binding (the dev's Stripe key
    // is not present in tests). The controller's pending-state branch
    // skips all four Stripe calls.

    $this->actingAs($this->admin)
        ->withSession(['current_business_id' => $this->business->id])
        ->get('/dashboard/payouts')
        ->assertOk()
        ->assertInertia(
            fn ($page) => $page
                ->where('account.status', 'pending')
                ->where('payouts', null)
        );
});

test('disabled account renders the disabled-state panel with no Stripe calls', function () {
    StripeConnectedAccount::factory()
        ->disabled()
        ->for($this->business)
        ->create(['stripe_account_id' => 'acct_test_disabled']);

    $this->actingAs($this->admin)
        ->withSession(['current_business_id' => $this->business->id])
        ->get('/dashboard/payouts')
        ->assertOk()
        ->assertInertia(
            fn ($page) => $page
                ->where('account.status', 'disabled')
                ->where('account.requirementsDisabledReason', 'rejected.fraud')
                ->where('payouts', null)
        );
});

test('Stripe Tax not configured exposes a non-active tax_status the page banner reads', function () {
    StripeConnectedAccount::factory()
        ->active()
        ->for($this->business)
        ->create(['stripe_account_id' => 'acct_test_tax_pending']);

    FakeStripeClient::for($this)
        ->mockBalanceRetrieve('acct_test_tax_pending')
        ->mockPayoutsList('acct_test_tax_pending')
        ->mockAccountRetrieve('acct_test_tax_pending')
        ->mockTaxSettingsRetrieve('acct_test_tax_pending', ['status' => 'pending']);

    $this->actingAs($this->admin)
        ->withSession(['current_business_id' => $this->business->id])
        ->get('/dashboard/payouts')
        ->assertOk()
        ->assertInertia(
            fn ($page) => $page
                ->where('payouts.tax_status', 'pending')
        );
});

test('non-CH connected account surfaces unsupported_market status (locked decision #43)', function () {
    StripeConnectedAccount::factory()
        ->active()
        ->for($this->business)
        ->create([
            'stripe_account_id' => 'acct_test_de',
            'country' => 'DE',
        ]);

    FakeStripeClient::for($this)
        ->mockBalanceRetrieve('acct_test_de')
        ->mockPayoutsList('acct_test_de')
        ->mockAccountRetrieve('acct_test_de', ['country' => 'DE'])
        ->mockTaxSettingsRetrieve('acct_test_de');

    $this->actingAs($this->admin)
        ->withSession(['current_business_id' => $this->business->id])
        ->get('/dashboard/payouts')
        ->assertOk()
        ->assertInertia(
            fn ($page) => $page
                ->where('account.status', 'unsupported_market')
                ->where('account.country', 'DE')
                ->where('supportedCountries', ['CH'])
        );
});

test('flipping supported_countries to include DE removes the unsupported-market status', function () {
    config(['payments.supported_countries' => ['CH', 'DE']]);
    Cache::flush();

    StripeConnectedAccount::factory()
        ->active()
        ->for($this->business)
        ->create([
            'stripe_account_id' => 'acct_test_de_supported',
            'country' => 'DE',
        ]);

    FakeStripeClient::for($this)
        ->mockBalanceRetrieve('acct_test_de_supported')
        ->mockPayoutsList('acct_test_de_supported')
        ->mockAccountRetrieve('acct_test_de_supported', ['country' => 'DE'])
        ->mockTaxSettingsRetrieve('acct_test_de_supported');

    $this->actingAs($this->admin)
        ->withSession(['current_business_id' => $this->business->id])
        ->get('/dashboard/payouts')
        ->assertOk()
        ->assertInertia(
            fn ($page) => $page
                ->where('account.status', 'active') // not 'unsupported_market'
                ->where('supportedCountries', ['CH', 'DE'])
        );
});

test('login-link mint POST calls accounts.createLoginLink without a stripe_account header and returns the URL as JSON', function () {
    StripeConnectedAccount::factory()
        ->active()
        ->for($this->business)
        ->create(['stripe_account_id' => 'acct_test_login_link']);

    FakeStripeClient::for($this)
        ->mockLoginLinkCreate('acct_test_login_link', [
            'url' => 'https://connect.stripe.com/express/acct_test_login_link/login_test_xyz',
        ]);

    $this->actingAs($this->admin)
        ->withSession(['current_business_id' => $this->business->id])
        ->post('/dashboard/payouts/login-link')
        ->assertOk()
        ->assertJson([
            'url' => 'https://connect.stripe.com/express/acct_test_login_link/login_test_xyz',
        ]);
});

test('login-link mint Stripe failure surfaces as Inertia validation error envelope (G-005)', function () {
    StripeConnectedAccount::factory()
        ->active()
        ->for($this->business)
        ->create(['stripe_account_id' => 'acct_test_login_fail']);

    $stripe = FakeStripeClient::for($this);
    $stripe->client->accounts = Mockery::mock();
    $stripe->client->accounts
        ->shouldReceive('createLoginLink')
        ->andThrow(new ApiConnectionException('Network failure'));

    $this->actingAs($this->admin)
        ->withSession(['current_business_id' => $this->business->id])
        ->postJson('/dashboard/payouts/login-link')
        ->assertStatus(422)
        ->assertJsonValidationErrors(['login_link']);
});

test('staff cannot mint a Stripe Express login link', function () {
    StripeConnectedAccount::factory()
        ->active()
        ->for($this->business)
        ->create(['stripe_account_id' => 'acct_test_login_staff']);

    $staff = User::factory()->create();
    $this->business->members()->attach($staff, ['role' => BusinessMemberRole::Staff->value]);

    $this->actingAs($staff)
        ->withSession(['current_business_id' => $this->business->id])
        ->post('/dashboard/payouts/login-link')
        ->assertForbidden();
});

test('login-link mint refuses 404 when no connected-account row exists', function () {
    $this->actingAs($this->admin)
        ->withSession(['current_business_id' => $this->business->id])
        ->post('/dashboard/payouts/login-link')
        ->assertNotFound();
});

test('login-link mint refuses 422 when the account is disabled', function () {
    StripeConnectedAccount::factory()
        ->disabled()
        ->for($this->business)
        ->create(['stripe_account_id' => 'acct_test_login_disabled']);

    $this->actingAs($this->admin)
        ->withSession(['current_business_id' => $this->business->id])
        ->post('/dashboard/payouts/login-link')
        ->assertStatus(422);
});

test('cache key isolates data per business so admins of different businesses never see each other data', function () {
    StripeConnectedAccount::factory()
        ->active()
        ->for($this->business)
        ->create(['stripe_account_id' => 'acct_test_iso_a']);

    $businessB = Business::factory()->onboarded()->create(['country' => 'CH']);
    StripeConnectedAccount::factory()
        ->active()
        ->for($businessB)
        ->create(['stripe_account_id' => 'acct_test_iso_b']);

    $adminB = User::factory()->create();
    attachAdmin($businessB, $adminB);

    // Admin A reads first → caches A's data under the (business, account) key.
    FakeStripeClient::for($this)
        ->mockBalanceRetrieve('acct_test_iso_a', ['available' => [['amount' => 1111, 'currency' => 'chf']]])
        ->mockPayoutsList('acct_test_iso_a', ['data' => []])
        ->mockAccountRetrieve('acct_test_iso_a')
        ->mockTaxSettingsRetrieve('acct_test_iso_a')
        ->mockBalanceRetrieve('acct_test_iso_b', ['available' => [['amount' => 2222, 'currency' => 'chf']]])
        ->mockPayoutsList('acct_test_iso_b', ['data' => []])
        ->mockAccountRetrieve('acct_test_iso_b')
        ->mockTaxSettingsRetrieve('acct_test_iso_b');

    $this->actingAs($this->admin)
        ->withSession(['current_business_id' => $this->business->id])
        ->get('/dashboard/payouts')
        ->assertInertia(fn ($page) => $page->where('payouts.available.0.amount', 1111));

    // Admin B reads next → caches B's data under their own (business, account)
    // key. If the cache key were shared, B would see A's 1111 here.
    $this->actingAs($adminB)
        ->withSession(['current_business_id' => $businessB->id])
        ->get('/dashboard/payouts')
        ->assertInertia(fn ($page) => $page->where('payouts.available.0.amount', 2222));

    // Verify both keys exist independently.
    expect(Cache::has(PayoutsController::cacheKey($this->business->id, 'acct_test_iso_a')))->toBeTrue();
    expect(Cache::has(PayoutsController::cacheKey($businessB->id, 'acct_test_iso_b')))->toBeTrue();
});

test('disconnect forgets the payouts cache for the disconnected account (F-006)', function () {
    StripeConnectedAccount::factory()
        ->active()
        ->for($this->business)
        ->create(['stripe_account_id' => 'acct_test_forget']);

    $cacheKey = PayoutsController::cacheKey($this->business->id, 'acct_test_forget');
    Cache::put($cacheKey, [
        'available' => [['amount' => 99999, 'currency' => 'chf']],
        'pending' => [],
        'payouts' => [],
        'schedule' => null,
        'tax_status' => null,
        'fetched_at' => now()->toIso8601String(),
        'stale' => false,
        'error' => null,
    ], now()->addDay());

    $this->actingAs($this->admin)
        ->withSession(['current_business_id' => $this->business->id])
        ->delete(route('settings.connected-account.disconnect'))
        ->assertRedirect();

    expect(Cache::has($cacheKey))->toBeFalse();
});
