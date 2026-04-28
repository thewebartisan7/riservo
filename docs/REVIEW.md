# Stripe Connect Payments — Adversarial Code Review
Reviewed range: b520250..HEAD (PAYMENTS Sessions 1–5)
Reviewer: codex
Date: 2026-04-28

## TL;DR
Hold. The happy path is tested, but the slice is still brittle where money state crosses process boundaries: Checkout can charge a value that no longer matches the booking snapshot, Checkout creation can leave slots held forever after a local persistence failure, and refund settlement can attach a Stripe refund to the wrong partial-refund row. Fix the Checkout/booking coupling first: the booking row must be the only money source of truth, and every post-Stripe local write needs a deterministic recovery path.

## Findings

### F-001 — Checkout charges mutable service/account state instead of the booking snapshot
**Severity:** High
**Category:** Money
**Location:** app/Http/Controllers/Booking/PublicBookingController.php:439, app/Http/Controllers/Booking/PublicBookingController.php:447, app/Http/Controllers/Booking/PublicBookingController.php:497, app/Services/Payments/CheckoutSessionFactory.php:74, app/Services/Payments/CheckoutSessionFactory.php:75, app/Services/Payments/CheckoutPromoter.php:126

**What's wrong:** The booking row captures `paid_amount_cents` and `currency` during creation, then the controller commits and calls Checkout creation afterward. Checkout creation ignores those captured columns and recalculates from the mutable `Service.price` and current connected-account currency. D-152/D-156 treat amount/currency mismatch as pathological and refuse promotion; this code can manufacture that mismatch itself.

**How to break it:** Start a public online booking. Between `Booking::create()` and `CheckoutSessionFactory::create()`, change the service price or sync the connected account to a different default currency. Stripe charges the new factory value, but the booking row still carries the old snapshot. When the webhook or success return runs, `CheckoutPromoter` hits the mismatch guard and leaves a paid booking unpromoted for manual reconciliation.

**Suggested fix:** Build the Checkout line item from `$booking->paid_amount_cents` and `$booking->currency`, not `$service->price` or `$account->default_currency`. Fail before `checkout.sessions.create` if either snapshot is null.

### F-002 — A post-Stripe DB failure can strand an awaiting-payment booking forever
**Severity:** High
**Category:** Money
**Location:** app/Http/Controllers/Booking/PublicBookingController.php:549, app/Http/Controllers/Booking/PublicBookingController.php:551, app/Http/Controllers/Booking/PublicBookingController.php:552, app/Console/Commands/ExpireUnpaidBookings.php:99, app/Console/Commands/ExpireUnpaidBookings.php:107

**What's wrong:** `mintCheckoutOrRollback()` creates the Stripe Checkout session and only then persists `stripe_checkout_session_id`. If the process dies or the DB write fails after Stripe accepts the session, the booking remains `pending + awaiting_payment` with no session id. The reaper treats a missing session id as unrecoverable and skips, so the slot is held indefinitely.

**How to break it:** Force a DB connection failure or kill the worker after `checkout.sessions.create()` returns and before `$booking->update(['stripe_checkout_session_id' => $session->id])`. The customer sees a failed booking attempt, but the slot remains blocked. Every later `bookings:expire-unpaid` tick logs and returns `skipped`.

**Suggested fix:** Catch local persistence failures after Checkout creation and release the slot. Also change the reaper policy for expired `online` bookings with no `stripe_checkout_session_id`: cancel after the grace window, because the app never returned a usable Stripe redirect without that local write.

### F-003 — Refund settlement fallback violates D-171 and can settle the wrong partial refund
**Severity:** High
**Category:** Webhook
**Location:** app/Http/Controllers/Webhooks/StripeConnectWebhookController.php:1243, app/Http/Controllers/Webhooks/StripeConnectWebhookController.php:1257, app/Http/Controllers/Webhooks/StripeConnectWebhookController.php:1322, app/Http/Controllers/Webhooks/StripeConnectWebhookController.php:1326, app/Http/Controllers/Webhooks/StripeConnectWebhookController.php:1333, docs/decisions/DECISIONS-PAYMENTS.md:914

**What's wrong:** D-171 explicitly rejected matching refund-settlement webhooks by `payment_intent`; the code does exactly that as a fallback, then picks the latest pending refund row with the same amount and backfills `stripe_refund_id`. Multiple partial refunds on the same PaymentIntent are no longer disambiguated by Stripe refund id, which is the whole point of D-171.

**How to break it:** On a CHF 50.00 booking, submit a CHF 10.00 partial refund where Stripe accepts but the response is lost, leaving row A pending with `stripe_refund_id = null`. Submit another CHF 10.00 partial refund, creating row B. When the webhook for row A's Stripe refund arrives, the fallback selects latest row B and writes row A's `stripe_refund_id` onto it. Row A stays pending, row B is marked settled for the wrong Stripe refund.

**Suggested fix:** Remove the amount-based fallback. If response-loss recovery is required, put `booking_refund_id` or the local UUID into Stripe refund metadata and match on that deterministic identifier under a row lock. Otherwise follow D-171: log unknown `stripe_refund_id` and return 200.

### F-004 — Failed-refund webhook handling is not idempotent
**Severity:** Medium
**Category:** Webhook
**Location:** app/Services/Payments/RefundService.php:453, app/Services/Payments/RefundService.php:475, app/Services/Payments/RefundService.php:549, app/Support/Billing/DedupesStripeWebhookEvents.php:41, app/Support/Billing/DedupesStripeWebhookEvents.php:52, database/migrations/2026_04_23_054441_add_dispute_id_unique_index_to_pending_actions.php:31

**What's wrong:** `recordSettlementFailure()` returns early from the transaction when the refund row is already `Failed`, but the method still proceeds to upsert a Pending Action and dispatch the admin email. Cache dedupe is check-then-put after the handler, so concurrent duplicates can both run; different Stripe event ids for the same refund object bypass cache dedupe entirely. The only payment Pending Action partial unique index is for disputes, not refund failures.

**How to break it:** Deliver `refund.updated` and `charge.refund.updated` for the same failed refund, or race two deliveries of the same event before the cache key is written. The first marks the row failed; the second sees the failed row, returns from the DB transaction, then still sends another refund-failed email. Under a PA creation race, both requests can also miss the `first()` lookup and insert duplicate `payment.refund_failed` actions.

**Suggested fix:** Make the transaction return `didTransition`. Only create/update the Pending Action and send email when the row changed from non-terminal to `Failed`. Add a partial unique index for `type = 'payment.refund_failed'` on `payload->>'booking_refund_id'` and catch the loser like the dispute path.

### F-005 — Admin cancellation refunds before the booking is cancelled
**Severity:** High
**Category:** Money
**Location:** app/Http/Controllers/Dashboard/BookingController.php:394, app/Http/Controllers/Dashboard/BookingController.php:407, app/Http/Controllers/Dashboard/BookingController.php:410, app/Services/Payments/RefundService.php:247, app/Services/Payments/RefundService.php:305

**What's wrong:** Paid-booking cancellation calls Stripe refund first, records local refund success, refreshes the booking, and only then updates `status` to cancelled. The external refund is irreversible; the local cancellation is a later, separate write with no recovery marker.

**How to break it:** Admin cancels a paid confirmed booking. Stripe accepts the refund and `RefundService` records the row as succeeded. Kill the worker before `$booking->update(['status' => $newStatus])`. The customer has been refunded, but the booking can remain pending/confirmed and keep the slot/calendar semantics alive.

**Suggested fix:** Introduce a cancellation intent/refund-pending state or transition to cancelled in the same local transaction that creates the refund attempt before calling Stripe. On Stripe failure, leave a `payment.refund_failed` action; do not keep the appointment active after money has been sent back.

### F-006 — Payout cache leaks stale connected-account financial data after reconnect
**Severity:** High
**Category:** Lifecycle
**Location:** app/Http/Controllers/Dashboard/PayoutsController.php:145, app/Http/Controllers/Dashboard/PayoutsController.php:152, app/Http/Controllers/Dashboard/PayoutsController.php:157, app/Http/Controllers/Dashboard/PayoutsController.php:158, app/Http/Controllers/Dashboard/PayoutsController.php:318, app/Http/Controllers/Dashboard/Settings/ConnectedAccountController.php:686

**What's wrong:** The payouts payload is cached as `payouts:business:{id}`. It is not keyed by `stripe_account_id`, and disconnect does not clear it. After a disconnect/reconnect, the page can render the new active account's health next to the old account's cached balances and payout rows.

**How to break it:** Open `/dashboard/payouts` for `acct_old` to populate cache. Disconnect Stripe and connect `acct_new`. Open `/dashboard/payouts` within the freshness window. `fetchPayoutsPayload()` returns the business-keyed cached payload without hitting Stripe, so the admin sees `acct_old` balances/payout history under `acct_new` account metadata.

**Suggested fix:** Key payout cache by both business id and `stripe_account_id`, or store the account id in the payload and reject cache entries whose account id differs from the current row. Forget payout caches on disconnect, deauthorization, and any active account-id change.

### F-007 — Payment migration rollback leaves `stripe_connected_account_id` behind
**Severity:** Medium
**Category:** Migration
**Location:** database/migrations/2026_04_23_174426_add_payment_columns_to_bookings.php:53, database/migrations/2026_04_23_174426_add_payment_columns_to_bookings.php:71

**What's wrong:** The migration adds `bookings.stripe_connected_account_id`, but `down()` omits that column from the `dropColumn()` list. D-133 was specifically about rollback correctness; this rollback is not correct.

**How to break it:** Run the payments migrations, roll back through `2026_04_23_174426_add_payment_columns_to_bookings`, then migrate forward again. The old `stripe_connected_account_id` column is still present, so the migration attempts to add an existing column.

**Suggested fix:** Add `stripe_connected_account_id` to the `dropColumn()` list and add a migration rollback assertion for the payments-era schema.

## Coverage Gaps

- No test asserts that Checkout line-item `unit_amount` and `currency` come from the booking's captured `paid_amount_cents` / `currency` after the underlying service price or account currency changes.
- No test simulates `checkout.sessions.create()` succeeding followed by a failed local `stripe_checkout_session_id` write, and no test asserts the reaper cancels expired online bookings with missing session ids.
- No test covers two same-amount pending partial refunds where the first Stripe refund is accepted but its synchronous response is lost before settlement webhooks arrive.
- No test asserts that `recordSettlementFailure()` on an already-failed refund row does not create/update Pending Actions or send admin notifications.
- No test covers payout cache invalidation or account-id scoping across disconnect/reconnect.
- No migration rollback test checks that `stripe_connected_account_id` is removed from `bookings`.
- No browser test covers the new payouts, refund dialog, or connected-account settings surfaces; this is acknowledged in the prompt, but it leaves the money UI unexercised outside PHP/Inertia prop tests.

## D-ID Drift

- `D-171` — Code falls back to `payment_intent + amount` matching for refund-settlement webhooks even though the locked decision requires `stripe_refund_id` matching only.
- `D-133` — The payments column rollback leaves `bookings.stripe_connected_account_id` behind.
- `D-152 / D-156` — The booking snapshot is supposed to be the stable amount/currency contract, but Checkout creation re-reads mutable service/account state and can create the mismatch that the promoter later treats as a critical refusal.

## What I Did Not Cover

- I did not review auth, scheduling, calendar sync, or generic Cashier subscription billing beyond routes and shared tests that intersect the payments slice.
- I did not run the Browser suite, per instruction, and I did not execute the manual Stripe end-to-end walkthrough.
- Verification run: `php artisan route:list --path=webhooks`, `php artisan route:list --path=stripe`, `php artisan test tests/Feature tests/Unit --compact` (963 passed), `./vendor/bin/phpstan` (no errors), and `vendor/bin/pint --test --format agent` (pass).
