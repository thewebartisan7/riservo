<?php

use App\Enums\PaymentMode;
use App\Models\Business;
use App\Models\StripeConnectedAccount;
use App\Models\User;
use Illuminate\Support\Facades\URL;
use Laravel\Cashier\Subscription;
use Tests\Support\Billing\FakeStripeClient;

beforeEach(function () {
    $this->business = Business::factory()->onboarded()->create([
        'payment_mode' => PaymentMode::Offline,
    ]);
    $this->admin = User::factory()->create();
    attachAdmin($this->business, $this->admin);
});

/**
 * Build the signed URL Stripe would send the user to on return/refresh.
 * Matches the mintAccountLink() shape in the controller (D-142).
 */
function signedConnectedAccountUrl(string $routeName, string $accountId): string
{
    return URL::temporarySignedRoute(
        $routeName,
        now()->addHour(),
        ['account' => $accountId],
    );
}

test('admin sees the not-connected state when no row exists', function () {
    $this->actingAs($this->admin)
        ->get('/dashboard/settings/connected-account')
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('dashboard/settings/connected-account')
            ->where('account', null)
        );
});

test('admin can create a connected account and is redirected to Stripe-hosted onboarding', function () {
    FakeStripeClient::for($this)
        ->mockAccountCreate(
            [
                'id' => 'acct_test_create',
                'country' => 'CH',
            ],
            // D-124 + Round-3 D-125: idempotency key includes a per-attempt
            // nonce (count of soft-deleted rows for this business) so a
            // disconnect+reconnect within Stripe's 24h window doesn't replay
            // the original acct_… and collide with the soft-deleted row.
            // First attempt for a fresh business → nonce = 0.
            'riservo_connect_account_create_'.$this->business->id.'_attempt_0',
        )
        ->mockAccountLinkCreate([
            'url' => 'https://connect.stripe.com/setup/c/acct_test_create/onboard_xyz',
        ]);

    // Inertia::location falls back to Redirect::away on a non-Inertia POST,
    // so we test the simpler 302 + Location form. Production uses the Inertia
    // path (409 + X-Inertia-Location) — the controller code is identical for
    // both, returning the same `Inertia::location($link->url)` call.
    $response = $this->actingAs($this->admin)
        ->post('/dashboard/settings/connected-account');

    $response->assertStatus(302);
    expect($response->headers->get('Location'))
        ->toBe('https://connect.stripe.com/setup/c/acct_test_create/onboard_xyz');

    $row = StripeConnectedAccount::where('business_id', $this->business->id)->first();
    expect($row)->not->toBeNull()
        ->and($row->stripe_account_id)->toBe('acct_test_create')
        ->and($row->details_submitted)->toBeFalse();
});

test('initial onboarding passes Stripe signed return/refresh URLs carrying the acct_id (D-144, codex Round 10)', function () {
    // Codex Round 10 finding: create() used to pass plain `route(...)` URLs
    // to Stripe for refresh_url and return_url. The corresponding routes
    // now require `signed` middleware, so the very first KYC return 403d.
    // create() must mint the same signed URLs that resume() and
    // resumeExpired() do — the FakeStripeClient matcher enforces both
    // `signature=` and `account=acct_id` in each URL. A plain `route(...)`
    // URL would fail the matcher here, not silently in production.
    FakeStripeClient::for($this)
        ->mockAccountCreate(
            ['id' => 'acct_test_signed_urls', 'country' => 'CH'],
            'riservo_connect_account_create_'.$this->business->id.'_attempt_0',
        )
        ->mockAccountLinkCreate(
            ['url' => 'https://connect.stripe.com/setup/c/acct_test_signed_urls/go'],
            'acct_test_signed_urls',
        );

    $this->actingAs($this->admin)
        ->post('/dashboard/settings/connected-account')
        ->assertStatus(302);
});

test('admin returning from completed Stripe KYC sees the active state', function () {
    StripeConnectedAccount::factory()
        ->pending()
        ->for($this->business)
        ->create(['stripe_account_id' => 'acct_test_active']);

    FakeStripeClient::for($this)->mockAccountRetrieve('acct_test_active', [
        'country' => 'CH',
        'default_currency' => 'chf',
        'charges_enabled' => true,
        'payouts_enabled' => true,
        'details_submitted' => true,
        'requirements' => (object) ['currently_due' => [], 'disabled_reason' => null],
    ]);

    $this->actingAs($this->admin)
        ->get(signedConnectedAccountUrl('settings.connected-account.refresh', 'acct_test_active'))
        ->assertRedirect('/dashboard/settings/connected-account');

    $this->actingAs($this->admin)
        ->get('/dashboard/settings/connected-account')
        ->assertInertia(fn ($page) => $page->where('account.status', 'active'));
});

test('GET /refresh is Stripes return_url: syncs state, redirects to settings page, no bounce to Stripe (D-137, codex Round 7)', function () {
    // Codex Round 7 finding: the return_url used to bounce pending /
    // incomplete admins back to Stripe immediately, trapping them. Now
    // GET /refresh is pure sync + redirect to settings. Admin-initiated
    // resume goes through POST /resume.
    StripeConnectedAccount::factory()
        ->incomplete()
        ->for($this->business)
        ->create(['stripe_account_id' => 'acct_test_refresh_return']);

    FakeStripeClient::for($this)
        ->mockAccountRetrieve('acct_test_refresh_return', [
            'country' => 'CH',
            'charges_enabled' => false,
            'payouts_enabled' => false,
            'details_submitted' => true,
            'requirements' => (object) [
                'currently_due' => ['external_account'],
                'disabled_reason' => null,
            ],
        ]);

    $this->actingAs($this->admin)
        ->get(signedConnectedAccountUrl('settings.connected-account.refresh', 'acct_test_refresh_return'))
        ->assertRedirect('/dashboard/settings/connected-account');
});

test('POST /resume mints a fresh Account Link and bounces admin to Stripe (D-137, codex Round 7)', function () {
    StripeConnectedAccount::factory()
        ->incomplete()
        ->for($this->business)
        ->create(['stripe_account_id' => 'acct_test_resume']);

    FakeStripeClient::for($this)
        ->mockAccountRetrieve('acct_test_resume', [
            'country' => 'CH',
            'charges_enabled' => false,
            'payouts_enabled' => false,
            'details_submitted' => true,
            'requirements' => (object) [
                'currently_due' => ['external_account'],
                'disabled_reason' => null,
            ],
        ])
        ->mockAccountLinkCreate([
            'url' => 'https://connect.stripe.com/setup/c/acct_test_resume/go',
        ]);

    $response = $this->actingAs($this->admin)
        ->post('/dashboard/settings/connected-account/resume');

    $response->assertStatus(302);
    expect($response->headers->get('Location'))
        ->toBe('https://connect.stripe.com/setup/c/acct_test_resume/go');
});

test('GET /resume-expired is Stripes refresh_url: mints fresh Account Link when link expired mid-flow (D-137)', function () {
    StripeConnectedAccount::factory()
        ->pending()
        ->for($this->business)
        ->create(['stripe_account_id' => 'acct_test_link_expired']);

    FakeStripeClient::for($this)
        ->mockAccountRetrieve('acct_test_link_expired', [
            'charges_enabled' => false,
            'payouts_enabled' => false,
            'details_submitted' => false,
            'requirements' => (object) ['currently_due' => [], 'disabled_reason' => null],
        ])
        ->mockAccountLinkCreate([
            'url' => 'https://connect.stripe.com/setup/c/acct_test_link_expired/retry',
        ]);

    $response = $this->actingAs($this->admin)
        ->get(signedConnectedAccountUrl('settings.connected-account.resume-expired', 'acct_test_link_expired'));

    $response->assertStatus(302);
    expect($response->headers->get('Location'))
        ->toBe('https://connect.stripe.com/setup/c/acct_test_link_expired/retry');
});

test('refresh/resume/resumeExpired/disconnect serialise row access via DB::transaction + lockForUpdate (D-148, codex Round 12 — structural)', function () {
    // Codex Round 12 finding: a concurrent disconnect racing
    // refresh/resume/resumeExpired between the pre-check and the Stripe
    // call could mint a fresh Account Link (or revive local state) for a
    // just-disconnected account. The fix is a row-level `lockForUpdate`
    // inside a `DB::transaction` with an inside-lock `trashed()` re-check.
    // Concurrency can't be reliably simulated in Pest without a real
    // multi-connection harness, so this test asserts the structural
    // invariant by inspecting the controller source. If someone removes
    // the lock or the inside-lock trashed check, this test fails loudly.
    $src = file_get_contents(app_path('Http/Controllers/Dashboard/Settings/ConnectedAccountController.php'));

    // `refresh` + `disconnect` keep the lock inline. `resume` +
    // `resumeExpired` delegate to the shared `runResumeInLock()` helper
    // for DRY. Either location is acceptable — assert each public
    // handler either inlines the lock OR calls the helper, and the
    // helper itself carries the lock.
    foreach (['refresh', 'resume', 'resumeExpired', 'disconnect'] as $method) {
        $start = strpos($src, "public function {$method}(");
        expect($start)->not->toBeFalse("handler {$method}() not found");

        $afterStart = substr($src, $start + strlen("public function {$method}("));
        $nextHandler = strpos($afterStart, "\n    public function ");
        $nextPrivate = strpos($afterStart, "\n    private function ");
        $end = min(array_filter([$nextHandler, $nextPrivate], fn ($i) => $i !== false));
        $body = $end !== false ? substr($afterStart, 0, $end) : $afterStart;

        $hasInlineLock = str_contains($body, 'DB::transaction')
            && str_contains($body, 'lockForUpdate')
            && str_contains($body, 'trashed()');
        $delegatesToHelper = str_contains($body, 'runResumeInLock(');

        expect($hasInlineLock || $delegatesToHelper)
            ->toBeTrue("handler {$method}() must either inline DB::transaction+lockForUpdate+trashed() re-check or delegate to runResumeInLock()");
    }

    // The shared helper MUST carry all three invariants.
    $helperStart = strpos($src, 'private function runResumeInLock(');
    expect($helperStart)->not->toBeFalse('runResumeInLock() helper missing');
    $helperBody = substr($src, $helperStart);
    expect($helperBody)->toContain('DB::transaction')
        ->and($helperBody)->toContain('lockForUpdate')
        ->and($helperBody)->toContain('trashed()');
});

test('GET /refresh on a trashed row (stale signed URL after disconnect) redirects with error (D-146, codex Round 11)', function () {
    // Codex Round 11 finding: the signed return_url lives 24h (D-142) but
    // disconnect() soft-deletes the row — the URL is not invalidated, and
    // resolveSignedAccountRow() uses withTrashed(), so an admin who
    // completes Stripe KYC AFTER disconnecting would revive local state
    // for a row the product presents as disconnected. Guard: trashed rows
    // redirect to the settings page; no accounts.retrieve call; no sync.
    $row = StripeConnectedAccount::factory()
        ->pending()
        ->for($this->business)
        ->create(['stripe_account_id' => 'acct_test_stale_refresh']);
    $signedUrl = signedConnectedAccountUrl('settings.connected-account.refresh', 'acct_test_stale_refresh');
    $row->delete(); // disconnect happens AFTER the URL was minted

    // No FakeStripeClient mock registered on purpose: a successful guard
    // means we never reach accounts.retrieve. A regression where the
    // trashed check is skipped triggers the unstubbed call and Mockery
    // surfaces the regression immediately.
    $this->actingAs($this->admin)
        ->get($signedUrl)
        ->assertRedirect('/dashboard/settings/connected-account')
        ->assertSessionHas('error');
});

test('GET /resume-expired on a trashed row (stale signed URL after disconnect) redirects with error (D-146, codex Round 11)', function () {
    // Same stale-URL class as above, but via the refresh_url path Stripe
    // hits when the Account Link expires mid-flow. Both paths must
    // reject trashed rows so a disconnect is actually final and no fresh
    // Account Link can be minted for a disconnected account.
    $row = StripeConnectedAccount::factory()
        ->pending()
        ->for($this->business)
        ->create(['stripe_account_id' => 'acct_test_stale_resume']);
    $signedUrl = signedConnectedAccountUrl('settings.connected-account.resume-expired', 'acct_test_stale_resume');
    $row->delete();

    $this->actingAs($this->admin)
        ->get($signedUrl)
        ->assertRedirect('/dashboard/settings/connected-account')
        ->assertSessionHas('error');
});

test('GET /resume-expired refuses on a read-only business (D-145, codex Round 10)', function () {
    // Codex Round 10 finding: resumeExpired() is a GET, so billing.writable
    // lets it through unconditionally (D-090) — but the handler mints a
    // fresh Stripe Account Link (a NEW payment surface), which D-116
    // forbids on a read-only business. Signed URL TTL is 24h, so a lapsed
    // admin who saved the link pre-lapse could keep opening new onboarding
    // surfaces. Guard explicitly: redirect to settings.billing with the
    // standard read-only flash; no Stripe call made.
    Subscription::factory()
        ->for($this->business, 'owner')
        ->canceled()
        ->withPrice('price_test_monthly')
        ->create(['ends_at' => now()->subDay()]);
    $this->business->refresh();
    expect($this->business->canWrite())->toBeFalse();

    StripeConnectedAccount::factory()
        ->pending()
        ->for($this->business)
        ->create(['stripe_account_id' => 'acct_test_link_expired_readonly']);

    // No Stripe mocks registered on purpose: a successful guard means we
    // never reach accountLinks->create. If we regress, the unstubbed call
    // on a freshly-bound mock explodes with "method not expected" and
    // surfaces the regression immediately.
    $this->actingAs($this->admin)
        ->get(signedConnectedAccountUrl('settings.connected-account.resume-expired', 'acct_test_link_expired_readonly'))
        ->assertRedirect('/dashboard/settings/billing')
        ->assertSessionHas('error');
});

test('GET /refresh without a valid signature is rejected (D-142, codex Round 9)', function () {
    // Codex Round 9 finding: refresh was an unsigned mutating GET. Now the
    // route carries `signed` middleware. A plain request without the
    // signature query params should be rejected before the controller runs.
    StripeConnectedAccount::factory()
        ->active()
        ->for($this->business)
        ->create(['stripe_account_id' => 'acct_test_unsigned']);

    $this->actingAs($this->admin)
        ->get('/dashboard/settings/connected-account/refresh?account=acct_test_unsigned')
        ->assertStatus(403);
});

test('GET /refresh with a signed URL for another tenants account is rejected (D-142)', function () {
    // Wrong-tenant risk: admin A holds a signed URL (phished or leaked)
    // for business B's account where they have NO membership. Controller
    // resolves the row by the signed account id, then verifies the user
    // has an active Admin membership on the row's business — 403
    // otherwise. D-147 changed the check from "session tenant matches"
    // to "user has admin membership on row's business", but a user who
    // is NOT a member of business B still fails authorisation.
    $businessB = Business::factory()->onboarded()->create();
    StripeConnectedAccount::factory()
        ->active()
        ->for($businessB)
        ->create(['stripe_account_id' => 'acct_test_other_tenant']);

    $this->actingAs($this->admin) // admin of $this->business (A), not a member of B
        ->get(signedConnectedAccountUrl('settings.connected-account.refresh', 'acct_test_other_tenant'))
        ->assertStatus(403);
});

test('GET /refresh resolves authorization from the signed rows business, re-pinning a mis-pinned session (D-147, codex Round 12)', function () {
    // Codex Round 12 finding: a multi-business admin whose session
    // expired mid-onboarding is re-pinned to their oldest membership by
    // ResolveTenantContext rule 2 on login. A valid signed URL for a
    // DIFFERENT business the admin also belongs to would 403 under the
    // pre-D-147 check ("current tenant owns the row"). Now: the
    // controller derives authorisation from the signed row's business
    // and re-pins the session to it so downstream tenant()/Inertia reads
    // stay consistent for the remainder of the request.
    $businessB = Business::factory()->onboarded()->create();
    attachAdmin($businessB, $this->admin); // admin of BOTH A and B

    // Session pinned to A (the default pick after login when A is the
    // oldest membership). Start the session by hitting an auth'd page
    // while in tenant A, so current_business_id = A.
    $this->actingAs($this->admin)
        ->get('/dashboard/settings/connected-account')
        ->assertOk();

    expect(session('current_business_id'))->toBe($this->business->id);

    StripeConnectedAccount::factory()
        ->pending()
        ->for($businessB)
        ->create(['stripe_account_id' => 'acct_test_multi_biz']);

    FakeStripeClient::for($this)->mockAccountRetrieve('acct_test_multi_biz', [
        'charges_enabled' => false,
        'payouts_enabled' => false,
        'details_submitted' => true,
        'requirements' => (object) ['currently_due' => ['external_account'], 'disabled_reason' => null],
    ]);

    // The signed URL is for B's account; the admin's session is pinned
    // to A. Pre-D-147 this would 403. Post-D-147 the controller re-pins
    // to B and proceeds with the sync.
    $this->actingAs($this->admin)
        ->get(signedConnectedAccountUrl('settings.connected-account.refresh', 'acct_test_multi_biz'))
        ->assertRedirect('/dashboard/settings/connected-account');

    // Session is now re-pinned to B — subsequent requests land on B.
    expect(session('current_business_id'))->toBe($businessB->id);
});

test('GET /refresh refuses when user has only staff membership on the signed rows business (D-147)', function () {
    // A staff member of B holds a signed URL for B's account. Even if
    // their session is pinned to A (where they are admin), D-147's
    // Admin-on-row's-business check rejects the request because Stripe
    // onboarding is admin-only on the ROW's business, not the session
    // tenant. The controller does not re-pin to a business where the
    // user only has staff access.
    $businessB = Business::factory()->onboarded()->create();
    attachStaff($businessB, $this->admin); // staff of B, admin of A
    StripeConnectedAccount::factory()
        ->active()
        ->for($businessB)
        ->create(['stripe_account_id' => 'acct_test_staff_on_row']);

    $this->actingAs($this->admin)
        ->get(signedConnectedAccountUrl('settings.connected-account.refresh', 'acct_test_staff_on_row'))
        ->assertStatus(403);
});

test('POST /resume refuses for active connected account (D-137)', function () {
    StripeConnectedAccount::factory()
        ->active()
        ->for($this->business)
        ->create(['stripe_account_id' => 'acct_test_already_active']);

    FakeStripeClient::for($this)
        ->mockAccountRetrieve('acct_test_already_active', [
            'charges_enabled' => true,
            'payouts_enabled' => true,
            'details_submitted' => true,
            'requirements' => (object) ['currently_due' => [], 'disabled_reason' => null],
        ]);

    $this->actingAs($this->admin)
        ->post('/dashboard/settings/connected-account/resume')
        ->assertRedirect('/dashboard/settings/connected-account')
        ->assertSessionHas('error');
});

test('admin can disconnect; row is soft-deleted; stripe_account_id retained; payment_mode forced to offline', function () {
    $this->business->forceFill(['payment_mode' => PaymentMode::Online->value])->save();

    $row = StripeConnectedAccount::factory()
        ->active()
        ->for($this->business)
        ->create(['stripe_account_id' => 'acct_test_disconnect']);

    $this->actingAs($this->admin)
        ->delete('/dashboard/settings/connected-account')
        ->assertRedirect('/dashboard/settings/connected-account');

    $row->refresh();
    expect($row->trashed())->toBeTrue();
    expect($row->stripe_account_id)->toBe('acct_test_disconnect');
    expect($this->business->fresh()->payment_mode)->toBe(PaymentMode::Offline);
});

test('disconnect after a reconnect cycle trashes the NEW active row, not the stale historical one (D-140, codex Round 8)', function () {
    // Codex Round 8 finding: the previous `withTrashed()->first()` lookup
    // could return an arbitrary historical trashed row when a business had
    // reconnect history. The new disconnect path prefers the active row.
    $this->business->forceFill(['payment_mode' => PaymentMode::Online->value])->save();

    // History: one soft-deleted old account + one active new account.
    $oldRow = StripeConnectedAccount::factory()
        ->active()
        ->for($this->business)
        ->create(['stripe_account_id' => 'acct_test_old_disc']);
    $oldRow->delete();

    $activeRow = StripeConnectedAccount::factory()
        ->active()
        ->for($this->business)
        ->create(['stripe_account_id' => 'acct_test_active_disc']);

    $this->actingAs($this->admin)
        ->delete('/dashboard/settings/connected-account')
        ->assertRedirect('/dashboard/settings/connected-account');

    // The NEW active row is the one that got trashed by disconnect.
    $activeRow->refresh();
    expect($activeRow->trashed())->toBeTrue()
        ->and($activeRow->stripe_account_id)->toBe('acct_test_active_disc');

    // The OLD row stays as it was (already trashed).
    $oldRow->refresh();
    expect($oldRow->trashed())->toBeTrue()
        ->and($oldRow->stripe_account_id)->toBe('acct_test_old_disc');

    // Business demoted to offline.
    expect($this->business->fresh()->payment_mode)->toBe(PaymentMode::Offline);
});

test('disconnect retry on already-trashed row still finishes business demotion (D-131, codex Round 5)', function () {
    // Codex Round 5 finding: the original disconnect() looked up the row via
    // the active-only relation. After the row was soft-deleted (whether by
    // a crashed first attempt or a webhook deauthorization mid-flow), a
    // retry would 404 and the business would stay stuck at `online`. The
    // fix uses withTrashed() so the retry finds the row and finishes
    // demoting the business; the trashed-noop branch is idempotent.
    $this->business->forceFill(['payment_mode' => PaymentMode::Online->value])->save();

    $row = StripeConnectedAccount::factory()
        ->active()
        ->for($this->business)
        ->create(['stripe_account_id' => 'acct_test_disc_retry']);
    $row->delete(); // simulate "the previous attempt soft-deleted but business save crashed"

    $this->actingAs($this->admin)
        ->delete('/dashboard/settings/connected-account')
        ->assertRedirect('/dashboard/settings/connected-account');

    expect($this->business->fresh()->payment_mode)->toBe(PaymentMode::Offline);
    expect($row->fresh()->trashed())->toBeTrue()
        ->and($row->fresh()->stripe_account_id)->toBe('acct_test_disc_retry');
});

test('staff cannot reach any Connected Account endpoint', function () {
    $staff = User::factory()->create();
    attachStaff($this->business, $staff);

    $this->actingAs($staff)
        ->get('/dashboard/settings/connected-account')
        ->assertStatus(403);

    $this->actingAs($staff)
        ->post('/dashboard/settings/connected-account')
        ->assertStatus(403);

    $this->actingAs($staff)
        ->get('/dashboard/settings/connected-account/refresh')
        ->assertStatus(403);

    $this->actingAs($staff)
        ->post('/dashboard/settings/connected-account/resume')
        ->assertStatus(403);

    $this->actingAs($staff)
        ->get('/dashboard/settings/connected-account/resume-expired')
        ->assertStatus(403);

    $this->actingAs($staff)
        ->delete('/dashboard/settings/connected-account')
        ->assertStatus(403);
});

test('cross-tenant: admin of A cannot disconnect Business B connected account', function () {
    // Locked roadmap decision #45 — controller resolves Business via tenant
    // context. With admin A acting, B's connected account is invisible
    // because B is not the tenant; the disconnect 404s through the tenant-
    // scoped relation.
    $businessB = Business::factory()->onboarded()->create();
    StripeConnectedAccount::factory()->active()->for($businessB)->create();

    $this->actingAs($this->admin)
        ->delete('/dashboard/settings/connected-account')
        ->assertNotFound();

    expect($businessB->fresh()->stripeConnectedAccount)->not->toBeNull()
        ->and($businessB->fresh()->stripeConnectedAccount->trashed())->toBeFalse();
});

test('multi-business admin onboards each business independently', function () {
    $businessB = Business::factory()->onboarded()->create();
    attachAdmin($businessB, $this->admin);

    StripeConnectedAccount::factory()
        ->active()
        ->for($this->business)
        ->create(['stripe_account_id' => 'acct_a']);
    StripeConnectedAccount::factory()
        ->active()
        ->for($businessB)
        ->create(['stripe_account_id' => 'acct_b']);

    expect($this->business->fresh()->stripeConnectedAccount->stripe_account_id)->toBe('acct_a')
        ->and($businessB->fresh()->stripeConnectedAccount->stripe_account_id)->toBe('acct_b');
});

test('GET /refresh surfaces unsupported_market state when account is active but country is outside supported_countries (D-150, codex Round 13)', function () {
    // Codex Round 13 finding: without the unsupported_market state the
    // admin would see a "Verified / Active" success flash even though
    // Business::canAcceptOnlinePayments() refuses online payments
    // because the country is not in the supported set. The refresh
    // response MUST distinguish this case so the UI + the flash copy
    // explain the mismatch instead of claiming onboarding is complete.
    StripeConnectedAccount::factory()
        ->pending()
        ->for($this->business)
        ->create(['stripe_account_id' => 'acct_test_unsupported']);

    // Stripe reports active capabilities AND a country that riservo's
    // supported_countries set does NOT include (after this test-only
    // flip to ['DE']; the factory and migration default to 'CH').
    config(['payments.supported_countries' => ['DE']]);

    FakeStripeClient::for($this)->mockAccountRetrieve('acct_test_unsupported', [
        'country' => 'CH',
        'default_currency' => 'chf',
        'charges_enabled' => true,
        'payouts_enabled' => true,
        'details_submitted' => true,
        'requirements' => (object) ['currently_due' => [], 'disabled_reason' => null],
    ]);

    $response = $this->actingAs($this->admin)
        ->get(signedConnectedAccountUrl('settings.connected-account.refresh', 'acct_test_unsupported'));

    $response
        ->assertRedirect('/dashboard/settings/connected-account')
        ->assertSessionHas('success', fn ($msg) => str_contains($msg, 'not available for your country'));

    // The row's status flips to unsupported_market — the Inertia shared
    // prop on the next request reflects it, and canAcceptOnlinePayments
    // stays false so the backend gate is coherent.
    $this->actingAs($this->admin)
        ->get('/dashboard/settings/connected-account')
        ->assertInertia(fn ($page) => $page
            ->where('account.status', 'unsupported_market')
            ->where('auth.business.connected_account.status', 'unsupported_market')
            ->where('auth.business.connected_account.can_accept_online_payments', false)
        );
});

test('Inertia auth.business.connected_account shared prop reflects all states', function () {
    // not_connected
    $this->actingAs($this->admin)
        ->get('/dashboard/settings/connected-account')
        ->assertInertia(fn ($page) => $page
            ->where('auth.business.connected_account.status', 'not_connected')
            ->where('auth.business.connected_account.can_accept_online_payments', false)
            ->where('auth.business.connected_account.payment_mode_mismatch', false)
        );

    // active
    StripeConnectedAccount::factory()->active()->for($this->business)->create();

    $this->actingAs($this->admin)
        ->get('/dashboard/settings/connected-account')
        ->assertInertia(fn ($page) => $page
            ->where('auth.business.connected_account.status', 'active')
            ->where('auth.business.connected_account.can_accept_online_payments', true)
            ->where('auth.business.connected_account.payment_mode_mismatch', false)
        );

    // payment_mode_mismatch fires when the Business is set to online but the
    // connected account is missing or unverified.
    $this->business->stripeConnectedAccount->delete();
    $this->business->forceFill(['payment_mode' => PaymentMode::Online->value])->save();

    $this->actingAs($this->admin)
        ->get('/dashboard/settings/connected-account')
        ->assertInertia(fn ($page) => $page
            ->where('auth.business.connected_account.status', 'not_connected')
            ->where('auth.business.connected_account.payment_mode_mismatch', true)
        );
});

test('create rejects with an error flash when a connected account already exists', function () {
    StripeConnectedAccount::factory()->active()->for($this->business)->create();

    $this->actingAs($this->admin)
        ->post('/dashboard/settings/connected-account')
        ->assertSessionHas('error');
});

test('create refuses when Business.country is not in supported_countries (D-141, codex Round 9)', function () {
    // Canonical country now lives on `Business.country`. A business whose
    // country is unsupported (e.g., the business is German and the MVP
    // supported set is ['CH']) is refused onboarding — Stripe Express
    // country is permanent, so a wrong-country account would be stuck.
    $this->business->forceFill(['country' => 'DE'])->save();

    $this->actingAs($this->admin)
        ->post('/dashboard/settings/connected-account')
        ->assertSessionHas('error');

    expect($this->business->fresh()->stripeConnectedAccount)->toBeNull();
});

test('create succeeds when supported_countries expands to include Business.country (D-141)', function () {
    // Forward-looking: once supported_countries is flipped to include the
    // business's country, onboarding succeeds. Proves the gate is truly
    // config-driven and not a hardcoded CH check.
    $this->business->forceFill(['country' => 'DE'])->save();
    config(['payments.supported_countries' => ['CH', 'DE']]);

    FakeStripeClient::for($this)
        ->mockAccountCreate(
            ['id' => 'acct_test_multi_country', 'country' => 'DE'],
            'riservo_connect_account_create_'.$this->business->id.'_attempt_0',
        )
        ->mockAccountLinkCreate(['url' => 'https://connect.stripe.com/setup/x']);

    $this->actingAs($this->admin)
        ->post('/dashboard/settings/connected-account')
        ->assertStatus(302);

    $row = StripeConnectedAccount::where('business_id', $this->business->id)->first();
    expect($row)->not->toBeNull()
        ->and($row->country)->toBe('DE');
});

test('create refuses when Business.country is null (D-141)', function () {
    // Defensive: an unset country signals the business onboarding
    // predates the D-141 collection step. Refuse until it's set.
    $this->business->forceFill(['country' => null])->save();

    $this->actingAs($this->admin)
        ->post('/dashboard/settings/connected-account')
        ->assertSessionHas('error');

    expect($this->business->fresh()->stripeConnectedAccount)->toBeNull();
});

test('disconnect+reconnect within Stripe 24h window uses a fresh idempotency key (D-125, codex Round 3)', function () {
    // The bug: a per-business-only key collides on reconnect within Stripe's
    // 24h idempotency TTL — Stripe replays the original acct_…, the local
    // insert hits the global unique on stripe_account_id (the soft-deleted
    // row retains the id per locked decision #36), the user gets a 500.
    // Fix: the key includes a per-attempt nonce that bumps with each
    // soft-deleted row.

    // First onboarding attempt: nonce = 0. Second attempt: nonce = 1. Both
    // expectations are registered up-front on the same FakeStripeClient mock
    // — Mockery's withArgs matchers route each call to the right one based
    // on the idempotency_key value.
    $expectedKey1 = 'riservo_connect_account_create_'.$this->business->id.'_attempt_0';
    $expectedKey2 = 'riservo_connect_account_create_'.$this->business->id.'_attempt_1';

    FakeStripeClient::for($this)
        ->mockAccountCreate(['id' => 'acct_test_first'], $expectedKey1)
        ->mockAccountCreate(['id' => 'acct_test_second'], $expectedKey2)
        ->mockAccountLinkCreate(['url' => 'https://connect.stripe.com/setup/x']);

    $this->actingAs($this->admin)
        ->post('/dashboard/settings/connected-account')
        ->assertStatus(302);

    // Disconnect: row soft-deleted, stripe_account_id retained.
    $this->actingAs($this->admin)
        ->delete('/dashboard/settings/connected-account')
        ->assertRedirect('/dashboard/settings/connected-account');

    expect(StripeConnectedAccount::onlyTrashed()->where('business_id', $this->business->id)->count())
        ->toBe(1);

    $this->actingAs($this->admin)
        ->post('/dashboard/settings/connected-account')
        ->assertStatus(302);

    // One active row + one soft-deleted row — both exist; no DB collision.
    expect(StripeConnectedAccount::where('business_id', $this->business->id)->count())
        ->toBe(1)
        ->and(StripeConnectedAccount::onlyTrashed()->where('business_id', $this->business->id)->count())
        ->toBe(1);
});

test('cross-tenant replay refuses to re-parent another business connected account (D-134, codex Round 6)', function () {
    // Codex Round 6 finding: the replay-recovery branch in create() used to
    // restore ANY soft-deleted row matching the returned acct_id and rewrite
    // its business_id to the current tenant. If Stripe replays an acct_id
    // that already belongs to a different Business (the abnormal case the
    // branch claims to defend against), we silently re-parented the row.
    // Now: cross-tenant collision aborts onboarding with a manual-
    // reconciliation flash and NEVER touches the existing row's ownership.
    $businessB = Business::factory()->onboarded()->create();
    $bRow = StripeConnectedAccount::factory()
        ->active()
        ->for($businessB)
        ->create(['stripe_account_id' => 'acct_test_xtenant_replay']);
    $bRow->delete(); // soft-deleted on B

    // Stripe replays B's acct_id when admin A clicks Enable.
    FakeStripeClient::for($this)
        ->mockAccountCreate(['id' => 'acct_test_xtenant_replay'], 'riservo_connect_account_create_'.$this->business->id.'_attempt_0')
        ->mockAccountLinkCreate(['url' => 'https://connect.stripe.com/setup/never_used']);

    $this->actingAs($this->admin)
        ->post('/dashboard/settings/connected-account')
        ->assertSessionHas('error');

    // Business B's row stays attached to B (not re-parented to A).
    $bRow->refresh();
    expect($bRow->business_id)->toBe($businessB->id)
        ->and($bRow->trashed())->toBeTrue()
        ->and($bRow->stripe_account_id)->toBe('acct_test_xtenant_replay');

    // Business A has no row at all — onboarding aborted before insert.
    expect(StripeConnectedAccount::withTrashed()->where('business_id', $this->business->id)->count())
        ->toBe(0);
});

test('reconnect tolerates Stripe replaying a soft-deleted acct_id by restoring the row (D-125 defense-in-depth)', function () {
    // Belt-and-braces: even if the per-attempt nonce was somehow bypassed
    // and Stripe DID replay an existing acct_…, the controller restores the
    // soft-deleted row instead of inserting a duplicate that would collide
    // with the global unique on stripe_account_id.
    $trashedRow = StripeConnectedAccount::factory()
        ->active()
        ->for($this->business)
        ->create(['stripe_account_id' => 'acct_test_replay']);
    $trashedRow->delete();

    // Force Stripe to "replay" by stubbing the same acct_id on the next call.
    FakeStripeClient::for($this)
        ->mockAccountCreate(['id' => 'acct_test_replay'], 'riservo_connect_account_create_'.$this->business->id.'_attempt_1')
        ->mockAccountLinkCreate(['url' => 'https://connect.stripe.com/setup/z']);

    $this->actingAs($this->admin)
        ->post('/dashboard/settings/connected-account')
        ->assertStatus(302);

    // Exactly one active row + zero trashed (the original was restored).
    expect(StripeConnectedAccount::where('business_id', $this->business->id)->count())
        ->toBe(1)
        ->and(StripeConnectedAccount::onlyTrashed()->where('business_id', $this->business->id)->count())
        ->toBe(0);

    expect($trashedRow->fresh()->trashed())->toBeFalse();
});

test('accounts.create retry uses the same idempotency key for the same business (D-124, codex Round 2)', function () {
    // Codex Round 2 finding: a crash between Stripe's accounts.create
    // response and our local insert would, on retry, create a SECOND Express
    // account because the local existence check would still see no row. The
    // idempotency_key bound to business_id makes Stripe collapse the retry
    // into the original response — Stripe stores idempotency keys for 24h.
    //
    // We can't simulate a real partial-commit failure from Pest, but we can
    // assert two things that together prove the property:
    //   1. The first call passes the expected key shape (covered by the
    //      "admin can create a connected account" test above via
    //      mockAccountCreate's expectedIdempotencyKey).
    //   2. A second invocation against the same Business — after deleting
    //      the local row to simulate the "crash before insert" path —
    //      passes the SAME key, so Stripe would collapse it.
    // The crash-then-retry case: the trashed-row count stays at 0 (the
    // hard-delete simulates "the local insert never committed"), so both
    // attempts compute the same per-attempt nonce and Stripe collapses them.
    $expectedKey = 'riservo_connect_account_create_'.$this->business->id.'_attempt_0';

    FakeStripeClient::for($this)
        ->mockAccountCreate(['id' => 'acct_test_idem'], $expectedKey)
        ->mockAccountLinkCreate(['url' => 'https://connect.stripe.com/setup/x']);

    $this->actingAs($this->admin)
        ->post('/dashboard/settings/connected-account')
        ->assertStatus(302);

    // Simulate "the local insert never committed" by hard-deleting the row
    // (no soft-delete, so the trashed-count stays 0 and the nonce is reused).
    StripeConnectedAccount::where('business_id', $this->business->id)
        ->forceDelete();

    // The second create call MUST pass the same idempotency key. The
    // FakeStripeClient's withArgs matcher rejects any other key.
    FakeStripeClient::for($this)
        ->mockAccountCreate(['id' => 'acct_test_idem'], $expectedKey)
        ->mockAccountLinkCreate(['url' => 'https://connect.stripe.com/setup/x']);

    $this->actingAs($this->admin)
        ->post('/dashboard/settings/connected-account')
        ->assertStatus(302);

    // The local row exists exactly once for this Business.
    expect(StripeConnectedAccount::where('business_id', $this->business->id)->count())
        ->toBe(1);
});
