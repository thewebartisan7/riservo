# Stripe Connect Express Onboarding (PAYMENTS Session 1)

This plan lives at `docs/PLAN.md` and follows `.claude/references/PLAN.md`. It is a living document. The sections `Progress`, `Surprises & Discoveries`, `Decision Log`, `Review`, and `Outcomes & Retrospective` are kept current as work proceeds.

A novice agent handed only `docs/SPEC.md`, `docs/ROADMAP.md`, `docs/HANDOFF.md`, and this file should be able to deliver Session 1 end-to-end without other context. Terms of art (tenant, GIST invariant, magic link, Wayfinder, Inertia prop, COSS UI, …) are defined inline on first use.


## Purpose / Big Picture

After this session lands, an admin of a riservo Business can click **"Enable online payments"** in dashboard settings, complete Stripe-hosted KYC ("Know Your Customer" identity / business verification, all on `connect.stripe.com`), return to riservo, and see a verified connected account on a new Settings → Payments page. A separate webhook endpoint at `POST /webhooks/stripe-connect` keeps the local row in sync with whatever Stripe authoritatively reports about that account, and disconnect collapses the surface back to the offline-only state.

What you can demonstrate after this session:

1. Admin visits `/dashboard/settings/connected-account`, sees the "Not connected" CTA, clicks **Enable online payments**, gets bounced into Stripe-hosted onboarding (in tests we never call real Stripe — see "Stripe SDK mocking via container binding" in `Context and Orientation`).
2. Stripe redirects back to the same controller's `refresh` action; the page now shows the fresh state pulled from `stripe.accounts.retrieve(...)` — country, default currency, verification chips.
3. A `POST /webhooks/stripe-connect` with a valid signature on an `account.updated` event re-fetches Stripe's authoritative state and updates the local row; a stale (older `created` timestamp) replay does NOT regress the row.
4. Disconnect soft-deletes the `stripe_connected_accounts` row, retains `stripe_account_id` for audit, and forces `Business.payment_mode` back to `offline`.
5. Admins of Business A cannot access Business B's connected-account routes (404 via tenant scoping per locked roadmap decision #45).
6. The Settings → Booking page still hides `online` and `customer_choice` from the `payment_mode` select (the hide-the-options ban is the first checklist item of this session and stays in place until Session 5).

No customer-facing charging, no refunds, no payouts. That all belongs to Sessions 2a/2b/3/4.

A glossary of terms used throughout this plan:

- **Connected account** — a Stripe Express account owned by a Business. The professional is the merchant of record; charges in Sessions 2+ ride on this account via the `Stripe-Account` header. One per Business per locked decision #22.
- **Pending Action ("PA")** — a row in the existing `calendar_pending_actions` table (renamed to `pending_actions` in this session) representing a state requiring admin attention. Today only calendar PAs exist; payment PAs land in 2b/3.
- **Tenant context** — the per-request resolver in `App\Support\TenantContext` (D-063) that returns the active business + role for the authenticated request. Always reach for `tenant()->business()` / `tenant()->role()` in dashboard controllers; never trust a `business_id` from the request.
- **GIST invariant** — the Postgres `EXCLUDE USING GIST` constraint on `bookings` (D-065) that prevents overlapping confirmed/pending bookings on the same provider. Not relevant to this session, but mentioned because Sessions 2+ rely on it.
- **billing.writable middleware** — `EnsureBusinessCanWrite`, alias `billing.writable` (D-090). Non-safe HTTP verbs on a SaaS-lapsed business redirect to the Settings → Billing page. Connected-account mutations sit inside this gate (a SaaS-lapsed business should not be opening new payment surfaces).
- **D-092 cache-dedup pattern** — Stripe webhook idempotency: cache the event id for 24 h after a 2xx response so retries no-op. The MVPC-3 controller inlines this with prefix `stripe:event:`; this session extracts it into a shared helper that takes a prefix parameter (per locked decision #38), the Connect webhook uses prefix `stripe:connect:event:`, the subscription webhook moves to `stripe:subscription:event:`.
- **Outcome-level idempotency** — every webhook handler additionally re-checks DB state at the top so replays / inline-promotion races never double-write (per locked roadmap decision #33). For Session 1 this manifests as the `account.updated` handler re-fetching Stripe state and skipping writes when the local row already matches.
- **Wayfinder** — `laravel/wayfinder`, generates TypeScript callables for Laravel routes / controllers under `resources/js/actions/...` and `resources/js/routes/...`. We regenerate after route additions with `php artisan wayfinder:generate` and import via `@/actions/...`.


## Progress

Planning phase. No code yet. Updated as exec proceeds.

- [ ] Plan drafted and waiting for developer approval (gate one).
- [ ] Hide `online` / `customer_choice` from Settings → Booking; persisted hidden values still read back without error.
- [ ] `config/payments.php` created with three keys; no hardcoded `'CH'` literal in app code.
- [ ] `pending_actions` rename migration + readers audit + `PendingActionType` extended with `payment.*` cases.
- [ ] `stripe_connected_accounts` table + `StripeConnectedAccount` model + `Business::stripeConnectedAccount()` + `Business::canAcceptOnlinePayments()`.
- [ ] `ConnectedAccountController` (show / create / refresh / disconnect) + admin-only routes inside `billing.writable`.
- [ ] Shared dedup helper extracted; subscription webhook switched to `stripe:subscription:event:`; existing MVPC-3 webhook tests still green.
- [ ] `POST /webhooks/stripe-connect` controller with signature verification against `STRIPE_CONNECT_WEBHOOK_SECRET`, handlers for `account.updated`, `account.application.deauthorized`, log-and-200 stub for `charge.dispute.*`.
- [ ] `auth.business.connected_account` Inertia shared prop wired in `HandleInertiaRequests`.
- [ ] `Dashboard/settings/connected-account.tsx` page (four states: not_connected, pending, active, disabled) + dashboard-wide mismatch banner.
- [ ] Settings nav adds the Connected Account item under "Business" group (admin-only).
- [ ] `FakeStripeClient` split into platform-level vs connected-account-level method categories with header asserts.
- [ ] Feature tests pass (full list in `Validation and Acceptance`).
- [ ] Wayfinder regenerated; `npm run build` clean; `vendor/bin/pint --dirty --format agent` clean.
- [ ] `docs/DEPLOYMENT.md` updated with Connect endpoint URL, secret env var, full subscribed-event list.
- [ ] New `D-NNN` decisions promoted into `docs/decisions/DECISIONS-PAYMENTS.md` (start at D-109).
- [ ] `docs/HANDOFF.md` rewritten to reflect shipped Session 1 state.

Use UTC timestamps when ticking boxes (`(2026-04-19 14:32Z)`).


## Surprises & Discoveries

Nothing to report yet.


## Decision Log

Decisions land here during exec. Each entry pairs with a freshly-allocated `D-NNN` in `docs/decisions/DECISIONS-PAYMENTS.md`. Anticipated entries (final IDs decided at promotion time):

- **D-109 — Stripe Connect webhook at `/webhooks/stripe-connect`, signature verified against `STRIPE_CONNECT_WEBHOOK_SECRET`, distinct controller (not a Cashier subclass)**. The Connect event set (`account.*`, `charge.dispute.*`, `checkout.session.*`) does not overlap the platform-subscription set Cashier's base controller routes. The endpoint sits next to `/webhooks/stripe` (subscription) and `/webhooks/google-calendar` under the existing `/webhooks/*` convention (D-091). CSRF is excluded the same way (`bootstrap/app.php`).
- **D-110 — Shared D-092 dedup helper + cache-key prefix per webhook source**. The MVPC-3 inline `DEDUP_PREFIX = 'stripe:event:'` is extracted into a small reusable shape (trait `App\Support\Billing\DedupesStripeWebhookEvents` or a `WebhookEventDeduper` helper class — exec call). The subscription controller switches to `stripe:subscription:event:`; the new Connect controller uses `stripe:connect:event:`. Two namespaces cannot collide. Locked roadmap decision #38.
- **D-111 — `stripe_connected_accounts` is per-business with a unique `business_id` + `stripe_account_id` retained on soft-delete**. Per locked decisions #22 (one connected account per Business) and #36 (retain id after disconnect for audit and for late-webhook refunds in 2b). Soft-delete (not hard-delete) is the disconnect implementation; reconnecting any previously-disconnected business creates a fresh row by undeleting + clearing stale fields, OR by inserting fresh — exec decides at implementation time which is safer.
- **D-112 — `config/payments.php` is the single switch for country gating**. Three keys: `supported_countries`, `default_onboarding_country`, `twint_countries` — MVP value `['CH']` for the lists, `'CH'` for the singleton, all env-driven. No hardcoded `'CH'` literal anywhere in app code, tests, Inertia props, Tailwind utilities. Locked roadmap decision #43.
- **D-113 — `pending_actions` table generalised; `integration_id` nullable; calendar-aware readers add `whereNotNull('integration_id')` (or a `type`-based filter)**. The `CalendarPendingActionController` keeps operating against the renamed table with the calendar-type filter. The dashboard URL / controller rename to a generic `PendingActionController` is post-MVP polish. `PendingActionType` enum gains the three Session 3-consumed cases (`payment.dispute_opened`, `payment.refund_failed`, `payment.cancelled_after_payment`) so 2b / 3 don't need a schema-or-enum session. Locked roadmap decision #44.
- **D-114 — `auth.business.connected_account` Inertia shared prop carries onboarding state**. Shape: `{ status: 'not_connected'|'pending'|'active'|'disabled', country: string|null, can_accept_online_payments: bool, payment_mode_mismatch: bool }`. Same shared-prop pattern as `subscription` (D-089) and `role` / `has_active_provider` (MVPC-4). The dashboard-wide banner reads `payment_mode_mismatch` to decide whether to render. Reading the prop avoids a per-page DB hit and centralises the banner logic in the layout.

Add additional `D-NNN` entries here when other architectural calls crystallise. Promote each into `docs/decisions/DECISIONS-PAYMENTS.md` before close.


## Review

(Empty until codex review runs against the staged diff. One subsection per round, per the format in `.claude/references/PLAN.md`.)


## Outcomes & Retrospective

Filled at session close.


## Context and Orientation

riservo.ch is a Laravel 13 + Inertia.js + React + Postgres SaaS at `docs/SPEC.md`. Businesses register, onboard providers and services, accept bookings on `riservo.ch/{slug}`, and (today) only support **offline payment** ("pay on site" — the customer arrives and pays in person). Riservo's revenue is the SaaS subscription (Cashier, on the `Business` model — D-007, D-089–D-095, MVPC-3 shipped). This session opens the door for **online customer-to-professional payments** via Stripe Connect, where the professional is the merchant of record and 100% of the charge minus Stripe processing fees lands on their connected Stripe account. Riservo takes zero commission (locked roadmap decision #1).

The full PAYMENTS roadmap is at `docs/ROADMAP.md` — six sessions (1, 2a, 2b, 3, 4, 5). This is Session 1, the onboarding-only foundation. Sessions 2a/2b layer on charging at booking; Session 3 adds refunds + disputes; Session 4 surfaces payouts; Session 5 lifts the hide-the-options ban in Settings → Booking.

### Codebase shape relevant to this session

- **Routes** — `routes/web.php`. The dashboard group at line ~111 (`auth + verified + role:admin,staff + onboarded`) wraps everything under `/dashboard`. Inside that, an admin-only group at line ~157 carries the existing Settings controllers under `/dashboard/settings`. Connected Account routes go into this admin-only group. The `billing.writable` middleware (D-090) at line ~126 wraps non-billing dashboard routes; Connected Account mutations sit inside it (a SaaS-lapsed business shouldn't open new payment surfaces). `GET` requests pass through the gate unconditionally regardless. Webhook routes (`/webhooks/stripe`, `/webhooks/google-calendar`) sit OUTSIDE the dashboard group at lines ~267–274; the new `/webhooks/stripe-connect` lands next to them. CSRF exclusions in `bootstrap/app.php` need the new path appended.
- **Billing model** — `app/Models/Business.php` already has the `Billable` trait (Cashier) with `stripe_id`, `pm_type`, `pm_last_four`, `trial_ends_at` as Cashier-managed fillables (these are SaaS-side, unrelated to the Connect flow). Adds `hasOne(StripeConnectedAccount::class)` and `canAcceptOnlinePayments(): bool` here. `payment_mode` is cast to `App\Enums\PaymentMode` (cases: `Offline`, `Online`, `CustomerChoice`).
- **Existing Stripe webhook** — `app/Http/Controllers/Webhooks/StripeWebhookController.php` extends Cashier's `WebhookController` and inlines the D-092 dedup with `private const DEDUP_PREFIX = 'stripe:event:';` (line 21). This session extracts that dedup into a reusable shape and switches the prefix to `stripe:subscription:event:` here; the new Connect controller is **not** a Cashier subclass (Cashier's base routes platform-subscription events that don't apply to connected accounts).
- **Test mocking** — `tests/Support/Billing/FakeStripeClient.php` is the MVPC-3 helper. `Cashier::stripe()` resolves `\Stripe\StripeClient` via `app(StripeClient::class, ['config' => …])`; the helper binds a Mockery double via `app()->bind(StripeClient::class, fn () => $mock)` (D-095). This session splits the helper into two method categories: **platform-level** (no `stripe_account` per-request option asserted absent) for `accounts.create / accounts.retrieve / accountLinks.create / accounts.createLoginLink`, and **connected-account-level** (per-request `stripe_account => $accountId` asserted present) for everything that runs on the connected account (`checkout.sessions.*`, `refunds.*`, etc.) — Session 1 only adds the platform-level methods; Sessions 2+ extend the connected-account-level surface.
- **Existing Pending Actions infrastructure (MVPC-2)** — `app/Models/PendingAction.php` (`protected $table = 'calendar_pending_actions'`), `database/migrations/2026_04_17_100003_create_calendar_pending_actions_table.php`, `app/Http/Controllers/Dashboard/CalendarPendingActionController.php`, `app/Jobs/Calendar/PullCalendarEventsJob.php` (only writer today). Other readers found by grep: `app/Http/Middleware/HandleInertiaRequests.php:82` (count for Inertia prop), `app/Http/Controllers/Dashboard/Settings/CalendarIntegrationController.php:338` (count for the calendar-integration page), `app/Http/Controllers/Dashboard/DashboardController.php:107` (list for the dashboard banner). Tests live under `tests/Feature/Calendar/` (factories use `riservoDeleted()` and `conflict()` states). All readers are calendar-typed today — none of them care about a future payment-typed row, but the rename + nullable FK means we should add a `whereNotNull('integration_id')` (or equivalent type-based filter) on every read so a future payment-typed row never leaks into a calendar-only surface.
- **Inertia shared props** — `HandleInertiaRequests` already shares `auth.role`, `auth.business.subscription`, `auth.has_active_provider`, `calendarPendingActionsCount`, `bookability.unbookableServices`. Connected-account state goes on `auth.business.connected_account`, same shape pattern as `subscription`.
- **Settings nav** — `resources/js/components/settings/settings-nav.tsx` defines admin and staff nav groups. Connected Account is admin-only (commercial decision per the roadmap); add it under the **Business** group next to Profile / Booking / Billing.

### Stripe Connect Express in 60 seconds (so you don't need a blog post)

Stripe **Connect** is Stripe's marketplace API. **Express** is the flavour where Stripe hosts the KYC UX entirely (vs **Standard** where the user already has a Stripe dashboard, or **Custom** where you build the KYC UX yourself). The flow:

1. Server calls `stripe.accounts.create([type: 'express', country: 'CH', capabilities: {card_payments: {requested: true}, transfers: {requested: true}}])` and gets back an `acct_…` id. We persist this id keyed by `business_id`.
2. Server calls `stripe.accountLinks.create([account: 'acct_…', refresh_url: …, return_url: …, type: 'account_onboarding'])` — Stripe returns a one-time `https://connect.stripe.com/setup/…` URL.
3. We redirect the admin to that URL. Stripe walks them through KYC (identity, business info, bank account).
4. Stripe redirects back to `return_url` when done, or to `refresh_url` when the link expires mid-flow (Account Links have a short TTL — minutes).
5. We re-fetch via `stripe.accounts.retrieve('acct_…')`. The response carries `country`, `default_currency`, `charges_enabled`, `payouts_enabled`, `details_submitted`, `requirements.currently_due` (array of strings), `requirements.disabled_reason` (nullable string).
6. Stripe also fires webhooks at `account.updated` whenever the account state changes (e.g., a verification document is approved). We re-fetch on every nudge per locked roadmap decision #34 — payload ordering is not guaranteed, so we trust `accounts.retrieve(...)` not the payload.
7. **Per-account API calls** in later sessions (creating Checkout sessions, issuing refunds) attach a `Stripe-Account: acct_…` header so they execute on the connected account. **Account-level API calls** (creating accounts, retrieving accounts, generating Account Links / Login Links) do NOT carry the header. The FakeStripeClient split enforces this distinction at the test boundary.
8. Disconnect for **Express accounts created via API** is a riservo-side concern only: we soft-delete the local row, retain `stripe_account_id` for audit (locked decision #36's late-webhook refund path in 2b reads it), and force `payment_mode = 'offline'`. Stripe API calls to `oauth.deauthorize` apply only to OAuth-connected Standard accounts, not API-created Express accounts. Re-connecting later creates a fresh `acct_…` for the same business.

### What's locked and not up for re-debate

Read locked roadmap decisions #1–#45 in `docs/ROADMAP.md` § "Cross-cutting decisions locked in this roadmap". The ones that bind Session 1 directly:

- **#5** — Direct charges with `Stripe-Account` header (no `application_fee_amount`, no destination charges). Asserted via the FakeStripeClient split.
- **#13, #20, #21** — Connected-account lifecycle: KYC failure forces `payment_mode = offline`, disconnect forces `payment_mode = offline`, paid bookings stay valid (no paid bookings yet — informational for Session 1).
- **#22** — One connected account per Business; unique constraint on `business_id`.
- **#26, #27** — `payment_mode` enum stays at `{offline, online, customer_choice}`; Sessions 1–4 keep `online` / `customer_choice` hidden from Settings → Booking; Session 5 unhides.
- **#33** — Outcome-level idempotency in every webhook handler.
- **#34** — `account.*` handlers re-fetch via `stripe.accounts.retrieve()`; payload is a nudge, not the source of truth.
- **#36** — Disconnect retains `stripe_account_id` on the soft-deleted row; idempotency-key shape for refunds is `'riservo_refund_'.{booking_refund_uuid}` — relevant in 2b not 1, but the rename + nullable-FK Pending Actions migration here is what enables the `payment.refund_failed` PA in 2b.
- **#38** — Distinct cache-key prefix per webhook namespace; shared D-092 helper takes a prefix parameter; new Connect controller is a fresh class (not a Cashier subclass).
- **#43** — Country gating via `config('payments.supported_countries')`; no hardcoded `'CH'` literal in app code, tests, Inertia props.
- **#44** — `pending_actions` table generalisation in this session; calendar-aware readers add `whereNotNull('integration_id')`. Existing `PendingActionType` enum values are NOT renamed in this roadmap; new `payment.*` cases are added.
- **#45** — Every dashboard controller scopes via `App\Support\TenantContext`; cross-tenant access is a 403 (authz) or 404 (tenant-scoped lookup).


## Plan of Work

The session ships in eight milestones, each independently verifiable. Each milestone leaves the iteration loop (`php artisan test tests/Feature tests/Unit --compact`, Pint, `php artisan wayfinder:generate`, `npm run build`) green.

### Milestone 1 — Hide the `payment_mode` options (do first)

**Goal**: filter `paymentModeItems` in `resources/js/pages/dashboard/settings/booking.tsx` (lines 51–55) to the `offline` option only. Remove the "Online payments require Stripe setup — coming soon." `FieldDescription` (line 208) so we don't promise a date. A persisted `online` / `customer_choice` value (set manually in DB for dogfooding, or seeded) must still render without crashing — the COSS UI Select silently shows the persisted value as the trigger label even when the popup only contains `offline`. The submit handler does NOT need to validate against the hidden values; the existing `BookingSettingsController::update` validation passes through the persisted value as long as the form posts it. Verify the `PaymentMode` enum in `App\Enums\PaymentMode` still accepts all three cases (it does — locked decision #26).

**Acceptance**: visit `/dashboard/settings/booking` as an admin; the `payment_mode` Select popup contains only `Pay on-site`. Manually flip the DB row to `payment_mode = 'online'`; reload the page; the trigger label reads "Pay online" (the persisted value) and the popup still shows only `Pay on-site`. Submitting the form with no change keeps the persisted `online` value (the hidden input round-trips the existing `defaultValue`).

### Milestone 2 — `config/payments.php` and the no-hardcoded-`'CH'` rule

**Goal**: create `config/payments.php` with three keys. Reading `config('payments.supported_countries')` in app code is the single switch that Sessions 2a/4/5 wire their gates against. Session 1 references the config from `ConnectedAccountController::create`'s onboarding-country fallback only; the gates themselves land in later sessions.

```php
return [
    // Locked roadmap decision #43. List of ISO-3166-1 alpha-2 country codes
    // whose connected accounts are allowed to set payment_mode = online or
    // customer_choice. MVP = CH only; the seam is open for IT/DE/FR/AT/LI.
    'supported_countries' => array_filter(array_map(
        'trim',
        explode(',', env('PAYMENTS_SUPPORTED_COUNTRIES', 'CH'))
    )),

    // Locked roadmap decision #43. Country code Stripe Connect uses to
    // create a fresh Express account when Business.address does not resolve
    // to a parseable country. MVP = CH.
    'default_onboarding_country' => env('PAYMENTS_DEFAULT_ONBOARDING_COUNTRY', 'CH'),

    // Locked roadmap decision #43. List of countries on whose Stripe accounts
    // we enable TWINT as a Checkout payment method. MVP = CH; identical to
    // supported_countries today, but Session 2a's payment_method_types
    // branching reads this independently to keep the seam open for non-CH
    // supported_countries that fall back to card-only.
    'twint_countries' => array_filter(array_map(
        'trim',
        explode(',', env('PAYMENTS_TWINT_COUNTRIES', 'CH'))
    )),
];
```

The `ConnectedAccountController::create` action reads `config('payments.default_onboarding_country')` as the country argument to `accounts.create([...])` — `Business.address` is freeform text and not reliably parseable into a country code; deferring to the config default (`'CH'`) gets the user to KYC fastest, where Stripe collects the real country alongside everything else. This is the only consumer of the config in Session 1.

**Acceptance**: a `tests/Unit` config test that asserts the three keys exist with the MVP defaults. A grep test (or a focused lint pass at session close) that no app file under `app/` or `resources/js/` introduces a hardcoded `'CH'` literal in this session. Running `php artisan config:show payments.supported_countries` returns `array (0 => 'CH')`.

### Milestone 3 — `pending_actions` table rename + readers audit + enum extension

**Goal**: generalise the existing MVPC-2 `calendar_pending_actions` table to `pending_actions` so payment Pending Actions in Sessions 2b/3 can write to the same table without a schema session.

**Migration** (`database/migrations/<timestamp>_rename_calendar_pending_actions_to_pending_actions.php`):

1. `Schema::rename('calendar_pending_actions', 'pending_actions');`
2. Alter `integration_id` to nullable. (On Postgres this is a single `ALTER TABLE … ALTER COLUMN … DROP NOT NULL`. Schema Builder: `$table->foreignId('integration_id')->nullable()->change();`. The existing FK constraint with `cascadeOnDelete()` is preserved.)
3. Down: revert the column to `NOT NULL` (rejecting the migration on a database with rows that have a null `integration_id`) and rename the table back. The down path is intentionally lossy on payment-typed rows — none should exist in dev when reverting.

**Model** (`app/Models/PendingAction.php`):

- Update `protected $table = 'pending_actions';`.
- The `integration()` relation already returns a `BelongsTo<CalendarIntegration, ...>`; nullable FK means the relation may resolve to `null` — every reader already handles this (`?->user_id`, `loadMissing(['integration', 'booking'])`). Verify by grep.

**Enum** (`app/Enums/PendingActionType.php`):

- Add three cases for Sessions 2b/3 readers, value strings dotted per the roadmap convention:
  - `case PaymentDisputeOpened = 'payment.dispute_opened';`
  - `case PaymentRefundFailed = 'payment.refund_failed';`
  - `case PaymentCancelledAfterPayment = 'payment.cancelled_after_payment';`
- Existing cases (`riservo_event_deleted_in_google`, `external_booking_conflict`) are NOT renamed (locked decision #44).

**Readers audit**: every site that selects from `pending_actions` and relies on `integration_id` being present must add `->whereNotNull('integration_id')` (or, equivalently, a type-based filter restricting to calendar types). Grep targets:

- `app/Http/Middleware/HandleInertiaRequests.php:82` (`resolveCalendarPendingActionsCount`) — the prop is already named "calendarPendingActionsCount"; restrict the query to calendar types so a future `payment.*` row doesn't inflate the badge. Use the type filter (semantically clearer than the FK filter).
- `app/Http/Controllers/Dashboard/Settings/CalendarIntegrationController.php:338` (`pendingActionsCountForViewer`) — same; calendar-typed only.
- `app/Http/Controllers/Dashboard/DashboardController.php:107` (`pendingActionsForViewer`) — the dashboard banner currently surfaces calendar PAs only. Restrict to calendar types so payment PAs in 2b/3 don't surface here without their own controller (per locked decision #44, payment PAs render per-session in their owning surface — booking-detail panel etc., not in a unified dashboard list).
- `app/Jobs/Calendar/PullCalendarEventsJob.php:308` (`createPendingAction` dedup query) — already filters by `where('integration_id', $integration->id)`, so safe; no change.
- `app/Models/CalendarIntegration.php:87` (`pendingActions()` relation) — uses `'integration_id'` as the FK explicitly, so it naturally returns only calendar-typed rows attached to this integration. No change.

**Tests touched**: `tests/Feature/Calendar/CalendarPendingActionResolutionTest.php`, `tests/Feature/Calendar/PullCalendarEventsJobTest.php`, `tests/Feature/Calendar/CalendarIntegrationConfigureTest.php` — they refer to the table only via the model (no raw SQL on `calendar_pending_actions`), so the rename should not disturb them. Re-run the iteration loop after the rename to confirm.

**Acceptance**: `php artisan test tests/Feature/Calendar --compact` stays green after the rename. A new test in `tests/Feature/Dashboard/PendingActionFiltersTest.php` (or similar) writes a fake `payment.dispute_opened` row directly via Eloquent and asserts: (a) `HandleInertiaRequests`' `calendarPendingActionsCount` prop does NOT count it; (b) `DashboardController::index` does NOT surface it in the dashboard pending-actions list; (c) `PullCalendarEventsJob`'s createPendingAction dedup does NOT collide with it.

### Milestone 4 — Data layer for `stripe_connected_accounts`

**Goal**: persist the connected-account row keyed by `business_id`.

**Migration** (`database/migrations/<timestamp>_create_stripe_connected_accounts_table.php`):

```php
Schema::create('stripe_connected_accounts', function (Blueprint $table) {
    $table->id();
    $table->foreignId('business_id')
        ->constrained()
        ->cascadeOnDelete();
    $table->string('stripe_account_id'); // acct_…; retained on soft-delete per #36
    $table->string('country', 2); // ISO-3166-1 alpha-2; Stripe authoritative
    $table->boolean('charges_enabled')->default(false);
    $table->boolean('payouts_enabled')->default(false);
    $table->boolean('details_submitted')->default(false);
    $table->json('requirements_currently_due')->nullable();
    $table->string('requirements_disabled_reason')->nullable();
    $table->string('default_currency', 3)->nullable(); // ISO-4217; Stripe authoritative
    $table->softDeletes(); // disconnect = soft-delete (D-111 / locked #36)
    $table->timestamps();

    // Locked decision #22 — one connected account per business.
    // Compound unique with deleted_at so a business can re-onboard after
    // disconnect (the soft-deleted row coexists with a fresh active one).
    $table->unique(['business_id', 'deleted_at']);
    $table->unique('stripe_account_id');
});
```

The `(business_id, deleted_at)` compound unique mirrors D-079's pattern for `business_members`: only one *active* (non-trashed) row per business; soft-deleted rows can coexist alongside a future fresh row. Postgres treats `NULL` as distinct in unique constraints, so two soft-deleted rows with `deleted_at = '2026-…'` would both be unique against an active `deleted_at IS NULL` row, but two simultaneously-active rows would violate. This is the desired shape.

**Model** (`app/Models/StripeConnectedAccount.php`):

- Standard Eloquent model, `SoftDeletes`, `HasFactory`.
- `#[Fillable([...])]` attribute (Laravel 13 style — see existing models).
- Casts: `requirements_currently_due => 'array'`, booleans, etc.
- `business(): BelongsTo<Business, ...>`.
- `verificationStatus(): string` returns one of `'pending'` | `'incomplete'` | `'active'` | `'disabled'` (plain strings, not an enum — Stripe's verification states are richer than a local enum can carry; per the roadmap's data layer note). Mapping:
  - `disabled` if `requirements_disabled_reason !== null`.
  - `active` if `charges_enabled && payouts_enabled && details_submitted`.
  - `incomplete` if `details_submitted && (! charges_enabled || ! payouts_enabled)`.
  - `pending` otherwise (account created but onboarding not yet submitted).

**Business model extensions** (`app/Models/Business.php`):

- `stripeConnectedAccount(): HasOne<StripeConnectedAccount, $this>` — naturally returns the active (non-trashed) row by virtue of the model's SoftDeletes scope.
- `canAcceptOnlinePayments(): bool` — returns `$this->stripeConnectedAccount?->charges_enabled === true && $this->stripeConnectedAccount?->payouts_enabled === true && $this->stripeConnectedAccount?->details_submitted === true`. (Equivalent: `$this->stripeConnectedAccount?->verificationStatus() === 'active'`.) Because the relation is `HasOne` and SoftDeletes-scoped, a soft-deleted row never satisfies the helper.

**Factory** (`database/factories/StripeConnectedAccountFactory.php`): standard factory with `pending()` / `active()` / `incomplete()` / `disabled()` states for test convenience.

**Acceptance**: model unit test (`tests/Unit/Models/StripeConnectedAccountTest.php`) covers each `verificationStatus()` branch. `BusinessTest` (or new `tests/Unit/Models/BusinessConnectedAccountTest.php`) covers `canAcceptOnlinePayments()` for each state and asserts a soft-deleted row returns `false`.

### Milestone 5 — Shared dedup helper + Connect webhook controller

**Goal**: extract the D-092 dedup from `StripeWebhookController` and stand up the new `/webhooks/stripe-connect` endpoint with signature verification + handlers for `account.updated`, `account.application.deauthorized`, and a log-and-200 stub for `charge.dispute.*`.

**Step 5a — Extract the dedup helper**:

- Create `app/Support/Billing/DedupesStripeWebhookEvents.php`. Choice between trait and class is the exec agent's call (a trait is the lighter touch when both controllers use it; a class is the cleaner abstraction when more callers materialise — Session 2b/3 don't add new webhook *endpoints*, so the trait is sufficient). The shape is:

```php
namespace App\Support\Billing;

use Closure;
use Illuminate\Support\Facades\Cache;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

trait DedupesStripeWebhookEvents
{
    private const DEDUP_TTL_SECONDS = 86400;

    /**
     * Cache-layer event-id idempotency (D-092). Returns 200 immediately if
     * the event id is already cached; otherwise invokes $process and caches
     * the id only on a 2xx response so transient failures still permit
     * Stripe's retry path.
     *
     * @param  string|null  $eventId  The Stripe event.id from the payload.
     * @param  string  $cachePrefix  Per-source namespace, e.g. 'stripe:subscription:event:'.
     * @param  Closure(): Response  $process  The handler that runs on the first delivery.
     */
    protected function dedupedProcess(?string $eventId, string $cachePrefix, Closure $process): Response
    {
        $cacheKey = $eventId !== null ? $cachePrefix.$eventId : null;

        if ($cacheKey !== null && Cache::has($cacheKey)) {
            return new Response('Webhook already processed.', 200);
        }

        try {
            $response = $process();
        } catch (Throwable $e) {
            throw $e; // leave cache untouched so Stripe retries
        }

        if ($cacheKey !== null && $response->getStatusCode() >= 200 && $response->getStatusCode() < 300) {
            Cache::put($cacheKey, true, self::DEDUP_TTL_SECONDS);
        }

        return $response;
    }
}
```

- Refactor `StripeWebhookController::handleWebhook` to consume the trait with prefix `'stripe:subscription:event:'`. The MVPC-3 `WebhookTest` cache-key assertion needs the new prefix (search for `stripe:event:` in `tests/`); update it.

**Step 5b — Connect webhook controller**:

- `app/Http/Controllers/Webhooks/StripeConnectWebhookController.php` (NOT a Cashier subclass — Cashier's base controller routes platform-subscription events that don't apply here).
- Single entry point `__invoke(Request $request): Response` (or a `handle` action — exec call, but `__invoke` is cleaner for a single-route controller).
- **Signature verification**: validate the `Stripe-Signature` header against `config('services.stripe.connect_webhook_secret')` using `\Stripe\Webhook::constructEvent($payload, $sigHeader, $secret, tolerance: 300)`. On `\Stripe\Exception\SignatureVerificationException`, return 400. On a missing-secret config (defensive), return 500 + log a critical line. **Tests** can short-circuit verification by setting the config secret to `null` and POSTing canonical event JSON, mirroring the MVPC-3 webhook test pattern.
- Pull `eventId = $event->id` and dispatch via the trait helper:

```php
return $this->dedupedProcess($event->id, 'stripe:connect:event:', function () use ($event) {
    return $this->dispatch($event);
});
```

- `dispatch($event)` is a `match` on `$event->type`:
  - `'account.updated'` → `handleAccountUpdated($event)`.
  - `'account.application.deauthorized'` → `handleAccountDeauthorized($event)`.
  - `'charge.dispute.created' | 'charge.dispute.updated' | 'charge.dispute.closed'` → `Log::info('Connect dispute event received pre-Session-3', [...]); return new Response('OK', 200);` (per the roadmap's "log-and-200 no-op handler to avoid Stripe retry storms during the window between roadmap sessions" requirement).
  - default → `return new Response('Webhook unhandled.', 200);` (unknown event types must 200, not 4xx — Stripe will retry on 4xx and we don't want noise; logging is fine).

**Step 5c — `account.updated` handler**:

Per locked decision #34, treat the event payload as a *nudge* and re-fetch authoritative state via `stripe.accounts.retrieve($accountId)`. Then per locked decision #33, guard against no-op writes:

```php
private function handleAccountUpdated(Event $event): Response
{
    $accountId = $event->data->object->id ?? null; // 'acct_…'
    if ($accountId === null) {
        return new Response('Missing account id.', 200); // log + skip
    }

    $row = StripeConnectedAccount::where('stripe_account_id', $accountId)->first();
    if ($row === null) {
        // Account was created against our key but we have no local row;
        // log + 200 so Stripe doesn't retry. This shouldn't happen under
        // normal flow (the create endpoint persists the row before redirecting
        // to onboarding), but a manual Stripe dashboard test can trigger it.
        Log::warning('Connect account.updated received for unknown stripe_account_id', ['id' => $accountId]);
        return new Response('Unknown account.', 200);
    }

    $stripe = app(StripeClient::class, ['config' => ['api_key' => config('cashier.secret')]]);
    $fresh = $stripe->accounts->retrieve($accountId);

    $fields = [
        'country' => $fresh->country,
        'charges_enabled' => (bool) $fresh->charges_enabled,
        'payouts_enabled' => (bool) $fresh->payouts_enabled,
        'details_submitted' => (bool) $fresh->details_submitted,
        'requirements_currently_due' => $fresh->requirements?->currently_due ?? [],
        'requirements_disabled_reason' => $fresh->requirements?->disabled_reason,
        'default_currency' => $fresh->default_currency,
    ];

    // Outcome-level idempotency (locked #33): skip writes if local matches Stripe.
    if ($row->matchesAuthoritativeState($fields)) {
        return new Response('No change.', 200);
    }

    $row->fill($fields)->save();

    // Locked #20: KYC failure forces payment_mode back to offline.
    if (! $row->business->canAcceptOnlinePayments() && $row->business->payment_mode !== PaymentMode::Offline) {
        $row->business->forceFill(['payment_mode' => PaymentMode::Offline->value])->save();
    }

    return new Response('OK', 200);
}
```

`StripeConnectedAccount::matchesAuthoritativeState(array $fields): bool` is a small model helper that returns `true` when every field in `$fields` matches the corresponding model attribute. Order-insensitive comparison on the JSON `requirements_currently_due` array.

**Step 5d — `account.application.deauthorized` handler**:

Per locked decision #36 (retain `stripe_account_id` on soft-delete) and the data layer, this is identical to the controller's `disconnect()` action below:

- Soft-delete the row.
- Force `Business.payment_mode = 'offline'`.
- Return 200.

**Step 5e — Route + CSRF + config**:

- `routes/web.php`: add `Route::post('/webhooks/stripe-connect', StripeConnectWebhookController::class)->name('webhooks.stripe-connect');` next to the existing `/webhooks/stripe` line.
- `bootstrap/app.php`: add `'webhooks/stripe-connect'` to the CSRF `except` list.
- `config/services.php`: add `'stripe' => ['connect_webhook_secret' => env('STRIPE_CONNECT_WEBHOOK_SECRET')]` (extending the existing `services` array). Per locked decision #38 the Connect secret is a separate value from `STRIPE_WEBHOOK_SECRET` (Cashier's subscription webhook).

**Acceptance**: feature tests under `tests/Feature/Payments/StripeConnectWebhookTest.php`:

- `account.updated` for a known account re-fetches via the FakeStripeClient mock and persists the new state.
- `account.updated` with stale state (Stripe returns the same fields the row already carries) is a no-op write — assert the model isn't dirty after handling.
- `account.updated` with `charges_enabled = false` after a previously-active row demotes `payment_mode` to `offline`.
- Stale-payload-but-Stripe-now-disagrees convergence: an older event payload arrives after a newer one; the handler still re-fetches and converges to Stripe-authoritative state. (Implementation: queue two events; the second fires `account.updated` payload that *says* `charges_enabled = true` but Stripe's `retrieve` returns `charges_enabled = false` — assert the row is `false`. This proves the handler trusts `retrieve` not the payload, per locked #34.)
- `account.application.deauthorized` soft-deletes the row, retains `stripe_account_id`, forces `payment_mode = offline`.
- `charge.dispute.created` returns 200 + log line (Session 3 owns the body).
- Unknown event type returns 200.
- Invalid signature returns 400.
- Missing event id (defensive) returns 200 without poisoning the cache.
- Cache-key prefix isolation: a `stripe:subscription:event:evt_X` and a `stripe:connect:event:evt_X` (same id) deduplicate independently — i.e., posting the same event id to both endpoints lets the second through its own dedup gate.

### Milestone 6 — `ConnectedAccountController` + Inertia shared prop + Settings page

**Goal**: stand up the admin-only Settings → Payments page and the four controller actions.

**Routes** (`routes/web.php`, inside the existing admin-only `prefix('dashboard/settings')` group at line ~157, inside `billing.writable`):

```php
Route::get('/connected-account', [ConnectedAccountController::class, 'show'])
    ->name('settings.connected-account');
Route::post('/connected-account', [ConnectedAccountController::class, 'create'])
    ->name('settings.connected-account.create');
Route::get('/connected-account/refresh', [ConnectedAccountController::class, 'refresh'])
    ->name('settings.connected-account.refresh'); // GET — Stripe redirects here
Route::delete('/connected-account', [ConnectedAccountController::class, 'disconnect'])
    ->name('settings.connected-account.disconnect');
```

The `refresh` route is `GET` because Stripe redirects browsers to it (no POST possible from Stripe's hosted onboarding). Reading Stripe state and persisting it on a `GET` is a deliberate exception to "GET shouldn't mutate" — Stripe doesn't give us a choice. `billing.writable` passes `GET` through unconditionally so a SaaS-lapsed business returning from KYC still lands on a working refresh handler.

**Controller** (`app/Http/Controllers/Dashboard/Settings/ConnectedAccountController.php`):

- Constructor injects `StripeClient` via `app(StripeClient::class, ['config' => ['api_key' => config('cashier.secret')]])`. Per D-095 this binding is mocked in tests.
- All actions resolve `$business = tenant()->business()` and authorize admin role via `tenant()->role() === BusinessMemberRole::Admin` (the route group already enforces `role:admin`, but defense-in-depth is cheap and matches D-088's pattern). `abort_unless($business !== null, 404);`. No request-supplied `business_id` is ever trusted (locked decision #45).

`show(Request $request)`:

- Loads `$business->stripeConnectedAccount` (HasOne, SoftDeletes-scoped; returns `null` for not_connected or post-disconnect).
- Returns `Inertia::render('dashboard/settings/connected-account', [...props matching the page contract below...])`.

`create(Request $request)`:

- `abort_unless($business->stripeConnectedAccount === null, 422, 'Already connected.');` (idempotent re-create is a no-op redirect to the existing onboarding link instead of a 422 — exec call; the simplest path is reject and force the user to disconnect first).
- Calls `$stripe->accounts->create([...])` with country = `config('payments.default_onboarding_country')`; persists the row with `details_submitted = false`, `charges_enabled = false`, `payouts_enabled = false`.
- Calls `$stripe->accountLinks->create(['account' => $row->stripe_account_id, 'refresh_url' => route('settings.connected-account.refresh'), 'return_url' => route('settings.connected-account.refresh'), 'type' => 'account_onboarding'])`. Both URLs point at `refresh()` — Stripe triggers `refresh_url` when the link expires mid-flow, `return_url` when the user completes / abandons; both cases need the same "pull current state, decide what to do next" handler.
- Returns `Inertia::location($accountLink->url)` — Stripe-hosted page is outside our app, so we use Inertia's external-redirect helper, which serves a 409 + `X-Inertia-Location` so the React side performs a full-page redirect.

`refresh(Request $request)`:

- Loads `$business->stripeConnectedAccount` (active or just-created).
- `abort_unless($row !== null, 404);`.
- Calls `$stripe->accounts->retrieve($row->stripe_account_id)` — single source of truth per locked decision #34.
- Persists fresh fields.
- If `$account->details_submitted === false` (user bailed mid-KYC), creates a fresh Account Link and returns `Inertia::location($accountLink->url)` — transparent re-entry.
- Otherwise redirects back to `route('settings.connected-account')` with a flash success / "still verifying" banner depending on `verificationStatus()`.

`disconnect(Request $request)`:

- `abort_unless($row !== null, 404);`.
- Soft-deletes the row (locked decision #36: `stripe_account_id` is retained on the row).
- Force `Business.payment_mode = PaymentMode::Offline` (locked decision #21). Use `forceFill` so the change isn't silently dropped by mass-assignment guard.
- (No call to Stripe — Express accounts are API-created, not OAuth-installed; we just stop using the account. Per `Context and Orientation` § "Stripe Connect Express in 60 seconds", `oauth.deauthorize` applies only to Standard accounts.)
- Redirects back to `route('settings.connected-account')` with a flash success.

**Inertia shared prop** (`app/Http/Middleware/HandleInertiaRequests.php`, inside `resolveBusiness`):

Extend the `auth.business` payload:

```php
'connected_account' => $this->resolveConnectedAccount($business),
```

Where `resolveConnectedAccount(Business $business): array` returns:

```php
$row = $business->stripeConnectedAccount;
if ($row === null) {
    return [
        'status' => 'not_connected',
        'country' => null,
        'can_accept_online_payments' => false,
        'payment_mode_mismatch' => $business->payment_mode !== PaymentMode::Offline,
    ];
}
return [
    'status' => $row->verificationStatus(),
    'country' => $row->country,
    'can_accept_online_payments' => $business->canAcceptOnlinePayments(),
    'payment_mode_mismatch' => ! $business->canAcceptOnlinePayments()
        && $business->payment_mode !== PaymentMode::Offline,
];
```

Eager-load `stripeConnectedAccount` via `$business->loadMissing(['subscriptions', 'stripeConnectedAccount'])` so the per-page query budget stays flat.

**Page** (`resources/js/pages/dashboard/settings/connected-account.tsx`):

- Use `SettingsLayout` (eyebrow `Settings · Business`, heading `Online payments`, description matching the four states).
- Imports: `<Form action={create()}>` for the Enable CTA, `<Form action={disconnect()} method="delete">` for disconnect (with a confirmation `<AlertDialog>` from COSS UI), `<Link>` to refresh URL not needed (Stripe drives that). Use Wayfinder route imports per `resources/js/CLAUDE.md`: `import { create, disconnect, refresh } from '@/actions/App/Http/Controllers/Dashboard/Settings/ConnectedAccountController';` after `php artisan wayfinder:generate`.
- Page contract — accept these Inertia props (matches what `show()` returns):

```ts
interface ConnectedAccountPageProps {
    account: null | {
        status: 'pending' | 'incomplete' | 'active' | 'disabled';
        country: string;
        defaultCurrency: string | null;
        chargesEnabled: boolean;
        payoutsEnabled: boolean;
        detailsSubmitted: boolean;
        requirementsCurrentlyDue: string[];
        requirementsDisabledReason: string | null;
        stripeAccountIdLast4: string; // last 4 chars of acct_… for support
    };
    flashError: string | null;
}
```

State rendering, in priority order — first matching state wins:

1. **Not-connected** (`account === null`): big "Enable online payments" CTA card. Explainer copy: onboarding is Stripe-hosted (KYC, identity, business info, bank account); a user admining multiple Businesses must onboard each one independently (locked decision #22 — "one connected account per Business; each Business gets its own Stripe Express dashboard login"); zero riservo commission (locked decision #1 — explicit reassurance). Required-info preview list (Stripe asks for: business legal name, address, tax ID, bank account, beneficial owners, …). Submit button posts to `create()`. **Note**: until Session 5 lifts the hide, the corresponding Settings → Booking option is still disabled — surface a small note "Once verified, you can enable online payments in Booking settings (coming in a later session)" so the admin understands the next step.
2. **Onboarding-incomplete** (`account.status === 'pending' || (account.status === 'incomplete' && requirementsCurrentlyDue.length > 0)`): "Continue Stripe onboarding" CTA — a `<Link>` to `refresh()` (which transparently re-mints an Account Link and redirects). List `requirementsCurrentlyDue` verbatim from Stripe.
3. **Active** (`account.status === 'active'`): summary card — country, default currency, charges-enabled chip, payouts-enabled chip, "Account ID …{last4}" (for support reference). Disconnect button with `<AlertDialog>` confirmation: "Disconnect Stripe? Online payments will be disabled. Existing bookings stay valid; you can re-connect later — Stripe will start a fresh account."
4. **Disabled** (`account.status === 'disabled'`): error banner with `requirementsDisabledReason` verbatim from Stripe (e.g., "rejected.fraud", "rejected.terms_of_service"). "Contact support" CTA links to `mailto:support@riservo.ch` (or wherever support routes today — confirm in code; if absent, log + use a placeholder and flag in Open Questions).

**Accessibility pass** (per the roadmap's checklist line):

- All interactive controls keyboard-reachable (default for `<Form>`, `<Button>`, `<Link>`).
- CTAs carry descriptive `aria-label` ("Enable online payments via Stripe", "Disconnect Stripe Connect").
- Status banners use `role="alert"` (errors) or `role="status"` (success / info).
- Verification chips carry both icon (lucide-react `CheckCircle` / `Clock` / `XCircle`) and text — colour is never the sole signal.

**Dashboard-wide mismatch banner**:

- Lives in `resources/js/layouts/authenticated-layout.tsx` (or wherever existing dashboard banners render — search for the existing "read_only" billing banner from D-090 and follow its placement convention).
- Reads `auth.business.connected_account.payment_mode_mismatch`. When `true`, render: "Online payments aren't ready — your connected account is `{status}`. Bookings will fall back to offline. [Resolve in settings →]" with the link going to `route('settings.connected-account')`.
- Because Sessions 1–4 keep the hide-the-options ban in place, this banner is dormant in normal use. But a DB-seeded `payment_mode = 'online'` value (dogfooding for Session 2a development) would trigger it. Implementing the banner now is cheap and gives Session 2a / 5 the affordance for free.

**Settings nav** (`resources/js/components/settings/settings-nav.tsx`): add Connected Account to the **Business** group (admin-only) between "Booking" and "Billing":

```ts
{ label: 'Connected Account', href: '/dashboard/settings/connected-account' },
```

Staff group is unchanged (this page is admin-only).

**Acceptance** (feature tests under `tests/Feature/Payments/ConnectedAccountControllerTest.php`):

- Admin GETs the page in not-connected state (no row exists) — page renders with `account: null`.
- Admin POSTs `create` — Stripe API mocks fire (`accounts.create`, `accountLinks.create`); a row is persisted with `details_submitted = false`; the redirect target matches the mocked Account Link URL.
- Admin GETs `refresh` after a completed-KYC mock — `accounts.retrieve` fires; row state matches Stripe; redirects to `settings.connected-account` with a success flash; the new page render reads `account.status = 'active'`.
- Admin GETs `refresh` after Stripe reports `details_submitted = false` (user bailed) — the controller mints a fresh Account Link and redirects back into Stripe (no settings page render).
- Admin DELETEs `disconnect` — the row is soft-deleted; `stripe_account_id` is retained; `Business.payment_mode` is forced to `'offline'`.
- Staff cannot reach any of the four endpoints (403 — the existing `role:admin` middleware enforces this; the assertion is defensive against future regressions).
- **Cross-tenant denial (locked decision #45)**: admin of Business A POSTing `create` cannot create a row for Business B; admin of A GETting `refresh` for B's account id cannot retrieve B's state (the controller resolves the account via `tenant()->business()->stripeConnectedAccount`, never via a request-supplied id; the test asserts that no Stripe API call fires for the other business's `acct_…`).
- Multi-business admin: the same User who is admin of A and B can create a connected account on each business independently; the rows are distinct; the unique constraint allows it (only one *active* row per business).
- The Inertia `auth.business.connected_account` shared prop matches expected shape across all four states.

### Milestone 7 — `FakeStripeClient` split + tests

**Goal**: extend `tests/Support/Billing/FakeStripeClient.php` with the platform-level method category (Session 1's surface), encoding the **header-absent** assertion for each. Sessions 2+ extend the connected-account-level category (header present); Session 1 leaves stubs / docblock for that bucket without implementing it.

**Methods Session 1 adds** (all platform-level — `stripe_account` per-request option must be ABSENT):

- `mockAccountCreate(array $params, array $response): self`
- `mockAccountRetrieve(string $accountId, array $response): self`
- `mockAccountLinkCreate(array $params, array $response): self`
- `mockAccountDeauthorize(string $accountId, array $response): self` — only used in tests that simulate the `account.application.deauthorized` webhook; if the controller doesn't call back to Stripe on that event (it doesn't — see Step 5d), this method is unused; omit and add when needed.

The header assertion is enforced by attaching a Mockery argument-matcher to each `shouldReceive` chain. Helper:

```php
private function assertNoStripeAccountHeader(array $opts): bool
{
    return ! array_key_exists('stripe_account', $opts);
}
```

…and on each platform-level mock:

```php
$this->accounts
    ->shouldReceive('create')
    ->withArgs(function ($params, $opts = []) {
        return $this->assertNoStripeAccountHeader((array) $opts);
    })
    ->andReturn(StripeAccount::constructFrom($response));
```

The connected-account-level bucket is **scaffolded in a comment block** in the file so Session 2a's agent doesn't need to re-discover the contract:

```php
/**
 * Session 2+ contract — DO NOT IMPLEMENT IN SESSION 1.
 *
 * Connected-account-level methods (per-request option ['stripe_account' => $accountId]
 * MUST be present and asserted):
 *   - mockCheckoutSessionCreateOnAccount (Session 2a)
 *   - mockCheckoutSessionRetrieveOnAccount (Session 2a)
 *   - mockRefundCreate — also asserts idempotency_key matches 'riservo_refund_'.{uuid} (Session 2b, locked #36)
 *   - mockTaxSettingsRetrieve (Session 4)
 *   - mockBalanceRetrieve (Session 4)
 *   - mockPayoutsList (Session 4)
 *   - mockLoginLinkCreate — platform-level despite the connected-account context (Session 4)
 *
 * A call that crosses categories (e.g., accounts.create with a stripe_account header,
 * or checkout.sessions.create without one) is a test failure by construction.
 */
```

**Acceptance**: at least one Session 1 webhook test exercises the header-absent assertion path — i.e., a deliberate test that injects `['stripe_account' => 'acct_x']` into `accounts.retrieve` causes the Mockery match to fail. (Or, equivalently, a Session-1 test that the existing tests pass when no header is supplied.) This is documentation-by-test: the agent of Session 2a opens the file, sees the contract block and the existing assertions, and continues the pattern.

### Milestone 8 — Wayfinder, Pint, build, DEPLOYMENT.md, decision promotion

**Goal**: green the iteration loop and update operator-facing docs.

- `php artisan wayfinder:generate` — the new `ConnectedAccountController` actions appear under `resources/js/actions/App/Http/Controllers/Dashboard/Settings/ConnectedAccountController.ts`. The page imports them per the snippet in Milestone 6.
- `vendor/bin/pint --dirty --format agent` — clean.
- `npm run build` — clean; Vite manifest updated.
- `php artisan test tests/Feature tests/Unit --compact` — green; baseline 695 tests grows by the Session 1 test count (estimate ~30: 6 webhook + 9 controller + 3 model + 4 cross-tenant + 2 Pending Action filter + 6 misc).
- Update `docs/DEPLOYMENT.md` — append a new subsection under "Billing (Stripe)" titled **"Stripe Connect (customer-to-professional payments)"** that documents:
  - Connect webhook endpoint URL: `https://<your-domain>/webhooks/stripe-connect`.
  - Env var: `STRIPE_CONNECT_WEBHOOK_SECRET` (in addition to the existing `STRIPE_WEBHOOK_SECRET` for the subscription webhook).
  - Subscribed event list to configure in the Stripe Connect dashboard (separate webhook endpoint from the platform-subscription one):
    - `account.updated`
    - `account.application.deauthorized`
    - `charge.dispute.created`
    - `charge.dispute.updated`
    - `charge.dispute.closed`
    - (Sessions 2a/2b/3 will add: `checkout.session.completed`, `checkout.session.expired`, `checkout.session.async_payment_succeeded`, `checkout.session.async_payment_failed`, `payment_intent.payment_failed`, `payment_intent.succeeded`, `charge.refunded`, `charge.refund.updated`, `refund.updated`. Configuring them now is forward-compatible — Session 1's controller log-and-200's any unhandled type — but flagging them as Session-2-only on the operator side avoids confusion.)
  - Test-vs-live key handling (same key set as the platform Stripe account; the Connect endpoint is a separate webhook subscription, not separate keys).
  - `config/payments.php` env vars: `PAYMENTS_SUPPORTED_COUNTRIES`, `PAYMENTS_DEFAULT_ONBOARDING_COUNTRY`, `PAYMENTS_TWINT_COUNTRIES` (all default to `CH`).
- **Promote `D-NNN` decisions** into `docs/decisions/DECISIONS-PAYMENTS.md`. Allocate the next free IDs starting at D-109 per `docs/HANDOFF.md`. Each decision in this PLAN's `Decision Log` becomes a topic-file entry with the standard date / status / context / decision / consequences shape (see `DECISIONS-FOUNDATIONS.md` for canonical examples). Write the entries during exec, not at the very end — discoveries surface decisions.
- **Rewrite `docs/HANDOFF.md`** to reflect Session 1 shipped state: bump the "Feature+Unit suite" baseline; add a "What shipped: PAYMENTS-1" line; update "Next free decision ID" (it'll be D-109 + however many we minted); document the connected-account onboarding path operators can now use; flag the next session (PAYMENTS-2a — Payment at Booking, Happy Path).


## Concrete Steps

This section is the executor's runbook — exact commands to run in the order they should run. Update it as work proceeds.

```bash
# --- Milestone 1 ---
# Edit resources/js/pages/dashboard/settings/booking.tsx:
#   - Filter paymentModeItems to only the offline option.
#   - Remove the FieldDescription "Online payments require Stripe setup — coming soon."
# Verify the page still renders for a Business with payment_mode = 'offline' (default)
# AND for one manually flipped to 'online' (the persisted value should round-trip).
php artisan test tests/Feature/Settings --compact --filter=Booking

# --- Milestone 2 ---
# Create config/payments.php as specified in Plan of Work § Milestone 2.
php artisan config:show payments
# expected:
#   payments.supported_countries  array (0 => 'CH')
#   payments.default_onboarding_country  'CH'
#   payments.twint_countries  array (0 => 'CH')

# --- Milestone 3 ---
php artisan make:migration rename_calendar_pending_actions_to_pending_actions
# Edit the migration: Schema::rename + alter integration_id to nullable.
# Edit app/Models/PendingAction.php: protected $table = 'pending_actions'.
# Edit app/Enums/PendingActionType.php: add the three payment.* cases.
# Edit the four readers identified in Milestone 3 (HandleInertiaRequests,
# CalendarIntegrationController, DashboardController, plus PullCalendarEventsJob
# is already safe by FK).
php artisan migrate
php artisan test tests/Feature/Calendar --compact

# --- Milestone 4 ---
php artisan make:model StripeConnectedAccount -mf
# Flesh out the migration per Plan of Work § Milestone 4. Include softDeletes,
# the (business_id, deleted_at) compound unique, and the unique on stripe_account_id.
# Flesh out the model: SoftDeletes, fillable, casts, relations, verificationStatus().
# Add Business::stripeConnectedAccount() and Business::canAcceptOnlinePayments().
php artisan migrate
php artisan test tests/Unit/Models/StripeConnectedAccountTest.php tests/Unit/Models/BusinessConnectedAccountTest.php --compact

# --- Milestone 5 ---
# Create app/Support/Billing/DedupesStripeWebhookEvents.php (trait per Plan).
# Refactor app/Http/Controllers/Webhooks/StripeWebhookController.php to use it
# with prefix 'stripe:subscription:event:'. Update the WebhookTest cache-key
# assertion to match the new prefix.
# Create app/Http/Controllers/Webhooks/StripeConnectWebhookController.php.
# Add 'stripe' => ['connect_webhook_secret' => env(...)] to config/services.php.
# Add the route to routes/web.php and the CSRF exclusion to bootstrap/app.php.
php artisan test tests/Feature/Billing tests/Feature/Payments/StripeConnectWebhookTest.php --compact

# --- Milestone 6 ---
php artisan make:controller Dashboard/Settings/ConnectedAccountController
# Implement the four actions per Plan of Work § Milestone 6.
# Add the four routes to routes/web.php inside the existing admin-only +
# billing.writable settings group.
# Extend HandleInertiaRequests::resolveBusiness with connected_account.
# Eager-load: $business->loadMissing(['subscriptions', 'stripeConnectedAccount']);
# Create resources/js/pages/dashboard/settings/connected-account.tsx per the
# four-state contract.
# Edit resources/js/components/settings/settings-nav.tsx to add the nav item.
# Add the dashboard-wide mismatch banner to authenticated-layout.tsx.

php artisan wayfinder:generate
php artisan test tests/Feature/Payments/ConnectedAccountControllerTest.php --compact
npm run build

# --- Milestone 7 ---
# Extend tests/Support/Billing/FakeStripeClient.php with the platform-level
# methods (mockAccountCreate, mockAccountRetrieve, mockAccountLinkCreate) plus
# the connected-account-level scaffold comment.
# Re-run any test that uses the fake to confirm no regressions.
php artisan test tests/Feature --compact

# --- Milestone 8 ---
vendor/bin/pint --dirty --format agent
php artisan wayfinder:generate
php artisan test tests/Feature tests/Unit --compact
npm run build

# Edit docs/DEPLOYMENT.md per Plan of Work § Milestone 8.
# Promote D-109..D-NNN into docs/decisions/DECISIONS-PAYMENTS.md.
# Rewrite docs/HANDOFF.md.
git status # everything staged but uncommitted; the developer commits.
```


## Validation and Acceptance

Phrase every acceptance check as observable behavior. The full set of new tests this session ships:

**`tests/Feature/Payments/ConnectedAccountControllerTest.php`** (~9 cases):

- `admin can view the not-connected state`.
- `admin can create a connected account` (asserts `accounts.create` and `accountLinks.create` fired with no `stripe_account` header; row persisted; redirect target matches mocked Account Link URL).
- `admin returning from completed KYC sees the active state` (`refresh` calls `accounts.retrieve`; row reflects `charges_enabled = true` etc.; redirect to `settings.connected-account` with success flash).
- `admin returning from incomplete KYC is bounced back into Stripe` (`refresh` mints a fresh Account Link; Inertia external redirect).
- `admin can disconnect; row is soft-deleted; stripe_account_id retained; payment_mode forced to offline`.
- `staff cannot reach any of the four endpoints` (403).
- `cross-tenant: admin of A cannot create / refresh / disconnect B's connected account` (404 / no Stripe call for B's id).
- `multi-business admin onboards each business independently; rows are distinct`.
- `Inertia auth.business.connected_account prop matches expected shape across all four states`.

**`tests/Feature/Payments/StripeConnectWebhookTest.php`** (~9 cases):

- `account.updated re-fetches via accounts.retrieve and persists fresh state`.
- `account.updated with state matching local row is a no-op (outcome-level idempotency)`.
- `account.updated transitioning charges_enabled true→false demotes payment_mode to offline`.
- `account.updated converges to Stripe-authoritative state when payload contradicts retrieve` (locked #34 hedge — this is the stale-payload test).
- `account.application.deauthorized soft-deletes the row, retains stripe_account_id, forces payment_mode = offline`.
- `charge.dispute.created log-and-200 stub returns 200 + log line` (Session 3 owns body).
- `unknown event type returns 200`.
- `invalid signature returns 400`.
- `cache-key prefix isolation: stripe:subscription:event:evt_X and stripe:connect:event:evt_X dedupe independently`.

**`tests/Unit/Models/StripeConnectedAccountTest.php`** (~5 cases): one per `verificationStatus()` branch (pending, incomplete, active, disabled) plus a `matchesAuthoritativeState()` test.

**`tests/Unit/Models/BusinessConnectedAccountTest.php`** (~3 cases): `canAcceptOnlinePayments` returns false when no row, false when soft-deleted, true when active.

**`tests/Feature/Dashboard/PendingActionFiltersTest.php`** (~3 cases): `HandleInertiaRequests::calendarPendingActionsCount` excludes payment-typed rows; `DashboardController::index` excludes payment-typed rows; calendar PullJob dedup ignores payment-typed rows.

**`tests/Unit/Config/PaymentsConfigTest.php`** (~1 case): the three keys exist with the MVP defaults.

**`tests/Feature/Settings/BookingSettingsTest.php`** (extend existing): an existing test or a new one asserts that the `payment_mode` Select renders only the `offline` option in the popup; persisted `online` value still round-trips.

**End-to-end demonstration** (manual; documented for the developer to repeat):

1. `php artisan migrate:fresh --seed`.
2. `composer run dev` (starts the dev server + queue + Vite).
3. Log in as the seeded admin.
4. Visit `/dashboard/settings/connected-account` → not-connected CTA.
5. Click "Enable online payments" → expect a redirect to `https://connect.stripe.com/setup/…` (in real Stripe test mode; in tests, mocked).
6. Walk through Stripe's hosted KYC.
7. Stripe redirects to `/dashboard/settings/connected-account/refresh` → page renders the active state with country = CH and verification chips green.
8. POST `/webhooks/stripe-connect` with a signed `account.updated` event from Stripe's CLI (`stripe listen --forward-connect-to localhost:8000/webhooks/stripe-connect`) → row updates.
9. Click Disconnect → confirmation dialog → row soft-deleted; page returns to not-connected; the SQL row's `deleted_at` is set, `stripe_account_id` is still populated.
10. Visit `/dashboard/settings/booking` → only `Pay on-site` available in the popup.

**Pre-existing tests must stay green** — the iteration-loop baseline of 695 / 2819 (`docs/HANDOFF.md` 2026-04-19) is the floor. Any regression is a P0 and stops the session close.


## Idempotence and Recovery

- **Migrations**: each is one-way idempotent — re-running `php artisan migrate` is a no-op once they've applied. The `pending_actions` rename includes a working `down()` that reverts the rename and the `nullable` change; if exec needs to re-roll a step, `php artisan migrate:rollback --step=N` handles it.
- **Webhook handlers**: cache-layer dedup (D-092) protects against Stripe replays. Outcome-level guards inside each handler (locked #33) protect against cache flushes / dev replays. Both layers are tested.
- **Connected-account creation**: `create()` rejects with 422 if a row already exists (idempotent retry of "create" is a no-op redirect — exec call). Disconnect + re-onboard flow is supported by the `(business_id, deleted_at)` compound unique.
- **Stripe API failures during `create()`**: if `accountLinks.create` fails after `accounts.create` has succeeded, we have a row in DB with no Account Link to redirect to. The `refresh()` action transparently re-mints an Account Link if `details_submitted = false`, so the retry path is clean — the admin can refresh the page or click the "Continue Stripe onboarding" CTA. Worth a defensive `try / catch` that flashes an error rather than 500'ing.


## Artifacts and Notes

The `account.updated` payload Stripe sends:

```json
{
  "id": "evt_1OabcXYZ123",
  "object": "event",
  "type": "account.updated",
  "account": "acct_1Oxyz...",
  "data": {
    "object": {
      "id": "acct_1Oxyz...",
      "object": "account",
      "country": "CH",
      "default_currency": "chf",
      "charges_enabled": true,
      "payouts_enabled": true,
      "details_submitted": true,
      "requirements": {
        "currently_due": [],
        "disabled_reason": null
      }
    }
  }
}
```

We extract `data.object.id` (the `acct_…`), then call `stripe.accounts.retrieve($accountId)` per locked #34 — the payload's other fields are ignored except for the id. The retrieve response carries the same shape and is the source of truth.

Account Links response:

```json
{
  "object": "account_link",
  "created": 1718000000,
  "expires_at": 1718000300,
  "url": "https://connect.stripe.com/setup/c/acct_1Oxyz/blah"
}
```

`expires_at` is typically a few minutes from `created` — Stripe's onboarding link is short-lived and re-mintable. Hence `refresh_url == return_url` per the roadmap.


## Interfaces and Dependencies

In `app/Models/Business.php`, define:

```php
/** @return HasOne<StripeConnectedAccount, $this> */
public function stripeConnectedAccount(): HasOne
{
    return $this->hasOne(StripeConnectedAccount::class);
}

public function canAcceptOnlinePayments(): bool
{
    $row = $this->stripeConnectedAccount;
    return $row !== null
        && $row->charges_enabled
        && $row->payouts_enabled
        && $row->details_submitted;
}
```

In `app/Models/StripeConnectedAccount.php`, define:

```php
class StripeConnectedAccount extends Model
{
    use HasFactory, SoftDeletes;

    public function business(): BelongsTo { /* … */ }

    public function verificationStatus(): string;        // 'pending'|'incomplete'|'active'|'disabled'
    public function matchesAuthoritativeState(array $fields): bool;
}
```

In `app/Http/Controllers/Dashboard/Settings/ConnectedAccountController.php`, define:

```php
class ConnectedAccountController
{
    public function show(Request $request): Response;
    public function create(Request $request): RedirectResponse;
    public function refresh(Request $request): RedirectResponse;
    public function disconnect(Request $request): RedirectResponse;
}
```

In `app/Support/Billing/DedupesStripeWebhookEvents.php`, define:

```php
trait DedupesStripeWebhookEvents
{
    protected function dedupedProcess(?string $eventId, string $cachePrefix, Closure $process): Response;
}
```

In `app/Http/Controllers/Webhooks/StripeConnectWebhookController.php`, define:

```php
class StripeConnectWebhookController
{
    use DedupesStripeWebhookEvents;
    public function __invoke(Request $request): Response;
}
```

Wayfinder will generate the four `ConnectedAccountController` callables under `resources/js/actions/App/Http/Controllers/Dashboard/Settings/ConnectedAccountController.ts`. The page imports them as `import { create, refresh, disconnect } from '@/actions/App/Http/Controllers/Dashboard/Settings/ConnectedAccountController';`.

The `auth.business.connected_account` Inertia prop shape:

```ts
interface ConnectedAccountProp {
    status: 'not_connected' | 'pending' | 'incomplete' | 'active' | 'disabled';
    country: string | null; // ISO-3166-1 alpha-2; null when not_connected
    can_accept_online_payments: boolean;
    payment_mode_mismatch: boolean;
}
```

Add it to the existing shared-prop type definition under `resources/js/types/index.d.ts` (or wherever `auth.business` is typed today — search for `subscription` in the types file).


## Open Questions

These are product / scope calls I cannot resolve from the live docs alone. Please answer before exec begins.

1. **`Business.address` parsing for onboarding country**. The roadmap says `create()` derives the country from `Business.address` falling back to `config('payments.default_onboarding_country')`. `Business.address` is a freeform string today (no separate country column). I'm proposing we **always use the config default** and never attempt to parse the freeform address — Stripe collects the real country during KYC and the data layer (`country` column on `stripe_connected_accounts`) is overwritten by the first `accounts.retrieve` anyway. Does this match your intent, or do you want a real address-parsing pass (postal-code regex, country-name extraction)?

2. **`ConnectedAccountController` inside `billing.writable` middleware?** I'm proposing **inside** — a SaaS-lapsed business shouldn't be opening new payment surfaces; if their riservo subscription has lapsed they should resubscribe before onboarding Stripe Connect. `GET` requests pass through the gate unconditionally regardless. Confirm? (If you'd rather lapsed admins still be able to disconnect Stripe — say, as part of a cleanup before they cancel — I'd move `disconnect()` outside the gate alongside the billing routes.)

3. **`create()` second-call behaviour**. If a connected account already exists, should `create()` (a) reject with 422 ("Already connected. Disconnect first."), (b) silently re-mint an Account Link and redirect into onboarding (idempotent re-create), or (c) redirect to the show page with a flash? I'm proposing (a) for the cleanest invariant ("one row at a time"); the user clicks Disconnect then Enable to re-onboard. (b) is friendlier but masks bugs. (c) is the no-op-with-info choice.

4. **Support contact for the `disabled` state's "Contact support" CTA**. There's no obvious `mailto:support@…` or support-page link in the codebase today (grep for `support@riservo` returned nothing under `app/` and `resources/`). Should the disabled-state CTA mailto a placeholder (`support@riservo.ch`), link to `/dashboard/settings/billing` (the closest existing surface for "talk to riservo"), or something else? If the answer is "we don't have a support flow yet", I'll inline the placeholder mailto and add a `docs/BACKLOG.md` entry.

5. **Pending Action enum value for the late-webhook path** (informational, not blocking). The roadmap names three new types (`payment.dispute_opened`, `payment.refund_failed`, `payment.cancelled_after_payment`). I'm adding all three to the enum in Session 1 even though only Session 3 writes the dispute one and only Session 2b writes the cancelled-after-payment one. Confirm I should pre-add all three (vs add per-session), or push back if you'd rather see a tighter scope.


## Risks & Notes

- **Cache-key prefix change is a one-time invalidation event for the subscription webhook**. The MVPC-3 `stripe:event:{id}` cache namespace becomes `stripe:subscription:event:{id}` after the refactor; any in-flight Stripe retries with old cache keys lose their dedup. The window is 24 hours (the cache TTL); the worst case is a duplicate-processed event on a redelivery in that window. MVPC-3 handlers are DB-idempotent (they update existing rows; no double-counted analytics yet), so this is acceptable. Documented for posterity.
- **Stripe Connect dashboard webhook subscription is a manual operator step**. The Connect webhook lives in a separate Stripe dashboard panel from the platform-subscription webhook (Stripe → Developers → Webhooks → "Connected accounts" tab vs "Account" tab). The DEPLOYMENT.md update spells this out — easy to miss otherwise.
- **`Inertia::location` for the Stripe redirect is the right call but worth flagging**. Inertia v3's `Inertia::location($url)` returns a 409 with `X-Inertia-Location` so the React side performs a full-page redirect. A regular `redirect()->away($url)` would not work cleanly inside the Inertia POST flow. The `<Form action={create()}>` submit triggers an Inertia POST; the controller responds with `Inertia::location(...)` and the browser navigates. Verified behaviour against Inertia v3 docs.
- **No tests for the mismatch banner today**. The dashboard-wide banner is a layout-level read of `auth.business.connected_account.payment_mode_mismatch`. Until Session 2a / 5 puts a Business in a non-`offline` `payment_mode` legitimately, the banner is dormant and tests-by-inspection. A factory-driven layout test could exercise it; if you want it, add it under `tests/Feature/Dashboard/MismatchBannerTest.php`. I'd default to "skip for now" — rendering a banner is a hard-to-screw-up frontend concern and the underlying prop is tested.
- **Soft-delete vs hard-delete on disconnect**. Locked decision #36 mandates soft-delete (so the cached `stripe_account_id` survives for 2b's late-webhook refund path). Hard-delete would lose the audit trail. The migration's compound-unique `(business_id, deleted_at)` is the seam that lets a re-onboard create a fresh active row alongside the soft-deleted one.
- **Charge dispute webhook stub is functional, not aspirational**. Operators MUST subscribe to `charge.dispute.*` in the Stripe Connect dashboard *now* (Session 1's DEPLOYMENT.md update) so Session 3 lands on a configured pipeline. Without the subscription, Stripe never delivers the events; Session 3's tests pass but production silently misses disputes until the operator re-checks the dashboard.
