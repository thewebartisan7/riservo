# PAYMENTS Session 2a — Payment at Booking (Happy Path)

This plan lives at `docs/PLAN.md` and follows `.claude/references/PLAN.md`. It is a living document. `Progress`, `Surprises & Discoveries`, `Decision Log`, `Review`, and `Outcomes & Retrospective` are kept current as work proceeds.


## Purpose / Big Picture

After this session ships, a customer booking on a business whose `payment_mode` is `online` (or `customer_choice` with the customer picking "pay now") sees a "Continue to payment" CTA that redirects them to a Stripe-hosted Checkout page — card and (for CH accounts) TWINT. On completion, both a `checkout.session.completed` webhook on the Connect endpoint AND a synchronous `stripe.checkout.sessions.retrieve(...)` call performed by the success-page controller flip the booking to `confirmed + paid` (or `pending + paid` when the business uses manual confirmation per locked roadmap decision #29). The slot is protected by the existing Postgres GIST exclusion constraint from the moment the booking row is created as `pending + awaiting_payment`.

You can see the feature by:

1. Seeding a business with a verified `stripe_connected_accounts` row (the test suite's `StripeConnectedAccount::factory()->active()` state produces this) and `payment_mode = online`.
2. Booking a service on `riservo.ch/{slug}`, landing on Stripe Checkout, paying with a `4242 4242 4242 4242` test card.
3. Returning to `/bookings/{cancellation_token}/payment-success?session_id={CHECKOUT_SESSION_ID}`, seeing the booking flip to `confirmed + paid` synchronously (even if the webhook hasn't fired).

Failure branching, reaper, cancel-URL UX, refund scaffolding, admin payment panel, and lifting the Settings → Booking UI hide are all Session 2b+. Session 2a is deliberately happy-path only, plus the defensive guards that must ship with it (country assertion, idempotent success-page retrieve, manual-confirmation copy branch, async-payment hedge).


## Progress

- [x] (2026-04-23) Plan approved by developer.
- [x] (2026-04-23) M1 — Data layer: `PaymentStatus` extended (Pending retired); migration `2026_04_23_174426_add_payment_columns_to_bookings.php` adds eight columns + index; `Booking` model carries new casts + helpers (`isOnlinePayment`, `wasCustomerChoice`, `remainingRefundableCents`); factory default flipped to NotApplicable + new `awaitingPayment()` / `paid()` states; all three `payment_status => 'pending'` writers updated.
- [x] (2026-04-23) M2 — Checkout creation: `CheckoutSessionFactory` authored with country assertion + TWINT branch + locale resolver; `PublicBookingController::store` online branch writes `Pending + AwaitingPayment + expires_at + paid_amount_cents + currency + payment_mode_at_creation`, mints the Checkout session on the connected account, marks the booking Cancelled on failure to release the slot. Public `show` surfaces `payment_mode` / `can_accept_online_payments` / `currency` props. `StorePublicBookingRequest` accepts optional `payment_choice`.
- [x] (2026-04-23) M3 — Return URLs: `BookingPaymentReturnController::success` performs the synchronous `checkout.sessions.retrieve` on the connected account (via `withTrashed` per locked decision #36) and delegates promotion to `CheckoutPromoter`; rejects mismatched `session_id` queries; renders `booking/payment-success` processing page on async sessions. `cancel()` is the Session 2a stub. Routes mounted under `throttle:booking-api`. `BookingManagementController::show` surfaces the new `payment` prop bag.
- [x] (2026-04-23) M4 — Webhook: `checkout.session.completed` + `checkout.session.async_payment_succeeded` arms added to `StripeConnectWebhookController::dispatch`. `handleCheckoutSessionCompleted` performs outcome-level idempotency + cross-account guard + delegates to `CheckoutPromoter`. The shared `CheckoutPromoter` service wraps promotion in `DB::transaction` + `lockForUpdate` (pattern D-148) and dispatches notifications with the manual-confirmation branch per locked decision #29.
- [x] (2026-04-23) M5 — FakeStripeClient: `mockCheckoutSessionCreateOnAccount` + `mockCheckoutSessionRetrieveOnAccount` authored + the connected-account-level contract comment block added; `stripe_account` header is asserted PRESENT.
- [x] (2026-04-23) M6 — Public booking UI: "Continue to payment" CTA for the online branch, inline "Pay now / Pay on site" pill for `customer_choice`, external `window.location.href` redirect when `redirect_url` is absolute. `booking/payment-success` Inertia page renders the async-pending spinner. `bookings/show` shows payment status + paid amount + "awaiting payment" copy. `PublicBusiness`, `BookingStoreResponse`, `BookingDetail` TS types extended.
- [x] (2026-04-23) M7 — Notifications: `BookingReceivedNotification` now carries the `paid_awaiting_confirmation` context (customer-facing) per locked decision #29; the `mail/booking-received.blade.php` template renders the new branch.
- [x] (2026-04-23) M8 — Tests + ops: 42 new tests / 124 new assertions (819 passed / 3248 assertions total; baseline 777 / 3124). Iteration loop clean: Pint, PHPStan level 5, `wayfinder:generate`, `npm run build`. DEPLOYMENT.md updated with Session 2a's active webhook events + the TWINT test-flow notes.
- [ ] Codex review (pending developer trigger).
- [ ] Developer commit.


## Surprises & Discoveries

- (none yet — update as implementation progresses)


## Decision Log

- **Decision**: new `App\Services\Payments\CheckoutPromoter` service encapsulates the "promote a `pending + awaiting_payment` booking given a retrieved Stripe Session" logic. Both the webhook handler and the success-page return controller call it; the DB-state guard per locked decision #33 lives there once, not in two controllers. This is the same shape Session 2b will extend for the reaper's pre-flight retrieve.
  **Rationale**: without this, the idempotency guard would live in two places and drift on maintenance.
  **Date / Author**: 2026-04-23 / planning agent.

- **Decision**: new `App\Services\Payments\CheckoutSessionFactory` owns the `checkout.sessions.create` call + country assertion + `payment_method_types` branching. `PublicBookingController::store` calls it after committing the booking row.
  **Rationale**: keeps the controller small; the country assertion is tested once against the factory rather than in every call-site test.
  **Date / Author**: 2026-04-23 / planning agent.

- **Decision**: success URL is `/bookings/{token}/payment-success?session_id={CHECKOUT_SESSION_ID}` (Stripe's server-side template placeholder is replaced at redirect time). Route carries the standard `throttle:booking-api` rate limiter. No signed URL — the `cancellation_token` is already the bearer secret for the booking record (existing `bookings.show` / `bookings.cancel` use it). We cross-check the Stripe session's `client_reference_id` equals the booking's id to prevent a hostile substitution.
  **Rationale**: reuses the existing guest-booking auth shape, avoids minting a second token scheme, keeps the URL shareable.
  **Date / Author**: 2026-04-23 / planning agent.

- **Decision**: `currency` on the booking row is captured at **booking creation time** from `stripe_connected_accounts.default_currency` — NOT overwritten by the Checkout session response on webhook promotion. Per locked decision #42 refunds must replay the same currency even if the account's default later changes; capturing at the earliest possible moment maximises that invariant.
  **Rationale**: capturing on promotion would leave refunds subject to currency drift between booking creation and completion.
  **Date / Author**: 2026-04-23 / planning agent.

- **Decision**: `paid_amount_cents` on the booking row is captured at **booking creation time** from `Service.price` converted to the smallest currency unit. The Checkout line item uses the same value, so the Stripe-reported amount is expected to equal the booking's captured value; if they differ on promotion, we log a critical and persist Stripe's figure (Stripe is authoritative on what was actually charged).
  **Rationale**: `paid_amount_cents` is the column refunds read (locked decision #37) and Session 2b's reaper reads; it must be populated before the customer ever sees the booking row so the reaper doesn't have to branch on "did the webhook run yet?".
  **Date / Author**: 2026-04-23 / planning agent.

- **Decision**: TWINT inclusion branches on `config('payments.twint_countries')`, NOT on Stripe capability detection. Per locked decision #3 TWINT is mandatory (not opt-in) for CH-located accounts; enabling it via the config key keeps the single-switch shape from D-112 and lines up with the non-CH-fast-follow fallback path (card-only for countries in `supported_countries` but not in `twint_countries`).
  **Rationale**: also satisfies decision #43's "no hardcoded `'CH'` anywhere".
  **Date / Author**: 2026-04-23 / planning agent.

- **Decision**: the `Pending` case on `PaymentStatus` is removed outright (not soft-aliased). Per the locked roadmap "no production data migration concerns" clause, existing dev-DB seed rows are `migrate:fresh --seed` reset. `BookingFactory` default flips to `PaymentStatus::NotApplicable` (reflecting the pre-Session-2a default `source = riservo, payment_mode = offline` factory state).
  **Rationale**: avoids carrying a dead enum case whose only purpose would be legacy data compatibility that does not exist.
  **Date / Author**: 2026-04-23 / planning agent.

- **Decision**: `stripe_charge_id` stays null in 2a — populating it would require a second Stripe API call (`payment_intents.retrieve` → `charges.data[0].id`) after webhook promotion. Session 3's refund path accepts the `payment_intent` id equivalently on `refunds.create`, so the column is unused until then.
  **Rationale**: saves an API round-trip per promotion; keeps M4 minimal; Session 3 can fill the column on demand if its refund implementation benefits.
  **Date / Author**: 2026-04-23 / planning agent.


## Review

### Round 1 (2026-04-23, codex adversarial review, applied on the same uncommitted diff)

**Codex verdict**: needs-attention — three high-severity findings. Two accepted, one rejected.

- [x] **F1 — Abandoned Checkout sessions never release the reserved slot** — *rejected, roadmap-scope*.
  *Location*: `app/Http/Controllers/Booking/BookingPaymentReturnController.php:130-143` (cancel stub), absence of `checkout.session.expired` webhook arm.
  *Assessment*: Locked roadmap decision #14 defines the branching semantics (`online` → slot released; `customer_choice` → `confirmed + unpaid`); locked decision #31 defines the 90-minute reaper with the grace-buffer + pre-flight-retrieve + late-webhook-refund defense-in-depth. Both are explicit Session 2b deliverables — the roadmap scoped Session 2a to the happy path precisely so 2b's failure branching could get its own reviewable unit. Shipping 2a's happy path without 2b reintroduces the slot-starvation risk Codex flagged, but the risk is bounded: `payment_mode = online` / `customer_choice` is UI-hidden in Settings → Booking (locked decision #27) AND server-validated-off via `UpdateBookingSettingsRequest::paymentModeRolloutRule()` (D-132) until Session 5 lifts both gates. Session 5 cannot ship without Sessions 2b, 3, and 4 — the roadmap explicitly sequences them. Between 2a-commit and 2b-commit, only direct-DB dogfooding businesses can reach the online-payment flow at all; a stale `pending + awaiting_payment` row on a dogfooding business is an acceptable dogfooding cost.
  *Fix*: none. Session 2b's `## Session 2b` block in `docs/ROADMAP.md` is the actual implementation vehicle.
  *Status*: rejected (documented for the record).

- [x] **F2 — Webhook promotion trusts any paid session on the same connected account** — *accepted, fixed*.
  *Location*: `app/Http/Controllers/Webhooks/StripeConnectWebhookController.php` `handleCheckoutSessionCompleted` + `app/Services/Payments/CheckoutPromoter.php` amount/currency handling.
  *Assessment*: Codex is right. The webhook looked bookings up by `client_reference_id` and accepted any session on the matching connected account. The success-page controller cross-checks `session_id` via its query-param guard (D-153); the webhook had no equivalent check. D-152's original "log and overwrite on amount/currency mismatch" stance was also wrong: a mismatch on a fixed-`unit_amount` Checkout session is pathological (coding bug, Stripe-side error, or hostile reconciliation). Overwriting with Stripe's figures would confirm an attack or bury a bug; the captured columns are load-bearing for Session 3's refund path (locked decisions #37 / #42), so silently overwriting them is worse than failing closed.
  *Fix*: `CheckoutPromoter::promote` now (a) rejects with `'mismatch'` outcome + critical log when `$session->id !== $booking->stripe_checkout_session_id` — moved the guard to the shared service so webhook AND success-page both inherit it; (b) rejects with `'mismatch'` + critical log on `amount_total` or `currency` divergence from the booking's captured values. The webhook handler 200s regardless (Stripe should not retry a mismatch — it will re-fire the same mismatch); the success-page renders a neutral "we'll follow up" flash instead of the success confirmation. Three new regression tests in `tests/Feature/Payments/CheckoutSessionCompletedWebhookTest.php` cover session-id, amount, and currency mismatch cases. D-152 is superseded by D-156 (see `docs/decisions/DECISIONS-PAYMENTS.md`).
  *Status*: done.

- [x] **F3 — Customers can cancel fully paid bookings without any refund handling** — *accepted, fixed*.
  *Location*: `app/Http/Controllers/Booking/BookingManagementController.php` `cancel()`.
  *Assessment*: Codex is right. The customer cancel path keys on booking `status` + cancellation window only; `payment_status = Paid` bookings satisfy `canCancel()` and the cancel transitions the booking to `Cancelled` with no refund — leaving `cancelled + paid` rows where the slot is freed but funds stay on the connected account. Session 3 will eventually relax this to "in-window cancel → automatic `RefundService::refund($booking, null, 'customer-requested')`" per locked decisions #15 / #16. Until Session 3's `RefundService` exists, the only safe behaviour is to refuse the customer-side cancel and route them to the business.
  *Fix*: `BookingManagementController::cancel` now refuses with a "please contact :business to cancel" error flash when `$booking->payment_status === PaymentStatus::Paid`. The `can_cancel` Inertia prop stays true so the existing button still renders and clicks surface the flash — matching the existing cancellation-window-exceeded UX shape. One new regression test in `tests/Feature/Booking/BookingManagementTest.php`. Session 3 will replace the block with the automatic-refund path in a two-line diff.
  *Status*: done.

**Round 1 test delta**: 819/3248 → **823/3261** (+4 tests / +13 assertions). All other invariants unchanged.
**Round 1 decision delta**: D-156 added; D-152 superseded.

### Round 2 (2026-04-23, codex native review, applied on the same uncommitted diff)

**Codex verdict**: 5 findings — 4 accepted and fixed, 1 rejected with the same roadmap-scope reasoning as Round 1 F1.

- [x] **[P2] Persist the connected account that minted the checkout** — *accepted, fixed*.
  *Location*: `StripeConnectWebhookController::handleCheckoutSessionCompleted` (`withTrashed()->value('stripe_account_id')`) + `BookingPaymentReturnController::success` (same lookup shape).
  *Assessment*: A business with disconnect+reconnect history carries multiple `stripe_connected_accounts` rows. `withTrashed()->where('business_id', …)->value('stripe_account_id')` returns the first match — order not guaranteed. Legitimate late webhooks for EITHER the old or new account could be rejected as cross-account mismatches. The booking must remember which account minted its Checkout session.
  *Fix*: new `bookings.stripe_connected_account_id` column (nullable string) added to the Session 2a migration; `Booking` model + factory states (`awaitingPayment()`, `paid()`) write it; `PublicBookingController::store` online branch pins `$connectedAccount->stripe_account_id` onto the booking at creation time. Webhook + success-page controllers now read `$booking->stripe_connected_account_id` directly — fail closed if null (pre-M2 bookings have `payment_mode_at_creation = 'offline'` and never reach these paths anyway). D-158 in `docs/decisions/DECISIONS-PAYMENTS.md`. New regression test `codex round 2 D-158: reconnect history does not break webhook cross-account guard` exercises the pinned column with a trashed historical row alongside the active one.
  *Status*: done.

- [x] **[P1] Guard paid-booking cancellation on every endpoint** — *accepted, fixed*.
  *Location*: `Customer\BookingController::cancel` (authenticated customer path) + `Dashboard\BookingController::updateStatus` (admin cancels via the dashboard). The Round 1 F3 fix (D-157) only covered `BookingManagementController::cancel` — the other two endpoints still produced `cancelled + paid` rows with no refund.
  *Fix*: both paths now refuse the transition when `$booking->payment_status === PaymentStatus::Paid`. Customer path surfaces the same "contact the business" flash as D-157. Admin path surfaces dashboard-appropriate copy: "refund in Stripe first, then cancel" — Session 3's automatic-refund-on-admin-cancel (locked decision #17) will relax this once `RefundService` exists. D-159. New regression file `tests/Feature/Booking/PaidCancellationGuardTest.php` with one case per endpoint.
  *Status*: done.

- [x] **[P2] Don't flash "confirmed" for manual-review payments** — *accepted, fixed*.
  *Location*: `BookingPaymentReturnController::success` — the unconditional "Payment received — your booking is confirmed." flash contradicted the `pending + paid` state that manual-confirmation businesses land in per locked decision #29.
  *Fix*: new `successFlashFor(Booking $booking): string` helper branches on `$booking->status`. `Pending` → "Payment received — your booking is pending confirmation from the business." `Confirmed` → the original copy. Both the outcome-level fast path (already-paid) and the post-promotion path use the helper. D-160. Two new regression tests in `CheckoutSuccessReturnTest.php` cover each branch with `Notification::assertSessionHas` + the email context.
  *Status*: done.

- [x] **[P1] Add an expiry path before reserving slots for checkout** — *rejected, roadmap-scope* (identical to Round 1 F1).
  *Assessment*: the reaper + `checkout.session.expired` webhook arm are explicit Session 2b deliverables per locked decisions #13 / #14 / #31. The roadmap split Session 2 into a happy-path half (2a) and a failure-branching half (2b) precisely so the reaper + expiry webhooks get their own reviewable unit. Between 2a-commit and 2b-commit, `payment_mode = online` is UI-hidden (locked decision #27) AND server-validated-off (D-132) — only dogfooding reaches the flow at all, and Session 5 (which lifts both gates) cannot ship without Sessions 2b / 3 / 4 by roadmap sequencing. Codex flagged the same concern in Round 1 F1; the same reasoning rejects it again.
  *Status*: rejected (documented for the record — confirmed by the developer; the spec-level rule "if online-only booking is set in config, then booking slot must be unblocked, otherwise not" is exactly what locked decision #14 codifies and Session 2b implements).

- [x] **[P2] Distinguish Stripe URLs from internal HTTPS URLs** — *accepted, fixed*.
  *Location*: `resources/js/components/booking/booking-summary.tsx` — the `result.redirect_url?.startsWith('https://')` heuristic would fire for HTTPS-deployed riservo internal URLs too, skipping the confirmation step and hard-navigating for every booking.
  *Fix*: `PublicBookingController::store` now returns an explicit `external_redirect: boolean` on both branches (`false` on offline, `true` on online/Checkout). `BookingStoreResponse` TS type extended; `booking-summary.tsx` dispatches on the boolean instead of inspecting the URL. D-161. Two new assertions: the online happy-path test asserts `external_redirect=true`; a new dedicated test asserts `external_redirect=false` on the offline path.
  *Status*: done.

**Round 2 test delta**: 823/3261 → **828/3275** (+5 tests / +14 assertions). The paid-cancel test file covers two endpoints; the D-158 reconnect-history test, the D-160 manual vs auto flash pair, and the D-161 offline-path assertion make up the rest.
**Round 2 decision delta**: D-158, D-159, D-160, D-161 added.
**Iteration loop**: Pest green, Pint clean, PHPStan no errors, Wayfinder regenerated, Vite build green (same ballpark as before).


## Outcomes & Retrospective

**What shipped** (end of exec, pre-review):

- Customers on a business with `payment_mode = online` (or `customer_choice` with pay-now pick) see a "Continue to payment" CTA; clicking it creates a `pending + awaiting_payment` booking (slot protected by the existing GIST overlap constraint), mints a Stripe Checkout session on the connected account (direct-charge, `Stripe-Account` header asserted; card + TWINT on CH accounts via `config('payments.twint_countries')`), and redirects to the hosted page via `window.location.href`.
- Stripe's `success_url` lands back on `/bookings/{token}/payment-success?session_id=cs_test_…` which performs a synchronous `checkout.sessions.retrieve` on the connected account and promotes the booking inline via the shared `CheckoutPromoter` service. The customer is redirected to the booking page showing `Confirmed + Paid`. The webhook (`checkout.session.completed` / `checkout.session.async_payment_succeeded`) is the authoritative backstop — if the customer closed the tab, the webhook promotes the same way.
- Manual-confirmation businesses (locked decision #29) land the booking at `pending + paid` and dispatch the new `BookingReceivedNotification` `paid_awaiting_confirmation` context to the customer; admins receive the existing `'new'` context. Session 3 wires the admin-rejection → refund path.
- `CheckoutPromoter::promote` serialises via `DB::transaction` + `lockForUpdate` (pattern D-148) and short-circuits on `payment_status === Paid` — webhook-vs-success-page races, cache-flush replays, and fresh-event replays all converge to a single promotion with no double notifications.
- Country gating (locked decision #43) is enforced by `CheckoutSessionFactory::assertSupportedCountry` before any Stripe call; a drifted account (country not in `config('payments.supported_countries')`) is refused with a 422 and the slot is released. Every config check goes through `config('payments.*')` — no hardcoded `'CH'` anywhere in app code, tests, or TS.
- Manual (`source = manual`) and google-calendar (`source = google_calendar`) bookings always write `payment_mode_at_creation = 'offline'` and `payment_status = not_applicable` per locked decision #30, regardless of Business payment mode.

**Gaps vs the original Purpose**: none. Every bullet in Purpose and every roadmap 2a requirement landed.

**Session 2b carry-overs** (documented in the roadmap — NOT regressions):

- Failure branching (`checkout.session.expired`, `payment_intent.payment_failed`, async-payment-failed) + the online / customer_choice branching per locked decision #14.
- 90-minute reaper with the pre-flight-retrieve + late-webhook-refund defense-in-depth (locked decision #31).
- `cancel_url` state transitions (Session 2a's stub just redirects back with an informational flash).
- Admin-side booking-detail payment panel + bookings-list "Payment" filter chips.
- Minimal `booking_refunds` table + `RefundService` skeleton (cancelled-after-payment path, per locked decision #31).

**Lessons**:

1. Larastan's HasOne-generic inference treats relation accessors as always non-null. The ergonomic fix is to tighten downstream method signatures (`StripeConnectedAccount` instead of `?StripeConnectedAccount`) and delete the null-guard defense; the D-127 / D-138 contract already guarantees it in the caller's scope.
2. Pest's `toContain` is iterable-only. For source-inspection structural tests use `str_contains` + `->toBeTrue()` (the D-148 R12-2 pattern already paid this tax).
3. The existing `BookingFactory` default had no `payment_mode_at_creation`; the migration's `default('offline')` plus an explicit factory write covered every writer. Tests that instantiate `Booking::factory()->create()` directly keep a sane default without opt-in.
4. The Session 1 `FakeStripeClient` split (platform vs connected-account, header ABSENT vs PRESENT) paid off here — the two new mock methods simply extend the connected-account bucket; a Session 2b `mockRefundCreate` will follow the same shape and the Session 4 payout/tax methods likewise.

**Post-exec review rounds**:
- **Round 1 (Codex adversarial)**: 3 findings. F1 rejected (Session 2b scope — roadmap owns the reaper + expiry webhook + cancel_url state transitions per locked decisions #13 / #14 / #31). F2 accepted + fixed (D-156 — `CheckoutPromoter` fails closed on session-id / amount / currency mismatch; supersedes D-152). F3 accepted + fixed (D-157 — `BookingManagementController::cancel` refuses paid bookings until Session 3). +4 tests / +13 assertions.
- **Round 2 (Codex native)**: 5 findings. P2 accepted + fixed (D-158 — new `bookings.stripe_connected_account_id` column pins the minting account id; webhook + success-page cross-account guards read the pinned id instead of `withTrashed()->value()` against business). P1 accepted + fixed (D-159 — paid-cancel guards extended to Customer + Dashboard endpoints). P2 accepted + fixed (D-160 — success-flash copy branches on `$booking->status` so manual-confirmation landings don't say "confirmed"). P1 rejected (same Session 2b roadmap-scope argument as R1 F1; confirmed by developer). P2 accepted + fixed (D-161 — explicit `external_redirect: bool` on the JSON response instead of the `https://` prefix heuristic). +5 tests / +14 assertions.

**Final test baseline**: 829 passed / 3277 assertions (Session 1 close was 777 / 3124; Session 2a added 52 tests / 153 assertions across exec + both review rounds + a close-checklist GIST slot-lock regression test). Iteration loop clean throughout (Pint, PHPStan level 5, Wayfinder, Vite build).

**Next step**: developer commits the full bundle (exec + Round 1 + Round 2) in one commit.


## Context and Orientation

### What Session 1 left on the ground (the launch pad for 2a)

The previous session (PAYMENTS Session 1, commit immediately before HEAD, 777 tests / 3123 assertions) shipped:

- `stripe_connected_accounts` table with a partial unique on `(business_id) WHERE deleted_at IS NULL` (D-122). `StripeConnectedAccount` model with `verificationStatus(): 'pending'|'incomplete'|'active'|'disabled'|'unsupported_market'` (D-150) and `matchesAuthoritativeState(array)` for outcome-level idempotency (locked roadmap decision #33).
- `Business::stripeConnectedAccount(): HasOne` (SoftDeletes-scoped) + `canAcceptOnlinePayments(): bool` that already folds in Stripe capabilities + `requirements_disabled_reason` (D-138) + supported-country gate (D-127).
- New `config/payments.php` with `supported_countries`, `default_onboarding_country`, `twint_countries`. MVP values = `['CH']`. No hardcoded literal anywhere in app code, tests, or Inertia props.
- `App\Support\Billing\DedupesStripeWebhookEvents` trait. Two cache prefixes: `stripe:subscription:event:` (MVPC-3 `StripeWebhookController`) and `stripe:connect:event:` (this session's `StripeConnectWebhookController`).
- `POST /webhooks/stripe-connect` (route name `webhooks.stripe-connect`) served by `StripeConnectWebhookController` — NOT a Cashier subclass (D-109). Already dispatches `account.updated`, `account.application.deauthorized`, `charge.dispute.*`. Signature verification reads `config('services.stripe.connect_webhook_secret')`; empty secret fails closed outside `testing` env (D-120). Session 2a adds `checkout.session.completed` + `checkout.session.async_payment_succeeded` arms to the existing `match` on `$event->type`.
- `calendar_pending_actions` → `pending_actions` (D-113). `integration_id` nullable. `PendingActionType::calendarValues()` is the static helper that every calendar-aware reader filters through. `PendingActionType::PaymentDisputeOpened`, `PaymentRefundFailed`, `PaymentCancelledAfterPayment` are pre-added for Sessions 2b/3 writers (D-119).
- Inertia shared prop `auth.business.connected_account = { status, country, can_accept_online_payments, payment_mode_mismatch }` (D-114, resolved in `HandleInertiaRequests::resolveConnectedAccountPayload`).
- `UpdateBookingSettingsRequest::paymentModeRolloutRule()` hard-blocks non-offline `payment_mode` except via idempotent passthrough (D-132). Session 2a MUST NOT re-expose `online` / `customer_choice` in the UI — locked roadmap decision #27 covers Sessions 1–4; Session 5 lifts it.
- `tests/Support/Billing/FakeStripeClient` split into platform-level (`stripe_account` header ABSENT) and a documented Session 2+ contract for connected-account-level methods (header PRESENT). Session 2a wires the first two members of that contract.
- `Business` has a canonical `country` column (D-141), seeded on registration via `config('payments.default_onboarding_country')` (D-143). This column gates onboarding; Session 2a also reads `$business->stripeConnectedAccount->country` as the gate for Checkout creation per locked decision #43.

### Key files Session 2a will touch or author

**Touch** (existing files):

- `app/Enums/PaymentStatus.php` — extend the enum, drop `Pending` case.
- `app/Models/Booking.php` — add casts, fillable, new helpers.
- `app/Http/Controllers/Booking/PublicBookingController.php` — `store` action (online-payment branch); `show` action (prop additions for the booking React page).
- `app/Http/Controllers/Booking/BookingManagementController.php` — `show` action (prop additions for the `bookings/show` React page so paid/unpaid state is visible).
- `app/Http/Controllers/Dashboard/BookingController.php` — manual booking `store` writes `payment_mode_at_creation = 'offline'` + `payment_status = not_applicable` explicitly.
- `app/Jobs/Calendar/PullCalendarEventsJob.php` — google-calendar booking writes the same.
- `app/Http/Controllers/Webhooks/StripeConnectWebhookController.php` — add `checkout.session.completed` / `checkout.session.async_payment_succeeded` arms.
- `app/Http/Requests/Booking/StorePublicBookingRequest.php` — add optional `payment_choice` field.
- `database/factories/BookingFactory.php` — flip default `payment_status` to `NotApplicable`.
- `database/seeders/BusinessSeeder.php` — any hardcoded `'pending'` strings flipped to `'not_applicable'`.
- `routes/web.php` — new routes for the payment success + cancel landings.
- `tests/Support/Billing/FakeStripeClient.php` — add the two connected-account-level methods (documented contract already in the file; implement).
- `resources/js/pages/booking/show.tsx` + `resources/js/components/booking/booking-summary.tsx` + `resources/js/components/booking/booking-confirmation.tsx` — "Continue to payment" CTA, Pay-now/Pay-on-site inline toggle for `customer_choice`, external-redirect handling.
- `resources/js/pages/bookings/show.tsx` — paid / awaiting-payment / unpaid surfaces.
- `resources/js/types/index.d.ts` — extend `PublicBusiness` + `BookingDetails` shapes.
- `app/Notifications/BookingReceivedNotification.php` — new `'paid_awaiting_confirmation'` context branch (locked decision #29).
- `docs/DEPLOYMENT.md` — Checkout event subscriptions + TWINT test notes.
- `docs/HANDOFF.md` — session close rewrite.
- `docs/decisions/DECISIONS-PAYMENTS.md` — promote new `D-151` (and following) decisions.

**Author** (new files):

- `database/migrations/2026_04_XX_XXXXXX_add_payment_columns_to_bookings.php` — the columns + index from locked decision #28.
- `app/Services/Payments/CheckoutSessionFactory.php` — builds the Stripe Checkout session on the connected account.
- `app/Services/Payments/CheckoutPromoter.php` — promotes a booking given a retrieved Stripe session; idempotent per locked decision #33.
- `app/Http/Controllers/Booking/BookingPaymentReturnController.php` — `success()` + `cancel()`.
- `app/Exceptions/Payments/UnsupportedCountryForCheckout.php` — thrown by the country assertion.
- `resources/js/pages/booking/payment-success.tsx` — Inertia success landing (minimal; the heavy lifting is the controller's server-side retrieve + promote).
- `tests/Feature/Booking/OnlinePaymentCheckoutTest.php`, `tests/Feature/Booking/CheckoutSuccessReturnTest.php`, `tests/Feature/Payments/CheckoutSessionCompletedWebhookTest.php`, `tests/Feature/Booking/SnapshotInvariantTest.php`, `tests/Unit/Services/Payments/CheckoutSessionFactoryTest.php` — session-specific test suites.

### Terms of art (defined up front)

- **Connected account** — a Stripe Express account representing a professional's merchant-of-record identity. Created on the platform via `stripe.accounts.create`, updated via hosted KYC. Its id is `acct_…`. Every Stripe API call scoped to this account is issued with the per-request `['stripe_account' => $acct]` option (the "header"), which Stripe translates to the `Stripe-Account` HTTP header.
- **Direct charge** — Stripe pattern where the charge is created on the connected account (`['stripe_account' => $acct]` + no `application_fee_amount`). The money lands on the connected account; the professional is the merchant of record (locked decision #5).
- **Hosted Checkout** — `stripe.checkout.sessions.create` returns a session object with a `url` (e.g. `https://checkout.stripe.com/c/pay/cs_test_…`). The customer goes there to pay; Stripe handles card / TWINT / SCA UI; we never see a card number (locked decision #4).
- **`client_reference_id`** — a string we set on the Checkout session (we use the booking id as a string). Stripe echoes it back on the `checkout.session.completed` event and on `checkout.sessions.retrieve`. That's how the webhook handler and the success-page retrieve find the booking row.
- **`expires_at` on the Checkout session** — Stripe's timeout for the session. We override Stripe's 24 h default to 90 min per locked decision #13, and mirror the same `expires_at` on the booking row so Session 2b's reaper can filter on it.
- **Outcome-level idempotency (locked decision #33)** — every promotion handler guards `if ($booking->payment_status === PaymentStatus::Paid) { return no-op; }` at the top. Cache-layer event-id dedup (D-092 / D-110) protects against Stripe replays; the outcome-level guard protects against inline-promotion-then-webhook races and against a cache flush in dev.
- **Snapshot invariant on `payment_mode_at_creation` (locked decision #14)** — the column mirrors `Business.payment_mode` at the exact moment the booking row is inserted. It NEVER reflects the customer's checkout-step choice (pay-now vs pay-on-site); that choice decides whether a Checkout session is created, not what we write in the column. The SOLE carve-out is locked decision #30: `source ∈ {manual, google_calendar}` bookings always write `'offline'`.


## Plan of Work

### Milestone 1 — Data layer

Update `app/Enums/PaymentStatus.php` to the final set (locked decision #28):

```php
enum PaymentStatus: string
{
    case NotApplicable     = 'not_applicable';
    case AwaitingPayment   = 'awaiting_payment';
    case Paid              = 'paid';
    case Unpaid            = 'unpaid';
    case Refunded          = 'refunded';
    case PartiallyRefunded = 'partially_refunded';
    case RefundFailed      = 'refund_failed';
}
```

Drop the existing `Pending` case entirely. Per the roadmap's "no production data migration concerns" clause, existing dev-DB rows are `migrate:fresh --seed` reset. Local dev + CI run that command before each pass.

Authoring the migration `2026_04_XX_XXXXXX_add_payment_columns_to_bookings.php`:

```php
Schema::table('bookings', function (Blueprint $t) {
    $t->string('stripe_checkout_session_id')->nullable()->unique();
    $t->string('stripe_payment_intent_id')->nullable()->unique();
    $t->string('stripe_charge_id')->nullable()->unique();
    $t->unsignedInteger('paid_amount_cents')->nullable();
    $t->string('currency', 3)->nullable();
    $t->timestamp('paid_at')->nullable();
    $t->string('payment_mode_at_creation')->default('offline');
    $t->timestamp('expires_at')->nullable();

    $t->index(['business_id', 'expires_at']); // Session 2b reaper.
});
```

Down migration drops the columns + index in reverse. Rolling-deploy-safe by construction (additive only).

Update `app/Models/Booking.php`:

- Add every new column to `#[Fillable(...)]`.
- Cast `paid_at` and `expires_at` to `datetime`.
- Helpers:
    - `isOnlinePayment(): bool` — `$this->payment_mode_at_creation !== 'offline' && $this->stripe_checkout_session_id !== null`. The Checkout session existence distinguishes an online-attempted booking from a `customer_choice` pay-on-site pick.
    - `wasCustomerChoice(): bool` — `$this->payment_mode_at_creation === 'customer_choice'`.
    - `paidAmountCents(): ?int` — plain accessor (returns the column).
    - `remainingRefundableCents(): int` — returns `$this->paid_amount_cents ?? 0` in Session 2a. Session 2b expands this to subtract `SUM(booking_refunds.amount_cents WHERE status IN (pending, succeeded))` once that table lands.

Update `database/factories/BookingFactory.php` default: `payment_status => PaymentStatus::NotApplicable, payment_mode_at_creation => 'offline'`.

Update every site that currently writes `'payment_status' => 'pending'`:

- `app/Http/Controllers/Booking/PublicBookingController.php::store` (the non-online branch M2 finalises below).
- `app/Http/Controllers/Dashboard/BookingController.php::store` (manual booking path).
- `app/Jobs/Calendar/PullCalendarEventsJob.php` (external-booking upsert, around line 220).

Each site additionally writes `payment_mode_at_creation` explicitly:

- Manual + google-calendar (locked decision #30): always `'offline'`.
- Public controller offline path (service price null/0, business = offline, or customer_choice + customer-picks-offline): `$business->payment_mode->value` (the snapshot invariant in its simplest form).

Add PHPDoc to `Booking` naming the new string property `payment_mode_at_creation` but keep it as a plain string (no enum cast). Reason: the string directly equals a `PaymentMode` enum value at write time BUT the snapshot is immutable and must not be silently reinterpreted if the enum ever grows — keeping it as a plain string string-compares cleanly against `'offline'` / `'online'` / `'customer_choice'` literals at every read site.


### Milestone 2 — Checkout session creation in `PublicBookingController::store`

Extend `app/Http/Requests/Booking/StorePublicBookingRequest.php` with an optional `payment_choice` field accepting `'online' | 'offline'`. Only meaningful when `Business.payment_mode === 'customer_choice'`. Validated via `Rule::in(['online', 'offline'])` + `nullable`. Absent ⇒ the Business's own `payment_mode` decides the branch.

In `PublicBookingController::store`, after the DB transaction that creates the Booking row (existing code shape, roughly lines 253–312), branch on whether an online payment is due:

```php
$needsOnlinePayment =
    $service->price !== null
    && (float) $service->price > 0                          // locked decision #8
    && (
        $business->payment_mode === PaymentMode::Online
        || ($business->payment_mode === PaymentMode::CustomerChoice
            && ($validated['payment_choice'] ?? 'online') === 'online')
    );
```

**Offline path** (`$needsOnlinePayment === false`): the existing code stays. The booking row writes `payment_status = NotApplicable` and `payment_mode_at_creation = $business->payment_mode->value` (the snapshot invariant — customer_choice + offline pick still snapshots `'customer_choice'`, NOT `'offline'` — only locked decision #30's manual / google_calendar carve-out rewrites the snapshot).

**Online path** (`$needsOnlinePayment === true`): the booking row inside the existing DB transaction writes:

- `status = BookingStatus::Pending` (both confirmation modes start pending; the webhook + manual-confirmation branching in M4 decides the eventual target `status`).
- `payment_status = PaymentStatus::AwaitingPayment`.
- `payment_mode_at_creation = $business->payment_mode->value`.
- `expires_at = now()->addMinutes(90)` ONLY when `payment_mode_at_creation === 'online'`. Null otherwise (locked decision #13 — `customer_choice` bookings never expire via this path).
- `paid_amount_cents = (int) round($service->price * 100)`.
- `currency = $business->stripeConnectedAccount->default_currency ?? 'chf'` (lower-case; Stripe's convention).

Outside the transaction, if `$needsOnlinePayment === true`:

1. **Country assertion** (locked decision #43). Call `CheckoutSessionFactory::assertSupportedCountry($business->stripeConnectedAccount)`. Throws `UnsupportedCountryForCheckout` on failure.
2. **Checkout session creation**. Call `CheckoutSessionFactory::create($booking, $service, $business, $connectedAccount)`. The factory builds the params, invokes `$stripe->checkout->sessions->create($params, ['stripe_account' => $acct])`, and returns the session object.
3. **Persistence**. `$booking->update(['stripe_checkout_session_id' => $session->id])`.
4. **Response shape**. Return JSON `{ token, status, redirect_url: $session->url }`. The React side reads `redirect_url`; if it's an absolute URL (starts with `https://`), it does `window.location.href = result.redirect_url`. The offline path's existing `redirect_url = route('bookings.show', $token)` keeps its internal-redirect semantics.

Failure handling (inside a `try` wrapping steps 1–3):

- `UnsupportedCountryForCheckout` ⇒ `$booking->update(['status' => BookingStatus::Cancelled])`; slot released via status transition; log critical with connected-account id, its country, and `config('payments.supported_countries')`; return 422 with __('Online payments aren't available for this business right now — please contact them directly.').
- `Stripe\Exception\ApiErrorException` ⇒ `$booking->update(['status' => BookingStatus::Cancelled])`; `report($e)`; return 422 with __("Couldn't start payment. Please try again in a moment.").

**`CheckoutSessionFactory::create`** param shape:

```php
[
    'mode' => 'payment',
    'client_reference_id' => (string) $booking->id,
    'customer_email' => $booking->customer->email,
    'payment_method_types' => $this->paymentMethodTypes($connectedAccount->country),
    'line_items' => [[
        'price_data' => [
            'currency' => $connectedAccount->default_currency ?? 'chf',
            'product_data' => ['name' => $service->name],
            'unit_amount' => (int) round($service->price * 100),
        ],
        'quantity' => 1,
    ]],
    'metadata' => [
        'riservo_booking_id' => (string) $booking->id,
        'riservo_business_id' => (string) $business->id,
        'riservo_payment_mode_at_creation' => $booking->payment_mode_at_creation,
    ],
    'success_url' => route('bookings.payment-success', $booking->cancellation_token)
        . '?session_id={CHECKOUT_SESSION_ID}',
    'cancel_url'  => route('bookings.payment-cancel', $booking->cancellation_token),
    'locale'      => $this->resolveStripeLocale(app()->getLocale()),
    'expires_at'  => now()->addMinutes(90)->timestamp, // Unix epoch, matches the booking row's expires_at.
]
```

`paymentMethodTypes(string $country): array` returns `['card', 'twint']` when `in_array($country, (array) config('payments.twint_countries'), true)`, `['card']` otherwise. No hardcoded `'CH'` anywhere.

`resolveStripeLocale(string $appLocale): string` returns the app locale verbatim for `['it', 'de', 'fr', 'en']`; anything else returns `'auto'` (Stripe's browser-based detection). Lives on the factory as a private method; a dedicated unit test covers the four-locale invariant (locked decision #39).

**`CheckoutSessionFactory::assertSupportedCountry`** logic:

```php
public function assertSupportedCountry(StripeConnectedAccount $account): void
{
    $supported = (array) config('payments.supported_countries');

    if (! in_array($account->country, $supported, true)) {
        throw new UnsupportedCountryForCheckout(
            account: $account,
            supported: $supported,
        );
    }
}
```

### Milestone 3 — Success + cancel return URLs

New routes in `routes/web.php`, alongside the existing `bookings.show` / `bookings.cancel`:

```php
Route::get('/bookings/{token}/payment-success', [BookingPaymentReturnController::class, 'success'])
    ->middleware('throttle:booking-api')
    ->name('bookings.payment-success');

Route::get('/bookings/{token}/payment-cancel', [BookingPaymentReturnController::class, 'cancel'])
    ->middleware('throttle:booking-api')
    ->name('bookings.payment-cancel');
```

Both public (no auth). Token-based — the `cancellation_token` on the booking row is already the bearer secret (same auth model as `bookings.show` and `bookings.cancel`).

`BookingPaymentReturnController::success(string $token, Request $request)`:

1. Look up the booking by token; 404 on miss.
2. Read `session_id = $request->query('session_id')`. If missing, log a warning and redirect to `route('bookings.show', $token)` with a neutral flash. If present but doesn't match `$booking->stripe_checkout_session_id`, same — prevents a mismatched-session attack.
3. If `$booking->payment_status === PaymentStatus::Paid`, redirect to `bookings.show` with the "Payment received" flash. (Idempotent fast-path; also handles the webhook-beat-us case.)
4. Else, resolve the connected account: `$acct = $booking->business->stripeConnectedAccount()->withTrashed()->first()?->stripe_account_id` (locked decision #36 — disconnect retains the id; we still retrieve against the original account). If null, log critical + redirect to `bookings.show` with __('Your booking is pending — we'll follow up.').
5. Try `$stripe->checkout->sessions->retrieve($session_id, ['stripe_account' => $acct])`. On `ApiErrorException`, catch + log + redirect to `bookings.show` with __('Still processing — check back in a moment.'). The webhook remains the authoritative backstop.
6. Call `CheckoutPromoter::promote($booking, $session)`. If it returns `'paid'` or `'already_paid'`, redirect to `bookings.show` with the success flash. If `'not_paid'`, render the `booking/payment-success` Inertia page with `state: 'processing'`.

`BookingPaymentReturnController::cancel(string $token)`:

1. Look up the booking by token.
2. Redirect to the business's public booking page (`route('booking.show', ['slug' => $booking->business->slug])`) with a flash __('Payment not completed. Your slot has been released.'). The actual state transition (slot release / `unpaid` promotion) is Session 2b's responsibility; per the roadmap the cancel-URL hit is informational only in 2a.

### Milestone 4 — Webhook handler (`checkout.session.completed`)

Extend the `match ($event->type)` block in `StripeConnectWebhookController::dispatch` with:

```php
'checkout.session.completed',
'checkout.session.async_payment_succeeded' => $this->handleCheckoutSessionCompleted($event),
```

`handleCheckoutSessionCompleted(StripeEvent $event): Response` logic:

1. Extract `$session = $event->data->object`.
2. Extract `$bookingId = $session->client_reference_id`. Log warning + 200 if missing or non-numeric (defensive — should never happen since we set it at session-create time).
3. Look up `$booking = Booking::find((int) $bookingId)`. If null, log critical + 200 (unknown booking id — manual reconciliation). A 200 (not 503) here because the race window at booking-creation is fully inside our own DB transaction, not across Stripe API boundaries.
4. **Cross-account guard**: verify `$session->account === $booking->business->stripeConnectedAccount()->withTrashed()->first()?->stripe_account_id` (the `withTrashed` catches late-webhook-after-disconnect races per locked decision #36). If not, log critical + 200.
5. **Outcome-level idempotency** (locked decision #33): `if ($booking->payment_status === PaymentStatus::Paid) { return 200; }`.
6. Call `CheckoutPromoter::promote($booking, $session)` — same shared service the success-page uses — and 200 on return.

`CheckoutPromoter::promote(Booking $booking, StripeCheckoutSession $session): string` (returns `'paid'` | `'not_paid'` | `'already_paid'`):

- Wrap the state transition in `DB::transaction(...)` with `Booking::query()->whereKey($booking->id)->lockForUpdate()->first()` — serialises webhook-vs-success-page races.
- Inside the lock: `if ($locked->payment_status === PaymentStatus::Paid) { return 'already_paid'; }`.
- Branch on `$session->payment_status` (locked decision #41 — NOT on event name):
    - `'paid'` ⇒ proceed with promotion.
    - anything else ⇒ return `'not_paid'` without any DB write (the async-success event will fire later).
- Promote:
    - `payment_status = PaymentStatus::Paid`.
    - `stripe_payment_intent_id = $session->payment_intent` (string).
    - `paid_amount_cents`: if `(int) $session->amount_total !== $locked->paid_amount_cents`, log critical (with both values) and overwrite with Stripe's figure. Stripe is authoritative.
    - `currency`: same — if differ, log critical and overwrite with Stripe's figure (lower-cased).
    - `paid_at = now()`.
    - `expires_at = null`.
    - **Status branch (locked decision #29)**: if `$booking->business->confirmation_mode === ConfirmationMode::Manual`, status stays `Pending`. Otherwise `status = BookingStatus::Confirmed`.
- Dispatch notifications INSIDE the locked transaction's `afterCommit` closure (D-075 / D-088 pattern) so a rollback doesn't send phantom emails. Inside that closure:
    - If the promotion set `status = Confirmed`: `BookingConfirmedNotification` to customer (unless `shouldSuppressCustomerNotifications()`, impossible here since online-payment bookings are never `google_calendar`) + `BookingReceivedNotification($booking, 'new')` to admins + provider — same shape as the existing offline happy path.
    - If `status` stayed `Pending` (manual confirmation): customer receives `BookingReceivedNotification($booking, 'paid_awaiting_confirmation')`; admins receive the existing `'new'` context. (M7 wires the new customer-facing context.)
- Return `'paid'`.

**PHPStan level 5 note**: `DB::transaction`'s generic return inference can't resolve array-shapes cleanly. `CheckoutPromoter::promote` returns a scalar string; the status branch is decided inside the closure and captured via a by-reference local (`&$outcome`) — same pattern `ConnectedAccountController::runResumeInLock` uses per D-148.


### Milestone 5 — FakeStripeClient extensions

Implement the two connected-account-level methods documented in the file's Session-2+ contract block (lines 159–183 of `tests/Support/Billing/FakeStripeClient.php`):

```php
public function mockCheckoutSessionCreateOnAccount(
    string $expectedAccountId,
    array $response = [],
): self
```

Asserts `stripe_account === $expectedAccountId` is PRESENT in the per-request options. Returns a `StripeCheckoutSession` with at minimum `id = 'cs_test_'.uniqid()` and `url = 'https://checkout.stripe.com/c/pay/cs_test_fake'` merged over `$response`. The session id is introspectable on the fake (return value) so tests can wire it into the webhook replay.

```php
public function mockCheckoutSessionRetrieveOnAccount(
    string $expectedAccountId,
    string $sessionId,
    array $response = [],
): self
```

Asserts both the header presence AND `$id === $sessionId`. Returns a session constructed from `$response`, defaulting to:

```php
[
    'id' => $sessionId,
    'payment_status' => 'paid',
    'amount_total' => 5000,
    'currency' => 'chf',
    'payment_intent' => 'pi_test_'.uniqid(),
    'client_reference_id' => null, // test-writer sets this per booking
    'account' => $expectedAccountId,
]
```

Add a shared private `assertConnectedAccountLevel(array $opts, string $expectedAccountId): bool` helper mirroring the existing `assertPlatformLevel` — returns true only when `$opts['stripe_account'] ?? null === $expectedAccountId`. A missing `stripe_account` key fails the matcher (which surfaces as a Mockery "method not expected" — the D-109 contract test shape).

Existing `ensureCheckoutSessions()` handles the `checkout->sessions` mock shape; the new methods reuse it. Mockery's `withArgs` accepts the SDK's variadic signature (`$params, $opts`).


### Milestone 6 — Public booking UI

Extend `PublicBookingController::show` to surface:

```php
'business' => [
    ...,
    'payment_mode' => $business->payment_mode->value,
    'can_accept_online_payments' => $business->canAcceptOnlinePayments(),
    'currency' => $business->stripeConnectedAccount?->default_currency,
],
```

The React `booking/show.tsx` + `booking-summary.tsx` + `customer-form.tsx` flow:

- When `business.payment_mode === 'online' && business.can_accept_online_payments === true && service.price != null && service.price > 0`: Summary CTA text becomes "Continue to payment"; the confirmation caption adds "Your card or TWINT will be charged CHF {formatted(price)}". Otherwise the existing "Confirm booking" + offline copy.
- When `business.payment_mode === 'customer_choice' && business.can_accept_online_payments === true && service.price != null && service.price > 0`: the summary step introduces an inline two-option pill (`pay_now` / `pay_on_site`). Default = `pay_now`. The `payment_choice` submitted value is included in the POST body; server-side validation covers the enum.
- When `business.can_accept_online_payments === false`: the UI always renders the offline CTA + copy, regardless of the stored `payment_mode` value — the Business is in the mismatch banner state (D-114), and the M2 server-side gate already refuses Checkout creation. The UI convergence is a UX nicety.

POST response handling in the `<Form>`/`useHttp` submit handler: when `result.redirect_url` is an absolute URL (starts with `https://`), `window.location.href = result.redirect_url`. When it's a riservo-internal path, `router.visit(result.redirect_url)` as today. Covered by a type guard.

New Inertia page `resources/js/pages/booking/payment-success.tsx` — minimal:

```tsx
interface PaymentSuccessProps {
    state: 'processing';
    booking: { token: string; business: { name: string } };
}
```

Renders a "Processing payment" spinner + copy: __("We're confirming your payment with Stripe. This usually takes a moment. You'll receive a confirmation email shortly."). A small link back to `route('bookings.show', $token)` as the "check status" escape hatch.

Extend `resources/js/pages/bookings/show.tsx` to show:

- Payment badge when `booking.payment_status ∈ {paid, awaiting_payment, unpaid}`.
- Paid amount + currency when `payment_status = 'paid'` — formatted via a small client-side helper (`formatMoney(cents, currency)` in a shared helper file; simple `Intl.NumberFormat`).
- "Awaiting payment" copy when `payment_status = 'awaiting_payment'` AND `expires_at > now` — plus a "Resume payment" link that opens the Stripe Checkout URL (derived from `stripe_checkout_session_id`; we don't need an API call — Stripe accepts returning customers to `https://checkout.stripe.com/c/pay/$session_id` when the session is still open).
- If `expires_at < now`, show an "expired" state and a link to rebook.

Update `resources/js/types/index.d.ts` with the extended `PublicBusiness` and `BookingDetails` shapes per the Interfaces section below.


### Milestone 7 — Notification copy branch for manual-confirmation + paid

Extend `app/Notifications/BookingReceivedNotification.php` to accept the new context `'paid_awaiting_confirmation'` alongside the existing `'new'`, `'accepted'`, `'rejected'`, etc. The class already branches on `$this->context` via a `match` statement (standing D-057 pattern); this is an additional arm with its own subject + body.

Copy for `paid_awaiting_confirmation` (customer):

- Subject: __('Your booking is pending confirmation — your payment is received')
- Body: __('We received your payment. :business will confirm your booking shortly. If they can't accept it, you'll receive an automatic full refund.', ['business' => $booking->business->name])

Admins continue to receive the existing `'new'` context — their dashboard copy already explains the pending-review UX.

The rejection → refund path (locked decision #29) is NOT wired in Session 2a — Session 3 does it. Session 2a's manual-confirmation test asserts the booking lands at `pending + paid` and the notifications fire with the correct contexts. The refund assertion belongs to Session 3.


### Milestone 8 — Tests + ops

Test files + their scenarios:

- `tests/Feature/Booking/OnlinePaymentCheckoutTest.php` — the POST `/booking/{slug}/book` surface.
    - Happy path for `payment_mode = online`: booking created `pending + awaiting_payment`, Checkout header asserted, response `redirect_url` is Stripe's URL, booking row carries `stripe_checkout_session_id` + `expires_at` + `paid_amount_cents` + `currency` + `payment_mode_at_creation = 'online'`.
    - Happy path for `customer_choice` + `payment_choice = online`: same as above but snapshot is `'customer_choice'`.
    - Happy path for `customer_choice` + `payment_choice = offline`: no Checkout session, booking `confirmed + not_applicable` (auto-confirm) or `pending + not_applicable` (manual), `payment_mode_at_creation = 'customer_choice'` (NOT `'offline'` — snapshot invariant).
    - `Service.price = null` forces offline (no Checkout) regardless of Business mode.
    - `Service.price = 0` ditto.
    - Country assertion failure: seed a connected account with `country = 'DE'` and `config('payments.supported_countries') = ['CH']`. POST fails 422, booking is `Cancelled` (slot freed), a critical log fires. A second assertion flips `supported_countries` to `['CH', 'DE']` in-test and proves the same POST succeeds — seams open.
    - Stripe API failure on session create: mock `mockCheckoutSessionCreateOnAccount` throwing `ApiErrorException`; booking is marked `Cancelled`, 422 returned.
    - Manual booking via `Dashboard\BookingController::store` creates `payment_mode_at_creation = 'offline' + payment_status = not_applicable` regardless of Business mode.
    - Google-calendar external-event upsert creates the same (add an assertion to the existing `PullCalendarEventsJobTest.php`).

- `tests/Feature/Booking/CheckoutSuccessReturnTest.php` — the GET `/bookings/{token}/payment-success` surface.
    - Success page promotes inline when the webhook hasn't fired: mocks the retrieve, asserts the booking is `confirmed + paid`, asserts the notifications fired exactly once.
    - Success page is a no-op when the webhook already ran: the outcome-level guard short-circuits; no extra notifications.
    - Success page renders "processing" when Stripe retrieve returns `payment_status != paid`.
    - Success page ignores the promotion when `session_id` query param doesn't match the booking's `stripe_checkout_session_id`.
    - Success page renders the retry flash when Stripe retrieve throws.
    - Success page with `session_id` query missing redirects to `bookings.show` with a neutral flash.
    - Disconnected-account race: booking's connected-account row is trashed; retrieve is still attempted against the retained `stripe_account_id` via `withTrashed()` (locked decision #36); promotion proceeds.

- `tests/Feature/Payments/CheckoutSessionCompletedWebhookTest.php` — the webhook arm.
    - Happy path: `payment_status: paid` ⇒ booking promoted; fields persisted.
    - Stale replay: same event id ⇒ cache dedup 200; DB untouched.
    - Fresh-event replay on already-paid booking ⇒ outcome-level guard returns 200; no double notifications.
    - Manual-confirmation business: booking lands at `pending + paid`, paid-awaiting-confirmation notification fires.
    - `payment_status != paid`: no DB write (async pending); a subsequent `checkout.session.async_payment_succeeded` with `payment_status: paid` promotes.
    - Cross-account guard: session with an `account` field that doesn't match the booking's connected account ⇒ log critical + 200; booking untouched.
    - Disconnect race: booking's connected-account row is trashed; handler still resolves it via `withTrashed` and promotes.
    - Cache-prefix isolation: same event id posted to `/webhooks/stripe` (subscription endpoint) does NOT collide with the Connect dedup cache.
    - Locale test (Checkout create): parameterised across `['it', 'de', 'fr', 'en']` + one unexpected locale; asserts the correct `locale` param at the Stripe call.

- `tests/Feature/Booking/SnapshotInvariantTest.php` — the locked-decision-#14 three-outcome invariant:
    - Business = `customer_choice` + pay-now + Checkout succeeds ⇒ `'customer_choice'`.
    - Business = `customer_choice` + pay-now + Checkout failure (country assertion or Stripe API error) ⇒ `'customer_choice'` (on a Cancelled booking; the snapshot column is still the stored value).
    - Business = `customer_choice` + pay-on-site upfront (no Checkout session created) ⇒ `'customer_choice'` (NOT `'offline'`).
    - Business = `online` → `'online'`.
    - Business = `offline` → `'offline'`.
    - Source = `manual` → `'offline'` regardless of Business mode.
    - Source = `google_calendar` → `'offline'` regardless of Business mode.

- `tests/Feature/Booking/SnapshotDoesNotReactToBusinessChangeTest.php` — change `Business.payment_mode` after booking creation, assert the booking's `payment_mode_at_creation` is unchanged.

- `tests/Unit/Services/Payments/CheckoutSessionFactoryTest.php`:
    - Locale test matrix (locked decision #39): for each of `['it', 'de', 'fr', 'en']`, assert the factory passes that locale unchanged; for `'xx'` (unsupported), assert `'auto'`.
    - `payment_method_types` branches on `config('payments.twint_countries')` — flip it in-test to `[]` and assert the factory returns `['card']` only.
    - `assertSupportedCountry` throws `UnsupportedCountryForCheckout` when the account's country is not in the supported set; does not throw when it is.

- `tests/Unit/Services/Payments/CheckoutPromoterStructuralTest.php` — structural regression guard, mirroring the D-148 R12-2 pattern for `ConnectedAccountController`. Reads `app/Services/Payments/CheckoutPromoter.php` via `file_get_contents`, asserts the source carries BOTH `DB::transaction` AND `lockForUpdate(` AND an outcome-level guard of the shape `payment_status === PaymentStatus::Paid` (or the equivalent enum comparison). Concurrency is not reliably simulable in Pest without a multi-connection harness; this structural check is the belt-and-braces — a regression that removes the lock or the idempotency guard fails loudly at test time instead of surfacing in a production race. 10-line test, zero runtime cost.

Every feature test that touches Stripe either registers matching FakeStripeClient mocks (header asserted PRESENT for connected-account-level calls) or doesn't touch Stripe. A call that crosses the platform-vs-account category fails with a Mockery "method not expected" diagnostic by construction.

Update `docs/DEPLOYMENT.md`:

- Promote `checkout.session.completed` + `checkout.session.async_payment_succeeded` in the Connected-accounts webhook subscription list from "reserved for Session 2+" to "active in Session 2a".
- Note the TWINT test flow: Stripe's test mode supports TWINT for CH accounts; the hosted page short-circuits the mobile redirect in test mode and lets the tester click Succeed / Fail.
- No env var changes in 2a.


## Concrete Steps

Run from the repo root.

Before writing code (after plan approval):

```bash
git status                                          # verify only Session 1's staged diff
php artisan migrate:fresh --seed                    # reset dev DB
php artisan test tests/Feature tests/Unit --compact # baseline: 777/3123
```

During implementation, iteration loop (re-run after each milestone):

```bash
php artisan test tests/Feature tests/Unit --compact
vendor/bin/pint --dirty --format agent
php artisan wayfinder:generate
./vendor/bin/phpstan
npm run build
```

On completion:

```bash
git add -A                                          # stage (NEVER commit)
git status                                          # show the developer what's ready
```


## Validation and Acceptance

A developer runs `php artisan serve` + `npm run dev` with `.env` pointing at Stripe's test keys AND a test-mode Connect webhook secret in `STRIPE_CONNECT_WEBHOOK_SECRET`. They also:

1. Seed a business whose connected account is test-verified:
   ```bash
   php artisan tinker --execute '
     $b = \App\Models\Business::first();
     $b->update(["payment_mode" => "online", "country" => "CH"]);
     \App\Models\StripeConnectedAccount::factory()->active()->for($b)->create();'
   ```
2. Book a service as a customer on `http://localhost:8000/{slug}`. After filling in details, click "Continue to payment".
3. They land on Stripe's hosted Checkout (URL starts with `https://checkout.stripe.com/c/pay/`). Test card `4242 4242 4242 4242` / any future date / any CVC.
4. Stripe redirects them to `http://localhost:8000/bookings/{token}/payment-success?session_id=cs_test_…`. The page immediately redirects to `/bookings/{token}` which shows:
    - Booking status: Confirmed (or Pending for manual-confirmation businesses).
    - Payment status: Paid.
    - Paid amount: CHF 45.00 (or whatever the service price was).
5. Running `stripe listen --forward-to http://localhost:8000/webhooks/stripe-connect --connect` in a separate terminal: the developer sees the `checkout.session.completed` event arrive; the handler 200s; the DB state stays `confirmed + paid` (outcome-level idempotency proved by the second handler running without side effects).

Feature test invocations:

```bash
php artisan test tests/Feature/Booking/OnlinePaymentCheckoutTest.php --compact
php artisan test tests/Feature/Booking/CheckoutSuccessReturnTest.php --compact
php artisan test tests/Feature/Payments/CheckoutSessionCompletedWebhookTest.php --compact
php artisan test tests/Feature/Booking/SnapshotInvariantTest.php --compact
php artisan test tests/Unit/Services/Payments/CheckoutSessionFactoryTest.php --compact
```

Each suite green.

Baseline growth: Session 1 left 777 tests / 3123 assertions. Session 2a adds approximately 25–35 feature / unit tests — after this session the suite should sit around 805–815.

Before hand-off for commit:

```bash
php artisan test tests/Feature tests/Unit --compact   # green
vendor/bin/pint --dirty --format agent                # no changes reported
php artisan wayfinder:generate                        # no pending regenerates
./vendor/bin/phpstan                                  # level 5, app/ only, clean
npm run build                                         # green; bundle ≤ same ballpark as 542 kB
```


## Idempotence and Recovery

- Migration is purely additive: `down()` drops the added columns and the index. No existing column is mutated.
- Enum removal of `Pending` is safe because no production data exists (pre-launch). Dev DBs + CI run `migrate:fresh`.
- Every new controller action is idempotent under retry: success-page re-reads booking state before writing; webhook guards on `payment_status === Paid`; the shared `CheckoutPromoter` is the single source of truth for promotion semantics.
- If M2's Checkout session create fails after the booking row commits, the row is marked `Cancelled` in a follow-up small transaction. The slot releases via the existing GIST constraint (pending/confirmed-only status filter on the exclude-using-gist).
- If a Stripe session replays an `id` the controller has already persisted, the second `update(['stripe_checkout_session_id' => …])` is a no-op UPDATE (same value). No functional impact.


## Artifacts and Notes

Shape of the new params to Stripe (sanity-check snippet):

```php
// CheckoutSessionFactory::create, single 'stripe_account' header PRESENT.
$stripe->checkout->sessions->create([
    'mode' => 'payment',
    'client_reference_id' => (string) $booking->id,
    'customer_email' => $booking->customer->email,
    'payment_method_types' => ['card', 'twint'], // CH; card-only elsewhere
    'line_items' => [[
        'price_data' => [
            'currency' => 'chf',
            'product_data' => ['name' => 'Haircut'],
            'unit_amount' => 4500,
        ],
        'quantity' => 1,
    ]],
    'metadata' => [
        'riservo_booking_id' => '42',
        'riservo_business_id' => '17',
        'riservo_payment_mode_at_creation' => 'online',
    ],
    'success_url' => '.../bookings/TOKEN/payment-success?session_id={CHECKOUT_SESSION_ID}',
    'cancel_url'  => '.../bookings/TOKEN/payment-cancel',
    'locale'      => 'en',
    'expires_at'  => 1714176000, // Unix epoch.
], [
    'stripe_account' => 'acct_test_abc',
]);
```

Shape of the webhook event body (as the mocks produce):

```json
{
    "id": "evt_test_abc",
    "type": "checkout.session.completed",
    "account": "acct_test_abc",
    "data": {
        "object": {
            "id": "cs_test_xyz",
            "client_reference_id": "42",
            "payment_status": "paid",
            "payment_intent": "pi_test_xyz",
            "amount_total": 4500,
            "currency": "chf"
        }
    }
}
```


## Interfaces and Dependencies

### New classes and their public signatures

In `app/Services/Payments/CheckoutSessionFactory.php`:

```php
final class CheckoutSessionFactory
{
    public function __construct(private readonly StripeClient $stripe) {}

    /** @throws UnsupportedCountryForCheckout */
    public function assertSupportedCountry(StripeConnectedAccount $account): void;

    /** @throws ApiErrorException */
    public function create(
        Booking $booking,
        Service $service,
        Business $business,
        StripeConnectedAccount $account,
    ): \Stripe\Checkout\Session;
}
```

In `app/Services/Payments/CheckoutPromoter.php`:

```php
final class CheckoutPromoter
{
    /** @return 'paid'|'not_paid'|'already_paid' */
    public function promote(Booking $booking, \Stripe\Checkout\Session $session): string;
}
```

In `app/Http/Controllers/Booking/BookingPaymentReturnController.php`:

```php
final class BookingPaymentReturnController extends Controller
{
    public function success(string $token, Request $request): RedirectResponse|InertiaResponse;
    public function cancel(string $token): RedirectResponse;
}
```

In `app/Exceptions/Payments/UnsupportedCountryForCheckout.php`:

```php
final class UnsupportedCountryForCheckout extends \RuntimeException
{
    /** @param array<int, string> $supported */
    public function __construct(
        public readonly StripeConnectedAccount $account,
        public readonly array $supported,
    ) {
        parent::__construct(sprintf(
            'Connected account %s country %s is not in supported set [%s]',
            $account->stripe_account_id,
            $account->country,
            implode(', ', $supported),
        ));
    }
}
```

### New routes (Wayfinder-visible)

- `bookings.payment-success` — `GET /bookings/{token}/payment-success` — rate-limited by `throttle:booking-api`.
- `bookings.payment-cancel` — `GET /bookings/{token}/payment-cancel` — same limiter.

### Webhook dispatch match arm additions

```php
// StripeConnectWebhookController::dispatch()
return match ($event->type) {
    'account.updated' => $this->handleAccountUpdated($event),
    'account.application.deauthorized' => $this->handleAccountDeauthorized($event),
    'charge.dispute.created',
    'charge.dispute.updated',
    'charge.dispute.closed' => $this->handleDisputeEvent($event),

    // Session 2a additions:
    'checkout.session.completed',
    'checkout.session.async_payment_succeeded' => $this->handleCheckoutSessionCompleted($event),

    default => new Response('Webhook unhandled.', 200),
};
```

### Inertia prop shape additions

`PublicBookingController::show`'s `business` payload gains:

```ts
interface PublicBusiness {
    // ...existing fields...
    payment_mode: 'offline' | 'online' | 'customer_choice';
    can_accept_online_payments: boolean;
    currency: string | null; // ISO-4217 lower-case
}
```

`BookingManagementController::show`'s `booking` payload gains:

```ts
interface BookingDetails {
    // ...existing fields...
    payment_status: 'not_applicable' | 'awaiting_payment' | 'paid' | 'unpaid'
        | 'refunded' | 'partially_refunded' | 'refund_failed';
    paid_amount_cents: number | null;
    currency: string | null;
    paid_at: string | null; // ISO-8601
    expires_at: string | null; // ISO-8601
    stripe_checkout_session_id: string | null;
}
```

`StorePublicBookingRequest` gains:

```php
'payment_choice' => ['sometimes', 'nullable', Rule::in(['online', 'offline'])],
```

### New Inertia page

`resources/js/pages/booking/payment-success.tsx`:

```ts
interface PaymentSuccessProps {
    state: 'processing';
    booking: { token: string; business: { name: string } };
}
```


## Open Questions

- **None for the developer**. The roadmap plus the D-109..D-150 decisions plus the session brief give full coverage of every branch this session needs to ship. Any ambiguity the codex review round flags will be resolved in `## Review`.


## Risks & Notes

- **Async-payment hedge** (locked decision #41): the plan handles both `checkout.session.completed` and `checkout.session.async_payment_succeeded`. TWINT is nominally synchronous in Stripe's current API, but `CheckoutPromoter::promote` branches on `$session->payment_status` — NOT on event name — so a future Stripe change flipping TWINT async does not regress this path. Confirmed via a dedicated test assertion at plan time.
- **PHPStan level 5 on `CheckoutPromoter`**: reference-capture (`&$outcome`) is the standard escape — this is the same pattern D-148's `runResumeInLock()` uses. PHPStan can't infer array-shape returns from `DB::transaction` closures; returning a scalar string sidesteps that.
- **Rate-limit tension**: `throttle:booking-api` is the existing public limiter. A flaky customer who reloads the success URL repeatedly (because Stripe is slow) could theoretically hit the limit. The success URL is idempotent on both sides (outcome-level DB guard + retrieve short-circuits), so a burst is safe — the limiter just makes the customer wait. If the developer wants a separate, more permissive limiter for this endpoint specifically, flag it during code review and a `booking-payment-return` limiter lands as a follow-up.
- **`stripe_charge_id` deliberately null** in 2a — filling it requires a second Stripe API call. Session 3's refund path accepts the `payment_intent` id equivalently on `refunds.create`, so 2a doesn't need it. Captured in Decision Log so the Session 3 agent knows not to look for it.
- **Non-rolling-deploy**: this migration adds columns only — rolling-deploy-safe by construction. No cross-release-window invariants like D-125's `pending_actions` rename. Nothing to note in DEPLOYMENT.md's operator section for this one.
- **Session 1 Inertia shared prop** (`auth.business.connected_account`) is already live; Session 2a does NOT need to extend it. The public booking page reads `business.can_accept_online_payments` via `PublicBookingController::show`'s explicit payload — not the Inertia shared prop — because the public page is unauthenticated and has no `auth.business`.
