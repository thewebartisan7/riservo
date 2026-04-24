# PAYMENTS Session 3 — Refunds (Customer / Admin-Manual / Business) + Disputes

This plan lives at `docs/PLAN.md` and follows `.claude/references/PLAN.md`. It is a living document. The sections `Progress`, `Surprises & Discoveries`, `Decision Log`, `Review`, and `Outcomes & Retrospective` are kept up to date as work proceeds.


## Purpose / Big Picture

After this session, every cancel path on a paid booking automatically refunds the customer, admins can issue full or partial refunds manually from the dashboard, manual-confirmation rejections of paid bookings refund the customer, and disputes + refund settlements surface to admins via the existing Pending Action system. Nothing is left where a cancelled booking sits with money stuck on the professional's connected account.

What someone can see working after this ships:

- **Customer in-window cancel.** A customer with a paid booking clicks "Cancel booking" on `/bookings/{token}` (or `/my-bookings/{id}/cancel` if authenticated). If the cancellation window hasn't passed, the booking flips to `cancelled`, `RefundService` dispatches a full refund against the pinned connected account, the `bookings/show` page shows a "Refund initiated — 5–10 business days" status line, and the customer receives a cancellation email including a "a full refund has been issued" clause. If the window has passed, the existing copy ("contact the business") stays as-is and no refund is attempted.
- **Admin-triggered manual refund.** An admin opens the booking-detail sheet in `/dashboard/bookings`, clicks a new "Refund" button visible only when `payment_status ∈ {paid, partially_refunded}` AND `remainingRefundableCents() > 0`. A dialog offers a full-refund radio (default) or a partial-refund amount input (clamped at the remaining refundable amount) plus a free-text reason. Submitting hits a new `Dashboard\BookingRefundController::store` which calls `RefundService::refund` with `reason='admin-manual'` and `initiated_by_user_id=auth()->id()`. Staff users don't see the Refund button at all (the admin-only Payment panel is already hidden from staff in Session 2b).
- **Business cancel.** An admin transitions a paid booking to `cancelled` from the dashboard detail sheet. The booking flips to `cancelled` and a full refund dispatches automatically with `reason='business-cancelled'`. The customer receives the existing `BookingCancelledNotification` with a refund clause appended; when the connected account is disconnected the fallback copy appears instead.
- **Manual-confirmation rejection.** A business running `confirmation_mode=manual` rejects a `pending + paid` booking from the detail sheet. The booking flips to `cancelled` and a full refund dispatches with `reason='business-rejected-pending'`. The customer's cancellation email includes the refund clause. Rejecting a `pending + unpaid` booking (Session 2b's customer_choice-failed-Checkout path) cancels without any refund — the email omits the refund clause entirely. Rejecting a `pending + awaiting_payment` booking (Stripe Checkout not yet completed) cancels without a refund attempt — the Checkout session will expire naturally.
- **Disputes.** When Stripe emits `charge.dispute.created` on a connected account, the existing `payment.dispute_opened` Pending Action stub (D-123) is upgraded: admins receive an email with the dispute reason + a deep-link to the Stripe Express dispute UI; the booking-detail sheet shows a new "Dispute" section deep-linking to Stripe. On `charge.dispute.updated` the payload refreshes (no duplicate email). On `charge.dispute.closed` the PA resolves with a "Dispute won" / "Dispute lost — funds returned to customer" summary and admins receive a closing email. Booking status + payment_status stay unchanged throughout.
- **Refund settlements.** When Stripe later confirms or fails the refund via `charge.refunded`, `charge.refund.updated`, or `refund.updated`, the matching `booking_refunds` row flips status + `failure_reason` in-place and the booking's `payment_status` reconciles (`paid → refunded` / `paid → partially_refunded` / `paid → refund_failed`). Every handler re-reads DB state before acting (locked decision #33).

**Iteration-loop baseline at session start:** 876 passed / 3559 assertions. Expected growth ≈ 30–55 new Feature + Unit tests.

**New decision IDs minted this session:** D-169 onwards. Next free id lives in `docs/HANDOFF.md`.


## Progress

- [x] (2026-04-24 05:15Z) Plan drafted — approved (gate 1 passed).
- [x] (2026-04-24 05:30Z) M1 — `RefundService` expansion: partial-refund path with 422 on overflow (D-169), full reason vocabulary `customer-requested` / `business-cancelled` / `admin-manual` / `business-rejected-pending` / `cancelled-after-payment`, `initiatedByUserId` parameter, new `recordStripeState` / `recordSettlementSuccess` / `recordSettlementFailure` methods for the webhook side. 11 new unit tests (`RefundServicePartialTest`) pass. `FakeStripeClient::mockRefundCreate` capped at `->once()` so stacked mocks match in registration order.
- [x] (2026-04-24 05:45Z) M2 — Replace D-157 / D-159 guards in `BookingManagementController::cancel`, `Customer\BookingController::cancel`, `Dashboard\BookingController::updateStatus` with `RefundService::refund` dispatches. Renamed + rewrote `PaidCancellationGuardTest` → `PaidCancellationRefundTest` (10 tests, all passing). Also rewrote the obsolete D-157 test inside `BookingManagementTest.php` to assert the new refund-dispatch behaviour.
- [x] (2026-04-24 06:05Z) M3 — Admin-manual refund: `Dashboard\BookingRefundController::store` + `StoreBookingRefundRequest` + route + Wayfinder regen + `RefundDialog` component + `BookingDetailSheet` Refund button. Tests pending under M10.
- [x] (2026-04-24 05:45Z) M4 — `BookingCancelledNotification` gains a `refundIssued` flag (D-175); blade template now renders the "A full refund has been issued" paragraph on `cancelledBy=business && refundIssued`; all three cancel callers pass the flag.
- [x] (2026-04-24 06:20Z) M5 — Dispute webhook extension: `handleDisputeEvent` now dispatches `DisputeOpenedNotification` on `created`, refreshes PA on `updated`, dispatches `DisputeClosedNotification` on `closed`; resolves the dispute → booking via `stripe_payment_intent_id` for the PA's booking_id link. `PaymentPendingActionController::resolve` now accepts `PaymentDisputeOpened` (admin-manual dismiss writes `resolution_note='dismissed-by-admin'`). Blade templates added. Tests pending under M10.
- [x] (2026-04-24 06:25Z) M6 — Refund-settlement webhook arms: `dispatch()` routes `charge.refunded` / `charge.refund.updated` / `refund.updated` to `handleRefundEvent`; matches rows by `stripe_refund_id`, enforces D-158 cross-account guard via the booking's pin, calls `RefundService::recordStripeState`. Tests pending under M10.
- [x] (2026-04-24 06:05Z) M7 — Admin Payment & refunds panel: `Dashboard\BookingController::index` payload now carries `remaining_refundable_cents` + `refunds[]` + includes `PaymentDisputeOpened` in the pending-action eager-load; `BookingDetailSheet` renders the refund list (latest-first, Stripe deep-link per row) + a Dispute section deep-linking to the Stripe Express dispute UI with a Dismiss button. Tests pending under M10.
- [x] (2026-04-24 06:35Z) M8 — `Booking::refundStatusLine()` computes the customer-facing copy (refunded / partial / pending / failed / disconnected-fallback); `/bookings/{token}` + `/my-bookings` both render it. TS types extended.
- [x] (2026-04-24 06:40Z) M9 — `tests/Support/Billing/StripeEventBuilder.php` with `disputeEvent` + `refundEvent` canonical payload builders.
- [x] (2026-04-24 06:50Z) M10 — 51 new tests across 6 files: `RefundServicePartialTest` (11), `PaidCancellationRefundTest` (10), `AdminManualRefundTest` (7), `DisputeWebhookTest` (6), `RefundSettlementWebhookTest` (8), `BookingRefundsPanelTest` (5), `BookingShowRefundLineTest` (6). Plus in-place rewrite of the D-157 test in `BookingManagementTest`.
- [x] (2026-04-24 07:05Z) M11 — Iteration loop: 927 / 3864 Feature+Unit green; Pint clean (formatter auto-fixed 7 files); Wayfinder regenerated; PHPStan level 5 clean (fixed dead-catch `@throws`, unreachable match default, over-narrowed instanceof, unnecessary nullsafe); Vite build clean (main chunk 569.64 kB, ~17 kB growth over 2b baseline for RefundDialog + refund helpers).
- [x] (2026-04-24 07:10Z) Promoted D-169..D-175 into `docs/decisions/DECISIONS-PAYMENTS.md`.
- [x] (2026-04-24 07:15Z) Rewrote `docs/HANDOFF.md`.


## Surprises & Discoveries

- **Observation (M1):** `FakeStripeClient::mockRefundCreate` without `->once()` routes every call to the first-registered expectation.
  **Evidence:** Stacking two `mockRefundCreate` calls in `RefundServicePartialTest::partial refunds compose` crashed on a duplicate `stripe_refund_id` — both refunds returned `re_test_null_one` because Mockery's default "0 or more" match picks the first registered expectation.
  **Consequence:** Added `->once()` to the refunds-create expectation inside `FakeStripeClient`. Tests that register multiple mocks now see them consumed in registration order; tests that register one and call twice would now fail loudly (the desired behaviour — Stripe's idempotency-key retry path returns the cached response, not a fresh create, so a single test-level mock is correct).

- **Observation (Round 1 P1):** My Session 3 rewrite of `Dashboard\BookingController::updateStatus` inherited a subtle gap from Session 2b — the controller authorises staff to cancel their own bookings (provider gate), and my new `$shouldRefund` branch ran unconditionally for any `Paid | PartiallyRefunded` booking. Codex caught the auth regression before commit.
  **Evidence:** The pre-Session-3 D-159 block refused paid cancels for every caller, not just staff. My replacement dispatched `RefundService::refund` for the caller regardless of role, side-stepping locked decision #19 (refund is admin-only) even though the dedicated refund endpoint `BookingRefundController::store` enforces the admin gate correctly.
  **Consequence:** Added an `$isAdmin` gate BEFORE the refund-dispatch branch; staff paid-cancel attempts return a "Ask your admin" error flash with zero Stripe calls and zero state change. The lesson: when swapping an unconditional guard for a conditional dispatch, verify every caller role still satisfies the new invariant.

- **Observation (Round 1 P2 serializer):** Collapsing multiple payment PAs into a single `pending_payment_action` key with urgency sort silently hid disputes when any higher-priority refund PA coexisted.
  **Evidence:** Codex read the sort-order `orderByRaw` in `Dashboard\BookingController::index` + the `pendingActions->first()` call in the serializer and spotted the shape — dispute PAs sorted last by design, so they never won `first()` unless they were alone.
  **Consequence:** Split the serialised payload into two keys — `pending_payment_action` (refund-typed, urgency-sorted as before) and `dispute_payment_action` (dispute only). `BookingDetailSheet` reads both independently. Regression test asserts the both-present case.

- **Observation (Round 1 P2 admin note):** The `StoreBookingRefundRequest` validated the `reason` field and the dialog labelled it an "internal note", but the controller never persisted it — the UI's audit promise was a lie. D-174 explicitly keeps `booking_refunds.reason` as a five-value enum-like string, so overloading it with free-text would break that invariant.
  **Evidence:** Codex traced the data path from the dialog form → FormRequest → controller and noticed `$validated['reason']` was never read.
  **Consequence:** New migration adds a nullable `admin_note` column distinct from `reason`. `RefundService::refund` gains a 5th optional arg threaded through to the row at insert time. Two regression tests assert persistence + null on empty input.

- **Observation (M1):** My initial D-169 overflow check mis-treated `$amountCents = null`.
  **Evidence:** `D-169 does NOT fire for $amountCents = null` test failed with the 422 message because the internal `$requestedAmount = $amountCents ?? $booking->paid_amount_cents` yielded the FULL paid amount (5000) on a booking where 3000 remained, tripping the overflow check.
  **Consequence:** Split the branch: `$amountCents === null` → refund `$remaining` (Session 2b contract preserved); non-null → clamp-check against `$remaining` and throw on overflow. System-dispatched paths (always `null`) can never hit the 422.


## Decision Log

Provisional (pre-approval) decisions framed here; each is promoted into `docs/decisions/DECISIONS-PAYMENTS.md` with its final `D-NNN` during exec.

- **D-169 (proposed) — `RefundService` rejects overflow with a 422 `ValidationException` rather than silently clamping.** *Rationale:* locked roadmap decision #37 mandates a server-side second check for partial refunds. The Session 2b implementation (`RefundService.php:154–165`) currently *clamps* `$requestedAmount` down to `$remaining` and logs a warning. That silent clamp is safe for Session 2b (only the `cancelled-after-payment` path runs, always with `$amountCents = null`) but misleading for Session 3's `admin-manual` path — if the admin's client-side clamp bug sends 6000 on a 5000-paid booking, silently refunding 5000 hides the bug and the admin's UI reports success with the wrong number. Instead, the service raises `ValidationException::withMessages(['amount' => ...])` and the controller surfaces it as a 422 with the existing clamp value echoed back; the admin sees the real remaining amount and retries. `$amountCents = null` is unchanged (it always refunds `$remaining`, so overflow is impossible).
- **D-170 (proposed) — `payment.dispute_opened` Pending Actions resolve primarily on `charge.dispute.closed`; `PaymentPendingActionController::resolve` accepts the type for an admin-manual dismiss.** *Rationale:* the normal lifecycle is webhook-driven per locked decision #35 (Stripe closes the dispute → we resolve the PA). Admin-manual dismiss exists as an escape hatch for stuck PAs (Stripe event lost, manual reconciliation). The admin-manual dismiss writes `resolution_note = 'dismissed-by-admin'` so the audit trail distinguishes it from a webhook-driven `resolution_note = 'closed:won'` / `'closed:lost'` resolution.
- **D-171 (proposed) — Refund-settlement webhooks match rows via `booking_refunds.stripe_refund_id`, not via `payment_intent`.** *Rationale:* by the time Stripe emits `charge.refunded` the id has already been persisted by `RefundService::refund` (second transaction after the successful create). If a race hits (webhook arrives before the commit — not observed in practice, Stripe's webhook dispatch is ~100ms after the API call returns), the handler 200s without writing and relies on Stripe retrying. This is the same race-safety posture as the late-webhook refund path.
- **D-172 (proposed) — Stripe refund statuses `requires_action` / `canceled` map to our three-value enum `{pending, succeeded, failed}` per D-167.** *Rationale:* riservo's flows don't trigger `requires_action` (no payer-initiated refund redirects in our Checkout config) and `canceled` means Stripe reversed the refund (rare, e.g. ACH NACK). Mapping: `requires_action` → no-op, leave `Pending`; `canceled` → `Failed` with `failure_reason = 'Stripe cancelled the refund'` + `payment_status = refund_failed` + `payment.refund_failed` PA. Log critical in both cases so operators see the anomaly.
- **D-173 (proposed) — Do NOT backfill `bookings.stripe_charge_id` on promotion for Session 3.** *Rationale:* the roadmap asked "decide at plan time". The refund-settlement webhooks can resolve everything via `stripe_refund_id` on our `booking_refunds` rows or via `payment_intent` (already on the booking row from D-152). The only user-visible benefit of `stripe_charge_id` is a slightly prettier Stripe dashboard deep-link on `BookingDetailSheet` — we already fall back to `payment_intent` when the charge id is null (`booking-detail-sheet.tsx:120–127`). Adding a second `payment_intents.retrieve` call to `CheckoutPromoter::promote` buys one dashboard URL shape and one more cross-account call on every happy-path promotion; not worth it. Left as a backlog entry on close.
- **D-174 (proposed) — Reason vocabulary is five plain strings, not an enum.** Current values: `cancelled-after-payment` (Session 2b), plus `customer-requested`, `business-cancelled`, `admin-manual`, `business-rejected-pending` (Session 3). *Rationale:* strings keep the reason available for audit / dashboard UI display without translation wiring, and the set is closed. If Session 4 or 5 adds a sixth reason this can promote to an enum in one edit.
- **D-175 (proposed) — `BookingCancelledNotification` gains a `$refundIssued` boolean constructor arg.** *Rationale:* locked decision #29 variant requires the cancellation notification to branch on `payment_status` — refund clause present for `paid` rejections, omitted for `unpaid` (customer_choice-failed-Checkout) rejections. Passing `payment_status` to the template and branching in Blade works, but a named flag makes the caller explicit: `new BookingCancelledNotification($booking, 'business', refundIssued: true|false)`. The callers are the three refund-dispatching sites; each sets the flag according to whether `RefundService::refund` returned a `succeeded` outcome. Existing callers (`BookingManagementController::cancel`, `Customer\BookingController::cancel`, `Dashboard\BookingController::updateStatus`) are updated to pass the flag.


## Review

### Round 1

**Codex verdict**: 4 legitimate findings — 1 P1 (auth regression), 2 P2 (PA surfacing + dropped admin note), 1 P3 (template literal `%s`). All applied on the staged diff; no false positives.

- [x] **P1 — Staff can trigger refunds via `Dashboard\BookingController::updateStatus`**.
  *Location*: `app/Http/Controllers/Dashboard/BookingController.php:346-356`.
  *Finding*: `updateStatus` still allowed staff/providers to cancel their own bookings, and my new `$shouldRefund` branch ran for any paid booking *before* any admin-only guard — letting a staff member trigger a Stripe refund, bypassing the admin-only gate on `BookingRefundController::store` (locked decision #19).
  *Fix*: added an `$isAdmin` gate *before* the refund-dispatch branch. Staff paid-cancel attempts now return `back()->with('error', __('Paid bookings can only be cancelled by an admin. Ask your admin to cancel and refund this booking.'))` without touching Stripe. Admin flow unchanged.
  *Regression test*: `tests/Feature/Booking/PaidCancellationRefundTest.php::Codex Round 1 P1: staff cannot trigger a refund via updateStatus cancel`. Asserts staff → error flash, booking stays Confirmed+Paid, zero refund rows, no Stripe call.
  *Status*: done.

- [x] **P2 — Dispute PA hidden when a higher-priority refund PA exists**.
  *Location*: `resources/js/components/dashboard/booking-detail-sheet.tsx:378-379` + `app/Http/Controllers/Dashboard/BookingController.php` serializer.
  *Finding*: the payload collapsed all pending-payment actions to a single `pending_payment_action` (urgency-sorted — `refund_failed` before `cancelled_after_payment` before `dispute_opened`). A booking with both an open dispute AND a `payment.refund_failed` PA would never surface the dispute section.
  *Fix*: split the payload into two independent keys — `pending_payment_action` (first non-dispute PA, as before) and `dispute_payment_action` (first dispute PA, regardless of other PAs). The BookingDetailSheet reads them independently so both banners can render simultaneously.
  *Regression tests*:
    - `tests/Feature/Dashboard/BookingRefundsPanelTest.php::dispute PA surfaces in dispute_payment_action when Pending` — asserts the single-dispute case uses the new key.
    - `tests/Feature/Dashboard/BookingRefundsPanelTest.php::Codex Round 1 P2: dispute + refund_failed PAs both surface independently` — asserts both keys are populated when both PAs exist.
  *Status*: done.

- [x] **P2 — Admin's free-form refund reason silently discarded**.
  *Location*: `app/Http/Controllers/Dashboard/BookingRefundController.php:58-69`.
  *Finding*: `StoreBookingRefundRequest` validated `reason` and the dialog labelled it "internal note — not shared with the customer", but the controller never read `$validated['reason']` or passed it to `RefundService`. Every admin note entered during a manual refund was silently discarded.
  *Fix*:
    - New migration `2026_04_24_063509_add_admin_note_to_booking_refunds_table.php` adds a nullable `admin_note` text column (distinct from the D-174-locked five-value `reason` enum-like string).
    - `BookingRefund` model fillable + PHPDoc extended.
    - `RefundService::refund` gains an optional fifth arg `?string $adminNote = null`; persisted on the row at insert time.
    - `BookingRefundController::store` reads `$validated['reason']` and passes it as `$adminNote`. Empty / missing → null.
  *Regression tests*:
    - `tests/Feature/Dashboard/AdminManualRefundTest.php::Codex Round 1 P2: admin reason is persisted on booking_refunds.admin_note` — asserts a non-empty reason lands on the column.
    - `tests/Feature/Dashboard/AdminManualRefundTest.php::Codex Round 1 P2: empty reason persists as null admin_note` — asserts an empty string does NOT persist as "".
  *Status*: done.

- [x] **P3 — `dispute-closed` blade template renders a literal `%s`**.
  *Location*: `resources/views/mail/payments/dispute-closed.blade.php:8-9`.
  *Finding*: for closed disputes whose Stripe status is neither `won` nor `lost` (e.g. `warning_closed`), the fallback copy read `'The outcome reported by Stripe was "%s".'` but no replacement was supplied — the email went out with a literal `%s` placeholder.
  *Fix*: replace the C-style placeholder with a Laravel translation-key substitution: `__('... was ":status".', ['business' => $businessName, 'status' => $stripeStatus])`.
  *Regression test*: `tests/Feature/Payments/DisputeWebhookTest.php::Codex Round 1 P3: dispute-closed email renders Stripe status literally when not won/lost` — emits a `warning_closed` dispute event, captures the `DisputeClosedNotification`, asserts `%s` is absent AND `warning_closed` is present in the rendered body.
  *Status*: done.

**Post-Round-1 iteration loop**: **932 passed / 3898 assertions** (baseline after Session 3 exec was 927 / 3864; Round 1 added 5 regression tests + 33 assertions). Pint clean, Wayfinder regenerated, PHPStan level 5 clean, Vite build clean (main chunk 569.89 kB).

**Schema impact**: one new migration (nullable column addition — idempotent on `migrate:fresh`, safe on any existing dev DB). No data backfill needed (column defaults to null).

### Round 2

**Codex verdict**: 3 legitimate findings — 1 P1 (admin-manual retry dispatches wrong amount), 1 P2 (settlement webhooks miss rows after response-loss), 1 P3 (N+1 on `/my-bookings`). All applied; no false positives.

- [x] **P1 — Admin-manual retry with different amount silently re-dispatches the old amount**.
  *Location*: `app/Services/Payments/RefundService.php:153-160` (retry-path lookup).
  *Finding*: when an `admin-manual` refund is still Pending (first `refunds.create()` timed out or Stripe still settling), a second manual refund with a DIFFERENT amount would hit the `(booking, reason=admin-manual, status=pending)` lookup, reuse the old row/UUID, and Stripe's idempotency-key dedup would return the OLD refund — silently re-dispatching the earlier amount under the new request. Dashboard reports success for the new amount, money actually refunded was the old.
  *Fix*: inside the retry branch, when `$reason === 'admin-manual'` AND `$amountCents !== null` AND `$amountCents !== $existing->amount_cents`, throw `ValidationException` with a 422 "A refund of CHF X is already pending for this booking. Wait for it to settle before issuing another." Same-amount double-click still reuses the row (idempotency preserved for intentional retries).
  *Regression tests*:
    - `AdminManualRefundTest::admin-manual retry with different amount while one is pending returns 422` — asserts 422 + no new row + no Stripe call.
    - `AdminManualRefundTest::admin-manual retry with same amount while pending reuses row (retry idempotency)` — asserts the idempotent retry path still works via the row's UUID.
  *Status*: done.

- [x] **P2 — Refund-settlement webhooks drop events whose row has `stripe_refund_id = null`**.
  *Location*: `app/Http/Controllers/Webhooks/StripeConnectWebhookController.php` `handleRefundEvent`.
  *Finding*: `RefundService::refund` intentionally bubbles transient Stripe errors (5xx / 429 / connection) with the row left at Pending + `stripe_refund_id = null` so the caller can retry via the UUID idempotency key. But if Stripe had actually CREATED the refund before the transport failed (response-loss), our row never gets the id — and the new `charge.refunded` / `refund.updated` arms key their match on `stripe_refund_id`, so the event is logged + dropped. Customer refunded, booking still shows Paid.
  *Fix*: new private method `resolveRefundRowByFallback()` — when the primary `stripe_refund_id` lookup misses, the Stripe Refund object's `payment_intent` + `amount` are used to find the booking via `stripe_payment_intent_id` + the most recent Pending row with `stripe_refund_id = null` AND matching `amount_cents`. Backfill `stripe_refund_id` on the row so future events converge via the primary path.
  *Regression tests*:
    - `RefundSettlementWebhookTest::response-loss fallback resolves row via payment_intent + amount when stripe_refund_id is null` — asserts the row gets backfilled + marked Succeeded + booking flips Refunded.
    - `RefundSettlementWebhookTest::payment_intent fallback misses when amount does not match any pending row` — negative test; fallback won't match a wrong-amount event (avoids cross-contamination).
  *Status*: done.

- [x] **P3 — `/my-bookings` is N+1 on `refundStatusLine()` + disconnect check**.
  *Location*: `app/Models/Booking.php:257-259` + `app/Http/Controllers/Customer/BookingController.php:24-30`.
  *Finding*: `refundStatusLine()` ran a fresh `booking_refunds` query per call; failed-latest rows also triggered a `stripe_connected_accounts` query per call. `Customer\BookingController::index()` called it for every booking after `->get()`, so 20 bookings with failed refunds = 40 extra queries.
  *Fix*:
    - `Booking::refundStatusLine()` now reads from the eager-loaded `bookingRefunds` relation when `relationLoaded('bookingRefunds')`; falls back to a fresh query for single-booking contexts (preserves correctness for `BookingManagementController::show`).
    - `refundStatusLine()` accepts an optional `?bool $pinnedAccountDisconnected` arg. When supplied, skips the internal `stripe_connected_accounts` query entirely. `hasDisconnectedPinnedAccount()` promoted from `private` to `public` to allow single-booking controllers to call it once.
    - `Customer\BookingController::index()` now eager-loads `bookingRefunds` AND batch-computes the set of disconnected `stripe_account_id` values via a single `whereIn` query, then passes the precomputed boolean into each `refundStatusLine()` call.
    - `BookingManagementController::show` also eager-loads `bookingRefunds` (single-booking context; no batch disconnect lookup needed — the internal fallback is a single query per page render).
  *Regression test*: `Customer/BookingsListTest::/my-bookings does not N+1 on refund status line for many bookings with failed refunds` — seeds 20 bookings each with a failed refund, asserts the total query count stays under 20 (before fix: ~60, after: ~10).
  *Status*: done.

**Post-Round-2 iteration loop**: **937 passed / 3916 assertions** (Round 1 was 932 / 3898; Round 2 added 5 regression tests + 18 assertions). Pint clean, Wayfinder regenerated, PHPStan level 5 clean, Vite build clean.

**Schema impact**: none. All Round 2 fixes are in-code.

### Self-Review (post-Round-2, pre-commit)

Codex quota was exhausted after Round 2; rather than wait for reset, I self-reviewed the three highest-risk surfaces touched by my Round 2 fixes to catch anything a third Codex round might have surfaced.

**Area 1 — Concurrency of `resolveRefundRowByFallback()`**

Walked through the two-concurrent-refunds-same-booking scenario. The reason-scoped retry in `RefundService::refund` (+ Round 2 P1's amount-mismatch block for `admin-manual`) guarantees at most one pending row per `(booking, reason)`. System-dispatched paths (`customer-requested`, `business-cancelled`, `business-rejected-pending`, `cancelled-after-payment`) always resolve `$amountCents = null` to `remainingRefundableCents()` inside the lock — which already subtracts the prior pending row. Two pending rows on the same booking can therefore only exist across different reasons, and their amounts cannot collide (the clamp consumption makes it impossible). Result: the fallback's `(payment_intent, amount_cents, status=pending, stripe_refund_id=null)` match is unambiguous under every reachable state. No bug found. Logged a follow-up in BACKLOG: "attach `booking_refund_id` to the Stripe refund's `metadata` so the settlement webhook could match by that id directly — would be defense-in-depth against a future multi-reason collision, but requires a Stripe API shape change and isn't justified by current risk."

**Area 2 — `ValidationException` propagation from inside `DB::transaction`**

Verified that Laravel's `DB::transaction` re-throws the `ValidationException` (caught, rollback, re-throw contract), Laravel's default exception handler converts it to a 302 redirect back with session errors for Inertia requests, and Inertia's `useForm` surfaces the errors via `form.errors.amount_cents` in the `RefundDialog`. Round 2 P1 test (`admin-manual retry with different amount while one is pending returns 422`) covers the full path via `assertSessionHasErrors(['amount_cents'])`. No bug found.

**Area 3 — Dispute PA linking via `stripe_payment_intent_id`**

Verified the lookup is scoped to `business_id` (no cross-tenant contamination), null-graceful when the payment_intent doesn't resolve to a booking, and can't race against Session 2a's promotion window (disputes only fire on CHARGED bookings, which by construction have completed Checkout and therefore have `stripe_payment_intent_id` populated via `CheckoutPromoter::promote`). Found one gap in test coverage: no dedicated cross-tenant test for the dispute-dismiss endpoint (`PaymentPendingActionController::resolve` on `PaymentDisputeOpened`). Added `DisputeWebhookTest::admin of Business A cannot dismiss a dispute PA of Business B (404)` to close the gap.

**Post-self-review iteration loop**: **938 passed / 3918 assertions** (+1 test / +2 assertions). Pint clean, Wayfinder regenerated, PHPStan level 5 clean, Vite build clean.

**Confidence summary before commit**: two Codex rounds with no false positives + one targeted self-review on the new Round 2 surface. No additional findings queued.


## Outcomes & Retrospective

**Shipped (pre-codex-review):**

- Customer in-window cancel, authenticated-customer cancel, admin cancel, manual-confirmation rejection, and late-webhook refund all funnel through the single `RefundService::refund` executor with one of five reasons. Paid cancels automatically refund (no more "contact the business" dead-end from Sessions 2a/2b); disconnected-account failures surface an admin-only `payment.refund_failed` Pending Action with an email; transient Stripe 5xx / rate-limit / connection-drops bubble as 503s so the pending row + UUID idempotency key survive a retry.
- Admin-manual refund dialog on the booking-detail sheet (admin-only per locked decision #19) supports full and partial refunds with client-side clamping + server-side 422 on overflow per D-169.
- Dispute webhooks now go end-to-end: `charge.dispute.created` emails admins + writes the PA + links the booking via `stripe_payment_intent_id` lookup; `charge.dispute.updated` refreshes without re-emailing; `charge.dispute.closed` resolves the PA with the outcome + emails admins. Admin-manual dismiss via the PA resolve endpoint is an escape hatch per D-170.
- Refund-settlement webhooks (`charge.refunded`, `charge.refund.updated`, `refund.updated`) match rows by `stripe_refund_id` (D-171) and map Stripe vocabulary onto our three-value enum (D-172) — `canceled` is treated as a failure with operator-surfaced copy.
- Customer-side refund UX: `Booking::refundStatusLine()` produces a plain-English status line across five branches (refunded / partial / pending / failed-generic / failed-disconnected) rendered on `/bookings/{token}` and `/my-bookings`.
- Admin-side refund UX: Payment panel now carries `remaining_refundable_cents` + a refund list (newest-first, per-row Stripe deep-link); Dispute section banner with deep-link + Dismiss button when a `payment.dispute_opened` PA is attached.

**Metrics:**

- Feature + Unit tests: **876 → 927** passed (+51); **3559 → 3864** assertions (+305).
- Wall-time: iteration-loop test suite 52s (baseline ~50s). PHPStan level 5 clean. Vite bundle 569 kB (+17 kB from refund dialog + helpers, still within the pre-existing >500 kB warning zone).

**What worked well:**

- The Session 2b `RefundService` groundwork paid off — Session 3 layered partial refunds + four new reasons + webhook settlement without touching the row-insert discipline (UUID idempotency key + reason-scoped retry lookup + `DB::transaction + lockForUpdate`).
- Separating `RefundService::recordSettlementSuccess` / `recordSettlementFailure` / `recordStripeState` as three layers — a vocabulary dispatcher on top of two idempotent writers — kept the webhook handler body tiny and let the unit tests cover both success and failure settlement paths independently of the webhook boundary.
- The `BookingCancelledNotification` `$refundIssued` flag approach (D-175) turned out cleaner than re-reading `payment_status` inside the blade; the three callers pass the flag explicitly, so a new branch (future session) is a one-liner + one blade gate.
- `FakeStripeClient::mockRefundCreate` cap at `->once()` caught a latent test-setup pitfall (first-registered wins) that would have turned every multi-refund scenario into a silent bug.

**What stung:**

- `@throws` docblocks on `RefundService::refund` are load-bearing for PHPStan dead-catch detection — I initially missed them and PHPStan flagged the controller's try/catch as dead. The fix was trivial (add three `@throws` lines) but the diagnostic pointed at the controllers, not the service.
- The default-arm-unreachable PHPStan diagnostic on the controller's `match ($result->outcome)` was slightly non-obvious (the DTO outcome is a closed string union; `default` is always dead). Future Session 4 + 5 callers consuming `RefundResult` should not add a `default` arm either.
- The `Booking::refundStatusLine()` method had to live on the model (to share between the two public controllers) but it reaches into `StripeConnectedAccount::withTrashed()` for the disconnected-account check — coupling the model to the connected-account concept. Worth a look in a future session to see if a helper method on `StripeConnectedAccount::isDisconnectedAccountId(string)` would clean it up.

**Carry-overs to BACKLOG:**

- **`stripe_charge_id` backfill on promotion** (D-173 — deferred past Session 3 explicitly). Add a BACKLOG entry.
- **Enum-ify refund reason vocabulary** (D-174 — kept as strings; promote when a 6th reason is needed).
- **Customer-facing "refund issued" email on admin-manual refunds** (Open Question #1 at plan time — conservative default was no new email; add a BACKLOG entry in case the developer wants it later).

**Gate two handoff:**

- 7 new D-NNN decisions (D-169..D-175) promoted into `docs/decisions/DECISIONS-PAYMENTS.md` — already there, not just in this plan.
- `docs/HANDOFF.md` rewritten (overwrite, not append) per close discipline.
- 927 / 3864 feature+unit suite green, Pint clean, Wayfinder regenerated, PHPStan clean, Vite build clean.
- Staged diff spans 26 files: 2 new controllers (`BookingRefundController`, notifications), 2 new FormRequests, 3 new notifications, 2 new blade templates, 1 new model method, 1 new frontend component, 1 updated dialog host (BookingDetailSheet), plus controller + webhook + service rewrites and the test suite.
- Developer runs codex review against the staged state; findings will land in `## Review` on the same uncommitted diff. Developer commits once at the end.


## Context and Orientation

For a reader unfamiliar with riservo, the relevant concepts for this session:

- **Booking** (`app/Models/Booking.php`): an appointment row with a lifecycle (`pending` / `confirmed` / `cancelled` / `completed` / `no_show`) and a payment lifecycle (`payment_status` — values `not_applicable` / `awaiting_payment` / `paid` / `unpaid` / `refunded` / `partially_refunded` / `refund_failed`). Paid bookings carry `stripe_payment_intent_id`, `paid_amount_cents`, `currency`, and — critically — `stripe_connected_account_id` (the "D-158 pin" — the connected account that minted the Checkout session, authoritative for every downstream Stripe call).
- **Stripe Connect Express**: each riservo `Business` connects its own Stripe account. Every Stripe SDK call for a booking passes `['stripe_account' => $booking->stripe_connected_account_id]` as per-request options. The professional is the merchant of record; riservo collects zero commission.
- **Pinned connected-account id (D-158)**: `bookings.stripe_connected_account_id` is set at Checkout-session creation time and never mutates. Disconnect+reconnect on the same business produces a new `stripe_connected_accounts` row; the old booking keeps its pin. Refunds for old bookings dispatch against the pinned id. When the pinned account is soft-deleted (disconnected), Stripe may refuse with a permission error → `RefundResult::disconnected` fallback (locked decision #36).
- **`RefundService`** (`app/Services/Payments/RefundService.php`, Session 2b): the single refund executor. Signature: `refund(Booking $booking, ?int $amountCents = null, string $reason = 'cancelled-after-payment'): RefundResult`. Inserts a `booking_refunds` row *before* the Stripe call, seeds the Stripe `idempotency_key` from the row's UUID (`riservo_refund_{uuid}` per D-162 + locked decision #36), and handles four outcome flavours: `succeeded`, `failed`, `disconnected`, `guard_rejected`. Transient Stripe errors (5xx, rate limit, connection drop) *don't* flip the row — they propagate so the caller can return 5xx and let Stripe retry; the pending row + UUID ensure the retry converges via Stripe's idempotency. Session 3 extends this service with the four additional reasons and a partial-refund branch while keeping the signature (a new optional 4th param for initiator).
- **`booking_refunds`** table (Session 2b): one row per refund ATTEMPT (locked decision #36). Columns: `id`, `uuid`, `booking_id`, `stripe_refund_id`, `amount_cents`, `currency`, `status` (enum `BookingRefundStatus` — `pending` / `succeeded` / `failed`), `reason` (string), `initiated_by_user_id`, `failure_reason`. Session 3 does not add columns.
- **Pending Actions** (`pending_actions` table, D-113): a generalised queue of admin attention items. The `type` enum values relevant here are `payment.dispute_opened`, `payment.refund_failed`, `payment.cancelled_after_payment` (all pre-added in Session 1 per D-119). Payment PAs are admin-only per locked decisions #19 / #31 / #35 / #36. `Dashboard\PaymentPendingActionController::resolve` (Session 2b) handles admin-manual resolution of the refund-failed + cancelled-after-payment types; Session 3 extends it to accept `payment.dispute_opened`.
- **Stripe Connect webhook** (`/webhooks/stripe-connect`, `StripeConnectWebhookController`): the `account.*`, `checkout.session.*`, `charge.dispute.*` event consumer. Signature-verified via `services.stripe.connect_webhook_secret`; dedup'd via the D-092 cache helper with prefix `stripe:connect:event:`. Session 3 adds `charge.refunded`, `charge.refund.updated`, `refund.updated` handlers + extends the existing dispute handler. All handlers re-read DB state inside `DB::transaction + lockForUpdate` blocks (locked decision #33).
- **`BookingCancelledNotification`** (`app/Notifications/BookingCancelledNotification.php`): fires on every business-side cancel (today unconditionally no refund clause) and on every customer-side cancel (admin / staff receive the notification). Session 3 adds the refund-clause branch per D-175.
- **`BookingDetailSheet`** (`resources/js/components/dashboard/booking-detail-sheet.tsx`): the admin dashboard's booking detail popup. Currently holds the Session 2b Payment panel + Pending-Action banner. Session 3 adds a "Payment & refunds" panel (original charge + refund list), a "Dispute" section when a dispute PA exists, and the admin-only "Refund" button / dialog.
- **`bookings/show`** (`resources/js/pages/bookings/show.tsx`): the public booking-management page (guests arrive via the signed cancellation-token URL; authenticated customers arrive via a similar flow in `customer/bookings.tsx`). Session 3 adds a refund status line below the payment panel.
- **Tenant context** (`App\Support\TenantContext`, D-063): every dashboard request has an active business + role (`admin` / `staff`). New controllers scope via `tenant()->business()` + `abort_unless($booking->business_id === tenant()->businessId(), 404)` for cross-tenant defence (locked decision #45).


## Plan of Work

### M1 — Extend `RefundService` for partial + multi-reason + webhook settlement

File: `app/Services/Payments/RefundService.php`.

1. Change the `refund` signature to accept an optional `initiatedByUserId`:

    ```php
    public function refund(
        Booking $booking,
        ?int $amountCents = null,
        string $reason = 'cancelled-after-payment',
        ?int $initiatedByUserId = null,
    ): RefundResult
    ```

   The extra argument defaults to `null` so every existing caller (Session 2b's `applyLateWebhookRefund`) continues to write `initiated_by_user_id = null` (system-dispatched). New callers in Session 3 (customer cancel, business cancel, manual-confirm rejection) also pass `null`; only `Dashboard\BookingRefundController::store` (admin manual) passes `auth()->id()`.

2. The existing retry-path lookup (`->where('reason', $reason)`) must continue to work. Session 3 uses one `reason` value per refund call-site, so the lookup is still unambiguous. Do NOT widen the lookup to all reasons — two concurrent auto-refund paths would otherwise collapse incorrectly; the booking-level `lockForUpdate` already serialises them so only one writes the refund and the other sees `remainingRefundableCents() = 0` and guard-rejects. Keep reason-scoping.

3. Replace the silent clamp at `RefundService.php:154-165` with a 422 `ValidationException` per D-169:

    ```php
    if ($requestedAmount > $remaining) {
        throw ValidationException::withMessages([
            'amount' => __('Refund exceeds the remaining refundable amount. Maximum allowed is :max.', [
                'max' => number_format($remaining / 100, 2, '.', "'"),
            ]),
        ]);
    }
    ```

   Raised inside the `DB::transaction` callback; the transaction rolls back so no orphan row is left behind. Callers that pass `null` (all system-dispatched paths) can never hit this branch; only the admin-manual path can, and its controller lets the exception bubble so Inertia renders it inline.

4. Populate `initiated_by_user_id` from the new argument at row insert.

5. Expose three new methods for the webhook settlement path (M6):

    ```php
    public function recordStripeState(
        BookingRefund $row,
        string $stripeStatus,
        ?string $failureReason = null,
    ): void {
        match ($stripeStatus) {
            'succeeded' => $this->recordSettlementSuccess($row, $row->stripe_refund_id ?? ''),
            'failed' => $this->recordSettlementFailure($row, $failureReason ?? 'Stripe reported failed'),
            'canceled' => $this->recordSettlementFailure($row, 'Stripe cancelled the refund'),
            'requires_action', 'pending' => null,  // leave Pending
            default => Log::warning('RefundService: unknown Stripe refund status', [
                'booking_refund_id' => $row->id,
                'stripe_status' => $stripeStatus,
            ]),
        };
    }

    public function recordSettlementSuccess(BookingRefund $row, string $stripeRefundId): void
    {
        DB::transaction(function () use ($row, $stripeRefundId): void {
            $booking = Booking::query()->whereKey($row->booking_id)->lockForUpdate()->firstOrFail();

            // Outcome-level idempotency (locked decision #33)
            if ($row->fresh()?->status === BookingRefundStatus::Succeeded) {
                return;
            }

            $row->forceFill([
                'status' => BookingRefundStatus::Succeeded,
                'stripe_refund_id' => $stripeRefundId !== '' ? $stripeRefundId : $row->stripe_refund_id,
            ])->save();

            $this->reconcilePaymentStatus($booking);
        });
    }

    public function recordSettlementFailure(BookingRefund $row, ?string $failureReason): void
    {
        DB::transaction(function () use ($row, $failureReason): void {
            $booking = Booking::query()->whereKey($row->booking_id)->lockForUpdate()->firstOrFail();

            if ($row->fresh()?->status === BookingRefundStatus::Failed) {
                return;
            }

            $row->forceFill([
                'status' => BookingRefundStatus::Failed,
                'failure_reason' => $failureReason,
            ])->save();

            $booking->forceFill(['payment_status' => PaymentStatus::RefundFailed])->save();
        });

        $row->refresh();
        $row->loadMissing('booking');
        $this->upsertRefundFailedPendingAction($row->booking, $row);
        $this->dispatchRefundFailedEmail($row->booking, $row);
    }
    ```

   `reconcilePaymentStatus`, `upsertRefundFailedPendingAction`, `dispatchRefundFailedEmail` already exist from Session 2b — reuse them. Make `upsertRefundFailedPendingAction` + `dispatchRefundFailedEmail` accessible from the two new public entry-points; they're already private instance methods so no visibility change is needed (internal calls).

Tests (new file `tests/Unit/Services/Payments/RefundServicePartialTest.php`):
- partial refund issues for 2000 of 5000, row status `Pending` → `Succeeded`, booking `payment_status` = `PartiallyRefunded`;
- second partial refund for 3000 succeeds, booking `payment_status` = `Refunded`;
- over-refund throws 422 (3000 left + request 4000); no row inserted;
- `admin-manual` reason stores `initiated_by_user_id`;
- `recordSettlementFailure` on an already-Failed row is a no-op;
- `recordSettlementSuccess` is idempotent on already-Succeeded rows;
- `recordStripeState` with `canceled` → failure; with `requires_action` → no-op.

### M2 — Replace D-157 / D-159 paid-cancel guards with `RefundService` dispatches

Three files, each a targeted two-step swap (drop guard, add dispatch).

**`app/Http/Controllers/Booking/BookingManagementController.php::cancel`** (token-based customer cancel):

Today (`BookingManagementController.php:93-97`) a paid booking is blocked with a flash message. Session 3:

- In-window (existing `canCancel()` check passes) AND `payment_status ∈ {Paid, PartiallyRefunded}` → dispatch `RefundService::refund($booking, null, 'customer-requested')`.
  - Wrap the dispatch in a try/catch for `ApiConnectionException | RateLimitException | ApiErrorException` — on transient Stripe failure, do NOT flip Cancelled; respond with a 503 via `abort(503, 'Temporary Stripe issue — please try again.')`. Customer retry replays cleanly (no status transition happened; the pending refund row is reused via the reason-scoped lookup).
  - On `succeeded` outcome: flip to Cancelled, flash `__('Booking cancelled. Refund initiated — you'll receive it in your original payment method within 5–10 business days.')`.
  - On `disconnected` or `failed` outcome: still flip to Cancelled (the service flipped `payment_status` to `refund_failed` and surfaced a Pending Action). Flash `__('Booking cancelled. The business couldn't process the refund automatically — they will contact you directly.')`.
  - On `guard_rejected` (shouldn't happen — we already checked `payment_status ∈ {Paid, PartiallyRefunded}`): treat defensively — generic error flash, keep booking state.
- Out-of-window (`canCancel()` false): the existing "cancellation window has passed" message stays. No refund dispatch.
- In-window AND `payment_status ∈ {NotApplicable, Unpaid, AwaitingPayment}`: existing no-refund cancel path (no change).

The staff-facing `BookingCancelledNotification($booking, 'customer', refundIssued: boolean)` carries the refund flag so admins / providers see the right context in their email.

**`app/Http/Controllers/Customer/BookingController.php::cancel`** (authenticated-customer cancel):

Same rewrite as above; the window check is duplicated (`Customer\BookingController.php:56-60`) and the paid guard (67-71) becomes the RefundService dispatch.

**`app/Http/Controllers/Dashboard/BookingController.php::updateStatus`** (admin / staff cancel from dashboard):

Today (`Dashboard\BookingController.php:300-302`): admin-cancel of a paid booking is blocked. Session 3 replaces with:

- Only when `$newStatus === Cancelled`:
  - `Pending + Paid` → `reason = 'business-rejected-pending'` (locked decision #29 manual-confirm rejection path).
  - `Pending + Unpaid` → no refund dispatch (locked decision #29 variant). Booking flips Cancelled normally. Customer email omits refund clause.
  - `Pending + AwaitingPayment` → no refund dispatch (Checkout never completed). Flip to Cancelled; the Checkout session will expire naturally (the subsequent webhook failure arm no-ops on Cancelled per Session 2b's outcome guard).
  - `Confirmed + Paid | PartiallyRefunded` → `reason = 'business-cancelled'` (locked decision #17).
- Wrap the dispatch in the same `ApiConnectionException | RateLimitException | ApiErrorException` try/catch as the customer paths; on transient failure the booking stays Confirmed and the admin sees a "try again" flash. The existing `$booking->update(['status' => $newStatus])` happens AFTER a successful dispatch so a transient Stripe failure can't produce a `Cancelled + pending-refund` intermediate state.
- On `disconnected` or `failed` outcome from `RefundService`: still flip to Cancelled; admin gets `__('Booking cancelled. Automatic refund failed — resolve in Stripe. The refund-failed action has the details.')`. The existing `payment.refund_failed` Pending Action writer surfaces the banner in the detail sheet.
- Dispatch `BookingCancelledNotification($booking, 'business', refundIssued: $refundWasSucceeded)` per D-175.

**Regression coverage:** `tests/Feature/Booking/PaidCancellationGuardTest.php` currently asserts the *blocks*. Rename it to `PaidCancellationRefundTest.php` via `git mv` and rewrite both cases to assert the new refund-dispatching behaviour, plus add the dashboard branches. See M10.

### M3 — Admin-manual refund controller + UI

**Backend:**

- New `app/Http/Requests/Dashboard/StoreBookingRefundRequest.php`:

    ```php
    public function authorize(): bool { return true; }  // controller enforces admin
    public function rules(): array {
        return [
            'kind' => ['required', Rule::in(['full', 'partial'])],
            'amount_cents' => ['required_if:kind,partial', 'nullable', 'integer', 'min:1'],
            'reason' => ['nullable', 'string', 'max:500'],
        ];
    }
    ```

- New `app/Http/Controllers/Dashboard/BookingRefundController.php` with `store(StoreBookingRefundRequest, Booking)`:

    ```php
    public function __construct(private readonly RefundService $refundService) {}

    public function store(StoreBookingRefundRequest $request, Booking $booking): RedirectResponse
    {
        $business = tenant()->business();
        abort_unless($business !== null && $booking->business_id === $business->id, 404);
        abort_unless(tenant()->role() === BusinessMemberRole::Admin, 403);

        $validated = $request->validated();
        $amount = $validated['kind'] === 'full' ? null : (int) $validated['amount_cents'];

        try {
            $result = $this->refundService->refund(
                $booking,
                $amount,
                'admin-manual',
                $request->user()->id,
            );
        } catch (ApiConnectionException|RateLimitException|ApiErrorException $e) {
            return back()->with('error', __('Temporary Stripe issue — try again in a minute.'));
        }

        return match ($result->outcome) {
            'succeeded' => back()->with('success', __('Refund issued.')),
            'disconnected', 'failed' => back()->with(
                'error',
                __('Stripe couldn\'t process this refund — a pending action has been created with the details.'),
            ),
            'guard_rejected' => back()->with('error', __('This booking is no longer refundable.')),
            default => back()->with('error', __('Unexpected refund outcome.')),
        };
    }
    ```

- Route in `routes/web.php` within the existing dashboard group:

    ```php
    Route::post('/dashboard/bookings/{booking}/refunds', [BookingRefundController::class, 'store'])
        ->name('dashboard.bookings.refunds.store');
    ```

- `php artisan wayfinder:generate` after the route lands.

**Frontend:**

- `BookingDetailSheet` gains a "Refund" button *inside* the existing Payment panel (below the Stripe deep-link), conditional on `isAdmin && payment.remaining_refundable_cents > 0`. The admin-only payment panel is already hidden from staff in Session 2b, so staff never see the button and no placeholder / tooltip is needed.
- New `resources/js/components/dashboard/refund-dialog.tsx` built on the existing Dialog primitive + `useForm`:

    ```tsx
    const form = useForm({ kind: 'full', amount_cents: 0, reason: '' });
    // ... RadioGroup: full (default) vs partial; amount input enabled only when partial;
    //     reason textarea; submit via form.post(store.url(booking.id)).
    ```

    Client-side input clamp uses `remaining_refundable_cents` from the booking payload; 422 errors from `RefundService` (D-169 overflow) render under the amount field. On success, `onSuccess` closes the dialog and triggers a partial reload of the bookings list.

- `Dashboard\BookingController::index` payload extension (admin-only branch): add `remaining_refundable_cents` + `refunds` array (see M7). Wayfinder + TS types updated accordingly.

### M4 — Manual-confirmation rejection paths

The rejection UI lives on `Dashboard\BookingController::updateStatus` — the same controller M2 rewrites. The only incremental change for M4 is notification-side:

1. `app/Notifications/BookingCancelledNotification.php` — add `public bool $refundIssued = false` constructor arg (defaulted so existing test callers keep compiling); pass into blade render.

2. `resources/views/mail/booking-cancelled.blade.php` — add a conditional block:

    ```blade
    @if ($cancelledBy === 'business' && $refundIssued)
    {{ __('A full refund has been issued to your original payment method. You should see it within 5–10 business days.') }}
    @endif
    ```

3. Every caller updated:
    - `BookingManagementController::cancel` (customer path): `cancelledBy='customer'`, `refundIssued` ignored server-side by the template (`cancelledBy === 'business'` guard).
    - `Customer\BookingController::cancel`: same.
    - `Dashboard\BookingController::updateStatus`: `cancelledBy='business'`, `refundIssued = (dispatched && result.outcome === 'succeeded')`. Every rejection branch passes the flag.

Test covers the three business-side branches (paid + rejected → refund + clause; unpaid + rejected → no refund + no clause; awaiting_payment + rejected → no refund + no clause) — see M10.

### M5 — Dispute webhook extension

File: `app/Http/Controllers/Webhooks/StripeConnectWebhookController.php::handleDisputeEvent`.

The existing Session 1 stub (D-123) persists the PA and returns 200. Session 3 layers on:

1. **`charge.dispute.created`**: after the PA upsert, dispatch a new `DisputeOpenedNotification` to business admins. File `app/Notifications/Payments/DisputeOpenedNotification.php` mirrors `RefundFailedNotification`. Email payload: business name, dispute reason, dispute amount + currency, deep-link to `https://dashboard.stripe.com/{stripe_account_id}/disputes/{dispute_id}`, evidence-due-by date.

   Outcome-level idempotency: the cache-layer event-ID dedup (D-092) collapses exact replays. For the rare cross-time re-delivery from Stripe with a different event id, re-sending the email is acceptable — disputes are urgent; admins prefer "one extra nag" over "missed the first email".

2. **`charge.dispute.updated`**: update the PA payload; do NOT send an email. This is the existing stub behaviour — preserved.

3. **`charge.dispute.closed`**: resolve the PA with `resolution_note = 'closed:'.$dispute->status` (existing Session 1 Round 3 behaviour). Then dispatch `DisputeClosedNotification` (new, admin-only) carrying the outcome summary. Subject: `__('Dispute resolved — :outcome', ['outcome' => $disputeStatus])`. Body renders a short paragraph per outcome + deep-link.

4. **`PaymentPendingActionController::resolve`** now accepts `PendingActionType::PaymentDisputeOpened`. Admin-manual dismiss writes `resolution_note = 'dismissed-by-admin'` to distinguish from webhook-set values.

5. Notifications dispatch OUTSIDE the DB transaction (same shape as `applyLateWebhookRefund`).

6. Attempt to resolve a booking for the dispute where possible by looking up `bookings` via `stripe_charge_id` OR `stripe_payment_intent_id` (the dispute's `charge` id — cross-referenced against `bookings` table). When found, link the PA to the booking via `booking_id`. When not found, leave `booking_id = null` (some disputes may be on charges that can't cleanly resolve to a single booking in edge cases — the dispute is still actionable in Stripe's dashboard).

### M6 — Refund-settlement webhook arms

File: `app/Http/Controllers/Webhooks/StripeConnectWebhookController.php`.

Extend the `dispatch()` `match ($event->type)` block:

```php
'charge.refunded',
'charge.refund.updated',
'refund.updated' => $this->handleRefundEvent($event),
```

New handler body (simplified):

```php
private function handleRefundEvent(StripeEvent $event): Response
{
    $refunds = $this->extractRefundsFromEvent($event);   // handles both shapes

    foreach ($refunds as $stripeRefund) {
        $bookingRefund = BookingRefund::where('stripe_refund_id', $stripeRefund->id)->first();
        if ($bookingRefund === null) {
            Log::warning('Connect refund event: no booking_refunds row matches stripe_refund_id', [
                'event_id' => $event->id,
                'stripe_refund_id' => $stripeRefund->id,
            ]);
            continue; // D-092 cache will dedup honest replays; unknown ids we 200 and move on
        }

        $bookingRefund->loadMissing('booking');
        $booking = $bookingRefund->booking;
        $expected = $booking?->stripe_connected_account_id;
        $actual = $event->account ?? null;

        if (! is_string($expected) || $expected === '' || $actual !== $expected) {
            Log::critical('Connect refund event cross-account mismatch', [
                'event_id' => $event->id,
                'booking_id' => $bookingRefund->booking_id,
                'expected' => $expected,
                'actual' => $actual,
            ]);
            continue;
        }

        $this->refundService->recordStripeState(
            $bookingRefund,
            (string) ($stripeRefund->status ?? ''),
            $stripeRefund->failure_reason ?? null,
        );
    }

    return new Response('OK', 200);
}

/**
 * `charge.refunded` carries a Charge whose `refunds->data` is the list.
 * `charge.refund.updated` + `refund.updated` carry a Refund directly.
 */
private function extractRefundsFromEvent(StripeEvent $event): array
{
    $object = $event->data->object ?? null;
    if ($object instanceof StripeRefund) {
        return [$object];
    }
    if ($object instanceof StripeCharge) {
        $refunds = $object->refunds->data ?? [];
        return is_array($refunds) ? $refunds : [];
    }
    return [];
}
```

Notes:

- `recordStripeState` is the M1 dispatcher — idempotent (re-entering with the same terminal state no-ops).
- `charge.refunded` + `refund.updated` + `charge.refund.updated` can all arrive for the same semantic transition. The D-092 event-ID cache dedup collapses exact-duplicate ids; different ids for the same state transition converge via the outcome-level guard inside `recordStripeState`.
- No cross-tenant tests here because the webhook has no tenant — just the cross-account guard via the D-158 pin.

### M7 — Admin Payment & refunds panel + Dispute section

File: `resources/js/components/dashboard/booking-detail-sheet.tsx` + `app/Http/Controllers/Dashboard/BookingController.php::index` payload.

Server-side payload changes (admin-only branch):

1. `payment` sub-object gains `remaining_refundable_cents: int`.
2. New key `refunds` on each booking row: an array of serialised `booking_refunds` entries — each `{ id, created_at, amount_cents, currency, status, reason, initiator_name, stripe_refund_id }`. `initiator_name` resolves `initiatedByUser.name` for `admin-manual`; other reasons display `null` and the UI renders "System". Eager-load `bookingRefunds.initiatedByUser:id,name`.
3. `pending_payment_action` eager-load extends the type filter to include `PaymentDisputeOpened`. Sort order: `PaymentRefundFailed` first (most urgent), then `PaymentCancelledAfterPayment`, then `PaymentDisputeOpened` (long-running).

Frontend changes inside `BookingDetailSheet`:

1. New sub-component `PaymentRefundsPanel` that renders below the existing Payment block. Compact table / list:

    ```
    2026-04-24 14:02 · CHF 20.00 · Succeeded · admin@example.com · Manual refund
    2026-04-23 10:15 · CHF 30.00 · Succeeded · System · Customer cancellation
    ```

    Each row has an "Open in Stripe" icon-link when `stripe_refund_id` is present, deep-linking to `https://dashboard.stripe.com/{acct}/refunds/{stripe_refund_id}`.

2. Below the refund list, a "Refund" button opens the `RefundDialog` (M3) when `isAdmin && payment.remaining_refundable_cents > 0`.

3. A separate "Dispute" section renders only when the `pending_payment_action.type === 'payment.dispute_opened'`. Shows:
   - Title: "Dispute — :status" (e.g. "Dispute — needs response").
   - Reason (from payload).
   - Evidence due-by (formatted).
   - Deep-link button: "Respond in Stripe" → `https://dashboard.stripe.com/{acct}/disputes/{dispute_id}`.
   - "Mark as dismissed" button (admin-only; calls `resolvePaymentPendingAction`) — for the rare admin-manual dismiss case.

4. New `resources/js/components/dashboard/refund-status-badge.tsx` chip for the refund list rows.

### M8 — Public bookings/show refund status line + customer bookings page

Files:
- `app/Models/Booking.php` — new method `refundStatusLine(): ?string` computing the customer-facing copy.
- `app/Http/Controllers/Booking/BookingManagementController.php::show` — pass `refund_status_line` + a lightweight `refunds` array (last 3 entries, guest-safe keys only: `amount_cents`, `currency`, `status`, `created_at`).
- `app/Http/Controllers/Customer/BookingController.php::index` — add `refund_status_line` on each booking's formatted output.
- `resources/js/pages/bookings/show.tsx` — render `refund_status_line` below the payment panel.
- `resources/js/pages/customer/bookings.tsx` — render `refund_status_line` on each booking card.

The `Booking::refundStatusLine()` method branches:

```php
public function refundStatusLine(): ?string
{
    $refunds = $this->bookingRefunds()->latest('id')->get();
    if ($refunds->isEmpty()) { return null; }

    $succeededCents = (int) $refunds->where('status', BookingRefundStatus::Succeeded)->sum('amount_cents');
    $paid = $this->paid_amount_cents ?? 0;
    $latest = $refunds->first();

    if ($latest->status === BookingRefundStatus::Failed) {
        return $this->stripe_connected_account_id !== null && $this->hasDisconnectedPinnedAccount()
            ? __('Your booking is cancelled. Because the business\'s payment setup has changed, the refund cannot be issued automatically — they will contact you to arrange it.')
            : __('The automatic refund couldn\'t be processed. The business has been notified and will contact you.');
    }

    if ($succeededCents >= $paid && $paid > 0) {
        return __('Refunded in full — expect the funds in your original payment method within 5–10 business days.');
    }

    if ($succeededCents > 0 && $succeededCents < $paid) {
        return __('Partial refund issued.');
    }

    // Only Pending rows so far
    return __('Refund initiated — processing.');
}

private function hasDisconnectedPinnedAccount(): bool
{
    return StripeConnectedAccount::withTrashed()
        ->where('stripe_account_id', $this->stripe_connected_account_id)
        ->whereNotNull('deleted_at')
        ->exists();
}
```

Both `/bookings/{token}` and `/my-bookings` pages render `{refund_status_line && <p>{refund_status_line}</p>}` below the existing payment panel.

### M9 — Test plumbing

File: new `tests/Support/Billing/StripeEventBuilder.php`.

Stripe webhook tests today POST raw JSON through `StripeConnectWebhookController` (the tolerant path honours `app()->environment('testing')`). Session 3 adds canonical event payload builders:

```php
public static function disputeEvent(
    string $accountId,
    string $type,  // 'charge.dispute.created' | '.updated' | '.closed'
    string $disputeId = 'dp_test_'.uniqid(),
    array $overrides = [],
): array;

public static function refundEvent(
    string $accountId,
    string $type,  // 'charge.refunded' | 'charge.refund.updated' | 'refund.updated'
    string $stripeRefundId = 're_test_'.uniqid(),
    array $overrides = [],
): array;
```

Each returns a `StripeEvent::constructFrom`-compatible payload. Existing dispute tests in `StripeConnectWebhookTest.php` likely build payloads inline today — refactor to use the builder in the same edit (pure mechanical move; doesn't alter semantics).

`FakeStripeClient` needs no structural additions — Session 2b's `mockRefundCreate` already covers the new admin-manual + customer-cancel + business-cancel paths (they all call `$stripe->refunds->create([...], [header + idempotency_key])` with the same shape).

### M10 — Tests (comprehensive)

**`tests/Feature/Booking/PaidCancellationRefundTest.php`** (`git mv` from `PaidCancellationGuardTest.php`):

- `customer in-window cancel on paid booking dispatches automatic full refund (customer-requested)`.
- `customer in-window cancel on partially_refunded booking dispatches remaining refund`.
- `customer out-of-window cancel on paid booking is blocked; no refund dispatched`.
- `customer cancel on unpaid booking cancels without refund (no RefundService call)`.
- `authenticated customer in-window cancel dispatches refund`.
- `customer cancel transient Stripe error leaves booking Confirmed (503 + no state change)`.
- `admin dashboard cancel on Confirmed + Paid dispatches business-cancelled refund; customer email includes refund clause`.
- `admin dashboard cancel on Pending + Paid dispatches business-rejected-pending refund; customer email includes refund clause`.
- `admin dashboard cancel on Pending + Unpaid cancels without refund; customer email omits refund clause`.
- `admin dashboard cancel on Pending + AwaitingPayment cancels without refund; no Stripe call`.
- `disconnected-account customer cancel: booking still flips Cancelled; payment_status = refund_failed; Pending Action created; fallback flash`.
- `staff cannot transition any booking to Cancelled when their provider isn't assigned (existing 403 preserved)`.

**`tests/Feature/Dashboard/AdminManualRefundTest.php`** (new):

- `admin full refund on Paid booking succeeds; row Succeeded; booking Refunded; initiated_by_user_id = admin.id`.
- `admin partial refund (2000 of 5000) succeeds; booking payment_status = PartiallyRefunded`.
- `admin second partial refund (3000 of remaining 3000) succeeds; booking payment_status = Refunded`.
- `admin over-refund (6000 of remaining 5000) fails with 422; no row inserted`.
- `admin refund on booking with payment_status=NotApplicable is rejected (guard_rejected + error flash)`.
- `admin refund on booking with remainingRefundableCents = 0 is guard-rejected`.
- `staff POST to refund endpoint returns 403`.
- `admin from Business A cannot refund Business B's booking (404 via tenant scope)`.
- `admin refund failure via disconnected-account surfaces refund_failed payment_status + Pending Action; error flash reflects the failure`.
- `idempotency: same admin double-submits collapse to one succeeded row (reason-scoped row reuse + Stripe key)`.
- `admin refund on Refunded booking is guard-rejected (remaining = 0)`.

**`tests/Feature/Payments/RefundSettlementWebhookTest.php`** (new):

- `charge.refunded webhook marks pending row Succeeded; booking Refunded (full)`.
- `charge.refunded webhook marks pending row Succeeded; booking PartiallyRefunded (partial amount)`.
- `charge.refund.updated with status=succeeded is idempotent (no double write)`.
- `charge.refund.updated with status=failed marks row Failed; booking refund_failed; PA created; admin email dispatched`.
- `refund.updated with status=succeeded matches on stripe_refund_id and settles`.
- `refund-settlement webhook for unknown stripe_refund_id logs + 200s` (no crash).
- `refund-settlement webhook cross-account mismatch logs critical + skips` (D-158 pin).
- `refund-settlement event-id replay dedupes at the cache layer (single admin email)`.
- `refund-settlement on an already-Succeeded row is idempotent (outcome-level guard)`.
- `refund canceled status → row Failed + refund_failed payment status` (D-172).

**`tests/Feature/Payments/DisputeWebhookTest.php`** (new):

- `charge.dispute.created writes PA; emails admins only; does not change booking status/payment_status`.
- `charge.dispute.updated refreshes PA payload; does not send duplicate email`.
- `charge.dispute.closed resolves PA with outcome; emails admins with closing summary`.
- `admin can manually dismiss payment.dispute_opened PA via PATCH resolve; resolution_note = 'dismissed-by-admin'`.
- `staff cannot dismiss payment.dispute_opened PA (403)`.
- `dispute webhook for unknown stripe_account_id logs critical + 200s` (existing Session 1 behaviour preserved).
- `booking payment_status is unchanged across dispute trio` (locked decision #25).

**`tests/Feature/Dashboard/BookingRefundsPanelTest.php`** (new):

- `admin sees Payment & refunds panel with refund rows sorted latest-first`.
- `admin sees Refund button when payment_status=paid && remainingRefundableCents > 0`.
- `admin does NOT see Refund button when payment_status=refunded (remaining = 0)`.
- `staff does NOT see Payment panel at all` (existing Session 2b behaviour; reinforce).
- `booking with dispute PA shows Dispute section + Stripe deep-link`.
- `Dashboard\BookingController::index payload exposes remaining_refundable_cents + refunds[] only for admins`.

**`tests/Unit/Services/Payments/RefundServicePartialTest.php`** (new, covered under M1).

**`tests/Feature/Booking/BookingShowRefundLineTest.php`** (new):

- public `/bookings/{token}` shows refund status line after in-window cancel + refund succeeded.
- page shows disconnected-account copy when refund disconnected.
- page shows "partial refund" copy when partial refund succeeded.
- page shows no line when booking has no refunds.

**Existing tests that need maintenance:**

- `tests/Feature/Dashboard/BookingPaymentPanelTest.php` — add `remaining_refundable_cents` assertion.
- `tests/Feature/Dashboard/BookingsListPaymentFilterTest.php` — no change needed; payload additions don't affect the filter.
- Any test instantiating `new BookingCancelledNotification(...)` — update to pass the new `refundIssued` arg (defaulted to `false` so most existing cases work unchanged).

### M11 — Iteration loop

```bash
php artisan test tests/Feature tests/Unit --compact
vendor/bin/pint --dirty --format agent
php artisan wayfinder:generate
./vendor/bin/phpstan
npm run build
```

Each must pass before handing back for codex review + commit.


## Concrete Steps

In-order exec after plan approval. Each bullet corresponds to a `## Progress` line. Stage frequently; never commit.

```bash
# M1 — RefundService expansion
vim app/Services/Payments/RefundService.php
# (RefundResult.php unchanged — outcome set already covers Session 3)
touch tests/Unit/Services/Payments/RefundServicePartialTest.php
vim tests/Unit/Services/Payments/RefundServicePartialTest.php
php artisan test --compact --filter=RefundService
git add -A

# M2 — rewrite three cancel paths + rename PaidCancellationGuardTest
vim app/Http/Controllers/Booking/BookingManagementController.php
vim app/Http/Controllers/Customer/BookingController.php
vim app/Http/Controllers/Dashboard/BookingController.php
git mv tests/Feature/Booking/PaidCancellationGuardTest.php tests/Feature/Booking/PaidCancellationRefundTest.php
vim tests/Feature/Booking/PaidCancellationRefundTest.php
php artisan test --compact --filter=PaidCancellationRefund
git add -A

# M3 — admin-manual refund endpoint + dialog
php artisan make:request Dashboard/StoreBookingRefundRequest
vim app/Http/Requests/Dashboard/StoreBookingRefundRequest.php
touch app/Http/Controllers/Dashboard/BookingRefundController.php
vim app/Http/Controllers/Dashboard/BookingRefundController.php
vim routes/web.php
php artisan wayfinder:generate
touch resources/js/components/dashboard/refund-dialog.tsx
vim resources/js/components/dashboard/refund-dialog.tsx
vim resources/js/components/dashboard/booking-detail-sheet.tsx
touch tests/Feature/Dashboard/AdminManualRefundTest.php
vim tests/Feature/Dashboard/AdminManualRefundTest.php
php artisan test --compact --filter=AdminManualRefund
git add -A

# M4 — notification template branch
vim app/Notifications/BookingCancelledNotification.php
vim resources/views/mail/booking-cancelled.blade.php
# (callers already updated under M2)
php artisan test --compact --filter=BookingCancelled
git add -A

# M5 — dispute webhook extension + DisputeOpenedNotification + DisputeClosedNotification
touch app/Notifications/Payments/DisputeOpenedNotification.php
vim app/Notifications/Payments/DisputeOpenedNotification.php
touch app/Notifications/Payments/DisputeClosedNotification.php
vim app/Notifications/Payments/DisputeClosedNotification.php
touch resources/views/mail/payments/dispute-opened.blade.php
vim resources/views/mail/payments/dispute-opened.blade.php
touch resources/views/mail/payments/dispute-closed.blade.php
vim resources/views/mail/payments/dispute-closed.blade.php
vim app/Http/Controllers/Webhooks/StripeConnectWebhookController.php
vim app/Http/Controllers/Dashboard/PaymentPendingActionController.php
touch tests/Feature/Payments/DisputeWebhookTest.php
vim tests/Feature/Payments/DisputeWebhookTest.php
php artisan test --compact --filter=Dispute
git add -A

# M6 — refund-settlement webhook arms
vim app/Http/Controllers/Webhooks/StripeConnectWebhookController.php
touch tests/Feature/Payments/RefundSettlementWebhookTest.php
vim tests/Feature/Payments/RefundSettlementWebhookTest.php
php artisan test --compact --filter=RefundSettlementWebhook
git add -A

# M7 — admin Payment & refunds panel
vim app/Http/Controllers/Dashboard/BookingController.php   # payload: remaining_refundable_cents + refunds list + dispute PA type
touch resources/js/components/dashboard/refund-status-badge.tsx
vim resources/js/components/dashboard/refund-status-badge.tsx
vim resources/js/components/dashboard/booking-detail-sheet.tsx
vim resources/js/types/index.d.ts
touch tests/Feature/Dashboard/BookingRefundsPanelTest.php
vim tests/Feature/Dashboard/BookingRefundsPanelTest.php
php artisan test --compact --filter=BookingRefundsPanel
git add -A

# M8 — public refund status line
vim app/Models/Booking.php
vim app/Http/Controllers/Booking/BookingManagementController.php
vim app/Http/Controllers/Customer/BookingController.php
vim resources/js/pages/bookings/show.tsx
vim resources/js/pages/customer/bookings.tsx
touch tests/Feature/Booking/BookingShowRefundLineTest.php
vim tests/Feature/Booking/BookingShowRefundLineTest.php
php artisan test --compact --filter=BookingShowRefundLine
git add -A

# M9 — test plumbing (build canonical payloads once)
touch tests/Support/Billing/StripeEventBuilder.php
vim tests/Support/Billing/StripeEventBuilder.php
# (refactor existing dispute test payloads to use it; mechanical)
git add -A

# M11 — iteration loop
php artisan test tests/Feature tests/Unit --compact
vendor/bin/pint --dirty --format agent
php artisan wayfinder:generate
./vendor/bin/phpstan
npm run build
git add -A

# HANDOFF + decisions promotion
vim docs/decisions/DECISIONS-PAYMENTS.md        # D-169 .. D-175
vim docs/HANDOFF.md                             # rewrite (overwrite, not append)
git add -A

# stop — developer commits (gate 2); codex review rounds may follow before commit.
```


## Validation and Acceptance

After approval and implementation, a novice agent can verify by:

1. **Iteration loop:**

    ```bash
    php artisan test tests/Feature tests/Unit --compact
    # expect 876 + N passed (N ≈ 30–55 new cases)
    vendor/bin/pint --dirty --format agent
    php artisan wayfinder:generate
    ./vendor/bin/phpstan
    npm run build
    ```

    Each must exit 0. Frontend bundle should remain in the same ballpark as Session 2b (~552 kB main chunk).

2. **Focused sub-suite runs:**

    ```bash
    php artisan test --compact --filter=RefundService
    php artisan test --compact --filter=PaidCancellationRefund
    php artisan test --compact --filter=AdminManualRefund
    php artisan test --compact --filter=RefundSettlementWebhook
    php artisan test --compact --filter=DisputeWebhook
    php artisan test --compact --filter=BookingRefundsPanel
    php artisan test --compact --filter=BookingShowRefundLine
    ```

3. **Visual acceptance (dev server):**

    ```bash
    composer run dev
    ```

    As an admin on a paid booking, open the booking-detail sheet in `/dashboard/bookings` and confirm:
    - Payment panel shows `Paid`, `CHF 50.00`, `CHF 50.00 refundable`.
    - "Refund" button opens a dialog with Full/Partial radios.
    - Submitting Full triggers a refund; the sheet shows a new entry in the Payment & refunds panel; booking chip flips to `Refunded`.
    - As a customer, `/bookings/{token}` shows the "Refund initiated" line.

4. **Webhook paths:**

    `php artisan test --compact --filter=RefundSettlementWebhook|DisputeWebhook` exercises both paths with canonical payloads built via `StripeEventBuilder`. Production verification is out of scope for this session.


## Idempotence and Recovery

- Every refund dispatch is idempotent at two layers: (a) `booking_refunds.uuid` → Stripe `idempotency_key` (same attempt reuses row); (b) outcome-level DB-state guards inside `RefundService::recordSettlementSuccess` / `recordSettlementFailure` (already-terminal rows no-op).
- The `Dashboard\BookingController::updateStatus` refund dispatch runs BEFORE the status flip. A transient Stripe 5xx raises; the controller returns a "try again" flash; booking remains Confirmed; admin retries.
- The admin-manual refund controller uses the same try/catch; the admin-facing dialog renders 422 errors inline (validation overflow) and 5xx as a generic "try again" flash.
- `recordStripeState` is safe to call any number of times with the same input; it no-ops on already-terminal state per D-171 + D-172.
- Late-webhook refund from Session 2b is unchanged by Session 3.
- Database migrations in this session: **none**. All changes ride the existing schema.


## Artifacts and Notes

Refund reason labels for UI display (`refunds` prop shape):

```typescript
const reasonLabels: Record<string, string> = {
    'cancelled-after-payment': 'Late payment',
    'customer-requested': 'Customer cancellation',
    'business-cancelled': 'Business cancellation',
    'admin-manual': 'Manual refund',
    'business-rejected-pending': 'Booking rejected',
};
```

Dispute PA payload shape (today, preserved):

```json
{
  "dispute_id": "dp_...",
  "charge_id": "ch_...",
  "amount": 5000,
  "currency": "chf",
  "reason": "fraudulent",
  "status": "warning_needs_response",
  "evidence_due_by": 1735689600,
  "last_event_id": "evt_...",
  "last_event_type": "charge.dispute.created"
}
```


## Interfaces and Dependencies

### `app/Services/Payments/RefundService.php`

```php
public function refund(
    Booking $booking,
    ?int $amountCents = null,
    string $reason = 'cancelled-after-payment',
    ?int $initiatedByUserId = null,
): RefundResult;

public function recordStripeState(
    BookingRefund $row,
    string $stripeStatus,
    ?string $failureReason = null,
): void;

public function recordSettlementSuccess(BookingRefund $row, string $stripeRefundId): void;
public function recordSettlementFailure(BookingRefund $row, ?string $failureReason): void;
```

### `app/Http/Controllers/Dashboard/BookingRefundController.php`

```php
public function store(StoreBookingRefundRequest $request, Booking $booking): RedirectResponse;
```

Route: `POST /dashboard/bookings/{booking}/refunds` → `dashboard.bookings.refunds.store`.

### `app/Notifications/BookingCancelledNotification.php`

```php
public function __construct(
    public Booking $booking,
    public string $cancelledBy,
    public bool $refundIssued = false,
);
```

### `app/Notifications/Payments/DisputeOpenedNotification.php` + `DisputeClosedNotification.php`

```php
public function __construct(
    public ?Booking $booking,     // null if the dispute couldn't resolve to a booking
    public PendingAction $pendingAction,
);
```

### `resources/js/types/index.d.ts`

```typescript
interface DashboardBookingPayment {
    status: string;
    paid_amount_cents: number | null;
    currency: string | null;
    paid_at: string | null;
    stripe_charge_id: string | null;
    stripe_payment_intent_id: string | null;
    stripe_connected_account_id: string | null;
    remaining_refundable_cents: number;   // NEW
}

interface DashboardBookingRefund {
    id: number;
    created_at: string;
    amount_cents: number;
    currency: string;
    status: 'pending' | 'succeeded' | 'failed';
    reason: string;
    initiator_name: string | null;
    stripe_refund_id: string | null;
}

interface DashboardBooking {
    // ... existing fields ...
    refunds?: DashboardBookingRefund[];   // NEW, admin-only
}
```


## Open Questions

None that block gate-1 approval. Three I'd flag for the developer to sanity-check before exec:

1. **Customer-facing email on admin-manual refund?** Roadmap §Customer-side explicitly mentions the customer-facing success copy for the customer-cancel path; it's silent on admin-manual. Conservative default: **no new customer email on admin-manual** (the customer already knows the booking is cancelled; the bank-side refund arrival is signal enough). If the developer wants one, the shape would be `App\Notifications\Payments\RefundIssuedNotification` to the customer on `admin-manual` succeeded outcomes.
2. **Dispute email PII level.** Locked decision #35 mentions the dispute reason + deep-link but is silent on customer PII. Default: **include customer name + email + booking starts_at** (admins investigating disputes always need to reach the customer). If the developer prefers a lean email, strip to reason + amount + deep-link.
3. **Exact wording of the disconnected-account refund line on `bookings/show`.** Locked decision #36 says "Refund not automatic — the business will contact you". I've drafted "Your booking is cancelled. Because the business's payment setup has changed, the refund cannot be issued automatically — they will contact you to arrange it." Confirm the wording before i18n keys freeze.
4. **`PaidCancellationGuardTest.php` rename.** The .claude/CLAUDE.md rule says "Do NOT delete tests without approval". A `git mv` + rewrite preserves the semantic intent (guard replaced by refund dispatch) but the assertions invert. Confirm the rename is acceptable vs. keeping a placeholder file that re-exports the new tests.

Proceeding on these with the conservative defaults above unless the developer corrects.


## Risks & Notes

- **Test-suite size growth.** Adding 30–55 tests to the Feature + Unit baseline (876 / 3559) will extend iteration-loop wall-time by a few seconds. Still well under the 2-minute full-suite budget.
- **`BookingCancelledNotification` blade change.** The new `@if ($cancelledBy === 'business' && $refundIssued)` block requires a template-render test (planned under M4). Unconditional refund copy is explicitly a regression per locked decision #29 variant.
- **Guard replacement atomicity.** M2 touches three controllers in what must remain a coherent diff. The PaidCancellationRefundTest rename is the durable record that the old guards are gone; the new dispatches are covered by separate tests per endpoint.
- **Stripe webhook ordering.** `charge.refunded` can arrive before `refund.updated` in principle; both should converge via our idempotent `recordStripeState`. Tests explicitly replay in both orders.
- **Disconnected-account flash UX.** The customer-facing disconnect copy is shared across customer in-window cancel, admin cancel, and `bookings/show`. Consolidating into `Booking::refundStatusLine()` for the public view is the right move; admin cancel paths use controller-local flash strings since the copy context differs.
- **Wayfinder regeneration.** After adding the new controller + route, `php artisan wayfinder:generate` is required — its omission is the most common cause of "Property 'store' does not exist" TS errors in PRs. The M3 / M11 steps include it.
- **Staff permissions.** Every new endpoint + UI element is admin-gated by `tenant()->role() === BusinessMemberRole::Admin` server-side AND by `isAdmin` branches client-side. Staff users never see the Refund button, never see the Payment panel, never see dispute PAs. Cross-tenant isolation per locked decision #45 is covered by explicit tests.
- **`PaidCancellationGuardTest.php` rename.** Noted under Open Questions — flag at approval.
- **Webhook 5xx propagation shape.** Session 2b's `applyLateWebhookRefund` returns `503 + Retry-After: 60` on transient Stripe errors so the connect webhook retries. The new `handleRefundEvent` does NOT call Stripe (it only reads event data + calls `recordStripeState` which only writes to the DB), so no 5xx propagation concern. If a DB error throws mid-transaction, Laravel's default 500 surfaces and Stripe retries per the dedup trait's "non-2xx is not cached" contract — matches Session 2b behaviour.
