# riservo.ch — Customer-to-Professional Payments Roadmap

> Version: 1.0 — Draft
> Status: Planning
> Scope: End-to-end online payment flow where the customer pays the professional at booking time. Stripe Connect Express, TWINT-first, zero riservo commission. Separate from MVPC-3 (riservo's own SaaS billing on the Business model).
> Format: WHAT only. The HOW is decided per-session by the implementing agent in a dedicated plan document.
> Each session is a focused, reviewable unit handed to a single agent. Sessions run sequentially in the order listed below — each session is a hard prerequisite for the next.

---

## Overview

The `payment_mode` enum on `Business` has existed as a data field since the early schema (values `offline`, `online`, `customer_choice`) but no flow backs the `online` or `customer_choice` values. SPEC §12 currently lists Stripe Connect and online payments as v2; this roadmap promotes them to a real near-term deliverable for the Swiss market, where TWINT availability is a competitive differentiator and customers already expect to pay at booking for certain verticals (beauty, wellness, coaching).

**Revenue model is unchanged**: riservo takes **zero commission** on customer-to-professional payments. Riservo's revenue remains purely the SaaS subscription (MVPC-3, Cashier on `Business`). Stripe processing fees are paid by the professional to Stripe directly via their connected account. This roadmap is a product feature, not a monetisation layer.

The new sequence is five sessions, each with a clear deliverable and a single owning agent:

| # | Session | Outcome |
|---|---------|---------|
| 1 | Stripe Connect Express Onboarding | Business connects a Stripe Express account via Stripe-hosted KYC; verification status persisted; `payment_mode` UI still locked to `offline` only |
| 2 | Payment at Booking (Checkout + TWINT) | Public booking flow charges the customer via hosted Stripe Checkout (card + TWINT); slot reserved via the existing GIST invariant while payment resolves |
| 3 | Refunds (Customer Cancel, Admin Manual, Business Cancel) | Refund flow wired both ways; automatic refunds on in-window customer cancels and business-side cancels; admin-initiated manual refund on the booking detail page |
| 4 | Payout Surface + Connected Account Health | Dashboard section showing connected-account status, next payout ETA, recent payouts, deep-link to Stripe Express dashboard |
| 5 | `payment_mode` Toggle Activation + UI Polish | Lift the hide-the-options ban in Settings → Booking; `online` and `customer_choice` become user-facing; settle the copy, empty states, and error banners |

Alternatives considered briefly before Session 1 is planned:

- **Mollie Connect** — Dutch PSP with native TWINT and a simpler marketplace abstraction. Rejected because we would introduce a second billing SDK alongside Cashier/Stripe (MVPC-3), splitting webhook plumbing, dashboard UX, and operational knowledge across two providers.
- **Datatrans** — Swiss-native PSP with first-class TWINT. Rejected because Datatrans does not provide a marketplace / connected-account abstraction — we would build account onboarding, KYC state machines, and payout orchestration from scratch, which is explicitly not where riservo's engineering effort belongs.
- **Stripe Connect Express** — picked. TWINT is supported natively on CH connected accounts; Express onboarding is Stripe-hosted (no PCI burden and no KYC UX to build); Stripe Tax integrates cleanly with Connect; Cashier already establishes Stripe as the SaaS stack, so we reuse SDK, webhook signature validation, idempotency patterns, and operational know-how. Standard is rejected as it hides the riservo brand poorly during onboarding; Custom is rejected because it moves the PCI + KYC burden onto us.

Sessions 1–4 keep the `online` and `customer_choice` options hidden from the Settings → Booking UI. Session 5 lifts the hide, which is why it ships last.

The SaaS-side billing work (MVPC-3, active in `docs/plans/PLAN-MVPC-3-CASHIER-BILLING.md`) is independent of this roadmap. Terminology is chosen to keep the two clearly separate: MVPC-3 uses "subscription", "billing", "trial"; this roadmap uses "payment", "charge", "refund", "payout", "connected account".

---

## Cross-cutting decisions locked in this roadmap

These are the decisions the implementing agents must not re-evaluate. Each must be recorded with a fresh decision ID (next available after D-095 is **D-096**) in the appropriate topical file under `docs/decisions/` — likely a new `DECISIONS-PAYMENTS.md` created in Session 1, since the concern is distinct from the SaaS billing file.

### Commercial model and PSP selection

1. **Zero commission**. Riservo takes no cut of any customer-to-professional payment. The full service price, minus Stripe processing fees, lands on the connected account. Cashier subscription revenue (MVPC-3) is riservo's only income stream. Locked.
2. **Stripe Connect Express**. Not Standard, not Custom. Rationale: Stripe-hosted KYC, TWINT on CH accounts out of the box, marketplace abstraction without PCI burden, same SDK as MVPC-3. Locked.
3. **TWINT is mandatory for CH-located businesses**. TWINT is enabled by default (not opt-in) on every Stripe Checkout session for a business whose connected account country is CH. Rationale: over 5M Swiss users, the dominant wallet in the target market; opt-in puts the product behind competitors by default. Locked.
4. **Hosted Stripe Checkout, not embedded Elements**. Rationale: less PCI surface, TWINT + card out of the box, 3DS / SCA handled automatically by the hosted page, mobile behaviour is Stripe's problem. Locked.
5. **Direct charges with `Stripe-Account` header, not destination charges**. Combined with locked decision #1 (zero commission) and #22 (one connected account per Business), the professional is the merchant of record. Direct charges put the Stripe Customer, the Charge, the Invoice, and the receipt email on the connected account, branded as the professional (not "riservo"). Destination charges with `application_fee_amount = 0` would technically work but are non-idiomatic and put riservo as the platform-of-record on every charge for no benefit. Locked.

### When and what to charge

6. **Charge at booking confirmation (auto-capture)**. Customer pays immediately on "Confirm booking" — not auth-and-capture at appointment time, not customer-choice at the checkout step. Rationale: simplest mental model for the customer, no card expiry between auth and capture, matches the offline cash-at-door flow timing-wise. Auth-and-capture is a v2 consideration once professionals request it. Locked.
7. **Full charge upfront, not a deposit**. The full `Service.price` is charged at booking. Deposit-plus-balance is deferred — it introduces a second charge flow and an offline reconciliation surface we do not want in this roadmap. Locked.
8. **`Service.price = null` ("on request") cannot be booked online**. Services without a set price force the `offline` flow irrespective of the Business's `payment_mode`. The public booking UI gates the online path on `price !== null && price > 0`. A service with `price = 0` is also treated as offline — there is nothing to charge. Locked.
9. **Invoice generation is Stripe-side**. The connected account's Stripe dashboard surfaces PDF invoices automatically; riservo does not generate a parallel invoice document. The Business can link to Stripe from the riservo dashboard (Session 4). A riservo-native invoicing engine is a v2 product consideration, not an MVP requirement for this roadmap. Locked.
10. **VAT / tax handled on the connected account via Stripe Tax**. The professional configures Stripe Tax settings on their own Stripe account; riservo does not compute, store, or display tax on bookings. Analogous reasoning to D-094 for the SaaS side: tax is the taxable entity's problem, not the platform's. Locked.
11. **Stripe Tax not configured = warning banner, not hard block**. Many small Swiss businesses have never enabled Stripe Tax. Riservo's onboarding (Session 1) does NOT make Stripe Tax setup mandatory — that would block legitimate professionals from enabling online payments. Instead, the Connected Account dashboard page shows a persistent "Stripe Tax not yet configured — your customers' receipts will not show VAT. [Configure in Stripe →]" warning when the connected account's tax settings status is not `active`, deep-linking to the Stripe Tax setup page. Soft enforcement; the professional decides. The same warning surfaces in Session 4's payout page health strip. Locked.

### Reservation, failure, and race semantics

12. **Slot-hold pattern = reserve-then-pay**. On "Confirm booking" with online payment the Booking row is created immediately as `pending` with `payment_status = awaiting_payment`; the slot is locked by the existing Postgres GIST invariant (D-065, D-066). The customer is redirected to hosted Checkout. On `checkout.session.completed` the webhook promotes the booking to `confirmed` + `payment_status = paid`. The GIST constraint protects against double-booking during the Checkout window at no extra cost, which is the key reason we pick this pattern over "hold the slot only after payment succeeds". Locked.
13. **Checkout expiry = 60 minutes; reaper applies only to `payment_mode_at_creation = online`**. Stripe Checkout session is created with `expires_at = now + 60 minutes` (Stripe default is 24h; we override). 60 minutes accommodates TWINT's slower flow (app switch + authentication + occasional SMS) and slow card customers, while still freeing genuinely abandoned slots in a reasonable window. The reaper job (`bookings:expire-unpaid`) cancels stale `pending + awaiting_payment` bookings ONLY when the underlying booking's `payment_mode_at_creation` is `online` — for `customer_choice` the booking is promoted to `confirmed + unpaid` instead (decision #14), so it is never a candidate for the reaper. The booking row's `expires_at` column is set only for `online` mode; for `customer_choice` it is left null. Locked.
14. **Failed/abandoned Checkout behaviour depends on `payment_mode_at_creation`**. The Business's `payment_mode` is captured on the booking row at creation time (new column `payment_mode_at_creation`, mirrors `Business.payment_mode` at booking time) so subsequent setting changes do not re-interpret in-flight bookings.
    - **`payment_mode_at_creation = online`**: failed Checkout (`payment_intent.payment_failed`), expired Checkout (`checkout.session.expired`), or customer abandonment via the cancel URL → booking cancelled, slot freed, payment_status terminal. Customer return page reads "Slot released — try again to rebook". This matches the Business's commercial intent ("require online payment").
    - **`payment_mode_at_creation = customer_choice` and the customer picked online at Checkout**: failed/expired/abandoned → booking promoted to `confirmed` with `payment_status = unpaid` (new value, see decision #28); slot stays held; the standard booking-confirmed notifications fire normally. Customer return page reads "Payment not completed — your booking is confirmed; pay at the appointment". The Business sees the booking with an "Unpaid" badge in the dashboard. Rationale: the customer affirmatively confirmed the slot before the redirect; the Business's `customer_choice` setting explicitly permits paying offline; the natural fallback for a failed-prepay attempt is "you can still pay on site". Locked.

### Refund policies

15. **Refund on customer cancel INSIDE the cancellation window = full automatic refund**. No business-level toggle in this roadmap; the cancellation window is already the business's stated policy (D-016), so honouring it fully is the only consistent stance. A per-business "no-refund" override is a v2 request if it materialises. Locked.
16. **Refund on customer cancel OUTSIDE the cancellation window = no automatic refund; business can issue manually**. The charge stands; the customer sees "Outside cancellation window — contact [Business] for a refund". The Business can refund manually from the booking detail page. Partial refunds are supported as a free-form amount (manually entered, not a preset percentage). Locked.
17. **Refund on business-side cancel = full automatic refund, always**. Rationale: trust. A customer whose appointment is cancelled by the provider must not also have to chase their money. Locked.
18. **Insufficient connected-account balance for refund**. Stripe handles this via its platform-wide debit-on-file default: if the connected account balance is insufficient, Stripe debits the account's attached bank. Riservo does not block the refund and does not surface the debit mechanics in the UI — the professional owns that relationship with Stripe. Surface the final refund outcome (succeeded / failed) via the webhook-driven booking state. Locked.
19. **Refund initiator permissions**. Admin can issue refunds from any booking. Staff (with a linked Provider) cannot issue refunds even for their own bookings — refund authority is an admin-only commercial decision. Locked.

### Connected-account lifecycle

20. **KYC failure after account creation → `payment_mode` remains / reverts to `offline`**. If the connected account is created but Stripe verification fails (document rejected, owner information missing, etc.), the Business's `payment_mode` is forced back to `offline` by the `account.updated` webhook handler. A persistent banner in the dashboard reads "Complete Stripe onboarding to enable online payments" with a deep-link back to Express onboarding. The verification status is stored on the connected-account row. Locked.
21. **Business disconnects Stripe → `payment_mode` reverts to `offline`; existing paid bookings stay valid**. Paid `pending` / `confirmed` bookings remain in place (money is already with the professional); no new `online` bookings can be created; `payment_mode` is forced to `offline` automatically. Re-connecting restores the option to switch back. Locked.
22. **Multi-business user = one connected account per Business**. A user who administers two businesses must onboard each one independently; the connected account is a Business-level resource, not a User-level resource. Schema: `stripe_connected_accounts` table keyed by `business_id` with a unique constraint, not by `user_id`. Locked.

### Payouts, disputes, and operator surfaces

23. **Payouts use Stripe's automatic schedule default (daily rolling)**. No riservo-level payout controls in MVP. The Business can change their payout schedule directly in the Stripe Express dashboard if they want weekly or manual. Locked.
24. **Riservo surfaces payout status, not payout management**. Session 4's dashboard section shows: connected-account verification status, next scheduled payout ETA + amount, last three payouts (date + amount + status), and a deep-link "Manage payouts in Stripe". No payout initiation, no payout pause, no payout schedule change from riservo. Locked.
25. **Disputes / chargebacks are Stripe's UI, not ours**. The professional handles disputes via the Stripe dashboard. Riservo does not surface dispute state in the booking detail page for this roadmap. A booking whose charge is disputed keeps its existing status; the `payment_status` is informative, not load-bearing for access control. A future session can surface disputes in riservo once the volume justifies the UX work. Locked.

### Existing schema and UI

26. **`payment_mode` enum values stay as-is**: `offline`, `online`, `customer_choice`. No enum change in this roadmap. Locked.
27. **Until this roadmap ships, the `online` and `customer_choice` options are hidden from Settings → Booking**. Session 1 ships the hide as its first checklist item. Session 5 lifts it. Between those two, the data column accepts all three values but the UI only exposes `offline`. Locked.
28. **`bookings.payment_status` column is already present** (cast to `App\Enums\PaymentStatus`). This roadmap extends the enum values; the final set is decided at Session 2 plan time but MUST include: `not_applicable` (offline-from-the-start booking, no payment ever expected through riservo), `awaiting_payment` (pending Checkout outcome), `paid`, `unpaid` (customer attempted online in `customer_choice` mode but did not complete; payment due at appointment — distinct from `not_applicable` so the Business can see the audit trail and analytics can track conversion), `refunded`, `partially_refunded`, `refund_failed`. A new column `bookings.payment_mode_at_creation` (string, nullable, mirrors `Business.payment_mode` at booking time) is also introduced in Session 2 to drive the failure-branching logic of decision #14 stably across subsequent Business setting changes. Locked.

---

## Session 1 — Stripe Connect Express Onboarding

**Owner**: single agent. **Prerequisites**: MVPC-3 closed (Cashier is installed, so the Stripe SDK, webhook plumbing pattern, and env conventions exist). **Unblocks**: Session 2.

Stand up the connected-account onboarding flow in isolation. Deliverable is a verified Stripe Express round-trip: an admin clicks "Enable online payments", lands on Stripe-hosted KYC, completes it, and returns to riservo with a connected account persisted and a verification state reflected in the dashboard. No charging, no refunds, no payouts — this session is pure onboarding + webhook reception.

### Hide `online` / `customer_choice` from Booking Settings (do first)
- [ ] Settings → Booking: the `payment_mode` selector renders only the `offline` option. `online` and `customer_choice` are absent from the select; any existing `online` / `customer_choice` value on a Business row is preserved in the DB but cannot be chosen in the UI (read-only display: "Online payments — coming soon").
- [ ] Session 5 lifts this restriction; every session up to and including Session 4 keeps the hide in place.

### Data layer
- [ ] New table `stripe_connected_accounts` keyed by `business_id` (unique, per locked decision #20). Columns: `id`, `business_id`, `stripe_account_id`, `country` (ISO-3166-1 alpha-2), `charges_enabled`, `payouts_enabled`, `details_submitted`, `requirements_currently_due` (JSON), `requirements_disabled_reason` (nullable string), `default_currency`, `created_at`, `updated_at`.
- [ ] `Business` model gains `hasOne(StripeConnectedAccount::class)` and a `canAcceptOnlinePayments(): bool` helper that returns `charges_enabled && payouts_enabled && details_submitted`.
- [ ] `StripeConnectedAccount` model with casts and a `verificationStatus(): string` returning one of `pending`, `incomplete`, `active`, `disabled` (plain strings, not an enum — Stripe's own verification states are the source of truth and are richer than a local enum can meaningfully carry).

### Controllers and routes
- [ ] `Dashboard\Settings\ConnectedAccountController` with actions `show`, `create`, `refresh`, `disconnect`. Scoped to admins only (not staff) — this is a commercial decision.
- [ ] `create()` calls the Stripe API to create an Express account in the Business's country (derived from `Business.address` or defaulted to `CH`), persists the `stripe_connected_accounts` row, and returns an Inertia redirect to the Stripe-generated Account Link onboarding URL.
- [ ] `refresh()` is the return URL after onboarding — pulls the latest account state via Stripe API and persists it; redirects to the Settings page with a success or "continue onboarding" banner.
- [ ] `disconnect()` follows locked decision #19: deletes the `stripe_connected_accounts` row (or soft-deletes it — implementation agent decides; the user-visible effect is the same), forces `payment_mode = offline` on the Business, writes an audit row if audit logging is in place.

### Webhook reception
- [ ] `POST /webhooks/stripe-connect` endpoint, CSRF-excluded, signature-verified against a dedicated Connect webhook secret (distinct from the MVPC-3 subscription webhook endpoint).
- [ ] Event `account.updated`: re-reads `charges_enabled`, `payouts_enabled`, `details_submitted`, `requirements`, and updates the row. If `charges_enabled` transitions from true to false, force `payment_mode` back to `offline` (locked decision #18).
- [ ] Event `account.application.deauthorized`: treat identically to `disconnect()`.
- [ ] Idempotency: process each event at-most-once using Stripe's event ID as the idempotency key (shared-utility with MVPC-3's webhook handler if it already exists).

### Dashboard UI
- [ ] `Dashboard/settings/connected-account.tsx` (new page under Settings, admin-only nav item, shown always once this session lands).
- [ ] Not-connected state: "Enable online payments" CTA; short explainer that onboarding is Stripe-hosted; required-info preview.
- [ ] Onboarding-incomplete state: "Continue Stripe onboarding" CTA back to a fresh Account Link; list of `requirements_currently_due` if Stripe exposes them.
- [ ] Active state: read-only summary (country, default currency, charges enabled, payouts enabled, Stripe account ID last 4 for support); "Disconnect" button with confirmation dialog.
- [ ] Disabled / disabled-by-Stripe state: error banner with `requirements_disabled_reason` verbatim from Stripe; "Contact support" CTA.
- [ ] Dashboard-wide banner on every page when `canAcceptOnlinePayments()` is false AND `payment_mode !== 'offline'` (can only happen transiently between a webhook demotion and a UI refresh; still worth surfacing).

### Tests + ops
- [ ] Feature tests: admin can create connected account (Stripe SDK faked); staff cannot reach the page (403); return-URL refresh persists state; `account.updated` webhook updates the row and demotes `payment_mode`; signature validation rejects unsigned requests; disconnect forces `payment_mode = offline`; multi-business admin creates one account per business.
- [ ] `php artisan wayfinder:generate` after route additions.
- [ ] Update `docs/DEPLOYMENT.md` with: Connect webhook endpoint URL and secret, test-vs-live Connect keys, env var inventory.
- [ ] Pint clean, full Pest suite green, `npm run build` clean.

**Out of scope**: taking actual payments, refunds, payout surfacing, lifting the Settings → Booking `payment_mode` hide, embedded onboarding. All of that lives in later sessions.

---

## Session 2 — Payment at Booking (Checkout + TWINT)

**Owner**: single agent. **Prerequisites**: Session 1. **Unblocks**: Session 3 (refunds require a paid booking to refund).

Wire the actual customer charge: on the public booking flow, when the Business has `payment_mode = online` (or `customer_choice` with customer having picked online), the "Confirm booking" CTA creates a `pending` booking + a Stripe Checkout session, redirects the customer to the hosted page, and the webhook promotes the booking to `confirmed` + `paid` on success.

**Note**: `payment_mode = online` / `customer_choice` is still hidden from Settings; for testing and dogfooding this session, the Business's `payment_mode` is seeded directly in the DB.

### Data layer
- [ ] Extend `App\Enums\PaymentStatus` to include `not_applicable`, `awaiting_payment`, `paid`, `unpaid`, `refunded`, `partially_refunded`, `refund_failed` (per locked decision #28; final set decided at plan time, but `unpaid` is mandatory to support the `customer_choice` failure branch in decision #14).
- [ ] Migration: `bookings` gains `stripe_checkout_session_id` (nullable unique string), `stripe_payment_intent_id` (nullable unique string), `stripe_charge_id` (nullable unique string), `paid_amount_cents` (nullable integer), `currency` (nullable ISO-4217 3-char), `paid_at` (nullable timestamp), `payment_mode_at_creation` (nullable string mirroring `Business.payment_mode` enum values, captured at booking time per locked decision #14), `expires_at` (nullable timestamp; populated only when `payment_mode_at_creation = online`, per locked decision #13).
- [ ] `Booking` model casts and fillable updated; a `isOnlinePayment()` helper and a `paidAmount(): ?Money` (or plain decimal) accessor; a `wasCustomerChoice(): bool` helper that reads `payment_mode_at_creation === 'customer_choice'`.

### Public booking flow
- [ ] Public booking page: when the Business's `payment_mode` is `online`, the "Confirm booking" CTA text becomes "Continue to payment"; when `customer_choice`, the final step exposes an inline "Pay now / Pay on site" choice (UI pattern = agent's call).
- [ ] `PublicBookingController::store` (existing) is extended: when the resolved booking would use online payment, the booking is created with `status = pending`, `payment_status = awaiting_payment`, `payment_mode_at_creation` set to the Business's `payment_mode` at this exact moment (snapshot — never re-read), and `expires_at` set to `now + 60 minutes` ONLY IF `payment_mode_at_creation = online` (left null for `customer_choice`, per locked decision #13). A Stripe Checkout session is created on the connected account using the `Stripe-Account` header; the customer is redirected to the Checkout URL via Inertia's external redirect helper.
- [ ] Checkout session configuration: `payment_method_types = [card, twint]` when the connected account country is CH; `card` only otherwise (per locked decision #3); `mode = payment`; line item derived from `Service.name` + `paid_amount_cents`; `success_url` and `cancel_url` route back to riservo; `client_reference_id = booking.id`; `metadata.riservo_booking_id` + `metadata.riservo_business_id` + `metadata.riservo_payment_mode_at_creation` (so webhook handlers can branch even before a DB lookup); `expires_at = now + 60min` (mirrors the booking row); direct charges via the `Stripe-Account` header per locked decision #5; `payment_intent_data.transfer_data` is NOT used; no `application_fee_amount`.
- [ ] A service with `Service.price === null` or `0` forces the offline path regardless of Business `payment_mode` (per locked decision #8); the booking is created as today (no Checkout session, `payment_status = not_applicable`, `payment_mode_at_creation = 'offline'`).

### Webhook handling (Connect endpoint extended from Session 1)
- [ ] `checkout.session.completed`: look up booking by `client_reference_id`; transition to `status = confirmed` + `payment_status = paid`; persist `stripe_payment_intent_id`, `stripe_charge_id`, `paid_amount_cents`, `currency`, `paid_at`; clear `expires_at`; dispatch the existing booking-confirmed notifications (suppressed for `source = google_calendar` per D-088, not relevant here but noted).
- [ ] `checkout.session.expired`, `checkout.session.async_payment_failed`, and `payment_intent.payment_failed`: branch on the booking's `payment_mode_at_creation` per locked decision #14:
  - `online` → cancel the booking (status → cancelled, payment_status → terminal `not_applicable` since no payment occurred — agent confirms the exact terminal value at plan time), free the slot, no booking-confirmed notifications.
  - `customer_choice` → promote the booking to `confirmed` + `payment_status = unpaid`, clear `expires_at`, dispatch the standard booking-confirmed notifications (the customer made a real booking, just unpaid). Slot stays held.
- [ ] All handlers are idempotent via the Stripe event ID.

### Expiry reaper
- [ ] Scheduled job `bookings:expire-unpaid` running every 2 minutes with `withoutOverlapping()`. Cancels bookings filtered on `status = pending` + `payment_status = awaiting_payment` + `payment_mode_at_creation = 'online'` + `expires_at < now`. The `payment_mode_at_creation` filter is mandatory per locked decision #13 — `customer_choice` bookings never expire via this path; if their Checkout times out, the webhook (or this job, but only as a no-op for them) leaves them alone, and the late `checkout.session.expired` event will eventually promote them to `unpaid`. Webhook is the fast path; this job is the backstop for webhook outages.
- [ ] Cancellation here does NOT dispatch customer notifications — the customer knows they didn't complete payment.

### Return / failure UX
- [ ] `success_url` lands the customer on the existing booking confirmation page; the page reads the fresh `status` + `payment_status` and displays the payment receipt inline.
- [ ] `cancel_url` lands on the public booking page with copy that branches on the booking's `payment_mode_at_creation` (looked up via `client_reference_id` + a lightweight read endpoint, OR encoded into the `cancel_url` itself as a query param at session-create time):
  - `online` → "Payment not completed — your slot is released" banner with the service pre-selected so retry is one click.
  - `customer_choice` → "Payment not completed — your booking is confirmed; pay at the appointment" banner with a link to the booking management page. The booking is also promoted to `confirmed + unpaid` server-side as described in the webhook section above (the cancel URL hit is informational only; the actual state transition is webhook-driven for idempotency).
- [ ] 3DS / SCA is entirely Stripe-hosted (per locked decision #4); riservo does not handle authentication UI.

### Admin dashboard touch-ups
- [ ] Booking detail page (dashboard): for online-payment bookings, show paid amount, payment method (Card / TWINT), Stripe charge ID as a deep-link to the Stripe dashboard (Connect account view). For `unpaid` bookings, show "Customer attempted online payment but did not complete — payment due at appointment" with the audit trail (attempted at, payment intent id if available).
- [ ] Bookings list: new column / filter "Payment" with chips for `paid`, `awaiting_payment`, `unpaid`, `refunded`, `offline` (the last maps to `not_applicable`).

### Tests + ops
- [ ] Feature tests: happy path (online payment creates Checkout session, webhook promotes booking, notifications fire); slot is locked during Checkout window (GIST constraint rejects overlapping booking attempts with the expected 409); `checkout.session.expired` for `payment_mode_at_creation = online` frees the slot; `checkout.session.expired` for `payment_mode_at_creation = customer_choice` promotes the booking to `confirmed + unpaid` and dispatches booking-confirmed notifications; `payment_intent.payment_failed` mirrors the same online-vs-customer_choice branching; `Service.price = null` forces offline path; non-CH connected account omits TWINT from `payment_method_types`; reaper cancels stale `online` bookings AND leaves `customer_choice` bookings untouched; `customer_choice` with customer picking offline creates a booking as today (no Checkout).
- [ ] Idempotency test: replaying a `checkout.session.completed` event does not double-promote.
- [ ] Snapshot test: the `payment_mode_at_creation` column on a booking row does not change when the Business's `payment_mode` changes after booking creation.
- [ ] Pint clean, full Pest suite green, `npm run build` clean.
- [ ] Update `docs/DEPLOYMENT.md` with: Checkout session env (public keys for the return page signing? — agent decides), webhook endpoint events to subscribe, test TWINT flow notes.

**Out of scope**: refunds (Session 3), payout surfacing (Session 4), lifting the Settings → Booking hide (Session 5). Also out of scope and deferred to BACKLOG: a "Resend payment link" UX for `unpaid` bookings — for MVP the customer simply pays at the appointment; if/when professionals request a way to nudge customers to retry online payment after a `customer_choice` failure, that becomes a focused mini-session (new endpoint that creates a fresh Checkout session for an existing `unpaid` booking).

---

## Session 3 — Refunds (Customer Cancel, Admin Manual, Business Cancel)

**Owner**: single agent. **Prerequisites**: Session 2. **Unblocks**: Session 5 indirectly (the full payment lifecycle must work before we expose the mode toggle).

Wire every refund trigger. Three distinct paths converge on one refund executor.

### Refund executor
- [ ] Service class `App\Services\Payments\RefundService` with a single public method `refund(Booking $booking, ?int $amountCents = null, string $reason = ''): RefundResult`. Defaults to full refund when `amountCents` is null. Calls Stripe Refund API on the connected account. Writes a `booking_refunds` audit row (see below). Handles idempotency via a client-generated idempotency key keyed to `(booking_id, amount_cents, initiator_hash)`.
- [ ] New table `booking_refunds`: `id`, `booking_id`, `stripe_refund_id` (unique), `amount_cents`, `currency`, `status` (`pending`, `succeeded`, `failed`), `reason` (customer-requested | business-cancelled | admin-manual), `initiated_by_user_id` (nullable — null for automatic), `failure_reason` (nullable), `created_at`, `updated_at`. One booking may have multiple refund rows (partial refunds accumulate).

### Customer-side cancel (in-window → automatic full refund)
- [ ] `CustomerBookingController::cancel` (or equivalent public cancel endpoint): after the existing cancellation-window check (D-016) passes, if the booking has `payment_status = paid`, dispatch `RefundService::refund($booking)` with reason `customer-requested`.
- [ ] The customer-facing success page reflects "Refund initiated — you'll receive it in your original payment method within 5–10 business days" (copy is Stripe's stock timing).
- [ ] Cancellation-window-outside still cancels the booking (consistent with existing behaviour, D-016) but issues no refund; the customer sees "Refund not automatic — contact the business" (locked decision #14).

### Admin dashboard — manual refund
- [ ] Booking detail page gains a "Refund" button visible only to admins (per locked decision #17) when `payment_status IN (paid, partially_refunded)` AND the Business has a connected account.
- [ ] Clicking opens a dialog: preset "Full refund" radio; alternative "Partial refund" with an amount input (bounded by `paid_amount_cents - sum(previous refunds)`); free-form reason textarea.
- [ ] Submits to `Dashboard\BookingRefundController::store` which calls `RefundService::refund` with `initiated_by_user_id = auth()->id()` and reason `admin-manual`.
- [ ] Staff users see the Refund button as disabled with a "Admin-only" tooltip.

### Business-side cancel (always automatic full refund)
- [ ] Existing admin/staff cancel path (`BookingController::cancel` or wherever live): after the cancel succeeds, if `payment_status = paid`, dispatch `RefundService::refund($booking)` with reason `business-cancelled` (full amount, non-configurable per locked decision #15).
- [ ] Customer-facing cancel-notification email is extended to say "A full refund has been issued" when this path fires.

### Webhook handling
- [ ] Connect webhook endpoint handles `charge.refunded` and `charge.refund.updated`: updates the matching `booking_refunds` row's status + `failure_reason`; updates the parent booking's `payment_status` to `refunded` (if total refunded = paid amount) or `partially_refunded` or `refund_failed` accordingly.
- [ ] Refund-failed case: a dashboard banner on the booking detail page surfaces the failure + Stripe's failure reason verbatim. Insufficient connected-account balance (locked decision #16) is one of the Stripe-handled causes — we do NOT block the refund attempt upfront.

### UI
- [ ] Booking detail: a "Payment & refunds" panel lists the original charge + every `booking_refunds` row (date, amount, status, initiator, reason).
- [ ] Public management page (customer): when a refund is in flight or complete, show a compact status line under the cancel button.

### Tests + ops
- [ ] Feature tests: in-window customer cancel issues automatic refund; out-of-window customer cancel issues no refund; admin manual full refund succeeds; admin partial refund bounded by remaining refundable amount; staff cannot reach the refund endpoint (403); business cancel issues automatic refund; `charge.refunded` webhook promotes status; `charge.refund.updated` with failure surfaces `refund_failed`; refunding an already-fully-refunded booking is rejected with a friendly error; idempotency replay is safe.
- [ ] Email copy: customer-facing refund-issued email copy exists and is covered by a rendering test.
- [ ] Pint clean, full Pest suite green, `npm run build` clean.

**Out of scope**: partial-refund presets, refund-on-no-show automatic policy (we default to no automatic refund on no-show — admin manual only), dispute handling.

---

## Session 4 — Payout Surface + Connected Account Health

**Owner**: single agent. **Prerequisites**: Session 3. **Unblocks**: Session 5.

Give the Business visibility into their money after it leaves the customer. Read-only, Stripe-sourced, no riservo-side payout orchestration.

### Data layer
- [ ] No new tables. Payout data is fetched live from Stripe on-demand and cached briefly in Laravel's cache (TTL decided at plan time, likely 60s). Persisting payout history locally is out of scope — Stripe is the source of truth.

### Controller
- [ ] `Dashboard\PayoutsController@index`: fetches the connected account's `balance`, `payouts.list` (last 10), and `payout_schedule` via Stripe API; returns an Inertia page. Admin-only.
- [ ] Graceful degradation when the connected account is not verified: the page shows the same onboarding CTA as Session 1's banner rather than crashing.

### UI
- [ ] `Dashboard/payouts.tsx`: four cards — Available balance (amount in default currency), Pending balance, Next payout ETA + amount, Recent payouts table (date, amount, status, arrival date).
- [ ] Prominent "Manage payouts in Stripe" button: generates an Express dashboard login link via Stripe API (`accounts.createLoginLink`) and opens it in a new tab. This is the single riservo-side action on payouts (per locked decision #22).
- [ ] Connected-account health strip at the top: charges-enabled / payouts-enabled / requirements-due, each a colour-coded chip with a tooltip for the underlying Stripe reason string.

### Error states
- [ ] Stripe API timeout / error: render cached data with a "Couldn't refresh — showing last known state" banner; never crash the page.
- [ ] Connected account disabled by Stripe: the whole page degrades to the `disabled_reason` banner + "Contact Stripe support" CTA.

### Navigation
- [ ] New nav item "Payouts" under the dashboard, admin-only, visible only when a connected account row exists. (Session 5 adjusts conditional visibility once the feature is broadly GA.)

### Tests + ops
- [ ] Feature tests: admin sees the page with faked Stripe responses; staff cannot reach the page (403); Stripe API error renders the degradation banner; Express dashboard link generation calls the right Stripe API; unverified account shows the onboarding CTA.
- [ ] Pint clean, full Pest suite green, `npm run build` clean.

**Out of scope**: payout schedule change, manual payout initiation, dispute dashboard, riservo-side payout history persistence.

---

## Session 5 — `payment_mode` Toggle Activation + UI Polish

**Owner**: single agent. **Prerequisites**: Sessions 1–4. **Unblocks**: nothing — this session closes the roadmap.

Lift the hide-the-options ban. Expose `online` and `customer_choice` in Settings → Booking. Walk through every touched surface and polish copy, empty states, error states, and edge cases discovered in earlier sessions.

### Settings → Booking toggle
- [ ] Settings → Booking re-exposes the full `payment_mode` select with three options: `offline`, `online`, `customer_choice`.
- [ ] Selecting `online` or `customer_choice` is gated on `Business.canAcceptOnlinePayments() === true`. When false, the options render disabled with a "Connect Stripe to enable" tooltip linking to Settings → Connected Account.
- [ ] Saving the setting while no verified connected account exists is rejected server-side with a 422 — the client-side disable is a convenience, not the enforcement (consistent with the D-068 gating pattern).
- [ ] Copy for each option: `offline` = "Customers pay on-site"; `online` = "Customers pay when booking"; `customer_choice` = "Customers choose at checkout".

### UI polish across the feature
- [ ] Public booking flow: finalise copy around the payment step ("Secured by Stripe" microcopy, TWINT logo shown when available, clear "Your card will be charged CHF {amount}" confirmation line).
- [ ] Error banner on the public booking page when the Business's connected account becomes disabled mid-session (race): "This business is no longer accepting online payments right now — try again later or contact them directly."
- [ ] Empty-state copy on the Payouts page, the Connected Account page, and the booking detail refund panel — ensure all three have a consistent voice.
- [ ] Dashboard-wide banner consolidation: one banner system, not three competing ones (Session 1's onboarding banner, Session 3's refund-failed banner, Session 5 unifies the styling / stacking).
- [ ] Internationalisation pass: every string introduced in Sessions 1–4 goes through `__()` (per D-008); no retrofitting at end.

### Audit and cleanup
- [ ] Audit every place in the codebase that branches on `Business.payment_mode`; confirm no code path was left reading the `online` / `customer_choice` values as if they didn't exist. Add explicit `match ($business->payment_mode) { ... }` handling wherever branching was previously `$business->payment_mode === 'offline' ? A : B`.
- [ ] Audit the slug reserved list — no new reservation needed (`/payments` is not a public route), but confirm.
- [ ] Confirm the bookings list filter and the Pending Actions list (Calendar integration) both behave sanely for online bookings.

### Tests + ops
- [ ] Feature tests: admin can select `online` when connected; admin cannot select `online` when not connected; server-side 422 enforces the same rule; `customer_choice` end-to-end (customer picks online → Checkout; customer picks offline → no Checkout); race test where connected account is disabled between page load and form submit.
- [ ] Visual regression pass on every dashboard page that grew a banner or panel.
- [ ] Pint clean, full Pest suite green, `npm run build` clean.
- [ ] `docs/HANDOFF.md` updated; the roadmap is closed; decisions D-096.. are recorded in `docs/decisions/DECISIONS-PAYMENTS.md` (the new topical file opened in Session 1).

---

## Cleanup tasks (after this roadmap is approved)

These are housekeeping moves performed before Session 1 starts. Listed here so they are not forgotten — they are not part of the sessions themselves.

- [ ] Update `docs/SPEC.md` §12: remove "Online payments / Stripe processing" and "Social login (Google, Apple via Socialite) — Stripe Connect" references from the Out-of-Scope table; add a forward-pointer: "Customer-to-professional payments are tracked in `docs/roadmaps/ROADMAP-PAYMENTS.md`."
- [ ] Update `docs/SPEC.md` §7.6 "Payment mode" line: remove the parenthetical "online payment requires Stripe integration (v2)" and replace with "`online` / `customer_choice` require the Business to have connected a Stripe account — see `docs/roadmaps/ROADMAP-PAYMENTS.md`."
- [ ] Update `docs/SPEC.md` §2 Business Model: keep "No commission on individual bookings"; add a sentence clarifying that online customer payments go 100% to the professional via Stripe Connect once this roadmap ships.
- [ ] Update `docs/BACKLOG.md`: if an existing Stripe Connect / online-payments backlog entry exists, mark it "Scheduled — see `ROADMAP-PAYMENTS.md`"; if not, add a one-line pointer so backlog readers find the roadmap.
- [ ] Add a `docs/BACKLOG.md` entry: "Resend payment link for `unpaid` `customer_choice` bookings" — deferred from this roadmap's Session 2 out-of-scope. Captures the future ability to give the customer a second shot at prepaying online after a failed Checkout (today they default to paying at the appointment). Likely shape: a button on the customer's booking management page that mints a fresh Checkout session against the same booking row.
- [ ] Update `docs/README.md` "Read When Relevant" section: add a pointer to `docs/roadmaps/ROADMAP-PAYMENTS.md` alongside the existing secondary roadmaps.
- [ ] Create `docs/decisions/DECISIONS-PAYMENTS.md` as an empty topical file with the standard header comment, so Session 1 has a home for its decision rows. Reference it from `docs/DECISIONS.md`'s index.
- [ ] Leave `docs/roadmaps/ROADMAP-MVP-COMPLETION.md`, `docs/roadmaps/ROADMAP-E2E.md`, and `docs/roadmaps/ROADMAP-GROUP-BOOKINGS.md` in place — independent of this work.

---

*This roadmap defines the WHAT. The HOW is decided per session by the implementing agent in a dedicated plan document under `docs/plans/`. Each session leaves the full Pest suite green, Pint clean, and the Vite build green before close.*
