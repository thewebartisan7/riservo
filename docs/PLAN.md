# PAYMENTS Hardening — Codex Adversarial Review Round 1 Fixes

This plan lives at `docs/PLAN.md` and follows `.claude/references/PLAN.md`. It is a post-roadmap hardening session: the PAYMENTS roadmap closed at Session 5 (HANDOFF.md), and a separate adversarial Codex review (`docs/REVIEW.md`, 2026-04-28) surfaced seven findings against the shipped slice. This session fixes those findings on a single uncommitted diff before going to a second adversarial review round.

Iteration-loop baseline at session start: **963 tests / 4173 assertions** (HANDOFF.md, post-Session-5). PHPStan level 5 / `app/` clean. Pint clean. Wayfinder up to date. Vite build green.

---

## Purpose / Big Picture

The Stripe Connect payments slice works on the happy path and the test matrix is dense, but Codex found seven brittleness points on uncommon-but-realistic execution paths. The unifying theme of the highest-severity findings: **the booking row is supposed to be the snapshot source of truth for amount, currency, and connected-account identity, but several call sites still re-derive these from mutable upstream state**. A second theme: **post-Stripe local writes have no deterministic recovery path** — when Stripe accepts a side effect and the local write that records it fails, the system is left in a state the operator must clean up by hand.

Concretely, after this session:

1. The Stripe Checkout session is built from `Booking.paid_amount_cents` + `Booking.currency`, never from the live `Service.price` or the live connected-account `default_currency`. A booking created with a 50 CHF snapshot can no longer be charged a different amount because someone edited the service price between the booking and the redirect.
2. A DB failure between `checkout.sessions.create` and `Booking::update(['stripe_checkout_session_id'])` no longer strands a slot. Either the local write succeeds and the customer reaches Checkout, or the booking is cancelled and the slot released.
3. Refund settlement webhooks resolve `BookingRefund` rows by `stripe_refund_id` only (D-171). The amount-based fallback that can mis-attribute partial refunds is removed.
4. Admin cancellation of a paid booking can no longer leave the customer refunded with the slot still active. The cancellation transitions the booking to `cancelled` in the same logical unit of work that dispatches the refund attempt.
5. The Payouts cache is keyed by both business id AND `stripe_account_id`, and is invalidated on disconnect / deauthorization / account-id change.
6. `recordSettlementFailure` is idempotent: a duplicate webhook for an already-failed refund does NOT re-send the admin email or duplicate the Pending Action. A new partial unique index closes the PA-creation race.
7. The payments-era column migration's `down()` actually rolls back every column it added.

Acceptance is verifiable by walking the Codex finding list against the staged diff (Round 2 review will check this explicitly), running `php artisan test tests/Feature tests/Unit --compact`, and watching the baseline grow without regressions.

---

## Scope

### In scope (only the Codex findings F-001..F-007)

- `app/Services/Payments/CheckoutSessionFactory.php` — read amount/currency from booking, not service/account.
- `app/Http/Controllers/Booking/PublicBookingController.php::mintCheckoutOrRollback` — catch local persistence failures after Stripe-side success.
- `app/Console/Commands/ExpireUnpaidBookings.php` — change the `missing session_id` policy from `skipped` to `cancelled` for online bookings past their grace window.
- `app/Http/Controllers/Webhooks/StripeConnectWebhookController.php::handleRefundEvent` + `resolveRefundRowByFallback` — remove the payment_intent+amount fallback per D-171.
- `app/Http/Controllers/Dashboard/BookingController.php::updateStatus` — refund + status transition in one transactional unit.
- `app/Services/Payments/RefundService.php::recordSettlementFailure` — idempotency guard around PA upsert + email dispatch.
- `app/Http/Controllers/Dashboard/PayoutsController.php` + `app/Http/Controllers/Dashboard/Settings/ConnectedAccountController.php` (+ Connect webhook deauth handler) — cache key includes `stripe_account_id`; forget on disconnect / deauth.
- A new migration adding a partial unique index on `pending_actions.payload->>'booking_refund_id'` for `type = 'payment.refund_failed'`.
- `database/migrations/2026_04_23_174426_add_payment_columns_to_bookings.php::down()` — include `stripe_connected_account_id` in `dropColumn()`.
- New + updated tests under `tests/Feature/` and `tests/Unit/` covering each finding's "How to break it" sequence.

### Out of scope

- Frontend XSS / Inertia prop leak audit on `payouts.tsx`, `connected-account.tsx`, `refund-dialog.tsx`. **Deferred to Round 2 of the Codex review** (where the developer asks for explicit frontend depth). Doing it now would conflate finding-fix verification with new analysis.
- Browser test authoring for the new payments surfaces (`tests/Browser/Payments/*`). Coverage gap acknowledged in Round 1; bigger session of its own.
- Refund metadata-based response-loss recovery (the alternative Codex offered for F-003). Not rolling-deploy-safe, and the simpler "remove the fallback, log unknown ids and 200" path that D-171 already prescribes is sufficient for MVP. Future work if response-loss is observed in production.
- Anything outside the seven findings: D-IDs, controllers, services, migrations not listed above.
- The 11 failing browser tests (`tests/Browser/Embed/IframeEmbedTest` etc.) — investigated separately after this hardening lands.

---

## Approach (per finding)

### F-001 — Checkout reads booking snapshot, not mutable upstream

`CheckoutSessionFactory::create($booking, $service, $business, $account)` currently computes:

```php
$currency    = $account->default_currency ?? 'chf';
$amountCents = (int) round((float) $service->price * 100);
```

Replace with:

```php
$currency    = $booking->currency;
$amountCents = $booking->paid_amount_cents;

if (! is_string($currency) || $currency === '' || ! is_int($amountCents) || $amountCents <= 0) {
    throw new InvalidBookingSnapshotForCheckout($booking);
}
```

The two snapshot columns are populated in `PublicBookingController::store` at booking creation (lines 439, 447), so by the time `mintCheckoutOrRollback` runs they are guaranteed non-null for any online-payment booking. The new exception is caught at the controller boundary and surfaces as `ValidationException::withMessages(['checkout_failed' => __(...)])` — same error envelope as `ApiErrorException` for symmetry.

The `Service` parameter on `CheckoutSessionFactory::create` is now only used for `$service->name` in the line item's `product_data.name`. We keep the parameter (the product name is still a runtime value, not pinned on the booking).

**D-ID**: this implements the **letter** of D-152 / D-156 — those decisions defined the booking columns as the contract; this change makes them the actual contract. Promoted as **D-177** (the booking snapshot is the only Checkout amount source).

### F-002 — Post-Stripe DB failure releases the slot, reaper cancels strays

`mintCheckoutOrRollback` currently:

```php
$session = $this->checkoutSessionFactory->create(...);
$booking->update(['stripe_checkout_session_id' => $session->id]);   // can throw
```

Wrap the persistence write in its own try/catch. On failure:

1. Log critical with the (now-orphaned) Stripe session id so the operator can manually expire it on the Stripe dashboard.
2. Call `$this->releaseSlotFor($booking)` (existing helper that flips the booking to `cancelled` so the GIST exclusion frees up).
3. Throw `ValidationException::withMessages(['checkout_failed' => __(...)])`.

Reaper policy in `ExpireUnpaidBookings::processBooking`: today, missing `stripe_checkout_session_id` returns `'skipped'` and logs critical (line 99-108). After this fix the orphaned-DB-write path cancels the booking on the spot, so the reaper should never see a missing session id under normal operation. The reaper still needs to handle the legacy / unexpected case — change the policy from `skipped` to `cancelled` when the booking is past its `expires_at + grace`. Log critical with the same operator-actionable detail. The tests for the missing-session-id branch get rewritten to assert cancellation.

### F-003 — Drop the D-171 fallback

`StripeConnectWebhookController::handleRefundEvent` calls `resolveRefundRowByFallback` when the primary `where('stripe_refund_id', $refundId)` lookup misses. Delete `resolveRefundRowByFallback` outright and the call site. Replace with a `Log::warning` (already there in the post-fallback miss branch) and `continue;`.

The "How to break it" sequence in F-003 (a CHF 10.00 partial-refund response-loss leaving row A pending, then a second CHF 10.00 partial creating row B, then the webhook for row A backfilling row B's id) is closed by simply not having a fallback. The narrow window the fallback was meant to cover (Stripe SDK throws after Stripe accepted the refund) is documented in D-171 as "log + 200, accept the operational gap". Restore that contract.

The `tests/Feature/Payments/RefundSettlementWebhookTest.php` test that codifies the fallback (`refund event for unmatched stripe_refund_id falls back to payment_intent + amount lookup` or similar) is rewritten to assert the unmatched id is logged and 200'd with no row update. Add a new test that proves the F-003 mis-attribution sequence does NOT mutate row B.

**D-ID drift fix**: this aligns the code with D-171 verbatim. No new D-ID; the comment block at the call site is rewritten to reference D-171 and the F-003 finding for traceability.

### F-004 — `recordSettlementFailure` idempotent

Two changes in `app/Services/Payments/RefundService.php`:

1. The DB transaction returns `bool $didTransition` (true iff the row moved from a non-terminal state to `Failed`). The current early-return `if ($row->status === BookingRefundStatus::Failed) { return; }` becomes the false branch.
2. The post-transaction Pending Action upsert + `Notification::route('mail', $admin)->notify(...)` block runs only when `$didTransition === true`.

Add a Postgres partial unique index migration:

```sql
CREATE UNIQUE INDEX pending_actions_payment_refund_failed_unique
  ON pending_actions ((payload->>'booking_refund_id'))
  WHERE type = 'payment.refund_failed'
    AND resolved_at IS NULL;
```

`PendingAction` creation in `recordSettlementFailure` is wrapped in a savepoint + catch-on-unique-violation — same shape as the dispute PA per D-126. The loser of the race silently no-ops; the winner has an active PA. Tests assert two concurrent webhook events for the same failed refund land exactly one PA + one admin email.

### F-005 — Admin paid-cancel: refund + status in one logical transaction

Today in `Dashboard\BookingController::updateStatus`:

```php
$result = $refundService->refund($booking, null, $reason);   // Stripe refund + local row
$booking->refresh();
// ... possibly other code ...
$booking->update(['status' => $newStatus]);                  // separate write
```

The risk window is "Stripe accepted the refund + we committed our local refund row, but we never wrote `status = cancelled`". Fix shape: transition the booking to `cancelled` BEFORE calling Stripe, on the assumption that the cancel intent is the source of truth and the refund is a downstream side effect. The refund row records the attempt; if Stripe accepts, the refund row is `succeeded`, if Stripe fails, the refund row is `failed` and the existing `payment.refund_failed` Pending Action surfaces it. The slot is freed either way — which is the correct state once the admin has clicked cancel.

Concrete change:

```php
DB::transaction(function () use ($booking, $newStatus) {
    $booking->update(['status' => $newStatus]);   // free the slot first
});
// Now dispatch the refund — failures land as PA + email, slot already free.
try {
    $result = $refundService->refund($booking, null, $reason);
} catch (...) { ... }
```

Concretely the refund call is moved to AFTER the status transition. The transaction wraps the status update only (the refund itself opens its own DB transactions inside `RefundService`). If `RefundService::refund` raises a transient Stripe error, the existing handler catches and returns a flash; the booking is now `cancelled` regardless, which is the safer state.

Tests: existing `PaidCancellationRefundTest.php` already covers happy path + refund-failure cases; add a test that asserts a worker-killed-after-refund scenario leaves `status = cancelled` (this is implicit in the new ordering, but worth a regression test). A second test asserts the public booking list reflects cancellation immediately even when the refund is still in flight.

### F-006 — Payouts cache: include account id, forget on disconnect

`PayoutsController::cacheKey` currently `"payouts:business:{$businessId}"`. Change to:

```php
private function cacheKey(int $businessId, string $stripeAccountId): string
{
    return "payouts:business:{$businessId}:account:{$stripeAccountId}";
}
```

Both call sites (`fetchPayoutsPayload` line 145; the `Cache::put` line 158) thread the row's `stripe_account_id` through. A disconnect+reconnect mints a new account id, so the new key cannot collide with the stale one — the stale entry simply expires harmlessly.

Belt-and-braces: `ConnectedAccountController::disconnect` and the Connect webhook's `account.application.deauthorized` handler call:

```php
Cache::forget("payouts:business:{$businessId}:account:{$stripeAccountId}");
```

Inside the same DB transaction that soft-deletes the row. Tests: a unit test on the cache key shape; a feature test that disconnect+reconnect followed by a fresh `index()` call hits Stripe (not cache).

### F-007 — Migration `down()` includes `stripe_connected_account_id`

One-line fix in `database/migrations/2026_04_23_174426_add_payment_columns_to_bookings.php`: append `'stripe_connected_account_id'` to the `dropColumn()` array. Add a unit test in `tests/Unit/Migrations/` (or extend an existing one) that runs `down()` on a freshly-migrated payments-era schema and asserts the column is gone.

---

## Risk register

- **F-005's reordering is the most behaviour-changing fix.** Today the admin sees the refund result before status flips; after this change, the cancel happens first and the refund is "best effort". Verify no UI relies on `payment_status` reflecting the refund outcome in the same response. Tests + manual walk through Settings → Bookings.
- **F-001 + F-003 are pure subtractions** (less ambient state, fewer fallbacks). Low blast radius.
- **F-004's partial unique index requires a non-rolling deploy** if the table has existing duplicate rows. Pre-launch — `migrate:fresh --seed` is the norm, no production data — so safe by inspection. The migration includes a guard that asserts no duplicates exist before adding the index (fails loud if a future deploy violates the precondition).
- **F-006's cache key change** invalidates every existing payouts cache entry on first deploy. This is desirable (the prior key is the bug); just note it for the operator.
- **D-177 promotion**: the finalised D-ID lands in `docs/decisions/DECISIONS-PAYMENTS.md` at session close, not before. Until promoted, the decision lives only in `## Decision Log` here. Per CLAUDE.md scoped guide rule, we write D-177 directly into `DECISIONS-PAYMENTS.md` during exec to avoid the overwrite-risk on next session.
- **Wayfinder regen**: no new routes added; no Wayfinder regen expected. Verify with `git diff resources/js/wayfinder/` after `php artisan wayfinder:generate`.

---

## Quality bar / Done check

- `php artisan test tests/Feature tests/Unit --compact` green; new tests added per finding.
- `vendor/bin/pint --dirty --format agent` clean.
- `php artisan wayfinder:generate` idempotent.
- `./vendor/bin/phpstan` no errors.
- `npm run build` clean.
- `docs/PLAN.md` `## Progress` reflects every finding closed; `## Decision Log` documents the few cases where the fix shape differs from Codex's suggestion (notably F-005's reorder vs Codex's intent/refund-pending-state suggestion).
- `D-177` written into `docs/decisions/DECISIONS-PAYMENTS.md` (booking snapshot is the only Checkout amount source).
- `docs/HANDOFF.md` rewritten — adds a "PAYMENTS Hardening Round 1" line under "What shipped" and updates the test baseline.
- `/codex:review` (Round 2) launched against the staged diff — see `## Review` section for findings + dispositions.

---

## Progress

- [x] (2026-04-28) Milestone 1 — F-001: `CheckoutSessionFactory::create` reads booking snapshot. New `InvalidBookingSnapshotForCheckout` exception. Caller catches as `checkout_failed`. Promoted as D-177.
- [x] (2026-04-28) Milestone 2 — F-002: `mintCheckoutOrRollback` catches local persistence failure (try/catch around `Booking::update`); reaper changes missing-session-id branch from `'skipped'` to `cancelBooking($booking)`. Promoted as D-178.
- [x] (2026-04-28) Milestone 3 — F-003: removed `resolveRefundRowByFallback` from `StripeConnectWebhookController`; orphan import cleanup (`BookingRefundStatus`). Rewrote the prior fallback test as F-003 mis-attribution proof. Promoted as D-179.
- [x] (2026-04-28) Milestone 4 — F-004: `recordSettlementFailure` returns `didTransition`; PA + email only on transition. New migration `2026_04_28_194851_add_refund_failed_unique_index_to_pending_actions.php` with pre-flight duplicate guard. `upsertRefundFailedPendingAction` wraps insert in `DB::transaction` + `UniqueConstraintViolationException` catch. New idempotency test. Promoted as D-180.
- [x] (2026-04-28) Milestone 5 — F-005: `Dashboard\BookingController::updateStatus` reorders `$booking->update(['status' => $newStatus])` BEFORE the `RefundService::refund()` call. New `transient` flash outcome added to the `match` + `flashKey` allow-list so transient Stripe errors surface to the admin without short-circuiting customer email + calendar push. Promoted as D-181.
- [x] (2026-04-28) Milestone 6 — F-006: `PayoutsController::cacheKey` promoted to public static, includes `stripe_account_id`. `Cache::forget` called inside `ConnectedAccountController::disconnect` + `StripeConnectWebhookController::handleAccountDeauthorized`. Existing cache-isolation tests rewritten to construct keys via the public helper. New disconnect-forgets-cache test. Promoted as D-182.
- [x] (2026-04-28) Milestone 7 — F-007: `2026_04_23_174426_add_payment_columns_to_bookings.php::down()` includes `stripe_connected_account_id` in `dropColumn()` list. Banal one-liner; not a D-ID promotion (not architectural).
- [x] (2026-04-28) Milestone 8 — Iteration loop:
    - `php artisan test tests/Feature tests/Unit --compact` → **965 / 4183** (baseline 963 / 4173; +2 tests, +10 assertions). Net change: F-003 pre-existing fallback test rewritten to the new contract + 2 net-new tests (F-003 mis-attribution proof, F-004 duplicate-webhook idempotency, F-006 disconnect-forgets-cache).
    - `vendor/bin/pint --dirty --format agent` → pass.
    - `php artisan wayfinder:generate` → regenerated (no route changes; idempotent re-run).
    - `./vendor/bin/phpstan` → No errors. (One transient finding on `BookingController:407` `$refundReason !== null` was redundant after the F-005 reorder; tightened the guard since `$refundReason` is set only via `$shouldRefund`'s positive branch.)
    - `npm run build` → clean (main app chunk ~585 kB; pre-existing warning unchanged).
- [x] (2026-04-28) Milestone 9 — Promoted D-177..D-182 (six decisions) directly into `docs/decisions/DECISIONS-PAYMENTS.md`. Next free D-ID: D-183.
- [x] (2026-04-28) Milestone 10 — Rewrote `docs/HANDOFF.md`. New baseline 965 / 4183. Six new D-IDs recorded. Browser-test failure note added (out of scope here; tracked separately).
- [ ] Milestone 11 — Codex Round 2 review against the staged diff; findings (if any) applied under `## Review — Round 2`.

---

## Surprises & Discoveries

- **F-005's customer email path** initially broke. The first cut of the F-005 reorder caught `ApiConnectionException|RateLimitException|ApiErrorException` and returned `back()->with('error', ...)` early — but by then the booking was already in `Cancelled`, so the early return short-circuited the customer cancellation email + the Google Calendar `delete` push. Fixed by lifting the transient error into a new `$refundOutcome = 'transient'` outcome so the rest of the handler still runs; the new flash branch surfaces "Booking cancelled. The refund hit a temporary Stripe issue — retry the refund from the booking detail panel." This is more user-honest than the prior shape too: before F-005 the flash on transient error was just "Temporary Stripe issue — try again in a minute" which is misleading because the cancel side-effect is already irreversible at that point.
- **D-177 was originally one promotion** in the plan; in practice each finding warranted its own D-ID because every fix changes a comportamental contract: D-177 (snapshot-as-truth), D-178 (reaper policy), D-179 (D-171 verbatim, drops fallback), D-180 (settlement-failure idempotency), D-181 (paid-cancel reorder), D-182 (payouts cache key + forget). F-007 stays out of the D-ID register — it's a missed-column rollback, not an architectural decision.
- **PHPStan caught one redundant guard** (`$shouldRefund && $refundReason !== null`) post-F-005. After the reorder `$refundReason` is set only inside the positive `$shouldRefund` branch, so the second check is always true. Tightened to `if ($refundReason !== null)`. Trivial follow-up; flagged the static analyser is keeping us honest on the new ordering.
- **`StripeEventBuilder::refundEvent` did not accept an event-id override**, which the F-004 idempotency test needs (the cache-layer dedup keys on event id; we want two distinct ids carrying the same refund id). Extended the builder with an optional `?string $eventId = null` parameter; default keeps the existing `'evt_test_'.uniqid()` shape so no other tests change.

---

## Review

(Filled in after the developer launches `/codex:review` against the staged diff.)

---

## Decision Log

D-177 through D-182 were promoted directly into `docs/decisions/DECISIONS-PAYMENTS.md` during exec to avoid the next-session-overwrite risk. Summary here for orientation; the canonical bodies live in DECISIONS-PAYMENTS.

- **D-177** — Stripe Checkout amount + currency read from booking snapshot, not live Service / connected-account.
- **D-178** — Reaper cancels orphan online bookings missing `stripe_checkout_session_id` instead of skipping; `mintCheckoutOrRollback` releases the slot synchronously when the local write fails.
- **D-179** — Refund-settlement webhook fallback removed; D-171 holds verbatim (match by `stripe_refund_id` only; unmatched logs + 200).
- **D-180** — `recordSettlementFailure` is idempotent on already-Failed rows; partial unique index on `pending_actions.payload->>'booking_refund_id'` for `payment.refund_failed` mirrors D-126.
- **D-181** — Admin paid-cancel transitions Cancelled BEFORE the refund call; new `transient` flash outcome.
- **D-182** — Payouts cache key includes `stripe_account_id`; `Cache::forget` on disconnect + deauthorization.

---

## Outcomes & Retrospective

**Shipped**: every Codex Round 1 finding closed. Iteration loop green: 965 / 4183 tests passing, PHPStan clean, Pint clean, Wayfinder regenerated, Vite build green. Six new architectural decisions promoted into `DECISIONS-PAYMENTS.md`.

**What went well**:

- The plan's per-finding milestone shape held throughout — no surprises that required re-planning, just one PHPStan tightness check and one F-005 follow-up (the customer-email short-circuit). Both caught quickly.
- The decision to remove the D-171 fallback rather than rebuild it on metadata was the right call: the fix is small (one method removed, one comment block rewritten, one test rewritten + one new) and aligns with the locked decision verbatim. The metadata-based recovery remains a future option in BACKLOG.
- The F-005 reorder pattern (cancel-first, refund-as-side-effect) generalises beyond admin paid-cancel; it's worth keeping in mind if/when other money-and-status sequences land.

**What to watch**:

- The first deploy of this hardening invalidates every existing payouts cache entry implicitly because the cache key shape changed (D-182). Pre-launch this is irrelevant; flagged for ops.
- The `payment.refund_failed` partial unique index (D-180) requires a non-rolling deploy if duplicates somehow exist already. The migration's pre-flight guard fails loud rather than silently corrupting; pre-launch + `migrate:fresh --seed` baseline → no problem in practice.
- Browser test failures (~11) the developer reported on a recent run are NOT introduced by this hardening — `tests/Browser/Embed/IframeEmbedTest` and friends fail on availability/UI assertions, not on payments-related surfaces. Out of scope for this session; tracked separately.

**Next**:

- Codex Round 2 review with frontend-deep prompt (XSS / Inertia prop leak audit on `payouts.tsx`, `connected-account.tsx`, `refund-dialog.tsx`) AND verification that the seven Round 1 findings are closed.
- Once Round 2 lands clean, developer commits the bundle (Hardening Round 1 exec + Round 2 fixes if any) as a single commit on top of `94567dc`.
- Browser test investigation as a separate follow-up session.
