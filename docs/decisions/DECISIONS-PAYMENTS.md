# Customer-to-Professional Payment Decisions

This file holds decisions specific to the customer-to-professional payment flow tracked in the active `docs/ROADMAP.md` (Stripe Connect Express, TWINT-first, zero riservo commission).

Distinct from `DECISIONS-FOUNDATIONS.md`, which carries the SaaS subscription billing decisions (D-007, D-089–D-095) for the riservo-charges-the-professional flow. Cross-references between the two files are explicit when relevant — terminology is intentionally separated: this file uses "payment", "charge", "refund", "payout", "connected account"; foundations uses "subscription", "billing", "trial".

---

### D-109 — Stripe Connect webhook at `/webhooks/stripe-connect`, distinct fresh controller

- **Date**: 2026-04-23
- **Status**: accepted
- **Context**: PAYMENTS Session 1 needs a webhook endpoint for `account.*`, `charge.dispute.*`, and (in later sessions) `checkout.session.*` / `payment_intent.*` / `charge.refunded` events scoped to connected accounts. Cashier's `StripeWebhookController` (D-091) routes platform-subscription events (`customer.subscription.*`, `invoice.*`) that do not match the Connect set, so subclassing Cashier's controller would mean fighting its base `match` arms.
- **Decision**: A fresh controller `App\Http\Controllers\Webhooks\StripeConnectWebhookController` mounted at `POST /webhooks/stripe-connect` (alongside MVPC-3's `/webhooks/stripe` and MVPC-2's `/webhooks/google-calendar`). It is invokable (single `__invoke`) and does NOT extend Cashier's `WebhookController`. Signature verification reads `config('services.stripe.connect_webhook_secret')` (env `STRIPE_CONNECT_WEBHOOK_SECRET`) — distinct from Cashier's `STRIPE_WEBHOOK_SECRET`. CSRF is excluded in `bootstrap/app.php` next to the other webhook paths.
- **Consequences**:
  - The Stripe dashboard requires a SEPARATE webhook subscription per Stripe convention — operators configure it in **Developers → Webhooks → Connected accounts** (the second tab). `docs/DEPLOYMENT.md` documents the full subscribed-event list including the events Sessions 2/3 will need.
  - Sessions 2a / 2b / 3 add their handlers to the existing `dispatch()` method's `match` arms. The controller's `default` returns 200 so Stripe never sees a 4xx for an unsubscribed-but-fired event.
  - Session 1 ships log-and-200 stubs for `charge.dispute.*` so operators configure the subscription once and Session 3 lands on a configured pipeline without churn.
- **Cross-refs**: locked roadmap decision #38 (cache-key prefix isolation); D-110 (shared dedup helper).

---

### D-110 — Shared D-092 dedup helper + per-source cache-key prefix

- **Date**: 2026-04-23
- **Status**: accepted
- **Context**: MVPC-3's `StripeWebhookController` inlined cache-layer event-id idempotency (D-092) with the constant `private const DEDUP_PREFIX = 'stripe:event:'`. PAYMENTS Session 1 adds the Connect webhook, which needs the same dedup pattern but against a different cache namespace per locked roadmap decision #38. Two namespaces cannot collide even if Stripe ever emits the same event ID across the platform and a connected account.
- **Decision**: Extract the dedup logic into a trait `App\Support\Billing\DedupesStripeWebhookEvents` that exposes `dedupedProcess(?string $eventId, string $cachePrefix, Closure $process): Response`. Both `StripeWebhookController` (subscription, prefix `stripe:subscription:event:`) and `StripeConnectWebhookController` (Connect, prefix `stripe:connect:event:`) consume the trait. The 24-hour TTL and "cache only on 2xx" rules from D-092 are preserved.
- **Consequences**:
  - The MVPC-3 cache namespace changes from `stripe:event:{id}` to `stripe:subscription:event:{id}`. Worst case is one duplicate-processed event for any redelivery in flight at deploy time — acceptable because MVPC-3 handlers are DB-idempotent. The 24-hour cache TTL bounds the window.
  - `WebhookTest.php` (subscription tests) was updated to the new prefix; the cache-key isolation test in `StripeConnectWebhookTest` proves the two namespaces dedupe independently.
  - Sessions 2+ that add Connect handlers reuse the trait without touching the dedup logic — they only branch in `dispatch()`.

---

### D-111 — `stripe_connected_accounts` per-business with `(business_id, deleted_at)` compound unique; soft-delete retains `stripe_account_id`

- **Date**: 2026-04-23
- **Status**: accepted
- **Context**: Locked roadmap decisions #22 (one connected account per Business) and #36 (retain `stripe_account_id` on disconnect for Session 2b's late-webhook refund path) bind the schema shape. A re-onboarding flow needs to coexist with the soft-deleted historical row.
- **Decision**: New table `stripe_connected_accounts` with `business_id` FK (`cascadeOnDelete`), `stripe_account_id` (unique), Stripe-authoritative state columns (`country`, `charges_enabled`, `payouts_enabled`, `details_submitted`, `requirements_currently_due` JSON, `requirements_disabled_reason`, `default_currency`), `softDeletes()`, and a compound unique on `(business_id, deleted_at)` mirroring D-079's pattern for `business_members`. Postgres treats `NULL` as distinct in unique constraints, so only one *active* (non-trashed) row per Business is permitted while soft-deleted rows can stack alongside. The `Business` model gains `stripeConnectedAccount(): HasOne` (SoftDeletes-scoped — returns the active row only) and `canAcceptOnlinePayments(): bool` (true iff `charges_enabled && payouts_enabled && details_submitted` on the active row).
- **Consequences**:
  - Disconnect (`ConnectedAccountController::disconnect`) soft-deletes; re-onboarding creates a fresh active row alongside the trashed one.
  - Session 2b's late-webhook refund path (locked roadmap decision #36) reads `stripe_account_id` from the soft-deleted row when needed.
  - The `Business::canAcceptOnlinePayments()` helper is the gate Session 5's Settings UI reads (along with the country gate per D-112) before re-exposing `online` / `customer_choice`.

---

### D-112 — `config/payments.php` is the single switch for country gating

- **Date**: 2026-04-23
- **Status**: accepted
- **Context**: Locked roadmap decision #43 mandates that no hardcoded `'CH'` literal appears in application code, tests, Inertia props, or Tailwind class checks — extending to IT / DE / FR / AT / LI in a fast-follow must be a config flip, not a refactor.
- **Decision**: New config file `config/payments.php` exposes three keys:
  - `supported_countries` (env `PAYMENTS_SUPPORTED_COUNTRIES` comma-separated; MVP = `['CH']`) — read by Session 2a's Checkout-creation country assertion, Session 4's payout-page non-CH banner, and Session 5's Settings → Booking gate.
  - `default_onboarding_country` (env `PAYMENTS_DEFAULT_ONBOARDING_COUNTRY`; MVP = `'CH'`) — passed to `stripe.accounts.create([...])` by Session 1's `ConnectedAccountController::create` per D-115.
  - `twint_countries` (env `PAYMENTS_TWINT_COUNTRIES` comma-separated; MVP = `['CH']`) — read by Session 2a's Checkout `payment_method_types` branching to enable TWINT only on CH-located accounts.
- **Consequences**: Extending the supported set is a config + env flip plus, per locked roadmap decision #43, a verification of (a) tax assumptions for the new country, (b) Stripe's locale matrix for the new country (decision #39), and (c) the card-only fallback UX for non-TWINT countries.

---

### D-113 — `pending_actions` table generalised; `integration_id` nullable; calendar-aware readers add `whereNotNull` (or type-bucket) filters

- **Date**: 2026-04-23
- **Status**: accepted
- **Context**: Locked roadmap decision #44 mandates that the existing `calendar_pending_actions` table (MVPC-2) is generalised so payment Pending Actions in Sessions 2b / 3 can write to the same table without a fresh schema session. Existing `PendingActionType` enum values are NOT renamed in this roadmap.
- **Decision**:
  - Migration renames `calendar_pending_actions` → `pending_actions` and alters `integration_id` to nullable. The FK constraint with `cascadeOnDelete()` is preserved for calendar-typed rows.
  - `App\Models\PendingAction::$table` updated to `'pending_actions'`.
  - `PendingActionType` enum gains three new cases per D-119: `PaymentDisputeOpened`, `PaymentRefundFailed`, `PaymentCancelledAfterPayment` (Sessions 2b / 3 are the writers).
  - Calendar-aware readers (Inertia `calendarPendingActionsCount` middleware prop, `CalendarIntegrationController::pendingActionsCountForViewer`, `DashboardController::pendingActionsForViewer`, `CalendarPendingActionController::resolve`) all scope to `whereIn('type', PendingActionType::calendarValues())` so payment-typed rows do not leak into calendar surfaces.
- **Consequences**:
  - The dashboard URL / controller rename to a generic `PendingActionController` is post-MVP polish; the existing `CalendarPendingActionController` keeps operating against `pending_actions` with the type-bucket filter.
  - Sessions 2b / 3 write payment-typed rows directly via Eloquent and surface them in their per-session UIs (booking-detail panel, dispute panel) — there is no unified cross-family list in MVP.
  - The `PendingActionType::calendarValues()` static helper is the single source of truth for the "calendar bucket" — extending it covers every reader at once.

---

### D-114 — `auth.business.connected_account` Inertia shared prop carries onboarding state

- **Date**: 2026-04-23
- **Status**: accepted
- **Context**: The dashboard-wide mismatch banner and the Connected Account settings page need a Business-scoped read of "is online payment ready?". A per-page DB read is wasteful when the value rides naturally on the existing `auth.business` shared prop alongside `subscription` (D-089) and `role` / `has_active_provider` (MVPC-4).
- **Decision**: `HandleInertiaRequests::resolveBusiness` extends the payload with a `connected_account` key shaped:
  ```ts
  {
      status: 'not_connected' | 'pending' | 'incomplete' | 'active' | 'disabled',
      country: string | null,
      can_accept_online_payments: boolean,
      payment_mode_mismatch: boolean,
  }
  ```
  `payment_mode_mismatch` is `true` when `Business.payment_mode !== 'offline'` AND `! canAcceptOnlinePayments()` — the dashboard layout reads this to render a warning banner. `stripeConnectedAccount` is eager-loaded alongside `subscriptions` so the per-page query budget stays flat.
- **Consequences**:
  - The banner is dormant until Session 2a / 5 puts a Business in a non-offline `payment_mode` legitimately, but is wired now so a DB-seeded value (Session 2a dogfooding) surfaces the warning correctly.
  - Sessions 2a / 5 read the prop directly on the Settings → Booking page to drive the per-option gate without an extra round-trip.

---

### D-115 — Onboarding country = `config('payments.default_onboarding_country')` (always)

- **Date**: 2026-04-23
- **Status**: accepted
- **Context**: `Business.address` is freeform text today (no separate country column); regex-parsing it for an ISO-3166-1 alpha-2 code is fragile (formatting variation, language variation, abbreviation variation). The roadmap permitted "agent decides" between parsing and config-default-only.
- **Decision**: `ConnectedAccountController::create` always passes `config('payments.default_onboarding_country')` to `stripe.accounts.create([...])`. Stripe collects the real country during hosted KYC, and the local row's `country` column is overwritten by the first `accounts.retrieve(...)` (either the synchronous return-URL retrieve or the `account.updated` webhook handler).
- **Consequences**: Worst case is one extra Stripe-side click for a non-CH admin to correct the guess during KYC. The benefit is zero parsing-related bugs and a deterministic onboarding code path. A future address-with-structured-country migration would simply read the column and skip the config default.

---

### D-116 — `ConnectedAccountController` mutations sit inside `billing.writable` middleware

- **Date**: 2026-04-23
- **Status**: accepted
- **Context**: D-090's `EnsureBusinessCanWrite` (alias `billing.writable`) gates non-billing dashboard mutations on a healthy SaaS subscription. The Connected Account routes are admin-only and arguably a different commercial concern than booking mutations.
- **Decision**: The four `ConnectedAccountController` routes (`show`, `create`, `refresh`, `disconnect`) sit INSIDE the `billing.writable` group. A SaaS-lapsed Business cannot open new payment surfaces — they must resubscribe via `settings.billing*` (which sits OUTSIDE the gate) before onboarding Stripe Connect. Per D-090 the gate passes safe HTTP verbs through unconditionally, so a lapsed admin returning from Stripe-hosted KYC still lands on a working `GET /refresh`.
- **Consequences**: Consistent with D-090's "gate at the mutation edge, billing routes carve out, everything else gated" shape. Lapsed admins see the read-only banner and the standard "Resubscribe" CTA, then complete onboarding cleanly post-resubscribe.

---

### D-117 — `ConnectedAccountController::create` rejects with 422 when an active row already exists

- **Date**: 2026-04-23
- **Status**: accepted
- **Context**: Two reasonable behaviours when the admin clicks "Enable online payments" on a Business that already has a connected-account row: (a) reject with 422 ("Already connected. Disconnect first."), (b) silently re-mint an Account Link, (c) redirect to the show page. The roadmap left this as an exec call.
- **Decision**: Reject with a flash error. The admin must click Disconnect before re-onboarding. A partial onboarding flow (admin bailed mid-KYC and the local row carries `details_submitted = false`) is continued via the Settings page's "Continue Stripe onboarding" CTA, which routes through `refresh()` and transparently mints a fresh Account Link — NOT through `create()` again.
- **Consequences**: One row at a time is the cleanest invariant; double-clicks and accidental re-submits surface loudly rather than silently creating orphan Stripe accounts.

---

### D-118 — Disabled-state "Contact support" CTA uses `mailto:support@riservo.ch` placeholder + BACKLOG entry

- **Date**: 2026-04-23
- **Status**: accepted
- **Context**: When Stripe disables a connected account (`requirements_disabled_reason !== null`), the Settings page surfaces the verbatim Stripe reason and a "Contact support" CTA. There is no support-flow surface in the codebase today — grep for `support@riservo` returns nothing under `app/` or `resources/`.
- **Decision**: The Settings page renders a `mailto:support@riservo.ch` link as a placeholder and ships. A `docs/BACKLOG.md` entry titled **"Formal support-contact surface"** captures the follow-up to replace placeholders with a real flow (help page, in-app contact form, or similar) before launch.
- **Consequences**: Ships the feature without blocking on a support-UX design session; the placeholder is searchable so a future consolidation pass can find it.

---

### D-120 — Stripe Connect webhook fails closed when `connect_webhook_secret` is empty outside the testing environment

- **Date**: 2026-04-23 (Codex Round 1 — applied on the same uncommitted Session 1 diff before commit)
- **Status**: accepted
- **Context**: D-109's initial implementation of `StripeConnectWebhookController::resolveEvent()` carried a "no secret → parse the raw payload" fallback as a testing escape hatch. Codex flagged this as a critical security finding: a blank or unset `STRIPE_CONNECT_WEBHOOK_SECRET` in production would turn `/webhooks/stripe-connect` into an unauthenticated state-mutating endpoint — anyone who knows a real `acct_…` id could POST `account.application.deauthorized` and force the target Business back to `payment_mode = offline`.
- **Decision**: The empty-secret bypass is restricted to the `testing` environment via `app()->environment('testing')`. In any other environment, an empty / unset secret causes `resolveEvent()` to log a critical line and return null, which the controller surfaces as a 400. Production is fail-closed.
- **Consequences**:
  - Operators MUST set `STRIPE_CONNECT_WEBHOOK_SECRET` before the Connect webhook is callable. `docs/DEPLOYMENT.md` already documents the env var; the failure mode now matches the documentation.
  - A new feature test `empty connect_webhook_secret in non-testing environments fails closed` proves the gate using `app()->detectEnvironment(fn () => 'production')` to switch out of testing for the duration of the assertion.
  - The testing escape hatch survives because the rest of `StripeConnectWebhookTest` posts canonical event JSON without computing real signatures — production cryptography is exercised by the dedicated signature-mismatch test which configures a real secret.

---

### D-121 — Stripe Connect onboarding refuses when `supported_countries` is multi-element until a per-Business country selector ships

- **Date**: 2026-04-23 (Codex Round 1)
- **Status**: accepted
- **Context**: D-115 (Session 1) chose to always pass `config('payments.default_onboarding_country')` to `stripe.accounts.create([...])` — Stripe collects the real country during KYC, the local `country` column is overwritten by the first `accounts.retrieve(...)`, etc. Codex flagged this as a high-severity bug: **Stripe Express country is permanent**. A non-default-country Business that reaches the Enable flow gets a Stripe account in the wrong legal country and cannot fix it without disconnect-and-recreate. In MVP this risk is bounded (the supported set is `['CH']`), but the seam is dangerous if `supported_countries` ever grows without a corresponding country-selector UI.
- **Decision**: `ConnectedAccountController::create()` now resolves the onboarding country via a private `resolveOnboardingCountry()` helper that:
  - Returns `null` if `count(config('payments.supported_countries')) !== 1` — refusing onboarding until a real per-Business country selector lands.
  - Returns `null` if `config('payments.default_onboarding_country')` is not the only entry in `supported_countries` — refusing on operator misconfig.
  - Returns the singleton country otherwise.
  When `null` is returned, the controller flashes a clear admin error and never calls Stripe. Two regression tests cover both refusal paths.
- **Consequences**:
  - In MVP the runtime behaviour is identical (`['CH']` + `'CH'` → `'CH'` is used). Production is unchanged but explicit.
  - Extending `supported_countries` becomes a forced workflow: add the country selector first, then flip the config — the controller breaks loudly rather than silently misprovisioning.
  - `docs/BACKLOG.md` carries the "Per-Business country selector for Stripe Express onboarding (D-121)" entry naming exactly what the future session must build.
  - D-115's "always use the config default" stance survives, but is now bounded by D-121's "only when the supported set is unambiguous" guardrail.

---

### D-122 — Active-row uniqueness on `stripe_connected_accounts` via Postgres partial unique index; create() wrapped in a row-locked transaction

- **Date**: 2026-04-23 (Codex Round 1)
- **Status**: accepted
- **Context**: D-111 originally added `unique(['business_id', 'deleted_at'])` to enforce "one active connected account per Business; soft-deleted rows can stack alongside" (locked roadmap decision #22). Codex correctly flagged this as broken on Postgres: NULLs in compound unique constraints are treated as DISTINCT, so two rows with `deleted_at IS NULL` for the same `business_id` are NOT rejected. The advertised invariant was unenforced. Concurrent Enable clicks could create multiple active rows AND multiple live Stripe accounts (each `accounts.create` succeeds; only the second insert would have failed under a working constraint, but it didn't fail).
- **Decision**: Two-part fix:
  1. New migration `2026_04_23_045538_replace_stripe_connected_accounts_unique_with_partial_index.php` drops the broken compound unique and creates a partial unique index `CREATE UNIQUE INDEX … ON stripe_connected_accounts (business_id) WHERE deleted_at IS NULL`. The Schema Builder has no fluent API for partial indexes, so the migration uses `DB::statement(...)` directly. Postgres-only by design — riservo is Postgres-only per D-065.
  2. `ConnectedAccountController::create()` is wrapped in a `DB::transaction` that takes a `lockForUpdate` on the parent `Business` row before the existence check + `accounts.create` + insert. Two concurrent Enable clicks now serialise: the second waits, sees the row created by the first, throws a dedicated `ConnectedAccountAlreadyExists` exception, and gets the same "already connected" flash as the pre-transaction existence check. No orphan Stripe accounts are created.
- **Consequences**:
  - Two new model tests prove the index: a duplicate active row throws `QueryException`; a soft-deleted row coexists with a fresh active row (the locked-decision-#36 cached `stripe_account_id` semantics still hold).
  - The lock is on the `businesses` row (not the new `stripe_connected_accounts` row, which doesn't exist yet on the create path) — this is the standard "synchronise on the parent" pattern when serialising creation of a child.
  - `ConnectedAccountAlreadyExists` lives at `app/Exceptions/Payments/ConnectedAccountAlreadyExists.php` — small, named, and only thrown by this controller's transaction body.
  - The migration is forward-only safe; `down()` reverts to the broken compound unique for completeness, but that path should never run in any environment that has shipped this fix.

---

### D-123 — Connect dispute webhook persists a Pending Action in Session 1 instead of log-and-200

- **Date**: 2026-04-23 (Codex Round 2)
- **Status**: accepted
- **Context**: D-109 originally shipped `charge.dispute.*` as a log-and-200 stub so operators could configure the Stripe-side subscription once and Session 3 would land on a configured pipeline. Codex (Round 2) flagged that the stub combined with the cache-layer dedup (`stripe:connect:event:{id}`) made every dispute "successfully handled" from Stripe's perspective — Stripe stops retrying, the dedup blocks re-processing, and the dispute is silently lost until / unless someone manually combs through logs.
- **Decision**: Session 1 persists every dispute event as a `payment.dispute_opened` Pending Action row keyed by `(business_id, type, payload->dispute_id)`:
  - `charge.dispute.created` / `charge.dispute.updated`: upsert the row with `status = pending`; payload carries `dispute_id`, `charge_id`, `amount`, `currency`, `reason`, `status`, `evidence_due_by`, `last_event_id`, `last_event_type`. If the row is already `resolved` (a `closed` event arrived first), no-op (outcome-level idempotency per locked roadmap decision #33).
  - `charge.dispute.closed`: resolve the row; capture the dispute outcome in `resolution_note = "closed:{$dispute->status}"`.
  - The connected-account lookup (`StripeConnectedAccount::withTrashed()->where('stripe_account_id', $event->account)`) includes soft-deleted rows so a dispute that fires after a Disconnect still resolves to the original Business per locked roadmap decision #36.
  - When the connected-account lookup misses (event for an unknown `acct_…`), log critical + return 200 — the unknown-account case is rare and indicates a DB out-of-sync condition that needs operator attention, not a Stripe retry storm.
- **Consequences**:
  - Session 3's pipeline (locked roadmap decision #35) reduces to: render the existing rows in a dispute panel, dispatch admin emails on new `created` events, deep-link rows to Stripe's Express dispute UI. The persistence layer is already correct.
  - The `pending_actions` table is calendar-typed-AND-payment-typed in production immediately; D-113's calendar-bucket filters on every reader (`PendingActionType::calendarValues()`) keep payment-typed rows out of calendar surfaces.
  - The Pending Action's `booking_id` stays null in Session 1 (no `bookings.stripe_charge_id` column yet — that lands in Session 2a). Session 3 backfills the FK from `payload->charge_id` once the column exists.
  - Four new feature tests cover: created persists; updated refreshes payload without status change; closed resolves; unknown account logs critical + 200 with no row inserted.
  - The DEPLOYMENT.md section that already lists `charge.dispute.*` as a configured-now event remains correct — operators are encouraged to subscribe in Session 1.

---

### D-124 — Stripe `accounts.create` carries an idempotency key bound to `business_id`

- **Date**: 2026-04-23 (Codex Round 2)
- **Status**: accepted
- **Context**: D-122's transaction + row-lock serialised concurrent Enable clicks for a single Business, but did not protect against the failure mode where Stripe's `accounts.create` succeeds and the local transaction then dies (process crash, OOM, network partition, ungraceful shutdown) before the Eloquent insert commits. On retry the local existence check sees no row and calls Stripe again — a second Express account is created. Stripe Express accounts are immutable and must be manually deleted from Stripe's dashboard to clean up; the orphan acct_… is a real operational cost.
- **Decision**: `ConnectedAccountController::create()` passes a per-request `idempotency_key` option to `accounts.create` of the form `'riservo_connect_account_create_'.$business->id`. Stripe stores idempotency keys for 24h and collapses retries with the same key into the original response. Within that window:
  - A retry for the same Business reuses the same key → Stripe returns the original `acct_…` instead of creating a second one.
  - The local insert (now wrapped in the D-122 transaction) records the same `acct_…` on the local row — no orphan.
  - After 24h the local row should already exist (the D-117 existence check rejects further `create()` calls before reaching Stripe), so the practical Stripe-side idempotency window matches the human-side recovery window.
  The shape mirrors locked roadmap decision #36's `riservo_refund_{uuid}` convention — verbose, namespaced, and named-after-its-purpose.
- **Consequences**:
  - The `FakeStripeClient::mockAccountCreate()` helper now requires (and asserts) an `idempotency_key` per-request option; an optional `expectedIdempotencyKey` parameter pins the exact value. Sessions 2+ that call other platform-level Stripe APIs whose idempotency matters can adopt the same pattern.
  - A new feature test "accounts.create retry uses the same idempotency key for the same business" simulates the post-Stripe / pre-commit failure path by hard-deleting the local row between two POSTs and asserts that the second call passes the same expected key — proving Stripe would collapse the retry.
  - Reconciliation against Stripe (search for an existing `acct_…` with `metadata.riservo_business_id` matching this business before calling `accounts.create`) is rejected as out-of-scope: it doubles the Stripe API cost on the happy path and is only useful in the >24h-after-crash edge case, which the D-117 existence check already covers in practice. If that edge case becomes real, a manual reconciliation path (a small admin-side "I created a Stripe account by hand; link it") is the right shape, not an automatic search.

---

### D-125 — Stripe `accounts.create` idempotency key includes a per-attempt nonce + the `pending_actions` rename requires a non-rolling deploy

- **Date**: 2026-04-23 (Codex Round 3)
- **Status**: accepted
- **Context**: Two findings combined into one decision because they share the same release shape:
  - **Reconnect race**: D-124 bound the idempotency key to `business_id` only. Stripe stores idempotency keys for 24h. Disconnect (soft-delete; retains `stripe_account_id` per locked decision #36) followed by Enable within that window replays Stripe's original `acct_…` response, the local insert collides on the global unique on `stripe_account_id`, and the user gets a 500.
  - **Rename rollout**: the `calendar_pending_actions` → `pending_actions` migration ships in the same release that switches every model / reader. Under any rolling deploy, one side of the rollout points at a non-existent table for the duration of the cutover.
- **Decision**:
  - **Idempotency key shape** is now `'riservo_connect_account_create_'.$business->id.'_attempt_'.$nonce` where `$nonce = StripeConnectedAccount::onlyTrashed()->where('business_id', $business->id)->count()`. Each disconnect bumps the trashed count, so the next reconnect uses a fresh key and Stripe creates a fresh `acct_…`. Defense-in-depth: if Stripe somehow still replays an existing `acct_…`, the controller restores the soft-deleted row (clears `deleted_at`, refills fields) instead of inserting a duplicate that would collide on the global unique. Two new feature tests cover the disconnect+reconnect-within-24h path and the defensive replay-restore path.
  - **Migration deploy requirement**: the rename is NOT rolling-deploy-safe and MUST ship via single-instance restart, blue-green cutover, or `php artisan down`. `docs/DEPLOYMENT.md` carries an explicit operator section documenting this. Acceptable for PAYMENTS Session 1 because riservo is pre-launch — there is no production traffic to disrupt. Future post-launch table renames should follow a phased expand/contract pattern (rename + parallel reads in one release, drop legacy paths in a follow-up).
- **Consequences**:
  - Reconnect within Stripe's 24h key window now works correctly. Tests prove both the happy reconnect path and the defensive restore path.
  - Operators have a clear deploy contract for this release. Laravel Cloud's default deploy strategy on the riservo plan should be verified before push (single-instance restart is the default for the MVP plan tier).
  - The metadata field `riservo_attempt` is added to the `accounts.create` payload so the operator can correlate Stripe-side acct_… records with the local attempt nonce when manually reconciling.

---

### D-126 — Dispute Pending Actions get a Postgres partial unique index on `payload->>'dispute_id'`; handler is race-safe via savepoint+catch

- **Date**: 2026-04-23 (Codex Round 3)
- **Status**: accepted
- **Context**: D-123's handler upserted a `payment.dispute_opened` Pending Action via `first()`-then-`create()`. Stripe assigns a fresh `event.id` to every dispute event (`charge.dispute.created`, `charge.dispute.updated`, `charge.dispute.closed`) so the cache-layer dedup (`stripe:connect:event:{id}`) does NOT collapse different events for the same dispute. Under concurrent delivery, both requests can observe `existing === null` and each insert a row, leaving duplicate dispute records and ambiguous operator workflow.
- **Decision**: Two-part fix:
  - New migration `2026_04_23_054441_add_dispute_id_unique_index_to_pending_actions.php` adds a Postgres partial unique index `ON pending_actions ((payload->>'dispute_id')) WHERE type = 'payment.dispute_opened'`. The expression-on-JSON index is Postgres-specific (riservo is Postgres-only per D-065). Schema Builder has no fluent API for partial / expression indexes, so the migration uses raw SQL.
  - Handler refactored to insert-first, catch-on-violation, then re-read for the update path. The insert is wrapped in `DB::transaction(...)` so the unique violation rolls back ONLY the savepoint (Postgres treats the outer transaction as aborted on any error within it; without the savepoint, the entire request transaction is poisoned and every subsequent query fails with `current transaction is aborted`).
- **Consequences**:
  - Two new feature tests cover the constraint directly: a duplicate dispute_id row throws `UniqueConstraintViolationException`; a different `type` with the same `dispute_id` payload key is allowed (the index is partial).
  - The race-loser path (existing dispute action found after the unique violation) shares code with the no-race "existing row" path — one re-read, one update, no special-case logic.
  - Session 3's planned dispute pipeline (locked roadmap decision #35) inherits the constraint for free; its email + UI work doesn't need to re-design the persistence shape.

---

### D-127 — `Business::canAcceptOnlinePayments()` includes the supported-country gate

- **Date**: 2026-04-23 (Codex Round 3)
- **Status**: accepted
- **Context**: D-111 / D-114 defined the helper as "row exists + Stripe capability booleans". The supported-country gate (locked roadmap decision #43) lived only at the Settings → Booking call site (Session 5's planned implementation). Codex flagged this as a leak: a Business whose connected-account country resolved to an unsupported value (KYC reported a different country than onboarding requested, future operator backfill, etc.) would still surface as "ready" through every other surface (the Inertia banner via `payment_mode_mismatch`, the `account.updated` demotion check, the future Settings UI gate).
- **Decision**: Fold the country check into the helper itself. The helper now requires:
  1. A non-trashed connected-account row exists.
  2. `charges_enabled && payouts_enabled && details_submitted` (Stripe capabilities).
  3. `in_array($row->country, config('payments.supported_countries'))`.
  All three must hold; any one failing returns `false`.
- **Consequences**:
  - Every reader (Inertia shared prop's `payment_mode_mismatch`, the `account.updated` webhook demotion path, the future Session 5 Settings gate) inherits the country check automatically.
  - Two new unit tests cover the new branch: an active account in an unsupported country reports `false`; flipping `supported_countries` config to include the country flips the helper to `true` (proves the gate is truly config-driven).
  - The `payment_mode_mismatch` banner now correctly fires for any Business whose stored country drifted out of the supported set, surfacing the issue in the dashboard before customers can attempt to pay.

---

### D-128 — Connect demotion paths are atomic + retry-safe (account.updated re-evaluates demotion even on no-op; deauthorized lookup uses withTrashed)

- **Date**: 2026-04-23 (Codex Round 4)
- **Status**: accepted
- **Context**: Both demotion paths in `StripeConnectWebhookController` mutated the `stripe_connected_accounts` row before forcing `payment_mode = offline` on the parent Business. If the row save succeeded but the business save failed (DB transient, OOM, restart), the system was left inconsistent in a way Stripe retries could NOT recover from:
  - **`account.updated`**: the next retry would see the row matching authoritative state via `matchesAuthoritativeState()` and 200-noop without re-running the demotion check. Business stayed at `online` forever.
  - **`account.application.deauthorized`**: the row lookup excluded soft-deleted rows. The next retry could not find the row (already trashed) and 200-noop. Business stayed at `online` forever.
- **Decision**: Three changes:
  1. Wrap each demotion path's row write + business write in a single `DB::transaction`. Partial-success crashes are no longer possible — both writes commit or both roll back.
  2. In `account.updated`, evaluate the demotion check OUTSIDE the `matchesAuthoritativeState()` short-circuit. The row save is gated by the check (skipped if matching), but the business demotion runs unconditionally on every retry until the business state is consistent with the row state.
  3. In `account.application.deauthorized`, the lookup uses `StripeConnectedAccount::withTrashed()` so a retry against an already-trashed row finds it and finishes the business demotion.
- **Consequences**:
  - Stripe webhook retries are now genuinely safe: any partial-success scenario converges to the correct state on the next delivery.
  - Two new feature tests cover both retry paths: a row-already-matches case where the business is still at `online` (proves demotion runs anyway); a row-already-trashed case where deauthorized still demotes the business.
  - The transaction also protects against a parallel admin-side disconnect racing the webhook — the second writer waits behind the first and re-evaluates state.

---

### D-129 — `ConnectedAccountController::refresh()` resumes onboarding for the `incomplete` state, not just `pending`

- **Date**: 2026-04-23 (Codex Round 4)
- **Status**: accepted
- **Context**: D-111 / D-114 defined the `incomplete` state as `details_submitted = true && (! charges_enabled || ! payouts_enabled)` — KYC submitted but Stripe still wants more details to unlock capabilities. The React page (`connected-account.tsx`) sends a "Continue in Stripe" CTA for that state, pointing at the `refresh()` route. But `refresh()` only minted a fresh Account Link when `! details_submitted` — for `incomplete`, the controller fell through to a success flash and returned the user to the same settings page without resuming onboarding. The admin would loop forever, never able to satisfy Stripe's outstanding requirements.
- **Decision**: The Account Link mint condition is now `in_array($row->verificationStatus(), ['pending', 'incomplete'], true)`. Stripe's `account_links.create` with `type = 'account_onboarding'` routes the user to whichever step still needs input — Stripe handles the "first time KYC" vs "additional details" branching server-side. `active` and `disabled` paths fall through to the success-flash redirect as before (active is done; disabled is non-recoverable through onboarding and routes to the support CTA).
- **Consequences**:
  - The admin's "Continue in Stripe" CTA actually works for both resumable states.
  - A new feature test seeds an `incomplete` row, mocks `accounts.retrieve` to return a still-incomplete state with outstanding requirements, and asserts that `refresh()` mints an Account Link (not a settings redirect).
  - Session 4's payouts-page health strip (locked roadmap decision #11) inherits the same gate — incomplete accounts there can hand the admin back to onboarding via the same CTA.

---

### D-130 — Server-side rollout gate on `payment_mode` lives in the FormRequest validator, not just the UI

- **Date**: 2026-04-23 (Codex Round 4)
- **Status**: accepted
- **Context**: PAYMENTS Session 1 (M1) hid the `online` / `customer_choice` options from the Settings → Booking select per locked roadmap decision #27. But `UpdateBookingSettingsRequest::rules()` still accepted any `PaymentMode` enum value: a direct PUT (curl, scripted client, stale browser tab) could persist `online` despite the UI hide, triggering the very mismatch banner D-114 surfaces.
- **Decision**: New rule closure `paymentModeRolloutRule()` on the FormRequest:
  - `offline` is always allowed.
  - Non-offline values are allowed only when `tenant()->business()->canAcceptOnlinePayments()` returns true (which already folds in Stripe capabilities + the supported-country gate per D-127).
  - **Idempotent passthrough**: a PUT that re-sends the currently-persisted non-offline value is allowed even when the business is no longer eligible. This keeps the form usable when other fields are edited but the persisted `payment_mode` round-trips through the form's hidden input.
- **Consequences**:
  - The rollout gate is no longer cosmetic. Direct requests cannot bypass it.
  - In Sessions 1–4 no business has `canAcceptOnlinePayments() === true` until they legitimately onboard Stripe Connect — so the gate practically reduces to "only offline" for the typical case while still permitting the dogfooding path (admin who actually onboards Connect can set non-offline via direct request before Session 5 unhides the UI).
  - Session 5 doesn't need to change the validator — it just removes the UI hide. The same `canAcceptOnlinePayments()` gate that powers the validator also powers the UI's enable / disable state, so the two paths agree by construction.
  - Four new feature tests: PUT online without Stripe is rejected; PUT customer_choice without Stripe is rejected; PUT online WITH a verified connected account is accepted; passthrough of an already-persisted non-offline value is allowed.

---

### D-131 — Admin disconnect is atomic + retry-safe (DB::transaction + withTrashed lookup)

- **Date**: 2026-04-23 (Codex Round 5)
- **Status**: accepted
- **Context**: D-128 (Round 4) wrapped both webhook demotion paths (`account.updated`, `account.application.deauthorized`) in transactions but did NOT touch the user-initiated `ConnectedAccountController::disconnect()` action. Codex Round 5 flagged that disconnect's row delete + business demote was still non-atomic. If the row delete succeeded but the business save threw (DB transient, OOM, restart), the next click would 404 — the active relation no longer resolved the trashed row — and the business would stay stuck at `online` with no UI path to recover.
- **Decision**: Mirror D-128's pattern in the user-initiated path:
  - Look up via `$business->stripeConnectedAccount()->withTrashed()->first()` so a retry against an already-trashed row finds it and finishes demoting the business.
  - Wrap row delete + business demote in a single `DB::transaction` so partial-success crashes are impossible.
  - The trashed-noop branch (skip the delete if already trashed, still force payment_mode = offline) makes the action idempotent — repeated clicks converge.
- **Consequences**:
  - Recovery path for any future drift exists by design: the user clicks Disconnect again, the controller no-ops the delete and forces offline.
  - One new feature test seeds a trashed row + non-offline business, posts disconnect, asserts the business demotes. Proves the recovery path.
  - The same withTrashed pattern is now used in three places (`account.updated`, `account.application.deauthorized`, `disconnect`); the consistency is intentional and documented.

---

### D-132 — Server hard-blocks non-offline `payment_mode` regardless of Stripe verification, until the booking flow consumes it

- **Date**: 2026-04-23 (Codex Round 5)
- **Status**: accepted
- **Supersedes**: D-130's verified-Stripe carve-out (kept the structural fix; removed the carve-out itself)
- **Context**: D-130 (Round 4) added a server-side validator gate that allowed verified-Stripe businesses to persist non-offline `payment_mode` via direct PUT, framed as a dogfooding path. Codex Round 5 flagged this as a false-ready surface: no booking-flow code consumes `payment_mode` yet (Session 2a wires Checkout, Session 5 lifts the UI hide). An admin who flipped to `online` would believe customer bookings were prepaid while the public flow still booked them without collecting money. The "Stripe onboarding complete — online payments are ready" success copy reinforced the false-ready perception.
- **Decision**:
  - Remove the verified-Stripe carve-out from `paymentModeRolloutRule()`. Non-offline values are hard-blocked regardless of Stripe verification status until Session 5 ships.
  - Idempotent passthrough is kept: a PUT that re-sends the currently-persisted non-offline value is allowed (keeps the form usable for DB-seeded development of Session 2a; the form's hidden input round-trips the value when other fields are edited).
  - `refresh()` success copy for the active state changes from "online payments are ready" to "Stripe onboarding complete. Online payments will activate in a later release." The Connected Account React page's onboarding bullet copy similarly reframes verification as "the prerequisite" rather than the green light.
  - Session 5 will swap the validator for an end-to-end check: `canAcceptOnlinePayments()` (D-127's country + Stripe capability gate) AND the public booking flow's Checkout-creation path actually exists. That swap is one line in `paymentModeRolloutRule()` plus the UI unhide.
- **Consequences**:
  - No way to reach the false-ready state in Sessions 1–4 — even a verified-Stripe admin must wait for Session 5's release.
  - The previous "PUT online is accepted with verified Stripe" test is inverted: it now asserts rejection. Two passthrough tests still cover the round-trip case.
  - Session 5's checklist gains one item: relax the validator carve-out alongside the UI unhide. Both must ship together.

---

### D-133 — `pending_actions` rename `down()` is partial: rename only, leave `integration_id` nullable

- **Date**: 2026-04-23 (Codex Round 5)
- **Status**: accepted
- **Context**: The original `down()` restored `integration_id NOT NULL` before renaming the table back. That change is incompatible with payment-typed rows added in Session 1 (D-123's dispute Pending Actions insert with `integration_id = null`). After even one such row exists, an emergency `migrate:rollback` would fail the NOT NULL change and leave the deployment stuck mid-rollback — the very situation rollbacks are supposed to recover from.
- **Decision**: `down()` performs the rename ONLY. `integration_id` stays nullable on rollback. This is a strict superset of the original NOT NULL constraint — old code that always set `integration_id` continues to work; the schema is no stricter than what the rolled-back code expects, so old reads / writes succeed. Operators who want a clean restore-to-original-schema after a rollback can manually delete payment-typed rows and run a follow-up `ALTER TABLE` (documented in the migration's docblock).
- **Consequences**:
  - Rollback is now safe regardless of how many payment-typed rows exist.
  - The schema state after `up()` then `down()` is not bit-identical to before `up()` — the column nullability differs. This is acceptable (and documented) because the rolled-back code didn't depend on the NOT NULL constraint at all (the application always set `integration_id` for calendar PAs).
  - Follow-up DDL for operators wanting strict restore is documented inline in the migration's docblock.

---

### D-134 — Replay-recovery refuses to re-parent a soft-deleted connected-account row across tenants

- **Date**: 2026-04-23 (Codex Round 6)
- **Status**: accepted
- **Context**: D-125's defense-in-depth restored any soft-deleted `stripe_connected_accounts` row matching the returned `acct_…` and rewrote its `business_id` to the current tenant. Codex Round 6 flagged the cross-tenant case: if Stripe ever replays an `acct_…` already attached to a different Business (improbable but possible — operator error, abuse, key collision), the restore branch silently re-parented the row, corrupting tenant ownership and breaking the audit + late-webhook refund linkage tied to the original Business.
- **Decision**: The replay branch now verifies `existing->business_id === requesting->business_id` BEFORE restoring. On mismatch:
  - Throw a dedicated `App\Exceptions\Payments\ConnectedAccountReplayCrossTenant` exception inside the transaction.
  - Log a `critical` line naming both businesses + the colliding `acct_…` so operators can reconcile.
  - The outer catch translates to a flash error directing the admin to contact riservo support.
  - The existing row is NEVER touched — its `business_id`, `stripe_account_id`, and trashed state are preserved as-is.
- **Consequences**:
  - Cross-tenant collision now fails closed instead of silently corrupting ownership. Operator-visible signal both in logs (critical) and admin UI (flash).
  - One new feature test seeds a soft-deleted row on Business B with an `acct_…`, then has admin A's onboarding attempt receive that same `acct_…` from Stripe; asserts B's row is untouched, A has no row, and the admin sees an error flash.
  - Same-tenant replay continues to work as before (D-125's behaviour preserved): admin who disconnected then re-clicks Enable for the same Business still gets the soft-deleted row restored with refreshed fields.

---

### D-135 — Cashier named-route contract restored (`cashier.payment` + `cashier.webhook`); payment route uses `config('cashier.path')`

- **Date**: 2026-04-23 (Codex Round 6)
- **Status**: accepted
- **Context**: D-091 chose `Cashier::ignoreRoutes()` so all webhooks live under our `/webhooks/*` convention. Codex Round 6 flagged two regressions from that override:
  - `cashier.webhook` was dropped entirely — `route('cashier.webhook')` 500'd. Cashier-internal callers (`cashier:webhook` artisan command, future SDK code) would break.
  - The re-registered `cashier.payment` hardcoded `'stripe/payment/{id}'`, ignoring `config('cashier.path')`. Deployments that rebrand the Cashier path via `CASHIER_PATH` env would generate the wrong SCA / IncompletePayment recovery URL.
- **Decision**: Two changes in `AppServiceProvider::register()` + `routes/web.php`:
  - The re-registered `cashier.payment` route now uses `config('cashier.path', 'stripe')` for the URL prefix (trimmed of leading/trailing slashes).
  - The existing `Route::post('/webhooks/stripe', ...)` is renamed from `webhooks.stripe` to `cashier.webhook`. The URL stays `/webhooks/stripe` (operator-facing) but the route NAME satisfies Cashier's contract. Our event-id-deduped `StripeWebhookController` still receives all traffic. No prior code referenced the `webhooks.stripe` name (verified by grep).
- **Consequences**:
  - Both `route('cashier.payment')` and `route('cashier.webhook')` resolve correctly. Cashier-internal callers and future code that reads the named routes work as expected.
  - Operators who set `CASHIER_PATH` get a payment URL under that prefix instead of a hardcoded `/stripe/...`.
  - One new regression test asserts both names exist and that `cashier.payment` honours the config path while `cashier.webhook` resolves to `/webhooks/stripe`.
  - The Connect webhook keeps its descriptive `webhooks.stripe-connect` name — Cashier has no naming contract for Connect events.

---

### D-136 — `account.updated` serialises per-account via `lockForUpdate` inside `DB::transaction`

- **Date**: 2026-04-23 (Codex Round 7)
- **Status**: accepted
- **Context**: D-128 wrapped the row save + business demote in a `DB::transaction`, but the `accounts.retrieve` call happened OUTSIDE the transaction with no row lock. Two concurrent webhook deliveries for the same account could interleave: request A fetches an older `incomplete` state, request B fetches a newer `active` state, B commits first, then A commits and regresses the row to the stale snapshot. This could also flip `payment_mode` back to `offline` until a future webhook or admin refresh corrects it.
- **Decision**: Move both the `accounts.retrieve` call AND the save inside a `DB::transaction` that opens with `StripeConnectedAccount::where(...)->lockForUpdate()->first()`. The pre-transaction existence check stays (fast path for unknown accounts without opening a transaction). Inside the lock, a nested existence check handles the rare race where a Disconnect commits between the two reads. The `accounts.retrieve` call happens UNDER the lock so whoever acquires second sees Stripe state that is never older than what the first committed.
- **Consequences**:
  - Per-account webhook processing is serialised. Holding the lock across a Stripe API call is acceptable for this handler's traffic profile (<1 req/s per account); if that ever becomes a hotspot we can swap to advisory locks or Stripe-event-sequence tracking.
  - If a concurrent Disconnect completes between the pre-check and the lock acquisition, the handler logs + bails — the deauthorized handler / admin-side disconnect already dealt with the business state.
  - True-concurrency testing in Pest is unreliable; the regression test asserts the structural invariant (lockForUpdate invoked inside `DB::transaction` in `handleAccountUpdated`) by source-inspecting the controller.

---

### D-137 — Connect onboarding return split into `refresh` (GET, sync) / `resume` (POST, admin) / `resumeExpired` (GET, Stripe-refresh_url)

- **Date**: 2026-04-23 (Codex Round 7)
- **Status**: accepted
- **Supersedes**: the single-endpoint design called out in the Plan's "Controllers and routes" section
- **Context**: D-129's fix for incomplete-state onboarding made the controller mint a fresh Stripe Account Link from within the return-URL `refresh()` action. Codex Round 7 flagged two problems that together trapped admins:
  - A pending / incomplete admin returning from Stripe got immediately bounced back into Stripe; there was no way to land on the settings page and disconnect mid-flow.
  - `refresh()` is a GET, so the `billing.writable` middleware passed it through unconditionally — SaaS-lapsed admins could continue opening Stripe Account Links they couldn't actually use.
- **Decision**: Split the single action into three:
  - **`refresh()` (GET)** — Stripe's `return_url`. Pure sync: `syncRowFromStripe()` then redirect to the settings page. Never bounces back into Stripe. The GET-mutation is Stripe-initiated and idempotent; acceptable because Stripe's redirect gives us no choice on verb.
  - **`resume()` (POST)** — admin-triggered "Continue Stripe onboarding". Sits behind the `billing.writable` gate (POST). Syncs state, then verifies the account is still in a resumable status (pending / incomplete), then mints a fresh Account Link and redirects to Stripe.
  - **`resumeExpired()` (GET)** — Stripe's `refresh_url`, only triggered when an Account Link expires mid-flow. Mints a fresh link and redirects back. GET-only because Stripe's redirect is a GET; the admin's action of clicking through Stripe's link-expired page implies consent.
  - `create()`'s Account Link now passes `refresh_url = resume-expired` and `return_url = refresh`. Shared `mintAccountLink()` helper.
  - The React page's "Continue Stripe onboarding" / "Continue in Stripe" CTAs are now `<Form action={resume()}>` POSTs instead of `<Link>` GETs.
- **Consequences**:
  - Pending / incomplete admins returning from Stripe land on the settings page and can choose to disconnect or press Continue. No more trap loop.
  - SaaS-lapsed admins cannot trigger `resume` (POST is gated); the `refresh` and `resume-expired` GETs still work so a user returning from Stripe's own flow isn't blocked, but they land on the settings page where the Resubscribe banner reminds them what's broken.
  - Three new feature tests cover the split: GET `/refresh` syncs and redirects; POST `/resume` mints and bounces; POST `/resume` refuses for active; GET `/resume-expired` mints and bounces. The staff-denied test now covers all five endpoints.

---

### D-138 — `canAcceptOnlinePayments()` folds in `requirements_disabled_reason`

- **Date**: 2026-04-23 (Codex Round 7)
- **Status**: accepted
- **Context**: D-127 added the supported-country gate to the readiness helper, but the helper still checked only the Stripe capability booleans + country. Codex Round 7 flagged that `requirements_disabled_reason` is Stripe's authoritative signal that the account can't process charges/transfers (per Stripe's Connect handling-api-verification docs). Capability flags and disabled_reason can drift under Stripe's eventual consistency — a row with active-looking flags + non-null disabled_reason would still surface as ready via the helper and propagate through the Inertia shared prop and the webhook demotion path.
- **Decision**: Helper now returns `false` when `$row->requirements_disabled_reason !== null`, checked BEFORE the capability booleans. The `StripeConnectedAccount::verificationStatus()` method already treats non-null disabled_reason as the `disabled` bucket; folding the same check into `canAcceptOnlinePayments()` makes the two helpers consistent by construction.
- **Consequences**:
  - Disabled accounts can never be surfaced as ready, even if Stripe's capability flags lag the disabled signal.
  - One new unit test covers the scenario: active capabilities + `requirements_disabled_reason = 'rejected.fraud'` → helper returns false.
  - The Inertia shared prop's `payment_mode_mismatch` flag fires correctly for disabled accounts, driving the dashboard-wide banner.

---

### D-139 — `account.application.deauthorized` only demotes `payment_mode` when no OTHER active connected account remains

- **Date**: 2026-04-23 (Codex Round 8)
- **Status**: accepted
- **Context**: D-128 introduced `withTrashed()` lookup so a retry after a partial-success crash could still find the row and finish demoting. D-136 serialised the Connect webhook under a row lock. Both were correct for the single-row case, but neither handled the reconnect history: once a business has disconnected and reconnected, it can carry one active connected-account row plus one or more soft-deleted historical rows. A late `account.application.deauthorized` for a retired `acct_…` would find the soft-deleted historical row (via `withTrashed`), no-op the delete (already trashed), and then unconditionally demote the business to `offline` — breaking a healthy reconnected business.
- **Decision**: After trashing the matched row (no-op if already trashed), the handler checks `$business->stripeConnectedAccount` (the SoftDeletes-scoped relation). The relation cache is cleared first via `unsetRelation('stripeConnectedAccount')` so the freshly-trashed row doesn't continue to resolve as "active". If any active row remains, the business has reconnected and we do NOT touch `payment_mode`. Only when the relation resolves to null (no active account left) do we force `payment_mode = offline`.
- **Consequences**:
  - Reconnected businesses keep their `payment_mode` when Stripe belatedly deauthorizes their old account.
  - The retry-recovery path (row was trashed by a prior partial-success crash, business still `online`, no reconnect): `stripeConnectedAccount` returns null → demotion proceeds. Correct.
  - Fresh-disconnect path (single active row): handler trashes it → relation returns null → demotion proceeds. Correct.
  - One new feature test covers the reconnect + late-deauthorize scenario: seeds trashed OLD row + active NEW row, fires deauthorize for the OLD acct, asserts business stays at `online` and the new active row is untouched.

---

### D-140 — `ConnectedAccountController::disconnect()` prefers the active row, falls back to trashed only in retry-recovery

- **Date**: 2026-04-23 (Codex Round 8)
- **Status**: accepted
- **Supersedes**: D-131's unconditional `withTrashed()->first()` lookup
- **Context**: D-131 switched `disconnect()` to `stripeConnectedAccount()->withTrashed()->first()` so a retry after a partial-success crash could still find a trashed row and finish demoting. Codex Round 8 flagged the same historical-row problem as D-139: once the table carries multiple soft-deleted history rows alongside an active one, `withTrashed()->first()` returns one of them — NOT guaranteed to be the active row. The trashed-noop branch then "disconnects" a historical row while the REAL active Stripe account stays behind, leaving the business split-brained.
- **Decision**: Prefer `$business->stripeConnectedAccount` (active only) for the primary lookup. Fall back to `stripeConnectedAccount()->withTrashed()->first()` ONLY when the active relation resolves to null — the retry-recovery path D-131 preserved. The transaction body is otherwise unchanged: trashed-noop on the row, force payment_mode=offline.
- **Consequences**:
  - A business with reconnect history (multiple trashed historical rows + one active row) disconnects the ACTIVE row.
  - The retry-recovery path still works: partial-success crash leaves a trashed row + online business → active relation is null → fall back to `withTrashed()->first()` → pick any trashed row (order-insensitive; the trashed-noop branch doesn't care which) → demote business.
  - One new feature test: seeds an OLD trashed row + a NEW active row, clicks Disconnect, asserts the NEW row is the one that gets trashed and the OLD row stays as-is.

---

### D-141 — Canonical `businesses.country` column gates Stripe Express onboarding

- **Date**: 2026-04-23 (Codex Round 9)
- **Status**: accepted
- **Supersedes**: D-115's "always use `config('payments.default_onboarding_country')`" stance
- **Context**: D-115 punted the onboarding-country question by always passing `config('payments.default_onboarding_country')` (MVP value `'CH'`) into `stripe.accounts.create()`. D-121 added a singleton-supported-countries guard. Neither gave us a code-level guarantee that the Business onboarding Stripe Connect is actually in that country — `Business.address` is freeform and not parsed. Codex Round 9 flagged that a non-CH tenant would still get an immutable CH Stripe account under this design.
- **Decision**: Add a canonical `businesses.country` column (ISO-3166-1 alpha-2, nullable). Migration backfills `'CH'` for all pre-existing rows (MVP is Swiss-only pre-launch). `BusinessFactory` defaults to `'CH'`. `ConnectedAccountController::create()` reads `$business->country`, verifies it is in `config('payments.supported_countries')`, and refuses onboarding when it's null or unsupported. `resolveOnboardingCountry()` now takes a `Business` argument and returns `$business->country` if eligible, `null` otherwise.
- **Consequences**:
  - Stripe never gets a hardcoded-default country again. A business whose country is not in the supported set is refused onboarding with a clear admin error, instead of being stuck with an immutable wrong-jurisdiction Stripe account.
  - Post-launch business onboarding will collect the country explicitly — BACKLOG entry "Collect country during business onboarding" tracks the UX. Pre-launch the backfill value is sufficient.
  - Three new feature tests cover the gate: country=DE while supported=[CH] is refused; supported=[CH,DE] accepts DE; country=null is refused. The previous "default_onboarding_country != supported singleton" test is deleted (the config-singleton check is gone — Business.country is now the source of truth).
  - Session 5's Settings → Booking gate inherits the country check automatically through `canAcceptOnlinePayments()` (which D-127 folded the supported-countries check into; D-138 also folded in disabled_reason). The chain is coherent.

---

### D-142 — Stripe return_url + refresh_url are signed temporary URLs bound to the `acct_…`

- **Date**: 2026-04-23 (Codex Round 9)
- **Status**: accepted
- **Context**: D-137 split the Connect return flow into `refresh()` (return_url), `resume()` (POST admin-initiated), `resumeExpired()` (refresh_url). Codex Round 9 flagged that `resumeExpired()` was an unsigned GET tied solely to `tenant()->business()`: (a) any cross-site GET could trigger a fresh Account Link mint; (b) if the admin's session is pinned to a different tenant at return time, the action runs against the wrong business; (c) the `billing.writable` middleware passes safe GET verbs through unconditionally, so a SaaS-lapsed admin could still use the endpoint.
- **Decision**: `mintAccountLink()` now passes `URL::temporarySignedRoute(...)` URLs for both `return_url` and `refresh_url`, carrying the `account` (acct_…) as a query parameter. A 24h TTL is well past Stripe's own Account Link TTL (minutes). Routes `settings.connected-account.refresh` and `settings.connected-account.resume-expired` carry the `signed` middleware. Inside the controller, a new `resolveSignedAccountRow()` helper looks up the row by the signed `account` param (via `withTrashed`) and verifies the current tenant's business owns it — 403 otherwise. Cross-site GETs without a valid signature are rejected by the middleware before the controller runs; cross-tenant signed URLs are rejected at the tenant-match check.
- **Consequences**:
  - Cross-site GET triggering is eliminated — the URL is a bearer token that riservo self-minted and only Stripe received.
  - Wrong-tenant returns (admin tab-switched mid-flow) are rejected with 403 rather than silently running on the wrong business. The admin must switch back to the correct tenant.
  - Two new feature tests: unsigned GET to `/refresh` is 403 (proves the middleware is on); signed URL for another tenant's account is 403 (proves the controller guard).
  - The admin-initiated POST `/resume` stays unsigned — it's a normal admin form submit, already gated by `role:admin` + `billing.writable`.
  - The Stripe dashboard has no issue with a long signed URL in `refresh_url` / `return_url` — Stripe treats them as opaque. Test-only concern: Pest generates the signed URL via the same `URL::temporarySignedRoute` call (shared `signedConnectedAccountUrl` helper), so the test path matches production.

---

### D-143 — `RegisterController::store()` seeds `Business.country` from `config('payments.default_onboarding_country')`

- **Date**: 2026-04-23 (Codex Round 10)
- **Status**: accepted
- **Context**: D-141 moved the Stripe Express country gate to a canonical `Business.country` column and refuses onboarding when it's null or unsupported. The migration backfilled `'CH'` for pre-existing rows, but `RegisterController::store()` still called `Business::create([...])` with only `name` + `slug` — so every post-migration signup landed with `country = null` and would be permanently locked out of Stripe Connect onboarding. Codex Round 10 flagged this as a high-severity regression: the gate was load-bearing but the write path that feeds it was never updated.
- **Decision**: `RegisterController::store()` now passes `'country' => config('payments.default_onboarding_country')` (MVP value `'CH'`) when creating the Business. The seed tracks the same config key that Session 1's onboarding flow originally used as a default (pre-D-141), so extending to another country is a config flip. A future business-onboarding step (BACKLOG entry "Collect country during business onboarding") will let the admin override the seed value per-business before they ever reach Stripe.
- **Consequences**:
  - Fresh signups can reach Connected Account onboarding without manual DB intervention.
  - One new feature test in `RegisterTest.php` asserts the column is seeded and the value is in `config('payments.supported_countries')` so the chain with D-141 is coherent.
  - If `default_onboarding_country` is ever set to a value NOT in `supported_countries`, new signups would be onboarded, then refused at the Stripe gate. That's a misconfiguration — `config/payments.php` documents the invariant (the default must be in the supported set). The test above additionally fails fast in that case.

---

### D-144 — Initial `ConnectedAccountController::create()` mints Stripe Account Links via `mintAccountLink()` (signed URLs)

- **Date**: 2026-04-23 (Codex Round 10)
- **Status**: accepted
- **Context**: D-142 wrapped `refresh()` and `resumeExpired()` routes with `signed` middleware and added a tenant-match check that resolves the row from a signed `account` query param. `mintAccountLink()` was updated to produce matching signed URLs. But `create()` — the FIRST onboarding call, which receives the Stripe response carrying the very first Account Link — still called `$this->stripe->accountLinks->create(...)` inline with plain `route('...')` URLs for `refresh_url` and `return_url`. Stripe returned Administrative admins to those endpoints with no signature and no `account` query param, so the very first KYC return or link-expired retry hit 403. Codex Round 10 caught this as a high-severity blocker — onboarding could not complete at all.
- **Decision**: `create()` replaces the inline `accountLinks->create([...])` call with `$link = $this->mintAccountLink($row)`. Single source of truth for the signed-URL shape. The extracted helper already carries the `URL::temporarySignedRoute(...)` logic + the `account` query param + the 24h TTL that D-142 specified; `create()` now inherits that contract instead of having its own copy.
- **Consequences**:
  - First-time KYC return works: Stripe redirects to a signed URL carrying the acct_id, `signed` middleware verifies signature + TTL, the controller verifies tenant ownership, the sync proceeds.
  - Link-expired mid-flow during first onboarding also works: Stripe hits the signed `refresh_url` → `resumeExpired()` runs.
  - `FakeStripeClient::mockAccountLinkCreate` gained an optional `expectedSignedAccountParam` argument that enforces both `signature=` and `account={acct_id}` on the URLs passed to `accountLinks->create`. A regression where the controller reverts to plain `route(...)` URLs fails the Mockery `withArgs` matcher with a clear "method not expected" diagnostic instead of silently shipping a 403.
  - One new feature test in `ConnectedAccountControllerTest.php` wires the assertion into the `create()` happy path — the matcher proves the URLs are signed and carry the expected acct_id.

---

### D-145 — `resumeExpired()` refuses read-only businesses even though GETs pass `billing.writable`

- **Date**: 2026-04-23 (Codex Round 10)
- **Status**: accepted
- **Context**: D-090 lets safe verbs (GET / HEAD / OPTIONS) through `billing.writable` unconditionally so a SaaS-lapsed admin can still read the dashboard and find the Resubscribe CTA. D-116 forbids a lapsed business from opening new payment surfaces. `resumeExpired()` is a GET (Stripe's `refresh_url` is a GET redirect) but its job is to mint a fresh Stripe Account Link — a NEW payment surface. The signed-URL TTL of 24h (D-142) meant an admin who saved the link while subscribed could keep calling it after the subscription lapsed and generate fresh onboarding URLs. Codex Round 10 flagged this as a billing-gate bypass.
- **Decision**: At the top of `resumeExpired()` — after the signed-URL + tenant-match guard but BEFORE any Stripe call — check `tenant()->business()?->canWrite()`. On false, redirect to `route('settings.billing')` with the same `__('Your subscription has ended. Please resubscribe to continue.')` flash that `EnsureBusinessCanWrite` produces, preserving the UX consistency. No Stripe call is made; the lapsed admin is rerouted to the billing page (which is always reachable per D-090).
- **Consequences**:
  - A lapsed admin can still LOAD the billing page, but can no longer open new Stripe onboarding surfaces via saved signed URLs.
  - `refresh()` does not need the same guard — it's a pure sync handler that pulls Stripe state into a local row. It doesn't mint a new payment surface; letting a lapsed admin land on the settings page after completing KYC is fine because the next mutation (subscribe / enable online / etc.) will hit `billing.writable` anyway.
  - `resume()` (POST) is already covered by `billing.writable` because POST is not a safe verb.
  - One new feature test: a canceled-subscription business hits a pre-minted signed `/resume-expired` URL and gets redirected to `/dashboard/settings/billing` with the standard flash. No Stripe call is stubbed; a regression where the guard is skipped would try the unstubbed `accountLinks->create` and explode with a Mockery "method not expected" error — structural enforcement, not a matcher we could accidentally tune away.

---

### D-146 — Signed `refresh` / `resume-expired` handlers treat soft-deleted rows as revoked

- **Date**: 2026-04-23 (Codex Round 11)
- **Status**: accepted
- **Context**: D-142 added 24h signed URLs for Stripe's `return_url` and `refresh_url`. Locked roadmap decision #36 retains `stripe_account_id` on the soft-deleted row so Session 2b's late-webhook refund path can still issue refunds against the original account after disconnect. But `resolveSignedAccountRow()` uses `withTrashed()` (correctly, for the cross-tenant-replay defense in `create()` per D-134), and neither `refresh()` nor `resumeExpired()` checked `$row->trashed()` after resolution. A pre-disconnect signed URL continued to resolve for 24h — an admin who clicked Disconnect but then completed Stripe KYC in the Stripe tab would revive local sync state via `refresh()`, or mint a fresh onboarding Account Link via `resumeExpired()`, for a row the product presents as disconnected. The Stripe acct_ still exists on Stripe's side (we kept it), so the reconcile cost is non-trivial: a disconnected tenant ends up with a live Stripe onboarding flow they can't see in riservo.
- **Decision**: Both `refresh()` and `resumeExpired()` check `if ($row->trashed())` after `resolveSignedAccountRow()` and redirect to `route('settings.connected-account')` with a clear admin flash: "This Stripe account has been disconnected. Start a new onboarding from the settings page if you want to accept online payments again." No `accounts.retrieve` call and no `accountLinks->create` call — the signed URL is treated as revoked from the moment the row transitions to trashed. The check is NOT moved into `resolveSignedAccountRow()` because that helper is reused in paths (`create()`'s cross-tenant-replay defense) that MUST see trashed rows.
- **Consequences**:
  - Disconnect is final for signed URLs: stale pre-disconnect URLs no longer revive local state or mint fresh onboarding surfaces.
  - Reconnect flow still works: `create()` generates a NEW row with a fresh `acct_…` (locked decision #36 + D-125's per-attempt nonce) and mints a fresh signed URL bound to the new acct_. The old trashed row's signed URL stays revoked.
  - Cross-tenant-replay defense in `create()` is unaffected — it looks up trashed rows directly through `StripeConnectedAccount::withTrashed()`, not through `resolveSignedAccountRow()`.
  - Two new feature tests register NO FakeStripeClient mocks: a trashed-row request on either handler triggers the guard and redirects. A regression that skips the guard would hit the unstubbed Stripe call and Mockery would surface it as "method not expected" — structural enforcement, not a matcher we could accidentally tune away.

---

### D-147 — Signed Connect handlers authorise via the row's business, not the session-pinned tenant

- **Date**: 2026-04-23 (Codex Round 12)
- **Status**: accepted
- **Context**: D-142's `resolveSignedAccountRow()` verified that `tenant()->business()->id === $row->business_id`, 403-ing otherwise. `ResolveTenantContext` rule 2 re-pins a user's session to their OLDEST active membership on login. A multi-business admin whose session expired mid-Stripe-onboarding therefore lands on their oldest membership after re-auth, and a legitimate signed URL for a DIFFERENT business would 403 — the admin has no UI path to recover the Stripe state for the business they were actually onboarding. The signed URL carried the acct_ but not the business_id, so the handler couldn't even tell it was in the wrong tenant.
- **Decision**: `resolveSignedAccountRow()` no longer reads `tenant()->business()`. Instead it:
  1. Loads the signed `StripeConnectedAccount` row (`withTrashed()`).
  2. Looks up the authenticated user's `BusinessMember` for `$row->business_id` (active only — `whereNull('deleted_at')` per D-079's pivot-softdeletes contract).
  3. Verifies the role is `Admin` (the outer `role:admin` middleware gate is preserved; this adds the ROW'S business check on top).
  4. Loads the Business and re-pins BOTH `session('current_business_id')` AND `app(TenantContext::class)` so downstream `tenant()` reads, Inertia shared props, and the response redirect all reflect the correct business for the remainder of the request.
- **Consequences**:
  - Multi-business admins whose session got re-pinned wrong can complete Stripe onboarding without a manual tenant switch.
  - The `role:admin` middleware still runs BEFORE the controller against the ORIGINAL (pre-re-pin) session tenant. A user who is staff-only on the pre-re-pin tenant and admin of the signed row's tenant STILL 403s at middleware — a known false-negative that D-147 does not solve. A proper fix is a middleware that re-pins session from the signed URL BEFORE `role:admin` runs; captured in BACKLOG ("Pre-role-middleware signed-URL session pinner") for post-MVP.
  - Tests: (a) admin of both businesses + session pinned wrong → 302 redirect + session re-pinned; (b) staff-only membership on signed row's business → 403.
  - Cross-tenant-replay defence in `create()` is unchanged — it looks up trashed rows directly via `StripeConnectedAccount::withTrashed()` and never calls `resolveSignedAccountRow()`.

---

### D-148 — `refresh` / `resume` / `resumeExpired` / `disconnect` serialise row access via `DB::transaction` + `lockForUpdate`

- **Date**: 2026-04-23 (Codex Round 12)
- **Status**: accepted
- **Context**: D-146 added a `$row->trashed()` guard at the top of `refresh()` and `resumeExpired()` to protect the disconnect-finality invariant. But the check was pre-transaction, point-in-time — a concurrent `disconnect()` committing BETWEEN the check and the Stripe call (typically `accounts.retrieve` or `accountLinks->create`) would still fire the Stripe side and either sync state onto the soon-to-be-trashed row or mint a fresh Account Link for a disconnected account. TOCTOU. `resume()` and `disconnect()` had related windows through the SoftDeletes-scoped relation being read outside a transaction.
- **Decision**: all four handlers wrap their state-mutating body in `DB::transaction` + `lockForUpdate` on the row, re-read + re-check `$locked->trashed()` inside the lock, and only then call Stripe. `resume()` and `resumeExpired()` share a `runResumeInLock(StripeConnectedAccount $row, string $settledOutcome)` helper for DRY — the `settledOutcome` arg differentiates the resume flow (not-resumable → error flash) from the link-expiry flow (settled → silent redirect back). `disconnect()`'s existing transaction was extended with an inside-lock re-read so any concurrent resume/refresh either observes the pre-disconnect row (they locked first) or the trashed row (we locked first), never the in-between.
- **Consequences**:
  - The disconnect-finality invariant D-146 set is now race-proof: no matter the interleaving, a disconnected row never sees a subsequent sync or mint.
  - Stripe API calls happen INSIDE a DB transaction. This holds a connection for the duration of the call (typically ~200ms). For the traffic profile of a Connect onboarding handler (<1 req/s per account) this is acceptable and mirrors D-136's accepted trade-off for `account.updated`. If this becomes a hotspot post-launch, swap to an advisory lock or state-machine sequencing.
  - A structural regression test inspects the controller source, asserting each public handler either inlines `DB::transaction + lockForUpdate + trashed()` re-check OR delegates to `runResumeInLock()`, and the helper itself carries all three invariants. Concurrency is not reliably simulable in Pest without a multi-connection harness, so the structural check is the belt-and-braces; a regression that removes the lock fails the test loudly.
  - The `runResumeInLock()` helper uses a by-reference capture pattern (`&$outcome`, `&$url`) instead of returning an `array{outcome, url?}` from the closure — PHPStan level-5's generic inference on `DB::transaction`'s callback couldn't resolve the array-shape return type. By-reference keeps the helper's PUBLIC contract clean (returns `array{outcome: string, url?: string}`) while working around the static-analysis quirk.

---

### D-149 — Unknown-account `account.*` webhook events return retryable `503` instead of `200`

- **Date**: 2026-04-23 (Codex Round 12)
- **Status**: accepted
- **Context**: `handleAccountUpdated` and `handleAccountDeauthorized` used to return `200 Unknown account.` when the local row wasn't found. The D-092 cache-dedup trait caches 2xx responses for 24h, so Stripe's retries stopped. If the webhook arrived before our `create()` transaction committed — the acct_ exists on Stripe's side at T, our row doesn't commit until T+ms — the local row would be stuck on the stale `accounts.create` snapshot until a manual admin refresh or another Stripe-side change arrived. Narrow but real; the race window is the DB commit latency, typically <50ms but non-zero.
- **Decision**: unknown-account branches return `503 Service Unavailable` with a `Retry-After: 60` header. The dedup trait only caches 2xx (D-092) so Stripe retries re-enter the handler; by the next retry our local insert has almost always committed. `handleAccountUpdated`'s pre-check now uses `withTrashed()` so a TRASHED row takes the no-op branch (the disconnect decision wins) rather than re-entering the 503 retry loop. Disputes (`handleDisputeEvent`) keep the existing `200 + Log::critical` for unknown accounts — disputes arrive days/weeks after charges, so the race can't realistically fire; the critical log is the reconciliation signal (D-123).
- **Consequences**:
  - `account.*` events that race our create+insert transaction resolve cleanly via Stripe retry; no manual admin action required.
  - A genuinely orphan `acct_` (bug: we called `accounts.create` but never inserted locally) triggers infinite Stripe retries with `Log::warning` each time. That's a noisy-but-correct outcome versus a silent-but-wrong 200+cache; the warnings fire alerts, operator investigates.
  - Trashed rows receiving new `account.updated` deliveries are a no-op — Stripe-side capability drift against a disconnected account is irrelevant; the local row already reflects the disconnect decision.
  - Two regression tests assert 503 + `Retry-After: 60` + cache NOT populated (via `Cache::has('stripe:connect:event:'.$eventId)` against an explicit event id). A regression that reverts to 200 would silently repopulate the cache and the `Cache::has` check would fail.

---

### D-150 — `verificationStatus()` returns `unsupported_market` when country drifts outside `supported_countries`

- **Date**: 2026-04-23 (Codex Round 13)
- **Status**: accepted
- **Context**: D-127 folded the `config('payments.supported_countries')` check into `Business::canAcceptOnlinePayments()`, so the BACKEND refuses online payments for a row whose country is not in the supported set. But `StripeConnectedAccount::verificationStatus()` — the string the UI consumes (connected-account page status branch, refresh-flash `match`, Inertia shared prop `auth.business.connected_account.status`) — checked only Stripe capabilities + `details_submitted` + `requirements_disabled_reason`. On a country drift (operator tightens `PAYMENTS_SUPPORTED_COUNTRIES` env, or beta coverage expands then retracts) the admin saw "Verified / Active" while the backend silently blocked online payments, with no explanation available.
- **Decision**: add an explicit `'unsupported_market'` state to `verificationStatus()`. Returned when Stripe caps are fully on (`charges_enabled && payouts_enabled && details_submitted`) AND `requirements_disabled_reason === null` BUT `country` is not in `config('payments.supported_countries')`. The `disabled` / `incomplete` / `pending` arms are unchanged — they're independent of the market check. Frontend `AccountState` status union picks up the new variant; a new `<UnsupportedMarket>` panel renders a warning alert + country code + Contact support + Disconnect CTA (shape matches `<Disabled>` for UX consistency). The refresh-flash `match` in `ConnectedAccountController::refresh()` gains an `'unsupported_market'` arm with dedicated copy. `resume()` / `resumeExpired()`'s in-lock `verificationStatus()` check naturally treats `'unsupported_market'` as not-resumable (it's not in the `['pending', 'incomplete']` allowlist) — correct, because minting a fresh Account Link doesn't help an admin whose country is out of the supported set.
- **Consequences**:
  - UI status and backend eligibility are now coherent: a Stripe-verified row whose country is not supported shows a clear "not available for your country yet" message instead of a misleading "Verified" chip.
  - The state is computed (not persisted), so re-expanding `supported_countries` flips the same row back to `'active'` without a DB write. The unit test "returns active once country enters supported_countries again" proves the gate is truly config-driven.
  - `canAcceptOnlinePayments()` (D-127) already returned `false` for this case, so the Session 5 UI gate, the Inertia shared prop `can_accept_online_payments` flag, and the Settings → Booking `payment_mode` validator (D-130) were all already coherent; D-150 only fixes the COMMUNICATION layer.
  - Three regression tests: (a) unit — caps-on + mismatched country returns `'unsupported_market'`; (b) unit — forward-looking flip back to `'active'` after config expansion; (c) feature — refresh flow surfaces the new state in session flash + Inertia prop + `can_accept_online_payments = false`.

---

### D-119 — Pre-add all three `payment.*` cases to `PendingActionType` in Session 1

- **Date**: 2026-04-23
- **Status**: accepted
- **Context**: Per locked roadmap decision #44, Sessions 2b and 3 are the writers for `payment.dispute_opened`, `payment.refund_failed`, and `payment.cancelled_after_payment` Pending Actions. Adding the enum cases when their writers land is one option; pre-adding them in Session 1 (alongside the table generalisation) is the other.
- **Decision**: Pre-add all three cases to `PendingActionType` now. The matching `PendingActionType::calendarValues()` static helper excludes them, so calendar-aware readers (D-113) stay correct. Sessions 2b / 3 land their writers without a cross-session enum edit.
- **Consequences**: Negligible cost, removes a coupling between schema-touching and code-only sessions. The unused enum cases in Session 1 are harmless — PHPStan's exhaustiveness checks on `match` statements over `PendingActionType` did surface the addition (forced `CalendarPendingActionController` to add a `default` arm + a calendar-bucket guard at the top), which is exactly the kind of compile-time signal we want.
