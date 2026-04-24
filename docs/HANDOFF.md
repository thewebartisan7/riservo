# Handoff

**State (2026-04-24):** PAYMENTS Session 3 (Refunds + Disputes) shipped. The active `docs/ROADMAP.md` (PAYMENTS) has two sessions left: 4, 5.

**Branch**: main.
**Feature+Unit suite**: 938 passed / 3918 assertions (baseline 876 / 3559 at Session 2b close; Session 3 added 62 tests / 359 assertions across exec + two codex review rounds + one self-review).
**Full suite**: 2+ minutes because of `tests/Browser`. Developer pre-push check.
**Tooling**: Pint clean. PHPStan (level 5, `app/` only) clean. Vite build clean (main app chunk ~570 kB; pre-existing >500 kB warning unaffected). Wayfinder regenerated.
**Codex review**: two rounds completed before commit — 7 legitimate findings total (0 false positives), all applied on the same uncommitted diff. A planned Round 3 was replaced with a targeted self-review when Codex quota was exhausted; three highest-risk surfaces walked through, one test-coverage gap closed (cross-tenant dispute-dismiss).

---

## What shipped

MVP (Sessions 1–11) + MVP Completion (MVPC-1..5): data layer, scheduling engine, frontend foundation, auth, onboarding, public booking, dashboard, settings, email notifications, calendar view, Google OAuth, bidirectional Google Calendar sync, Cashier subscription billing, provider self-service settings, advanced calendar interactions. E2E-0..6 also shipped.

**PAYMENTS Session 1 (2026-04-23, commit `b520250`):** Stripe Connect Express onboarding + connected account model + settings page + Connect webhook with `account.*` + dispute stubs. Pending Actions table generalised (D-113).

**PAYMENTS Session 2a (2026-04-23, commit `36879f0` + CI fix `5bdbe4e`):** happy-path online payment — booking creation in `pending + awaiting_payment`, Stripe Checkout minting, webhook `checkout.session.completed` + `.async_payment_succeeded` promotion via the shared `CheckoutPromoter` service (D-151), success-page synchronous retrieve (locked decision #32), D-158 account pin, D-156 fail-closed on mismatch, D-157 + D-159 paid-cancel guards across all cancel paths.

**PAYMENTS Session 2b (2026-04-23, commit `8fd5e53`):** failure branching — `checkout.session.expired`, `checkout.session.async_payment_failed`, `payment_intent.succeeded` late-refund path, expiry reaper with defense-in-depth (pre-flight retrieve + grace buffer + late-webhook refund), admin booking-detail payment panel, unpaid badge + filters. `booking_refunds` table + `RefundService` skeleton (one reason — `cancelled-after-payment`) + `mockRefundCreate` / `mockRefundCreateFails`.

**PAYMENTS Session 3 (this session, 2026-04-24):**

- **RefundService expansion**: new signature `refund(Booking, ?int $amountCents, string $reason, ?int $initiatedByUserId): RefundResult` — fourth arg is the admin user id for `admin-manual` (null for every system-dispatched path). Reason vocabulary grows to five values: `cancelled-after-payment` (2b), `customer-requested`, `business-cancelled`, `admin-manual`, `business-rejected-pending`. Partial-refund overflow now throws `ValidationException` per D-169 instead of silently clamping. Three new public methods for webhook settlement — `recordStripeState(BookingRefund, string, ?string)`, `recordSettlementSuccess(BookingRefund, string)`, `recordSettlementFailure(BookingRefund, ?string)` — idempotent at the outcome level (D-33 + D-167 + D-171 + D-172).
- **Cancel paths rewired**: D-157 + D-159 paid-cancel guards in `BookingManagementController::cancel`, `Customer\BookingController::cancel`, and `Dashboard\BookingController::updateStatus` replaced with `RefundService::refund` dispatches per locked decisions #15 / #16 / #17. Each site wraps the dispatch in a try/catch for `ApiConnectionException | RateLimitException | ApiErrorException` and bubbles transient errors as 503 / "try again" flashes. `Dashboard\BookingController::updateStatus` branches the reason on booking status (Pending → `business-rejected-pending`; Confirmed → `business-cancelled`); Pending+Unpaid and Pending+AwaitingPayment rejections cancel WITHOUT a refund dispatch per locked decision #29 variant.
- **Admin-manual refund UI**: new `Dashboard\BookingRefundController::store` (admin-only + tenant-scoped per locked decision #45) fed by `StoreBookingRefundRequest` (`kind=full|partial`, `amount_cents`, `reason`). `RefundDialog` component on `BookingDetailSheet` exposes a full/partial radio + amount input (client-clamped at `remaining_refundable_cents`) + free-text reason. 422 on overflow surfaces inline via `useForm` error rendering.
- **BookingCancelledNotification**: new constructor arg `bool $refundIssued = false` (D-175). Blade template adds a `@if ($cancelledBy === 'business' && $refundIssued)` paragraph rendering "A full refund has been issued to your original payment method. You should see it within 5–10 business days." The three cancel callers pass `$refundIssued = ($result->outcome === 'succeeded')`.
- **Dispute webhook extension**: `handleDisputeEvent` now dispatches `DisputeOpenedNotification` to admins on `charge.dispute.created`, refreshes PA payload on `charge.dispute.updated` (no email), dispatches `DisputeClosedNotification` with the outcome on `charge.dispute.closed`. Dispute PA now links to the booking via `stripe_payment_intent_id` lookup. `PaymentPendingActionController::resolve` accepts `PaymentDisputeOpened` per D-170; admin-manual dismiss writes `resolution_note = 'dismissed-by-admin'`.
- **Refund-settlement webhook arms**: `charge.refunded` / `charge.refund.updated` / `refund.updated` route through `handleRefundEvent`. Rows match by `stripe_refund_id` (D-171); D-158 pin enforces the cross-account guard via the booking. `RefundService::recordStripeState` maps Stripe vocabulary onto our three-value enum per D-172 (succeeded / failed / canceled → Failed / requires_action + pending → no-op).
- **Admin Payment & refunds panel**: `Dashboard\BookingController::index` admin payload gains `payment.remaining_refundable_cents` + a `refunds: []` list (newest-first, each with `initiator_name` resolved from `booking_refunds.initiated_by_user_id`). `pending_payment_action` eager-load now includes `PaymentDisputeOpened`; sort order = refund-failed → cancelled-after-payment → dispute-opened. `BookingDetailSheet` renders the refund list + per-row Stripe deep-link; a Dispute section appears when the PA type is `payment.dispute_opened` (banner + Stripe evidence-UI deep-link + Dismiss button).
- **Public refund status line**: new `Booking::refundStatusLine()` computes customer-facing copy across five branches (refunded / partial / pending / failed-generic / failed-disconnected). `BookingManagementController::show` + `Customer\BookingController::index` pass the line as a prop; `bookings/show.tsx` + `customer/bookings.tsx` render it below the payment panel.
- **`FakeStripeClient::mockRefundCreate`** was capped at `->once()` per registration so stacked test mocks (two consecutive refunds) consume in order rather than collapse to the first expectation. New `tests/Support/Billing/StripeEventBuilder.php` helper builds canonical `charge.dispute.*` + `charge.refunded` / `refund.updated` payloads consumed by the new feature tests.
- **Tests**: 62 new cases across 7 files (51 initial + 11 from two codex review rounds + one self-review addition). File breakdown: `tests/Unit/Services/Payments/RefundServicePartialTest.php` (11), `tests/Feature/Booking/PaidCancellationRefundTest.php` (11; `git mv` from `PaidCancellationGuardTest.php` + rewrite + 1 round-1 P1 regression), `tests/Feature/Dashboard/AdminManualRefundTest.php` (11; +2 round-1 P2 + +2 round-2 P1), `tests/Feature/Payments/DisputeWebhookTest.php` (8; +1 round-1 P3 + +1 self-review cross-tenant dismiss), `tests/Feature/Payments/RefundSettlementWebhookTest.php` (10; +2 round-2 P2), `tests/Feature/Dashboard/BookingRefundsPanelTest.php` (6; +1 round-1 P2), `tests/Feature/Booking/BookingShowRefundLineTest.php` (6), `tests/Feature/Customer/BookingsListTest.php` (+1 round-2 P3 N+1 regression). The D-157 guard test in `tests/Feature/Booking/BookingManagementTest.php` was rewritten in-place.

**Codex review rounds** (both pre-commit, on the same uncommitted diff):
- **Round 1** — 4 findings (all applied): P1 staff-can-trigger-refund auth regression via `updateStatus` → added `$isAdmin` gate before refund dispatch; P2 dispute PA hidden by higher-priority refund PA → split payload into independent `pending_payment_action` + `dispute_payment_action` keys; P2 admin's free-form "reason" note silently dropped → new migration `add_admin_note_to_booking_refunds_table` + `RefundService::refund` 5th arg `?string $adminNote` threaded through; P3 dispute-closed email blade template rendered literal `%s` → replaced with `:status` interpolation.
- **Round 2** — 3 findings (all applied): P1 admin-manual retry with different amount re-dispatched the old amount silently → `RefundService` throws 422 when `reason=admin-manual && amount != existing.amount_cents`; P2 response-loss webhook events couldn't reconcile rows with null `stripe_refund_id` → new `resolveRefundRowByFallback()` matches by `(payment_intent, amount_cents, status=pending)` and backfills the id; P3 `/my-bookings` N+1 on `refundStatusLine()` + disconnect check → eager-load `bookingRefunds`, batch-compute disconnected account set, `refundStatusLine()` accepts optional `?bool $pinnedAccountDisconnected`.
- **Self-review (post-Round-2)** — 3 high-risk surfaces reviewed after Codex quota exhausted: concurrency of the new fallback matcher (safe under all reachable states); `ValidationException` propagation through `DB::transaction` to Inertia dialog (verified by framework contract + existing test); dispute PA linking via `payment_intent` (graceful, tenant-scoped; added one cross-tenant dismiss regression test to close a coverage gap).

**New architectural decisions** (D-169..D-175 — 7 total, promoted this session): D-169 (422 on overflow), D-170 (admin-manual dispute PA dismiss), D-171 (refund-settlement row match via `stripe_refund_id`), D-172 (Stripe refund status mapping), D-173 (`stripe_charge_id` backfill deferred), D-174 (reason vocabulary is strings, not enum), D-175 (`refundIssued` flag on `BookingCancelledNotification`).

97 architectural decisions (D-080..D-175) recorded across `docs/decisions/DECISIONS-*.md`. Next free decision ID: **D-176**.

---

## What is next

`docs/ROADMAP.md` — **PAYMENTS Session 4, Payout Surface + Connected Account Health**. Read the roadmap section under `## Session 4 — Payout Surface + Connected Account Health` for the brief.

Prerequisites met: Session 3's refund executor, the D-158 pin, the admin-only payment panel, and the Stripe Connect webhook plumbing all stay stable through Session 4. Session 4 is read-only payout surfacing — no new writable endpoints to the connected account, no payout-initiation flow. Stripe is the source of truth; riservo surfaces balance, next payout ETA, recent payouts, and a Stripe Express login-link.

Sessions 4 → 5 close the PAYMENTS roadmap:

- Session 4: Payouts page + connected-account health strip + Stripe Express dashboard deep-link.
- Session 5: lift the hide-the-options ban on Settings → Booking; `online` and `customer_choice` become user-facing; copy polish pass across every surface.

Parked in `docs/roadmaps/`: `ROADMAP-E2E.md` (ongoing coverage) and `ROADMAP-GROUP-BOOKINGS.md` (post-MVP, not scheduled).

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

All MVP-era conventions remain. Highlights most relevant to Session 4 onward:

- **Stripe SDK mocking via container binding (D-095)**. Every Stripe call flows through `app(StripeClient::class, ...)`. Tests mock via the `FakeStripeClient` builder in `tests/Support/Billing/`. Session 3 capped `mockRefundCreate` at `->once()` per registration so stacked mocks consume in order — tests that accidentally call Stripe twice with a single mock registration will now fail loudly. Session 4 extends the connected-account-level bucket with `mockBalanceRetrieve`, `mockPayoutsList`, `mockLoginLinkCreate` (platform-level for the last one), `mockTaxSettingsRetrieve`.
- **Connect webhook at `/webhooks/stripe-connect` (D-109, D-110)**. NOT a Cashier subclass. Cache prefix `stripe:connect:event:`. Session 3 added `charge.dispute.*` full handling + `charge.refunded`, `charge.refund.updated`, `refund.updated`. Session 4 doesn't add new events — the payouts page is a live-read via `stripe.payouts.list` + `stripe.balance.retrieve` per admin request (short TTL cache at plan time).
- **Outcome-level idempotency (locked roadmap decision #33)**. Every webhook handler re-reads DB state inside its lock; replays and cross-path races no-op. Session 3's `RefundService::recordStripeState` + the dispute PA upsert-then-catch-unique-violation follow the same pattern.
- **`CheckoutPromoter` is the single promotion service (D-151)**. Unchanged through Session 3.
- **`RefundService` is the single refund executor (Session 2b + Session 3)**. `refund(Booking, ?int, string, ?int): RefundResult` is the contract. Row-UUID idempotency key per D-162. Disconnected-account fallback per locked decision #36. Session 4 and 5 do not modify this service.
- **Row-UUID idempotency key (D-162)**. Unchanged.
- **D-158 account pin**. Unchanged. Session 4's payouts page uses the business's CURRENT active `stripeConnectedAccount` relation, NOT the D-158 pin on any specific booking, because the payouts surface is business-scoped not booking-scoped.
- **`payment_mode_at_creation` snapshot invariant (locked decision #14)**. Unchanged.
- **`Booking::pendingActions(): HasMany` is unfiltered** — the dashboard controller type-buckets payment rows explicitly. Session 3 extended the admin-only filter to include `PaymentDisputeOpened`; sort order `refund_failed → cancelled_after_payment → dispute_opened` (urgency-first).
- **Payment PAs are admin-only** (locked decisions #19 / #31 / #35 / #36) — `Dashboard\PaymentPendingActionController` enforces the admin gate. Session 3 extended it to accept `PaymentDisputeOpened` dismissal per D-170.
- **Cancel paths dispatch `RefundService::refund` automatically** — the three cancel endpoints (`BookingManagementController::cancel`, `Customer\BookingController::cancel`, `Dashboard\BookingController::updateStatus`) own the refund dispatch inline. The D-157 / D-159 guards are GONE; re-introducing them is a regression. Session 4 and 5 don't touch these endpoints.
- **`BookingReceivedNotification` has four contexts** (`new`, `confirmed`, `paid_awaiting_confirmation`, `pending_unpaid_awaiting_confirmation`). `BookingCancelledNotification` has the `$refundIssued` flag per D-175. Session 4 doesn't touch notifications; Session 5's UI-polish pass may edit copy.
- **Direct charges on connected accounts for PAYMENTS** (locked roadmap decision #5). Unchanged.
- **GIST overlap constraint on bookings** (D-065 / D-066). Unchanged.
- **Tenant context via `App\Support\TenantContext` (D-063)** — every admin-only dashboard endpoint enforces `abort_unless($resource->business_id === tenant()->businessId(), 404)`. Session 4's new `Dashboard\PayoutsController` follows the same pattern.

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

See `docs/BACKLOG.md`. Most relevant post-Session-3 carry-overs:

- **`stripe_charge_id` backfill on promotion** (D-173). Session 3 deferred — the Stripe deep-link falls back to `payment_intent` cleanly and refund-settlement webhooks match via `stripe_refund_id`. A future session can backfill once there's a payout-reconciliation use case.
- **Resend payment link for `unpaid` customer_choice bookings** — still deferred (post-MVP).
- **Formal support-contact surface (PAYMENTS Session 1, D-118)**. Connected Account disabled-state CTA mailtos a placeholder; pre-launch needs a real flow.
- **Per-Business country selector for Stripe Express onboarding (D-121 superseded by D-141)**.
- **Collect country during business onboarding** (D-141 / D-143 follow-up).
- **Pre-role-middleware signed-URL session pinner (D-147 false-negative)**.
- **Tighten billing freeload envelope** (MVPC-3 D-089).
- **WeekScheduleEditor rename** from `components/onboarding/` to `components/settings/` (MVPC-4 cleanup).
- **R-16 frontend code splitting pass** on the whole bundle (the >500 kB Vite warning is pre-existing and unrelated).
- **Calendar carry-overs** (R-17, R-19, R-9 items).
- **Prune old decisions**. `DECISIONS-HISTORY.md` plus anything superseded by D-080+.
- **Refund reason vocabulary promotion to enum** — only if Session 4 or 5 adds a sixth reason (D-174 keeps it as strings for now).
