# Handoff

**State (2026-04-24):** PAYMENTS Session 5 (`payment_mode` Toggle Activation + UI Polish) shipped. **The PAYMENTS roadmap is closed** — all six sessions (1, 2a, 2b, 3, 4, 5) have landed on `main`. `docs/ROADMAP.md` is ready to be overwritten by a parked roadmap or a fresh one.

**Branch**: main.
**Feature+Unit suite**: 963 passed / 4173 assertions (baseline 954 / 4079 at Session 4 close; Session 5 added 9 tests / 94 assertions across exec + four codex review rounds).
**Full suite**: 2+ minutes because of `tests/Browser`. Developer pre-push check.
**Tooling**: Pint clean. PHPStan (level 5, `app/` only) clean. Vite build clean (main app chunk ~585 kB; pre-existing >500 kB warning unaffected). Wayfinder regenerated.
**Codex review**: to be run by the developer against the staged diff; findings (if any) stack under `## Review — Round N` inside `docs/PLAN.md` on the same uncommitted diff before the final commit.

---

## What shipped

MVP (Sessions 1–11) + MVP Completion (MVPC-1..5): data layer, scheduling engine, frontend foundation, auth, onboarding, public booking, dashboard, settings, email notifications, calendar view, Google OAuth, bidirectional Google Calendar sync, Cashier subscription billing, provider self-service settings, advanced calendar interactions. E2E-0..6 also shipped.

**PAYMENTS Session 1 (2026-04-23, commit `b520250`):** Stripe Connect Express onboarding + connected account model + settings page + Connect webhook with `account.*` + dispute stubs. Pending Actions table generalised (D-113).

**PAYMENTS Session 2a (2026-04-23, commit `36879f0` + CI fix `5bdbe4e`):** happy-path online payment — booking creation in `pending + awaiting_payment`, Stripe Checkout minting, webhook `checkout.session.completed` + `.async_payment_succeeded` promotion via the shared `CheckoutPromoter` service (D-151), success-page synchronous retrieve (locked decision #32), D-158 account pin, D-156 fail-closed on mismatch, D-157 + D-159 paid-cancel guards across all cancel paths.

**PAYMENTS Session 2b (2026-04-23, commit `8fd5e53`):** failure branching — `checkout.session.expired`, `checkout.session.async_payment_failed`, `payment_intent.succeeded` late-refund path, expiry reaper with defense-in-depth (pre-flight retrieve + grace buffer + late-webhook refund), admin booking-detail payment panel, unpaid badge + filters. `booking_refunds` table + `RefundService` skeleton (one reason — `cancelled-after-payment`) + `mockRefundCreate` / `mockRefundCreateFails`.

**PAYMENTS Session 3 (2026-04-24, commit `737f2e5`):** RefundService expanded to five reasons + admin-manual variant + 4-arg signature; cancel paths rewired to dispatch refunds inline; admin-manual refund UI; dispute webhook handling (`charge.dispute.*` create/update/close + admin emails + dispute PA); refund-settlement webhooks (`charge.refunded`, `charge.refund.updated`, `refund.updated`); admin Payment & refunds panel on booking detail; public refund status line; D-169..D-175 promoted; 938 / 3918 baseline.

**PAYMENTS Session 4 (2026-04-24, commit `c6a4e2e`):** Admin-only Payouts surface (`Dashboard\PayoutsController` + `dashboard/payouts.tsx`) with two-layer cache (60s freshness + 24h fallback), connected-account health strip, Stripe Express dashboard login-link mint, support for Stripe Tax warning (locked decision #11), non-CH banner, stale/unreachable fallbacks. `FakeStripeClient` gained 4 mocks (`mockBalanceRetrieve`, `mockPayoutsList`, `mockTaxSettingsRetrieve`, `mockLoginLinkCreate`). Navigation entry + cross-tenant neutralisation via `ResolveTenantContext`. 16 new tests. Two follow-up TS-error-cleanup commits (`eb8f28d`, `e35dca9`) made `npx tsc --noEmit` exit 0.

**PAYMENTS Session 5 (this session, 2026-04-24):**

- **Server-side gate lift**: `UpdateBookingSettingsRequest::paymentModeRolloutRule()` replaces D-132's transitional hard-block with a `canAcceptOnlinePayments()` check. Non-offline `payment_mode` values are accepted iff the helper returns true (Stripe caps + country + no `requirements_disabled_reason`). Idempotent-passthrough preserved verbatim so the form's hidden-input round-trip still works mid-edit on a persisted-but-lapsed non-offline business.
- **Settings → Booking UI unhide**: all three `payment_mode` options now visible in `resources/js/pages/dashboard/settings/booking.tsx`. Non-offline options render disabled with inline muted sub-text explaining the reason, priority-ordered per the roadmap: "Connect Stripe and finish onboarding" (no active row / caps not on) → "Online payments in MVP support CH-located businesses only" (active row, non-supported country) → reserved. The COSS UI `<Select>` primitive sets `pointer-events:none` on disabled items, so hover tooltips don't fire — the inline sub-text is the primary accessibility signal (visible on pointer, touch, keyboard, screen reader); a `title=""` hover backup exists for pointer devices. Locked decision #29 manual × online/customer_choice hint renders below the select as the admin toggles either control.
- **Eligibility Inertia prop**: `BookingSettingsController::edit()` now passes a `paymentEligibility` block (`has_verified_account`, `country_supported`, `can_accept_online_payments`, `connected_account_country`, `supported_countries`). `has_verified_account` reads the raw Stripe capability booleans (NOT `verificationStatus() === 'active'`) so a DE-active account picks the "non-CH" tooltip, not the "Connect Stripe" one.
- **Race-banner 422** (**D-176**, behaviour change): `PublicBookingController::store` now throws `ValidationException::withMessages(['online_payments_unavailable' => ...])` BEFORE the transaction when the customer intended online payment but `canAcceptOnlinePayments()` is false. Previously Session 2a silently downgraded to the offline path, contradicting the Business's "require online" commercial contract. Soft path preserved for `customer_choice`: both customer-explicit `payment_choice=offline` AND absent `payment_choice` (degraded-at-load case where the client never rendered the picker) land at offline regardless of account status — online intent requires an **explicit** `payment_choice === 'online'`. Every 4xx branch in `store()` + `mintCheckoutOrRollback` was migrated from hand-rolled `response()->json()` to `ValidationException` — that's the ONLY shape Inertia v3's `useHttp` consumes into `http.errors`. The Round-2 implementation used `{reason, message}` top-level keys that `useHttp` silently ignored, making the race banner dead in the browser (caught only by Round-3 codex review; HTTP-only tests proved the server response but not the client rendering). **Round 4**: for `payment_mode = 'online'` Businesses whose connected account is currently degraded, the public booking page now renders the "unavailable" copy at page-load (not only after submit) and disables the "Continue to payment" CTA. Prior behaviour disguised the degraded state as an offline flow with an email-confirmation caption, only to 422 on click — misleading UX on the exact race the banner is meant to cover. `customer_choice + degraded` remains a legitimate soft-fallback (picker hidden, "Confirm booking" offline flow works).
- **Structured 4xx `reason` codes**: every 4xx response in `PublicBookingController::store` / `mintCheckoutOrRollback` now carries a stable `reason` key (`slot_taken | no_provider | online_payments_unavailable | checkout_failed`) alongside the human-readable `message`. Frontend branches on the discriminator, not on substring matching — same pattern as D-161's `external_redirect` flag.
- **Public-booking copy polish**: "Continue to payment" CTA now shows "Your card will be charged CHF X.XX on the next step." + "Secured by Stripe" microcopy + optional TWINT badge. The TWINT badge reads a new server-computed `business.twint_available` boolean (D-154 `twint_countries` config read server-side; no hardcoded 'CH' on the client). Race banner copy splits into four cases via `errorCopyForReason()` helper.
- **Branching audit**: rewrote `PublicBookingController.php:383`'s `expires_at` ternary as an explicit `match(PaymentMode)` statement — locked decision #13's "only online sets expires_at; customer_choice relies on Checkout-session expiry" intent is now visible at the call site. Audited remaining `payment_mode` dereferences in `app/`; all others already use enum equality correctly. `payment_mode_at_creation` raw-string comparisons are correct by design (D-14 snapshot column).
- **Banner consolidation**: extracted `resources/js/components/dashboard/dashboard-banner.tsx` — single presentational wrapper for the dashboard banner stack. The three call sites in `authenticated-layout.tsx` (subscription lapse, payment-mode mismatch, unbookable services) now consume it. Removed the duplicated padding / wrapper markup and the `Alert*`/`AlertTriangleIcon` imports from the layout. Not a priority / stacking system — each caller still owns its copy, variant, testId, action wiring.
- **i18n audit**: walked the Session 1–4 diff for unwrapped user-facing strings. Clean (the only near-hit was a `placeholder="0.00"` on the refund dialog — numeric format, not translatable).
- **Tests**: +7 tests / +67 assertions. `tests/Feature/Settings/BookingSettingsTest.php` gained 5 cases (positive gate-lift for CH, customer_choice positive, non-CH rejected server-side, config-flip opens the seam for non-CH, 2 eligibility-prop Inertia assertions). `tests/Feature/Booking/OnlinePaymentCheckoutTest.php` gained the race test (capability flip → 422) and the customer_choice + pay-on-site fallback test; the pre-Session-5 "silent downgrade" test was rewritten to expect 422 + zero booking rows. `tests/Browser/Settings/BookingSettingsTest.php::updates payment_mode to online` seeds an active connected account before the HTTP PUT. Locked-decision-#29 variants already covered by Session 3's `PaidCancellationRefundTest.php:190,218` — no new tests needed.
- **Cleanup tasks** left for the developer (before overwriting `docs/ROADMAP.md`): the checklist at `docs/ROADMAP.md::## Cleanup tasks (after this roadmap is approved)` — SPEC §12 + §7.6 + §2 updates, BACKLOG entries for post-MVP online-payment follow-ups, etc. Not part of Session 5's code diff.

**One new architectural decision** (D-176). 98 architectural decisions (D-080..D-176) recorded across `docs/decisions/DECISIONS-*.md`. Next free decision ID: **D-177**.

---

## What is next

`docs/ROADMAP.md` is **closed**. Choose one:

- **Promote a parked roadmap**: `docs/roadmaps/ROADMAP-E2E.md` (ongoing coverage) or `docs/roadmaps/ROADMAP-GROUP-BOOKINGS.md` (post-MVP). Overwrite `docs/ROADMAP.md` with the chosen body and delete the parked file.
- **Draft a fresh roadmap**: use `.claude/references/ROADMAP.md` as the canonical reference for structure + probing checklist + quality bar.
- **Before either**: walk `docs/ROADMAP.md::## Cleanup tasks (after this roadmap is approved)` — SPEC updates, BACKLOG additions — as a housekeeping pass. These were deliberately kept out of Session 5's code diff (they're documentation hygiene, not feature work).

No Session 5 carry-overs. Session 4's Payouts surface stayed stable through Session 5 (the only Payouts-page edit considered was the i18n audit, which found nothing to fix).

---

## Workflow (minimal)

1. Developer briefs an architect agent to review / revise `docs/ROADMAP.md`.
2. Developer briefs a planning agent for a single session. The agent reads `SPEC.md` + `HANDOFF.md` + `ROADMAP.md` + the relevant code, writes `docs/PLAN.md`, stops for developer approval.
3. On approval, the same agent (or a fresh one) implements the plan, keeps `## Progress` current in `docs/PLAN.md`, runs tests, stages the work. Never commits.
4. Developer reviews the diff. May also run codex review (`/codex:review` or the companion script) against the staged state — if run inside the plan+exec chat, the agent sees findings directly in the transcript; otherwise developer pastes them back. Agent applies fixes under a `## Review` section in `docs/PLAN.md` on the same uncommitted diff. Developer commits once at the end (single commit bundles exec + review fixes).
5. Agent rewrites `HANDOFF.md` if the session changed shipped state, promotes any new `D-NNN` into the matching `docs/decisions/DECISIONS-*.md` file, stages close artifacts. Developer commits.
6. At the start of the next session, `docs/PLAN.md` gets overwritten. Git keeps the previous plan.

Two developer gates per session: plan approval, commit.

---

## Conventions that future work must not break

All MVP-era conventions remain. Highlights most relevant to post-PAYMENTS work:

- **Stripe SDK mocking via container binding (D-095)**. Every Stripe call flows through `app(StripeClient::class, ...)`. Tests mock via the `FakeStripeClient` builder in `tests/Support/Billing/`. Session 4 extended the platform-level bucket with `mockLoginLinkCreate` (header asserted ABSENT) and the connected-account-level bucket with `mockBalanceRetrieve`, `mockPayoutsList`, `mockTaxSettingsRetrieve` (header asserted PRESENT). `mockRefundCreate` is still capped at `->once()` per registration so stacked mocks consume in order (Session 3 contract).
- **Connect webhook at `/webhooks/stripe-connect` (D-109, D-110)**. Unchanged through Session 5.
- **Outcome-level idempotency (locked roadmap decision #33)**. Unchanged.
- **`CheckoutPromoter` is the single promotion service (D-151)**. Unchanged.
- **`RefundService` is the single refund executor**. Unchanged through Session 5.
- **Row-UUID idempotency key (D-162)**. Unchanged.
- **D-158 account pin**. Unchanged.
- **`payment_mode_at_creation` snapshot invariant (locked decision #14)**. Unchanged. Session 5 reinforces this via the new race-banner test for customer_choice + pay-on-site.
- **`Booking::pendingActions(): HasMany` is unfiltered**. Unchanged through Session 5.
- **Payment PAs are admin-only** (locked decisions #19 / #31 / #35 / #36). Unchanged.
- **Cancel paths dispatch `RefundService::refund` automatically**. Unchanged.
- **`BookingReceivedNotification` has four contexts**. **`BookingCancelledNotification` has the `$refundIssued` flag (D-175).** Unchanged.
- **Direct charges on connected accounts for PAYMENTS** (locked roadmap decision #5). Unchanged.
- **GIST overlap constraint on bookings** (D-065 / D-066). Unchanged.
- **Tenant context via `App\Support\TenantContext` (D-063)** — every admin-only dashboard endpoint enforces `abort_if(...)` or reads via `tenant()->business()->relation`. Session 5 did not add new authenticated controllers; all Session-5 edits live inside existing tenant-scoped paths.
- **Two-layer cache pattern for read-only Stripe surfaces** (PAYMENTS Session 4): `fetched_at` freshness + long TTL fallback. Still inlined in `PayoutsController`. Session 5 did not add a second consumer; lifting to a shared helper remains deferred.
- **No hardcoded `'CH'` literal anywhere** (locked decision #43, D-112). React reads the supported set from Inertia props; PHP reads it from `config('payments.supported_countries')`; tests assert a config flip changes behaviour. **Session 5 reinforced this**: the Settings → Booking gate uses `canAcceptOnlinePayments()` (which folds country via config), the TWINT badge reads a server-computed `business.twint_available` boolean backed by `config('payments.twint_countries')`, and the new race-banner tests include a config-flip case.
- **Inertia-native error envelope**: every 4xx from controllers that back Inertia forms / `useHttp` requests must come from `throw ValidationException::withMessages([$discriminator => __($localizedMessage)])`. Hand-rolled `response()->json([...], 422)` is NOT consumed by `useHttp` and leaves the form UI silently broken. The Session 5 discriminator vocabulary for the public booking flow is `slot_taken | no_provider | online_payments_unavailable | checkout_failed | provider_id | booking` — carried as the error KEY. Future booking-flow error paths should extend this vocabulary via additional keys, not invent a parallel response shape.
- **`payment_mode = online` + ineligible connected account → 422, not silent offline** (**D-176**). `PublicBookingController::store` throws `ValidationException::withMessages(['online_payments_unavailable' => ...])` before the DB transaction. Soft paths preserved for `customer_choice`: explicit `payment_choice=offline` AND absent `payment_choice` (degraded-at-load) both land offline. Online intent requires explicit `payment_choice === 'online'` — no null-default escalation. Future PAYMENTS work must preserve this distinction.

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

See `docs/BACKLOG.md`. Most relevant post-Session-5 carry-overs (inherit from the roadmap's cleanup list):

- **Pre-existing TS error in `resources/js/components/calendar/booking-hover-card.tsx:52`** — Base UI Tooltip Root's `delay` / `closeDelay` pattern. Session 4 fixed the twin in `payouts.tsx`. `npm run build` does not strict-type-check the React side, so not CI-blocking but IDE-flagged. One-line fix; out of PAYMENTS scope.
- **`stripe_charge_id` backfill on promotion** (D-173). Session 3 deferred, Session 4 + 5 confirmed the deferral (the Payouts page surfaces payouts, not charges, and no Session 5 UI needed per-charge reconciliation). Future session can backfill when a per-charge reconciliation use case lands.
- **Resend payment link for `unpaid` customer_choice bookings** — still deferred (post-MVP).
- **"Accept offline bookings when Stripe is temporarily unavailable" opt-out for `payment_mode = 'online'`** — new BACKLOG entry (PAYMENTS Session 5, 2026-04-24). The Session-5 baseline hard-blocks public bookings when a `payment_mode = 'online'` business's Stripe account is degraded. Some businesses would prefer to still accept bookings with on-site payment during a Stripe outage (they set `online` for bookkeeping, not commercial rigour). The proposed surface is a Settings → Booking checkbox that defaults to OFF (keeping the current hard-block) and when ON makes the controller silent-downgrade to offline instead of returning 422. Full context + implementation sketch in `docs/BACKLOG.md`. To evaluate for the next session or later — not a blocker for MVP.
- **Post-hoc online payment link for manual bookings** — BACKLOG per roadmap cleanup list.
- **Online payments for non-CH connected accounts** — BACKLOG per roadmap cleanup list; the seams are proven open by Session 5's config-flip test.
- **Payment conversion analytics** — BACKLOG per roadmap cleanup list.
- **Consolidated Connect UX for multi-business admins** — BACKLOG per roadmap cleanup list.
- **In-dashboard dispute evidence flow** — BACKLOG per roadmap cleanup list.
- **Formal support-contact surface (PAYMENTS Session 1, D-118)**. Connected Account disabled-state CTA + Session 4's payouts disabled-state CTA both mailto a placeholder; pre-launch needs a real flow.
- **Per-Business country selector for Stripe Express onboarding (D-121 superseded by D-141)**.
- **Collect country during business onboarding** (D-141 / D-143 follow-up).
- **Pre-role-middleware signed-URL session pinner (D-147 false-negative)**.
- **Tighten billing freeload envelope** (MVPC-3 D-089).
- **WeekScheduleEditor rename** from `components/onboarding/` to `components/settings/` (MVPC-4 cleanup).
- **R-16 frontend code splitting pass** on the whole bundle (the >500 kB Vite warning is pre-existing and unrelated).
- **Calendar carry-overs** (R-17, R-19, R-9 items).
- **Prune old decisions**. `DECISIONS-HISTORY.md` plus anything superseded by D-080+.
- **Refund reason vocabulary promotion to enum** — deferred until a sixth reason lands (D-174).
- **Lift the two-layer cache pattern** from `PayoutsController` into a shared helper once a second consumer exists (Session 4 backlog; unchanged).
- **SPEC + BACKLOG updates** listed under `docs/ROADMAP.md::## Cleanup tasks (after this roadmap is approved)` — developer work before overwriting the roadmap.
