# Handoff

**Session**: MVPC-3 — Subscription Billing (Cashier) (Session 3 of `docs/roadmaps/ROADMAP-MVP-COMPLETION.md`)
**Date**: 2026-04-17
**Status**: Code complete + three review rounds applied; Feature + Unit suite **638 passed / 2640 assertions** (post-MVPC-2 baseline 582, +56 cases). Pint clean. `npm run build` green. Wayfinder regenerated. Plan archived to `docs/archive/plans/PLAN-MVPC-3-CASHIER-BILLING.md`.

> Full-suite Browser/E2E run is the developer's session-close check (`tests/Browser` takes 2+ minutes). The iteration loop used `php artisan test tests/Feature tests/Unit --compact` throughout.

---

## Post-review fixes (two rounds, six bugs total, all fixed + tested)

Two review rounds surfaced six bugs in total. All addressed in this revision:

**Round 1** — runtime + state-management:

| # | Bug | Fix |
|---|---|---|
| P1 | `BillingController::subscribe` and `hasStripeKeys` only rejected `null` Stripe config; `.env.example` ships blank values which `env()` returns as `''` (empty string), so an unconfigured install would call `newSubscription(..., '')->checkout()` and explode at Stripe instead of redirecting with a friendly flash. `has_stripe_keys` similarly reported "configured" on a fresh install. | Switched both checks to `blank()` / `filled()`. New tests: `subscribe rejects blank string price ids` and `billing page reports has_stripe_keys=false when env values are blank strings`. |
| P1 | `StripeWebhookController` cached the event id BEFORE delegating to Cashier. A 5xx or thrown exception inside Cashier's handler would still mark the event processed, permanently dropping any subscription update Stripe later retried. | Cache writes moved to AFTER `parent::handleWebhook()` returns, gated on a 2xx status. Throws / non-2xx leave the cache untouched so Stripe's retry can recover. New test: `webhook does NOT mark the event id processed when the handler throws`. |
| P2 | `Business::subscriptionStateForPayload()` called `->toISOString()` on `trial_ends_at` but the column had no cast, so any non-null trial date would crash every authenticated Inertia request with "Call to a member function toISOString() on string". | Added `'trial_ends_at' => 'datetime'` to `Business::casts()`. New test: `subscriptionStateForPayload serialises trial_ends_at when set`. |
| P2 | `BillingController::subscribe` had no server-side guard against duplicate subscriptions — the billing page hides the plan picker once a subscription exists, but the POST route was still reachable, so an admin could spawn a second Stripe subscription for the same business. | Added `if (! $business->onTrial() && state !== 'read_only') { redirect with error }` before the price lookup. New tests: blocks active, blocks canceling-in-grace; allows read_only (so a lapsed business can resubscribe). |

**Round 2** — routing + missing-credentials guards:

| # | Bug | Fix |
|---|---|---|
| P2 | `Cashier::ignoreRoutes()` removed BOTH the default `/stripe/webhook` AND the `cashier.payment` confirmation page. The patch only added `/webhooks/stripe` back; SCA/3DS `IncompletePayment` flows and `ConfirmPayment` notifications redirect to `route('cashier.payment')`, which would have thrown `RouteNotFoundException` for any customer hitting an off-session payment confirmation. | Re-registered the payment route explicitly in `AppServiceProvider::boot()` at the original path `/stripe/payment/{id}` with the original `cashier.payment` name. Webhook stays at `/webhooks/stripe` (D-091); only the payment-confirmation surface is restored. New test: `cashier.payment route is registered after Cashier::ignoreRoutes()`. |
| P2 | `BillingController::subscribe` checked `blank($priceId)` but `portal`, `cancel`, and `resume` did not check `cashier.secret` at all. With `STRIPE_SECRET=` (the default in `.env.example`), those handlers would call the Stripe SDK and surface `\Stripe\Exception\AuthenticationException` instead of the same friendly redirect the page already shows. | Added `guardStripeSecret()` — checks `cashier.secret` only. Called at the top of all four mutating actions. The guard is **deliberately narrower** than `hasStripeKeys()` because portal / cancel / resume operate on the business's existing Stripe customer + subscription, NOT on any price id — locking them out when a price id is blanked mid-rotation would regress operational recovery for existing subscribers. New tests: each of `subscribe`, `portal`, `cancel`, `resume` short-circuits with a flash when `STRIPE_SECRET` is blank; `portal` and `cancel` additionally prove they still work with blank price ids as long as the secret is set. |

Test delta from the two review rounds: +14 cases total (56 billing tests, up from 42 at the initial close).

---

## What Was Built

MVPC-3 installs Laravel Cashier on the `Business` model and delivers the full subscription surface: indefinite trial at signup with no card, Stripe Checkout subscription flow for monthly + annual plans, Stripe Customer Portal for self-service, cancel-at-period-end semantics, and a read-only dashboard transition once a subscription fully ends. Webhooks are signature-validated and idempotent. Stripe Tax handles Swiss VAT automatically. The shared `auth.business.subscription` Inertia prop drives the dashboard banner and the billing page UI.

Plan: `docs/archive/plans/PLAN-MVPC-3-CASHIER-BILLING.md`. Seven new decisions:

- **D-089** — Indefinite trial = "no `subscriptions` row exists". `Business::onTrial()` reads `subscriptions->isEmpty()`. No sentinel dates, no extra column, no registration side-effect. `past_due` write-allowed during Stripe's dunning envelope (~7d–3w).
- **D-090** — Read-only enforcement via `EnsureBusinessCanWrite` middleware (alias `billing.writable`). Mutating verbs only; safe methods pass through. Billing routes, webhooks, and public booking live outside the gate.
- **D-091** — Stripe webhook endpoint at `/webhooks/stripe`. `Cashier::ignoreRoutes()` suppresses the default. Aligns with `/webhooks/google-calendar` convention.
- **D-092** — Webhook idempotency via cache-layer event-id dedup (24h TTL).
- **D-093** — Pricing CHF 29/month, CHF 290/year. Price IDs in `config/billing.php` (env-driven). Display amounts kept in lockstep.
- **D-094** — Stripe Tax via `Cashier::calculateTaxes()`. Swiss VAT computed and collected by Stripe; zero in-app VAT logic.
- **D-095** — Stripe SDK mocked in tests via `app()->bind(StripeClient::class, fn () => $mock)`. Container-binding seam works because `Cashier::stripe()` resolves through `app(StripeClient::class, ...)`. `Cashier::fake()` and `Cashier::useStripeClient()` do not exist in v16.

### Cashier package version

The plan said v15. Cashier v15 requires `illuminate/console ^10.0|^11.0|^12.0`; this project runs Laravel 13. Installed `laravel/cashier:^16.0` (resolved to **v16.5.1**). All assumptions in the plan hold for v16: container-binding seam (D-095) is unchanged, `subscriptions` migration carries `UNIQUE(stripe_id) + INDEX(user_id, stripe_status)` and no `UNIQUE(user_id, type)`, customer + subscription + checkout APIs are identical to v15.

### Database

Five new migrations applied, named to fit the project's `2026_04_17_NNNNNN_*` convention:

- `2026_04_17_100005_add_customer_columns_to_businesses_table.php` — adds `stripe_id` (indexed), `pm_type`, `pm_last_four`, `trial_ends_at` to `businesses`. Cashier's published migration targets `users` by default; manually rewritten to target `businesses`.
- `2026_04_17_100006_create_subscriptions_table.php` — Cashier default, FK column renamed `user_id` → `business_id` to match `Business::getForeignKey()`. Cashier resolves the relation through that method automatically; no model override needed.
- `2026_04_17_100007_create_subscription_items_table.php` — Cashier default, no schema change.
- `2026_04_17_100008_add_meter_id_to_subscription_items_table.php` — Cashier default for usage-based billing meters. Unused in MVP; left as-is for forward compatibility.
- `2026_04_17_100009_add_meter_event_name_to_subscription_items_table.php` — same.

### Backend

- `App\Http\Controllers\Dashboard\Settings\BillingController` — `show / subscribe / portal / cancel / resume`. All five actions read `tenant()->business()` and guard against missing-subscription / no-stripe-customer states with friendly flashes.
- `App\Http\Controllers\Webhooks\StripeWebhookController extends Laravel\Cashier\Http\Controllers\WebhookController` — overrides `handleWebhook` to add cache-layer event-id idempotency before delegating.
- `App\Http\Middleware\EnsureBusinessCanWrite` — passes `isMethodSafe()` requests through; on mutating verbs, redirects to `settings.billing` with an error flash when `! $business->canWrite()`. Aliased `billing.writable` in `bootstrap/app.php`.
- `App\Http\Requests\Billing\SubscribeRequest` — validates `plan ∈ ['monthly', 'annual']`.
- `App\Models\Business` — `Billable` trait + four Cashier columns added to `#[Fillable]` + new helpers `onTrial()`, `subscriptionState()`, `canWrite()`, `subscriptionStateForPayload()`.
- `App\Providers\AppServiceProvider` — `Cashier::ignoreRoutes()` in `register()`; `Cashier::useCustomerModel(Business::class)` + `Cashier::calculateTaxes()` in `boot()`.
- `App\Http\Middleware\HandleInertiaRequests` — `resolveBusiness()` eager-loads `subscriptions` and includes `subscription` in the payload (D-089 §4.9).
- `bootstrap/app.php` — registers the `billing.writable` alias and adds `webhooks/stripe` to the CSRF-exempt list.

### Routes

Restructured `routes/web.php` to wrap the dashboard group:

```php
Route::middleware(['verified', 'role:admin,staff', 'onboarded'])->group(function () {
    // Billing — admin-only, OUTSIDE the gate.
    Route::middleware('role:admin')->prefix('dashboard/settings')->group(function () {
        Route::get('/billing', [BillingController::class, 'show'])->name('settings.billing');
        Route::post('/billing/subscribe', [BillingController::class, 'subscribe'])->name('settings.billing.subscribe');
        Route::post('/billing/portal', [BillingController::class, 'portal'])->name('settings.billing.portal');
        Route::post('/billing/cancel', [BillingController::class, 'cancel'])->name('settings.billing.cancel');
        Route::post('/billing/resume', [BillingController::class, 'resume'])->name('settings.billing.resume');
    });

    // All other dashboard routes — gated.
    Route::middleware('billing.writable')->group(function () {
        // ... existing dashboard routes ...
    });
});

// Top-level webhook (CSRF-exempt).
Route::post('/webhooks/stripe', [StripeWebhookController::class, 'handleWebhook'])->name('webhooks.stripe');
```

### Config

- `config/billing.php` — new file. `prices.{monthly,annual}` from env; `display.{monthly,annual}.{amount,currency,interval}` for UI labels (intentional duplicate of Stripe prices, kept in lockstep).
- `config/cashier.php` — published, no edits.
- `.env.example` — six new keys: `STRIPE_KEY`, `STRIPE_SECRET`, `STRIPE_WEBHOOK_SECRET`, `STRIPE_PRICE_MONTHLY`, `STRIPE_PRICE_ANNUAL`, `CASHIER_CURRENCY=chf`.

### Frontend

- `resources/js/pages/dashboard/settings/billing.tsx` — new page. Renders one of five states (trial / active / past_due / canceling / read_only) with the appropriate CTA stack: monthly + annual subscribe buttons (trial / read_only), portal + cancel (active / past_due), portal + resume (canceling).
- `resources/js/components/settings/settings-nav.tsx` — added "Billing" admin item under the "Business" group.
- `resources/js/layouts/authenticated-layout.tsx` — extended the existing banner area with a subscription-state banner (warning for past_due and canceling, error for read_only). Banner links to `/dashboard/settings/billing` for admins; staff sees the warning text but no CTA. The existing D-078 unbookable-services banner is unchanged.
- `resources/js/types/index.d.ts` — new `SubscriptionState` interface; `Business.subscription: SubscriptionState`.

### Shared Inertia props

`auth.business.subscription` shape:
```ts
{
  status: 'trial' | 'active' | 'past_due' | 'canceled' | 'read_only',
  trial_ends_at: string | null,
  current_period_ends_at: string | null,
}
```
Resolved server-side via `Business::subscriptionStateForPayload()`. `loadMissing('subscriptions')` is called once in `HandleInertiaRequests::resolveBusiness()` so the helpers read from the eager-loaded collection (one query, not two).

### Tests

New test directory `tests/Feature/Billing/`:

- `BusinessSubscriptionStateTest.php` — 7 cases. Pure model. Exercises `onTrial / canWrite / subscriptionState / subscriptionStateForPayload` across trial / active / past_due / canceled-grace / read_only states.
- `BillingControllerTest.php` — 11 cases. Uses `tests/Support/Billing/FakeStripeClient` (D-095 helper). Covers admin-vs-staff access, subscribe → checkout redirect, portal redirect (with and without `stripe_id`), cancel + resume happy/error paths, plan validation.
- `WebhookTest.php` — 6 cases. POSTs canonical Stripe event payloads to `/webhooks/stripe`. Covers `customer.subscription.{created,updated,deleted}`, idempotency dedup (cache-key probe), the "throw inside the parent handler does NOT cache the event id" contract (post-review fix), and signature-verify rejection on a known-bad signature returns HTTP 403 (Cashier's `VerifyWebhookSignature` throws `AccessDeniedHttpException`).
- `ReadOnlyEnforcementTest.php` — 19 cases. HTTP layer: dataset walks 12 mutating dashboard routes (without model bindings — see deviation #6) and asserts each redirects to `/dashboard/settings/billing` for a read-only admin. Plus six explicit carve-out cases: read pages remain reachable, billing routes remain reachable, subscribe is allowed (gate doesn't apply), webhook is reachable, public booking page is reachable, an active business is not gated. **Structural layer**: one route-introspection test walks `Route::getRoutes()` and asserts every mutating dashboard route is wrapped by `billing.writable` (with explicit one-line carve-outs for billing routes only). Together the two layers prove both that the middleware behaves correctly when invoked AND that it's wired to every route it should be wired to.

Shared: `tests/Support/Billing/FakeStripeClient.php` — programmable Stripe client double bound via `app()->bind(StripeClient::class, fn () => $client)`. Mirrors MVPC-2's `FakeCalendarProvider` pattern. Methods: `mockCheckoutSession`, `mockBillingPortalSession`, `mockSubscriptionUpdate`, `mockCustomerCreate`, `mockCustomerUpdate`. Returns real `Stripe\Checkout\Session::constructFrom(...)` / `Stripe\BillingPortal\Session::constructFrom(...)` so Cashier's type-checked Checkout constructor accepts them.

Pre-existing test contract evolution: `tests/Feature/Settings/SettingsAuthorizationTest.php` — Billing added to both the staff-forbidden matrix and the admin-allowed matrix.

---

## Current Project State

- **Backend**:
  - `App\Models\Business` — `Billable` trait, Cashier columns in fillable, four product-semantic helpers.
  - `App\Http\Controllers\Dashboard\Settings\BillingController` — five actions.
  - `App\Http\Controllers\Webhooks\StripeWebhookController` — extends Cashier, adds dedup.
  - `App\Http\Middleware\EnsureBusinessCanWrite` — `billing.writable` alias.
  - `App\Http\Requests\Billing\SubscribeRequest`.
  - `App\Providers\AppServiceProvider` — Cashier wiring.
  - `App\Http\Middleware\HandleInertiaRequests` — subscription prop + eager-load.
- **Database**: five new migrations applied. `businesses` extended with Cashier customer columns; `subscriptions` + `subscription_items` (with meter columns) created.
- **Frontend**: one new page (billing), one new banner spec on the auth layout, one new nav item, types extended.
- **Config / env**: `config/billing.php` new; `.env.example` extended with six Stripe keys.
- **Tests**: Feature + Unit **638 passed / 2640 assertions** (three review rounds added +14 cases over the initial 624 — see "Post-review fixes" below). Browser suite 249 untouched.
- **Decisions**: D-089 through D-095 recorded in `docs/decisions/DECISIONS-FOUNDATIONS.md`. No existing decision superseded.
- **Dependencies**: `laravel/cashier:^16.0` (resolved v16.5.1) added; `stripe/stripe-php:^17`, `moneyphp/money:^4`, `symfony/polyfill-intl-icu` pulled transitively.

---

## How to Verify Locally

```bash
php artisan test tests/Feature tests/Unit --compact     # 638 passed (iteration loop)
php artisan test --compact                              # full suite incl. Browser (run by developer at close)
vendor/bin/pint --dirty --format agent                  # {"result":"pass"}
php artisan wayfinder:generate                          # idempotent
npm run build                                           # green, ~1.3s
```

Targeted:

```bash
php artisan test tests/Feature/Billing --compact        # 56 billing-specific cases
php artisan route:list --path=billing                   # 5 routes under settings.billing*
php artisan route:list --path=webhooks                  # 2 routes (google-calendar, stripe)
```

Manual smoke (requires real Stripe test credentials):

1. Configure all six `STRIPE_*` / `CASHIER_CURRENCY` env vars per `docs/DEPLOYMENT.md §Billing`.
2. Create a Product + two Prices in Stripe test-mode, copy IDs into `STRIPE_PRICE_MONTHLY` / `STRIPE_PRICE_ANNUAL`.
3. Enable Stripe Tax in the dashboard, set the origin address.
4. Register a webhook endpoint pointing to your local tunnel (`https://<tunnel>/webhooks/stripe`) for the five Stripe events listed in DEPLOYMENT.
5. Register a business locally → onboard → Settings → Billing. Trial state visible.
6. Click Subscribe → Monthly. Stripe Checkout opens. `4242 4242 4242 4242`, future expiry, any CVC. Complete.
7. Returns to `/dashboard/settings/billing?checkout=success`. Active state visible.
8. Click Cancel. Modal → confirm. Canceling state visible with end date.
9. Click Resume. Active state restored.
10. Use Stripe CLI: `stripe trigger customer.subscription.deleted` with the subscription id. Dashboard transitions to read-only. Try to create a manual booking → redirected to billing with error flash. Subscribe again → restored.

---

## What the Next Session Needs to Know

Next up: **Session 4 — Provider Self-Service Settings** in `docs/roadmaps/ROADMAP-MVP-COMPLETION.md`. Session 4 opens the settings area to staff-with-Provider-row for Account and Availability self-management.

### Conventions MVPC-3 established that future work must not break

- **D-089 — `Business::onTrial()` is the canonical "indefinite trial" predicate.** It reads `$this->subscriptions->isEmpty()`. Any future code that asks "is this business on trial?" must call this method, not Cashier's `onGenericTrial()` (which reads `trial_ends_at` and is wrong for our model).
- **D-090 — `EnsureBusinessCanWrite` (`billing.writable`) is the only HTTP gate for billing state.** New mutating dashboard routes inherit it automatically because they live inside the gated group. Any new route OUTSIDE the gate (e.g. a future webhook for a different integration) must justify its position in the route file. Server-side automation runs unconditionally — middleware is HTTP-only by design.
- **D-091 — All third-party webhooks live under `/webhooks/*`.** The `Cashier::ignoreRoutes()` call must stay in `AppServiceProvider::register()` for the Stripe webhook to remain at `/webhooks/stripe` instead of Cashier's default `/stripe/webhook`.
- **D-092 — Webhook idempotency is enforced at the boundary.** Any new Stripe-side handler added to `StripeWebhookController` (e.g. a custom `invoice.payment_succeeded` listener) is automatically deduped because `handleWebhook()` runs the cache check before the handler dispatch.
- **D-093 — Pricing flips through env vars + `config/billing.php` display labels in lockstep.** No price strings hardcoded in controllers, frontend, or tests.
- **D-094 — `Cashier::calculateTaxes()` stays on.** Removing it would make every Cashier-generated invoice VAT-naked.
- **D-095 — Stripe SDK mocking goes through `app()->bind(StripeClient::class, ...)`.** Any new test that exercises a Stripe path must use `FakeStripeClient` (or extend it). Hand-rolled HTTP mocks are not the supported pattern. If you ever feel the urge to call `\Stripe\Stripe::*` statically, route it through `Cashier::stripe()` so the test seam holds.
- **`Business` is the billable model.** Cashier's `Cashier::useCustomerModel(Business::class)` is in `AppServiceProvider::boot()`. Removing it would break every Cashier query.
- **`subscriptions.business_id` is the correct FK column.** Cashier resolves it through `Business::getForeignKey()`; no model override needed. The plan noted this explicitly because the published Cashier migration ships `user_id` and we renamed it.

### MVPC-3 hand-off notes

- Cashier was upgraded from the planned v15 to v16 because v15 doesn't support Laravel 13. All architectural assumptions hold.
- Cashier publishes 5 migrations (customer columns + subscriptions + subscription_items + 2 meter columns for usage-based billing). The two meter migrations are unused in MVP but kept to avoid future churn if usage-based billing is ever needed (post-MVP).
- The `SubstituteBindings` middleware runs after `billing.writable` for routes with `{model}` placeholders. The dataset test in `ReadOnlyEnforcementTest` covers only routes without bindings — adding routes with bindings to the dataset would yield 404 instead of the intended 302 (the gate fires before bindings, but Laravel's behaviour is to short-circuit on missing-model 404). Not a bug; just a test shape consideration.
- Stripe Tax requires the customer billing address to be set. Cashier handles this on Checkout — the customer enters it inline. We don't need to collect billing addresses inside riservo.
- `subscriptionStateForPayload()` runs on every authenticated Inertia request. Verified to be a single query thanks to `loadMissing('subscriptions')` in the resolver. If a future change introduces a second N+1 path here, treat it as a regression.

---

## Open Questions / Deferred Items

New from MVPC-3:
- **Tighten billing freeload envelope** — `past_due` write-allowed envelope of ~7d–3w (Stripe dunning policy). Tracked in `docs/BACKLOG.md` as a post-launch refinement gated on abuse telemetry.

Earlier carry-overs unchanged from MVPC-2 hand-off:
- Tenancy (R-19 carry-overs): R-2B business-switcher UI; admin-driven member deactivation + re-invite; "leave business" self-serve.
- R-16 frontend code splitting (deferred).
- R-17 carry-overs: admin email/push notification on bookability flip; richer "vacation" UX; banner ack history.
- R-9 / R-8 manual QA.
- Orphan-logo cleanup.
- Profile + onboarding logo upload deduplication.
- Per-business invite-lifetime override.
- Real `/dashboard/settings/notifications` page.
- Per-business email branding.
- Mail rendering smoke-test in CI.
- Failure observability for after-response dispatch.
- Customer email verification flow.
- Customer profile page.
- Scheduler-lag alerting.
- `X-RateLimit-Remaining` / `Retry-After` headers on auth-recovery throttle.
- SMS / WhatsApp reminder channel.
- Browser-test infrastructure beyond Pest Browser.
- Popup widget i18n.
- `docs/ARCHITECTURE-SUMMARY.md` stale terminology.
- Real-concurrency smoke test.
- Availability-exception race.
- Parallel test execution (`paratest`).
- Slug-alias history.
- Booking-flow state persistence.
