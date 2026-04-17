# PLAN-MVPC-3 — Subscription Billing (Cashier)

- **Session**: MVPC-3 (third session of `docs/roadmaps/ROADMAP-MVP-COMPLETION.md`)
- **Source**: `ROADMAP-MVP-COMPLETION.md §Session 3 — Subscription Billing (Cashier)`
- **Cross-cutting decisions this session implements**: locked #9 (one paid tier, monthly + annual), #10 (indefinite trial, no card at signup), #11 (no hard limits), #12 (`cancel_at_period_end` semantics). Recorded as fresh IDs **D-089** through **D-094**.
- **Related live decisions**: D-007 (Cashier on Business, pre-roadmap), D-052 (Dashboard\\Settings namespace), D-063 / D-064 (tenant context + validation), D-081 (settings split), D-075 (after-response dispatch pattern).
- **Baseline**: post-MVPC-2 state, suite at **582 passed / 2471 assertions**; Pint clean; Vite build clean; Wayfinder regenerated.

---

## 1. Context

Sessions 1 and 2 of the MVP-completion roadmap have shipped. MVPC-1 stood up OAuth plumbing; MVPC-2 delivered the full bidirectional Google Calendar sync (commits `5388d8a`, `132535e`). The `Business` model has no billing attached yet. `laravel/cashier-stripe` is not installed. SPEC §2 and D-007 named Cashier on `Business` as the MVP billing strategy from day one; this session delivers it.

The roadmap's locked billing decisions (#9–#12) narrow the design space substantially:

- One paid tier, two prices (monthly + annual). No Starter/Pro tiers.
- Indefinite trial, no card collected at registration. Card is requested only when the business clicks "Subscribe".
- No hard plan limits enforced in MVP — no staff caps, no booking caps.
- `cancel_at_period_end` semantics: business keeps access until the period ends, then the dashboard becomes read-only with a "Resubscribe" CTA. The enforcement layer is decided in this plan.

The `payment_mode` field already on `Business` (values `offline` / `online` / `customer_choice`) is customer-facing appointment payment — out of scope for MVP. It does not overlap with the SaaS subscription this session introduces. No touch, no rename.

## 2. Goal

Install Cashier on the `Business` model. Every existing Business and every new Business has access today, with no subscription record and no card. A new admin-only "Billing" page in `Dashboard\Settings` shows trial status and a "Subscribe" CTA. Clicking Subscribe redirects to Stripe Checkout for the chosen price (monthly or annual). After successful checkout, the subscription is active and the UI shows plan + renewal date + "Manage billing" (Stripe Customer Portal) + "Cancel subscription". On cancel, Stripe sets `cancel_at_period_end=true` and access persists until the period ends. When the subscription becomes `canceled` (via `customer.subscription.deleted` webhook), the dashboard transitions to read-only: `GET` still works, every mutating verb redirects back to Billing with a "Your subscription has ended — please resubscribe" banner. Webhooks are signature-validated and idempotent. Stripe handles Swiss VAT via Stripe Tax. Shared Inertia prop `auth.business.subscription` is populated so the UI can render the state in one glance.

No hard plan limits. No Stripe Connect. No live-mode keys. Test mode only.

## 3. Scope

### In scope

1. Install `laravel/cashier-stripe:^15` via Composer.
2. Publish Cashier's migrations with the timestamp convention `2026_04_18_NNNNNN_*`. Cashier creates: columns on the `businesses` table (`stripe_id`, `pm_type`, `pm_last_four`, `trial_ends_at`) + `subscriptions` table + `subscription_items` table. No conflict with the existing `customers` table (Cashier stores the Stripe customer id as a column on the billable, does not create a `customers` table).
3. Add the `Laravel\Cashier\Billable` trait to `Business`. No contract change — Cashier is a trait, not an interface.
4. `config/billing.php` — new config file. Keys: `prices.monthly`, `prices.annual`, `currency` (defaults `chf`), `trial_length_days` (null = indefinite sentinel; see §4.1). Values read from env (`STRIPE_PRICE_MONTHLY`, `STRIPE_PRICE_ANNUAL`).
5. `.env.example` gains `STRIPE_KEY`, `STRIPE_SECRET`, `STRIPE_WEBHOOK_SECRET`, `STRIPE_PRICE_MONTHLY`, `STRIPE_PRICE_ANNUAL`, `CASHIER_CURRENCY=chf` under a new `# Stripe (billing)` section.
6. `AppServiceProvider::boot()` enables Stripe Tax: `Cashier::calculateTaxes();`. No-op in local dev unless test keys are present.
7. New controller `App\Http\Controllers\Dashboard\Settings\BillingController` — `show`, `subscribe`, `portal`, `cancel`, `resume`. Thin bodies, all business-scoped via `tenant()->business()`.
8. New admin-only routes under the existing `role:admin` settings group (aligns with D-081 — billing is admin-only, never staff):
   - `GET /dashboard/settings/billing` → `show`
   - `POST /dashboard/settings/billing/subscribe` → `subscribe` (accepts `plan=monthly|annual`, redirects to Stripe Checkout)
   - `POST /dashboard/settings/billing/portal` → `portal` (redirects to Stripe Customer Portal)
   - `POST /dashboard/settings/billing/cancel` → `cancel` (Stripe-side `cancel_at_period_end=true`)
   - `POST /dashboard/settings/billing/resume` → `resume` (during grace period)
9. `POST /webhooks/stripe` — top-level route, no auth, CSRF-excluded in `bootstrap/app.php` (same shape as `/webhooks/google-calendar`). Uses Cashier's `WebhookController` extended to add idempotency. Signature verified by Cashier's built-in middleware.
10. New middleware `App\Http\Middleware\EnsureBusinessCanWrite` — applied to a new inner group inside the existing dashboard group that wraps every mutating dashboard + settings route EXCEPT the five billing routes and the onboarding routes. Passes through all `GET`/`HEAD`/`OPTIONS`. On mutating verb + `!$business->canWrite()`, redirects to `settings.billing` with a flash error. Registered as `alias 'billing.writable'` in `bootstrap/app.php`.
11. `Business` model: override `onTrial()` contract-wise (see §4.1) — defines trial as "no subscription row exists for this business". New helpers: `canWrite()`, `subscriptionState()` (returns one of `'trial' | 'active' | 'past_due' | 'canceled' | 'read_only'`), `subscriptionStateForPayload()` (returns shaped array for the Inertia prop).
12. `HandleInertiaRequests::share()` — extend `auth.business` with `subscription` key:
    ```
    auth.business.subscription = {
      status: 'trial' | 'active' | 'past_due' | 'canceled' | 'read_only',
      trial_ends_at: string | null,
      current_period_ends_at: string | null,
    }
    ```
13. `resources/js/components/settings/settings-nav.tsx` — add "Billing" nav item under the "Business" group for admins only. Staff nav is unchanged (billing is admin-only per the `role:admin` group).
14. `resources/js/pages/dashboard/settings/billing.tsx` — new page. States:
    - **Trial**: hero card showing "Free trial — no billing information on file" + two buttons (Monthly / Annual) that POST to the subscribe route with the chosen plan, each rendering price + interval.
    - **Active**: current plan, next renewal date, "Manage billing" (portal), "Cancel subscription" destructive link.
    - **Canceling (grace)**: current plan, "Subscription will end on {date}", "Resume subscription" link, "Manage billing".
    - **Past due**: current plan, warning banner explaining the failed payment, "Manage billing" (updating card).
    - **Read-only**: prominent "Your subscription has ended" banner, two prominent Subscribe buttons (monthly / annual) + "Manage billing" to see past invoices.
15. `resources/js/components/layouts/authenticated-layout.tsx` (or wherever the D-078 bookability banner lives) — when `auth.business.subscription.status === 'read_only'`, render a persistent destructive Alert above page content with a link to Billing. When `status === 'canceled'` or `'past_due'`, render a warning Alert. The D-078 unbookable-services banner keeps its existing behaviour; these are additive.
16. `resources/js/types/index.d.ts` — extend the `Business` interface with `subscription: SubscriptionState`; add `SubscriptionState` interface.
17. `tests/Feature/Billing/` — new directory. New Pest feature tests (~20 cases, see §6). Canonical pattern: post signed Stripe-format payloads to `/webhooks/stripe` for webhook idempotency + state-transition tests; for the controller tests, exercise the actions under `actingAs` and assert the redirect and side-effects.
18. `docs/DEPLOYMENT.md` — appended with a new "Billing (Stripe)" section: Stripe account test-mode setup, price creation, webhook endpoint + secret, `STRIPE_*` env vars, Stripe Tax requirement, pre-launch activity to flip to live keys, read-only enforcement behaviour after cancellation.
19. `php artisan wayfinder:generate` after route additions; Wayfinder TypeScript deltas committed; `npm run build` clean.
20. Pint clean. Feature + Unit suite green. Feature + Unit test count delta reported (target roughly +20 cases, ~602 passed / ~2550 assertions; exact count confirmed after implementation).

### Explicitly out of scope

- Stripe Connect / customer-facing payment at booking time (v2; `payment_mode` untouched).
- Multi-tier plans (Starter/Pro). One paid tier only, locked #9.
- Staff caps, booking caps, any tier-based plan limit. Locked #11.
- Live-mode keys, real Stripe account creation, production webhook endpoint registration — pre-launch per `docs/ROADMAP.md §Pre-Launch`.
- Invoice template customisation, proration policies beyond Cashier defaults.
- Per-business email branding of billing emails (existing carry-over).
- Outlook / Apple / CalDAV calendar sync.
- Session 4 (provider self-service settings) and Session 5 (advanced calendar interactions).
- Trial length cap (currently indefinite). Capturing the easy future-move is part of §4.1's rationale but no cap is introduced today.
- Touching `payment_mode` or any customer-facing payment concept.
- Changing existing onboarding flow. Trial is the natural post-registration state under §4.1 — no observer, no RegisterController hook.

## 4. Key design decisions

### 4.1 Trial representation — **D-089**

Cashier ships `onGenericTrial()`:
```
public function onGenericTrial()
{
    return $this->trial_ends_at && $this->trial_ends_at->isFuture();
}
```
Null `trial_ends_at` returns false under Cashier's default semantics. Three options were evaluated:

- **(a) Null + convention**: `trial_ends_at = null` means "on trial". Requires overriding `onGenericTrial()` OR introducing a custom predicate. No migration churn, no sentinel dates, no extra column.
- **(b) Far-future sentinel**: `trial_ends_at = 2099-12-31` (or similar). Cashier's default `onGenericTrial()` works unchanged. Sentinel dates are load-bearing magic, fail mode is silent drift if any code re-reads the column as a real date.
- **(c) New `has_used_trial` boolean column**: Orthogonal to Cashier's trial surface. Extra column, extra reasoning axis, extra test surface.

**Picked (a) with a specific shape**: Do NOT override Cashier's `onGenericTrial()`. Instead, define a product-semantic helper `Business::onTrial(): bool` that returns `true` iff no `subscriptions` row exists for this business. This matches how the product actually thinks about "trial" — a business is on trial until they have ever subscribed, after which they are either active, canceled, or past due per Stripe. `trial_ends_at` stays null and unused in MVP.

Rationale vs the other options:
- **Vs (b)**: No sentinel reads-as-magic. If `trial_ends_at` is non-null in this codebase, it was written explicitly by a future trial-length-cap change. Today it stays null.
- **Vs (c)**: Same information is derivable from `$business->subscriptions()->exists()`, which is already a Cashier-native query. Adding a boolean would duplicate state and drift.
- **Future-proofing**: When/if a "introduce 30-day trial cap" policy is adopted later, set `trial_ends_at = now()->addDays(30)` at registration (RegisterController side-effect) AND update `onTrial()` to: "no subscription OR (trial_ends_at is future AND no subscription)". The migration column is already there — the policy flip is a one-line change in RegisterController and a one-line update in `onTrial()`. No schema migration needed.

**Product-semantic helpers on `Business`** (all three are part of D-089):

```php
public function onTrial(): bool
{
    return ! $this->subscriptions()->exists();
}

public function subscriptionState(): string
{
    if ($this->onTrial()) {
        return 'trial';
    }

    $sub = $this->subscription();

    if ($sub === null || $sub->ended()) {
        return 'read_only';
    }

    if ($sub->pastDue()) {
        return 'past_due';
    }

    if ($sub->canceled() && $sub->onGracePeriod()) {
        return 'canceled';
    }

    return 'active';
}

public function canWrite(): bool
{
    return in_array($this->subscriptionState(), ['trial', 'active', 'past_due', 'canceled'], true);
}
```

`past_due` is write-allowed in MVP: Stripe is dunning the card and we don't want to lock the business out during a payment retry window. Stripe eventually promotes `past_due` → `canceled` if all retries fail, at which point the webhook fires and we transition to read-only.

`canceled` (cancel-at-period-end during grace) is write-allowed: the business has paid for the period. They don't lose access mid-period for scheduling a cancellation.

Only `read_only` blocks writes. It is entered exclusively via the `customer.subscription.deleted` webhook (or, defensively, when `$sub->ended()` is true for any other reason).

### 4.2 Read-only enforcement layer — **D-090**

Three options evaluated, per the brief:
- **(a) Middleware on mutating routes**
- **(b) Policy on each mutating controller action**
- **(c) Centralised `Business::canWrite()` check at every mutating controller entry point**

**Picked (a): middleware**. Specifically, `App\Http\Middleware\EnsureBusinessCanWrite`, aliased `billing.writable`, applied to a new **inner** group inside the existing dashboard group at `routes/web.php:101`.

Middleware semantics:
- Passes through `GET`/`HEAD`/`OPTIONS` unconditionally. Read-only mode means read still works; only writes are blocked.
- On any other method: if `tenant()->business()` is null, pass through (caller will hit auth/role/onboarded guards). Otherwise check `$business->canWrite()`. If false, redirect to `route('settings.billing')` with `flash('error', __('Your subscription has ended. Please resubscribe to continue.'))`.
- Skips JSON responses? No — the request is a mutation regardless of `Accept` header. If a future AJAX mutation lands on this middleware, it gets a 302 back to billing; the frontend handler treats that as a redirect, which is the correct UX.

Route structure (rewrites `routes/web.php` §dashboard):

```php
Route::middleware(['verified', 'role:admin,staff', 'onboarded'])->group(function () {
    // Billing routes — admin only, OUTSIDE the canWrite gate so users can
    // always reach Subscribe / Portal / Cancel / Resume while read-only.
    Route::middleware('role:admin')->prefix('dashboard/settings')->group(function () {
        Route::get('/billing', [BillingController::class, 'show'])->name('settings.billing');
        Route::post('/billing/subscribe', [BillingController::class, 'subscribe'])->name('settings.billing.subscribe');
        Route::post('/billing/portal', [BillingController::class, 'portal'])->name('settings.billing.portal');
        Route::post('/billing/cancel', [BillingController::class, 'cancel'])->name('settings.billing.cancel');
        Route::post('/billing/resume', [BillingController::class, 'resume'])->name('settings.billing.resume');
    });

    // All other dashboard + settings routes gated by the billing.writable middleware.
    Route::middleware('billing.writable')->group(function () {
        // ... existing dashboard routes (bookings, calendar, customers, settings) ...
    });
});
```

Why middleware beats the alternatives:

- **Vs policy (b)**: Billing state is not a permission of the acting user over a specific resource. It is a tenant-level "can this business mutate anything at all" gate. A policy per mutating action is N × N growth; middleware is 1 × N.
- **Vs controller-level `canWrite()` check (c)**: Centralised check scatters the call across ~30 mutating actions (every controller under `Dashboard\*`). Easy to forget one. Middleware runs before the controller, closes every write path at the router level, can be verified by a single dataset-driven test that walks the route list (see §6).

**Write paths closed (verified via `php artisan route:list` walkthrough + dataset test):**

- `POST/PATCH/DELETE /dashboard/bookings/*` (manual booking + status + notes)
- `POST/PUT/DELETE /dashboard/settings/*` except `billing/*` (profile, booking settings, hours, exceptions, services, staff, providers, account, embed)
- `POST /dashboard/calendar-pending-actions/{action}/resolve`
- `POST/GET /dashboard/settings/calendar-integration/connect|callback|configure|sync-now|disconnect` — read-only-mode blocks the reconnect path specifically, matching the product intent that an expired subscription halts all mutations.

**Write paths that stay open:**

- `POST/DELETE /dashboard/settings/billing/*` — all five billing routes. You can resubscribe while read-only.
- `GET /dashboard/*` — everything readable.
- Webhooks (`/webhooks/stripe`, `/webhooks/google-calendar`).
- Public booking (`/{slug}`, `/booking/{slug}/*`). Guests booking a customer-of-an-expired-business can still book — their experience is unaffected by the salon's billing state. Product trade-off: lapsed-but-not-wilfully-canceled businesses don't surprise their customers with dead booking pages. The read-only state surfaces only in the dashboard. (Alternative "lock public booking too" was rejected: surprises the wrong party.)
- Authentication / invitation / onboarding / email-verify (outside the gated group).
- Customer area `/my-bookings` (separate role middleware).

### 4.3 Webhook endpoint naming — **D-091**

Cashier's default webhook route is `/stripe/webhook`. The existing webhook in this codebase is `/webhooks/google-calendar`. Aligning means one of:

- Override Cashier's default: in `config/cashier.php`, set the webhook URL to `webhooks/stripe`; set the route path via `Cashier::ignoreRoutes()` + explicit route registration.
- Accept Cashier's default at `/stripe/webhook`.

**Picked**: register `/webhooks/stripe` explicitly, call `Cashier::ignoreRoutes()` in `AppServiceProvider::register()` to suppress Cashier's auto-registration. The handler controller extends Cashier's `WebhookController` (§4.4).

Benefits:
- Consistent prefix for all third-party webhooks (`webhooks/*`).
- Easier for ops to reason about firewall/WAF rules targeting a single prefix.
- Stripe dashboard webhook URL matches existing convention.

Cost:
- Two lines of code (ignoreRoutes + a route registration).

Not worth preserving the `/stripe/webhook` default for zero benefit.

### 4.4 Webhook idempotency — **D-092**

Stripe retries webhook deliveries with the same event id. Cashier does **not** dedupe; its default `WebhookController` processes every invocation. Duplicates are usually cosmetically harmless (Cashier's update handlers are mostly idempotent on DB state), but `invoice.payment_succeeded` firing twice could double-count future analytics or future business logic hooks — better to dedupe at the boundary.

**Picked**: thin subclass `App\Http\Controllers\Webhooks\StripeWebhookController extends \Laravel\Cashier\Http\Controllers\WebhookController`. Override `handleWebhook` to:

1. Parse the payload's `event.id`.
2. Check `cache()->has("stripe:event:{$eventId}")`. If yes, return 200 immediately (log debug).
3. Otherwise `cache()->put("stripe:event:{$eventId}", true, now()->addDay())`.
4. Delegate to `parent::handleWebhook()`.

Cache driver in prod is `database` (per `.env`), in tests is `array`. Dedup window of 24h matches Stripe's retry policy envelope. No new table — the cache layer is already durable.

Alternative rejected: dedicated `stripe_events` table. Cleanable but more ceremony for the same guarantee.

### 4.5 Registration side-effect — **D-089 consequence** (no new decision ID)

Because D-089's `onTrial()` reads "no subscription record exists", new businesses are automatically on indefinite trial with zero code added. RegisterController does not touch billing state. BusinessFactory does not touch billing state. No observer. No migration side-effect. The Cashier migration adds `trial_ends_at` as a nullable column on `businesses`, which defaults to null for existing rows; our `onTrial()` implementation doesn't read it.

**Why not via observer / factory / controller**:
- Observer: adds an abstraction with one job. Observer semantics vs. controller-level side-effects are a Pando vote in this codebase; the existing pattern is explicit controller code (D-075 dispatches notifications from controllers, not observers).
- Factory: would couple test-fixture behaviour to billing state. Factories should set data, not business-policy-derived state.
- Controller: matches existing conventions — but there's nothing to DO. `trial_ends_at` stays null; no subscription is created; Stripe customer is only created at Subscribe time. Adding `$business->trial_ends_at = null; $business->save();` would be dead code.

### 4.6 Pricing values — **D-093**

Plan pricing:
- **Monthly**: **CHF 29.00/month**
- **Annual**: **CHF 290.00/year** (~17% discount, equivalent to two months free)

Locked roadmap decision #9 defers the exact numbers to session-plan time. CHF 29 is a credible entry-tier B2B SaaS price in the Swiss market for a single-location salon/small business, aligned with peers (Salonized, Fresha, SimplyBook.me entry tiers when priced for single-user CHF). CHF 290 annual with ~17% discount is the standard pattern.

Storage:
- `config/billing.php`:
  ```
  'prices' => [
      'monthly' => env('STRIPE_PRICE_MONTHLY'),
      'annual' => env('STRIPE_PRICE_ANNUAL'),
  ],
  'currency' => env('CASHIER_CURRENCY', 'chf'),
  ```
- `.env.example` receives placeholder keys with a comment explaining the test-mode price creation steps (see §7 DEPLOYMENT).

The developer flips prices by editing `config/billing.php` display labels and the Stripe product prices — no code change in controllers.

### 4.7 Tax handling — **D-094**

Swiss VAT is 8.1% (MWST/TVA/IVA). For MVP, we defer tax collection to Stripe Tax:

- `AppServiceProvider::boot()`: `Cashier::calculateTaxes();`. This globally enables automatic tax on Checkout and on Invoice generation for any charge via Cashier.
- Checkout session passes `'automatic_tax' => ['enabled' => true]` (or the Cashier v15 equivalent argument; confirm during implementation).
- Business is a Stripe customer; address syncing happens at Checkout (customer fills it in), so we do NOT need to collect a business address inside riservo for tax purposes today.

Requirements called out in DEPLOYMENT:
- Stripe Tax must be enabled in the Stripe account (test + live).
- Origin address of the product seller (riservo.ch) is configured once in the Stripe Dashboard.
- Any customer outside CH that subscribes will be taxed per Stripe's nexus rules (effectively: Stripe handles it, not our problem).

Alternative rejected: skip tax at MVP and defer the whole thing to pre-launch. Rationale: the price a Swiss business sees must be VAT-clear from day one; a 29 CHF price that becomes 31.35 CHF on an invoice mid-launch is a bad surprise. Stripe Tax is a flip-of-a-switch from Cashier's side, so the cost of enabling now is the Stripe dashboard checkbox; the cost of deferring is a post-launch surprise + retroactive invoicing.

### 4.8 Billing page location — confirmed

`/dashboard/settings/billing`. Under the admin-only `role:admin` settings group (billing is admin-only; staff never see it). Aligned with existing naming convention (`settings.profile`, `settings.booking`, `settings.hours`, `settings.services`, etc.). Added to `settings-nav.tsx` under the "Business" group, admin-only (staff nav unchanged).

### 4.9 Scope of the shared `auth.business.subscription` prop

The shape is bounded to what the UI needs to render the page, the nav badges (if any), and the banner. Intentionally small:

```
{
  status: 'trial' | 'active' | 'past_due' | 'canceled' | 'read_only',
  trial_ends_at: string | null,          // ISO; null for indefinite trial
  current_period_ends_at: string | null, // ISO; null when no subscription
}
```

Resolved server-side in `HandleInertiaRequests::share()` via a new `Business::subscriptionStateForPayload()` helper. All four status values map to established Cashier predicates (`onGracePeriod`, `pastDue`, `ended`, `canceled`); the trial status is our own (§4.1). Banner rendering and button copy are driven entirely off `status`.

**Eager-load to avoid per-request N+1**. Every authenticated Inertia request runs `subscriptionStateForPayload()`, which in turn calls `subscriptions()->exists()` (the trial check) and `subscription('default')` (the active-row lookup) — two queries per request on the hot path. Before returning the payload, `HandleInertiaRequests::resolveBusiness()` calls `$business->loadMissing('subscriptions')`. `onTrial()` then reads the already-loaded collection via `$this->subscriptions->isEmpty()`; `subscription('default')` resolves from the same collection because Cashier's `subscription($type)` reads through the relation. One query total.

The helpers' internal implementation is updated accordingly:

```php
public function onTrial(): bool
{
    return $this->subscriptions->isEmpty();
}
```

`$this->subscriptions` (accessor, not the method) resolves the already-loaded collection when present. If the middleware forgot the eager-load, the accessor lazy-loads the single-row query — correctness preserved. The middleware's `loadMissing` is a performance optimisation, not a correctness gate.

### 4.10 Plan-not-configured safety

On local dev before the developer has added Stripe test keys: `STRIPE_KEY` empty, `STRIPE_PRICE_MONTHLY` empty. In this state:

- `BillingController::show` renders the page — trial status comes from `onTrial()`, which works with or without Stripe keys.
- Subscribe buttons are disabled (pass `config('billing.prices.monthly') ? ... : ...` into the page props; the frontend hides the two subscribe buttons when null). Copy: "Subscribe (setup required)" helper text linking to DEPLOYMENT.
- Portal button hidden when no Stripe customer exists (no `stripe_id`).
- No network calls to Stripe are made without keys.

This means the test suite can run without Stripe env vars. Only tests that specifically exercise the subscribe / portal / cancel / resume actions need stubbed Stripe HTTP responses (§6).

### 4.11 Testing strategy — **D-095**

Corrected after source-level verification of Cashier v15.x.

**Negative findings**:
- `Cashier::fake()` does **not** exist in v15.x. Checked `src/Cashier.php` directly — the only `public static` methods are `stripe()`, `findBillable()`, `useCustomerModel()`, `formatAmount()`, `formatCurrencyUsing()`. No public test helper.
- `Cashier::useStripeClient($client)` does **not** exist either.
- `Http::fake()` will **not** intercept Stripe SDK calls — Stripe's SDK uses `Stripe\HttpClient\CurlClient` through its own transport, not Laravel's `Http` facade. The earlier draft of this plan naming `Http::fake()` was wrong and would have produced either real-Stripe-hit tests or "invalid API key" failures.

**Positive finding — the seam Cashier actually uses**: every Stripe SDK call in Cashier v15 goes through `Cashier::stripe()` (or `static::stripe()`, which delegates). The body of that method is:

```php
public static function stripe(array $options = [])
{
    $config = array_merge([
        'api_key' => $options['api_key'] ?? config('cashier.secret'),
        'stripe_version' => static::STRIPE_VERSION,
        'api_base' => static::$apiBaseUrl,
    ], $options);

    return app(StripeClient::class, ['config' => $config]);
}
```

The `app(StripeClient::class, …)` resolution is Laravel's container. Binding an instance overrides the resolution — the `['config' => …]` parameters are ignored when an instance is pre-bound. This is the clean mocking seam.

**Decision — bind a Mockery mock of `\Stripe\StripeClient` in the container per test**:

```php
// tests/Feature/Billing/BillingControllerTest.php
use Stripe\StripeClient;
use Stripe\Service\CheckoutService;
use Stripe\Service\CheckoutSessionService;
use Stripe\Service\BillingPortalService;
use Stripe\Service\BillingPortalSessionService;
use Stripe\Service\SubscriptionService;

beforeEach(function () {
    $this->stripe = Mockery::mock(StripeClient::class);
    $this->app->instance(StripeClient::class, $this->stripe);
});
```

StripeClient exposes service trees as properties (`$stripe->checkout`, `$stripe->billingPortal`, `$stripe->subscriptions`, `$stripe->customers`). Each level is a distinct Stripe service class. Mock them inline as properties on the top-level mock, then set expectations on the leaf:

```php
// Subscribe action — mocks Stripe\Checkout\Session::create via the container
$checkoutService = Mockery::mock(CheckoutService::class);
$sessionService = Mockery::mock(CheckoutSessionService::class);
$this->stripe->checkout = $checkoutService;
$checkoutService->sessions = $sessionService;
$sessionService->shouldReceive('create')
    ->once()
    ->with(Mockery::on(fn ($args) => $args['mode'] === 'subscription'))
    ->andReturn((object) [
        'id' => 'cs_test_123',
        'url' => 'https://checkout.stripe.com/c/pay/cs_test_123',
    ]);
```

This is verbose by design — it captures the exact Stripe shape the controller exercises and catches any future drift where Cashier starts calling a different endpoint.

**Methods to mock per BillingController action**:

| Action | Stripe SDK path | Mock target |
|--------|-----------------|-------------|
| `subscribe` | `$stripe->checkout->sessions->create([...])` | `CheckoutSessionService::create` |
| `portal` | `$stripe->billingPortal->sessions->create([...])` (triggered when `redirectToBillingPortal` runs; `stripe_id` required, so stub via `ManagesCustomer::createOrGetStripeCustomer` path if needed) | `BillingPortalSessionService::create` + `CustomerService::retrieve` (if the Business has no `stripe_id` yet) |
| `cancel` | `$stripe->subscriptions->update($id, ['cancel_at_period_end' => true])` | `SubscriptionService::update` |
| `resume` | `$stripe->subscriptions->update($id, [...])` | `SubscriptionService::update` |

Cashier's internal calls during these paths (`$stripe->customers->create`, `$stripe->customers->update` for address sync under Stripe Tax) are mocked as needed — identified at implementation time by exercising the test and reading the Mockery "method not expected" errors.

**Shared test helper** — to keep verbosity bounded, `tests/Support/Billing/FakeStripeClient.php` provides a builder:

```php
FakeStripeClient::for($this)
    ->mockCheckoutSession(returns: ['url' => 'https://checkout.stripe.com/…'])
    ->mockBillingPortalSession(returns: ['url' => 'https://billing.stripe.com/…'])
    ->mockSubscriptionUpdate();
```

Same pattern MVPC-2 used for `FakeCalendarProvider`. Programmable double with just enough methods for the tests that need it.

**Webhook tests — different seam, same file is fine**:

- POST the canonical Stripe event shape (copied from Stripe's public webhook docs) directly to `/webhooks/stripe`.
- For signature: set `config(['cashier.webhook.secret' => null])` inside the test. Cashier's `VerifyWebhookSignature` middleware skips verification when the secret is null — the library's own test pattern. The "production path signs and verifies; test path opts out" separation is captured by a dedicated test that POSTs with a known-bad signature and asserts 403 (covering the verification code path), plus the rest-of-matrix tests running with `null` secret.
- Dedup assertions via `Cache::has('stripe:event:…')` before and after the POST.

**Business-model unit tests** — zero Stripe involvement:

- Create `Business` + `Subscription` rows via Cashier's `Subscription` factory (ships with the package; verify during implementation step 2).
- Exercise the `onTrial() / canWrite() / subscriptionState()` matrix by manipulating `$subscription->stripe_status`, `->ends_at`, and `->stripe_price` directly.

**Summary of the boundary**:

| Test file | Stripe mocking path | Why |
|-----------|--------------------|-----|
| `BillingControllerTest` | `$this->app->instance(StripeClient::class, Mockery::mock(...))` | Controller calls Cashier which calls the SDK; SDK resolution goes through the container. |
| `WebhookTest` | `config(['cashier.webhook.secret' => null])` for matrix; one signature-verify-fails test for coverage | POST to local route, no SDK. |
| `BusinessSubscriptionStateTest` | None | Pure model. |
| `ReadOnlyEnforcementTest` | None | Pure middleware; subscription rows created via factory directly. |

No hand-rolled HTTP mocks. Every fake either binds a well-known container key or flips a documented Cashier config. Mechanism is recorded as **D-095** (see §8).

### 4.12 Server-side automation on a read-only business — confirmed intentional

The `billing.writable` middleware gates **HTTP** mutations only. Server-side automation — queued jobs, scheduled commands, webhook handlers — runs unconditionally on every business regardless of subscription state.

The concrete case is `AutoCompleteBookings` (nightly command): it transitions confirmed past-end bookings to `completed`, which per MVPC-2 dispatches `PushBookingToCalendarJob(update)`. On a read-only business, Google Calendar events tied to old bookings keep updating as those bookings complete.

This is intentional. The business paid for the period those bookings live in. Their customers (guests with signed-URL cancellation tokens; registered customers in `/my-bookings`) and their connected Google calendars must continue to reflect the true lifecycle of already-created bookings until those bookings close out naturally. The product intent of the read-only state is "no new mutations from the dashboard UI", not "freeze all system behaviour in amber".

Other in-scope automation that keeps running on a read-only business:
- `SendBookingReminders` — reminder emails for bookings already created pre-lapse still fire.
- `calendar:renew-watches` — existing Google Calendar watches continue to renew.
- `PullCalendarEventsJob` — inbound calendar webhooks still upsert external events (the business may not be able to see pending-actions UI in write mode, but the calendar data stays consistent).
- `StripeWebhookController` — obviously runs; it's the path back to `active` when the business resubscribes.

Documented in `docs/DEPLOYMENT.md §Billing` under "Read-only behaviour after cancellation" so operators aren't surprised when a lapsed business's queue remains active.

## 5. Implementation steps, in order

1. **Install Cashier.** `composer require laravel/cashier-stripe:^15 --no-interaction`. Verify the package auto-discovers.
2. **Publish migrations.** `php artisan vendor:publish --tag=cashier-migrations`. Move the generated files from their default timestamp to the `2026_04_18_NNNNNN_*` naming convention:
   - `2026_04_18_100000_add_customer_columns_to_businesses_table.php` — renames Cashier's "add customer columns to users table" to target `businesses`. Use `$table->string('stripe_id')->nullable()->unique()`, `pm_type`, `pm_last_four`, `trial_ends_at`. Cashier's published migration targets `users` by default; a manual rewrite to point at `businesses` is mandatory because our billable is `Business`.
   - `2026_04_18_100001_create_subscriptions_table.php` — Cashier default, renamed.
   - `2026_04_18_100002_create_subscription_items_table.php` — Cashier default, renamed.
3. **Run migrations.** `php artisan migrate` against the dev DB. Verify the new columns on `businesses` and the two new tables. Factory regressions on `BusinessFactory` are zero (columns are nullable).
4. **Config.** Publish `config/cashier.php` if the project wants to override defaults (`php artisan vendor:publish --tag=cashier-config`). Keep defaults + set `'currency' => env('CASHIER_CURRENCY', 'chf')`, `'webhook' => ['secret' => env('STRIPE_WEBHOOK_SECRET'), 'tolerance' => 300]`, and call `Cashier::ignoreRoutes()` in `AppServiceProvider::register()` before explicit route registration (§step 10).
5. **Create `config/billing.php`.** Keys per §4.6. No vendor:publish needed — bespoke config.
6. **`.env.example`.** Append:
   ```
   # Stripe (billing) — test-mode keys until pre-launch. See docs/DEPLOYMENT.md §Billing.
   STRIPE_KEY=
   STRIPE_SECRET=
   STRIPE_WEBHOOK_SECRET=
   STRIPE_PRICE_MONTHLY=
   STRIPE_PRICE_ANNUAL=
   CASHIER_CURRENCY=chf
   ```
7. **AppServiceProvider::boot().** Add `Cashier::calculateTaxes();` after other bindings.
8. **Business model.** Add `use Laravel\Cashier\Billable;` trait. Add `Business` to the model PHPDoc with the four new Cashier columns (`stripe_id`, `pm_type`, `pm_last_four`, `trial_ends_at`). Add the three helpers `onTrial()`, `subscriptionState()`, `canWrite()` per §4.1 + a `subscriptionStateForPayload()` method that returns the shaped array for the Inertia prop.
9. **BillingController.** `php artisan make:controller Dashboard/Settings/BillingController --no-interaction`. Five actions:
   - `show(): Response` — render `dashboard/settings/billing`. Pass `subscription` (shaped as §4.9), `prices` (`['monthly' => ['id' => ..., 'amount' => 29, 'currency' => 'CHF'], 'annual' => [...]]`), `has_stripe_keys` (bool derived from `config('billing.prices.monthly') !== null`). All localised.
   - `subscribe(SubscribeRequest $request): RedirectResponse` — validates `plan ∈ ['monthly', 'annual']`; looks up the price id from `config('billing.prices.'.$plan)`; `return $business->newSubscription('default', $priceId)->checkout(['success_url' => route('settings.billing').'?checkout=success', 'cancel_url' => route('settings.billing').'?checkout=cancel'])`. Returns Cashier's redirect response.
   - `portal(): RedirectResponse` — `return $business->redirectToBillingPortal(route('settings.billing'))`.
   - `cancel(): RedirectResponse` — `$business->subscription('default')->cancel(); return redirect()->route('settings.billing')->with('success', __('Your subscription will end on :date.', ['date' => ...]));`
   - `resume(): RedirectResponse` — `$business->subscription('default')->resume(); return redirect()->route('settings.billing')->with('success', __('Your subscription has been resumed.'));`
   
   All actions call `tenant()->business()` and guard against `null`/no-subscription states with early returns / friendly flashes.
10. **Routes.** Edit `routes/web.php`:
    - Add the five billing routes under the admin-only settings group (new block before the shared calendar-integration group).
    - Wrap existing non-billing dashboard routes in a new `Route::middleware('billing.writable')->group(...)` block. This is a structural edit — ~60 lines of routes move into the gate.
    - Top-level: `Route::post('/webhooks/stripe', [StripeWebhookController::class, 'handleWebhook'])->name('webhooks.stripe');`
11. **Webhook controller.** Create `app/Http/Controllers/Webhooks/StripeWebhookController.php` extending `Laravel\Cashier\Http\Controllers\WebhookController`. Override `handleWebhook(Request $request)` for idempotency (§4.4).
12. **CSRF exclusion.** `bootstrap/app.php`: add `'webhooks/stripe'` to the existing `preventRequestForgery(except: [...])` list.
13. **Middleware registration.** `bootstrap/app.php`: register `'billing.writable' => EnsureBusinessCanWrite::class` in the alias array.
14. **Middleware class.** `php artisan make:middleware EnsureBusinessCanWrite --no-interaction`. Body per §4.2.
15. **Form Requests.** `SubscribeRequest` with validation `plan` in `['monthly', 'annual']`.
16. **HandleInertiaRequests.** Extend `resolveBusiness` to include `subscription` via `$business->subscriptionStateForPayload()` when the user is authenticated and a tenant exists. **Eager-load** the subscriptions relation immediately before building the payload: `$business->loadMissing('subscriptions')`. This collapses the two queries `subscriptions()->exists()` + `subscription('default')` into one relation-load that both helpers read through (§4.9).
17. **Wayfinder.** `php artisan wayfinder:generate`.
18. **Settings nav.** Edit `settings-nav.tsx` to add "Billing" to the admin's "Business" group.
19. **Billing page.** Create `resources/js/pages/dashboard/settings/billing.tsx`. Use `<Form>` for each action (subscribe / portal / cancel / resume). Conditional rendering per `subscription.status`. All strings via `useTrans()`.
20. **Banner.** Extend `resources/js/layouts/authenticated-layout.tsx` (or the component holding the D-078 banner) to render a read-only/canceled/past-due Alert above page content when `subscription.status` warrants it. Admin-only for `read_only`/`canceled`; staff sees a muted read-only notice (no "Resubscribe" CTA — they can't act).
21. **Types.** Extend `resources/js/types/index.d.ts`: `SubscriptionState` interface + `Business.subscription: SubscriptionState`.
22. **Tests.** Per §6.
23. **Docs.** `docs/DEPLOYMENT.md` new "Billing (Stripe)" section. `docs/decisions/DECISIONS-FOUNDATIONS.md` gets D-089, D-090, D-091, D-092, D-093, D-094, D-095.
24. **Pint + build.** `vendor/bin/pint --dirty --format agent`; `npm run build`.
25. **Feature + Unit suite.** `php artisan test tests/Feature tests/Unit --compact`. Target: +~20 cases over 582 baseline.

## 6. Tests

### `tests/Feature/Billing/BusinessSubscriptionStateTest.php` (model-level, no Stripe)

1. New business has `subscriptionState() === 'trial'`; `onTrial()` true; `canWrite()` true.
2. Business with an active `subscription` row has `subscriptionState() === 'active'`.
3. Business with a canceled-at-period-end subscription, within grace, has `subscriptionState() === 'canceled'`; `canWrite()` true.
4. Business with a subscription whose `ends_at` is past has `subscriptionState() === 'read_only'`; `canWrite()` false.
5. Business with a past-due subscription has `subscriptionState() === 'past_due'`; `canWrite()` true.
6. `subscriptionStateForPayload()` returns the shaped array with the right keys per state.

### `tests/Feature/Billing/BillingControllerTest.php` (controller-level, `Http::fake()`)

7. `GET /dashboard/settings/billing` admin 200 with `subscription.status === 'trial'` when no subscription exists.
8. `GET /dashboard/settings/billing` staff gets 403 (admin-only).
9. `POST /dashboard/settings/billing/subscribe` with `plan=monthly` creates a Checkout session (asserted via `Http::assertSent` on `/v1/checkout/sessions`) and redirects to the Stripe-returned URL.
10. `POST /dashboard/settings/billing/subscribe` with unknown plan returns 422.
11. `POST /dashboard/settings/billing/portal` with no `stripe_id` flashes an error and stays on the page.
12. `POST /dashboard/settings/billing/portal` with a `stripe_id` redirects to the Stripe-returned portal URL.
13. `POST /dashboard/settings/billing/cancel` on an active subscription calls Stripe cancel (mocked) and flashes a success with the end date.
14. `POST /dashboard/settings/billing/resume` on a canceled-in-grace subscription calls Stripe resume (mocked) and flashes success.

### `tests/Feature/Billing/WebhookTest.php` (webhook endpoint)

15. Signature verification: posting with an invalid signature returns 403 (Cashier's built-in behaviour).
16. `customer.subscription.created` event upserts a `subscriptions` row; returns 200.
17. `customer.subscription.updated` event updates `stripe_status` + `ends_at`; returns 200.
18. `customer.subscription.deleted` event marks the subscription as ended; `Business::subscriptionState()` transitions to `read_only`.
19. `invoice.payment_failed` event sets the subscription to past_due; state transitions to `past_due`.
20. Idempotency: posting the same event id twice calls the Cashier handler exactly once (asserted via a cache-probe or a Cashier subscription-side-effect assertion).

### `tests/Feature/Billing/ReadOnlyEnforcementTest.php` (middleware, dataset-driven)

21. Dataset of mutating routes (manual booking create, booking status update, service create, staff invite, profile update, exception store, calendar-integration configure, calendar-pending-action resolve, …). For each: admin of a read-only business hitting the route with the correct verb + valid body returns 302 to `settings.billing` with an error flash; admin of an active business returns the controller's own response.
22. `GET` routes in the gated group are reachable by a read-only admin (smoke: dashboard + bookings list + calendar + settings.profile).
23. Billing routes themselves are reachable by a read-only admin (the whole point of the carve-out): `GET settings.billing` 200, `POST settings.billing.subscribe` starts Stripe Checkout.
24. Webhook endpoint is reachable regardless of billing state (read-only businesses must still receive Stripe events to transition back on resubscribe).
25. Public booking endpoints are reachable for a read-only business's slug (product trade-off per §4.2).

Total target: ~19–25 new cases + ~3–5 assertion-count expansions on `SettingsAuthorizationTest.php` (Billing row added to the admin-only matrix). Delta: +~20 cases, conservatively **602 passed** against the 582 baseline.

## 7. Files to create / modify

### Create

- `config/billing.php`
- `app/Http/Controllers/Dashboard/Settings/BillingController.php`
- `app/Http/Controllers/Webhooks/StripeWebhookController.php`
- `app/Http/Middleware/EnsureBusinessCanWrite.php`
- `app/Http/Requests/Billing/SubscribeRequest.php`
- `resources/js/pages/dashboard/settings/billing.tsx`
- Migrations (via Cashier publish + rename):
  - `database/migrations/2026_04_18_100000_add_customer_columns_to_businesses_table.php`
  - `database/migrations/2026_04_18_100001_create_subscriptions_table.php`
  - `database/migrations/2026_04_18_100002_create_subscription_items_table.php`
- Tests:
  - `tests/Feature/Billing/BusinessSubscriptionStateTest.php`
  - `tests/Feature/Billing/BillingControllerTest.php`
  - `tests/Feature/Billing/WebhookTest.php`
  - `tests/Feature/Billing/ReadOnlyEnforcementTest.php`

### Modify

- `composer.json` + `composer.lock` (via `composer require`)
- `config/cashier.php` (if publishing — set webhook secret + tolerance)
- `.env.example` — `STRIPE_*` block
- `app/Providers/AppServiceProvider.php` — `Cashier::calculateTaxes()`, `Cashier::ignoreRoutes()`
- `app/Models/Business.php` — `Billable` trait + helpers
- `app/Http/Middleware/HandleInertiaRequests.php` — `auth.business.subscription`
- `bootstrap/app.php` — middleware alias + CSRF exclusion
- `routes/web.php` — billing group, `billing.writable` gate, webhook route
- `resources/js/components/settings/settings-nav.tsx` — Billing admin item
- `resources/js/layouts/authenticated-layout.tsx` (or the D-078 banner host) — subscription banner
- `resources/js/types/index.d.ts` — `SubscriptionState` + `Business.subscription`
- `tests/Feature/Settings/SettingsAuthorizationTest.php` — Billing added to admin-only matrix
- Wayfinder-generated TypeScript under `resources/js/actions/.../BillingController.ts` and `resources/js/routes/settings/billing.ts`

### Docs

- `docs/decisions/DECISIONS-FOUNDATIONS.md` — D-089, D-090, D-091, D-092, D-093, D-094, D-095 (billing decisions live here because they cross-cut the platform rather than belonging to a single topical concern).
- `docs/HANDOFF.md` — rewritten (overwrite) with MVPC-3 state.
- `docs/DEPLOYMENT.md` — new "Billing (Stripe)" section.
- `docs/roadmaps/ROADMAP-MVP-COMPLETION.md` — tick every §Session 3 checkbox.
- `docs/plans/PLAN-MVPC-3-CASHIER-BILLING.md` → `docs/archive/plans/` at session close.

## 8. Decisions to record

All seven are new (D-088 was the last one recorded in MVPC-2).

### D-089 — Indefinite trial represented as "no subscription row exists"

- **Date**: 2026-04-17
- **Status**: accepted
- **Context**: Locked roadmap decision #10 requires indefinite trial with no card at signup. Cashier's default `onGenericTrial()` reads `trial_ends_at` and returns false for null. Three options evaluated (null + convention, far-future sentinel, boolean column).
- **Decision**: `Business::onTrial()` returns true iff no `subscriptions` row exists. `trial_ends_at` stays null and unused in MVP. Helpers `subscriptionState()` and `canWrite()` derive the product-semantic states. No observer, no factory change, no RegisterController hook: new businesses start with zero subscriptions, i.e. on indefinite trial.
- **Consequences**: No sentinel magic dates. No extra column. Future trial-length-cap policy is a one-line addition in RegisterController + one-line update in `onTrial()`; no migration. `onGenericTrial()` (Cashier) remains on the model via the trait but is not consulted by product code.
- **Freeload envelope for `past_due` (write-allowed)**: `past_due` is treated as a recoverable state — a salon whose card fails gets Stripe's default dunning window (~7 days / 4 retries; account-configurable up to ~3 weeks) before Stripe flips them to `canceled` and our webhook transitions them to `read_only`. In the worst case (permanently invalid card, maximum retry window), a lapsed salon keeps creating bookings for ~3 weeks before the dashboard locks. This envelope is acceptable for MVP — the alternative (gating `past_due` writes) locks out salons mid-payment-retry and causes legitimate customer-facing incidents. A future "past_due for more than N days → read_only" refinement is tracked in `docs/BACKLOG.md` as "Tighten billing freeload envelope"; not scoped for MVP.

### D-090 — Read-only enforcement via mutating-verb middleware on a dashboard inner group

- **Date**: 2026-04-17
- **Status**: accepted
- **Context**: Locked roadmap decision #12 requires that access becomes read-only after the cancellation period ends; locked enforcement strategy decided at plan time. Three options evaluated (middleware, policy, centralised controller check).
- **Decision**: `App\Http\Middleware\EnsureBusinessCanWrite` (alias `billing.writable`) applied to a new inner group inside the dashboard group. Passes through safe HTTP methods unconditionally. On any other method, if `!$business->canWrite()`, redirect to `settings.billing` with an error flash. Billing routes (`settings.billing*`), webhook routes, public booking routes, and authentication routes live OUTSIDE the gate. Public booking stays reachable for read-only businesses — product trade-off: lapsed salons don't surprise their customers mid-booking-flow.
- **Consequences**: One middleware class closes every dashboard write path. Dataset-driven test walks the gated route list. Adding a new dashboard mutating route in the future automatically inherits the gate. Exception carve-outs are explicit and reviewed.

### D-091 — Stripe webhook endpoint at `/webhooks/stripe`, Cashier auto-routing suppressed

- **Date**: 2026-04-17
- **Status**: accepted
- **Context**: Cashier's default webhook path is `/stripe/webhook`. The existing third-party webhook in this codebase is `/webhooks/google-calendar` (MVPC-2). Two options: keep Cashier's default or align to `/webhooks/*`.
- **Decision**: Register `/webhooks/stripe` explicitly; call `Cashier::ignoreRoutes()` to suppress Cashier's auto-registration. Handler is a thin subclass of `Laravel\Cashier\Http\Controllers\WebhookController`.
- **Consequences**: All third-party webhooks live under one prefix. Stripe dashboard endpoint URL matches the project convention. Trivial code cost (two lines).

### D-092 — Stripe webhook idempotency via cache-layer event-id dedup

- **Date**: 2026-04-17
- **Status**: accepted
- **Context**: Stripe retries webhook deliveries with the same event id. Cashier does not dedupe. Duplicates are mostly cosmetic but carry future-analytics risk; best closed at the boundary.
- **Decision**: `StripeWebhookController::handleWebhook` checks `cache()->has("stripe:event:{$eventId}")`, returns 200 early if present, otherwise sets the cache key (24h TTL) and delegates to `parent::handleWebhook()`. Cache driver is `database` in prod (durable) and `array` in tests.
- **Consequences**: Every Stripe event id is processed exactly once within a 24h window (longer than Stripe's retry envelope). No new table. Dedup is testable via `Cache::has(...)` assertions.

### D-093 — Pricing: CHF 29/month, CHF 290/year; price IDs via config/env

- **Date**: 2026-04-17
- **Status**: accepted
- **Context**: Locked roadmap decision #9 defers the exact numbers to session-plan time.
- **Decision**: Single paid tier, two prices — CHF 29/month and CHF 290/year (~17% discount, two months free). Price IDs stored in `config/billing.php` reading `STRIPE_PRICE_MONTHLY` and `STRIPE_PRICE_ANNUAL` from env. Currency set via `CASHIER_CURRENCY=chf`.
- **Consequences**: Prices are tunable without code changes by editing the Stripe products and flipping env vars. No downstream code branches on price.

### D-094 — Stripe Tax for Swiss VAT, enabled via `Cashier::calculateTaxes()`

- **Date**: 2026-04-17
- **Status**: accepted
- **Context**: Swiss VAT is 8.1%. MVP needs VAT-clean pricing from day one. Two options: implement VAT ourselves, or defer to Stripe Tax.
- **Decision**: `Cashier::calculateTaxes()` in `AppServiceProvider::boot()`. Checkout session enables automatic tax. Customer enters address at Checkout; Stripe computes and collects VAT. Stripe Tax must be enabled in the Stripe account (test and live).
- **Consequences**: Zero in-app VAT logic. The Swiss business's invoice shows the correct VAT line. Customers outside CH (unlikely in MVP) are taxed per Stripe's rules. Pre-launch checklist gains "enable Stripe Tax in live account".

### D-095 — Stripe SDK is mocked via container binding of `\Stripe\StripeClient`

- **Date**: 2026-04-17
- **Status**: accepted
- **Context**: Cashier v15's controller-path Stripe calls (`$business->newSubscription(...)->checkout(...)`, `$business->redirectToBillingPortal(...)`, `$subscription->cancel()`, `$subscription->resume()`) go through the Stripe PHP SDK, which uses its own cURL transport — Laravel's `Http::fake()` does **not** intercept them. Source-level inspection of `laravel/cashier-stripe@15.x` confirmed `Cashier::fake()` and `Cashier::useStripeClient()` do **not** exist in v15.x. Cashier's own test suite uses real Stripe test keys, which we reject (env dependency, latency, flakiness, real Stripe test artefacts in CI).
- **Decision**: Mock the Stripe SDK via Laravel's container. `Cashier::stripe()` resolves `\Stripe\StripeClient` through `app(StripeClient::class, ...)`. In tests, `$this->app->instance(StripeClient::class, Mockery::mock(StripeClient::class))` overrides the resolution. Property chains (`$stripe->checkout->sessions->create(...)`) are mocked by setting each level as a property on the top-level mock with a further Mockery mock attached, then `shouldReceive` on the leaf. A shared `tests/Support/Billing/FakeStripeClient.php` builder encapsulates the verbose wiring behind a fluent interface (mirroring MVPC-2's `FakeCalendarProvider` pattern).
- **Consequences**:
  - Zero real-Stripe calls in the test suite. No Stripe env dependency for CI.
  - Every test that exercises a Stripe path must declare what it expects the controller to call. Drift (Cashier changing which Stripe endpoint a method hits) surfaces as a Mockery "method not expected" failure, not a silent pass.
  - Webhook tests bypass the SDK entirely and POST canonical event shapes to `/webhooks/stripe`. The `config(['cashier.webhook.secret' => null])` helper skips signature verification for the matrix; a dedicated signature-verify test covers the production path.
  - Business-model unit tests exercise `onTrial() / canWrite() / subscriptionState()` against factory-built `subscriptions` rows directly — no Stripe involvement.
- **Rejected alternatives**:
  - *Real Stripe test keys* — matches Cashier's own test strategy but introduces env dependency, network latency, and real-artefact cleanup.
  - *`Http::fake()`* — doesn't intercept Stripe's cURL transport; would either hit real Stripe or fail with connection errors.
  - *`stripe-mock` side-container in CI* — heavier setup than the container-mock approach for the same fidelity at the test boundary; only worth revisiting if we end up needing real Stripe request/response shape validation.

## 9. Verification at session close

Commands (per the brief):

```bash
php artisan test tests/Feature tests/Unit --compact     # iteration loop — Feature+Unit only
vendor/bin/pint --dirty --format agent
php artisan wayfinder:generate                          # before npm run build
npm run build
```

Expected outcomes:
- `test tests/Feature tests/Unit --compact` — green; target roughly **602 passed / ~2550 assertions** (baseline 582 / 2471).
- `pint --dirty --format agent` — `{"result":"pass"}`.
- `wayfinder:generate` — Wayfinder files regenerated; `git status` shows auto-generated TypeScript deltas committed.
- `npm run build` — Vite build clean.
- `tests/Browser` — NOT run during iteration; developer's session-close check.

Manual smoke (documented in HANDOFF, requires real Stripe test keys):
1. Set `STRIPE_KEY`, `STRIPE_SECRET`, `STRIPE_WEBHOOK_SECRET`, `STRIPE_PRICE_MONTHLY`, `STRIPE_PRICE_ANNUAL` in `.env`.
2. Create a Stripe product "riservo Plan" with two prices (29 CHF/mo, 290 CHF/yr) in test mode.
3. Register a new business locally, complete onboarding, navigate to `/dashboard/settings/billing`. Page shows "Free trial" state.
4. Click Subscribe → Monthly. Stripe Checkout opens. Enter `4242 4242 4242 4242`, expiry future, CVC any, ZIP any. Complete.
5. Redirect back to `/dashboard/settings/billing?checkout=success`. Page shows "Active" state with renewal date.
6. Click Cancel. Modal → confirm. Page shows "Canceling" state with end date.
7. Click Resume. Page returns to "Active" state.
8. Use Stripe CLI (`stripe trigger customer.subscription.deleted`) with the subscription id to simulate period-end. Dashboard transitions to read-only. Try to create a manual booking → redirected to billing with error flash. Clicking Subscribe reopens Checkout.
9. Webhook endpoint reachable via a local tunnel (Herd Share or ngrok) registered in the Stripe Dashboard webhook settings.

## 10. Open questions — all resolved in this plan

Brief enumerated seven open questions; all resolved in §4.

- **Q1 Trial representation** → §4.1 / D-089. `onTrial()` = no subscription row. No sentinel, no column.
- **Q2 Registration side-effect** → §4.5. None needed — D-089 makes new businesses naturally on trial.
- **Q3 Read-only enforcement layer** → §4.2 / D-090. Middleware on mutating verbs on an inner dashboard group.
- **Q4 Pricing** → §4.6 / D-093. CHF 29 / CHF 290. Config-driven.
- **Q5 Webhook endpoint** → §4.3 / D-091. `/webhooks/stripe`, Cashier auto-routing suppressed.
- **Q6 Tax** → §4.7 / D-094. Defer to Stripe Tax. `Cashier::calculateTaxes()`.
- **Q7 Billing page location** → §4.8. `/dashboard/settings/billing`, admin-only.

No residual open questions for the developer.

## 11. Constraints carried in

From `CLAUDE.md` + the referenced decisions:

- Inertia v3 + React. Forms via `<Form>` (subscribe / portal / cancel / resume all fit `<Form>` cleanly — no programmatic submission needed).
- All user-facing strings via `__()` / `useTrans().t(...)`. English base.
- Frontend route references via Wayfinder.
- Tenant-scoped queries via `tenant()`. Billing controller reads `tenant()->business()`, never injects `Business` from the request.
- Multi-tenancy: `auth.business.subscription` is scoped to the current tenant.
- No Laravel Fortify, no Jetstream, no Zap.
- All datetimes stored UTC; display in business timezone via the existing Carbon/Timezone conventions.
- Migrations extend, do not recreate (Cashier's published migrations are renamed, not re-created).
- Pint clean before session close.
- `afterCommit()` on any queued work touching billing state — none of the five controller actions queue work today (all Stripe calls are synchronous during the request), but any future queued billing job MUST use `afterCommit()`. Documented as a forward-looking convention in D-092's "Consequences".

## 12. Session-close checklist

- `php artisan test tests/Feature tests/Unit --compact` → green, target ~602 passed.
- `vendor/bin/pint --dirty --format agent` → `{"result":"pass"}`.
- `npm run build` → green.
- `php artisan wayfinder:generate` → committed.
- D-089 through D-095 added to `docs/decisions/DECISIONS-FOUNDATIONS.md`.
- `docs/HANDOFF.md` rewritten with MVPC-3 state, decision list, suite count delta.
- `docs/DEPLOYMENT.md` "Billing (Stripe)" section written: account setup, webhook endpoint URL, env var inventory, read-only enforcement behaviour post-cancellation, pre-launch switch-to-live-keys notes.
- `docs/roadmaps/ROADMAP-MVP-COMPLETION.md` §Session 3 checkboxes ticked.
- This plan moved from `docs/plans/` to `docs/archive/plans/`.

## 13. Escape hatch

If implementation surfaces a material complication — e.g., Cashier v15's `$business->newSubscription(...)->checkout(...)` has a different argument shape than assumed and the success/cancel URL plumbing changes, the middleware-based enforcement has a corner case at the precise webhook-to-middleware transition instant (e.g., the webhook fires and marks `ends_at` past, but the middleware is reading a cached Business model), or `Cashier::calculateTaxes()` requires more setup than expected — stop, report the specific problem with a short proposal (rarely a full split; typically a one-paragraph plan patch), and wait for approval.

Currently expected-risk areas, flagged:

- The exact method signature for checkout with automatic tax in Cashier v15 (`checkout(['automatic_tax' => ...])` vs. builder-chain form). Implementation verifies at the call site.
- Whether Cashier v15 needs explicit `->trialDays(0)` on the `newSubscription` builder to skip any subscription-level trial. We want Stripe's subscription trial to be zero because our trial is app-side indefinite (D-089).
- Whether Cashier's published migration lands cleanly as a `businesses`-targeted migration via rename, or requires copy + edit. The rewrite is straightforward if needed.

None of these are expected to block the plan.

**Resubscribe after lapse — verified, not an open risk.** Cashier v15's `subscriptions` table migration (inspected at `laravel/cashier-stripe@15.x:database/migrations/2019_05_03_000002_create_subscriptions_table.php`) carries only `UNIQUE(stripe_id)` and `INDEX(user_id, stripe_status)`. There is **no** `UNIQUE(user_id, type)` constraint. A Business that lapses (old `subscriptions` row has `ends_at` in the past) can create a new `default`-typed subscription without schema conflict. Cashier's `subscription('default')` query resolves through `stripe_status` ordering, not type uniqueness. The resubscribe path is schema-supported as fact.

---

**Status**: draft, awaiting approval. No code will be written before approval.
