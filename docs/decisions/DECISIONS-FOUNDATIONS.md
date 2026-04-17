# Foundations Decisions

This file contains live cross-cutting architectural decisions about platform defaults, routing, tenancy, and broad project conventions.

---

### D-001 — No Laravel Fortify or Jetstream
- **Date**: 2025-01-01
- **Status**: accepted
- **Context**: Laravel Fortify and Jetstream add opinionated abstraction layers over authentication that complicate customisation and make the codebase harder to reason about for agents and developers alike.
- **Decision**: Authentication is implemented with custom Laravel controllers, middleware, and `URL::temporarySignedRoute()` for magic links. No Fortify, no Jetstream.
- **Consequences**: More code to write initially, but full control over every auth flow. No risk of package upgrades breaking customised auth behaviour.

---

### D-002 — No Laravel Zap for scheduling
- **Date**: 2025-01-01
- **Status**: accepted
- **Context**: Laravel Zap was evaluated for the scheduling engine but introduced friction during testing and had limitations on edge cases. The scheduling requirements (recurring rules + exceptions at two levels + buffers) are well-defined enough to implement cleanly in-house.
- **Decision**: Scheduling engine implemented as custom `AvailabilityService` and `SlotGeneratorService` using Laravel + Carbon.
- **Consequences**: More initial implementation work, but full control over slot logic, testability, and no third-party dependency risk.

---

### D-003 — Single domain path-based routing for MVP
- **Date**: 2025-01-01
- **Status**: accepted
- **Context**: Subdomain routing (`{slug}.riservo.ch`) requires wildcard SSL, more complex local dev setup, and potentially a multi-tenancy package.
- **Decision**: MVP uses catch-all path-based routing (`riservo.ch/{slug}`). Each Business has a unique slug set at registration. A reserved slug blocklist prevents conflicts with system routes.
- **Consequences**: Simpler deployment and dev setup. Migration to subdomain routing is possible post-MVP without data model changes (slug is the stable identifier throughout).
- **See also**: Reserved slug blocklist must be maintained as new system routes are added.

---

### D-007 — Laravel Cashier (Stripe) on the Business model
- **Date**: 2025-01-01
- **Status**: accepted
- **Context**: riservo.ch is a B2B SaaS — the billing subject is the Business, not individual users. Laravel Spark was evaluated but is too opinionated for a project with a custom data model and React/Inertia frontend.
- **Decision**: `laravel/cashier-stripe` installed and the `Billable` trait applied to the `Business` model. Billing portal is custom-built in React. No Laravel Spark.
- **Consequences**: Full control over billing UI and plan logic. Stripe Connect (for online customer payments) deferred to v2.

---

### D-008 — English as base language, `__()` from day one
- **Date**: 2025-01-01
- **Status**: accepted
- **Context**: riservo.ch targets the Swiss market (IT/DE/FR) but translating during feature development slows velocity. Retrofitting `__()` calls after the fact is painful and error-prone.
- **Decision**: All user-facing strings use the `__()` helper from the first line of code. English is the base language key. Translation files for Italian, German, and French are completed pre-launch.
- **Consequences**: Slightly more verbose string writing during development, but zero translation retrofit work.

---

### D-009 — File storage via Laravel Storage facade
- **Date**: 2025-01-01
- **Status**: accepted
- **Context**: S3 or object storage adds operational complexity early on.
- **Decision**: All file operations (business logo, collaborator avatars) use Laravel's `Storage` facade with the `local` driver in dev and the `public` disk in production. No direct filesystem calls anywhere in the codebase.
- **Consequences**: Migration between storage backends requires only a driver config change — no code changes. Production host changed from Hostpoint to Laravel Cloud per D-065; the `Storage` facade abstraction enables the swap to Laravel Cloud's managed object storage with no code change.

---

### D-012 — No multi-tenancy package for MVP
- **Date**: 2025-01-01
- **Status**: accepted
- **Context**: `tenancy-for-laravel` and similar packages add significant complexity to the development workflow, local setup, and deployment. Business data isolation can be achieved reliably with `business_id` scoping on all queries.
- **Decision**: No multi-tenancy package. All business-owned data is scoped via `business_id`. Global scopes or policies enforce this at the model level.
- **Consequences**: Developers and agents must be disciplined about scoping all queries. A security review pre-launch should verify no cross-business data leakage is possible.

---

### D-013 — Catch-all `/{slug}` routing (no `/b/` prefix)
- **Date**: 2026-04-12
- **Status**: accepted
- **Context**: SPEC.md had inconsistent URL prefixes (`/b/{slug}` in some places, `/{slug}` in others). A `/b/` prefix is safer but less clean for customers.
- **Decision**: Public booking pages use `riservo.ch/{slug}` — a catch-all route registered last. A reserved slug blocklist prevents conflicts with system routes.
- **Consequences**: The reserved slug blocklist must be comprehensive and maintained as new system routes are added.

---

### D-025 — Enum fields stored as strings for SQLite compatibility
- **Date**: 2026-04-12
- **Status**: accepted
- **Context**: MySQL/MariaDB native ENUM types are not supported by SQLite. The project uses SQLite for development.
- **Decision**: All enum-backed fields (status, source, role, type, etc.) are stored as `string` columns. PHP string-backed enums with Eloquent casts enforce valid values at the application layer.
- **Consequences**: Cross-database compatible. No migration issues between SQLite (dev) and MariaDB (prod). Validation at the database level is sacrificed for portability.

---

### D-026 — Skip Blueprint, use artisan make commands
- **Date**: 2026-04-12
- **Status**: accepted
- **Supersedes**: D-011
- **Context**: D-011 proposed using Laravel Blueprint for initial data layer scaffolding. However, Blueprint generates old-convention code (`$fillable` arrays) incompatible with Laravel 13's `#[Fillable]` attributes, cannot express custom FK names like `collaborator_id`, and requires extensive manual adjustment.
- **Decision**: Session 2 uses `php artisan make:model`, `make:migration`, and `make:factory` instead of Blueprint. The `draft.yaml` task from the roadmap is replaced by manually written, convention-following code.
- **Consequences**: No Blueprint dependency. All generated code follows Laravel 13 conventions from the start. Slightly more upfront work, but no post-generation cleanup.

---

### D-065 — Postgres as the single database engine; Laravel Cloud as production host
- **Date**: 2026-04-16
- **Status**: accepted
- **Context**: R-4 (booking race condition) required a correctness-grade
  solution for preventing overlapping bookings on the same provider. Postgres
  exposes `EXCLUDE USING GIST` exclusion constraints with the `btree_gist`
  extension — a declarative, transaction-scoped, atomic invariant that
  expresses exactly "no two rows overlap on (provider, effective_interval)
  where status in (pending, confirmed)". MariaDB / MySQL have no equivalent;
  every workaround is application-level lock management (row locks, advisory
  locks, triggers). SQLite has no `SELECT FOR UPDATE` semantics and only
  connection-level locking. Moving to Postgres in production required
  choosing a new host, since Hostpoint shared hosting does not offer
  Postgres.
- **Decision**: Postgres 16 is the only supported database engine across
  development, test, and production. SQLite is dropped as a dev target.
  MariaDB is dropped as a production target. Production hosting moves from
  Hostpoint to **Laravel Cloud** — first-party host with managed Postgres,
  managed queue workers, managed scheduler, and a zero-friction Laravel
  deploy story.
- **Consequences**:
  - The R-4B race guard uses `EXCLUDE USING GIST` with `btree_gist`, giving a
    declarative DB-level invariant against overlapping bookings.
  - `config/database.php` default is `pgsql`. `phpunit.xml` runs tests on a
    dedicated local Postgres database, not `:memory:`. Test runs are slightly
    slower than SQLite-in-memory but stay inside the "fast feedback" budget.
  - D-025 (enums stored as strings) remains in effect on its own merits —
    portable schema, no DB-enum ALTER churn. The SQLite rationale in D-025
    is obsolete but the decision outcome is unchanged.
  - D-009 (file storage via Laravel Storage facade) remains in effect. The
    production storage target changes from Hostpoint public-disk file
    storage to Laravel Cloud's object storage driver, configured at deploy
    time. No code change — the `Storage` facade abstraction is precisely
    what enables this swap.
  - `docs/DEPLOYMENT.md` is rewritten around Laravel Cloud. Hostpoint
    Supervisor / cron / SMTP sections are removed. Queue workers and
    scheduler are managed by the platform.
  - Every reference to SQLite, MariaDB, MySQL, and Hostpoint across docs,
    env files, SPEC, architecture summary, and CLAUDE guides is removed.
  - Laravel Cashier (not currently installed, noted aspirational in SPEC)
    supports Postgres natively whenever it is adopted.
- **Supersedes**: none. D-009 and D-025 remain accepted; their consequences
  are extended in this decision rather than replaced.

---

### D-076 — Canonical storage URL helper is `Storage::disk('public')->url(...)`
- **Date**: 2026-04-16
- **Status**: accepted
- **Context**: Pre-R-15, controllers split 10/5 between
  `Storage::disk('public')->url($path)` (settings, onboarding,
  dashboard-shell layer) and `asset('storage/'.$path)` (booking +
  calendar layer). Both helpers produce the same URL on a default
  Laravel install with the public symlink in place, so the drift
  was invisible in local dev and CI. Per D-009 + D-065, production
  uses Laravel Cloud's managed object storage — not a public-symlink
  layout. `asset('storage/...')` would generate a wrong URL there;
  `Storage::disk('public')->url(...)` consults the disk config and
  returns the correct CDN/S3 URL. REVIEW-1 §#16 surfaced the drift;
  R-15 requires a single canonical helper across all controllers.
- **Decision**: All controllers and Inertia prop-builders use
  `Storage::disk('public')->url($path)` for any URL pointing to a
  user-uploaded file (logos, avatars). The `asset('storage/...')`
  pattern is removed from the codebase. Future contributors writing
  new controllers default to the canonical helper.
- **Consequences**:
  - On Laravel Cloud, file URLs resolve through the configured object-
    storage driver without code changes — the `Storage` facade
    abstraction is exactly what D-009 was set up to enable.
  - 5 call sites migrated:
    - `app/Http/Controllers/Dashboard/BookingController.php:126`
    - `app/Http/Controllers/Dashboard/CalendarController.php:93, 107`
    - `app/Http/Controllers/Booking/PublicBookingController.php:70, 118`
  - 10 call sites already canonical — unchanged.
  - No migration of previously-uploaded files needed; the helper
    only changes URL generation, not file paths.
  - Pre-launch test pass exercises every URL via the regression suite
    (logo upload + render in onboarding step-1, profile, welcome,
    public booking page; avatar render in calendar, manual booking,
    staff list).
- **Rejected alternative**:
  - *`asset('storage/...')` everywhere* — would require keeping the
    public symlink alive on Laravel Cloud, which contradicts the
    object-storage migration path D-065 set up. Net regression on
    deploy-time flexibility.
  - *Per-feature freedom* — leave both helpers in use, document
    which to use when. Adds rule-following overhead for a one-line
    convention; no upside.

---

### D-089 — Indefinite trial represented as "no subscription row exists"

- **Date**: 2026-04-17
- **Status**: accepted
- **Context**: Locked roadmap decision #10 in `docs/roadmaps/ROADMAP-MVP-COMPLETION.md` requires indefinite trial with no card at signup. Cashier's default `onGenericTrial()` reads `trial_ends_at` and returns false for null — that's the wrong shape for "trial forever". Three options were evaluated (null + convention, far-future sentinel, boolean column).
- **Decision**: A product-semantic helper `Business::onTrial(): bool` returns `true` iff no `subscriptions` row exists. `trial_ends_at` stays null and unused in MVP. `Business::subscriptionState()` and `Business::canWrite()` derive the dashboard-facing state from Cashier's standard predicates (`onGracePeriod`, `pastDue`, `ended`, `canceled`). No observer, no factory change, no RegisterController hook: a brand-new business naturally has zero subscriptions and is therefore on indefinite trial without any side-effect at registration.
- **Consequences**:
  - No sentinel magic dates — `trial_ends_at` is interpreted by Cashier as a Stripe-side timestamp only, never as a riservo trial signal.
  - No extra column. Same information is derivable from `$business->subscriptions()->exists()`, already a Cashier-native query.
  - Future trial-length-cap policy is a one-line addition in RegisterController (`$business->trial_ends_at = now()->addDays(N)`) plus a one-line update in `onTrial()`. No migration.
  - `onGenericTrial()` (Cashier) remains on the model via the trait but is not consulted by product code.
  - **Freeload envelope for `past_due` (write-allowed)**: `past_due` is treated as a recoverable state — a salon whose card fails gets Stripe's default dunning window (~7 days / 4 retries; account-configurable up to ~3 weeks) before Stripe flips them to `canceled` and our webhook transitions them to `read_only`. Worst case: a salon with a permanently invalid card keeps creating bookings for ~3 weeks before the dashboard locks. This envelope is acceptable for MVP — the alternative (gating `past_due` writes) locks out salons mid-payment-retry and causes legitimate customer-facing incidents. A future "past_due for more than N days → read_only" refinement is tracked in `docs/BACKLOG.md` as "Tighten billing freeload envelope".

---

### D-090 — Read-only enforcement via mutating-verb middleware on a dashboard inner group

- **Date**: 2026-04-17
- **Status**: accepted
- **Context**: Locked roadmap decision #12 requires that access becomes read-only after the cancellation period ends; the enforcement strategy was decided at plan time. Three options evaluated (middleware, policy, centralised controller `canWrite()` check).
- **Decision**: `App\Http\Middleware\EnsureBusinessCanWrite` (alias `billing.writable`) applied to a new inner group inside the dashboard group at `routes/web.php`. Passes through safe HTTP methods (GET/HEAD/OPTIONS) unconditionally. On any other method, if `! $business->canWrite()`, redirect to `settings.billing` with an error flash. Billing routes (`settings.billing*`), webhook routes, public booking routes, and authentication routes live OUTSIDE the gate. Public booking stays reachable for read-only businesses — product trade-off: lapsed salons don't surprise their customers mid-booking-flow.
- **Consequences**:
  - One middleware class closes every dashboard write path. Adding a new dashboard mutating route in the future automatically inherits the gate.
  - Dataset-driven test (`tests/Feature/Billing/ReadOnlyEnforcementTest.php`) walks the gated route list, proving every mutating endpoint redirects and every read endpoint stays open.
  - Carve-outs are explicit and reviewed: billing routes, `/webhooks/*`, public booking, authentication, customer area.
  - Server-side automation (queued jobs, scheduled commands, webhook handlers) continues running on a read-only business — the gate is HTTP-only. Documented in `docs/DEPLOYMENT.md §Billing` so operators aren't surprised when a lapsed business's queue stays active for already-created bookings (e.g. `AutoCompleteBookings` continues, `SendBookingReminders` continues, `calendar:renew-watches` continues, `PullCalendarEventsJob` continues).

---

### D-091 — Stripe webhook endpoint at `/webhooks/stripe`, Cashier auto-routing suppressed

- **Date**: 2026-04-17
- **Status**: accepted
- **Context**: Cashier's default webhook path is `/stripe/webhook` (or `/cashier/webhook` via config). The existing third-party webhook in this codebase is `/webhooks/google-calendar` (MVPC-2). Two options: keep Cashier's default or align to `/webhooks/*`.
- **Decision**: `Cashier::ignoreRoutes()` is called in `AppServiceProvider::register()` to suppress Cashier's auto-registration. We register `/webhooks/stripe` explicitly via `App\Http\Controllers\Webhooks\StripeWebhookController` (a thin subclass of Cashier's `WebhookController`).
- **Consequences**: All third-party webhooks live under one prefix. Stripe Dashboard endpoint URL matches the project convention. Trivial code cost (two lines).

---

### D-092 — Stripe webhook idempotency via cache-layer event-id dedup

- **Date**: 2026-04-17
- **Status**: accepted
- **Context**: Stripe retries webhook deliveries with the same event id. Cashier does not dedupe; its default `WebhookController` processes every invocation. Most Cashier handlers are DB-idempotent (e.g. `customer.subscription.updated` updates the existing subscription row), but `invoice.payment_succeeded` could double-count future analytics. Best closed at the boundary.
- **Decision**: `StripeWebhookController::handleWebhook` reads `cache()->has("stripe:event:{$eventId}")`, returns `200` early if present, otherwise sets the cache key (24h TTL — longer than Stripe's retry envelope) and delegates to `parent::handleWebhook()`. Cache driver is `database` in prod (durable) and `array` in tests.
- **Consequences**: Every Stripe event id is processed exactly once within a 24h window. No new table. Dedup is testable via `Cache::has(...)` assertions in `WebhookTest`.

---

### D-093 — Pricing: CHF 29/month, CHF 290/year; price IDs via config/env

- **Date**: 2026-04-17
- **Status**: accepted
- **Context**: Locked roadmap decision #9 deferred the exact numbers to session-plan time.
- **Decision**: Single paid tier, two prices — CHF 29/month and CHF 290/year (~17% discount, equivalent to two months free). Stripe price IDs stored in `config/billing.php` reading from `STRIPE_PRICE_MONTHLY` and `STRIPE_PRICE_ANNUAL`. Currency set via `CASHIER_CURRENCY=chf`.
- **Consequences**: Prices are tunable without code changes by editing the Stripe products + flipping env vars. The display-only labels in `config/billing.php` `display.{monthly,annual}.amount` are intentional duplicates of the Stripe prices — the developer flips them in lockstep when the underlying price changes. No downstream code branches on price.

---

### D-094 — Stripe Tax for Swiss VAT, enabled via `Cashier::calculateTaxes()`

- **Date**: 2026-04-17
- **Status**: accepted
- **Context**: Swiss VAT is 8.1%. MVP needs VAT-clean pricing from day one. Two options: implement VAT ourselves, or defer to Stripe Tax.
- **Decision**: `Cashier::calculateTaxes()` in `AppServiceProvider::boot()`. Customer enters address at Checkout; Stripe computes and collects VAT automatically on every Cashier-generated subscription and invoice. Stripe Tax must be enabled in the Stripe account (test and live).
- **Consequences**: Zero in-app VAT logic. The Swiss business's invoice shows the correct VAT line. Customers outside CH (unlikely in MVP) are taxed per Stripe's nexus rules. Pre-launch checklist gains "enable Stripe Tax in live account" — captured in `docs/DEPLOYMENT.md §Billing`.

---

### D-095 — Stripe SDK is mocked in tests via container binding of `\Stripe\StripeClient`

- **Date**: 2026-04-17
- **Status**: accepted
- **Context**: Cashier v16's controller-path Stripe calls (`$business->newSubscription(...)->checkout(...)`, `$business->redirectToBillingPortal(...)`, `$subscription->cancel()`, `$subscription->resume()`) go through the Stripe PHP SDK, which uses its own cURL transport — Laravel's `Http::fake()` does **not** intercept them. Source-level inspection of `laravel/cashier@16.5.x` confirmed `Cashier::fake()` and `Cashier::useStripeClient()` do **not** exist as public APIs. Cashier's own test suite uses real Stripe test keys, which we reject (env dependency, latency, flakiness, real Stripe test artefacts in CI).
- **Decision**: Mock the Stripe SDK via Laravel's container. `Cashier::stripe()` resolves `\Stripe\StripeClient` through `app(StripeClient::class, ['config' => $config])`. Because `app()->instance(...)` is bypassed when the resolver passes parameters, we use `app()->bind(StripeClient::class, fn () => $mock)` — closure resolution ignores the parameters and returns the mock every time. Property chains (`$stripe->checkout->sessions->create(...)`) are mocked by setting each level as a property on the top-level mock with a further Mockery mock attached, then `shouldReceive('create')` on the leaf. A shared `tests/Support/Billing/FakeStripeClient.php` builder encapsulates the wiring behind a fluent interface (mirroring MVPC-2's `FakeCalendarProvider` pattern).
- **Consequences**:
  - Zero real-Stripe calls in the test suite. No Stripe env dependency for CI.
  - Drift (Cashier changing which Stripe endpoint a method hits) surfaces as a Mockery "method not expected" failure, not a silent pass.
  - Webhook tests bypass the SDK entirely and POST canonical event shapes to `/webhooks/stripe`. The `config(['cashier.webhook.secret' => null])` helper skips signature verification for the matrix; a dedicated signature-verify test covers the production path.
  - Business-model unit tests (`BusinessSubscriptionStateTest`) exercise `onTrial()` / `canWrite()` / `subscriptionState()` against factory-built `subscriptions` rows directly — no Stripe involvement.
- **Rejected alternatives**:
  - *Real Stripe test keys* — matches Cashier's own test strategy but introduces env dependency, network latency, and real-artefact cleanup.
  - *`Http::fake()`* — doesn't intercept Stripe's cURL transport; would either hit real Stripe or fail with connection errors.
  - *`stripe-mock` side-container in CI* — heavier setup than the container-mock approach for the same fidelity at the test boundary.

---

### Cashier deviations recorded for the developer

- **Cashier version**: plan said `^15.0`. Cashier v15.x requires Laravel ≤ 12 (`illuminate/console ^10.0|^11.0|^12.0`). This project runs Laravel 13, so v16 is the minimum compatible version. Installed `laravel/cashier:^16.0` (resolved to `v16.5.1`). The container-binding seam (D-095), the absence of a `UNIQUE(user_id, type)` constraint on `subscriptions` (verified in source), and the published migration shape are unchanged between v15 and v16.
- **`subscriptions.user_id` → `subscriptions.business_id`**: Cashier's published migration ships a `user_id` column. With `Business` as the billable, Cashier's relation resolver (`$this->getForeignKey()` in `Billable::subscriptions()`) returns `business_id`. We renamed the column in our copy of the migration so the schema is honest about what it points at. No model override needed; Cashier picks the column up automatically.
