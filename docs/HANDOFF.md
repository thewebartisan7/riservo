# Handoff

**State (2026-04-24):** PAYMENTS Session 2b (Payment at Booking — Failure Branching + Admin Surface) shipped. The active `docs/ROADMAP.md` (PAYMENTS) has three sessions left: 3, 4, 5.

**Branch**: main.
**Feature+Unit suite**: 876 passed / 3559 assertions (baseline 829 / 3277 at Session 2a close; Session 2b added 47 tests / 282 assertions across initial exec + four Codex review rounds).
**Full suite**: 2+ minutes because of `tests/Browser`. Developer pre-push check.
**Tooling**: Pint clean. PHPStan (level 5, `app/` only) clean. Vite build clean (main app chunk ~552 kB; pre-existing >500 kB warning unaffected). Wayfinder regenerated.
**Codex review**: four rounds landed before commit — 15 findings total, all applied with regression tests. Session 2a carry-over bugs that surfaced under review (Stripe-SDK `$params` vs `$opts` on the success-page retrieve) were fixed in the same bundle since they share the mechanical shape of the Session 2b bugs.

---

## What shipped

MVP (Sessions 1–11) + MVP Completion (MVPC-1..5): data layer, scheduling engine, frontend foundation, auth, onboarding, public booking, dashboard, settings, email notifications, calendar view, Google OAuth, bidirectional Google Calendar sync, Cashier subscription billing, provider self-service settings, advanced calendar interactions. E2E-0..6 also shipped.

**PAYMENTS Session 1 (2026-04-23, commit `b520250`):** Stripe Connect Express onboarding + connected account model + settings page + Connect webhook with `account.*` + dispute stubs. Pending Actions table generalised (D-113). Full inventory lives in prior HANDOFF revisions (`git log --follow docs/HANDOFF.md`).

**PAYMENTS Session 2a (2026-04-23, commit `36879f0` + CI fix `5bdbe4e`):** happy-path online payment — booking creation in `pending + awaiting_payment`, Stripe Checkout minting, webhook `checkout.session.completed` + `.async_payment_succeeded` promotion via the shared `CheckoutPromoter` service (D-151), success-page synchronous retrieve (locked decision #32), D-158 account pin, D-156 fail-closed on mismatch, D-157 + D-159 paid-cancel guards across all cancel paths.

**PAYMENTS Session 2b (this session, 2026-04-23):**

- **Data layer**: new `booking_refunds` table (one row per refund ATTEMPT per locked decision #36) with composite index `(booking_id, status)`. New enum `App\Enums\BookingRefundStatus` (Pending / Succeeded / Failed — D-167). New `App\Models\BookingRefund` + factory (`pending()` / `succeeded()` / `failed()` states). `Booking::bookingRefunds(): HasMany<BookingRefund>` relation + `Booking::pendingActions(): HasMany<PendingAction>` relation. `Booking::remainingRefundableCents()` extended from Session 2a's stub to the real clamp (`max(0, paid - sum(pending+succeeded refunds))`).
- **Refund service**: `App\Services\Payments\RefundService` with `refund(Booking, ?int, string = 'cancelled-after-payment'): RefundResult`. UUID-seeded Stripe idempotency key (`riservo_refund_{uuid}` — D-162). Row inserted BEFORE the Stripe call inside `DB::transaction` + `lockForUpdate`. `PermissionException` / `AuthenticationException` → `disconnected` outcome; other `ApiErrorException` → `failed`. Both sad paths flip booking `payment_status` to `refund_failed`, upsert a `payment.refund_failed` Pending Action, dispatch `RefundFailedNotification` to admins only. Happy path runs `reconcilePaymentStatus` (full = `Refunded`). Returns a readonly `RefundResult` DTO (D-165).
- **Failure webhook arms** on `StripeConnectWebhookController::dispatch`:
  - `checkout.session.expired` + `checkout.session.async_payment_failed` → `handleCheckoutSessionFailed`.
  - `payment_intent.payment_failed` → `handlePaymentIntentFailed` (resolves via `stripe_payment_intent_id`).
  - `payment_intent.succeeded` → `handlePaymentIntentSucceeded` (late-webhook refund path per locked decision #31.3).
  - `handleCheckoutSessionCompleted` now routes Cancelled bookings to the late-refund branch BEFORE calling the promoter (which would otherwise reject via its DB-state guard and surface as `'mismatch'`).
- **Branching per locked decision #14** (shared `applyCheckoutFailureBranch`): `online` → Cancelled + NotApplicable (no notifications); `customer_choice` → Confirmed or Pending (under manual-confirm per locked decision #29) + Unpaid, standard customer + staff notifications. The customer-facing branch for manual-confirm + customer_choice + failed Checkout uses a new `BookingReceivedNotification` context `'pending_unpaid_awaiting_confirmation'` (D-168) — distinct from `'paid_awaiting_confirmation'` because there's nothing to refund.
- **Late-webhook refund** (`applyLateWebhookRefund`): marks booking Paid with charge columns (booking stays Cancelled — slot may be re-booked), dispatches `RefundService::refund`, upserts a `payment.cancelled_after_payment` Pending Action, sends `CancelledAfterPaymentNotification` to admins. Reads the D-158 pinned account id directly (D-163).
- **Expiry reaper** `bookings:expire-unpaid`: scheduled every 2 min with `withoutOverlapping()`. Base filter enforces `payment_mode_at_creation = 'online'` (locked decision #13 policy gate — `customer_choice` bookings are never reaped; the webhook arm keeps their slot held). 5-min grace buffer. Pre-flight `checkout.sessions.retrieve` on the pinned account — if Stripe reports `status = complete` OR `payment_status = paid`, runs `CheckoutPromoter::promote` inline and SKIPS the cancel regardless of promoter outcome (including `'mismatch'` — D-166 conservative choice). Stripe 4xx → cancels; 5xx / connection error → leaves for next tick. Cancel runs inside `DB::transaction + lockForUpdate` with re-checked outcome guards.
- **Admin dashboard payment surface**: `Dashboard\BookingController::index` enriches each list row with a `payment` sub-object (status / paid_amount_cents / currency / paid_at / Stripe ids) and a `pending_payment_action` sub-object (null when absent, eager-loaded via a type-bucket filter). New `?payment_status=` query filter; `offline` ↔ `not_applicable` mapping. New `Dashboard\PaymentPendingActionController::resolve` (admin-only + tenant-scoped, route `PATCH /dashboard/payment-pending-actions/{action}/resolve`). Frontend: new `PaymentStatusBadge` component; Payment filter Select + Payment column on bookings list (admin-only); Payment panel + Pending-Action banner on `BookingDetailSheet` with status-specific copy + Stripe dashboard deep-link + Mark-as-resolved CTA.
- **Cancel-URL copy branching**: `BookingPaymentReturnController::cancel` mutates no state; branches flash copy on the `payment_mode_at_creation` snapshot (D-164). Redirects to `bookings.show`. Handles disconnected-account race.
- **FakeStripeClient extensions**: `mockRefundCreate` asserts the `stripe_account` header is PRESENT + matches + the `idempotency_key` starts with `riservo_refund_`; optional exact-match parameter ties a call to a specific row UUID. `mockRefundCreateFails` throws `Stripe\Exception\PermissionException` for the disconnected-account branch.
- **Tests**: 47 new cases across 7 files (initial 36 + 11 regression tests from four Codex review rounds). File breakdown: `tests/Feature/Payments/CheckoutFailureWebhookTest.php` (12), `tests/Feature/Payments/LateWebhookRefundTest.php` (9), `tests/Feature/Console/ExpireUnpaidBookingsTest.php` (7), `tests/Feature/Booking/CancelUrlLandingTest.php` (4), `tests/Feature/Booking/CheckoutSuccessReturnTest.php` extended (+1 Cancelled-guard regression), `tests/Feature/Dashboard/BookingPaymentPanelTest.php` (6), `tests/Feature/Dashboard/BookingsListPaymentFilterTest.php` (4), `tests/Unit/Services/Payments/RefundServiceTest.php` (4).

**Codex review rounds** (landed before commit, all on the same uncommitted diff):
- **Round 1** — 3 findings (all applied): dropped `payment_intent.*` webhook arms (F1: can't resolve bookings pre-promotion), gated dashboard `payment` + `pending_payment_action` on `$isAdmin` to plug staff leak (F2), ordered the `pendingActions` eager-load so `payment.refund_failed` surfaces before `payment.cancelled_after_payment` (F3).
- **Round 2** — 4 findings (all applied): reaper now catches `RateLimitException` before `InvalidRequestException` (F1 — 429 is retryable), `RefundService` distinguishes transient from terminal Stripe errors and bubbles 503 for retry with pending-row-UUID reuse (F2), `payment_status` filter gated on `$isAdmin` (F3), cancel-URL flashes pending-request copy under manual-confirm (F4).
- **Round 3** — 4 findings (all applied): Stripe SDK `retrieve($id, $params, $opts)` header fix on the reaper AND on the Session 2a success-page retrieve (F1 — same-shape pre-existing bug caught in audit), `success()` short-circuits on `Cancelled` to stop re-activation after late-refund (F2), refund row creation now fully serialized inside `DB::transaction + lockForUpdate` with the clamp-check inside the lock (F3), customer_choice Confirmed failures dispatch `PushBookingToCalendarJob` (F4).
- **Round 4** — 4 findings (all applied): late-refund retry path widened to cover pre-Stripe crash window (F1 — Paid + no row must still retry), session-id trust boundary added to the Cancelled-branch late-refund and the failure-webhook handler (F2 + F3 — D-156 equivalent), `payment_status === Unpaid` added to the outcome-level guard so `customer_choice` failure replays after cache-TTL elapse don't re-dispatch notifications (F4).
- **Round 5** — Codex usage quota exhausted; could not run. The prior four rounds each produced only legitimate P1/P2 findings (no false positives); stopping after Round 4 is an intentional call, not a conclusion that no issues remain. The signal at Round 4 was still strong.

**New architectural decisions** (D-162..D-168 — 7 total, promoted this session): D-162 (row-UUID idempotency key), D-163 (late-webhook refund reads D-158 pin), D-164 (cancel-URL branches on snapshot), D-165 (`RefundResult` DTO), D-166 (reaper skips cancel on mismatch), D-167 (native enum for BookingRefundStatus — gate-1 revision), D-168 (new notification context).

90 architectural decisions (D-080–D-168) recorded across `docs/decisions/DECISIONS-*.md`. Next free decision ID: **D-169**.

---

## What is next

`docs/ROADMAP.md` — **PAYMENTS Session 3, Refunds (Customer Cancel, Admin Manual, Business Cancel) + Disputes**. The roadmap session under `## Session 3 — Refunds …` is the brief.

Prerequisites met: Session 2b ships the `booking_refunds` table, the `RefundService` skeleton (with one reason live), the `payment.refund_failed` + `payment.cancelled_after_payment` Pending Action writers, the `RefundFailedNotification` + `CancelledAfterPaymentNotification` shells, the `mockRefundCreate` / `mockRefundCreateFails` test helpers, and the D-157 + D-159 paid-cancel guards that Session 3 will RELAX (not re-implement) in a two-line diff per D-157's "Consequences" clause.

Session 3 layers on:

- Extend `RefundService` with the other four reasons (`customer-requested`, `business-cancelled`, `admin-manual`, `business-rejected-pending`) and partial-refund support (clamp against `remainingRefundableCents()` per locked decision #37).
- Customer-side in-window cancel → automatic full refund (replaces the D-157 `BookingManagementController::cancel` block + D-159 `Customer\BookingController::cancel` block).
- Admin-side cancel → automatic full refund (replaces the D-159 `Dashboard\BookingController::updateStatus` block).
- Admin manual-refund dialog on the booking-detail sheet (full vs partial radio).
- Manual-confirmation rejection → automatic full refund on `pending + paid`; refund-free cancel on `pending + unpaid` (Session 2b already lands the `pending + unpaid` state — Session 3 wires the template branch).
- Dispute webhook handlers (`charge.dispute.created/updated/closed`) with admin PA + email (locked decision #35).
- Refund-settlement webhook handlers (`charge.refunded`, `charge.refund.updated`, `refund.updated`) updating `booking_refunds.status` + booking `payment_status`.

Sessions 4 → 5 follow per the roadmap.

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

All MVP-era conventions remain. Highlights most relevant to Session 3 onward:

- **Stripe SDK mocking via container binding (D-095)**. Every Stripe call flows through `app(StripeClient::class, ...)`. Tests mock via the `FakeStripeClient` builder in `tests/Support/Billing/`. Session 2b extended the connected-account-level bucket with `mockRefundCreate` + `mockRefundCreateFails`. Session 3 extends for `mockDisputeEvent` + scenario variants on `mockRefundCreate`.
- **Connect webhook at `/webhooks/stripe-connect` (D-109, D-110)**. NOT a Cashier subclass. Cache prefix `stripe:connect:event:`. Session 2b added `checkout.session.expired`, `checkout.session.async_payment_failed`, `payment_intent.payment_failed`, `payment_intent.succeeded`. Session 3 adds `charge.refunded`, `charge.refund.updated`, `refund.updated`, `charge.dispute.*`.
- **Outcome-level idempotency (locked roadmap decision #33)**. Every webhook handler re-reads DB state inside its lock; replays and cross-path races no-op. `CheckoutPromoter::promote` (D-151) for checkout-session events; `RefundService::refund` for refund events; `handleDisputeEvent` for disputes (D-123 / D-126 Round 3 race-safe pattern).
- **`CheckoutPromoter` is the single promotion service (D-151)**. Session 2b's reaper pre-flight + the Cancelled-bypass branch in `handleCheckoutSessionCompleted` are the callers on the webhook side. Session 3 does NOT duplicate promotion logic.
- **`RefundService` is the single refund executor (Session 2b)**. Session 3 extends the method with more reasons; the signature `refund(Booking, ?int, string)` and the `booking_refunds` schema stay. Disconnected-account fallback (locked decision #36) is already wired.
- **Row-UUID idempotency key (D-162)**. `booking_refunds.uuid` seeds the Stripe `idempotency_key` as `'riservo_refund_'.{uuid}`. Retries of the same attempt reuse the row; legitimately-distinct attempts get distinct rows. Do not switch to synthetic hashes (`(booking_id, amount, initiator)`) — they would collapse a legitimate second partial refund into the first.
- **D-158 account pin** — `booking.stripe_connected_account_id` is the authoritative account id for every webhook / success-page / reaper / refund path. Never look up via `withTrashed()->where('business_id')->value('stripe_account_id')` — that path is non-deterministic across reconnect history.
- **`payment_mode_at_creation` snapshot invariant (locked decision #14)**. Immutable after booking creation. The reaper's `WHERE payment_mode_at_creation = 'online'` filter is the policy gate that keeps it from ever touching customer_choice bookings (locked decision #13). Session 3's refund triggers branch on the snapshot wherever the customer's intent at creation matters.
- **`Booking::pendingActions(): HasMany` is unfiltered** — the dashboard controller type-buckets payment rows explicitly. Calendar surfaces use `PendingActionType::calendarValues()` (D-113). Payment PAs are admin-only (locked decisions #19 / #31 / #35 / #36) — `Dashboard\PaymentPendingActionController` enforces the admin gate.
- **Paid-cancel guards on `BookingManagementController::cancel`, `Customer\BookingController::cancel`, `Dashboard\BookingController::updateStatus`** — Session 2b KEEPS these blocks. Session 3 REPLACES them with `RefundService::refund` dispatches per locked decisions #15 / #16 / #17. Do not re-introduce the guards in Session 3.
- **`BookingReceivedNotification` has four contexts** (`new`, `confirmed`, `paid_awaiting_confirmation`, `pending_unpaid_awaiting_confirmation`). Session 3's manual-confirm rejection branch uses `BookingCancelledNotification` with a new `payment_status`-aware template branch — refund clause IS PRESENT for `paid` bookings; REFUND CLAUSE IS OMITTED for `unpaid` bookings (locked decision #29 variant). Unconditional refund copy is a regression.
- **Server-side automation runs unconditionally**, even for read-only businesses. `AutoCompleteBookings`, `SendBookingReminders`, `calendar:renew-watches`, `PullCalendarEventsJob`, `StripeWebhookController`, `StripeConnectWebhookController`, `bookings:expire-unpaid` — all keep firing for existing data regardless of subscription state.
- **Direct charges on connected accounts for PAYMENTS** (locked roadmap decision #5). Professionals are merchant of record; `Stripe-Account` header on every Connect call (FakeStripeClient enforces header-present for connected-account methods, header-absent for platform methods).
- **GIST overlap constraint on bookings** (D-065 / D-066). Reaper cancellation + webhook failure-arm Cancellation both release the slot via this mechanism.
- **Tenant context via `App\Support\TenantContext` (D-063)** — `Dashboard\PaymentPendingActionController::resolve` scopes via `tenant()->businessId()`; cross-tenant access is a 404 via tenant-scoped `abort_unless`.
- **Booking success + cancel URLs use `cancellation_token` as bearer** (D-153). The cancel URL in Session 2b mutates no state — webhook owns the transition per D-151.

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

See `docs/BACKLOG.md`. Most relevant post-Session-2b carry-overs:

- **Formal support-contact surface (PAYMENTS Session 1, D-118)**. Connected Account disabled-state CTA mailtos a placeholder; pre-launch needs a real flow.
- **Resend payment link for `unpaid` customer_choice bookings** (PAYMENTS Session 2 carry-over — still out of scope post-2b; explicitly deferred).
- **Per-Business country selector for Stripe Express onboarding (D-121 superseded by D-141)**.
- **Collect country during business onboarding** (D-141 / D-143 follow-up).
- **Pre-role-middleware signed-URL session pinner (D-147 false-negative)**.
- **Tighten billing freeload envelope** (MVPC-3 D-089).
- **WeekScheduleEditor rename** from `components/onboarding/` to `components/settings/` (MVPC-4 cleanup).
- **R-16 frontend code splitting pass** on the whole bundle (the >500 kB Vite warning is pre-existing and unrelated).
- **Calendar carry-overs** (R-17, R-19, R-9 items).
- **Prune old decisions**. `DECISIONS-HISTORY.md` plus anything superseded by D-080+.
- **`Booking::pendingActions()` unfiltered — revisit in Session 3** if codex review prefers a named-scope `pendingPaymentActions()`; for 2b the type-bucket on the dashboard controller is sufficient.
