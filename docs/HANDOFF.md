# Handoff

**State (2026-04-23):** PAYMENTS Session 2a (Payment at Booking — Happy Path) implemented and staged; awaiting developer review + commit. The active `docs/ROADMAP.md` (PAYMENTS) drives the remaining four sessions: 2b, 3, 4, 5.

**Branch**: main.
**Feature+Unit suite**: 829 passed / 3277 assertions measured 2026-04-23 after Session 2a + Codex adversarial Round 1 + Codex native Round 2 + close checklist pass (baseline 777 / 3124 at Session 1 close; Session 2a added 52 tests / 153 assertions across exec + both review rounds + close GIST slot-lock test).
**Full suite**: 2+ minutes because of `tests/Browser`. Developer pre-push check.
**Tooling**: Pint clean. PHPStan (level 5, `app/` only) clean. Vite build clean (main app chunk 547 kB; pre-existing >500 kB warning unaffected). Wayfinder regenerated.

---

## What shipped

MVP (Sessions 1–11) + MVP Completion (MVPC-1..5): data layer, scheduling engine, frontend foundation, auth, onboarding, public booking, dashboard, settings, email notifications, calendar view, Google OAuth, bidirectional Google Calendar sync, Cashier subscription billing, provider self-service settings, advanced calendar interactions. E2E-0..6 also shipped.

**PAYMENTS Session 1 (2026-04-23, commit `b520250`):**
- Stripe Connect Express onboarding: `stripe_connected_accounts` table + model + `Business::canAcceptOnlinePayments()` / `stripeConnectedAccount()` relation. Partial unique index `(business_id) WHERE deleted_at IS NULL` (D-122), `withTrashed`-aware webhooks, per-attempt nonce on `accounts.create` idempotency key (D-125), signed-URL return flow with tenant-scope re-pinning (D-142, D-147, D-148).
- Settings → Connected Account page with not_connected / pending / incomplete / active / disabled / unsupported_market branches (D-150).
- `POST /webhooks/stripe-connect` controller with `DedupesStripeWebhookEvents` trait (D-110) and per-source cache prefix `stripe:connect:event:`. Handlers: `account.updated` (lock+retrieve+compare), `account.application.deauthorized` (withTrashed + reconnect-safe demotion per D-139), `charge.dispute.*` (persists a `payment.dispute_opened` Pending Action per D-123 / D-126).
- `config/payments.php` with `supported_countries` / `default_onboarding_country` / `twint_countries`. No hardcoded `'CH'` literal anywhere.
- `pending_actions` generalised (calendar + payment types coexist); `PendingActionType::calendarValues()` is the calendar-bucket filter.
- Inertia shared prop `auth.business.connected_account = {status, country, can_accept_online_payments, payment_mode_mismatch}` (D-114).
- `UpdateBookingSettingsRequest::paymentModeRolloutRule()` hard-blocks non-offline `payment_mode` except idempotent passthrough (D-132).
- `FakeStripeClient` split into platform-level (header ABSENT) and a documented Session 2+ contract for connected-account-level (header PRESENT).

**PAYMENTS Session 2a (this session, 2026-04-23):**
- `PaymentStatus` enum extended to final set (`NotApplicable`, `AwaitingPayment`, `Paid`, `Unpaid`, `Refunded`, `PartiallyRefunded`, `RefundFailed`). The pre-2a `Pending` case was retired outright per D-155 (pre-launch `migrate:fresh` resets dev data). `Booking` gains eight columns via migration `2026_04_23_174426_add_payment_columns_to_bookings.php`: `stripe_checkout_session_id` / `stripe_payment_intent_id` / `stripe_charge_id` (all nullable unique), `paid_amount_cents`, `currency`, `paid_at`, `payment_mode_at_creation` (default 'offline'), `expires_at`. Plus an index `(business_id, expires_at)` for Session 2b's reaper. All three existing writers (`PublicBookingController::store`, `Dashboard\BookingController::store`, `PullCalendarEventsJob`) now write `payment_mode_at_creation` explicitly per locked decisions #14 / #30.
- `PublicBookingController::store` online-payment branch: when the Business payment mode is `online` (or `customer_choice` with `payment_choice = 'online'`) and the service price > 0 and `canAcceptOnlinePayments()` is true, the booking row commits as `pending + AwaitingPayment` with `paid_amount_cents`, `currency` (from connected account default), and `expires_at = now + 90min` (only when snapshot='online' per locked decision #13). Then the country assertion + `CheckoutSessionFactory::create` mint the Checkout session on the connected account; on any failure the booking is marked Cancelled to release the slot. Snapshot invariant per locked decision #14 enforced throughout (customer_choice + pay-on-site still snapshots `'customer_choice'`, NOT `'offline'`).
- `App\Services\Payments\CheckoutSessionFactory` owns the Stripe call: direct-charge via `['stripe_account' => $acct]` header (locked decision #5), `payment_method_types = ['card', 'twint']` driven by `config('payments.twint_countries')` (D-154), `line_items` from `Service` + connected account currency, `metadata` carries `riservo_booking_id` / `riservo_business_id` / `riservo_payment_mode_at_creation`, locale resolved to one of `['it', 'de', 'fr', 'en']` or `'auto'` fallback (locked decision #39), `expires_at = now + 90min`. Country assertion via `assertSupportedCountry` throws `UnsupportedCountryForCheckout` before any API call (D-151, locked decision #43 defense-in-depth).
- `App\Services\Payments\CheckoutPromoter` is the **single source of truth** for "promote an awaiting-payment booking given a retrieved Stripe session" (D-151). Both the webhook handler and the success-page return controller call `promote($booking, $session): 'paid'|'already_paid'|'not_paid'`. The service wraps the state transition in `DB::transaction` + `lockForUpdate` on the booking row (pattern D-148), performs outcome-level `PaymentStatus::Paid` guard inside the lock (locked decision #33), branches on `$session->payment_status` not on event name (locked decision #41 — TWINT async-hedge), logs+overwrites on `paid_amount_cents` / `currency` mismatch (D-152), and dispatches notifications: `BookingConfirmedNotification` + `BookingReceivedNotification('new')` for auto-confirmation, or the new `BookingReceivedNotification('paid_awaiting_confirmation')` for manual-confirmation businesses (locked decision #29). Scalar return shape avoids PHPStan level-5 generic-inference issues on `DB::transaction` callbacks.
- `POST /bookings/{token}/payment-success` + `/payment-cancel` routes served by `BookingPaymentReturnController`. `success()` cross-checks `session_id` query against the booking's persisted `stripe_checkout_session_id` (D-153 — hostile-substitution defence), short-circuits on already-paid, resolves the connected account via `withTrashed` (locked decision #36 — disconnect race), performs `checkout.sessions.retrieve(['stripe_account' => $acct])` (locked decision #32), delegates to `CheckoutPromoter`, and either redirects to `bookings.show` (paid / already-paid) or renders the `booking/payment-success` Inertia page with `state: 'processing'` (async-pending). `cancel()` is the Session 2a stub — Session 2b wires the state transition on the webhook path.
- `StripeConnectWebhookController::dispatch` gains the `checkout.session.completed` + `checkout.session.async_payment_succeeded` arms. `handleCheckoutSessionCompleted` performs pre-promotion guards (valid `client_reference_id`, known booking, cross-account match via `withTrashed`, outcome-level fast-path) and delegates to `CheckoutPromoter`. Unknown-booking replays log critical + 200 (dedup cache does NOT cache 2xx-with-critical; operator investigation signal).
- `FakeStripeClient` gains `mockCheckoutSessionCreateOnAccount` + `mockCheckoutSessionRetrieveOnAccount` on the connected-account-level bucket; both assert `['stripe_account' => $expectedAccountId]` is PRESENT in per-request options (fails as Mockery "method not expected" otherwise — D-109's contract).
- `BookingReceivedNotification` accepts the new `'paid_awaiting_confirmation'` context (locked decision #29). `mail/booking-received.blade.php` renders the customer-facing "payment received, business will confirm" copy.
- Public booking UI: `booking/show` page forwards `payment_mode` / `can_accept_online_payments` / `currency` props; `booking-summary.tsx` renders "Continue to payment" CTA for the online branch, an inline Pay-now / Pay-on-site pill for `customer_choice`, and does external `window.location.href = result.redirect_url` when the response URL starts with `https://`. `bookings/show` surfaces payment badge + paid amount + "awaiting payment" copy. `booking/payment-success.tsx` renders the processing spinner for async-pending sessions. TS types extended (`PublicBusiness`, `BookingStoreResponse`, `BookingDetail`).
- `StorePublicBookingRequest` accepts optional `payment_choice` (`'online'|'offline'`) forwarded only in `customer_choice` mode.
- `docs/DEPLOYMENT.md` updated: promotes `checkout.session.completed` + `checkout.session.async_payment_succeeded` from "reserved for Session 2+" to "active in Session 2a"; documents the TWINT test-mode flow + the `stripe listen --connect` forwarding command for local dev.
- Tests: 42 new cases across `tests/Feature/Booking/OnlinePaymentCheckoutTest.php` (12 — online / customer_choice / offline / price-null / country drift / API failure / snapshot invariant / manual + google carve-out), `tests/Feature/Booking/CheckoutSuccessReturnTest.php` (8 — happy path / webhook-beat-us / processing / session_id mismatch / Stripe timeout / disconnect race / manual-confirmation variant), `tests/Feature/Payments/CheckoutSessionCompletedWebhookTest.php` (10 — happy / stale replay / outcome-guard / manual-confirm / async hedge / async-success / cross-account mismatch / disconnect race / unknown booking / cache-prefix isolation), `tests/Unit/Services/Payments/CheckoutSessionFactoryTest.php` (11 including the 4-locale dataset — supported-country assertion / TWINT branch / card-only fallback / locale matrix / auto fallback / metadata shape), `tests/Unit/Services/Payments/CheckoutPromoterStructuralTest.php` (1 — source inspection asserting `DB::transaction` + `lockForUpdate` + `PaymentStatus::Paid`).

**Codex adversarial Round 1 (2026-04-23, applied on the same uncommitted diff)**: three findings surfaced — F1 rejected as roadmap-scope (Session 2b owns the reaper + `checkout.session.expired` webhook arm + `cancel_url` state transitions per locked decisions #13 / #14 / #31); F2 accepted + fixed (D-156 — `CheckoutPromoter::promote` now fails closed on session-id / amount / currency mismatch, supersedes D-152's log-and-overwrite stance; critical log + `'mismatch'` outcome added; both webhook and success-page callers handle the new outcome; three new regression tests); F3 accepted + fixed (D-157 — `BookingManagementController::cancel` refuses `payment_status = Paid` bookings with a "contact the business" error flash until Session 3 ships `RefundService`; one new regression test). See `docs/PLAN.md` `## Review — Round 1` for the full record.

**Codex native Round 2 (2026-04-23, applied on the same uncommitted diff)**: five findings — one rejected (same roadmap-scope argument as R1 F1 for the reaper/expiry-webhook), four accepted + fixed. D-158: new `bookings.stripe_connected_account_id` column pins the minting account id on the booking at Checkout-session creation; webhook + success-page cross-account guards now read the pinned id instead of `withTrashed()->value()` against business (fixes the non-determinism across reconnect history). D-159: customer-authenticated path (`Customer\BookingController::cancel`) AND dashboard admin path (`Dashboard\BookingController::updateStatus`) now refuse paid-booking cancellation — extends D-157 to every endpoint so no `cancelled + paid` rows can be produced until Session 3 ships `RefundService`. D-160: payment-success flash copy branches on `$booking->status` (Pending → "pending confirmation"; Confirmed → "confirmed") so manual-confirmation landings don't contradict the paid_awaiting_confirmation email. D-161: booking store response carries an explicit `external_redirect: bool` so the React client doesn't rely on an `https://`-prefix heuristic (which would mistakenly hard-navigate offline-path redirects on HTTPS-deployed riservo). Five new regression tests across three files. See `docs/PLAN.md` `## Review — Round 2` for the full record.

**New architectural decisions** (D-151..D-161 — 11 total, promoted this session). D-152 superseded by D-156. D-157 partial scope extended by D-159.

83 architectural decisions (D-080–D-161) recorded across `docs/decisions/DECISIONS-*.md`. Next free decision ID: **D-162**.

---

## What is next

`docs/ROADMAP.md` — **PAYMENTS Session 2b, Payment at Booking (Failure Branching + Admin Surface)**. The roadmap session under `## Session 2b — Payment at Booking (Failure Branching + Admin Surface)` is the brief.

Prerequisites met: Session 2a ships `PaymentStatus::AwaitingPayment` / `Unpaid` / `RefundFailed`, the `expires_at` column, `CheckoutPromoter` (which Session 2b's reaper pre-flight-retrieve calls), the `mockCheckoutSessionCreateOnAccount` / `mockCheckoutSessionRetrieveOnAccount` helpers, and the snapshot-column writers. Session 2b layers on:

- Failure webhook arms: `checkout.session.expired`, `checkout.session.async_payment_failed`, `payment_intent.payment_failed` with the online / customer_choice branching per locked decision #14 (online → Cancelled + slot released; customer_choice → Confirmed + Unpaid).
- 90-minute reaper `bookings:expire-unpaid` with the grace-buffer + pre-flight retrieve + late-webhook refund defense-in-depth (locked decision #31).
- `cancel_url` state transitions (Session 2a has a stub).
- Minimal `booking_refunds` table + `RefundService` skeleton (one reason: `cancelled-after-payment`, wired end-to-end per D-155's sibling decisions).
- Admin dashboard touch-ups: booking-detail payment panel, bookings-list Payment filter chips, Pending Action banners for `payment.cancelled_after_payment`.

Sessions 3 → 5 follow per the roadmap. The dispute-pipeline email + UI wiring in Session 3 will extend the Pending Actions Session 1 already persists.

Parked in `docs/roadmaps/`: `ROADMAP-E2E.md` (ongoing coverage) and `ROADMAP-GROUP-BOOKINGS.md` (post-MVP, not scheduled).

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

- **Stripe SDK mocking via container binding (D-095)**. Every Stripe call flows through `app(StripeClient::class, ...)`. Tests mock via the `FakeStripeClient` builder in `tests/Support/Billing/`. Session 2a extended the connected-account-level bucket with `mockCheckoutSessionCreateOnAccount` + `mockCheckoutSessionRetrieveOnAccount` (header PRESENT asserted). Sessions 2b+ extend the same bucket with `mockRefundCreate` + `mockBalanceRetrieve` etc.
- **Connect webhook at `/webhooks/stripe-connect` (D-109)**. NOT a Cashier subclass. Signature verified against `STRIPE_CONNECT_WEBHOOK_SECRET`. Session 2a added `checkout.session.completed` + `checkout.session.async_payment_succeeded` arms to the existing `dispatch()` `match`. Sessions 2b / 3 add their handlers the same way (2b: `checkout.session.expired`, `payment_intent.payment_failed`, etc.; 3: `charge.refunded`, `charge.refund.updated`, `refund.updated`). Cache prefix `stripe:connect:event:` (D-110); cannot collide with the subscription cache.
- **`account.*` handlers re-fetch via `accounts.retrieve()` (locked roadmap decision #34)**. The webhook payload is treated as a nudge; the authoritative state is whatever Stripe currently reports. Out-of-order delivery converges automatically.
- **Outcome-level idempotency (locked roadmap decision #33)**. Every webhook handler additionally re-checks DB state at the top so replays / inline-promotion races never double-write. `StripeConnectedAccount::matchesAuthoritativeState()` is the reusable shape for `account.*`; `CheckoutPromoter::promote` is the shape for `checkout.session.*` (D-151). Refund / dispute handlers in 2b/3 follow the same pattern with their own state fields.
- **`CheckoutPromoter` is the single promotion service (D-151)**. Webhook + success-page + Session 2b reaper pre-flight all converge on `promote($booking, $session)`. Do NOT inline promotion logic in new code paths — extend the service.
- **`payment_mode_at_creation` snapshot invariant (locked decision #14 + D-151's reliance)**. Mirrors `Business.payment_mode` at booking creation, NEVER the customer's Checkout-step choice. Sole carve-out: `source ∈ {manual, google_calendar}` always writes `'offline'` (locked decision #30).
- **`paid_amount_cents` + `currency` captured at booking creation (D-152)**. Refunds (Sessions 2b/3) read the columns; they must be populated before any webhook runs. Stripe is authoritative at promotion — mismatch logs critical + overwrites.
- **`pending_actions` is generalised (D-113)**. Calendar-typed and payment-typed rows coexist on the same table; `integration_id` is nullable. Calendar-aware readers MUST filter via `PendingActionType::calendarValues()`.
- **Country gating reads `config('payments.supported_countries')` (D-112 + locked decision #43)**. No hardcoded `'CH'` literal anywhere in app code, tests, Inertia props, or Tailwind. Session 2a's Checkout creation carries the assertion (D-151); Sessions 4 + 5 inherit it via `canAcceptOnlinePayments()`.
- **TWINT inclusion driven by `config('payments.twint_countries')` (D-154)**. No Stripe capability introspection. Extending the supported set is a config flip plus ops verification.
- **Disconnect retains `stripe_account_id` on the soft-deleted row (locked decision #36)**. Session 2a's webhook handler + success-page controller use `StripeConnectedAccount::withTrashed()` when resolving the account for a booking — the late-webhook + post-disconnect return paths both work. Session 2b's late-webhook refund path reads the same column.
- **`billing.writable` middleware (D-090) wraps every mutating dashboard route**. Public payment routes (`/bookings/*/payment-*`) live OUTSIDE the auth block — unauthenticated customers return from Stripe; the token is the bearer secret (D-153).
- **Server-side automation runs unconditionally**, even for read-only businesses. `AutoCompleteBookings`, `SendBookingReminders`, `calendar:renew-watches`, `PullCalendarEventsJob`, `StripeWebhookController`, `StripeConnectWebhookController` — all keep firing for existing data regardless of subscription state. Session 2b's reaper follows the same convention.
- **Direct charges on connected accounts for PAYMENTS** (locked roadmap decision #5). Professionals are merchant of record; `Stripe-Account` header on every Connect call (Session 2a enforced via `CheckoutSessionFactory::create`); no `application_fee_amount`. The FakeStripeClient split enforces this distinction at the test boundary.
- **GIST overlap constraint on bookings** (D-065 / D-066). Session 2a's `pending + awaiting_payment` row participates in the exclusion (status IN (pending, confirmed)); Cancelled status on Checkout-creation failure releases the slot. Session 2b's reaper relies on the same behaviour.
- **`Booking::shouldSuppressCustomerNotifications()` / `shouldPushToCalendar()` (D-088, D-083)** — every booking mutation site uses them. Session 2a's `CheckoutPromoter::dispatchNotifications` guards on `shouldSuppressCustomerNotifications()` defensively even though online-payment bookings can never be `google_calendar` sourced.
- **Tenant context via `App\Support\TenantContext` (D-063)** — never inject `Business` from the request directly; read via `tenant()`. Cross-tenant access is a 403 (authz) or 404 (tenant-scoped `findOrFail`) — never a silent read, never a silent write (locked roadmap decision #45). Public payment routes are slug-based (BookingManagementController / BookingPaymentReturnController) — they resolve the Business via the booking's FK; no tenant context required.
- **Shared Inertia props on `auth.business`** — `subscription` (MVPC-3), `role` and `has_active_provider` (MVPC-4), `connected_account` (Session 1 D-114). Session 2a does NOT extend this — the public booking page is unauthenticated and reads its own explicit business payload.

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
- **Per-Business country selector for Stripe Express onboarding (D-121 superseded by D-141)**. The canonical column exists; a future UI step lets admins pick the country during business onboarding.
- **Collect country during business onboarding** (D-141 / D-143 follow-up). Seeds `'CH'` today; UX follow-up for non-CH markets.
- **Pre-role-middleware signed-URL session pinner (D-147 false-negative)**. Multi-business admins whose session is pinned to a staff-only tenant can still 403 at `role:admin` even when the signed URL is legitimate.
- **Tighten billing freeload envelope** (MVPC-3 D-089 — `past_due` write-allowed window bounded by Stripe's dunning).
- **WeekScheduleEditor rename** from `components/onboarding/` to `components/settings/` (MVPC-4 cleanup).
- **R-16 frontend code splitting pass** on the whole bundle (the >500 kB Vite warning is pre-existing and unrelated).
- **Calendar carry-overs** (R-17, R-19, R-9 items).
- **Prune old decisions**. `DECISIONS-HISTORY.md` plus anything superseded by D-080+. Judgment call per entry.
