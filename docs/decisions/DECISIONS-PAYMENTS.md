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

---

### D-151 — `CheckoutPromoter` is the single source of truth for Checkout-session → paid-booking promotion

- **Date**: 2026-04-23 (PAYMENTS Session 2a)
- **Status**: accepted
- **Context**: Two code paths trigger promotion of a `pending + awaiting_payment` booking to `confirmed/pending + paid`: (a) the `checkout.session.completed` (and `.async_payment_succeeded`) webhook arm on `StripeConnectWebhookController`, (b) the synchronous `checkout.sessions.retrieve` performed by `BookingPaymentReturnController::success` per locked roadmap decision #32. Keeping two copies of the DB-state guard, the lockForUpdate, the notification dispatch, and the manual-confirmation `ConfirmationMode` branch would guarantee drift on maintenance.
- **Decision**: New service `App\Services\Payments\CheckoutPromoter` with one public method `promote(Booking $booking, Stripe\Checkout\Session $session): 'paid'|'already_paid'|'not_paid'`. Both callers invoke it. The service wraps the state transition in `DB::transaction` + `lockForUpdate` on the booking row (same concurrency shape D-148 uses on `ConnectedAccountController`), performs the outcome-level `PaymentStatus::Paid` guard inside the lock (locked roadmap decision #33), and dispatches the customer + staff notifications in the `confirmed`-branch or the paid-awaiting-confirmation branch (locked decision #29) depending on the Business's `confirmation_mode`. Return shape is deliberately scalar — PHPStan level-5 cannot infer array-shape returns from `DB::transaction` callbacks (same workaround D-148 accepted).
- **Consequences**:
  - Webhook-vs-success-page races converge: whoever acquires the row lock first promotes; the other path re-reads and no-ops at the guard.
  - A structural regression test (`tests/Unit/Services/Payments/CheckoutPromoterStructuralTest.php`) inspects the controller source for the `DB::transaction`, `lockForUpdate`, and `PaymentStatus::Paid` tokens so a maintenance edit that removes any of them fails loudly instead of surfacing in prod.
  - Session 2b's reaper (`bookings:expire-unpaid`) per locked decision #31's pre-flight retrieve will call this same service — no duplicate promotion logic to write or maintain.
  - Future refund / dispute handlers follow the same shape: outcome-level guard first, transaction-scoped DB mutations, notifications in the `afterCommit`-adjacent branch.

---

### D-152 — Booking `currency` + `paid_amount_cents` captured at booking creation, overwritten by Stripe on promotion

- **Date**: 2026-04-23 (PAYMENTS Session 2a)
- **Status**: accepted
- **Context**: Locked decision #42 (refunds preserve booking currency, not account default) and #37 (refunds read `paid_amount_cents`, never `Service.price`) both require these two columns to be authoritative and populated BEFORE any Stripe event arrives. Two candidate moments to populate: (a) at booking creation from the connected account's `default_currency` + the service price, (b) at webhook promotion from `$session->currency` + `$session->amount_total`. Session 2b's reaper needs the columns populated at creation; Stripe is authoritative on what actually settled.
- **Decision**: Write both columns at booking creation. `PublicBookingController::store`'s online-payment branch captures `paid_amount_cents = (int) round($service->price * 100)` and `currency = $connectedAccount->default_currency ?? 'chf'`. At webhook / success-page promotion, `CheckoutPromoter::promote` compares `$session->amount_total` and `$session->currency` against the captured values; on any mismatch it logs a critical and overwrites with Stripe's figures (Stripe is authoritative). Both columns are NOT NULL once the online-payment branch runs.
- **Consequences**:
  - Session 2b's reaper can filter on `paid_amount_cents` without branching on "did the webhook run yet?".
  - Session 3's refund path reads `paid_amount_cents` per decision #37 with no null-handling needed for awaiting-payment or paid rows.
  - A mismatch during promotion fires a critical log — diagnostic signal if Stripe ever returns a different amount than the line item requested (should be impossible for a fixed-amount Checkout session, but the log is our guardrail).

---

### D-153 — Booking success URL uses the cancellation token as bearer + cross-checks the Checkout session id

- **Date**: 2026-04-23 (PAYMENTS Session 2a)
- **Status**: accepted
- **Context**: Stripe's `success_url` is a client-visible URL. The customer's return to riservo must identify the booking AND verify the return is for that specific Checkout session (not a replayed URL with a swapped `session_id`). Two auth schemes considered: (a) mint a fresh signed URL à la D-142's Connect return-URL, (b) reuse the existing `cancellation_token` that already authenticates `bookings.show` and `bookings.cancel`.
- **Decision**: The success URL is `/bookings/{cancellation_token}/payment-success?session_id={CHECKOUT_SESSION_ID}` (Stripe's server-side template placeholder is replaced at redirect time). The token provides access-level auth (who can view this booking); the controller additionally verifies `$request->query('session_id') === $booking->stripe_checkout_session_id` and redirects to `bookings.show` with a neutral flash on mismatch (logged as a warning). The `cancel_url` mirrors the shape for consistency. Rate limiter: `throttle:booking-api`.
- **Consequences**:
  - One auth token per booking, reused across the full customer-facing surface (`show`, `cancel`, `payment-success`, `payment-cancel`).
  - A hostile actor swapping the `session_id` query param cannot redeem someone else's Checkout session — the cross-check rejects before any Stripe API call.
  - The URL is shareable (customer saves the email link and reopens later) without minting a second token scheme.

---

### D-154 — TWINT inclusion driven by `config('payments.twint_countries')`, not by capability introspection

- **Date**: 2026-04-23 (PAYMENTS Session 2a)
- **Status**: accepted
- **Context**: Locked decision #3 makes TWINT mandatory (not opt-in) for CH-located connected accounts. Two implementations considered: (a) query Stripe for the account's enabled payment-method capabilities and include TWINT when present, (b) drive inclusion off the config key `twint_countries` introduced in Session 1 (D-112). Option (a) adds an API call per Checkout creation; option (b) aligns with the roadmap's "single config switch" ethos (decision #43).
- **Decision**: `CheckoutSessionFactory::paymentMethodTypes` returns `['card', 'twint']` when the account's country is in `config('payments.twint_countries')`, `['card']` otherwise. No Stripe introspection. The MVP config values (`['CH']`) make the branches coincident with `supported_countries`; the fast-follow to IT/DE/FR/AT/LI becomes a config flip with documented card-only fallback for non-TWINT markets (the card-only branch is unreachable in MVP today but wired for this reason).
- **Consequences**:
  - Zero extra API round-trips. A newly-added TWINT market becomes live on a config flip plus ops-side verification.
  - The card-only branch has its own unit-test (`CheckoutSessionFactoryTest > create falls back to card-only when the country is not in twint_countries`) to guarantee the seam stays open.
  - The roadmap's non-CH fast-follow plan remains valid: flip `PAYMENTS_SUPPORTED_COUNTRIES` + verify tax/locale assumptions per decision #11 and #39.

---

### D-156 — `CheckoutPromoter` fails closed on session-id / amount / currency mismatch (supersedes D-152's log-and-overwrite stance)

- **Date**: 2026-04-23 (Codex adversarial review, Round 1, F2)
- **Status**: accepted
- **Supersedes**: D-152's "log and overwrite with Stripe's figures" stance on amount / currency divergence
- **Context**: D-152 chose "log critical and persist Stripe's figures" when the Stripe session's `amount_total` / `currency` diverged from the booking's captured values at promotion time, treating Stripe as authoritative on what actually settled. Codex Round 1 flagged two problems this created: (a) `StripeConnectWebhookController::handleCheckoutSessionCompleted` looked bookings up by `client_reference_id` and cross-checked only the connected account — a second Stripe integration on the same account (or a manual Stripe-dashboard session whose `client_reference_id` reused a riservo booking id) could reconcile a riservo booking as paid; (b) the log-and-overwrite stance on amount/currency actively confirmed any attack that came through. For a fixed-`unit_amount` Checkout session the values MUST match; a mismatch is pathological (coding bug, Stripe-side error, or hostile reconciliation). The captured columns are also load-bearing for Session 3's refund path (locked decisions #37 / #42); silently overwriting them is worse than failing closed.
- **Decision**: `CheckoutPromoter::promote` (the shared service on the webhook AND success-page paths) now:
  1. Rejects with `'mismatch'` outcome + critical log when `$session->id !== $booking->stripe_checkout_session_id` — before any DB write, before the lock. Placing the guard on the shared service means both callers inherit it; the success-page's query-param cross-check (D-153) and the webhook's new session-id guard now converge.
  2. Rejects with `'mismatch'` outcome + critical log when `$session->amount_total` diverges from the booking's captured `paid_amount_cents`, or when `$session->currency` diverges from the booking's captured `currency`. These checks happen inside the row lock, so a legitimate concurrent promotion (webhook vs success-page) can't sneak through.
  3. Return union expands to `'paid' | 'already_paid' | 'not_paid' | 'mismatch'`. The webhook handler 200s regardless (Stripe must NOT retry a mismatch — a retry would re-fire the same mismatch); the success-page controller renders a neutral "we'll follow up" flash instead of the success confirmation.
- **Consequences**:
  - Cross-integration `client_reference_id` collisions on the same connected account can no longer promote a riservo booking.
  - Amount / currency divergence forces manual operator reconciliation via the critical log rather than silent corruption of the refund-load-bearing columns.
  - Three new regression tests in `tests/Feature/Payments/CheckoutSessionCompletedWebhookTest.php` cover the session-id, amount, and currency mismatch cases (each asserts the booking stays `AwaitingPayment` + no notifications fire).
  - D-152's "capture at booking creation" half (currency + paid_amount_cents snapshot at Checkout create time) is kept — that half was load-bearing for Session 2b's reaper anyway. Only the "overwrite on divergence" half is superseded.

---

### D-157 — Customer-side cancel refuses `payment_status = Paid` bookings until Session 3 ships `RefundService`

- **Date**: 2026-04-23 (Codex adversarial review, Round 1, F3)
- **Status**: accepted
- **Context**: `BookingManagementController::cancel` keys on booking `status` + cancellation window only; the `canCancel()` helper returns true for Confirmed / Pending bookings regardless of `payment_status`. After Session 2a the factory can land bookings at `payment_status = Paid`, which still satisfies `canCancel()`. A customer holding the `cancellation_token` can therefore transition a paid booking to `Cancelled` with no refund — leaving `cancelled + paid` rows where the slot is freed but funds stay on the connected account. Session 3 per locked decisions #15 / #16 will wire the in-window automatic full refund; until that ships, the system has no path to refund money the customer just released the slot for.
- **Decision**: Before the state mutation (after the existing status + window checks), `BookingManagementController::cancel` now refuses with an error flash `Please contact :business to cancel this booking — refunds are handled directly with the business for now.` when `$booking->payment_status === PaymentStatus::Paid`. The `can_cancel` Inertia prop on `bookings/show` stays true so the existing Cancel button still renders; clicking it surfaces the error flash — matching the existing cancellation-window-exceeded UX shape. No refund is attempted; no status transition; no customer notification.
- **Consequences**:
  - No `cancelled + paid` rows can be produced by the customer-side path in Sessions 2a → 2b.
  - Session 3 relaxes the guard in a two-line diff: in-window + paid → dispatch `RefundService::refund($booking, null, 'customer-requested')` before the status transition; out-of-window + paid → existing block stays with "contact the business" copy. The rest of Session 3's refund wiring lands on a clean foundation.
  - One new regression test in `tests/Feature/Booking/BookingManagementTest.php` covers the block (paid booking + `/bookings/{token}/cancel` → status stays Confirmed, payment_status stays Paid, error flash).
  - Admin-side cancellation (dashboard) is unaffected — admins can always cancel. Session 3 will wire the automatic-refund path for admin cancels of paid bookings per locked decision #17.

---

### D-158 — Minting connected-account id pinned onto the booking at creation time

- **Date**: 2026-04-23 (Codex native review, Round 2, P2)
- **Status**: accepted
- **Context**: Round 1's webhook + success-page cross-account guards read the expected account id via `StripeConnectedAccount::withTrashed()->where('business_id', $booking->business_id)->value('stripe_account_id')`. For a business with disconnect+reconnect history — one active row plus one or more trashed historical rows — `value()` returns the first match without guaranteed ordering. Legitimate late webhooks for EITHER the old trashed account or the new active account could be rejected as cross-account mismatches, AND the success-page's `checkout.sessions.retrieve` could be targeted at the wrong account. The booking needs to remember exactly which account minted its Checkout session.
- **Decision**: New column `bookings.stripe_connected_account_id` (nullable string). `PublicBookingController::store`'s online-payment branch writes `$connectedAccount->stripe_account_id` onto the booking inside the creation transaction, alongside `stripe_checkout_session_id`. `StripeConnectWebhookController::handleCheckoutSessionCompleted` and `BookingPaymentReturnController::success` read `$booking->stripe_connected_account_id` directly — fail closed with a critical log if null. `BookingFactory`'s `awaitingPayment()` + `paid()` states populate the column with random `acct_test_…` values so legacy tests keep a matching id; tests that need a specific id override via the factory array.
- **Consequences**:
  - Reconnect history no longer makes the cross-account guard non-deterministic. A new regression test in `OnlinePaymentCheckoutTest` seeds both a trashed historical row and the active row, confirms the booking's pinned id matches the active (minting) account.
  - The `withTrashed()` lookup on the business + account relation is retained in Session 1's onboarding paths (where it's load-bearing per D-139 / D-146 / D-148) — Round 2 only replaces its usage in the two checkout-promotion paths.
  - Session 2b's reaper pre-flight retrieve per locked roadmap decision #31 reads the same pinned id — no new lookup shape needed.
  - The existing "disconnect race" test keeps passing because the booking's pinned id survives the account row's soft-delete.

---

### D-159 — Paid-cancel guards extended to Customer + Dashboard endpoints

- **Date**: 2026-04-23 (Codex native review, Round 2, P1)
- **Status**: accepted
- **Supersedes**: partial scope of D-157 (which only covered `BookingManagementController::cancel`)
- **Context**: D-157 (Round 1) blocked `payment_status = Paid` cancellation on the token-based `BookingManagementController::cancel` endpoint, but two other paths could still produce `cancelled + paid` rows: the authenticated customer path `Customer\BookingController::cancel` (customers with an account cancelling from `/my-bookings`) and the staff/admin path `Dashboard\BookingController::updateStatus` (admin transitioning a booking to Cancelled from the dashboard). Session 3's `RefundService` isn't here yet.
- **Decision**: Both paths now refuse the transition when `$booking->payment_status === PaymentStatus::Paid`:
  - `Customer\BookingController::cancel` surfaces the same "contact the business — refunds are handled directly with the business for now" flash as D-157.
  - `Dashboard\BookingController::updateStatus` refuses the `Cancelled` transition with dashboard-appropriate copy: "This booking has been paid online. Automatic refunds ship in a later release — until then, refund the customer in the Stripe dashboard first, then cancel." Admins still cancel unpaid bookings via the same endpoint normally.
  - Session 3 will replace both blocks: the customer path relaxes to "in-window → automatic `RefundService::refund($booking, null, 'customer-requested')`" per locked decisions #15 / #16; the admin path dispatches `RefundService::refund($booking, null, 'business-cancelled')` before the status transition per locked decision #17.
- **Consequences**:
  - No customer- or admin-facing endpoint can produce a `cancelled + paid` row in Sessions 2a → 2b.
  - New regression file `tests/Feature/Booking/PaidCancellationGuardTest.php` covers both endpoints.
  - Session 3's diff is cleaner: two small code edits (swap the block for the refund dispatch) instead of writing the refund dispatch next to an in-the-wild state-transition path.

---

### D-160 — Payment-success flash copy branches on booking status (manual vs auto confirmation)

- **Date**: 2026-04-23 (Codex native review, Round 2, P2)
- **Status**: accepted
- **Context**: `BookingPaymentReturnController::success` unconditionally flashed "Payment received — your booking is confirmed." on every paid outcome. Manual-confirmation businesses (locked decision #29) land the booking at `pending + paid` — "confirmed" contradicts the actual state, the admin's approval queue, and the `paid_awaiting_confirmation` notification the customer just received.
- **Decision**: Extract a `successFlashFor(Booking $booking): string` helper that branches on `$booking->status`:
  - `Pending` → "Payment received — your booking is pending confirmation from the business."
  - `Confirmed` → the original "Payment received — your booking is confirmed."
  Both the outcome-level fast path (booking already paid before the controller ran) and the post-promotion path call the helper on the fresh booking state. The helper lives on the controller rather than on the model because the copy is controller-layer UX, not a domain concept.
- **Consequences**:
  - Manual-confirmation customers see copy that matches the paid-awaiting-confirmation email — no UX contradiction.
  - Auto-confirmation customers see the original copy.
  - Two new regression tests in `CheckoutSuccessReturnTest` cover each branch with `assertSessionHas('success', …)` + the underlying notification context assertion.

---

### D-161 — Booking store response carries an explicit `external_redirect` flag

- **Date**: 2026-04-23 (Codex native review, Round 2, P2)
- **Status**: accepted
- **Context**: `booking-summary.tsx` dispatched on `result.redirect_url?.startsWith('https://')` to decide whether to `window.location.href` (external Stripe URL) vs handle internally. On an HTTPS-deployed riservo instance the offline-path `route('bookings.show', …)` is ALSO an `https://…` URL, so every booking (online or offline) would hard-navigate, skipping the existing confirmation step.
- **Decision**: `PublicBookingController::store` returns an explicit `external_redirect: boolean` on both response branches — `false` for the offline path (internal `route()` URL), `true` for the online path (absolute Stripe Checkout URL). `BookingStoreResponse` TS type gains the boolean. `booking-summary.tsx` dispatches on the server-computed boolean instead of a URL heuristic. The server is authoritative on the redirect target's category; the client doesn't need to re-derive it.
- **Consequences**:
  - HTTPS-deployed riservo doesn't short-circuit offline-path redirects. The existing confirmation step renders for every offline booking as intended.
  - One new `external_redirect: false` JSON assertion on the offline path test; the online happy-path test now asserts `external_redirect: true` alongside the Stripe URL.
  - Other clients consuming this response (a future embed SDK, a mobile app) get a single boolean to dispatch on — no URL-host inspection required.

---

### D-155 — `PaymentStatus::Pending` removed outright (no alias, no backfill)

- **Date**: 2026-04-23 (PAYMENTS Session 2a)
- **Status**: accepted
- **Context**: Locked decision #28 + the Session 2a Data-layer clause ("riservo is pre-launch; dev DB is `migrate:fresh --seed`") permit retiring the legacy `Pending` case without a soft alias. Two options considered: (a) keep `Pending` as a sentinel mapping to `NotApplicable` for legacy data, (b) remove the case and update every writer + seed + factory + the initial migration's default. The first option keeps a dead enum case forever; the second is a small coordinated edit under pre-launch constraints.
- **Decision**: Remove the `Pending` case. Update `BookingFactory`'s default to `PaymentStatus::NotApplicable` + `payment_mode_at_creation = 'offline'`. Update the three existing writers (`PublicBookingController::store` offline branch, `Dashboard\BookingController::store`, `PullCalendarEventsJob`) to write `PaymentStatus::NotApplicable` + the appropriate `payment_mode_at_creation`. Update the initial `create_bookings_table` migration's column default from `PaymentStatus::Pending->value` to `PaymentStatus::NotApplicable->value` (migrations run via `migrate:fresh` so editing the initial migration is safe).
- **Consequences**:
  - No sentinel state to explain to future readers; every enum value has a concrete meaning.
  - Session 2b's failure-branching writes use the same vocabulary (`AwaitingPayment` → `Unpaid` / `NotApplicable` per locked decision #14's three outcomes).
  - Factory gains two states `awaitingPayment()` + `paid()` for the Session 2a test matrix; `manual()` and `external()` now explicitly write the `offline` snapshot + `not_applicable` status per locked decision #30's carve-out.

---

### D-162 — `booking_refunds.uuid` seeds the Stripe refund idempotency key

- **Date**: 2026-04-23 (PAYMENTS Session 2b)
- **Status**: accepted
- **Context**: Locked roadmap decision #36 mandates one row per refund ATTEMPT with a dedicated UUID column whose value forms the Stripe `idempotency_key`. Two schemes considered: (a) use the auto-increment `id` directly; (b) mint a UUID at insert time. The UUID survives seed-from-dump / schema manipulation, so (b).
- **Decision**: `booking_refunds.uuid` is a unique UUID column populated at `RefundService::refund` insert time via `(string) Str::uuid()`. Stripe is called with `['idempotency_key' => 'riservo_refund_'.$row->uuid]`. Retries of the same attempt (scheduler re-run mid-response, double-click, webhook re-delivery during the Stripe call) reuse the row — so the UUID — so Stripe collapses duplicates. Two legitimately-distinct refund intents (e.g., admin issues two $50 partials in Session 3) get two rows and two UUIDs. A synthetic `(booking_id, amount, initiator)` hash would collapse a legitimate second partial refund into the first; the row-UUID approach avoids that foot-gun by construction.
- **Consequences**:
  - `FakeStripeClient::mockRefundCreate` asserts the idempotency key starts with `riservo_refund_`; an optional exact-match parameter lets tests tie a Stripe call back to a specific `booking_refunds.uuid`.
  - Session 3 extends `RefundService` with the other four reasons (`customer-requested`, `business-cancelled`, `admin-manual`, `business-rejected-pending`) — the row shape + UUID seeding stay identical.

---

### D-163 — Late-webhook refund reads the D-158 pinned account id, not a business lookup

- **Date**: 2026-04-23 (PAYMENTS Session 2b)
- **Status**: accepted
- **Context**: `StripeConnectWebhookController::applyLateWebhookRefund` (the reaper-cancelled-but-Stripe-eventually-paid path per locked decision #31.3) needs to dispatch a refund against the ORIGINAL minting account. A business with disconnect+reconnect history has multiple `stripe_connected_accounts` rows — reading the `stripe_account_id` via `withTrashed()->where('business_id', $id)->value('stripe_account_id')` is non-deterministic (Codex Round 2 / D-158 resolved the same class of bug in the happy-path handler).
- **Decision**: The late-webhook refund path reads `booking.stripe_connected_account_id` (D-158 pin) directly — both the cross-account event-guard and `RefundService`'s resolve-target-account logic use the same column. No business-level lookup. If the pin is null the service returns `guard_rejected` with a critical log; that is an anomaly worth surfacing rather than silently using a different account.
- **Consequences**:
  - Reconnect history is handled deterministically across the whole payment-lifecycle surface (happy-path, success-page, reaper pre-flight, late-webhook refund).
  - A refund against a soft-deleted connected-account row still works: Stripe retains the `acct_id` and the locked roadmap decision #36 disconnected-account fallback runs when Stripe refuses the call.

---

### D-164 — Cancel-URL controller branches flash copy on `payment_mode_at_creation` snapshot, not current booking status

- **Date**: 2026-04-23 (PAYMENTS Session 2b)
- **Status**: accepted
- **Context**: Stripe hits `cancel_url` when the customer abandons the hosted Checkout page. The webhook (`checkout.session.expired` or `async_payment_failed`) typically arrives AFTER the customer lands on the cancel URL, so reading `booking.status` / `booking.payment_status` would show stale copy. The controller has two choices: (a) mutate state inline (which would duplicate the webhook path and violate D-151 "CheckoutPromoter is the single source of truth"); (b) read the immutable snapshot and pick copy that matches the INTENT at booking creation.
- **Decision**: `BookingPaymentReturnController::cancel` mutates nothing. It reads `booking.payment_mode_at_creation` (the locked-decision-#14 snapshot, immutable by construction) and picks the flash:
  - `online` → error flash "Payment not completed. Your slot has been released." (the webhook failure arm will Cancel the booking).
  - `customer_choice` → success flash "Your booking is confirmed — pay at the appointment." (the webhook failure arm promotes to Confirmed + Unpaid).
  - Connected account has no active row (disconnected between creation and return) → error flash "This business is no longer accepting online payments — contact them directly."
  The redirect target is `route('bookings.show', $token)` so the customer lands on a page that will reflect the webhook-driven final state once it fires.
- **Consequences**:
  - No double-transition paths; the D-151 invariant is preserved.
  - Customer sees coherent copy even if they return within milliseconds of abandoning Checkout.
  - Session 5 polishes the disconnected-account copy; the 2b default is stable.

---

### D-165 — `RefundService::refund` returns a readonly `RefundResult` DTO (not a scalar)

- **Date**: 2026-04-23 (PAYMENTS Session 2b)
- **Status**: accepted
- **Context**: D-151 set a scalar-return precedent for `CheckoutPromoter::promote` to dodge PHPStan level-5 generic-inference limits on `DB::transaction` callback returns. `RefundService::refund` has four terminal outcomes (`succeeded`, `failed`, `disconnected`, `guard_rejected`) plus a `BookingRefund` row reference and an optional failure message — forcing this into a scalar would either collapse information or grow a parallel `out` parameter.
- **Decision**: Return a readonly DTO `App\Services\Payments\RefundResult { string $outcome; ?BookingRefund $bookingRefund; ?string $failureReason; }`. The DTO is constructed OUTSIDE the `DB::transaction` callback (the closure captures the row via `use (&$row)`), so PHPStan's generic-inference constraint on callback returns still holds. Session 3 extends the outcome set without breaking callers — the DTO is the stable contract.
- **Consequences**:
  - Webhook handlers + the admin refund UI (Session 3) read the same outcome vocabulary.
  - No `is_string` / `===` outcome-string checks spread across callers; `$result->outcome === 'succeeded'` is the one pattern.

---

### D-166 — Reaper SKIPS the cancel for every `CheckoutPromoter::promote` return value, including `'mismatch'`

- **Date**: 2026-04-23 (PAYMENTS Session 2b)
- **Status**: accepted
- **Context**: The reaper's pre-flight retrieve asks Stripe for the Checkout session state. If Stripe reports `status = complete` OR `payment_status = paid`, the reaper runs `CheckoutPromoter::promote` inline. The promoter can return `'paid'`, `'already_paid'`, `'not_paid'`, or `'mismatch'` (D-156). The reaper then needs to decide: should it still cancel the booking when the promoter refused?
- **Decision**: The reaper SKIPS the cancel regardless of the promoter's return value. Rationale:
  - `'paid'` / `'already_paid'` — the booking is now terminal; cancelling would produce `Cancelled + Paid`.
  - `'not_paid'` — async session still settling; cancelling before it settles would strand funds.
  - `'mismatch'` — the promoter already logged critical for operator reconciliation; cancelling the booking could free a slot that has real money attached to it. The conservative choice is to leave the booking at `AwaitingPayment` until operators investigate.
- **Consequences**:
  - A mismatched booking will re-appear in every subsequent reaper tick until operators either fix the underlying anomaly (hostile `client_reference_id` collision, duplicate Stripe integration, code bug) OR the Stripe session eventually expires and Stripe responds with a non-paid status on retrieve — at which point the cancel proceeds normally.
  - The noisy-but-correct outcome is preferred over the quiet-but-wrong one (same philosophy as D-149 for `account.updated` unknown-account events).

---

### D-167 — `BookingRefund.status` is a native PHP enum, not a string column

- **Date**: 2026-04-23 (PAYMENTS Session 2b gate-1 revision)
- **Status**: accepted
- **Context**: Initial plan had `status` as a plain string column with a PHPDoc union, arguing that Session 3 might grow the value set. Gate-1 feedback: the values surfaced by riservo's flow are exactly three (`pending`, `succeeded`, `failed`) across Sessions 2b + 3 — Stripe refund states `requires_action` and `canceled` are not modelled because our flow does not trigger them.
- **Decision**: New enum `App\Enums\BookingRefundStatus` with cases `Pending = 'pending'`, `Succeeded = 'succeeded'`, `Failed = 'failed'`. `BookingRefund` casts the `status` column through the enum. `Booking::remainingRefundableCents()` reads `BookingRefundStatus::Pending->value` + `::Succeeded->value`. The factory's `pending()` / `succeeded()` / `failed()` states write enum instances.
- **Consequences**:
  - PHPStan `match` exhaustiveness applies wherever callers branch on the status.
  - Session 3 adding a 4th value (if Stripe semantics ever require it) forces a compile-time `match` update — the desired property, not a drawback.

---

### D-168 — `BookingReceivedNotification` gains `pending_unpaid_awaiting_confirmation` context for manual-confirm + customer_choice failed Checkout

- **Date**: 2026-04-23 (PAYMENTS Session 2b)
- **Status**: accepted
- **Context**: Locked decision #14 + #29 land `customer_choice + manual-confirm + failed Checkout` bookings at `Pending + Unpaid`. The existing `'new'` context on `BookingReceivedNotification` is staff-facing; `'paid_awaiting_confirmation'` (Session 2a / D-151) promises the customer an automatic refund on rejection — wrong copy for a booking that has no payment on file.
- **Decision**: Add a fourth context value `'pending_unpaid_awaiting_confirmation'`. Subject: "Booking request received — :business will confirm". Body: "Your booking request at :business has been received and is pending their confirmation. Your online payment did not complete — if the business accepts your booking, you can pay at the appointment." The Session 2b webhook dispatcher (`StripeConnectWebhookController::dispatchCustomerChoiceFailureNotifications`) selects this context when the target status is `Pending`.
- **Consequences**:
  - Four contexts total on `BookingReceivedNotification`: `'new'` (default, staff-facing), `'confirmed'` (auto-confirm customer-facing), `'paid_awaiting_confirmation'` (manual-confirm + paid), `'pending_unpaid_awaiting_confirmation'` (manual-confirm + customer_choice + failed Checkout).
  - The blade template branches on `$context` with `@elseif`; an unknown context falls through to the `'new'` default.

---

### D-169 — `RefundService` rejects partial-refund overflow with a 422 `ValidationException` instead of silently clamping

- **Date**: 2026-04-24 (PAYMENTS Session 3)
- **Status**: accepted
- **Supersedes**: the silent-clamp branch added in Session 2b (`RefundService.php` pre-Session-3 lines 154–165).
- **Context**: Locked roadmap decision #37 mandates a server-side second check for partial refunds. The Session 2b implementation clamped a `$amountCents > remaining` request down to `$remaining` and logged a warning. That silent clamp is safe for Session 2b (the only caller was `applyLateWebhookRefund`, always passing `$amountCents = null`) but misleading for Session 3's `admin-manual` path — if the admin's client-side clamp ever drifts from the server's view (stale `remainingRefundableCents` after a concurrent refund by another admin), silently refunding a smaller amount hides the bug: the dialog reports success for the wrong figure, and operators can only reconcile by reading Stripe's dashboard directly.
- **Decision**: `RefundService::refund` splits the amount-resolution branch:
    - `$amountCents === null` → refund `$remaining` (the Session 2b contract for system-dispatched paths); no overflow possible by construction.
    - `$amountCents !== null && $amountCents > $remaining` → `throw ValidationException::withMessages(['amount_cents' => [__('Refund exceeds the remaining refundable amount. Maximum allowed is :max.', ['max' => ...])]])`. Laravel renders it as a 422; the admin-manual dialog surfaces the error under the amount field via `useForm`.
    - `$amountCents !== null && $amountCents <= $remaining` → insert + dispatch as before.
    The throw happens INSIDE the `DB::transaction + lockForUpdate` callback so the row insert rolls back on overflow — no orphan.
- **Consequences**:
    - `tests/Unit/Services/Payments/RefundServicePartialTest.php` asserts the 422 + no-row-inserted contract.
    - `tests/Feature/Dashboard/AdminManualRefundTest.php` asserts the 422 surface on the HTTP boundary + the `amount_cents` error-bag key.
    - System-dispatched paths (customer cancel, business cancel, late-webhook refund, manual-confirm rejection) cannot trip this path — they all pass `$amountCents = null` by construction.
    - The `@throws` docblock on `refund()` now lists `ValidationException | ApiConnectionException | RateLimitException | ApiErrorException`, which is what callers' catch blocks need to compile clean under PHPStan level 5.

---

### D-170 — `PaymentPendingActionController::resolve` accepts `PaymentDisputeOpened` for admin-manual dismiss with `resolution_note='dismissed-by-admin'`

- **Date**: 2026-04-24 (PAYMENTS Session 3)
- **Status**: accepted
- **Context**: Locked roadmap decision #35 puts `payment.dispute_opened` Pending Actions on a webhook-driven lifecycle: Stripe closes the dispute → `charge.dispute.closed` resolves the PA with `resolution_note = 'closed:<stripe_status>'`. There was no dashboard-initiated resolution path for stuck PAs (webhook lost, dispute closed out-of-band, etc.); the only surface was the Stripe Express dashboard, which is fine functionally but leaves the riservo banner up forever.
- **Decision**: `Dashboard\PaymentPendingActionController::resolve` extends the accepted-types allow-list to `{PaymentCancelledAfterPayment, PaymentRefundFailed, PaymentDisputeOpened}`. When resolving a `PaymentDisputeOpened` row, the controller writes `resolution_note = 'dismissed-by-admin'` instead of inheriting the existing value — this distinguishes admin-manual escape-hatch dismissal from webhook-driven resolution in the audit trail. The dispute-PA UI banner on `BookingDetailSheet` exposes a "Dismiss" button backed by this endpoint. Staff users continue to receive 403 per locked decision #19.
- **Consequences**:
    - Webhook-driven resolution is still the norm (99% of disputes close via `charge.dispute.closed`); admin-manual dismiss is the operator escape hatch.
    - The distinct `resolution_note` lets future analytics / audit tooling cleanly bucket "Stripe-closed" vs "admin-dismissed" without a boolean column.
    - `DisputeWebhookTest::admin dismiss on dispute PA writes resolution_note=dismissed-by-admin` covers the new code path; the staff-cannot-dismiss test covers the role gate.

---

### D-171 — Refund-settlement webhooks match `booking_refunds` rows via `stripe_refund_id`, not via `payment_intent`

- **Date**: 2026-04-24 (PAYMENTS Session 3)
- **Status**: accepted
- **Context**: `StripeConnectWebhookController::handleRefundEvent` lands `charge.refunded` / `charge.refund.updated` / `refund.updated` events. The arriving Stripe Refund object carries both `id` (our row's eventual `stripe_refund_id`) and `payment_intent` (the booking's `stripe_payment_intent_id`). Two match strategies were considered:
    - Match on `stripe_refund_id` — precise; exactly one row per refund attempt (locked decision #36).
    - Match on `payment_intent` — broader; one booking, possibly multiple refund attempts; needs further disambiguation (which row?).
- **Decision**: Match on `booking_refunds.stripe_refund_id`. `RefundService::refund` persists this id within the second transaction immediately after Stripe accepts the refund, so by the time Stripe emits `charge.refunded` (~100ms later) the id is reliably on the row. An event whose refund id has no matching row is logged + 200'd (Stripe won't retry 2xx; the cache-layer dedup catches honest replays). A race where the webhook arrives before the commit is theoretical — Stripe's dispatcher adds its own latency — and would be handled by Stripe's natural retry on the 2xx-is-no-op path.
- **Consequences**:
    - Partial refunds (multiple rows per booking) disambiguate unambiguously.
    - The D-158 pin on the booking remains the cross-account guard; the refund handler reads `row->booking->stripe_connected_account_id` vs `event.account`.
    - `tests/Feature/Payments/RefundSettlementWebhookTest.php::refund event for unknown stripe_refund_id` covers the miss-logs-and-200 path.

---

### D-172 — Stripe refund statuses `requires_action` / `canceled` map onto the three-value `BookingRefundStatus` enum

- **Date**: 2026-04-24 (PAYMENTS Session 3)
- **Status**: accepted
- **Context**: D-167 locked `BookingRefundStatus` at three values (`pending`, `succeeded`, `failed`) because riservo's flows don't trigger `requires_action` (no payer-initiated refund redirects in the hosted-Checkout configuration) and rarely trigger `canceled` (ACH NACKs, out-of-band Stripe reversals). But the refund-settlement webhook must still handle these values when Stripe DOES emit them.
- **Decision**: `RefundService::recordStripeState` maps:
    - `succeeded` → `recordSettlementSuccess` (row Succeeded + `reconcilePaymentStatus`).
    - `failed` → `recordSettlementFailure` (row Failed + `payment_status = refund_failed` + PA + admin email) with the Stripe `failure_reason` persisted on the row.
    - `canceled` → `recordSettlementFailure` (same sad-path surface) with `failure_reason = 'Stripe cancelled the refund'`. Logged critical so operators see the anomaly.
    - `requires_action` / `pending` → no-op (row stays Pending; Stripe emits a follow-up `refund.updated` later).
    - Anything else → logged as unknown + no-op.
- **Consequences**:
    - `RefundServicePartialTest::recordStripeState maps canceled → Failed (D-172)` + `recordStripeState on requires_action/pending leaves row Pending` cover the mapping.
    - The admin banner + email surface fire correctly for the `canceled` case; operators aren't surprised by a silent Failed row.
    - If Stripe ever emits a settlement status outside our known set, the log line identifies the booking + refund row for manual reconciliation.

---

### D-173 — `bookings.stripe_charge_id` backfill on promotion is deferred past Session 3

- **Date**: 2026-04-24 (PAYMENTS Session 3 plan-time call)
- **Status**: accepted
- **Context**: The Session 2b `payment` sub-object in `Dashboard\BookingController::index` exposes `stripe_charge_id` for the booking-detail deep-link, but happy-path promotion via `CheckoutPromoter::promote` does NOT populate the column — only `stripe_payment_intent_id`. Session 3 needs to decide whether to backfill the charge id on promotion so refund-settlement webhooks can match rows via the charge id, and the dashboard deep-link renders `/payments/ch_...` instead of `/payments/pi_...`.
- **Decision**: Do NOT backfill. The refund-settlement webhook matches via `booking_refunds.stripe_refund_id` (D-171), not via charge id; the dashboard deep-link already falls back to `stripe_payment_intent_id` when the charge id is null (`booking-detail-sheet.tsx:120–127`). Adding a second `payment_intents.retrieve` call to `CheckoutPromoter::promote` buys one prettier dashboard URL at the cost of one more cross-account Stripe call on every happy-path promotion — not worth it.
- **Consequences**:
    - No change to `CheckoutPromoter::promote` or the promotion-time flow.
    - If a future session (e.g., payout-reconciliation work) needs authoritative charge ids on bookings, it can backfill via a one-shot command reading from Stripe's API.
    - Added as a BACKLOG entry on session close for future consideration.

---

### D-174 — Refund reason vocabulary is five plain strings, not an enum

- **Date**: 2026-04-24 (PAYMENTS Session 3)
- **Status**: accepted
- **Context**: Session 3 grows the `booking_refunds.reason` column value space from `{cancelled-after-payment}` (Session 2b) to five values: `customer-requested`, `business-cancelled`, `admin-manual`, `business-rejected-pending`, and the inherited `cancelled-after-payment`. Two choices:
    - Promote to a native PHP enum mirroring `BookingRefundStatus` (D-167) for match-exhaustiveness.
    - Keep as a plain string column with the five values as documented invariants.
- **Decision**: Keep as strings. Rationale:
    - The reason is a presentation-layer concern — the refund-list UI renders a human label keyed off the string (`admin-manual` → "Manual refund", etc.). An enum adds a layer that only the label helper would consume.
    - The set is CLOSED for Session 3; Session 4 and 5 don't add new reasons per the roadmap.
    - If a future session (e.g., "refund-on-no-show") adds a sixth reason, promoting to an enum is a mechanical edit; no schema change needed, just a cast + the call-site enum reference.
- **Consequences**:
    - No enum file; the five values live as documented invariants in `RefundService` + the refund-dialog UI + the reason-label mapper in `BookingDetailSheet`.
    - PHPStan won't catch a typo'd reason at the call site (`refund(..., 'admin_manual')` with an underscore would compile). Accepted risk; covered by unit tests on the five known call-sites.

---

### D-175 — `BookingCancelledNotification` gains a `$refundIssued` boolean constructor arg; template renders the refund clause only for `business` cancels with `refundIssued === true`

- **Date**: 2026-04-24 (PAYMENTS Session 3)
- **Status**: accepted
- **Context**: Locked roadmap decision #29 variant requires the customer-facing cancellation email to branch on whether a refund was issued. For `paid` business rejections, the email includes "a full refund has been issued"; for `unpaid` rejections (customer_choice + manual-confirm failed Checkout — there's nothing to refund), the refund clause MUST be omitted. An unconditional refund clause would mislead customers into expecting money they were never charged.
- **Decision**: `BookingCancelledNotification::__construct` gains a third arg `public bool $refundIssued = false` (defaulted to false so existing test callers compile). The blade template (`resources/views/mail/booking-cancelled.blade.php`) renders the refund clause only when `$cancelledBy === 'business' && $refundIssued`. The three call sites (`BookingManagementController::cancel`, `Customer\BookingController::cancel`, `Dashboard\BookingController::updateStatus`) pass the flag based on `RefundResult::$outcome === 'succeeded'`. Customer-cancel paths (`cancelledBy = 'customer'`) never render the refund clause because the staff-facing email has different copy — the customer-facing refund signal goes via flash copy + the `refundStatusLine()` on the public booking pages.
- **Consequences**:
    - `PaidCancellationRefundTest` asserts `refundIssued` on every business-cancel branch (Confirmed+Paid, Pending+Paid, Pending+Unpaid, disconnected-account failure).
    - The notification's existing test callers that instantiate `new BookingCancelledNotification($booking, 'customer')` still work — the `refundIssued` default is false.
    - Adding a new branch (e.g., Session 4 payout-side cancels) means one more call-site wires the flag; the blade's gate is stable.
