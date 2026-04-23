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
- **PHPStan / Larastan** — static analysis at level 5 over `app/` (see `phpstan.neon`). Part of the iteration loop per the Session Done Checklist; command is `./vendor/bin/phpstan`.


## Progress

- [x] (2026-04-23 22:00Z) Plan drafted and approved by developer; defaults applied for the 5 open questions (D-115..D-119).
- [x] (2026-04-23 22:10Z) M1 — Hide `online` / `customer_choice` from Settings → Booking; persisted hidden values still read back without error. New regression test: `tests/Feature/Settings/BookingSettingsTest.php::"persisted online payment_mode reads back without error"`.
- [x] (2026-04-23 22:15Z) M2 — `config/payments.php` created with three keys; no hardcoded `'CH'` literal in app code. New unit test: `tests/Unit/Config/PaymentsConfigTest.php`.
- [x] (2026-04-23 22:25Z) M3 — `pending_actions` rename migration + nullable `integration_id` + readers audit + `PendingActionType` extended with three `payment.*` cases + `calendarValues()` helper. New regression tests: `tests/Feature/Dashboard/PendingActionFiltersTest.php`. Calendar test suite stayed green.
- [x] (2026-04-23 22:35Z) M4 — `stripe_connected_accounts` migration + `StripeConnectedAccount` model (SoftDeletes, `verificationStatus()`, `matchesAuthoritativeState()`) + factory with `pending`/`incomplete`/`active`/`disabled` states + `Business::stripeConnectedAccount()` + `Business::canAcceptOnlinePayments()`. New unit tests: `tests/Unit/Models/StripeConnectedAccountTest.php`, `tests/Unit/Models/BusinessConnectedAccountTest.php`.
- [x] (2026-04-23 22:50Z) M5 — `DedupesStripeWebhookEvents` trait extracted; subscription cache prefix moved to `stripe:subscription:event:`; `MVPC-3 WebhookTest` updated to the new prefix and stayed green. `POST /webhooks/stripe-connect` controller (signature verification against `STRIPE_CONNECT_WEBHOOK_SECRET`; `account.updated` re-fetches via `accounts.retrieve`; `account.application.deauthorized` soft-deletes + forces `payment_mode=offline`; `charge.dispute.*` log-and-200 stub). New tests: `tests/Feature/Payments/StripeConnectWebhookTest.php` (9 cases including stale-payload convergence and cache-prefix isolation).
- [x] (2026-04-23 23:20Z) M6 — `ConnectedAccountController` with four actions inside the admin-only + `billing.writable` group; `auth.business.connected_account` Inertia shared prop in `HandleInertiaRequests`; `Dashboard/settings/connected-account.tsx` page with four states + accessible disconnect confirmation; dashboard-wide payment-mode-mismatch banner in `authenticated-layout.tsx`; Settings nav item under "Business" group. New tests: `tests/Feature/Payments/ConnectedAccountControllerTest.php` (10 cases including cross-tenant denial + multi-business admin + Inertia prop shape). One workaround: production uses Inertia external redirects, but those rewrite to the request URL in tests due to the version-mismatch handler in `Inertia\Middleware`; tests use the non-Inertia 302 + Location form (controller code is identical for both paths).
- [x] (2026-04-23 23:25Z) M7 — `FakeStripeClient` split into platform-level (`mockAccountCreate`, `mockAccountRetrieve`, `mockAccountLinkCreate`, header asserted ABSENT via `withArgs`) + scaffold comment for the connected-account-level bucket (Sessions 2+). Shipped alongside M5/M6.
- [x] (2026-04-23 23:35Z) M8 — Wayfinder regenerated; Pint clean (one phpdoc_align fix); PHPStan level-5 clean (5 issues fixed: match exhaustiveness, nullsafe-on-non-null, is_array-on-narrowed-type); Feature+Unit suite 730/2965 (was 695/2819 baseline; +35 tests, +146 assertions); `npm run build` clean; `docs/DEPLOYMENT.md` updated with Stripe Connect operator setup; `docs/decisions/DECISIONS-PAYMENTS.md` populated with D-109..D-119; `docs/BACKLOG.md` updated with the support-contact-surface follow-up; `docs/HANDOFF.md` rewritten.
- [x] (2026-04-23 04:55Z next-day-UTC) Codex Round 1 — three blockers fixed on the same uncommitted diff. See `## Review — Round 1` for findings + fixes. New decisions D-120, D-121, D-122. Suite now 735/2974; PHPStan + Pint + build still clean.
- [x] (2026-04-23 05:30Z next-day-UTC) Codex Round 2 — three more findings fixed (parse error, dispute persistence, accounts.create idempotency key). See `## Review — Round 2`. New decisions D-123, D-124. Suite now 739/2994; PHPStan + Pint + build still clean.
- [x] (2026-04-23 06:10Z next-day-UTC) Codex Round 3 — four more findings fixed (reconnect-within-24h, rename deploy contract, dispute partial unique index, country gate in helper). See `## Review — Round 3`. New decisions D-125, D-126, D-127. Suite now 745/3009; PHPStan + Pint + build still clean.
- [x] (2026-04-23 07:00Z next-day-UTC) Codex Round 4 — three more findings fixed (atomic + retry-safe demotion paths, incomplete-state resume, server-side payment_mode gate). See `## Review — Round 4`. New decisions D-128, D-129, D-130. Suite now 752/3027; PHPStan + Pint + build still clean.
- [x] (2026-04-23 07:30Z next-day-UTC) Codex Round 5 — three more findings fixed (atomic disconnect, hard-block non-offline payment_mode, rollback-safe migration down). See `## Review — Round 5`. New decisions D-131, D-132, D-133. Suite now 753/3033; PHPStan + Pint + build still clean.
- [x] (2026-04-23 08:05Z next-day-UTC) Codex Round 6 — three more findings fixed (cross-tenant replay guard, Cashier named-route contract, false-ready UI copy). See `## Review — Round 6`. New decisions D-134, D-135. Suite now 755/3042; PHPStan + Pint + build still clean.
- [x] (2026-04-23 09:25Z next-day-UTC) Codex Round 7 — three more findings fixed (account.updated serialisation, refresh/resume/resume-expired split, canAcceptOnlinePayments disabled_reason check). See `## Review — Round 7`. New decisions D-136, D-137, D-138. Suite now 759/3052; PHPStan + Pint + build still clean.
- [x] (2026-04-23 10:00Z next-day-UTC) Codex Round 8 — two more findings fixed (reconnect-history deauthorize guard, disconnect prefers active row). See `## Review — Round 8`. New decisions D-139, D-140. Suite now 761/3063; PHPStan + Pint + build still clean.
- [x] (2026-04-23 11:00Z next-day-UTC) Codex Round 9 — two more findings fixed (Business.country canonical field, signed URLs for Stripe return/refresh). See `## Review — Round 9`. New decisions D-141, D-142. Suite now 764/3068; PHPStan + Pint + build still clean.
- [x] (2026-04-23 12:30Z next-day-UTC) Codex Round 10 — three more findings fixed (country seed on signup, signed URLs on initial create(), resumeExpired billing-gate). See `## Review — Round 10`. New decisions D-143, D-144, D-145. Suite now 767/3076; PHPStan + Pint + build still clean.
- [x] (2026-04-23 13:15Z next-day-UTC) Codex Round 11 — one finding fixed (stale signed URL after disconnect); one rejected (pending_actions rename restatement of D-125). See `## Review — Round 11`. New decision D-146. Suite now 769/3082; PHPStan + Pint + build still clean.
- [x] (2026-04-23 14:30Z next-day-UTC) Codex Round 12 — three findings fixed (multi-business admin authz via row's business, disconnect-vs-resume TOCTOU serialisation, unknown-account webhook returns retryable 503). See `## Review — Round 12`. New decisions D-147, D-148, D-149. Suite now 774/3105; PHPStan + Pint + build still clean.
- [x] (2026-04-23 15:40Z next-day-UTC) Codex Round 13 — one finding fixed (verificationStatus returns `unsupported_market` when Stripe caps on but country drifts outside supported_countries). See `## Review — Round 13`. New decision D-150. Suite now 777/3123; PHPStan + Pint + build still clean.
- [ ] Final commit (developer; gate two).

All times approximate, UTC.


## Surprises & Discoveries

- **Observation**: Inertia v3's `Middleware::onVersionChange` rewrites a 409 + `X-Inertia-Location` response to point at the request URL when the request's `X-Inertia-Version` header doesn't match `Inertia::getVersion()`. In tests this collapsed an external Stripe redirect to a same-URL self-redirect, masking the controller's real behaviour.
  **Evidence**: Controller log line `Inertia::location url {"url":"https://connect.stripe.com/setup/c/.../redo"}` immediately followed by an X-Inertia-Location header value of `http://riservo.test/dashboard/settings/connected-account/refresh` in the response. Reproduced even after pinning `Inertia::version('test-fixed')` and matching the header — suggests a separate Inertia middleware path also rewrites.
  **Consequence**: Tests assert the non-Inertia 302 + `Location` form instead. The controller code is identical for both paths (a single `return Inertia::location($link->url)`); the test exercises the same external-redirect contract, just at the simpler boundary.
- **Observation**: Adding new enum cases to `PendingActionType` broke PHPStan's exhaustiveness check on `CalendarPendingActionController::resolve`'s `match` statement — exactly the compile-time signal we wanted.
  **Evidence**: `match.unhandled` error from `./vendor/bin/phpstan` covering the three new `payment.*` cases on the existing match.
  **Consequence**: Added a calendar-bucket `abort(404)` guard at the top of `resolve()` (so payment-typed rows never reach the calendar resolver) plus a `default => 'invalid'` arm. Resolution shapes for payment Pending Actions land in their owning per-session UIs in 2b/3.
- **Observation**: Cashier resolves `StripeClient` via `app(StripeClient::class, ['config' => $config])`. A naive container binding `bind(StripeClient::class, fn () => Cashier::stripe())` would infinite-loop because `Cashier::stripe()` itself calls `app(StripeClient::class, ...)`.
  **Evidence**: Reading `vendor/laravel/cashier/src/Cashier.php:120-129`.
  **Consequence**: Bound `StripeClient` directly in `AppServiceProvider::register()` with a closure that constructs `new StripeClient(['api_key' => config('cashier.secret'), 'stripe_version' => Cashier::STRIPE_VERSION])` — no recursion. `FakeStripeClient` in tests overrides this binding via its own `app()->bind(...)` call (D-095).


## Decision Log

Decisions land here during exec. Each entry pairs with a freshly-allocated `D-NNN` in `docs/decisions/DECISIONS-PAYMENTS.md`. Anticipated entries (final IDs decided at promotion time):

- **D-109 — Stripe Connect webhook at `/webhooks/stripe-connect`, signature verified against `STRIPE_CONNECT_WEBHOOK_SECRET`, distinct controller (not a Cashier subclass)**. The Connect event set (`account.*`, `charge.dispute.*`, `checkout.session.*`) does not overlap the platform-subscription set Cashier's base controller routes. The endpoint sits next to `/webhooks/stripe` (subscription) and `/webhooks/google-calendar` under the existing `/webhooks/*` convention (D-091). CSRF is excluded the same way (`bootstrap/app.php`).
- **D-110 — Shared D-092 dedup helper + cache-key prefix per webhook source**. The MVPC-3 inline `DEDUP_PREFIX = 'stripe:event:'` is extracted into a small reusable shape (trait `App\Support\Billing\DedupesStripeWebhookEvents` or a `WebhookEventDeduper` helper class — exec call). The subscription controller switches to `stripe:subscription:event:`; the new Connect controller uses `stripe:connect:event:`. Two namespaces cannot collide. Locked roadmap decision #38.
- **D-111 — `stripe_connected_accounts` is per-business with a unique `business_id` + `stripe_account_id` retained on soft-delete**. Per locked decisions #22 (one connected account per Business) and #36 (retain id after disconnect for audit and for late-webhook refunds in 2b). Soft-delete (not hard-delete) is the disconnect implementation; reconnecting any previously-disconnected business creates a fresh row by undeleting + clearing stale fields, OR by inserting fresh — exec decides at implementation time which is safer.
- **D-112 — `config/payments.php` is the single switch for country gating**. Three keys: `supported_countries`, `default_onboarding_country`, `twint_countries` — MVP value `['CH']` for the lists, `'CH'` for the singleton, all env-driven. No hardcoded `'CH'` literal anywhere in app code, tests, Inertia props, Tailwind utilities. Locked roadmap decision #43.
- **D-113 — `pending_actions` table generalised; `integration_id` nullable; calendar-aware readers add `whereNotNull('integration_id')` (or a `type`-based filter)**. The `CalendarPendingActionController` keeps operating against the renamed table with the calendar-type filter. The dashboard URL / controller rename to a generic `PendingActionController` is post-MVP polish. `PendingActionType` enum gains the three Session 3-consumed cases (`payment.dispute_opened`, `payment.refund_failed`, `payment.cancelled_after_payment`) so 2b / 3 don't need a schema-or-enum session. Locked roadmap decision #44.
- **D-114 — `auth.business.connected_account` Inertia shared prop carries onboarding state**. Shape: `{ status: 'not_connected'|'pending'|'active'|'disabled', country: string|null, can_accept_online_payments: bool, payment_mode_mismatch: bool }`. Same shared-prop pattern as `subscription` (D-089) and `role` / `has_active_provider` (MVPC-4). The dashboard-wide banner reads `payment_mode_mismatch` to decide whether to render. Reading the prop avoids a per-page DB hit and centralises the banner logic in the layout.
- **D-115 — Onboarding country = `config('payments.default_onboarding_country')` (always)**. `Business.address` is a freeform string today with no separate country column; regex-parsing it is fragile and unnecessary because Stripe collects the real country during hosted KYC and the `country` column on `stripe_connected_accounts` is overwritten by the first `accounts.retrieve`. Config-default-only keeps the code path simple and defers all country truth to Stripe.
  **Rationale**: simplicity and no-false-positives outweigh friction (the single extra KYC click to correct a wrong country guess). Approved 2026-04-23 by the developer.
- **D-116 — `ConnectedAccountController` mutations sit inside `billing.writable` middleware**. A SaaS-lapsed Business must resubscribe (via the `settings.billing*` routes, which sit outside the gate) before opening new payment surfaces. `GET /dashboard/settings/connected-account` and `GET /dashboard/settings/connected-account/refresh` pass through the gate unconditionally per D-090 (safe verbs are not gated); `POST` and `DELETE` redirect to `settings.billing` with a flash when the Business is read-only.
  **Rationale**: consistent with D-090's "gate at the mutation edge, billing routes carve out, everything else gated" shape. Approved 2026-04-23 by the developer.
- **D-117 — `ConnectedAccountController::create()` rejects with 422 when a connected account already exists**. Retry after a prior failure follows the same path (the partially-created row exists; the admin hits "Continue Stripe onboarding" on the settings page, which re-enters `refresh()` and mints a fresh Account Link). No silent idempotent re-create, no show-page redirect. Message: "A connected account already exists for this business — disconnect first to re-onboard."
  **Rationale**: one row at a time is the cleanest invariant and surfaces bugs (duplicate clicks, double-submits) loudly. Approved 2026-04-23 by the developer.
- **D-118 — Disabled-state "Contact support" CTA uses `mailto:support@riservo.ch` as a placeholder + `docs/BACKLOG.md` entry**. No support-flow surface exists in the codebase today (grep for `support@riservo` returned nothing under `app/` and `resources/`). The placeholder mailto links out; a BACKLOG entry titled **"Formal support-contact surface"** captures the follow-up to replace placeholders with a real flow (help page, in-app contact form, or similar) before launch.
  **Rationale**: ship the feature without blocking on a support-UX design session; track the follow-up explicitly. Approved 2026-04-23 by the developer.
- **D-119 — Pre-add all three `payment.*` cases to `PendingActionType` in Session 1**. `PaymentDisputeOpened`, `PaymentRefundFailed`, `PaymentCancelledAfterPayment` all land in the enum now. Sessions 2b / 3 are the writers; pre-adding costs nothing and avoids a cross-session enum edit that could be missed.
  **Rationale**: tight scope vs pre-add is a judgment call; pre-adding wins on ergonomics and lands within Session 1's locked #44 remit. Approved 2026-04-23 by the developer.

Add additional `D-NNN` entries here when other architectural calls crystallise. Promote each into `docs/decisions/DECISIONS-PAYMENTS.md` before close.


## Review

### Round 1

**Codex verdict**: needs-attention. Three blockers — webhook fail-open on empty secret, hardcoded country into immutable Stripe Express account, broken Postgres uniqueness on the active-row invariant. All three fixed on the same uncommitted diff.

- [x] **Finding 1 (critical)** — Webhook signature verification fails open when `STRIPE_CONNECT_WEBHOOK_SECRET` is empty. Anyone who knows a real `acct_…` id could POST `account.application.deauthorized` and force the target Business back to `payment_mode = offline`.
  *Location*: `app/Http/Controllers/Webhooks/StripeConnectWebhookController.php:66-96` (`resolveEvent()`).
  *Fix*: Restrict the empty-secret bypass to `app()->environment('testing')`. In any other environment, log a critical line and return null (the controller surfaces this as a 400). New regression test `empty connect_webhook_secret in non-testing environments fails closed` flips the environment to `production` and asserts the 400 + that no state mutation occurred. Promoted as **D-120** in `docs/decisions/DECISIONS-PAYMENTS.md`.
  *Status*: done.

- [x] **Finding 2 (high)** — `ConnectedAccountController::create()` silently passes `config('payments.default_onboarding_country')` to `stripe.accounts.create([...])`. Stripe Express country is permanent, so any non-default-country Business that hits the Enable flow gets a Stripe account in the wrong legal country with no recovery short of disconnect-and-recreate.
  *Location*: `app/Http/Controllers/Dashboard/Settings/ConnectedAccountController.php:59-107` (`create()`).
  *Fix*: New private helper `resolveOnboardingCountry()` returns `null` when (a) `count(supported_countries) !== 1` or (b) the singleton doesn't equal `default_onboarding_country`. The controller refuses with a clear admin error in both cases — no Stripe call. In MVP (`['CH']` + `'CH'`) the runtime behaviour is identical; expanding `supported_countries` becomes a forced workflow that breaks loudly until a real per-Business country selector lands. Two new feature tests cover both refusal paths. BACKLOG entry **"Per-Business country selector for Stripe Express onboarding (D-121)"** captures the fast-follow. Promoted as **D-121** in DECISIONS-PAYMENTS.md (D-115's stance is preserved but now bounded by D-121).
  *Status*: done.

- [x] **Finding 3 (high)** — `unique(['business_id', 'deleted_at'])` does not enforce "one active row per business" on Postgres because NULLs are treated as DISTINCT. Concurrent Enable clicks could create multiple active rows AND multiple live Stripe accounts (each `accounts.create` succeeds; the unique constraint never fires).
  *Location*: `database/migrations/2026_04_22_231504_create_stripe_connected_accounts_table.php:13-38` and `app/Http/Controllers/Dashboard/Settings/ConnectedAccountController.php:59-107`.
  *Fix*: New migration `2026_04_23_045538_replace_stripe_connected_accounts_unique_with_partial_index.php` drops the broken compound unique and creates a Postgres partial unique index `CREATE UNIQUE INDEX … ON stripe_connected_accounts (business_id) WHERE deleted_at IS NULL`. `ConnectedAccountController::create()` is wrapped in a `DB::transaction` with a `lockForUpdate()` on the parent `Business` row; concurrent attempts throw a dedicated `App\Exceptions\Payments\ConnectedAccountAlreadyExists` and get the same "already connected" flash as the existence check. Two new unit tests prove the index (duplicate active throws `QueryException`; soft-deleted coexists with active). Promoted as **D-122** in DECISIONS-PAYMENTS.md.
  *Status*: done.

**Iteration loop after Round 1**: 735 / 2974 tests pass (was 730 / 2965 pre-review; +5 tests / +9 assertions). PHPStan level-5 clean. Pint clean (touched two test files for `fully_qualified_strict_types` / `ordered_imports`). `npm run build` clean. Decisions D-120, D-121, D-122 promoted into `docs/decisions/DECISIONS-PAYMENTS.md`. BACKLOG updated with the country-selector follow-up (D-121).

### Round 2

**Codex verdict**: needs-attention. Three findings — one critical (a stray `/c` prefix turned ConnectedAccountController into invalid PHP), two high (dispute webhook silently swallows real disputes via the dedup cache; `accounts.create` has no idempotency key and orphans Express accounts on crash-then-retry). All three fixed on the same uncommitted diff.

- [x] **Finding 1 (critical)** — `ConnectedAccountController.php` started with `/c<?php`; `php -l` failed; any request loading the controller would fatal before the page or actions could run.
  *Location*: `app/Http/Controllers/Dashboard/Settings/ConnectedAccountController.php:1`.
  *Fix*: removed the stray `/c` prefix. `php -l` confirms `No syntax errors detected`. The full Feature+Unit suite now exercises every connected-account code path successfully.
  *Status*: done.

- [x] **Finding 2 (high)** — `charge.dispute.*` events were routed to a log-and-200 stub, then cached by `dedupedProcess()` as "successfully handled". Stripe stops retrying; the cache prevents re-processing. A real dispute opened pre-Session-3 would be silently lost.
  *Location*: `app/Http/Controllers/Webhooks/StripeConnectWebhookController.php:51-225`.
  *Fix*: the new `handleDisputeEvent()` upserts a `payment.dispute_opened` Pending Action keyed by `(business_id, payload->dispute_id)` for `created` / `updated`, resolves the row on `closed` (capturing the dispute outcome in `resolution_note = "closed:{$dispute->status}"`). The connected-account lookup includes soft-deleted rows so a dispute fired post-Disconnect still resolves to the original Business per locked roadmap decision #36. Outcome-level idempotency: an `updated` after a `closed` is a no-op. Unknown-account events log critical + 200 (no row inserted). Four new feature tests cover all branches. Promoted as **D-123** in DECISIONS-PAYMENTS.md.
  *Status*: done.

- [x] **Finding 3 (high)** — `accounts.create` had no `idempotency_key`. A crash between Stripe's response and the local insert orphans a duplicate Express account on retry.
  *Location*: `app/Http/Controllers/Dashboard/Settings/ConnectedAccountController.php:84-128`.
  *Fix*: pass `idempotency_key = 'riservo_connect_account_create_'.$business->id` per request; Stripe collapses retries within its 24h key TTL. The shape mirrors locked roadmap decision #36's `riservo_refund_{uuid}` convention. `FakeStripeClient::mockAccountCreate()` now requires and asserts the `idempotency_key` option (with optional `expectedIdempotencyKey` for exact-value pinning); a new feature test "accounts.create retry uses the same idempotency key for the same business" simulates post-Stripe/pre-commit failure by hard-deleting the local row between two POSTs and asserts the second call passes the same key. Promoted as **D-124** in DECISIONS-PAYMENTS.md (rejected reconciliation-via-search alternative documented).
  *Status*: done.

**Iteration loop after Round 2**: 739 / 2994 tests pass (was 735 / 2974 post-Round-1; +4 tests / +20 assertions). PHPStan level-5 clean. Pint clean (touched two test files for `fully_qualified_strict_types` / `ordered_imports`). `npm run build` clean. Decisions D-123, D-124 promoted into `docs/decisions/DECISIONS-PAYMENTS.md`.

### Round 3

**Codex verdict**: needs-attention. Four findings — two high (reconnect-within-24h breaks via the Stripe idempotency cache; the `pending_actions` rename is not rolling-deploy-safe), two medium (dispute Pending Action race; country gate missing from the readiness helper). All four addressed on the same uncommitted diff.

- [x] **Finding 1 (high)** — D-124's per-business idempotency key collides on disconnect+reconnect inside Stripe's 24h key TTL: Stripe replays the original `acct_…`, the local insert hits the global unique on `stripe_account_id` (the soft-deleted row retains the id per locked decision #36), the user gets a 500.
  *Location*: `app/Http/Controllers/Dashboard/Settings/ConnectedAccountController.php:99-136`.
  *Fix*: idempotency key is now `riservo_connect_account_create_{business_id}_attempt_{nonce}` where nonce = count of soft-deleted rows for this business. Each disconnect bumps the nonce → fresh key → fresh `acct_…` from Stripe. Defense-in-depth: if Stripe somehow still replays an existing `acct_…`, the controller restores the soft-deleted local row instead of inserting a colliding duplicate. Two new feature tests cover the disconnect+reconnect path and the defensive restore. Promoted as **D-125** in DECISIONS-PAYMENTS.md.
  *Status*: done.

- [x] **Finding 2 (high)** — `calendar_pending_actions` → `pending_actions` rename ships in the same release that switches readers; under any rolling deploy, one side of the rollout points at a non-existent table.
  *Location*: `database/migrations/2026_04_22_231235_rename_calendar_pending_actions_to_pending_actions.php`.
  *Fix*: documented as an explicit operator deploy requirement in `docs/DEPLOYMENT.md` (single-instance restart / blue-green / `php artisan down`). Pre-launch context (no production traffic) makes this acceptable for PAYMENTS Session 1; future post-launch table renames should follow phased expand/contract. Recorded in **D-125** alongside the idempotency-key fix because both bind the same release shape.
  *Status*: done (documentation-only fix).

- [x] **Finding 3 (medium)** — Dispute Pending Actions used `first()`-then-`create()` which races under concurrent delivery. Different Stripe events for the same dispute carry different event ids, so the cache-layer dedup doesn't collapse them.
  *Location*: `app/Http/Controllers/Webhooks/StripeConnectWebhookController.php:285-324`.
  *Fix*: new migration `add_dispute_id_unique_index_to_pending_actions.php` adds a Postgres partial unique index `((payload->>'dispute_id'))` scoped to `type = 'payment.dispute_opened'`. Handler refactored to insert-first, catch `UniqueConstraintViolationException`, then re-read for the update branch — both winner and loser converge on the same row. The insert is wrapped in `DB::transaction` so the unique violation rolls back to a Postgres SAVEPOINT, leaving the outer request transaction intact (otherwise Postgres aborts every subsequent query with `current transaction is aborted`). Two new feature tests cover the constraint directly. Promoted as **D-126** in DECISIONS-PAYMENTS.md.
  *Status*: done.

- [x] **Finding 4 (medium)** — `Business::canAcceptOnlinePayments()` checked Stripe capability booleans only, ignoring `config('payments.supported_countries')`. A connected account whose country resolved to an unsupported value would still surface as "ready" via the Inertia mismatch-banner check, the `account.updated` webhook demotion path, and the future Session 5 Settings gate.
  *Location*: `app/Models/Business.php:165-173`.
  *Fix*: helper now requires `in_array($row->country, config('payments.supported_countries'))` in addition to the existing capability checks. Every reader inherits the country gate automatically. Two new unit tests: an active account in an unsupported country reports false; flipping the supported-countries config makes the same row eligible (proves the gate is truly config-driven). Promoted as **D-127** in DECISIONS-PAYMENTS.md.
  *Status*: done.

**Iteration loop after Round 3**: 745 / 3009 tests pass (was 739 / 2994 post-Round-2; +6 tests / +15 assertions). PHPStan level-5 clean. Pint clean (touched two test files for `fully_qualified_strict_types` / `ordered_imports`). `npm run build` clean. Decisions D-125, D-126, D-127 promoted into `docs/decisions/DECISIONS-PAYMENTS.md`. DEPLOYMENT.md carries the rename-migration deploy contract.

### Round 4

**Codex verdict**: needs-attention. Three findings — two high (webhook retries can permanently skip business demotion; `incomplete` accounts cannot resume onboarding), one medium (server-side `payment_mode` gate was cosmetic). All three fixed on the same uncommitted diff.

- [x] **Finding 1 (high)** — Both Connect demotion paths mutated the row before forcing `payment_mode = offline`. `account.updated` then short-circuited on `matchesAuthoritativeState()` without re-evaluating demotion, and `account.application.deauthorized` scoped trashed rows out of the lookup. A partial-success crash (row saved, business save failed) left the system in a forever-broken state Stripe retries could not recover from.
  *Location*: `app/Http/Controllers/Webhooks/StripeConnectWebhookController.php:130-216`.
  *Fix*: both demotion paths wrap row + business writes in a single `DB::transaction`. `account.updated` evaluates the demotion check OUTSIDE the matches-short-circuit so a retry still finishes demoting even when the row already matches Stripe. `account.application.deauthorized` lookup uses `withTrashed()` so a retry against an already-trashed row finds it. Two new feature tests cover both retry paths. Promoted as **D-128** in DECISIONS-PAYMENTS.md.
  *Status*: done.

- [x] **Finding 2 (high)** — `refresh()` only minted a fresh Account Link when `! details_submitted`. The `incomplete` state (`details_submitted = true && capabilities missing`) gets a "Continue in Stripe" CTA from the React page that pointed at this route — admins were stuck in an infinite loop with no way to satisfy Stripe's outstanding requirements.
  *Location*: `app/Http/Controllers/Dashboard/Settings/ConnectedAccountController.php:226-280`.
  *Fix*: gate is now `in_array($row->verificationStatus(), ['pending', 'incomplete'], true)`. Stripe's `account_links.create` with `account_onboarding` routes to whichever step still needs input. New feature test seeds an incomplete row and asserts a fresh Account Link is minted. Promoted as **D-129** in DECISIONS-PAYMENTS.md.
  *Status*: done.

- [x] **Finding 3 (medium)** — `UpdateBookingSettingsRequest` validator accepted any `PaymentMode` enum value. The UI hide was cosmetic; a direct PUT could persist `online` and trigger the mismatch banner.
  *Location*: `app/Http/Requests/Dashboard/Settings/UpdateBookingSettingsRequest.php:22-29`.
  *Fix*: new `paymentModeRolloutRule()` closure restricts non-offline values to businesses that pass `canAcceptOnlinePayments()` (which folds in Stripe capabilities + supported-country gate per D-127). Idempotent passthrough: the currently-persisted value can always be re-submitted (keeps the form usable when other fields are edited). Four new feature tests cover the gate. Session 5 needs zero validator changes — just removes the UI hide. Promoted as **D-130** in DECISIONS-PAYMENTS.md.
  *Status*: done.

**Iteration loop after Round 4**: 752 / 3027 tests pass (was 745 / 3009 post-Round-3; +7 tests / +18 assertions). PHPStan level-5 clean. Pint clean (touched one test file for `fully_qualified_strict_types` / `ordered_imports`). `npm run build` clean. Decisions D-128, D-129, D-130 promoted into `docs/decisions/DECISIONS-PAYMENTS.md`.

### Round 5

**Codex verdict**: needs-attention. Three findings — two high (disconnect not atomic / not retry-safe; verified-Stripe carve-out lets admins persist non-offline before any flow consumes it), one medium (migration `down()` would fail rollback once payment rows exist). All three fixed on the same uncommitted diff.

- [x] **Finding 1 (high)** — `disconnect()` was not transactional; the active-only relation lookup meant a retry against a partially-completed state 404'd. Business could be permanently stuck at `online`.
  *Location*: `app/Http/Controllers/Dashboard/Settings/ConnectedAccountController.php:303-314`.
  *Fix*: D-128's pattern extended to the user-initiated path. Lookup uses `withTrashed()`; row delete + business demote wrapped in `DB::transaction`; trashed-noop branch makes the action idempotent so a retry converges. New regression test seeds a trashed row + non-offline business and asserts the retry demotes. Promoted as **D-131** in DECISIONS-PAYMENTS.md.
  *Status*: done.

- [x] **Finding 2 (high)** — D-130's verified-Stripe carve-out let admins persist `online` / `customer_choice` via direct PUT before any booking-flow code consumes `payment_mode`. Combined with the active-state success copy ("online payments are ready"), this created a false-ready surface where customers would book without paying.
  *Location*: `app/Http/Requests/Dashboard/Settings/UpdateBookingSettingsRequest.php:47-77` + `ConnectedAccountController::refresh()` + `connected-account.tsx`.
  *Fix*: removed the verified-Stripe carve-out; non-offline values are hard-blocked regardless of Stripe verification until Session 5 ships. Idempotent passthrough kept (DB-seeded development for Session 2a still works). Active-state success copy reworded to "Stripe onboarding complete. Online payments will activate in a later release." React page bullet reframes verification as the prerequisite. Inverted the previous "verified Stripe accepts online" test to assert rejection. Promoted as **D-132** in DECISIONS-PAYMENTS.md.
  *Status*: done.

- [x] **Finding 3 (medium)** — `pending_actions` rename `down()` restored `integration_id NOT NULL`, which would fail after any payment-typed row (D-123's dispute Pending Actions insert with `integration_id = null`).
  *Location*: `database/migrations/2026_04_22_231235_rename_calendar_pending_actions_to_pending_actions.php:28-37`.
  *Fix*: `down()` now performs the rename only; `integration_id` stays nullable on rollback (a strict superset of the original constraint, no impact on rolled-back code that always set `integration_id`). Inline docblock documents the operator follow-up DDL for a clean restore. Promoted as **D-133** in DECISIONS-PAYMENTS.md.
  *Status*: done.

**Iteration loop after Round 5**: 753 / 3027 tests pass (was 752 / 3027 post-Round-4; +1 net test, the inverted online-acceptance test stays at the same count, +1 disconnect-retry test). PHPStan level-5 clean. Pint clean. `npm run build` clean. Decisions D-131, D-132, D-133 promoted into `docs/decisions/DECISIONS-PAYMENTS.md`.

### Round 6

**Codex verdict**: needs-attention. Three findings — one high (replay-recovery silently re-parented cross-tenant rows), two medium (Cashier named-route contract dropped; active-state UI still said "online payments are ready"). All three fixed on the same uncommitted diff.

- [x] **Finding 1 (high)** — D-125's replay-recovery branch restored ANY soft-deleted row matching the returned `acct_…` and rewrote `business_id` to the current tenant. Cross-tenant replays silently re-parented another Business's row.
  *Location*: `app/Http/Controllers/Dashboard/Settings/ConnectedAccountController.php:141-155`.
  *Fix*: replay branch now verifies `existing->business_id === requesting->business_id`. On mismatch: log critical + throw `App\Exceptions\Payments\ConnectedAccountReplayCrossTenant` (caught by the controller, translates to a manual-reconciliation flash). Existing row is never touched. New cross-tenant regression test seeds B's soft-deleted row with the colliding `acct_…`, asserts B is untouched and A gets the error flash. Promoted as **D-134** in DECISIONS-PAYMENTS.md.
  *Status*: done.

- [x] **Finding 2 (medium)** — `Cashier::ignoreRoutes()` dropped `cashier.webhook` entirely; the re-registered `cashier.payment` hardcoded `'stripe/payment/{id}'`, ignoring `config('cashier.path')`.
  *Location*: `app/Providers/AppServiceProvider.php:33-37` + `routes/web.php`.
  *Fix*: `cashier.payment` now uses `config('cashier.path', 'stripe')` (trimmed). The existing `/webhooks/stripe` route renamed from `webhooks.stripe` to `cashier.webhook` (no callers of the old name found by grep). New regression test asserts both names exist and the URLs match the contract. Promoted as **D-135** in DECISIONS-PAYMENTS.md.
  *Status*: done.

- [x] **Finding 3 (medium)** — Active-state Connected Account page said "Your Stripe account is verified. Online payments are ready" while the validator hard-blocks online (D-132). False-ready surface for admins.
  *Location*: `resources/js/pages/dashboard/settings/connected-account.tsx:181-192`.
  *Fix*: chip label changes from "Active" to "Verified"; copy changes to "Your Stripe account is verified. Charging customers will activate in a later release." Aligns with the backend rollout gate.
  *Status*: done.

**Iteration loop after Round 6**: 755 / 3042 tests pass (was 753 / 3033 post-Round-5; +2 tests / +9 assertions). PHPStan level-5 clean. Pint clean (touched one test file for `class_definition` / `fully_qualified_strict_types` / `braces_position` / `ordered_imports`). `npm run build` clean. Decisions D-134, D-135 promoted into `docs/decisions/DECISIONS-PAYMENTS.md`.

### Round 7

**Codex verdict**: needs-attention. Three findings — two high (account.updated race; mutating GET refresh that trapped incomplete admins + bypassed the write gate), one medium (canAcceptOnlinePayments ignored requirements_disabled_reason). All three fixed on the same uncommitted diff.

- [x] **Finding 1 (high)** — `account.updated` fetched Stripe state before opening a row lock; two concurrent deliveries could interleave fetch+save and an older snapshot could overwrite a newer one.
  *Location*: `app/Http/Controllers/Webhooks/StripeConnectWebhookController.php:159-188`.
  *Fix*: the `accounts.retrieve` + save + business demote now run inside a single `DB::transaction` that opens with `StripeConnectedAccount::where(...)->lockForUpdate()->first()`. Per-account webhook processing is serialised. A new structural test asserts the invariant. Promoted as **D-136** in DECISIONS-PAYMENTS.md.
  *Status*: done.

- [x] **Finding 2 (high)** — `refresh()` was GET-mutating-and-redirecting-to-Stripe. Pending/incomplete admins returning from KYC got bounced back into Stripe with no way to land on the settings page; SaaS-lapsed admins bypassed `billing.writable` because GET.
  *Location*: `app/Http/Controllers/Dashboard/Settings/ConnectedAccountController.php:265-313`.
  *Fix*: split into three endpoints — `refresh()` (GET, sync + redirect to settings), `resume()` (POST, admin-triggered, gated by billing.writable, mints Account Link), `resumeExpired()` (GET, Stripe's refresh_url handler). React page's "Continue" CTAs become `<Form action={resume()}>` POSTs. Four new feature tests cover the split. Promoted as **D-137** in DECISIONS-PAYMENTS.md.
  *Status*: done.

- [x] **Finding 3 (medium)** — `canAcceptOnlinePayments()` ignored `requirements_disabled_reason`. Disabled accounts could be surfaced as ready if capability flags were stale.
  *Location*: `app/Models/Business.php:176-190`.
  *Fix*: helper now returns false when `requirements_disabled_reason` is non-null, checked before capability booleans. Consistent with `StripeConnectedAccount::verificationStatus()`. New unit test covers active-capabilities + disabled_reason. Promoted as **D-138** in DECISIONS-PAYMENTS.md.
  *Status*: done.

**Iteration loop after Round 7**: 759 / 3052 tests pass (was 755 / 3042 post-Round-6; +4 tests / +10 assertions). PHPStan level-5 clean. Pint clean. `npm run build` clean. Decisions D-136, D-137, D-138 promoted into `docs/decisions/DECISIONS-PAYMENTS.md`.

### Round 8

**Codex verdict**: needs-attention. Two findings (both high) — reconnect history broke both the deauthorize demotion gate and the admin-side disconnect row lookup. Both fixed on the same uncommitted diff.

- [x] **Finding 1 (high)** — Late `account.application.deauthorized` for a retired `acct_…` could demote a business that had already reconnected with a fresh active account.
  *Location*: `app/Http/Controllers/Webhooks/StripeConnectWebhookController.php:243-263`.
  *Fix*: after trashing the matched row, unset the relation cache and re-read `$business->stripeConnectedAccount` (SoftDeletes-scoped). Only demote `payment_mode` to `offline` when the relation resolves to null (no active account remains). Historical soft-deleted rows never mutate current business state. New regression test covers the reconnect + late-deauthorize scenario. Promoted as **D-139** in DECISIONS-PAYMENTS.md.
  *Status*: done.

- [x] **Finding 2 (high)** — `disconnect()` used `stripeConnectedAccount()->withTrashed()->first()`, which after a reconnect cycle could return a historical trashed row instead of the active one — leaving the real active Stripe account behind.
  *Location*: `app/Http/Controllers/Dashboard/Settings/ConnectedAccountController.php:430-442`.
  *Fix*: prefer the active relation (`$business->stripeConnectedAccount`). Fall back to `withTrashed()->first()` only when no active row exists (the retry-recovery path D-131 preserved). New regression test: reconnect history + disconnect, asserts the NEW active row is the one trashed. Promoted as **D-140** in DECISIONS-PAYMENTS.md.
  *Status*: done.

**Iteration loop after Round 8**: 761 / 3063 tests pass (was 759 / 3052 post-Round-7; +2 tests / +11 assertions). PHPStan level-5 clean. Pint clean. `npm run build` clean. Decisions D-139, D-140 promoted into `docs/decisions/DECISIONS-PAYMENTS.md`.

### Round 9

**Codex verdict**: needs-attention. Two findings (both high) — hardcoded Stripe country with no canonical per-business value; unsigned mutating GET for Stripe's refresh_url bound to mutable session tenant. Both fixed on the same uncommitted diff.

- [x] **Finding 1 (high)** — `create()` passed `config('payments.default_onboarding_country')` into `accounts.create()` with no code-level proof the business was in that country. Stripe Express country is permanent; a non-CH tenant would get an immutable wrong-jurisdiction account.
  *Location*: `app/Http/Controllers/Dashboard/Settings/ConnectedAccountController.php:54-119`.
  *Fix*: canonical `businesses.country` column added (nullable, backfilled to `'CH'` for pre-launch rows). `resolveOnboardingCountry()` now takes the Business and verifies `$business->country` is in `config('payments.supported_countries')`, returning null (→ refused onboarding) when missing or unsupported. Three new feature tests; D-115's config-fallback stance is superseded. Promoted as **D-141** in DECISIONS-PAYMENTS.md.
  *Status*: done.

- [x] **Finding 2 (high)** — `resumeExpired()` was an unsigned GET that minted Stripe Account Links, bound only to `tenant()->business()`. Cross-site GET could trigger; tab-switched session could resume the wrong tenant; `billing.writable` passed GET verbs through unconditionally.
  *Location*: `app/Http/Controllers/Dashboard/Settings/ConnectedAccountController.php:349-468`.
  *Fix*: `mintAccountLink()` passes `URL::temporarySignedRoute(...)` for both `return_url` and `refresh_url`, carrying the `account` (acct_…) as a query param. Routes for `refresh` and `resume-expired` carry the `signed` middleware. New `resolveSignedAccountRow()` helper looks up the row by the signed param and verifies the current tenant owns it (403 otherwise). Two new security regression tests: unsigned GET rejected; cross-tenant signed URL rejected. Promoted as **D-142** in DECISIONS-PAYMENTS.md.
  *Status*: done.

**Iteration loop after Round 9**: 764 / 3068 tests pass (was 761 / 3063 post-Round-8; +3 tests / +5 assertions). PHPStan level-5 clean. Pint clean (touched one test file for `fully_qualified_strict_types` / `ordered_imports` / `single_blank_line_at_eof`). `npm run build` clean. Decisions D-141, D-142 promoted into `docs/decisions/DECISIONS-PAYMENTS.md`.

### Round 10

**Codex verdict**: needs-attention. Three findings — two high (first-time KYC return 403s because the initial create() flow uses plain URLs, post-migration signups dead-ended by the D-141 country gate), one medium (resumeExpired billing-gate bypass via 24h signed URL). All three fixed on the same uncommitted diff.

- [x] **Finding 1 (high)** — Post-migration signups land with `country = null`; the D-141 gate then refuses Stripe onboarding forever because `null` is not in `supported_countries`. `RegisterController::store()` created Business rows with only `name` + `slug`. The migration backfilled pre-existing rows, so the regression was invisible in existing test data but permanent for every real signup after the deploy.
  *Location*: `app/Http/Controllers/Auth/RegisterController.php:31-35`.
  *Fix*: `Business::create([...])` now also passes `'country' => config('payments.default_onboarding_country')`. The seed uses the same config key Session 1 originally treated as the default pre-D-141, so extending to another country is a config flip. A future business-onboarding step (BACKLOG entry) will override the seed per-business before Stripe is ever touched. New feature test in `RegisterTest.php` asserts the column is seeded and the value is in `supported_countries` (the chain with D-141 is coherent). Promoted as **D-143** in DECISIONS-PAYMENTS.md.
  *Status*: done.

- [x] **Finding 2 (high)** — The initial `create()` flow still passed plain `route('settings.connected-account.refresh' / 'resume-expired')` URLs to Stripe for `refresh_url` / `return_url`. Both routes now require `signed` middleware + a tenant-matched `account` query param (D-142), so the very first KYC return or link-expired retry hit 403 — onboarding could never complete.
  *Location*: `app/Http/Controllers/Dashboard/Settings/ConnectedAccountController.php:196-207`.
  *Fix*: `create()` now calls `$this->mintAccountLink($row)` — the same helper `resume()` and `resumeExpired()` already used. Single source of truth for the signed-URL shape; the 24h TTL + `account` query param + tenant-match check all come along for free. `FakeStripeClient::mockAccountLinkCreate()` gained an optional `expectedSignedAccountParam` argument that enforces `signature=…` + `account={acct_id}` on both URLs via Mockery's `withArgs`. A regression where the controller reverts to plain `route(...)` URLs fails the matcher with a clear diagnostic instead of silently shipping a 403. One new feature test wires the assertion into `create()`'s happy path. Promoted as **D-144** in DECISIONS-PAYMENTS.md.
  *Status*: done.

- [x] **Finding 3 (medium)** — `resumeExpired()` is GET, so `billing.writable` passed it through unconditionally (D-090). But the handler mints a fresh Stripe Account Link — a NEW payment surface that D-116 forbids on a read-only business. The signed URL's 24h TTL meant a pre-minted link carried over the subscription cancellation boundary: a lapsed admin could keep opening fresh onboarding URLs.
  *Location*: `app/Http/Controllers/Dashboard/Settings/ConnectedAccountController.php:353-382`.
  *Fix*: After the signed-URL + tenant-match guard and BEFORE any Stripe call, check `tenant()->business()?->canWrite()`. On false, redirect to `route('settings.billing')` with the same `__('Your subscription has ended. Please resubscribe to continue.')` flash `EnsureBusinessCanWrite` uses — UX consistency. No Stripe call made. `refresh()` doesn't need the same guard (it's pure sync, doesn't mint a new surface); `resume()` is a POST so `billing.writable` already covers it. New feature test: canceled-subscription business hits a pre-minted signed `/resume-expired` URL → redirects to billing with the standard flash. No Stripe mocks registered — a regression where the guard is skipped triggers an unstubbed `accountLinks->create` and Mockery explodes with "method not expected". Structural enforcement. Promoted as **D-145** in DECISIONS-PAYMENTS.md.
  *Status*: done.

**Iteration loop after Round 10**: 767 / 3076 tests pass (was 764 / 3068 post-Round-9; +3 tests / +8 assertions). PHPStan level-5 clean. Pint clean (touched one test file for `fully_qualified_strict_types` / `ordered_imports`). Wayfinder regenerated. `npm run build` clean. Decisions D-143, D-144, D-145 promoted into `docs/decisions/DECISIONS-PAYMENTS.md`.

### Round 11

**Codex verdict**: needs-attention. Two findings — one high (stale signed Stripe return/refresh URLs still resolve against soft-deleted rows, letting a post-disconnect KYC completion revive local state), one high REJECTED (restatement of D-125's pre-launch deploy-timing trade-off for the `pending_actions` rename). One fix applied on the same uncommitted diff; one documented as a restatement, not a new finding.

- [x] **Finding 1 (high)** — `resolveSignedAccountRow()` uses `withTrashed()` (correct for the cross-tenant-replay defense in `create()`), and `refresh()` + `resumeExpired()` did not check `$row->trashed()` afterwards. Signed URLs live 24h (D-142); a pre-disconnect signed URL therefore kept resolving after disconnect. An admin who completed Stripe KYC AFTER clicking Disconnect would revive local sync state (via `refresh()` → `syncRowFromStripe`) or mint a FRESH Account Link for a disconnected account (via `resumeExpired()` → `mintAccountLink`) — both violate the disconnect invariant and are expensive to reconcile because the real Stripe acct_ still exists (locked decision #36 retains the id on the trashed row for Session 2b's late-refund path).
  *Location*: `app/Http/Controllers/Dashboard/Settings/ConnectedAccountController.php:283-310` (`refresh()`), `app/Http/Controllers/Dashboard/Settings/ConnectedAccountController.php:358-390` (`resumeExpired()`).
  *Fix*: both handlers now check `if ($row->trashed())` after `resolveSignedAccountRow()` and redirect to `settings.connected-account` with a clear admin flash: "This Stripe account has been disconnected. Start a new onboarding from the settings page if you want to accept online payments again." No Stripe call is made — neither `accounts.retrieve` (would redundantly sync a disconnected row) nor `accountLinks->create` (would mint a fresh onboarding surface for a disconnected account). Two new regression tests register NO FakeStripeClient mocks: a trashed-row request arriving on either handler triggers the guard and redirects; a regression that skips the guard would hit the unstubbed Stripe call and Mockery would explode with "method not expected" — structural enforcement. The check does NOT move into `resolveSignedAccountRow()` because that helper is also called indirectly in the cross-tenant-replay defense path, which DOES need to see trashed rows (to refuse re-parenting). Keeping the trashed-check at each handler site preserves both invariants. Promoted as **D-146** in DECISIONS-PAYMENTS.md.
  *Status*: done.

- [ ] **Finding 2 (high) — REJECTED as restatement of D-125**. Codex flagged that the `calendar_pending_actions` → `pending_actions` rename is not safe for any deploy where old and new code overlap, and recommended an expand/contract sequence or an explicit maintenance window. This is the exact issue Codex Round 3 surfaced and is already documented in D-125 + `docs/DEPLOYMENT.md`: the operator deploy requirement is a single-instance restart / blue-green / `php artisan down` because riservo is pre-launch (no production traffic, single instance). Expand/contract is the right pattern POST-launch and is captured as a general future-deploy discipline — but for PAYMENTS Session 1's context (pre-launch, single-node, no concurrent readers), documented atomic cutover is sufficient. No code change. No new decision. This rejection is recorded here (not as a new `D-` entry) because D-125 already carries the reasoning; a second entry would just duplicate it.

**Iteration loop after Round 11**: 769 / 3082 tests pass (was 767 / 3076 post-Round-10; +2 tests / +6 assertions). PHPStan level-5 clean. Pint clean (nothing to fix). `npm run build` unchanged. Decision D-146 promoted into `docs/decisions/DECISIONS-PAYMENTS.md`.

### Round 12

**Codex verdict**: needs-attention. Three findings — two high (signed URLs can strand multi-business admins after re-auth; disconnect is racy against resume/refresh), one medium (unknown-account `account.*` webhook events return 200 + cache, stranding us when an event beats our local insert). All three fixed on the same uncommitted diff. The review was run WITH focus text telling Codex about D-125's pre-launch deploy trade-off so it didn't re-flag the `pending_actions` rename; Codex complied and surfaced three new-angle findings instead.

- [x] **Finding 1 (high)** — `resolveSignedAccountRow()` required the session-pinned tenant to own the signed row. Problem: `ResolveTenantContext` rule 2 re-pins a user's session to their oldest active membership on login. A multi-business admin whose session expired during Stripe onboarding therefore 403s on a valid signed URL for a DIFFERENT business, even though they ARE an admin of it. The signed URL doesn't carry enough context to recover safely.
  *Location*: `app/Http/Controllers/Dashboard/Settings/ConnectedAccountController.php:472-537` (`resolveSignedAccountRow()`).
  *Fix*: authorisation is now derived from the SIGNED ROW's business. The helper loads the row, looks up the user's `BusinessMember` for that business (active memberships only — `whereNull('deleted_at')` per D-079), verifies the role is `Admin`, loads the Business, and re-pins BOTH `session('current_business_id')` AND `app(TenantContext::class)` so downstream `tenant()` calls, Inertia shared props, and the response redirect all see the correct business. The outer `role:admin` middleware still runs against the original session tenant (cannot be bypassed), so the false-negative case "staff of session tenant, admin of signed row's tenant" still 403s at middleware — acceptable tactical-fix boundary for this round; a future middleware that re-pins BEFORE `role:admin` is captured in BACKLOG. Two regression tests: (a) admin of both businesses + session pinned wrong → redirects + session re-pinned; (b) staff-only membership on signed row's business → 403. Promoted as **D-147** in DECISIONS-PAYMENTS.md.
  *Status*: done.

- [x] **Finding 2 (high)** — `refresh()`, `resume()`, `resumeExpired()` all had a pre-check-then-Stripe-call pattern. A `disconnect()` committing between the check and the Stripe call would either (a) sync `accounts.retrieve` state onto a row we're trashing, or (b) mint a fresh Account Link for a disconnected account — both violate the D-146 disconnect-finality invariant.
  *Location*: `app/Http/Controllers/Dashboard/Settings/ConnectedAccountController.php:281-468` (all four handlers).
  *Fix*: all four handlers now wrap their body in `DB::transaction` + `lockForUpdate` on the row and re-check `$locked->trashed()` inside the lock before any Stripe call or local state mutation. `resume()` and `resumeExpired()` share a `runResumeInLock(StripeConnectedAccount $row, string $settledOutcome)` helper — the `settledOutcome` arg differentiates the resume flow ("not-resumable" → error flash) from the link-expiry flow ("settled" → silent redirect back to settings). `disconnect()`'s existing transaction was extended with an inside-lock re-read so any in-flight resume/refresh either sees the pre-disconnect row (they locked first) or the trashed row (we locked first). A structural regression test inspects the controller source, asserting each public handler either inlines `DB::transaction + lockForUpdate + trashed()` re-check OR delegates to `runResumeInLock()`, and the helper itself carries all three invariants. Concurrency is not reliably simulable in Pest without a multi-connection harness; the structural check is the belt-and-braces. Promoted as **D-148** in DECISIONS-PAYMENTS.md.
  *Status*: done.

- [x] **Finding 3 (medium)** — `handleAccountUpdated` and `handleAccountDeauthorized` returned `200 Unknown account.` when the local row wasn't found. The D-092 cache-dedup trait caches 2xx responses for 24h; Stripe stops retrying. If the webhook raced `create()`'s `accounts.create` + local-insert transaction — the acct_ exists on Stripe's side at T, our row doesn't commit until T+ms — the local row gets stuck on the stale `accounts.create` snapshot until a manual admin refresh or another Stripe-side change arrives. Narrow but real.
  *Location*: `app/Http/Controllers/Webhooks/StripeConnectWebhookController.php:140-175` (`handleAccountUpdated`), `app/Http/Controllers/Webhooks/StripeConnectWebhookController.php:253-266` (`handleAccountDeauthorized`).
  *Fix*: unknown-account branches now return `503 Service Unavailable` with a `Retry-After: 60` header. The dedup trait only caches 2xx (D-092) so Stripe's retry delivers the event again; by the time Stripe retries, our local insert has almost always committed. For disputes, the "unknown account" branch stays at `200 + Log::critical` (D-123) — disputes arrive days/weeks after charges, so the race can't realistically fire, and the critical log is the reconciliation signal. `handleAccountUpdated`'s pre-check now uses `withTrashed()` so a trashed row takes the no-op path (the disconnect decision wins) rather than re-entering the 503 retry loop. Two new regression tests assert the 503 + `Retry-After` header AND that the cache is NOT populated for the event id — a regression that reverts to 200 would silently repopulate the cache and the test would fail on the `Cache::has(...)` check. Dispute-unknown-account stays at 200 per the existing test. Promoted as **D-149** in DECISIONS-PAYMENTS.md.
  *Status*: done.

**Iteration loop after Round 12**: 774 / 3105 tests pass (was 769 / 3082 post-Round-11; +5 tests / +23 assertions). PHPStan level-5 clean (one unresolvable-type error required refactoring the two resume/resumeExpired transactions into a shared helper that uses a by-reference capture pattern — the `array{outcome, url?}` return type confused PHPStan's generic inference on `DB::transaction`'s template). Pint clean. `npm run build` unchanged. Decisions D-147, D-148, D-149 promoted into `docs/decisions/DECISIONS-PAYMENTS.md`.

### Round 13

**Codex verdict**: needs-attention. One finding (medium) — `verificationStatus()` returned `active` based on Stripe capabilities alone, ignoring the country gate that `Business::canAcceptOnlinePayments()` enforces. A config/env flip removing a row's country from `supported_countries` would surface "Verified / Active" in the UI while the backend silently refuses online payments, stranding the admin with no explanation. Fixed on the same uncommitted diff. The review was run WITH focus text explicitly listing Rounds 1–12 as settled; Codex complied and returned exactly one new-angle finding.

- [x] **Finding 1 (medium)** — `StripeConnectedAccount::verificationStatus()` on an otherwise-active row returned `'active'` regardless of the row's `country`. `canAcceptOnlinePayments()` (D-127) layered on the country gate, but the UI consumed `verificationStatus()` directly (connected-account page + refresh flash + Inertia shared prop), so a country-drift scenario (operator tightens `PAYMENTS_SUPPORTED_COUNTRIES` env, or we expand then retract during beta) showed "Verified" on the account page while the backend quietly blocked online payments. The admin had no way to tell why.
  *Location*: `app/Models/StripeConnectedAccount.php:85-100` (`verificationStatus()`), `app/Http/Controllers/Dashboard/Settings/ConnectedAccountController.php:327-338` (refresh flash), `resources/js/pages/dashboard/settings/connected-account.tsx:20-30` (AccountState status union).
  *Fix*: added an explicit `'unsupported_market'` state: returned when Stripe capabilities are fully on AND `details_submitted === true` AND `requirements_disabled_reason === null`, but the row's `country` is not in `config('payments.supported_countries')`. The refresh flash gets a new `match` arm with dedicated copy ("Stripe onboarding complete, but online payments are not available for your country yet. Please contact support."). The frontend `AccountState` union picks up the new variant and the page renders a new `<UnsupportedMarket>` panel (warning alert + country code + Contact support + Disconnect CTA, matching the `<Disabled>` shape for consistency). Three regression tests: (a) unit — caps on + country=CH + supported=['DE'] returns `'unsupported_market'`; (b) unit — flipping supported_countries to include the row's country reverts to `'active'` without a DB write (proves the gate is config-driven, not persisted); (c) feature — refresh flow with mismatched country produces the new flash + Inertia shared prop state + `can_accept_online_payments = false`. `verificationStatus()`'s existing `disabled` / `incomplete` / `pending` arms are unchanged because they're independent of the market check. Promoted as **D-150** in DECISIONS-PAYMENTS.md.
  *Status*: done.

**Iteration loop after Round 13**: 777 / 3123 tests pass (was 774 / 3105 post-Round-12; +3 tests / +18 assertions). PHPStan level-5 clean. Pint clean. `npm run build` unchanged. Decision D-150 promoted into `docs/decisions/DECISIONS-PAYMENTS.md`.


## Outcomes & Retrospective

**Outcome**: PAYMENTS Session 1 lands the Stripe Connect Express onboarding foundation end-to-end, then survives 13 rounds of adversarial review hardening. An admin can now click "Enable online payments" in Settings → Online Payments, complete Stripe-hosted KYC, return to a verified-state settings page, and disconnect cleanly. The `/webhooks/stripe-connect` endpoint keeps the local row in sync with Stripe's authoritative state via the re-fetch-on-nudge pattern (locked roadmap decision #34) and demotes `payment_mode` back to offline when capabilities are lost (locked decision #20). The `pending_actions` table is generalised so payment Pending Actions in Sessions 2b / 3 can write without a fresh schema session. The FakeStripeClient is split so Sessions 2+ can extend the connected-account-level surface without re-discovering the platform-vs-account-header contract.

**Tests**: 777 / 3123 (was 695 / 2819 baseline; +82 tests, +304 assertions). Iteration loop fully green after every round: Pint, PHPStan level 5, Wayfinder, npm build.

**Compared to Purpose**: every observable acceptance criterion from `## Purpose / Big Picture` is met. The `payment_mode_mismatch` banner is dormant (no Business has `payment_mode != offline` legitimately yet), but the prop is wired and tested for a DB-seeded value. All 13 codex review rounds addressed to the developer's satisfaction (one finding rejected as restatement of D-125; all others applied).

**Decisions promoted**: D-109..D-150 (42 decisions) in `docs/decisions/DECISIONS-PAYMENTS.md`. Next free D-ID = **D-151**.

**Codex round summary (what each round found, in 1 line each)**:
- R1 (D-120/D-121/D-122): webhook fail-closed; country gate; Postgres partial unique index.
- R2 (D-123/D-124): parse error; dispute persistence; idempotency key.
- R3 (D-125/D-126/D-127): reconnect-24h nonce; dispute race; country gate in readiness helper.
- R4 (D-128/D-129/D-130): atomic demotion; incomplete-state resume; server-side payment_mode gate.
- R5 (D-131/D-132/D-133): atomic disconnect; payment_mode hard-block; rollback-safe down().
- R6 (D-134/D-135): cross-tenant replay guard; Cashier named-route contract.
- R7 (D-136/D-137/D-138): account.updated lock; refresh/resume/resume-expired split; disabled_reason in readiness.
- R8 (D-139/D-140): reconnect+late-deauth guard; disconnect prefers active row.
- R9 (D-141/D-142): canonical Business.country; signed URLs for Stripe return/refresh.
- R10 (D-143/D-144/D-145): country seed at signup; signed URLs on create(); resumeExpired billing gate.
- R11 (D-146): trashed-row guard on signed GETs.
- R12 (D-147/D-148/D-149): authz via row's business; disconnect/resume TOCTOU serialisation; unknown-account 503+Retry-After.
- R13 (D-150): `unsupported_market` state for country drift.

**Carry-overs**:
- Formal support-contact surface (D-118) — `mailto:support@riservo.ch` placeholder shipped; pre-launch needs a real flow. Tracked in `docs/BACKLOG.md`.
- Pre-role-middleware signed-URL session pinner (D-147 false-negative for "staff of session tenant + admin of signed row's tenant"). Tracked in `docs/BACKLOG.md`.
- The Inertia v3 version-mismatch behaviour deserves a small follow-up note in the testing reference. Not a blocker.

**Lessons learned**:
- Enum exhaustiveness via PHPStan is a real safety net — adding the three `payment.*` cases surfaced the calendar match coverage gap immediately, not at a runtime "Unhandled match value" exception.
- The FakeStripeClient platform-vs-account split is the cheapest correctness investment in this session: every Stripe API call in Sessions 2–4 will be tested against the right header constraint by construction.
- D-117's "reject if a row already exists" is the right call — the alternative (silent re-create) would have made debugging double-clicks much harder.
- Iterative codex review with focus text works — R12 / R13 avoided re-flagging settled findings when we explicitly listed them as out of scope. Without focus text R11 re-flagged D-125.
- Every round's fix landed on the same uncommitted diff without regression against prior rounds. The `## Review` section per round + the `D-NNN` promotion discipline kept the rationale attached to code.
- Structural / source-inspection tests (R12-2 lock serialisation, R5 migration down, etc.) are the right tool when concurrency / timing can't be reliably simulated in Pest. They catch regressions that behavioural tests can't reach.

**Status**: ready for developer commit (gate two). 48 files / 5,739 insertions staged. 13 codex rounds applied; further rounds yield diminishing returns — developer call.


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
./vendor/bin/phpstan
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

Resolved — see `## Decision Log` (D-115..D-119 defaults). None outstanding.


## Risks & Notes

- **Cache-key prefix change is a one-time invalidation event for the subscription webhook**. The MVPC-3 `stripe:event:{id}` cache namespace becomes `stripe:subscription:event:{id}` after the refactor; any in-flight Stripe retries with old cache keys lose their dedup. The window is 24 hours (the cache TTL); the worst case is a duplicate-processed event on a redelivery in that window. MVPC-3 handlers are DB-idempotent (they update existing rows; no double-counted analytics yet), so this is acceptable. Documented for posterity.
- **Stripe Connect dashboard webhook subscription is a manual operator step**. The Connect webhook lives in a separate Stripe dashboard panel from the platform-subscription webhook (Stripe → Developers → Webhooks → "Connected accounts" tab vs "Account" tab). The DEPLOYMENT.md update spells this out — easy to miss otherwise.
- **`Inertia::location` for the Stripe redirect is the right call but worth flagging**. Inertia v3's `Inertia::location($url)` returns a 409 with `X-Inertia-Location` so the React side performs a full-page redirect. A regular `redirect()->away($url)` would not work cleanly inside the Inertia POST flow. The `<Form action={create()}>` submit triggers an Inertia POST; the controller responds with `Inertia::location(...)` and the browser navigates. Verified behaviour against Inertia v3 docs.
- **No tests for the mismatch banner today**. The dashboard-wide banner is a layout-level read of `auth.business.connected_account.payment_mode_mismatch`. Until Session 2a / 5 puts a Business in a non-`offline` `payment_mode` legitimately, the banner is dormant and tests-by-inspection. A factory-driven layout test could exercise it; if you want it, add it under `tests/Feature/Dashboard/MismatchBannerTest.php`. I'd default to "skip for now" — rendering a banner is a hard-to-screw-up frontend concern and the underlying prop is tested.
- **Soft-delete vs hard-delete on disconnect**. Locked decision #36 mandates soft-delete (so the cached `stripe_account_id` survives for 2b's late-webhook refund path). Hard-delete would lose the audit trail. The migration's compound-unique `(business_id, deleted_at)` is the seam that lets a re-onboard create a fresh active row alongside the soft-deleted one.
- **Charge dispute webhook stub is functional, not aspirational**. Operators MUST subscribe to `charge.dispute.*` in the Stripe Connect dashboard *now* (Session 1's DEPLOYMENT.md update) so Session 3 lands on a configured pipeline. Without the subscription, Stripe never delivers the events; Session 3's tests pass but production silently misses disputes until the operator re-checks the dashboard.
