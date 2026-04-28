# ROADMAP — E2E testing for PAYMENTS (Sessions 1–5 + race / race-banner)

> Status: **parked**. Activate by promoting to `docs/ROADMAP.md` when the developer decides to extend automated E2E coverage onto the PAYMENTS surfaces.
> Scope: Pest 4 Browser E2E tests for every user-visible flow introduced by the PAYMENTS roadmap (Sessions 1–5 shipped 2026-04-23 / 2026-04-24). Complements the Feature + Unit + Browser test coverage already present (963 / 4173).
> Format: WHAT only. The HOW is decided per session by the implementing agent in `docs/PLAN.md`.
> Related: `docs/roadmaps/ROADMAP-E2E.md` — the pre-existing E2E roadmap that shipped E2E-0..E2E-6 (everything non-PAYMENTS). This new roadmap picks up where that one stopped and focuses exclusively on the PAYMENTS surfaces.
> Related: `docs/TESTING-STRIPE-END-TO-END.md` — the **manual** sandbox walkthrough (the human-driven counterpart to this automated roadmap). Keep both — they cover different layers.

---

## Overview

PAYMENTS Sessions 1–5 introduced seven new user-visible surfaces (Connected Account onboarding, public online-payment booking, failure/cancel-URL branching, customer_choice picker, admin refund + dispute UI, payouts page, race banner). Each surface has Feature test coverage and in most cases a Browser smoke, but there is **no automated user-flow E2E test** that exercises the full click-through journey (admin enables online payments → customer books online → webhook lands → booking promotes → customer sees confirmation → admin can refund → webhook updates → customer sees refund status).

This roadmap sequences a single-agent workflow to close that gap. Sessions are strictly dependent on the one before them: each new session reuses fixtures / helpers built by the previous one. All tests run in CI against the project's `FakeStripeClient` (already established in Feature tests, Session 2a) adapted to Pest 4 Browser via a small helper; **no real Stripe calls** happen in the automated E2E run. Real sandbox testing is the domain of `docs/TESTING-STRIPE-END-TO-END.md`, which stays in parallel as the human verification guide for pre-launch.

Seven sessions proposed:

| # | Session | Prerequisites | Outcome |
|---|---------|---------------|---------|
| E2E-P0 | Setup — `FakeStripeClient` for Browser, fixtures, page objects | ROADMAP-E2E E2E-0 (existing infra) | A `tests/Browser/Support/Payments/` helpers package usable by every subsequent session |
| E2E-P1 | Stripe Connect Express onboarding | E2E-P0 | Admin enables online payments → returns from mocked Stripe-hosted KYC → sees "Active" state |
| E2E-P2 | Public booking happy path (`payment_mode = online`) | E2E-P1 | Customer walks the full booking → mocked Stripe Checkout → success page shows `confirmed + paid` |
| E2E-P3 | Checkout failure branches + expiry reaper | E2E-P2 | Expired / failed Checkout produces the right cancel-URL copy and webhook-promoted state |
| E2E-P4 | `customer_choice` picker + pay-on-site fallback | E2E-P2 | Customer picks online → Checkout; picks offline → no Checkout, booking lands offline |
| E2E-P5 | Refunds (customer cancel, admin manual, business cancel) + disputes | E2E-P2, E2E-P3 | Full refund lifecycle verified end-to-end for all three initiators; dispute PA surfaced to admin |
| E2E-P6 | Payouts page + connected-account health | E2E-P1 | Admin sees balance, recent payouts, health chips, login-link opens new-tab flow |
| E2E-P7 | `payment_mode` toggle gate + race banner (D-176) | E2E-P1, E2E-P2 | Settings → Booking disables options with correct tooltip; public race banner fires pre-submit + post-submit |

Alternatives considered briefly:

- **Playwright instead of Pest Browser** — rejected: the project already standardised on Pest 4 Browser in `ROADMAP-E2E.md` (E2E-0) and has 6 sessions of Browser-test muscle. A second tool would split the coverage discipline.
- **Full sandbox Stripe in CI** — rejected: a real-Stripe CI run needs the Stripe CLI to forward webhooks, which doesn't fit GitHub Actions cleanly; flakiness cost outweighs realism gain when `FakeStripeClient` already reproduces every handshake faithfully.
- **Combined mega-session** — rejected: the PAYMENTS surface is too broad (~7 flows, each with 3–5 scenarios). A single session would exceed the ~2-hour single-agent scope the project uses.

---

## Cross-cutting decisions locked in this roadmap

These decisions apply to every session and must not be re-litigated. Each must be recorded with a fresh `D-NNN` in `docs/decisions/DECISIONS-PAYMENTS.md` (or a new `DECISIONS-TESTING.md` if the implementing agent prefers). The next available `D-NNN` is whatever `docs/HANDOFF.md` states at session time.

1. **Pest 4 Browser is the test runner.** Not Playwright, not Cypress, not Laravel Dusk. Every session's deliverable must produce Pest Browser tests that run via `php artisan test tests/Browser --compact`. Locked.

2. **FakeStripeClient is the only Stripe surface.** Every E2E test registers `FakeStripeClient::for($this)` in `beforeEach`, expresses its expected Stripe interactions via the existing helpers (`mockAccountCreate`, `mockCheckoutSessionCreateOnAccount`, etc.), and asserts the full call graph completes. No real Stripe calls in CI. Locked.

3. **The Stripe-hosted pages (onboarding, Checkout, Express dashboard login) are mocked at the controller boundary, not via browser-side interception.** When a riservo controller would redirect to `https://connect.stripe.com/...` or `https://checkout.stripe.com/...`, the FakeStripeClient returns a stub URL the E2E harness recognises as "external — stop here and assume success". The test then injects the "return from Stripe" path (webhook + return-URL GET) directly. This keeps the automated run deterministic; the `TESTING-STRIPE-END-TO-END.md` manual guide is where real hosted-page verification happens. Locked.

4. **Webhook events are replayed via HTTP POST to `/webhooks/stripe-connect` with a faked signature.** Pest Browser's HTTP client is available via `$this->postJson()` inline with browser steps; the `StripeConnectWebhookController` already accepts mocked signatures when `FakeStripeClient` is bound. Each E2E test triggers the webhook events it needs at the point in the flow where Stripe would have sent them (e.g. after the "return from Checkout" redirect). Locked.

5. **Fixtures are factory-based, not seeder-based.** Reuse the `Business::factory()->onboarded()`, `StripeConnectedAccount::factory()->active()`, `Service::factory()`, etc. patterns already established in Feature tests. No shared DB seed between E2E tests — each test builds its own world. Locked.

6. **Fail-fast on Stripe mocks mismatch.** If a test invokes a Stripe API that has no `mockXxx` registration on the active FakeStripeClient, the test fails immediately with a clear Mockery assertion. No silent fall-through to real Stripe. Locked.

7. **Every session's deliverable leaves `tests/Browser --compact` green AND preserves the `tests/Feature + tests/Unit` baseline unchanged.** E2E tests must not mutate shared state (no `Config::set` without cleanup, no factory overrides that leak). Locked.

8. **Tests cover user-facing flows, not internal state.** Assertions like `$booking->fresh()->payment_status` are discouraged in E2E (they exist in Feature tests); prefer `$page->assertSee('Paid')` or a visible badge selector. E2E's value is "what the user sees"; internal state is Feature's job. Locked.

9. **No coverage of the dispute evidence upload flow.** Locked roadmap decision #25 punts that to Stripe's dashboard. E2E-P5 covers "dispute PA appears + admin is notified + deep-link opens" — not evidence upload. Locked.

10. **No coverage of real-browser TWINT completion.** TWINT in test mode renders in the Stripe Checkout UI but cannot be completed without a real TWINT app (see `TESTING-STRIPE-END-TO-END.md` Part 0). The E2E tests verify the TWINT badge renders on the public booking summary; Checkout completion is always via the `4242...` test card (mocked). Locked.

---

## Session E2E-P0 — Setup: `FakeStripeClient` for Browser, fixtures, page objects

**Owner**: single agent. **Prerequisites**: `docs/roadmaps/ROADMAP-E2E.md` E2E-0 shipped (Pest 4 Browser infra + CI config present). **Unblocks**: every subsequent E2E-P session.

### Deliverable checklist
- [ ] Create `tests/Browser/Support/Payments/` package with:
  - [ ] `PaymentsTestCase.php` — Pest-style base class (or trait) that wires `FakeStripeClient::for($this)` + `RefreshDatabase` + a `PaymentsWorld` setup helper
  - [ ] `PaymentsWorld::class` — fluent fixture builder that produces a Business + admin + service + provider + connected account in a single call (`PaymentsWorld::default()->withActiveStripeAccount()->withOnlinePaymentMode()->build()`)
  - [ ] Page objects: `ConnectedAccountPage`, `BookingPage`, `BookingSummaryStep`, `PaymentSuccessPage`, `BookingManagementPage`, `DashboardBookingDetail`, `PayoutsPage`, `SettingsBookingPage`
- [ ] Extend `FakeStripeClient` to optionally operate in "browser mode": when registered via `FakeStripeClient::forBrowser($test)`, the mocks tolerate `stripe listen`-shaped webhook POSTs triggered from within the same test (no Mockery "method not expected" on incoming webhooks). The Feature-test mode remains the default.
- [ ] Webhook helper: `$this->dispatchStripeConnectEvent('checkout.session.completed', $payload)` — builds a canonical event payload, POSTs to `/webhooks/stripe-connect` with a faked-valid signature, asserts 200.
- [ ] Stub-URL registry: `FakeStripeClient` mocks for `createLoginLink`, `createAccountLink`, `createCheckoutSession` all return a well-known stub URL pattern (`https://stripe.test/external/{uuid}`) so E2E tests can assert "redirect to external Stripe" without hard-coding Stripe's domain.
- [ ] Document the helpers in `tests/Browser/Support/Payments/README.md` with usage snippets for every page object.
- [ ] Add a single smoke E2E test that proves the helpers wire correctly: `admin sees connected account page with active state when PaymentsWorld::default()->withActiveStripeAccount() is set up`.

### Testable surfaces confirmed via smoke
- GET `/dashboard/settings/connected-account` (as admin)

### Out of scope
- Any PAYMENTS flow beyond the smoke test — that's E2E-P1..E2E-P7.

---

## Session E2E-P1 — Stripe Connect Express onboarding

**Owner**: single agent. **Prerequisites**: E2E-P0. **Unblocks**: E2E-P2, E2E-P6, E2E-P7.

Cover the full Connected Account surface: not-connected → create account → (mocked) Stripe-hosted KYC → return → active → refresh → disconnect. Webhook-driven state transitions are tested here because they're integral to the user-visible flow.

### Testable endpoints
- `GET /dashboard/settings/connected-account` — `Dashboard\Settings\ConnectedAccountController@show`
- `POST /dashboard/settings/connected-account` — `@create` (mocked: starts Stripe onboarding → redirect)
- `GET /dashboard/settings/connected-account/refresh` — `@refresh` (signed URL, Stripe return)
- `POST /dashboard/settings/connected-account/resume` — `@resume` (admin-triggered re-onboarding)
- `GET /dashboard/settings/connected-account/resume-expired` — `@resumeExpired` (Stripe refresh_url)
- `DELETE /dashboard/settings/connected-account` — `@disconnect`
- `POST /webhooks/stripe-connect` — events `account.updated`, `account.application.deauthorized`

### Test checklist
- [ ] **Happy path — onboarding**:
  - [ ] admin visits Connected Account page in "not-connected" state, sees "Enable online payments" CTA
  - [ ] admin clicks CTA → controller calls `accounts.create` + `accountLinks.create` (mocked), returns redirect to stub Stripe URL
  - [ ] test injects the "return from Stripe" via `GET /dashboard/settings/connected-account/refresh?account=acct_stub` with a valid signed URL; FakeStripeClient `mockAccountRetrieve` returns a fully-active account
  - [ ] admin lands back on Connected Account page showing Active chip + CH country + "Disconnect" button
- [ ] **Pending state**: account created but KYC not submitted → page shows "Continue Stripe onboarding" CTA + requirements list; clicking re-mints an Account Link
- [ ] **Expired-link refresh_url**: Stripe calls our `resume-expired` URL when the Account Link expires; test asserts a fresh Account Link is re-minted and the admin is redirected back into Stripe onboarding (mocked)
- [ ] **Disabled state**: account with `requirements_disabled_reason = 'rejected.fraud'` shows the disabled panel with Stripe's verbatim reason + "Contact support" CTA; business's `payment_mode` is forced back to offline by the `account.updated` webhook (if it was non-offline)
- [ ] **Disconnect**: admin clicks Disconnect → confirmation dialog → submits → Connected Account row soft-deleted, business's `payment_mode` forced to offline, page shows not-connected state
- [ ] **Webhook-driven demotion**: admin has `payment_mode = online` and an active Stripe account; webhook `account.updated` fires with `requirements_disabled_reason` non-null → business's `payment_mode` auto-demoted to offline; next page load shows the dashboard-wide `paymentModeMismatch` banner
- [ ] **Cross-tenant denial** (locked decision #45): admin of Business A cannot hit Business B's connected-account routes (404 via tenant scope); applies to GET, POST, DELETE, refresh, resume, resume-expired
- [ ] **Staff 403**: staff user is 403ed on every connected-account route

### Files to create / modify
- `tests/Browser/Payments/ConnectedAccountOnboardingTest.php` (new — ~10 test cases)

### Out of scope
- Dispute webhook surfaces (E2E-P5)
- `checkout.session.*` webhooks (E2E-P2, E2E-P3)
- Multi-business admin onboarding friction (locked decision #22 — backlog entry)

---

## Session E2E-P2 — Public booking happy path (`payment_mode = online`)

**Owner**: single agent. **Prerequisites**: E2E-P1 (connected account exists). **Unblocks**: E2E-P3, E2E-P4, E2E-P5, E2E-P7.

Cover the full customer-side online-payment flow for a business with `payment_mode = online`: visit `/{slug}` → pick service → pick slot → customer info → summary with "Continue to payment" CTA → (mocked) Stripe Checkout → return + webhook → success page shows `confirmed + paid`.

### Testable endpoints
- `GET /{slug}` — `Booking\PublicBookingController@show`
- `GET /booking/{slug}/providers`, `/slots`, `/available-dates` (JSON helpers)
- `POST /booking/{slug}/book` — `@store`
- `GET /bookings/{token}/payment-success` — `@paymentSuccess`
- `POST /webhooks/stripe-connect` — events `checkout.session.completed`, `checkout.session.async_payment_succeeded`, `payment_intent.succeeded`, `charge.succeeded`

### Test checklist
- [ ] **Happy path — card**:
  - [ ] customer visits `/{slug}`, picks service, picks date+time, fills name/email/phone
  - [ ] summary step renders: "Continue to payment →", "Your card will be charged CHF X.XX on the next step.", "Secured by Stripe" + TWINT badge
  - [ ] customer clicks CTA → controller creates booking at `pending + awaiting_payment` → mints Checkout session (mocked) → redirects to stub URL
  - [ ] test dispatches `checkout.session.completed` webhook → booking promotes to `confirmed + paid`
  - [ ] test visits the success URL → page renders Confirmed + Paid + CHF amount
  - [ ] customer email dispatched (assert via `Notification::fake`)
- [ ] **Success-page synchronous retrieve** (locked decision #32): test visits the success URL BEFORE the webhook has fired; the controller's inline `sessions.retrieve` (mocked paid) promotes the booking and renders Confirmed + Paid without polling
- [ ] **Manual-confirmation** (`confirmation_mode = manual` business): webhook sets `payment_status = paid` but leaves `status = pending`; page shows "Payment received. Your booking is pending the business's confirmation."
- [ ] **TWINT badge visibility**: test asserts the TWINT pill renders next to "Secured by Stripe" when `business.twint_available = true` (CH account). For a DE-active account in `supported_countries = ['CH', 'DE']`, the TWINT pill is absent.
- [ ] **Service price = 0 or null** (locked decision #8): the booking falls through to offline path even when `payment_mode = online`; no Checkout session; booking lands `confirmed + not_applicable`
- [ ] **GIST slot hold during Checkout window** (D-065 / D-066): while booking A is `pending + awaiting_payment`, booking B targeting the same slot is rejected with 422 + `slot_taken` key
- [ ] **Locale passthrough** (locked decision #39): customer visits `/{slug}` with IT locale → Checkout session is created with `locale = 'it'`; FakeStripeClient asserts the param
- [ ] **Booking confirmation email rendering** — snapshot the rendered email body and assert the refund-clause is absent (only cancel emails carry it per D-175)

### Files to create / modify
- `tests/Browser/Payments/OnlinePaymentHappyPathTest.php` (new — ~8 test cases)

### Out of scope
- Failure branches (E2E-P3)
- `customer_choice` picker (E2E-P4)
- Refunds (E2E-P5)

---

## Session E2E-P3 — Checkout failure branches + expiry reaper

**Owner**: single agent. **Prerequisites**: E2E-P2. **Unblocks**: E2E-P5.

Cover the Session 2b failure paths end-to-end: expired Checkout, failed payment intent, abandoned Checkout (customer hit cancel_url), and the reaper's defense-in-depth (grace buffer + pre-flight retrieve + late-webhook refund).

### Testable endpoints
- `GET /bookings/{token}/payment-cancel` — `Booking\BookingManagementController@paymentCancel`
- `POST /webhooks/stripe-connect` — events `checkout.session.expired`, `checkout.session.async_payment_failed`, `payment_intent.payment_failed`, `payment_intent.succeeded` (late-refund path), `charge.refunded` (late-refund settlement)
- Console command: `php artisan bookings:expire-unpaid` (or whatever `ExpireUnpaidBookings` binds to)

### Test checklist
- [ ] **`checkout.session.expired` for `payment_mode_at_creation = online`**: customer starts booking → times out → webhook fires → booking transitions to `cancelled + not_applicable`; slot released
- [ ] **`checkout.session.expired` for `customer_choice + pay-now-then-failed`**: webhook promotes to `confirmed + unpaid`; slot stays; booking-confirmed notifications fire
- [ ] **`payment_intent.payment_failed` (online)**: same as expired — booking cancelled, slot released
- [ ] **Manual confirmation × online × failed Checkout**: `confirmation_mode = manual` + `customer_choice` + failed Checkout → booking lands at `pending + unpaid` (locked decision #29 variant)
- [ ] **Cancel-URL landing (online)**: customer hits cancel_url → page shows "Payment not completed — your slot is released" + pre-selected service for one-click retry
- [ ] **Cancel-URL landing (customer_choice)**: page shows "Payment not completed — your booking is confirmed; pay at the appointment"
- [ ] **Reaper — grace buffer**: booking with `expires_at < now - 5 min` is targeted; booking with `expires_at < now` but within 5 min buffer is skipped
- [ ] **Reaper — pre-flight retrieve (paid)**: reaper calls `sessions.retrieve`, Stripe reports paid → booking is PROMOTED inline, cancel skipped
- [ ] **Reaper — pre-flight retrieve (expired)**: Stripe reports expired → reaper proceeds with cancel
- [ ] **Late-webhook refund** (locked decision #31): booking is cancelled by reaper, then `checkout.session.completed` arrives late → handler dispatches automatic refund via `RefundService::refund(..., 'cancelled-after-payment')`; admin sees the `payment.cancelled_after_payment` Pending Action banner on the booking detail page
- [ ] **Idempotency**: replaying the same `checkout.session.expired` event does NOT double-cancel

### Files to create / modify
- `tests/Browser/Payments/OnlinePaymentFailureBranchesTest.php` (new — ~10 test cases)

### Out of scope
- Normal customer-side refunds (E2E-P5)
- Disputes (E2E-P5)

---

## Session E2E-P4 — `customer_choice` picker + pay-on-site fallback

**Owner**: single agent. **Prerequisites**: E2E-P2. **Unblocks**: nothing directly.

Cover the `customer_choice` flow: picker renders on the summary step, both branches (pay-now and pay-on-site) produce the correct server state, and the snapshot invariant (locked decision #14) holds.

### Testable endpoints
- `GET /{slug}` (public booking page with picker)
- `POST /booking/{slug}/book` with `payment_choice = 'online'` or `'offline'` or absent
- `GET /bookings/{token}/payment-success`

### Test checklist
- [ ] **Picker renders**: business is `customer_choice`, CH account, eligible → summary step shows "Pay now / Pay on site" radio group; "Pay now" is the default selection
- [ ] **Customer picks online**: POST with `payment_choice = 'online'` → booking at `pending + awaiting_payment`, Checkout session mocked, redirect; `payment_mode_at_creation = 'customer_choice'` (NOT `'online'`)
- [ ] **Customer picks offline**: POST with `payment_choice = 'offline'` → no Checkout session (FakeStripeClient asserts no call), booking at `confirmed + not_applicable`, `payment_mode_at_creation = 'customer_choice'` (locked decision #14 three-outcome invariant)
- [ ] **Customer picks offline + manual-confirm business**: booking at `pending + not_applicable`
- [ ] **`customer_choice` with degraded account + `payment_choice` absent**: D-176 soft path — booking lands offline without 422 (the picker wasn't rendered because `onlinePaymentAvailable` was false at page-load)
- [ ] **`customer_choice` + Checkout failure + auto-confirm**: webhook fires `checkout.session.expired` → booking promotes to `confirmed + unpaid`; slot stays; Unpaid badge visible in dashboard
- [ ] **Snapshot invariant test in the browser**: assert via visible UI that the three outcomes produce the same `customer_choice` snapshot (visible on the dashboard booking detail panel or via an admin-visible audit chip)

### Files to create / modify
- `tests/Browser/Payments/CustomerChoiceFlowTest.php` (new — ~7 test cases)

### Out of scope
- `customer_choice` + manual-confirm rejection refund path (E2E-P5)

---

## Session E2E-P5 — Refunds + disputes end-to-end

**Owner**: single agent. **Prerequisites**: E2E-P2, E2E-P3. **Unblocks**: nothing directly.

Cover every refund trigger (customer cancel in-window / out-of-window, admin manual full / partial, business cancel, manual-confirmation rejection) plus the dispute surface. Refund webhook settlement is tested end-to-end (Pest dispatches `charge.refunded`).

### Testable endpoints
- `POST /my-bookings/{booking}/cancel` — `Customer\BookingController@cancel`
- `POST /bookings/{token}/cancel` — `Booking\BookingManagementController@cancel` (guest customer path)
- `POST /dashboard/bookings/{booking}/refunds` — `Dashboard\BookingRefundController@store`
- `PATCH /dashboard/bookings/{booking}/status` — `Dashboard\BookingController@updateStatus` (business cancel + manual rejection)
- `PATCH /dashboard/payment-pending-actions/{action}/resolve` — `Dashboard\PaymentPendingActionController@resolve`
- `POST /webhooks/stripe-connect` — events `charge.refunded`, `charge.refund.updated`, `refund.updated`, `charge.dispute.created`, `charge.dispute.updated`, `charge.dispute.closed`

### Test checklist
- [ ] **Customer in-window cancel** (authenticated customer on `/my-bookings`): dispatches automatic full refund with reason `customer-requested`; success page shows "Refund initiated — 5–10 business days"; webhook `charge.refunded` promotes `payment_status = refunded`
- [ ] **Customer in-window cancel** (guest via token on `/bookings/{token}`): same outcome, same notification
- [ ] **Customer out-of-window cancel**: booking cancelled; NO refund dispatch; customer page says "Refund not automatic — contact the business"
- [ ] **Admin manual full refund**: booking detail → Refund button → Full radio → submit → FakeStripeClient asserts the Stripe refund call with idempotency key `riservo_refund_{uuid}`; webhook settles → `payment_status = refunded`
- [ ] **Admin manual partial refund**: dialog enforces the `remainingRefundableCents()` clamp client-side (amount input max); server rejects overflow with 422; successful partial → `payment_status = partially_refunded`; second partial that fully exhausts → `payment_status = refunded`
- [ ] **Admin manual refund — staff 403**: staff user sees the Refund button disabled with "Admin-only" tooltip; direct POST returns 403
- [ ] **Business cancel** (admin from dashboard booking detail): paid booking → admin changes status to Cancelled → automatic refund dispatched with reason `business-cancelled`; customer email carries "A full refund has been issued"
- [ ] **Manual-confirmation rejection on `pending + paid`**: admin rejects → automatic refund dispatched with reason `business-rejected-pending`; customer email renders refund clause
- [ ] **Manual-confirmation rejection on `pending + unpaid`**: admin rejects → NO refund dispatched; customer email OMITS refund clause (D-175)
- [ ] **Disconnected-account fallback**: business disconnects Stripe mid-session; admin tries to refund → Stripe returns permission error → `payment_status = refund_failed`, Pending Action banner appears on booking detail, admin email dispatched; "Mark as resolved offline" button on PA resolves the banner
- [ ] **Dispute `created`**: webhook → Pending Action banner appears on booking detail (admin-only); admin email dispatched; deep-link to Stripe Express dashboard works (visible as external link)
- [ ] **Dispute `updated`**: webhook → PA detail refreshes (no duplicate emails)
- [ ] **Dispute `closed` (won)**: webhook → PA resolves with "Dispute won" summary; admin email dispatched
- [ ] **Dispute `closed` (lost)**: webhook → PA resolves with "Dispute lost — funds returned to customer"; admin email dispatched
- [ ] **Refund idempotency**: admin double-clicks the Refund button → exactly one Stripe call, one `booking_refunds` row
- [ ] **Cross-tenant denial**: admin of Business A cannot resolve a Business B Pending Action (404)

### Files to create / modify
- `tests/Browser/Payments/RefundFlowsTest.php` (new — ~10 test cases)
- `tests/Browser/Payments/DisputeFlowTest.php` (new — ~5 test cases)

### Out of scope
- Dispute evidence upload (out of scope per locked decision #25)
- Late-webhook refund (covered in E2E-P3)

---

## Session E2E-P6 — Payouts page + connected-account health

**Owner**: single agent. **Prerequisites**: E2E-P1. **Unblocks**: nothing directly.

Cover the Session 4 payouts surface: health chips, balance cards, payouts list, Stripe Tax warning, non-CH banner, "Manage payouts in Stripe" login-link mint.

### Testable endpoints
- `GET /dashboard/payouts` — `Dashboard\PayoutsController@index`
- `POST /dashboard/payouts/login-link` — `@loginLink`

### Test checklist
- [ ] **Active CH account — full UI**: admin visits → sees health strip (3 green chips), available + pending balance cards, payout schedule card, recent payouts table, "Manage payouts in Stripe" button
- [ ] **Not-connected state**: no connected-account row → page shows onboarding CTA deep-linking to `/dashboard/settings/connected-account` (not the payouts page crashing)
- [ ] **Pending / incomplete state**: account row exists but caps not on → page shows "Continue Stripe onboarding" prompt
- [ ] **Disabled state**: `requirements_disabled_reason` set → page shows disabled panel with Stripe's verbatim reason + mailto support placeholder
- [ ] **Stripe Tax warning** (locked decision #11): account where `tax_settings.status !== 'active'` → persistent banner "Stripe Tax not yet configured..."
- [ ] **Non-CH account banner** (locked decision #43): active account with country = DE, supported_countries = ['CH'] → info banner "Online payments in MVP support CH-located businesses only"
- [ ] **Config-flip seam proof**: `config(['payments.supported_countries' => ['CH', 'DE']])` mid-test, DE active account → non-CH banner disappears (proves no hardcoded 'CH')
- [ ] **Stale cache fallback**: Stripe API fails → page shows cached payload with "Couldn't refresh — showing last known state" banner; never crashes
- [ ] **Unreachable (empty cache)**: Stripe fails AND cache is empty → page shows "Couldn't load" with a retry suggestion
- [ ] **Login-link mint**: admin clicks "Manage payouts in Stripe" → `useHttp` POST to `loginLink` → FakeStripeClient `mockLoginLinkCreate` returns stub URL (header asserted ABSENT, platform-level call) → frontend opens `window.open(url, '_blank', 'noopener')` (assert via Pest Browser window-open interception)
- [ ] **Login-link for disabled account**: 422 (can't mint for disabled)
- [ ] **Staff 403**: every route
- [ ] **Cross-tenant denial**: admin of A cannot view B's payouts

### Files to create / modify
- `tests/Browser/Payments/PayoutsPageTest.php` (new — ~12 test cases)

### Out of scope
- Payout schedule modification / manual payout initiation (locked decision #23)

---

## Session E2E-P7 — `payment_mode` toggle gate + race banner (D-176)

**Owner**: single agent. **Prerequisites**: E2E-P1, E2E-P2. **Unblocks**: nothing — closes the E2E-PAYMENTS roadmap.

Cover the Session 5 surface: Settings → Booking gate with priority-ordered tooltips, server-side 422 enforcement, public-side race banner (pre-submit + post-submit), degraded-account pre-load banner.

### Testable endpoints
- `GET /dashboard/settings/booking` — `Dashboard\Settings\BookingSettingsController@edit`
- `PUT /dashboard/settings/booking` — `@update`
- `GET /{slug}` (public booking page — pre-load race banner)
- `POST /booking/{slug}/book` (post-submit race banner)

### Test checklist
- [ ] **No connected account**: Settings → Booking shows all 3 options; non-offline disabled with sub-text "Connect Stripe and finish onboarding to enable online payments."
- [ ] **Pending account**: same as above (canAcceptOnlinePayments = false)
- [ ] **Active CH account**: all 3 options enabled; admin can save `online` and `customer_choice`
- [ ] **Active DE account + supported = ['CH']**: non-offline disabled with sub-text "Online payments in MVP support CH-located businesses only."
- [ ] **Config flip `['CH', 'DE']`**: DE account → non-offline enabled and saveable (proves seam open)
- [ ] **Manual × online hint** (locked decision #29): confirmation_mode = manual + payment_mode = online → hint "Customers will be charged at booking; if you reject a booking, they'll receive an automatic full refund." renders reactively as the admin toggles either select
- [ ] **Server-side gate — direct PUT bypass**: POST `payment_mode=online` for a business without a connected account → 422 with error keyed on `payment_mode`; no DB change
- [ ] **Server-side gate — non-CH**: active DE account → PUT online → 422 with specific "CH-located only" message (not the generic "Connect Stripe")
- [ ] **Idempotent passthrough**: business persisted at `online` + account now disabled → PUT `online` with other fields changed → 422 does NOT fire (passthrough kept)
- [ ] **Public race banner — pre-load** (D-176 Round 4): business payment_mode = online + account disabled → customer visits `/{slug}` → on the summary step the "unavailable" banner renders BEFORE any click; CTA is disabled
- [ ] **Public race banner — post-submit** (D-176 Round 1): business payment_mode = online + account becomes disabled BETWEEN page load and form submit → customer clicks Continue to payment → 422 response → banner renders on the same page with reason `online_payments_unavailable`
- [ ] **customer_choice + degraded + `payment_choice = 'offline'`**: public booking succeeds as offline (D-176 soft path; picker not rendered but explicit offline intent honoured)
- [ ] **customer_choice + degraded + `payment_choice` absent** (stale client / degraded-at-load): booking succeeds as offline (no null-default escalation)

### Files to create / modify
- `tests/Browser/Payments/PaymentModeToggleTest.php` (new — ~10 test cases)
- `tests/Browser/Payments/RaceBannerTest.php` (new — ~5 test cases)

### Out of scope
- The future opt-out checkbox (deferred — `docs/BACKLOG.md` entry "Accept offline bookings when Stripe is temporarily unavailable")

---

## Dependency graph

```
          E2E-P0 (setup, helpers)
             │
             ▼
          E2E-P1 (Connect onboarding)
             │
       ┌─────┼─────────┐
       │     │         │
       ▼     ▼         ▼
   E2E-P2  E2E-P6   E2E-P7
   (happy) (payouts) (toggle + race)
       │
   ┌───┴───┐
   ▼       ▼
 E2E-P3  E2E-P4
 (fail)  (choice)
   │       │
   └───┬───┘
       ▼
    E2E-P5
    (refunds + disputes)
```

E2E-P0 is strictly first. E2E-P1 is the second gate — Connect onboarding is needed by everything else. After that, E2E-P2 / E2E-P6 / E2E-P7 can run in parallel if multiple agents are available; E2E-P3 / E2E-P4 require P2; E2E-P5 requires P2 + P3.

---

## Cleanup tasks (after this roadmap is approved)

- [ ] `docs/TESTING-STRIPE-END-TO-END.md` (the manual sandbox guide) stays in place alongside this roadmap — automated E2E and manual sandbox are complementary coverage layers, not replacements.
- [ ] `docs/BACKLOG.md` entry "Accept offline bookings when Stripe is temporarily unavailable" is a feature roadmap, not an E2E roadmap — when it eventually ships, its implementing session adds its own E2E tests (or extends E2E-P7); this roadmap does not need revision.
- [ ] Consider a final session "E2E-P8 — Multi-business admin flows" after a future "Consolidated Connect UX for multi-business admins" feature ships (also in BACKLOG).

---

*This roadmap defines the WHAT. The HOW is decided per session by the implementing agent in `docs/PLAN.md` (one live file, overwritten each session). Each session leaves the full Pest Browser suite green alongside the existing `tests/Feature + tests/Unit` baseline.*
