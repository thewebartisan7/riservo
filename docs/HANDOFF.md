# Handoff

**State (2026-04-23):** PAYMENTS Session 1 (Stripe Connect Express Onboarding) implemented and staged; awaiting developer review + commit. The active `docs/ROADMAP.md` (PAYMENTS) drives the remaining five sessions: 2a, 2b, 3, 4, 5.

**Branch**: main.
**Feature+Unit suite**: 777 passed / 3123 assertions measured 2026-04-23 after PAYMENTS Session 1 + codex Rounds 1–13 (was 695 / 2819 at the docs-collapse baseline; Session 1 + Rounds 1–13 added 82 tests / 304 assertions).
**Full suite**: 2+ minutes because of `tests/Browser`. Developer pre-push check.
**Tooling**: Pint clean. PHPStan (level 5, `app/` only) clean. Vite build clean (main app chunk 542 kB; pre-existing >500 kB warning unaffected by Session 1). Wayfinder regenerated.

---

## What shipped

MVP (Sessions 1–11) + MVP Completion (MVPC-1..5): data layer, scheduling engine, frontend foundation, auth, onboarding, public booking, dashboard, settings, email notifications, calendar view, Google OAuth, bidirectional Google Calendar sync, Cashier subscription billing, provider self-service settings, advanced calendar interactions. E2E-0..6 also shipped.

**PAYMENTS Session 1 (this session, 2026-04-23):**
- Settings → Booking hides `online` / `customer_choice` (locked roadmap decision #27); persisted hidden values still round-trip on load.
- New `config/payments.php` with three env-driven keys (`supported_countries`, `default_onboarding_country`, `twint_countries`); MVP defaults to `'CH'` everywhere. No hardcoded `'CH'` literal in app code (locked decision #43, D-112).
- `calendar_pending_actions` renamed to `pending_actions`; `integration_id` nullable; `PendingActionType` extended with `payment.dispute_opened`, `payment.refund_failed`, `payment.cancelled_after_payment` (D-113, D-119). Calendar-aware readers (`HandleInertiaRequests`, `CalendarIntegrationController`, `DashboardController`, `CalendarPendingActionController`) scope to `PendingActionType::calendarValues()` so payment-typed rows do not leak into calendar surfaces.
- New `stripe_connected_accounts` table + `StripeConnectedAccount` model (SoftDeletes, `verificationStatus()`, `matchesAuthoritativeState()` for outcome-level idempotency), per-Business `(business_id, deleted_at)` compound unique (D-111). `Business` gains `stripeConnectedAccount(): HasOne` + `canAcceptOnlinePayments(): bool`.
- `Dashboard\Settings\ConnectedAccountController` (admin-only, inside `billing.writable` per D-116) with `show / create / refresh / disconnect` actions. `create()` rejects with 422 when an active row already exists (D-117); onboarding country is always `config('payments.default_onboarding_country')` (D-115); disconnect soft-deletes and forces `payment_mode = offline`. Re-onboarding is supported via the compound unique seam.
- `App\Support\Billing\DedupesStripeWebhookEvents` trait extracted from `StripeWebhookController`; subscription cache prefix moved to `stripe:subscription:event:`; new Connect cache prefix `stripe:connect:event:` (D-110, locked decision #38). Two namespaces cannot collide.
- `POST /webhooks/stripe-connect` controller (NOT a Cashier subclass — D-109) with signature verification against `STRIPE_CONNECT_WEBHOOK_SECRET`. Handlers: `account.updated` (re-fetches via `accounts.retrieve` per locked decision #34 — payload is a nudge, not the source of truth; demotes `payment_mode` to offline if capabilities lost per locked decision #20), `account.application.deauthorized` (soft-deletes row, retains `stripe_account_id` per locked decision #36), `charge.dispute.*` (log-and-200 stub; Session 3 wires the body).
- `auth.business.connected_account` Inertia shared prop (D-114) carries `{status, country, can_accept_online_payments, payment_mode_mismatch}`. Dashboard layout reads `payment_mode_mismatch` to render an admin-only mismatch banner; dormant until Session 2a / 5 puts a Business in non-offline `payment_mode` legitimately.
- `Dashboard/settings/connected-account.tsx` page with four states (not_connected / pending / incomplete / active / disabled) + `<DisconnectButton>` confirmation; accessible (role="alert/status", aria-labels, capability chips with icon + text). New "Online Payments" item in Settings nav under the "Business" group (admin-only).
- `tests/Support/Billing/FakeStripeClient.php` split into platform-level (header asserted ABSENT) — `mockAccountCreate`, `mockAccountRetrieve`, `mockAccountLinkCreate` — and a documented Session 2+ contract for the connected-account-level bucket. A call that crosses categories is a test failure by construction.
- `docs/DEPLOYMENT.md` updated with Stripe Connect operator setup (separate webhook endpoint in the Connected accounts dashboard tab, env vars, full subscribed-event list including Session 2/3 events for forward-compat). `docs/BACKLOG.md` adds the support-contact-surface follow-up (D-118).
- `docs/decisions/DECISIONS-PAYMENTS.md` populated with D-109..D-119.

**Codex Round 1** (2026-04-23, applied on the same uncommitted diff): three blockers fixed — (1) Connect webhook fail-closed on empty secret outside testing (D-120); (2) onboarding refuses to silently default a country into an immutable Stripe Express account, MVP requires `supported_countries` to be a singleton (D-121, BACKLOG carry-over for the per-Business country selector); (3) Postgres partial unique index `(business_id) WHERE deleted_at IS NULL` replaces the broken compound unique, controller wrapped in `DB::transaction` + `lockForUpdate` on the parent Business row (D-122). See `docs/PLAN.md` `## Review — Round 1` for the full record.

**Codex Round 2** (2026-04-23, same diff): three more findings fixed — (1) stray `/c` prefix that broke `ConnectedAccountController.php` PHP syntax; (2) dispute webhook now persists a `payment.dispute_opened` Pending Action instead of log-and-200, so disputes are durable even before Session 3 ships email + UI (D-123); (3) `accounts.create` carries `idempotency_key = riservo_connect_account_create_{business_id}` to prevent orphan Express accounts on crash-then-retry, asserted by `FakeStripeClient::mockAccountCreate` (D-124). See `docs/PLAN.md` `## Review — Round 2` for the full record.

**Codex Round 3** (2026-04-23, same diff): four more findings fixed — (1) reconnect within Stripe's 24h idempotency window now uses a per-attempt nonce (`_attempt_{soft_deleted_count}`) and defensively restores soft-deleted rows on Stripe replay (D-125); (2) `pending_actions` rename documented as a non-rolling-deploy release in `docs/DEPLOYMENT.md` (D-125, pre-launch trade-off); (3) dispute Pending Actions get a Postgres partial unique index on `payload->>'dispute_id' WHERE type = 'payment.dispute_opened'`; handler now race-safe via savepoint+catch (D-126); (4) `Business::canAcceptOnlinePayments()` now includes the supported-country gate so the Inertia mismatch banner, the `account.updated` demotion path, and the future Settings gate all inherit the country check (D-127). See `docs/PLAN.md` `## Review — Round 3` for the full record.

**Codex Round 4** (2026-04-23, same diff): three more findings fixed — (1) Connect demotion paths are now atomic + retry-safe (`account.updated` evaluates demotion outside the matches-short-circuit; `account.application.deauthorized` lookup uses `withTrashed`; both writes wrapped in `DB::transaction`) so partial-success crashes converge on the next webhook retry (D-128); (2) `refresh()` mints a fresh Account Link for the `incomplete` state too (not just `pending`) so admins with capabilities still pending can actually resume KYC instead of looping (D-129); (3) `payment_mode` rollout gate moved to `UpdateBookingSettingsRequest` validator — non-offline values require `canAcceptOnlinePayments()` (which folds in Stripe capabilities + the country gate); idempotent passthrough keeps the form usable for already-persisted values (D-130). See `docs/PLAN.md` `## Review — Round 4` for the full record.

**Codex Round 5** (2026-04-23, same diff): three more findings fixed — (1) admin `disconnect()` now atomic + retry-safe (DB::transaction, withTrashed lookup, idempotent trashed-noop branch) so partial failure cannot leave a business stuck at `online` with no UI recovery (D-131); (2) D-130's verified-Stripe carve-out removed — non-offline `payment_mode` is hard-blocked regardless of Stripe verification until Session 5 ships; success copy reframed from "online payments are ready" to "will activate in a later release" so no false-ready surface exists (D-132); (3) `pending_actions` rename `down()` no longer restores `integration_id NOT NULL` — rollback is now safe with payment-typed rows present, schema rolls back to a strict-superset state (D-133). See `docs/PLAN.md` `## Review — Round 5` for the full record.

**Codex Round 6** (2026-04-23, same diff): three more findings fixed — (1) replay-recovery in `create()` now refuses to re-parent a soft-deleted row from a different Business; cross-tenant collision logs critical + flashes a manual-reconciliation error (D-134); (2) Cashier's `cashier.payment` + `cashier.webhook` named-route contract restored — payment URL now respects `config('cashier.path')`, the existing `/webhooks/stripe` route renamed to `cashier.webhook` (D-135); (3) active-state Connected Account page chip + copy reframed from "Active / online payments are ready" to "Verified / charging customers will activate in a later release" so the UI matches the backend rollout gate. See `docs/PLAN.md` `## Review — Round 6` for the full record.

**Codex Round 7** (2026-04-23, same diff): three more findings fixed — (1) `account.updated` now serialises per-account via `lockForUpdate` inside `DB::transaction` around the retrieve+save, eliminating the stale-snapshot race (D-136); (2) Connect return flow split into `refresh` (GET, sync only) / `resume` (POST, admin-triggered, gated by billing.writable) / `resume-expired` (GET, Stripe-triggered link-expiry) so pending/incomplete admins can land on the settings page and disconnect instead of being trapped in a Stripe loop (D-137); (3) `canAcceptOnlinePayments()` now also requires `requirements_disabled_reason === null` so disabled accounts cannot surface as ready via any reader (D-138). See `docs/PLAN.md` `## Review — Round 7` for the full record.

**Codex Round 8** (2026-04-23, same diff): two more findings fixed — (1) late `account.application.deauthorized` for a retired `acct_…` no longer demotes a reconnected business; the handler re-checks for a surviving active connected account before forcing `payment_mode = offline` (D-139); (2) `disconnect()` now prefers the active connected-account row and only falls back to `withTrashed()->first()` when no active row exists (D-131 retry-recovery path), preventing a split-brain where a stale historical row got "disconnected" while the real active account stayed behind (D-140). See `docs/PLAN.md` `## Review — Round 8` for the full record.

**Codex Round 9** (2026-04-23, same diff): two more findings fixed — (1) new canonical `businesses.country` column (nullable, pre-launch backfill to `'CH'`) gates Stripe Express onboarding; `create()` refuses when missing or not in `config('payments.supported_countries')` (D-141, supersedes D-115's config-fallback stance); (2) Stripe `return_url` and `refresh_url` are now `URL::temporarySignedRoute(...)` URLs carrying the `acct_…`; `refresh` and `resume-expired` routes carry the `signed` middleware; controllers resolve the row by the signed param and verify the current tenant owns it — rejects cross-site GETs and wrong-tenant returns (D-142). See `docs/PLAN.md` `## Review — Round 9` for the full record.

**Codex Round 10** (2026-04-23, same diff): three more findings fixed — (1) `RegisterController::store()` now seeds `Business.country` from `config('payments.default_onboarding_country')` so post-migration signups can reach Stripe Connect onboarding (D-143, closes the gap D-141 opened on the signup side); (2) `ConnectedAccountController::create()` mints Account Links via `mintAccountLink()` so the very first KYC return and link-expired retry carry signed URLs with the `acct_id` — plain `route(...)` URLs would 403 against the D-142 `signed` middleware (D-144, enforced by a new `expectedSignedAccountParam` matcher on `FakeStripeClient::mockAccountLinkCreate`); (3) `resumeExpired()` now refuses read-only businesses with a `settings.billing` redirect before any Stripe call, closing the 24h-signed-URL billing-gate bypass that `billing.writable` lets through on GET (D-145). See `docs/PLAN.md` `## Review — Round 10` for the full record.

**Codex Round 11** (2026-04-23, same diff): one finding fixed + one rejected — (1) `refresh()` and `resumeExpired()` now check `$row->trashed()` after `resolveSignedAccountRow()` and redirect with a revoked-account flash, so 24h-old signed URLs minted pre-disconnect cannot revive local state or mint fresh Account Links for disconnected accounts (D-146); (2) Codex re-flagged the `calendar_pending_actions` rename as non-rolling-deploy-safe — explicitly rejected as a restatement of D-125 + DEPLOYMENT.md's pre-launch single-instance-restart trade-off; no new decision. See `docs/PLAN.md` `## Review — Round 11` for the full record.

**Codex Round 12** (2026-04-23, same diff): three findings fixed — (1) signed Connect return-URL handlers now authorise via the SIGNED ROW's business (re-pinning `session('current_business_id')` + `TenantContext`) instead of the session-pinned tenant; multi-business admins whose session was re-pinned wrong on re-auth can now complete Stripe onboarding without a manual tenant switch (D-147); (2) `refresh` / `resume` / `resumeExpired` / `disconnect` all serialise row access via `DB::transaction` + `lockForUpdate` with an inside-lock `trashed()` re-check, closing the TOCTOU where a concurrent disconnect between pre-check and Stripe call could revive or re-mint onboarding for a disconnected account (D-148); (3) `account.updated` and `account.application.deauthorized` unknown-account branches return `503 Retry-After: 60` instead of `200`, so Stripe retries re-enter the handler and a webhook that beats our `create()` commit doesn't get permanently dedup-cached (D-149). See `docs/PLAN.md` `## Review — Round 12` for the full record.

**Codex Round 13** (2026-04-23, same diff): one finding fixed — `StripeConnectedAccount::verificationStatus()` now returns a distinct `'unsupported_market'` state when Stripe capabilities are fully on but the row's `country` is not in `config('payments.supported_countries')`. Closes the UX gap where a config drift (operator tightens `PAYMENTS_SUPPORTED_COUNTRIES` or we expand then retract during beta) would surface a misleading "Verified / Active" chip while the backend silently refused online payments. Includes refresh-flash copy, a frontend `<UnsupportedMarket>` panel, and three regression tests (two unit, one feature). Backend gates (`canAcceptOnlinePayments`, Settings → Booking validator, Inertia `can_accept_online_payments` flag) were already coherent via D-127 / D-130 / D-141; D-150 fixes only the COMMUNICATION layer (D-150). See `docs/PLAN.md` `## Review — Round 13` for the full record.

72 architectural decisions (D-080–D-150) recorded across `docs/decisions/DECISIONS-*.md`. Next free decision ID: **D-151**.

---

## What is next

`docs/ROADMAP.md` — **PAYMENTS Session 2a, Payment at Booking (Happy Path)**. The roadmap session under `## Session 2a — Payment at Booking (Happy Path)` is the brief.

Prerequisites met: Session 1 ships the connected-account, the Connect webhook controller, the FakeStripeClient platform-level methods, `config/payments.php`, and the generalised `pending_actions` table. Session 2a layers on:
- Extended `PaymentStatus` enum + `bookings` columns (`stripe_checkout_session_id`, `stripe_payment_intent_id`, `stripe_charge_id`, `paid_amount_cents`, `currency`, `paid_at`, `payment_mode_at_creation`, `expires_at`).
- Public booking flow extension (pending + awaiting_payment booking row, Checkout session creation on the connected account, success-page synchronous retrieve per locked decision #32).
- `checkout.session.completed` handler in the Connect webhook (extends Session 1's `dispatch()` `match`).
- `mockCheckoutSessionCreateOnAccount` + `mockCheckoutSessionRetrieveOnAccount` on the connected-account-level FakeStripeClient bucket (header asserted PRESENT).

Sessions 2b → 5 follow per the roadmap.

Parked in `docs/roadmaps/`: `ROADMAP-E2E.md` (ongoing coverage, ticks up with each feature) and `ROADMAP-GROUP-BOOKINGS.md` (post-MVP, not scheduled).

---

## Workflow (minimal)

1. Developer briefs an architect agent to review / revise `docs/ROADMAP.md`.
2. Developer briefs a planning agent for a single session. The agent reads `SPEC.md` + `HANDOFF.md` + `ROADMAP.md` + the relevant code, writes `docs/PLAN.md`, stops for developer approval.
3. On approval, the same agent (or a fresh one) implements the plan, keeps `## Progress` current in `docs/PLAN.md`, runs tests, stages the work. Never commits.
4. Developer reviews the diff. May also run codex review (`/codex:review` or the companion script) against the staged state — if run inside the plan+exec chat, the agent sees findings directly in the transcript; otherwise developer pastes them back. Agent applies fixes under a `## Review` section in `docs/PLAN.md` on the same uncommitted diff. Developer commits once at the end (single commit bundles exec + review fixes).
5. Agent rewrites `HANDOFF.md` if the session changed shipped state, promotes any new `D-NNN` into the matching `docs/decisions/DECISIONS-*.md` file, stages close artifacts. Developer commits.
6. At the start of the next session, `docs/PLAN.md` gets overwritten. Git keeps the previous plan.

Two developer gates per session: plan approval, commit. No orchestrator, no brief skills, no index files.

---

## Conventions that future work must not break

All MVP-era conventions remain. Highlights most relevant to the remaining PAYMENTS sessions:

- **Stripe SDK mocking via container binding (D-095)**. Every Stripe call flows through `app(StripeClient::class, ...)`. Tests mock via the `FakeStripeClient` builder in `tests/Support/Billing/`. PAYMENTS Session 1 split the helper into platform-level (no `Stripe-Account` header) and connected-account-level (header required) buckets — sessions 2+ extend the connected-account-level surface only.
- **Connect webhook at `/webhooks/stripe-connect` (D-109)**. NOT a Cashier subclass. Signature verified against `STRIPE_CONNECT_WEBHOOK_SECRET`. Sessions 2a / 2b / 3 add their handlers to the existing `dispatch()` `match` arms (see `StripeConnectWebhookController::dispatch`). Cache prefix `stripe:connect:event:` (D-110); the subscription cache prefix is `stripe:subscription:event:` — the two cannot collide.
- **`account.*` handlers re-fetch via `accounts.retrieve()` (locked roadmap decision #34)**. The webhook payload is treated as a nudge; the authoritative state is whatever Stripe currently reports. Out-of-order delivery converges automatically.
- **Outcome-level idempotency (locked roadmap decision #33)**. Every webhook handler additionally re-checks DB state at the top so replays / inline-promotion races never double-write. `StripeConnectedAccount::matchesAuthoritativeState()` is the reusable shape for `account.*`; payment / refund handlers in 2b/3 will follow the same pattern with their own state fields.
- **`pending_actions` is generalised (D-113)**. Calendar-typed and payment-typed rows coexist on the same table; `integration_id` is nullable. Calendar-aware readers MUST filter via `PendingActionType::calendarValues()` (the existing `Inertia` middleware, `CalendarIntegrationController`, `DashboardController`, `CalendarPendingActionController` already do).
- **Country gating reads `config('payments.supported_countries')` (D-112 + locked decision #43)**. No hardcoded `'CH'` literal anywhere in app code, tests, Inertia props, or Tailwind class checks. Extending to IT / DE / FR / AT / LI is a config flip.
- **Disconnect retains `stripe_account_id` on the soft-deleted row (locked decision #36)**. Session 2b's late-webhook refund path reads it to issue refunds against the original account even after disconnect. Re-onboarding is supported via the `(business_id, deleted_at)` compound unique on `stripe_connected_accounts` (D-111).
- **`billing.writable` middleware (D-090) wraps every mutating dashboard route via the outer group**. Connected-account mutations sit inside the gate (D-116) — a SaaS-lapsed Business must resubscribe before opening new payment surfaces. `GET` requests pass through unconditionally, so a lapsed admin returning from KYC still lands on a working `refresh()`.
- **Server-side automation runs unconditionally**, even for read-only businesses. `AutoCompleteBookings`, `SendBookingReminders`, `calendar:renew-watches`, `PullCalendarEventsJob`, `StripeWebhookController`, `StripeConnectWebhookController` — all keep firing for existing data regardless of subscription state.
- **Direct charges on connected accounts for PAYMENTS** (locked roadmap decision #5). Professionals are merchant of record; `Stripe-Account` header on every Connect call (Sessions 2+); no `application_fee_amount`. The FakeStripeClient split enforces this distinction at the test boundary.
- **GIST overlap constraint on bookings** (D-065 / D-066). Session 2's reserve-then-pay pattern relies on it during the Checkout window.
- **`Booking::shouldSuppressCustomerNotifications()` / `shouldPushToCalendar()` (D-088, D-083)** — every booking mutation site uses them. PAYMENTS extends; never bypasses.
- **Tenant context via `App\Support\TenantContext` (D-063)** — never inject `Business` from the request directly; read via `tenant()`. Cross-tenant access is a 403 (authz) or 404 (tenant-scoped `findOrFail`) — never a silent read, never a silent write (locked roadmap decision #45).
- **Shared Inertia props on `auth.business`** — `subscription` (MVPC-3), `role` and `has_active_provider` (MVPC-4), `connected_account` (PAYMENTS Session 1 — D-114).

---

## Test / build commands

Iteration loop (agents):
```bash
php artisan test tests/Feature tests/Unit --compact
vendor/bin/pint --dirty --format agent
php artisan wayfinder:generate
./vendor/bin/phpstan
npm run build
```

Full suite (developer, pre-push):
```bash
php artisan test --compact
```

---

## Open follow-ups

See `docs/BACKLOG.md`. Most relevant post-MVP carry-overs:

- **Formal support-contact surface (PAYMENTS Session 1, D-118)**. Connected Account disabled-state CTA mailtos a placeholder; pre-launch needs a real flow.
- **Resend payment link for `unpaid` customer_choice bookings** (PAYMENTS Session 2 carry-over).
- **Tighten billing freeload envelope** (MVPC-3 D-089 — `past_due` write-allowed window bounded by Stripe's dunning).
- **WeekScheduleEditor rename** from `components/onboarding/` to `components/settings/` (MVPC-4 cleanup).
- **R-16 frontend code splitting pass** on the whole bundle (the >500 kB Vite warning is pre-existing and unrelated to PAYMENTS Session 1).
- **Calendar carry-overs** (R-17, R-19, R-9 items).
- **Prune old decisions**. `DECISIONS-HISTORY.md` plus anything superseded by D-080+. Judgment call per entry.
