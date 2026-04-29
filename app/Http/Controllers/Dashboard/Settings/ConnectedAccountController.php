<?php

namespace App\Http\Controllers\Dashboard\Settings;

use App\Enums\BusinessMemberRole;
use App\Enums\PaymentMode;
use App\Exceptions\Payments\ConnectedAccountAlreadyExists;
use App\Exceptions\Payments\ConnectedAccountReplayCrossTenant;
use App\Http\Controllers\Controller;
use App\Http\Controllers\Dashboard\PayoutsController;
use App\Models\Business;
use App\Models\BusinessMember;
use App\Models\StripeConnectedAccount;
use App\Support\TenantContext;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\URL;
use Inertia\Inertia;
use Inertia\Response;
use Stripe\Exception\ApiErrorException;
use Stripe\StripeClient;
use Symfony\Component\HttpFoundation\Response as SymfonyResponse;

/**
 * Admin-only Stripe Connect Express onboarding surface (PAYMENTS Session 1).
 *
 * Actions:
 *  - show()       — GET  /dashboard/settings/connected-account
 *  - create()     — POST /dashboard/settings/connected-account
 *  - refresh()    — GET  /dashboard/settings/connected-account/refresh
 *  - disconnect() — DELETE /dashboard/settings/connected-account
 *
 * Route group (web.php) enforces `role:admin` and sits inside
 * `billing.writable` per D-116 — a SaaS-lapsed Business cannot open new
 * payment surfaces; `GET` requests pass through unconditionally per D-090.
 *
 * Tenant context: every action resolves the target Business via
 * `tenant()->business()` (locked roadmap decision #45). Request-supplied
 * ids are NEVER trusted; cross-tenant attempts 404 through the tenant-
 * scoped `stripeConnectedAccount` relation.
 */
class ConnectedAccountController extends Controller
{
    public function __construct(private readonly StripeClient $stripe) {}

    public function show(Request $request): Response
    {
        $business = $this->resolveBusiness();

        $business->loadMissing('stripeConnectedAccount');

        return Inertia::render('dashboard/settings/connected-account', [
            'account' => $this->accountPayload($business->stripeConnectedAccount),
        ]);
    }

    /**
     * Create a Stripe Express connected account for this Business and mint an
     * Account Link for Stripe-hosted onboarding (locked roadmap decisions #5,
     * #22). `Business.address` is freeform and is NOT parsed for a country
     * code — per D-115 the onboarding country is always
     * `config('payments.default_onboarding_country')` (Stripe collects the
     * real country during KYC and the row's `country` column is overwritten
     * by the first `accounts.retrieve`).
     */
    public function create(Request $request): SymfonyResponse|RedirectResponse
    {
        $business = $this->resolveBusiness();

        if ($business->stripeConnectedAccount !== null) {
            // D-117: one active row at a time — the admin must click
            // Disconnect before re-onboarding. A partial onboarding flow (the
            // admin bailed mid-KYC) is continued via refresh(), not create().
            return back()->with('error', __('A connected account already exists for this business — disconnect first to re-onboard.'));
        }

        // Codex Round-1 (D-121) + Round-9 (D-141): Stripe Express country
        // is permanent once the account is created. We MUST refuse to
        // silently default a global country into Stripe — a non-default-
        // country Business would be misprovisioned with no recovery short
        // of disconnect-and-recreate. The country now comes from the
        // canonical `Business.country` column (D-141), not from a config
        // fallback. If the column is null or the value is not in
        // `config('payments.supported_countries')`, onboarding is refused.
        $country = $this->resolveOnboardingCountry($business);
        if ($country === null) {
            return back()->with('error', __('Online payments are not available for your business yet. Either your country is not in the supported set, or your business profile is missing the country. Please contact support.'));
        }

        try {
            $row = DB::transaction(function () use ($business, $country) {
                // Codex Round-1 finding (D-122): serialise concurrent Enable
                // clicks. Without the row lock, two parallel POSTs would both
                // pass the existence check above, both call accounts.create,
                // and both insert; the partial unique index would reject the
                // second insert AFTER a real Stripe account had been created
                // (orphan acct_…). The lock confines the create-or-skip
                // decision to one transaction at a time per Business.
                Business::query()->whereKey($business->id)->lockForUpdate()->first();

                if (StripeConnectedAccount::where('business_id', $business->id)->exists()) {
                    throw new ConnectedAccountAlreadyExists;
                }

                // D-124 + Codex Round-3 finding (D-125): the idempotency key
                // must be unique per onboarding ATTEMPT, not just per business.
                // Stripe stores idempotency keys for 24h. A bound-by-business
                // key collides on disconnect+reconnect within that window:
                // Stripe replays the original `acct_…`, the local insert then
                // hits the global unique on `stripe_account_id` (the
                // soft-deleted row retains the id per locked decision #36),
                // and the user gets a 500. We bump a per-attempt nonce —
                // the count of soft-deleted rows for this business — so each
                // disconnect+reconnect cycle uses a fresh key and Stripe
                // creates a fresh `acct_…`.
                $attemptNonce = StripeConnectedAccount::onlyTrashed()
                    ->where('business_id', $business->id)
                    ->count();

                $account = $this->stripe->accounts->create(
                    [
                        'type' => 'express',
                        'country' => $country,
                        'capabilities' => [
                            'card_payments' => ['requested' => true],
                            'transfers' => ['requested' => true],
                        ],
                        'metadata' => [
                            'riservo_business_id' => (string) $business->id,
                            'riservo_attempt' => (string) $attemptNonce,
                        ],
                    ],
                    [
                        'idempotency_key' => sprintf(
                            'riservo_connect_account_create_%d_attempt_%d',
                            $business->id,
                            $attemptNonce,
                        ),
                    ],
                );

                // Defense-in-depth: if Stripe returns an `acct_…` that
                // matches a soft-deleted local row anyway (operator manually
                // re-used a key, key TTL longer than expected, etc.), restore
                // that row instead of inserting a duplicate that would
                // collide with the global unique on stripe_account_id.
                //
                // Codex Round-6 finding (D-134): the restore branch MUST
                // verify the existing row already belongs to the same
                // Business. Otherwise a Stripe-side `acct_…` collision
                // (improbable but possible with abuse / operator error)
                // would silently re-parent another tenant's connected
                // account to the current tenant — corrupting ownership and
                // breaking the audit + late-webhook refund linkages tied
                // to the original Business. Cross-tenant collisions abort
                // onboarding with a manual-reconciliation error; they do
                // NOT silently rewrite ownership.
                $existing = StripeConnectedAccount::withTrashed()
                    ->where('stripe_account_id', $account->id)
                    ->first();

                if ($existing !== null) {
                    if ($existing->business_id !== $business->id) {
                        Log::critical('Stripe Connect replay returned an acct_id that already belongs to another business — refusing to re-parent', [
                            'returned_stripe_account_id' => $account->id,
                            'existing_business_id' => $existing->business_id,
                            'requesting_business_id' => $business->id,
                        ]);

                        throw new ConnectedAccountReplayCrossTenant;
                    }

                    $existing->restore();
                    $existing->fill([
                        'country' => $account->country ?? $country,
                        'charges_enabled' => (bool) ($account->charges_enabled ?? false),
                        'payouts_enabled' => (bool) ($account->payouts_enabled ?? false),
                        'details_submitted' => (bool) ($account->details_submitted ?? false),
                        'default_currency' => $account->default_currency ?? null,
                        'requirements_currently_due' => [],
                    ])->save();

                    return $existing;
                }

                return StripeConnectedAccount::create([
                    'business_id' => $business->id,
                    'stripe_account_id' => $account->id,
                    'country' => $account->country ?? $country,
                    'charges_enabled' => (bool) ($account->charges_enabled ?? false),
                    'payouts_enabled' => (bool) ($account->payouts_enabled ?? false),
                    'details_submitted' => (bool) ($account->details_submitted ?? false),
                    'default_currency' => $account->default_currency ?? null,
                    'requirements_currently_due' => [],
                ]);
            });

            // Codex Round-7 (D-137): refresh_url and return_url are now
            // different endpoints. Stripe hits refresh_url ONLY when the
            // Account Link expires mid-flow (minting a fresh link is the
            // expected response). return_url lands on a pure sync endpoint
            // so an admin returning from KYC actually sees the settings
            // page instead of being bounced back into Stripe immediately.
            //
            // Codex Round-10 (D-144): both URLs MUST be signed and carry
            // the acct_id, because the receiving routes enforce `signed`
            // middleware and the controller verifies tenant ownership via
            // the `account` query param. Passing plain `route(...)` URLs
            // here turned the very first KYC return into a 403. Reuse
            // `mintAccountLink()` — the same signed-URL shape used by
            // resume() and resumeExpired() — for parity.
            $link = $this->mintAccountLink($row);
        } catch (ConnectedAccountAlreadyExists) {
            // The race-loser path: the parallel request created the row
            // before us. Treat as the same outcome as the pre-transaction
            // existence check.
            return back()->with('error', __('A connected account already exists for this business — disconnect first to re-onboard.'));
        } catch (ConnectedAccountReplayCrossTenant) {
            // Codex Round-6 (D-134): Stripe replayed an acct_id that is
            // already attached to a different Business. Refuse to re-parent
            // — the operator must reconcile manually (typically: contact
            // riservo support to disambiguate).
            return back()->with('error', __('Stripe returned an account that is already linked to a different business. Please contact support so we can reconcile this.'));
        } catch (ApiErrorException $e) {
            report($e);

            return back()->with('error', __('Could not start Stripe onboarding. Please try again in a moment.'));
        }

        return Inertia::location($link->url);
    }

    /**
     * Resolve the Stripe Express account country for this onboarding attempt.
     *
     * Per locked roadmap decision #43 + D-121 + D-141, the country is read
     * from the canonical `Business.country` column and then verified to be
     * in `config('payments.supported_countries')`. No config-default
     * fallback — a Business with a null `country` is refused until the
     * column is populated (via onboarding in a later session; BACKLOG
     * entry "Collect country during business onboarding" tracks the UX).
     */
    private function resolveOnboardingCountry(Business $business): ?string
    {
        $country = $business->country;

        if (! is_string($country) || $country === '') {
            return null;
        }

        $supported = (array) config('payments.supported_countries');

        if (! in_array($country, $supported, true)) {
            return null;
        }

        return $country;
    }

    /**
     * Codex Round-7 finding (D-137): the original `refresh()` acted as both
     * Stripe's `return_url` AND `refresh_url`, AND transparently bounced
     * pending/incomplete admins back into Stripe. That trapped users in a
     * loop — an admin who bailed mid-KYC could never land on the settings
     * page to disconnect. It also bypassed the `billing.writable` write
     * gate because GET is always allowed through. The flow now splits:
     *
     *  - `refresh()` (this action, GET) — Stripe's `return_url`. Pure sync
     *    handler: pulls authoritative state (idempotent mutation, but
     *    Stripe-initiated via GET redirect, so unavoidable), then ALWAYS
     *    redirects to the settings page. Never bounces back into Stripe.
     *  - `resume()` (POST) — admin-triggered "Continue Stripe onboarding".
     *    Sits inside `billing.writable`; SaaS-lapsed admins cannot trigger
     *    it. Mints a fresh Account Link and redirects back to Stripe.
     *  - `resumeExpired()` (GET) — Stripe's `refresh_url`, which only
     *    fires when an Account Link expires mid-flow. Mints a fresh link
     *    and redirects back to Stripe (Stripe's own redirect means we
     *    have no say in the HTTP verb here).
     */
    public function refresh(Request $request): RedirectResponse
    {
        // Codex Round-9 (D-142): the signed URL binds this request to the
        // connected account id Stripe saw when minting the Account Link,
        // so the action can't silently run against a different tenant the
        // admin happened to switch to in another tab. `signed` middleware
        // is on the route; we resolve the row via the signed `account`
        // query param and then 403 if the user is not admin of the row's
        // business (D-147).
        $row = $this->resolveSignedAccountRow($request);

        // Codex Round-10 (D-146): fast-path pre-transaction trashed check.
        // Stays around so callers that arrive on an obviously-disconnected
        // row get a clean redirect without opening a DB lock.
        if ($row->trashed()) {
            return $this->disconnectedRedirect();
        }

        try {
            // Codex Round-12 (D-148): serialise `refresh` against a
            // concurrent `disconnect()` via `lockForUpdate`. Without the
            // lock the pre-check above is a TOCTOU: a disconnect committing
            // between the pre-check and `syncRowFromStripe` would still see
            // the Stripe `accounts.retrieve` write back onto the now-trashed
            // row, undoing the disconnect invariant D-146 restored. We
            // re-load inside the transaction, hold a row lock for the
            // duration of the sync, and bail if the trashed flag flipped.
            $status = DB::transaction(function () use ($row) {
                $locked = StripeConnectedAccount::withTrashed()
                    ->whereKey($row->id)
                    ->lockForUpdate()
                    ->first();

                if ($locked === null || $locked->trashed()) {
                    return null;
                }

                $this->syncRowFromStripe($locked);

                return $locked->verificationStatus();
            });
        } catch (ApiErrorException $e) {
            report($e);

            return redirect()
                ->route('settings.connected-account')
                ->with('error', __('Could not refresh Stripe onboarding state. Please try again in a moment.'));
        }

        if ($status === null) {
            return $this->disconnectedRedirect();
        }

        // Codex Round-5 (D-132): copy avoids "online payments are ready"
        // because the public booking flow does not yet consume `payment_mode`
        // — Session 2a wires Checkout, Session 5 lifts the UI hide. Until
        // then, a verified connected account is a prerequisite, not a green
        // light. The copy says the verification step finished and signals
        // that the actual go-live happens in a later release.
        // Codex Round-13 (D-150): the `unsupported_market` arm covers the
        // config-drift case — Stripe caps on, but `country` is no longer in
        // `config('payments.supported_countries')`. The copy explains why
        // the account won't activate for payments despite KYC completion,
        // so the admin isn't stranded on a misleading "Verified" message.
        $message = match ($status) {
            'active' => __('Stripe onboarding complete. Online payments will activate in a later release.'),
            'unsupported_market' => __('Stripe onboarding complete, but online payments are not available for your country yet. Please contact support.'),
            'disabled' => __('Stripe has disabled this account. Please contact support.'),
            'incomplete' => __('Stripe still needs some details to finish verification. Continue from the settings page when ready.'),
            default => __('Stripe onboarding updated.'),
        };

        return redirect()->route('settings.connected-account')->with('success', $message);
    }

    /**
     * Admin-triggered resume (D-137, Codex Round 7). Sits inside
     * `billing.writable` via the POST method on the route — SaaS-lapsed
     * admins can't open a fresh onboarding attempt. Syncs the row so the
     * decision to mint is based on current Stripe state, then mints and
     * redirects back to Stripe. Only valid for resumable states (pending /
     * incomplete); active / disabled paths redirect back to the settings
     * page with an appropriate flash.
     */
    public function resume(Request $request): SymfonyResponse|RedirectResponse
    {
        $business = $this->resolveBusiness();
        $row = $business->stripeConnectedAccount;

        abort_if($row === null, 404);

        try {
            // Codex Round-12 (D-148): serialise against a concurrent
            // `disconnect()` via `lockForUpdate` on the row. Without it,
            // the admin could click Disconnect in one tab and Resume in
            // another; a disconnect commit between the relation read
            // above and `mintAccountLink` would mint a fresh Stripe
            // Account Link for a row the product has just disconnected —
            // exactly the D-146 invariant we're defending. Re-loading
            // inside the lock closes the TOCTOU.
            $result = $this->runResumeInLock($row);
        } catch (ApiErrorException $e) {
            report($e);

            return redirect()
                ->route('settings.connected-account')
                ->with('error', __('Stripe onboarding could not be resumed. Please try again.'));
        }

        return match ($result['outcome']) {
            'disconnected' => $this->disconnectedRedirect(),
            'not-resumable' => redirect()
                ->route('settings.connected-account')
                ->with('error', __('Onboarding cannot be resumed from this state.')),
            default => Inertia::location($result['url']),
        };
    }

    /**
     * Stripe-triggered handler for an expired Account Link (D-137,
     * Codex Round 7). Minted fresh link + redirect. GET-only because
     * Stripe's `refresh_url` redirect is a GET.
     */
    public function resumeExpired(Request $request): SymfonyResponse|RedirectResponse
    {
        // Codex Round-9 (D-142) + Round-12 (D-147): signed-URL guard with
        // authorization derived from the signed row's business. `signed`
        // middleware on the route verifies signature + TTL before we run.
        $row = $this->resolveSignedAccountRow($request);

        // Codex Round-10 (D-146): fast-path pre-transaction trashed check.
        // The authoritative re-check happens inside the lock below.
        if ($row->trashed()) {
            return $this->disconnectedRedirect();
        }

        // Codex Round-10 (D-145): `billing.writable` lets GETs through
        // unconditionally (D-090), but this handler mints a fresh Stripe
        // Account Link — a NEW payment surface — which D-116 forbids on a
        // read-only business. The signed URL also has a 24h TTL, so a link
        // minted before the subscription lapsed would otherwise let a
        // lapsed admin keep opening new onboarding surfaces. Guard
        // explicitly and redirect to the billing settings page (which is
        // always reachable per D-090). `tenant()->business()` has been
        // re-pinned to the signed row's business by `resolveSignedAccountRow`
        // (D-147), so the `canWrite()` check authorises against the
        // correct business even when the session was pinned elsewhere.
        $business = tenant()->business();
        if ($business === null || ! $business->canWrite()) {
            return redirect()
                ->route('settings.billing')
                ->with('error', __('Your subscription has ended. Please resubscribe to continue.'));
        }

        try {
            // Codex Round-12 (D-148): same serialisation as `resume()` and
            // `refresh()`. A concurrent disconnect that commits between
            // the pre-check and `accountLinks->create` would otherwise
            // let this handler mint a fresh onboarding URL for a
            // disconnected account. The lock closes the window.
            $result = $this->runResumeInLock($row, settledOutcome: 'settled');
        } catch (ApiErrorException $e) {
            report($e);

            return redirect()
                ->route('settings.connected-account')
                ->with('error', __('Stripe onboarding could not be resumed. Please try again.'));
        }

        return match ($result['outcome']) {
            'disconnected' => $this->disconnectedRedirect(),
            'settled' => redirect()->route('settings.connected-account'),
            default => Inertia::location($result['url']),
        };
    }

    /**
     * Codex Round-12 (D-148): shared lock-then-sync-then-maybe-mint helper
     * used by `resume()` (admin-triggered POST) and `resumeExpired()`
     * (Stripe-triggered GET). Both paths need the same TOCTOU protection:
     * a concurrent `disconnect()` trashes the row; without a row-level
     * `lockForUpdate` inside a transaction, the handler would mint a
     * fresh Stripe Account Link for a row the product just disconnected.
     *
     * The `settledOutcome` string differentiates the `resume` flow
     * ("not-resumable" → error flash) from the `resumeExpired` flow
     * ("settled" → silent redirect back to the settings page).
     *
     * @return array{outcome: string, url?: string}
     *
     * @throws ApiErrorException
     */
    private function runResumeInLock(StripeConnectedAccount $row, string $settledOutcome = 'not-resumable'): array
    {
        $outcome = 'disconnected';
        $url = null;

        DB::transaction(function () use ($row, $settledOutcome, &$outcome, &$url): void {
            $locked = StripeConnectedAccount::withTrashed()
                ->whereKey($row->id)
                ->lockForUpdate()
                ->first();

            if ($locked === null || $locked->trashed()) {
                $outcome = 'disconnected';

                return;
            }

            $this->syncRowFromStripe($locked);

            if (! in_array($locked->verificationStatus(), ['pending', 'incomplete'], true)) {
                $outcome = $settledOutcome;

                return;
            }

            $outcome = 'link';
            $url = $this->mintAccountLink($locked)->url;
        });

        return $url !== null
            ? ['outcome' => $outcome, 'url' => $url]
            : ['outcome' => $outcome];
    }

    /**
     * Codex Round-10 (D-146): shared redirect for any signed GET handler
     * that discovers (pre- or inside-lock) the row has been soft-deleted.
     * The flash is deliberately user-directed (start a new onboarding)
     * rather than technical — from the admin's POV the account is gone.
     */
    private function disconnectedRedirect(): RedirectResponse
    {
        return redirect()
            ->route('settings.connected-account')
            ->with('error', __('This Stripe account has been disconnected. Start a new onboarding from the settings page if you want to accept online payments again.'));
    }

    /**
     * Codex Round-9 (D-142) + Round-12 (D-147): resolve the connected-
     * account row the signed URL was minted for and authorize the
     * authenticated user against THAT row's business — not the session-
     * pinned tenant. `ResolveTenantContext` rule 2 re-pins session to the
     * user's oldest active membership when a session expires, so a multi-
     * business admin returning from Stripe KYC would otherwise 403 on a
     * legitimate signed URL whenever the signed row's business isn't
     * their oldest membership. We load the row, verify the user has an
     * active Admin membership on the row's business, and re-pin the
     * session + `TenantContext` so downstream helpers (`tenant()`,
     * `Inertia` shared props, the response redirect) reflect the correct
     * business for the remainder of the request.
     *
     * 403 when:
     *  - the `account` query param is missing;
     *  - the user has no active membership on the row's business;
     *  - the user's membership on the row's business is not Admin (this
     *    is the same bar the outer `role:admin` group enforces — we are
     *    authorising against the signed row's business, which may differ
     *    from the session-pinned one that `role:admin` already cleared).
     * 404 when:
     *  - no connected-account row matches the signed acct_.
     */
    private function resolveSignedAccountRow(Request $request): StripeConnectedAccount
    {
        $accountId = $request->query('account');
        abort_if(! is_string($accountId) || $accountId === '', 403);

        $row = StripeConnectedAccount::withTrashed()
            ->where('stripe_account_id', $accountId)
            ->first();

        abort_if($row === null, 404);

        $user = $request->user();
        abort_if($user === null, 403);

        $membership = BusinessMember::query()
            ->where('user_id', $user->id)
            ->where('business_id', $row->business_id)
            ->whereNull('deleted_at')
            ->first();

        abort_if($membership === null, 403);
        abort_unless($membership->role === BusinessMemberRole::Admin, 403);

        $business = Business::find($row->business_id);
        abort_if($business === null, 404);

        if ($request->session()->get('current_business_id') !== $business->id) {
            $request->session()->put('current_business_id', $business->id);
            app(TenantContext::class)->set($business, $membership->role);
        }

        return $row;
    }

    /**
     * Pull authoritative state from Stripe and persist it on the row.
     *
     * @throws ApiErrorException
     */
    private function syncRowFromStripe(StripeConnectedAccount $row): void
    {
        $account = $this->stripe->accounts->retrieve($row->stripe_account_id);

        $row->fill([
            'country' => $account->country ?? $row->country,
            'charges_enabled' => (bool) ($account->charges_enabled ?? false),
            'payouts_enabled' => (bool) ($account->payouts_enabled ?? false),
            'details_submitted' => (bool) ($account->details_submitted ?? false),
            'requirements_currently_due' => $account->requirements->currently_due ?? [],
            'requirements_disabled_reason' => $account->requirements->disabled_reason ?? null,
            'default_currency' => $account->default_currency,
        ])->save();
    }

    /**
     * Mint a fresh Stripe Account Link for continuing onboarding.
     *
     * Codex Round-9 (D-142): the `refresh_url` and `return_url` passed to
     * Stripe are signed temporary URLs carrying the `acct_…` as a query
     * param. The receiving controllers verify the signature + TTL +
     * tenant-match before acting, which (a) prevents cross-site GET
     * triggers from poisoning controller state mutations, and (b) binds
     * each return to a specific connected account so a session whose
     * current tenant has drifted can't land the action on the wrong
     * business. The 24h TTL is well past Stripe's own Account Link TTL
     * (minutes), so the signed URL never expires before Stripe's does.
     *
     * @throws ApiErrorException
     */
    private function mintAccountLink(StripeConnectedAccount $row): object
    {
        return $this->stripe->accountLinks->create([
            'account' => $row->stripe_account_id,
            'refresh_url' => URL::temporarySignedRoute(
                'settings.connected-account.resume-expired',
                now()->addHours(24),
                ['account' => $row->stripe_account_id],
            ),
            'return_url' => URL::temporarySignedRoute(
                'settings.connected-account.refresh',
                now()->addHours(24),
                ['account' => $row->stripe_account_id],
            ),
            'type' => 'account_onboarding',
        ]);
    }

    /**
     * Soft-delete the connected-account row (retaining `stripe_account_id`
     * for audit + Session 2b's late-webhook refund path per locked roadmap
     * decision #36) and force `payment_mode` back to offline (locked roadmap
     * decision #21).
     *
     * Codex Round-5 finding (D-131): the original implementation was not
     * atomic — if the row delete succeeded but the business save crashed,
     * the next request to this endpoint would 404 (the active relation no
     * longer resolved the trashed row) and leave the business permanently
     * stuck at `online` with no UI path to recover. Fix:
     *  - Wrap both writes in a single `DB::transaction` so partial failure
     *    is impossible.
     *  - PREFER the active row; fall back to any trashed row only when no
     *    active row exists (the retry-recovery path). Using
     *    `withTrashed()->first()` unconditionally was a Round-8 regression
     *    (D-140): once the table can carry multiple soft-deleted history
     *    rows alongside an active one (after a reconnect cycle), `first()`
     *    may return an older trashed row, which the trashed-noop branch
     *    then "disconnects" as a no-op — the REAL active Stripe account
     *    stays behind, and the business ends up split-brained.
     */
    public function disconnect(Request $request): RedirectResponse
    {
        $business = $this->resolveBusiness();

        // Codex Round-8 (D-140): prefer the active row. Only fall back to a
        // trashed row for the retry-recovery path where D-131 needed to
        // finish the business demotion after a prior partial-success crash
        // left the business at `online` with no active row.
        $row = $business->stripeConnectedAccount;
        if ($row === null) {
            $row = $business->stripeConnectedAccount()->withTrashed()->first();
        }

        abort_if($row === null, 404);

        DB::transaction(function () use ($row, $business) {
            // Codex Round-12 (D-148): hold a row-level lock for the entire
            // disconnect transaction so any in-flight `resume()` /
            // `resumeExpired()` / `refresh()` sitting on the same
            // `lockForUpdate` either observes the pre-disconnect row (if
            // it acquired the lock first) or the trashed row (if it
            // acquires after us) — never the in-between. Without this,
            // the other handlers could mint a fresh Account Link or sync
            // Stripe state onto a row we're about to soft-delete.
            $locked = StripeConnectedAccount::withTrashed()
                ->whereKey($row->id)
                ->lockForUpdate()
                ->first();

            if ($locked === null) {
                return;
            }

            if (! $locked->trashed()) {
                $locked->delete();
            }

            if ($business->payment_mode !== PaymentMode::Offline) {
                $business->forceFill(['payment_mode' => PaymentMode::Offline->value])->save();
            }

            // F-006 (PAYMENTS Hardening Round 1): forget the payouts cache so
            // a future reconnect cannot show the disconnected account's stale
            // balances/payout history under the new account id. The cache key
            // includes stripe_account_id so a reconnect-with-new-account is
            // already isolated; this forget covers the reconnect-with-same-
            // account-id path (rare but possible if Stripe returns the same
            // acct_… on retry) and keeps state hygienic regardless.
            Cache::forget(PayoutsController::cacheKey($business->id, (string) $locked->stripe_account_id));
        });

        return redirect()
            ->route('settings.connected-account')
            ->with('success', __('Stripe Connect disconnected.'));
    }

    private function resolveBusiness(): Business
    {
        $business = tenant()->business();

        abort_if($business === null, 404);

        return $business;
    }

    /**
     * @return array<string, mixed>|null
     */
    private function accountPayload(?StripeConnectedAccount $row): ?array
    {
        if ($row === null) {
            return null;
        }

        return [
            'status' => $row->verificationStatus(),
            'country' => $row->country,
            'defaultCurrency' => $row->default_currency,
            'chargesEnabled' => $row->charges_enabled,
            'payoutsEnabled' => $row->payouts_enabled,
            'detailsSubmitted' => $row->details_submitted,
            // D-185 (PAYMENTS Hardening Round 2): expose only a count.
            // Stripe field paths can carry PII-flavoured labels; the UI
            // routes the operator to Stripe for the actual list.
            'requirementsCount' => count($row->requirements_currently_due ?? []),
            'requirementsDisabledReason' => $row->requirements_disabled_reason,
            'stripeAccountIdLast4' => substr($row->stripe_account_id, -4),
        ];
    }
}
