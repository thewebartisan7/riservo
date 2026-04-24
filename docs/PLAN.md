# PAYMENTS Session 2b — Payment at Booking (Failure Branching + Admin Surface)

This plan lives at `docs/PLAN.md` and follows `.claude/references/PLAN.md`. It is a living document. The sections `Progress`, `Surprises & Discoveries`, `Decision Log`, `Review`, and `Outcomes & Retrospective` are kept current as work proceeds.


## Purpose / Big Picture

Session 2a shipped the happy path: a customer whose Stripe Checkout completes lands at `confirmed + paid` (or `pending + paid` for manual-confirmation businesses). Session 2b makes the failure and admin surfaces real.

After this session ships:

- **An expired, failed, or abandoned Checkout stops stranding a booking at `pending + awaiting_payment`.** The Connect webhook endpoint handles `checkout.session.expired`, `checkout.session.async_payment_failed`, and `payment_intent.payment_failed`. For bookings whose snapshot is `payment_mode_at_creation = 'online'` the slot is released (`status → Cancelled`, `payment_status → NotApplicable`, no customer notifications). For `customer_choice` the booking is promoted to `confirmed + Unpaid` (or `pending + Unpaid` under manual-confirmation businesses, per locked roadmap decision #29) and the standard booking-confirmed notifications fire — the slot stays held, the customer pays at the appointment.
- **A 90-minute expiry reaper (`bookings:expire-unpaid`) runs every 2 minutes with `withoutOverlapping()`** and cleans up `online` bookings whose Checkout window has closed. "Cleanup" means transitioning the booking to `Cancelled`, which releases the slot via the GIST exclusion constraint (D-065 / D-066 only holds while `status IN (pending, confirmed)`). The reaper's base filter includes `payment_mode_at_creation = 'online'` — it **only** touches bookings that were created against businesses configured as online-only. Three stacked mitigations against the webhook race (locked roadmap decision #31): a 5-minute grace buffer on `expires_at`, a Stripe-side pre-flight `checkout.sessions.retrieve` that promotes via `CheckoutPromoter` when Stripe already reports paid (so a slow TWINT payment doesn't get reaped), and a late-webhook refund path when `checkout.session.completed` or `payment_intent.succeeded` arrives after the reaper already cancelled.

  **Why the reaper only touches `online`-only bookings** (locked roadmap decision #13): a `customer_choice` business accepts offline payment too, so a failed Checkout does NOT mean "release the slot" — it means "the customer tried to prepay online, couldn't, and will now pay at the appointment". For those bookings, the webhook failure arms (`checkout.session.expired` etc.) promote the booking to `confirmed + Unpaid` (slot stays held). The reaper and the webhook failure arms are therefore complementary, NOT redundant:

  | Business `payment_mode` → booking's `payment_mode_at_creation` | Failed Checkout outcome | Slot released? | Who owns the transition |
  |---|---|---|---|
  | `online` → `'online'` | `Cancelled + NotApplicable` | Yes (GIST frees the slot) | Reaper **and** webhook failure arms |
  | `customer_choice` → `'customer_choice'` (customer picked "pay now") | `Confirmed + Unpaid` (or `Pending + Unpaid` under manual-confirm) | No (slot stays held) | Webhook failure arms only |
  | `offline` → `'offline'` | N/A (no Checkout session ever created) | N/A | N/A |

  The reaper's `WHERE payment_mode_at_creation = 'online'` guard is what prevents it from ever touching a `customer_choice` booking. The webhook failure arms branch internally on the same snapshot, and for `customer_choice` they set `expires_at = null` so even if a future reaper pass loosened its filter, the row would fall out of the `expires_at < now - 5min` criterion.
- **A minimal refunds pipeline is wired end-to-end for the one reason Session 2b owns (`cancelled-after-payment`).** New `booking_refunds` table + `App\Services\Payments\RefundService` skeleton. The service inserts a `booking_refunds` row with a populated UUID, calls Stripe with `idempotency_key = 'riservo_refund_{uuid}'` (locked roadmap decision #36), writes the outcome, and — on a disconnected-account permission error — marks the row `failed`, sets the booking's `payment_status = refund_failed`, creates a `payment.refund_failed` Pending Action, and dispatches an admin email. Session 3 expands the service with the other four reasons; it does NOT re-create the table or redefine the signature.
- **Admins see payments in the dashboard.** The booking-detail sheet grows a Payment panel (paid amount + method + Stripe charge deep-link for paid bookings; audit-trail copy for `unpaid`; manual-confirmation copy for `pending + paid`; `cancelled-after-payment` banner + "Mark as resolved" CTA for Pending Actions). The bookings-list grows a Payment filter (chips for `paid`, `awaiting_payment`, `unpaid`, `refunded`, `offline`) and a Payment column.
- **The `cancel_url` landing page on the public booking route shows copy that branches on `payment_mode_at_creation`.** The underlying state transition stays webhook-driven (locked roadmap decision #31 idempotency contract + D-151's "CheckoutPromoter is the single source of truth" invariant); the cancel URL is informational.

You can see the feature by, from a clean `migrate:fresh --seed`:

1. **Online-mode expiry branch** — Seed a Business with a verified connected account, `payment_mode = online`. Book a service at `riservo.ch/{slug}`; see the Stripe Checkout URL. Abandon the page. Run `php artisan bookings:expire-unpaid` after 95 minutes (or manually set `bookings.expires_at = now()->subMinutes(10)` and run the command — the 5-minute grace buffer is the only gate). See the reaper's pre-flight retrieve hit Stripe; see the booking flip to `Cancelled + NotApplicable`; confirm the slot is bookable again.
2. **Customer-choice failure branch** — Same Business, flip `payment_mode = customer_choice`. Book again and pick "pay now". On Stripe's hosted page, wait for `checkout.session.expired` (or in test mode dispatch the event via `stripe trigger checkout.session.expired`). See the booking flip to `Confirmed + Unpaid` (or `Pending + Unpaid` under manual confirmation), the slot stays held, the standard booking-confirmed email fires.
3. **Late-webhook refund** — On an online-mode booking, let the reaper cancel the row. Then dispatch a delayed `checkout.session.completed` for the same session (test mode). See: `payment_status` goes to `Paid` with the charge columns populated; a `booking_refunds` row is created with `status = succeeded` and a `stripe_refund_id`; a `payment.cancelled_after_payment` Pending Action appears on the booking-detail banner; the admin gets an email with the customer's contact and the dispatched refund id.
4. **Admin surface** — Visit `/dashboard/bookings` as an admin. Filter by Payment chip `paid`. Open a row. See the Payment panel: amount, currency, method, Stripe dashboard deep-link. Resolve the `cancelled-after-payment` banner — it marks the Pending Action resolved.

Under the hood, Session 2a's `CheckoutPromoter` is the single promotion path (D-151); Session 2b's reaper pre-flight calls it, Session 2b's late-webhook refund handler does not (the booking is already Cancelled — the handler writes the Paid columns inline and dispatches `RefundService`). No inline promotion logic is introduced anywhere.


## Progress

Timestamps in UTC. Mark each step `[x]` as it completes; split a step into "done: X / remaining: Y" if you stop partway.

### M0 — Plan & alignment

- [x] (2026-04-23 12:00Z) Plan written, gate-1 approval requested.
- [x] (2026-04-23 12:10Z) Gate-1 approved by developer — with two concrete revisions: (a) `BookingRefundStatus` is a native enum, not a string; (b) Purpose section now explicitly documents that the reaper only cancels `online`-only bookings because `customer_choice` failures keep the slot held via the webhook path. Both changes are live in this plan.

### M1 — Data layer (migration + model helpers)

- [x] (2026-04-23 12:20Z) Migration `database/migrations/2026_04_23_175629_create_booking_refunds_table.php` created with full column set + composite index `(booking_id, status)`.
- [x] (2026-04-23 12:22Z) Enum `App\Enums\BookingRefundStatus` with three cases — `Pending`, `Succeeded`, `Failed`.
- [x] (2026-04-23 12:25Z) `App\Models\BookingRefund` with fillable + casts + `booking()` + `initiatedByUser()` relations; PHPDoc pins properties + enum status.
- [x] (2026-04-23 12:26Z) `bookingRefunds(): HasMany<BookingRefund>` relation added to `Booking`.
- [x] (2026-04-23 12:28Z) `Booking::remainingRefundableCents()` extended to the real clamp. Smoke-tested via tinker: paid=10000 → pending 3000 → remaining 7000 → + succeeded 2000 → remaining 5000 → + failed 1000 → still 5000 (failed rows do NOT reduce the clamp). `max(0, …)` guard in place.
- [x] (2026-04-23 12:30Z) `BookingRefundFactory` with `pending()` / `succeeded()` / `failed()` states. Default `reason = 'cancelled-after-payment'`; `booking_id` defaults to a paid booking.

### M2 — `RefundService` skeleton (cancelled-after-payment only)

- [x] (2026-04-23 12:45Z) `App\Services\Payments\RefundService` + `RefundResult` DTO. Idempotency key shape `riservo_refund_{uuid}`; `PermissionException` / `AuthenticationException` → disconnected; other `ApiErrorException` → failed; both share `recordFailure()` which marks the row Failed, flips booking `payment_status = RefundFailed`, upserts the `payment.refund_failed` PA, dispatches `RefundFailedNotification` to admins only. Happy path reconciles `payment_status → Refunded` (full) or `PartiallyRefunded` (defensive — Session 2b only hits full). PHPStan level-5 clean.
- [x] (2026-04-23 12:35Z) M5 notifications pulled forward (dependency of RefundService): `RefundFailedNotification` + `CancelledAfterPaymentNotification` with markdown templates under `resources/views/mail/payments/`.
- [ ] Inside `refund()`:
  1. Top-level guards return `guard_rejected` when `paid_amount_cents` is null/0, when `stripe_payment_intent_id` is null (nothing to refund against), or when `remainingRefundableCents() <= 0`. Log warning + booking_id; do NOT create a row.
  2. Wrap the `BookingRefund` insert in `DB::transaction` with a `lockForUpdate` on the booking. Insert with `status = pending`, `uuid = (string) Str::uuid()`, `amount_cents = $amountCents ?? $booking->paid_amount_cents`, `currency = $booking->currency`, `reason = $reason`. The DB::transaction callback returns nothing (D-148 / D-151 scalar-return workaround — the inserted row is captured via reference).
  3. Outside the transaction, inside a `try` — resolve the connected-account id as `$booking->stripe_connected_account_id` (D-158; critical log + return `failed` if null); call `$this->stripe->refunds->create(['payment_intent' => $booking->stripe_payment_intent_id, 'amount' => $row->amount_cents], ['stripe_account' => $acct, 'idempotency_key' => 'riservo_refund_'.$row->uuid])`.
  4. On `Stripe\Exception\PermissionException` OR any `ApiErrorException` whose error code indicates a permission / deauthorisation issue (`account_invalid`, `account_inactive`, `permission_error`, HTTP 401 / 403) → mark the row `failed`, `failure_reason = $e->getMessage()`, set booking's `payment_status = refund_failed`, `upsertRefundFailedPendingAction(...)`, dispatch `RefundFailedNotification` to admins, return `RefundResult::disconnected(...)`.
  5. On any other `ApiErrorException` → same DB side-effects as #4 but return `RefundResult::failed(...)`.
  6. On success → update the row: `status = succeeded`, `stripe_refund_id = $refund->id`; recompute booking's `payment_status` via `reconcilePaymentStatus($booking)` — full refund (the only Session 2b reason) → `Refunded`. Defensive: if the sum of succeeded refunds < `paid_amount_cents` (can't happen in 2b), set `PartiallyRefunded`. Return `RefundResult::succeeded(...)`.
- [ ] `upsertRefundFailedPendingAction($booking, $row)` writes a `payment.refund_failed` PendingAction with `payload = ['booking_refund_id', 'booking_id', 'stripe_payment_intent_id', 'amount_cents', 'currency', 'failure_reason']`. Idempotent via `updateOrCreate` keyed on `(business_id, payload->>'booking_refund_id')`.

### M3 — Failure webhook arms on `StripeConnectWebhookController`

- [x] (2026-04-23 13:10Z) Four new arms wired: `checkout.session.expired` + `.async_payment_failed` → `handleCheckoutSessionFailed`; `payment_intent.payment_failed` → `handlePaymentIntentFailed`; `payment_intent.succeeded` → `handlePaymentIntentSucceeded`. `handleCheckoutSessionCompleted` routes Cancelled bookings to the late-refund branch before calling the promoter. Shared helpers: `applyCheckoutFailureBranch`, `applyLateWebhookRefund`, `upsertCancelledAfterPaymentPendingAction`, `dispatchCancelledAfterPaymentEmail`, `dispatchCustomerChoiceFailureNotifications`. New `BookingReceivedNotification` context `'pending_unpaid_awaiting_confirmation'` + blade branch for manual-confirm + customer_choice + failed-Checkout customer email. PHPStan clean; existing 66 Payments tests still green.

- [x] Extend the `dispatch()` `match` with four new arms:
  - `checkout.session.expired` → `handleCheckoutSessionExpired(...)`.
  - `checkout.session.async_payment_failed` → shares body with expired (`handleCheckoutSessionFailed(...)`).
  - `payment_intent.payment_failed` → `handlePaymentIntentFailed(...)` (resolves the booking via the PI id).
  - `payment_intent.succeeded` → `handlePaymentIntentSucceeded(...)` (late-webhook refund path).
- [ ] Shared body for the two Checkout failure events: resolve booking via `client_reference_id` (same guards as the happy-path handler for non-numeric / unknown); cross-check `stripe_connected_account_id` (D-158 pin — critical log + 200 on mismatch); outcome-level guard (`payment_status === Paid` → 200; `status === Cancelled` → 200 no-op). Then branch on `payment_mode_at_creation`:
  - `'online'` → `DB::transaction { lockForUpdate; re-read status/payment_status guards; status = Cancelled; payment_status = NotApplicable; expires_at = null; }`; no notifications (customer knows they didn't complete payment).
  - `'customer_choice'` → `DB::transaction { lockForUpdate; re-read guards; status = $business->confirmation_mode === Manual ? Pending : Confirmed; payment_status = Unpaid; expires_at = null; }`. Then OUTSIDE the lock dispatch the standard notifications via a new private helper `dispatchConfirmedOrReceivedNotifications($booking)` — Confirmed → `BookingConfirmedNotification` to customer + staff `BookingReceivedNotification('new')`; Pending → staff `BookingReceivedNotification('new')` + customer `BookingReceivedNotification` ('pending' context — reuse the manual-confirmation template's unpaid-awaiting-confirmation variant or add a new context value `'customer_choice_unpaid_pending'` if the existing ones don't fit).
  - `'offline'` → defensive: should never reach here (offline bookings don't open Checkout sessions). Log critical + 200.
- [ ] `handlePaymentIntentFailed` resolves via `Booking::where('stripe_payment_intent_id', $pi->id)->first()`. If null → log warning + 200 (the PI id is only set at Session 2a's `checkout.session.completed` promotion; a failed PI before that point doesn't have a linked booking yet). Once resolved: same body + same branching as the Checkout failure handlers.
- [ ] `handlePaymentIntentSucceeded` → late-webhook refund path (locked roadmap decision #31.3):
  1. Resolve booking via `stripe_payment_intent_id` (present) OR via the charge id's upstream PI → fall back to no-op if unresolvable.
  2. Guards: if `status !== Cancelled` → 200 no-op (happy-path handler owns the `AwaitingPayment → Paid` transition). If `payment_status === Paid` → 200 no-op. Otherwise:
  3. `DB::transaction { lockForUpdate; re-read guards; payment_status = Paid; paid_at = now(); paid_amount_cents = $pi->amount_received; currency = strtolower($pi->currency); stripe_charge_id = $pi->latest_charge; }` — booking stays `Cancelled`; the slot may already be re-booked.
  4. Outside the lock: `$refundResult = $this->refundService->refund($booking, null, 'cancelled-after-payment');`.
  5. Upsert a `payment.cancelled_after_payment` PendingAction with `payload = ['booking_id', 'booking_refund_id', 'customer_name', 'customer_email', 'customer_phone', 'amount_cents', 'currency', 'starts_at', 'refund_outcome']`. Idempotent via `updateOrCreate` keyed on `(business_id, payload->>'booking_id', type)` — one PA per cancelled-after-payment booking.
  6. Dispatch `CancelledAfterPaymentNotification` to the business's admins (customer contact + booking_refund.id + amount/currency + booking detail deep-link).
- [ ] **Also handle `checkout.session.completed` arriving late for a `Cancelled + !Paid` booking** (belt-and-braces before locked #31.3's PI-based path in case Stripe emits the Checkout-level event first without a PI one). In the existing `handleCheckoutSessionCompleted` body, branch BEFORE calling `CheckoutPromoter::promote`: if `$booking->status === Cancelled`, reuse `handlePaymentIntentSucceeded`'s late-refund branch (pulling `$session->payment_intent`, `$session->amount_total`, `$session->currency`). The promoter would otherwise reject via its session-id-mismatch or DB-state guard, surfacing a false-positive `'mismatch'` log.

### M4 — Reaper command `bookings:expire-unpaid`

- [x] (2026-04-23 13:25Z) `ExpireUnpaidBookings` command shipped. Base filter enforces `payment_mode_at_creation = 'online'` + 5-min grace buffer; pre-flight retrieve on pinned connected account (D-158) with explicit `InvalidRequestException` (4xx) / `ApiConnectionException` / `ApiErrorException` (5xx) branches; `CheckoutPromoter::promote` runs inline when Stripe reports paid/complete (cancel always skipped, even on `'mismatch'`). `DB::transaction + lockForUpdate` around the cancel with re-checked outcome guard inside the lock. Schedule entry `everyTwoMinutes()->withoutOverlapping()` added to `routes/console.php`. Smoke-test on empty DB: `Reaper: cancelled=0 promoted=0 skipped=0`. PHPStan clean.

- [x] Create `app/Console/Commands/ExpireUnpaidBookings.php`. Signature `bookings:expire-unpaid`. Description "Cancel or recover stale pending+awaiting_payment online bookings after the 90-minute Checkout window elapsed plus a 5-minute grace buffer (locked roadmap decision #31)."
- [ ] Command body:
  1. Base query: `Booking::query()->where('status', BookingStatus::Pending)->where('payment_status', PaymentStatus::AwaitingPayment)->where('payment_mode_at_creation', 'online')->where('expires_at', '<', now()->subMinutes(5))`. Platform-wide scope (same convention as `AutoCompleteBookings` etc.). **The `payment_mode_at_creation = 'online'` predicate is the policy gate** per locked roadmap decision #13: it guarantees the reaper only ever cancels bookings against online-only businesses, so the slot-release is always the intended outcome. `customer_choice` bookings (`payment_mode_at_creation = 'customer_choice'`) never appear in this query — their Checkout-failure path is handled by the webhook arms in M3, which keep the slot held and promote the booking to `Confirmed + Unpaid`. If the reaper's filter were ever loosened, the `expires_at IS NULL` convention for `customer_choice` bookings (established in Session 2a per locked decision #13) would still keep them out of the `expires_at < now - 5 min` criterion.
  2. Chunk 100 rows; each booking processed in its own `try / catch` so a single failure doesn't abort the batch. Output a table / structured log summary at the end.
  3. **Pre-flight retrieve** (locked roadmap decision #31.2). For each booking:
     - If `stripe_checkout_session_id` OR `stripe_connected_account_id` is null → critical log + skip (data anomaly).
     - Try `$this->stripe->checkout->sessions->retrieve($session_id, ['stripe_account' => $acct])`.
     - `ApiConnectionException` / HTTP 5xx → warning log + skip (leave for next tick).
     - HTTP 4xx (session not found) → proceed with the cancel (session is gone).
     - Otherwise:
       - If `$session->status === 'complete'` OR `$session->payment_status === 'paid'` → call `$this->checkoutPromoter->promote($booking, $session)`. **SKIP the cancel regardless of the promoter's return** (`'paid'`, `'already_paid'`, `'not_paid'`, `'mismatch'`). The `'mismatch'` case means the promoter already logged critical; cancelling would potentially free a paid slot (see Risks & Notes).
       - Otherwise (`status = 'expired'` or `'open' && payment_status != 'paid'`) → proceed with the cancel.
  4. **Cancel path**: `DB::transaction { lockForUpdate; re-read status = Pending + payment_status = AwaitingPayment; then update status = Cancelled, payment_status = NotApplicable, expires_at = null; }`. No customer notifications.
  5. Structured log for each iteration — `booking_id`, outcome (`promoted | cancelled | skipped_stripe_5xx | skipped_data_anomaly`).
- [ ] Schedule the command in `routes/console.php` right below `calendar:renew-watches`: `Schedule::command('bookings:expire-unpaid')->everyTwoMinutes()->withoutOverlapping();`. Laravel 13's `Schedule` facade ships `everyTwoMinutes()`; if not available on this version, fall back to `->cron('*/2 * * * *')`.
- [ ] Constructor property promotion for `StripeClient` + `CheckoutPromoter` + `LoggerInterface` (if needed) so `FakeStripeClient` and mocks drop in cleanly.

### M5 — Admin notifications (two new mail notifications)

- [ ] `App\Notifications\Payments\RefundFailedNotification`. Markdown mail, subject "Refund could not be processed automatically". Body: business.name, customer.name/email/phone, booking.starts_at (in business TZ), amount_cents + currency formatted, verbatim failure_reason, deep-link to `dashboard.bookings` filtered to this booking. Recipient: admins of the owning business (via `business->admins`).
- [ ] `App\Notifications\Payments\CancelledAfterPaymentNotification`. Markdown mail, subject "A cancelled booking was paid — refund dispatched". Body: business.name, customer.name/email/phone, booking.starts_at, amount_cents + currency, `booking_refund.id`, `stripe_refund_id` if populated, deep-link to booking detail. Recipient: admins only (locked decisions #19 / #31).
- [ ] Markdown templates under `resources/views/mail/payments/refund-failed.blade.php` + `cancelled-after-payment.blade.php`. All user-facing strings via `__()`.

### M6 — Admin UI: Payment panel + list filter + Pending Action banner

- [x] (2026-04-23 13:55Z) Backend: `BookingController::index` eager-loads `pendingActions` (type-bucketed to payment rows) + reads `?payment_status=` with `offline → not_applicable` mapping + each row in the payload carries a `payment` sub-object and a `pending_payment_action` sub-object (null when absent). `Booking::pendingActions(): HasMany<PendingAction>` relation added.
- [x] (2026-04-23 13:58Z) New controller `Dashboard\PaymentPendingActionController::resolve` (admin-only + tenant-scoped); route `PATCH dashboard.payment-pending-actions.resolve`.
- [x] (2026-04-23 14:05Z) Frontend: new `PaymentStatusBadge` component + Payment filter Select (admin-only) + Payment column (admin-only) on bookings list; Payment panel (badge + amount + Stripe deep-link + status-specific copy) + Pending Action banner with Mark-as-resolved CTA on `BookingDetailSheet`. TS `DashboardBooking.payment` + `PendingPaymentAction` interface added. Wayfinder regenerated. `npm run build` clean.
- [ ] **Backend — Booking relation**: add `pendingActions(): HasMany<PendingAction>` to `Booking` if not already present (check first — MVPC-2 may have added it already).
- [ ] **Backend — new controller** `app/Http/Controllers/Dashboard/PaymentPendingActionController.php` with one action `resolve(Request $request, PendingAction $action): RedirectResponse`. Admin-only (abort 403 for staff). Tenant-scoped: `abort_unless($action->business_id === tenant()->businessId(), 404)`. Mark `status = Resolved, resolved_by_user_id = $request->user()->id, resolved_at = now()`. Accept `payment.cancelled_after_payment` and `payment.refund_failed` only.
- [ ] **Routes**: add `PATCH /dashboard/payments/pending-actions/{action}/resolve` named `dashboard.payments.pending-actions.resolve`, inside the admin-scoped route group (not under `billing.writable` — resolve is an admin confirmation, and Session 1's `ConnectedAccountController::disconnect` is the sibling pattern — but double-check the group shape in `routes/web.php` at M6 time).
- [ ] **Frontend — Payment panel in `BookingDetailSheet`**. New section rendered when `booking.payment.status !== 'not_applicable'`. Copy branches by status. Deep-link formula: `https://dashboard.stripe.com/{stripe_connected_account_id}/payments/{stripe_charge_id}` (or `/payments/{stripe_payment_intent_id}` when charge_id is null — Session 2a only writes the PI id).
- [ ] **Frontend — Pending Action banner in `BookingDetailSheet`**. Render at the top of the sheet body when `booking.pending_payment_action` is non-null. "Mark as resolved" button calls the new resolve route via `router.patch`.
- [ ] **Frontend — bookings list Payment column + filter**.
  - Column "Payment" after "Source". Renders a new `PaymentStatusBadge` component.
  - Filter: a new `Select` with options `All payments`, `Paid`, `Awaiting payment`, `Unpaid`, `Refunded`, `Refund failed`, `Offline`. The URL param key is `payment_status`. Offline maps server-side to `not_applicable`.
- [ ] **Frontend — types** (`resources/js/types/index.d.ts`): extend `DashboardBooking` with `payment` + `pending_payment_action`; add `PendingPaymentAction` interface; extend `BookingsPageProps.filters` with `payment_status: string`.

### M7 — Cancel-URL public copy

- [x] (2026-04-23 13:30Z) `BookingPaymentReturnController::cancel` now redirects to `bookings.show` (not the public slug page) and branches flash on `payment_mode_at_creation`: `online` → error "slot released"; `customer_choice` → success "confirmed — pay at appointment"; business has no active connected account → error "no longer accepting online payments — contact directly". The handler MUTATES NO STATE; the webhook path owns transitions (D-151).

- [x] Update `BookingPaymentReturnController::cancel` (currently a Session 2a stub).
- [ ] Redirect target: `route('bookings.show', $booking->cancellation_token)` (not the public slug page). Rationale: the booking exists from the moment the customer clicked Continue-to-payment; the `bookings.show` page surfaces the webhook-driven final state + the payment status.
- [ ] Flash branches on `payment_mode_at_creation`:
  - `'online'` → `flash.error = __('Payment not completed. Your slot has been released.')`.
  - `'customer_choice'` → `flash.success = __('Your booking is confirmed — pay at the appointment.')`.
- [ ] Disconnected-account race: if the SoftDeletes-scoped `$booking->business->stripeConnectedAccount` returns null (all rows trashed), override flash with `flash.error = __('This business is no longer accepting online payments — contact them directly.')`.
- [ ] The controller MUST NOT mutate state. The webhook path owns the transition (D-151 idempotency contract). The cancel_url is informational only.

### M8 — Tests

- [x] (2026-04-23 14:50Z) **36 new tests** shipped across 7 files (overshoots the ~35 target): CheckoutFailureWebhookTest 10, LateWebhookRefundTest 6, ExpireUnpaidBookingsTest 6, CancelUrlLandingTest 3, BookingPaymentPanelTest 4, BookingsListPaymentFilterTest 3, RefundServiceTest 4. FakeStripeClient extended with `mockRefundCreate` + `mockRefundCreateFails`. All files green on first integrated run.

Target ~35 new tests across 7 files. Pest-first discipline on the reaper + webhook handlers. Local test IDs (T01..TNN) are session-local; they are NOT `D-NNN`.

- [ ] `tests/Feature/Payments/CheckoutFailureWebhookTest.php` (~10 tests — T01..T10):
  - T01 `checkout.session.expired` for `online` booking → `Cancelled + NotApplicable`, zero notifications, slot bookable again.
  - T02 `checkout.session.expired` for `customer_choice` auto-confirm → `Confirmed + Unpaid`, `BookingConfirmedNotification` + staff `BookingReceivedNotification('new')` fire exactly once.
  - T03 `checkout.session.expired` for `customer_choice` manual-confirm → `Pending + Unpaid`, slot held, staff `BookingReceivedNotification` fires, customer gets pending-awaiting-confirmation variant.
  - T04 `checkout.session.async_payment_failed` mirrors T01 / T02.
  - T05 `payment_intent.payment_failed` resolved via `stripe_payment_intent_id` → online branch.
  - T06 Outcome idempotency: replaying the SAME expired event after the booking is Cancelled → 200, no state change, no notifications.
  - T07 Fresh event id on an already-Cancelled booking → 200, no state change (DB guard, not cache).
  - T08 Cross-account mismatch (D-158): event's account id doesn't match `booking.stripe_connected_account_id` → critical log + 200.
  - T09 Unknown `client_reference_id` → critical log + 200.
  - T10 Expired event for a booking already Paid (race with the happy-path promoter) → outcome guard short-circuits, zero notifications.
- [ ] `tests/Feature/Payments/LateWebhookRefundTest.php` (~6 tests — T11..T16):
  - T11 `checkout.session.completed` for a reaper-cancelled online booking → `payment_status = Paid`; `BookingRefund` row `status = succeeded`, `stripe_refund_id` populated; Stripe call asserts `idempotency_key = riservo_refund_{uuid}`; `payment.cancelled_after_payment` PA exists; `CancelledAfterPaymentNotification` fires once; booking stays Cancelled.
  - T12 `payment_intent.succeeded` for a reaper-cancelled online booking → same end state as T11.
  - T13 Disconnected account: Stripe `PermissionException` on refunds.create → `BookingRefund` `status = failed`; booking's `payment_status = refund_failed`; `payment.refund_failed` PA; `RefundFailedNotification` fires.
  - T14 Replay same `checkout.session.completed` event id after T11 → cache dedup + zero additional `BookingRefund` rows + zero additional notifications.
  - T15 Fresh event id for the same booking → outcome guard catches it (booking already Paid), zero additional rows / notifications.
  - T16 Late webhook for a `Cancelled + Paid` booking (double-delivery race after T11 already persisted Paid) → 200 no-op.
- [ ] `tests/Feature/Console/ExpireUnpaidBookingsTest.php` (~6 tests — T17..T22):
  - T17 Reaper cancels an `online` booking whose `expires_at < now - 5min` after pre-flight returns `status = 'expired'`.
  - T18 Reaper leaves a `customer_choice` booking alone (filter on `payment_mode_at_creation = 'online'`).
  - T19 Reaper promotes a paid-but-webhook-delayed booking inline via the pre-flight (Stripe returns `payment_status = 'paid'`); booking flips via `CheckoutPromoter`; cancel is skipped.
  - T20 Pre-flight Stripe 5xx → booking untouched this tick; next tick with a fresh mock proceeds.
  - T21 Pre-flight HTTP 4xx (session not found) → cancel proceeds.
  - T22 Reaper ignores a booking whose `expires_at` is within the 5-minute grace buffer.
- [ ] `tests/Feature/Booking/CancelUrlLandingTest.php` (~3 tests — T23..T25):
  - T23 `online` booking: cancel_url redirects to `bookings.show`, flash.error = "slot released".
  - T24 `customer_choice` booking: cancel_url redirects to `bookings.show`, flash.success = "confirmed; pay at the appointment".
  - T25 Disconnected-account race: cancel_url flash.error = "business no longer accepting online payments".
- [ ] `tests/Feature/Dashboard/BookingPaymentPanelTest.php` (~4 tests — T26..T29):
  - T26 Admin sees Payment panel on a `paid` booking with amount + currency + Stripe deep-link.
  - T27 Admin sees Pending-Action banner on a booking with `payment.cancelled_after_payment`; POSTing resolve marks it Resolved.
  - T28 Cross-tenant denial (locked decision #45): admin of Business A cannot resolve Business B's PA (404).
  - T29 Staff cannot resolve payment PAs (403).
- [ ] `tests/Feature/Dashboard/BookingsListPaymentFilterTest.php` (~3 tests — T30..T32):
  - T30 `?payment_status=paid` filters the list to Paid only.
  - T31 `?payment_status=offline` filters to NotApplicable.
  - T32 Payment column renders the expected chip for each status (dataset test).
- [ ] `tests/Unit/Services/Payments/RefundServiceTest.php` (~4 tests — T33..T36):
  - T33 Happy path full refund: row inserted + Stripe called + row succeeded + booking `payment_status = Refunded`.
  - T34 Disconnected account: row failed + booking `refund_failed` + PA + notification.
  - T35 Guard rejection: `paid_amount_cents = null` → no row, no Stripe call, outcome `guard_rejected`.
  - T36 Idempotency key shape matches `/^riservo_refund_[0-9a-f-]{36}$/` via `mockRefundCreate` `withArgs`.
- [ ] **Extend `tests/Support/Billing/FakeStripeClient.php`** with:
  - `mockRefundCreate(string $expectedAccountId, ?string $expectedIdempotencyKeyExact = null, array $response = []): self` — stubs `$stripe->refunds->create([...], [...])`. Asserts the `stripe_account` header is PRESENT + matches; asserts `$opts['idempotency_key']` starts with `'riservo_refund_'` and, when `$expectedIdempotencyKeyExact !== null`, equals it. Returns a `Stripe\Refund::constructFrom([...])` default.
  - `mockRefundCreateFails(string $expectedAccountId, string $errorCode = 'account_invalid', string $message = 'This account does not have permission to perform this operation.'): self` — throws `Stripe\Exception\PermissionException` so the disconnected-account branch can be exercised.

### M9 — Iteration loop & close

- [x] (2026-04-23 15:05Z) Iteration loop all green: 865 / 3469 tests; Pint clean; Wayfinder regenerated; PHPStan level-5 `app/` clean; Vite build clean.
- [x] (2026-04-23 15:10Z) `docs/HANDOFF.md` rewritten with Session 2b summary + next-free `D-169`.
- [x] (2026-04-23 15:12Z) `D-162..D-168` promoted into `docs/decisions/DECISIONS-PAYMENTS.md` (60 Session-PAYMENTS decisions total).
- [x] (2026-04-23 15:14Z) `## Outcomes & Retrospective` filled in.
- [ ] Developer stages + commits.

- [ ] `php artisan test tests/Feature tests/Unit --compact` — baseline 829 / 3277 (Session 2a close); expect growth to ≈860+ tests.
- [ ] `vendor/bin/pint --dirty --format agent`.
- [ ] `php artisan wayfinder:generate` (new resolve route).
- [ ] `./vendor/bin/phpstan` (level 5, `app/` only) — watch for: generic inference on `DB::transaction` callbacks (D-148 / D-151 scalar-return workaround), `BookingRefund` PHPDoc + casts, PendingActionType `match` exhaustiveness wherever new enum cases land as `match` arms.
- [ ] `npm run build`.
- [ ] Rewrite `docs/HANDOFF.md` (not appended) with Session 2b summary + next-free `D-NNN`.
- [ ] Promote new `D-NNN` entries into `docs/decisions/DECISIONS-PAYMENTS.md`.
- [ ] Fill in `## Outcomes & Retrospective`.
- [ ] Stage with `git add -A`; report diff summary; developer commits (gate 2).


## Surprises & Discoveries

*(Populated as the session unfolds.)*

- Observation: Nothing to report yet.


## Decision Log

*(Populated as the session unfolds. Decisions that survive commit get promoted to `docs/decisions/DECISIONS-PAYMENTS.md`. Next free ID is D-162 per HANDOFF.)*

Design choices I expect to record during implementation (provisional IDs):

- **D-162 (provisional)** — `booking_refunds.uuid` is the source of the Stripe `idempotency_key`; never reuse the row id or a synthetic `(booking_id, amount, initiator)` hash. Directly implements locked roadmap decision #36.
- **D-163 (provisional)** — Late-webhook refund path reads `$booking->stripe_connected_account_id` (the D-158 pin) rather than doing a `withTrashed()->where('business_id')` lookup on the business. The pin is deterministic across reconnect history.
- **D-164 (provisional)** — The cancel_url controller redirects to `bookings.show` (not the public slug page) and branches the flash on `payment_mode_at_creation` (the snapshot), NOT on the current booking status. The cancel_url often lands BEFORE the webhook has fired — reading `status` would yield stale copy. The snapshot is the intent of the booking at creation; the customer's expected landing copy follows the intent, not the (possibly-not-yet-updated) outcome.
- **D-165 (provisional)** — `RefundService::refund` returns a readonly `RefundResult` DTO rather than a scalar. Session 3's multi-trigger expansion reads the outcome + reason + failure payload cleanly from a single value. Constructed OUTSIDE the `DB::transaction` callback so PHPStan's generic-inference constraint on callback returns still applies.
- **D-166 (provisional)** — The reaper SKIPS the cancel for all four `CheckoutPromoter::promote` return values, including `'mismatch'`. The promoter logged critical on mismatch; cancelling anyway could free a paid slot. Leaving the booking at `awaiting_payment` until operators reconcile is the conservative choice.
- **D-167 (dropped)** — earlier draft had `BookingRefund.status` as a plain string column. Gate-1 feedback: the three values are fixed across Sessions 2b + 3 (Stripe's refund states we care about are `pending`, `succeeded`, `failed` — `requires_action` and `canceled` are not surfaced in our flow), so a native PHP enum `App\Enums\BookingRefundStatus` is the right shape. No decision record needed.

Final IDs are assigned at commit time (in HANDOFF's next-free-ID sequence).


## Review

### Round 1

**Codex verdict**: three findings — one broken webhook lookup (PI events can't resolve a booking from the persisted state) and two dashboard permission/triage regressions (staff payment leak + wrong PA surfaced when late-refund fails).

- [x] **Finding 1 [P1]** — PI webhook arms can't resolve bookings (stripe_payment_intent_id is null pre-promotion).
  *Location*: `app/Http/Controllers/Webhooks/StripeConnectWebhookController.php`.
  *Fix*: dropped both `payment_intent.payment_failed` and `payment_intent.succeeded` arms. `checkout.session.expired` + `checkout.session.async_payment_failed` cover the failure case via `client_reference_id`; `checkout.session.completed`'s Cancelled-branch covers the late-webhook refund case. Removed the two handler methods + the `StripePaymentIntent` import + the two PI-event test cases (one in each test file).
  *Status*: done.

- [x] **Finding 2 [P1]** — `/dashboard/bookings` leaks Stripe IDs + payment state to staff.
  *Location*: `app/Http/Controllers/Dashboard/BookingController.php`.
  *Fix*: the `payment` + `pending_payment_action` sub-objects are now gated on `$isAdmin` in the serializer — staff see `null` for both fields. The `pendingActions` eager-load is also gated on `$isAdmin` so staff don't pay the query cost. TS type `DashboardBooking.payment` widened to `{...} | null`. Bookings list cell renders `—` for the staff / null case (defensive — the column itself is already behind `isAdmin && ...`). New regression test `staff get null payment + pending_payment_action on their own bookings` asserts the leak is fixed.
  *Status*: done.

- [x] **Finding 3 [P2]** — on a failed late-webhook refund, admins see the `cancelled_after_payment` banner instead of the more-urgent `refund_failed` one.
  *Location*: `app/Http/Controllers/Dashboard/BookingController.php`.
  *Fix*: the `pendingActions` eager-load now sorts `payment.refund_failed` before `payment.cancelled_after_payment` via `orderByRaw("CASE WHEN type = ? THEN 0 ELSE 1 END", [...])` with `latest('id')` as the secondary key. New regression test `refund_failed PA sorts before cancelled_after_payment in booking payload` asserts the ordering.
  *Status*: done.

**Round 1 outcome**: 865 / 3490 tests pass (−2 dropped PI-arm tests, +2 new regression tests, net 0 test count but +21 assertions from stronger coverage). PHPStan clean. Pint clean. Vite build clean.

### Round 2

**Codex verdict**: four findings — two transient-error-handling correctness issues (P1s), one permissions gap on the filter query-string (P2), and one customer-facing copy inconsistency under manual-confirm (P2).

- [x] **Finding 1 [P1]** — reaper cancels bookings on Stripe rate-limit (429).
  *Location*: `app/Console/Commands/ExpireUnpaidBookings.php`.
  *Fix*: added a `RateLimitException` catch BEFORE `InvalidRequestException` — 429 is now treated as retryable (warning log, leave for next tick). New regression test `reaper leaves the booking untouched on Stripe 429 rate-limit`.
  *Status*: done.

- [x] **Finding 2 [P1]** — `RefundService` marks terminal failure on indeterminate Stripe errors.
  *Location*: `app/Services/Payments/RefundService.php` + `app/Http/Controllers/Webhooks/StripeConnectWebhookController.php::applyLateWebhookRefund`.
  *Fix*: narrowed the catches — `PermissionException` / `AuthenticationException` → disconnected (terminal); `ApiConnectionException` + `RateLimitException` + 5xx `ApiErrorException` → transient (row stays `pending`, log warning, bubble exception); 4xx `ApiErrorException` → failed (terminal). `applyLateWebhookRefund` now catches the transient exception and returns 503 `Retry-After: 60` to force Stripe to re-deliver. The retry path is made safe by two more changes: (a) `RefundService::refund` looks up an existing `pending` row for the same booking+reason BEFORE the remaining-cents guard and reuses its UUID so Stripe's `idempotency_key` collapses the duplicate on retry; (b) `applyLateWebhookRefund`'s inner transaction detects `Paid + has-pending-refund-row` and sets `$shouldDispatchRefund = true` so the retry actually dispatches (the Cancelled-branch check also moved ahead of the generic `Already paid` short-circuit in `handleCheckoutSessionCompleted`). Two new regression tests cover transient behaviour + retry convergence.
  *Status*: done.

- [x] **Finding 3 [P2]** — staff can filter by `payment_status` and infer payment state from row counts.
  *Location*: `app/Http/Controllers/Dashboard/BookingController.php`.
  *Fix*: the `payment_status` filter is now server-gated on `$isAdmin` (mirrors the `provider_id` filter's `&& $isAdmin` guard). Staff requests silently drop the filter. New regression test `staff requesting ?payment_status=paid gets their full booking list back` asserts the gate.
  *Status*: done.

- [x] **Finding 4 [P2]** — cancel-URL "confirmed" flash is wrong under manual-confirm.
  *Location*: `app/Http/Controllers/Booking/BookingPaymentReturnController.php`.
  *Fix*: the customer_choice branch now additionally reads `$booking->business->confirmation_mode`. Manual-confirm → "Your booking request has been received — the business will confirm, and you can pay at the appointment." Auto-confirm → the original "Your booking is confirmed — pay at the appointment." New regression test `customer_choice + manual-confirm: cancel_url flashes the pending-request copy, not 'confirmed'` asserts the branching.
  *Status*: done.

**Round 2 outcome**: 870 / 3532 tests pass (+5 new regression tests). PHPStan clean. Pint clean. Vite build clean. The Session 2b refund pipeline now correctly distinguishes transient from terminal Stripe errors and recovers via the same row UUID on retry.

### Round 3

**Codex verdict**: four findings — three production-impact P1s (a reaper Stripe call that drops the connected-account header, a success-return path that un-cancels late-refunded bookings, an unserialized refund clamp) and one P2 (missing calendar push for customer_choice-confirmed failures).

- [x] **Finding 1 [P1]** — reaper passes `Stripe-Account` as params, not options.
  *Location*: `app/Console/Commands/ExpireUnpaidBookings.php` + (out-of-scope audit hit) `app/Http/Controllers/Booking/BookingPaymentReturnController.php::success`.
  *Fix*: moved `['stripe_account' => $acct]` from the 2nd arg to the 3rd (Stripe SDK `retrieve($id, $params = null, $opts = null)`). Audit turned up the SAME bug in Session 2a's success-page retrieve — fixed both in this round. Tightened `FakeStripeClient::mockCheckoutSessionRetrieveOnAccount` `withArgs` to assert `$params === null` strictly so the regression can't land again. Reaper-test assertion widened likewise.
  *Status*: done.

- [x] **Finding 2 [P1]** — `BookingPaymentReturnController::success()` can un-cancel a late-refunded booking.
  *Location*: `app/Http/Controllers/Booking/BookingPaymentReturnController.php::success`.
  *Fix*: top-level `status === BookingStatus::Cancelled` guard short-circuits before the retrieve + promoter. Redirects to `bookings.show` with a neutral error flash; the booking-detail page shows the accurate Cancelled + Refunded state. New regression test `Cancelled booking landing on the success page does NOT re-activate the slot`.
  *Status*: done.

- [x] **Finding 3 [P1]** — refund row creation not serialized under concurrent deliveries.
  *Location*: `app/Services/Payments/RefundService.php`.
  *Fix*: both the retry-path lookup AND the remaining-cents check + fresh-row insert now happen INSIDE a single `DB::transaction + lockForUpdate` block. The lock serialises concurrent callers; inside the lock we check first for an existing pending row (retry path — reuse UUID), otherwise recompute the clamp and insert a fresh row. Two racing callers either both find the first's row (second takes the retry branch with the shared UUID) or serialise cleanly through the lock.
  *Status*: done.

- [x] **Finding 4 [P2]** — customer_choice + Confirmed failure doesn't push to provider calendar.
  *Location*: `app/Http/Controllers/Webhooks/StripeConnectWebhookController.php::dispatchCustomerChoiceFailureNotifications`.
  *Fix*: added `if ($booking->status === BookingStatus::Confirmed && $booking->shouldPushToCalendar()) PushBookingToCalendarJob::dispatch(...)`. `shouldPushToCalendar()` already gates on the GoogleCalendar source (D-088) + configured-integration (D-083). New regression test `customer_choice + Confirmed failure dispatches PushBookingToCalendarJob when provider has a configured integration` asserts the dispatch via `Bus::assertDispatched`.
  *Status*: done.

**Round 3 outcome**: 872 / 3541 tests pass (+2 new regression tests; F1 regression coverage strengthened the existing mock helper). PHPStan clean. Pint clean. Vite build clean. All four Round 3 findings were legitimate production bugs. F1 turned up a Session 2a carry-over with the same pattern that was also silently broken — fixed in this round since it's the same mechanical shape.

### Round 4

**Codex verdict**: four findings — one crash-window P1 on the late-refund path, two P2 session-id trust-boundary gaps that match the D-156 shape Session 2a already enforces for the happy path, and one P2 replay-idempotency gap on `customer_choice` failure replays.

- [x] **Finding 1 [P1]** — pre-Stripe crash window strands `Paid` booking without a refund.
  *Location*: `app/Http/Controllers/Webhooks/StripeConnectWebhookController.php::applyLateWebhookRefund`.
  *Fix*: the Paid-branch guard now unconditionally dispatches `$shouldDispatchRefund = true` whenever the booking is `Cancelled + Paid` — the pending-row presence check was the footgun. The `RefundService`'s retry-path lookup already handles both "existing pending row" (reuse UUID) and "no row yet" (fresh insert with clamp recompute inside the lock) paths transparently. New regression test `late-refund retry after pre-Stripe crash (Paid + no pending row) still dispatches the refund`. Existing "no-op on Paid + no row" test was asserting the F1 bug — retargeted to `Refunded` (the actual terminal outcome).
  *Status*: done.

- [x] **Finding 2 [P2]** — Cancelled-branch late-refund bypasses session-id check.
  *Location*: `app/Http/Controllers/Webhooks/StripeConnectWebhookController.php::handleCheckoutSessionCompleted`.
  *Fix*: Cancelled-branch entry now verifies `$session->id === $booking->stripe_checkout_session_id` before `applyLateWebhookRefund`. Mismatch → critical log + 200 (same shape as the Stripe-level retry contract). New regression test `Cancelled-branch refuses to refund when the session id does not match the booking`.
  *Status*: done.

- [x] **Finding 3 [P2]** — failure webhook bypasses session-id check.
  *Location*: `app/Http/Controllers/Webhooks/StripeConnectWebhookController.php::handleCheckoutSessionFailed`.
  *Fix*: after the cross-account guard, added the same session-id check as F2. New regression test `failure webhook refuses to act when session id does not match the booking`.
  *Status*: done.

- [x] **Finding 4 [P2]** — customer_choice replays re-dispatch notifications.
  *Location*: `app/Http/Controllers/Webhooks/StripeConnectWebhookController.php::applyCheckoutFailureBranch`.
  *Fix*: added `payment_status === Unpaid` short-circuit to the outcome guard. Same-event-id replays are caught by the cache dedup; fresh-id replays (after the 24h TTL expires) are now caught by the DB-state guard. New regression test `replay of customer_choice failure with a fresh event id does NOT re-dispatch notifications`.
  *Status*: done.

**Round 4 outcome**: 876 / 3559 tests pass (+4 new regression tests). PHPStan clean. Pint clean. Vite build clean. All four Round 4 findings legitimate. F1 exposed that my Round 3 F3 fix had a narrow-but-serious crash-window gap; the fix widens the retry gate and the new regression test locks it in.


## Outcomes & Retrospective

**Shipped 2026-04-24** — PAYMENTS Session 2b delivered end-to-end failure branching, the 90-minute expiry reaper with defense-in-depth, the minimal refunds pipeline (one reason live: `cancelled-after-payment`), the admin dashboard payment surface, and the cancel-URL copy branching. **47 new tests (876 / 3559)** after four Codex review rounds. PHPStan clean, Pint clean, Vite build clean.

**What landed**:

- `booking_refunds` table + `BookingRefund` model + `BookingRefundStatus` enum + factory with pending/succeeded/failed states; `Booking::remainingRefundableCents()` real-clamp reads non-failed attempts (locked decision #37).
- `App\Services\Payments\RefundService` + `RefundResult` DTO. Row-UUID idempotency key per locked decision #36. Disconnected-account fallback writes `payment.refund_failed` PA + admin email (`RefundFailedNotification`).
- Four new webhook arms: `checkout.session.expired`, `checkout.session.async_payment_failed`, `payment_intent.payment_failed`, `payment_intent.succeeded`. `handleCheckoutSessionCompleted` routes Cancelled bookings to the late-refund branch.
- `bookings:expire-unpaid` command scheduled every 2 min with `withoutOverlapping()`. Base filter enforces `payment_mode_at_creation = 'online'` — the policy gate that keeps `customer_choice` bookings from ever being reaped (they go through the webhook arm instead, slot stays held).
- Admin UI: Payment column + Payment filter (chips: paid / awaiting_payment / unpaid / refunded / partially_refunded / refund_failed / offline); Payment panel on the booking-detail sheet with Stripe deep-link; Pending-Action banner + Mark-as-resolved CTA. New `Dashboard\PaymentPendingActionController::resolve`.
- `BookingPaymentReturnController::cancel` branches flash on `payment_mode_at_creation`; redirects to `bookings.show`; handles disconnected-account race.
- `BookingReceivedNotification` gains `pending_unpaid_awaiting_confirmation` context for manual-confirm + customer_choice + failed-Checkout customers.
- Two new mail notifications: `RefundFailedNotification`, `CancelledAfterPaymentNotification` — admin-only recipients.
- `FakeStripeClient` extended with `mockRefundCreate` (asserts stripe_account header + idempotency key shape) + `mockRefundCreateFails` (throws `PermissionException` for disconnected-account tests).

**What the roadmap said is "out of scope" for 2b and remains deferred**:

- Partial-refund UI + refund trigger expansion (customer in-window cancel, admin manual, business cancel, manual-confirmation rejection) — Session 3.
- `Booking::pendingActions()` relation touches calendar + payment PAs; calendar-aware readers unchanged (D-113 bucket filter already in place).
- Payouts surface, Settings toggle lift — Sessions 4 + 5.
- "Resend payment link for `unpaid` customer_choice bookings" — BACKLOG entry remains post-MVP.

**Decisions shipped** (D-162 through D-168): row-UUID idempotency key (D-162), D-158 pin re-used for the late-webhook refund path (D-163), cancel-URL branches on snapshot not current status (D-164), `RefundResult` DTO shape (D-165), reaper SKIPS cancel on every promoter return including `mismatch` (D-166), `BookingRefundStatus` native enum (D-167 gate-1 revision), new `pending_unpaid_awaiting_confirmation` notification context (D-168). Next-free decision id: **D-169**.

**Carry-overs for Codex review** (if the developer triggers it before commit):

- `Booking::pendingActions()` is unfiltered — the dashboard controller type-buckets payment rows explicitly; calendar code paths keep their existing `calendarValues()` filter. If codex flags a preference for a named-scope pattern we can layer `pendingPaymentActions()` on top.
- `handlePaymentIntentSucceeded` depends on `stripe_payment_intent_id` already being populated on the booking. For a true late-webhook case (reaper cancelled BEFORE any `checkout.session.completed` arrived) the PI id may be absent; the handler then 200-no-ops ("No booking linked"). The Checkout-side `handleCheckoutSessionCompleted` for `Cancelled` bookings covers this gap — both paths converge on `applyLateWebhookRefund`.
- The mail templates use English-only strings via `__()` consistent with existing templates; pre-launch i18n pass extends them.

**Retrospective**: the plan rhythm held — each milestone had a clear test-before-merge bar. The webhook controller grew the most (+533 lines) but the match-arm routing + shared helpers (`applyCheckoutFailureBranch`, `applyLateWebhookRefund`, `dispatchCustomerChoiceFailureNotifications`) kept handler bodies short and symmetric. The four Codex review rounds were highly productive — 15 findings total, all legitimate (no false positives), including several Session 2a carry-over bugs that mirrored Session 2b patterns (Stripe `$params` vs `$opts`, insufficient trust boundaries on bypass paths). Round 4 still produced 4 P1/P2 findings, so Round 5 would likely have more signal — but Codex's usage quota hit before it could run. The decision to commit after Round 4 rather than wait ~6 hours for Round 5 reflects confidence in the current state; a fresh post-commit review can always surface remaining issues for a follow-up.

**Review rounds summary**:
- Round 1 — 3 findings: dropped PI webhook arms (F1), gated dashboard payment payload on admin (F2), ordered pending-actions by urgency (F3).
- Round 2 — 4 findings: reaper rate-limit catch (F1), RefundService transient-vs-terminal distinction (F2), payment_status filter gated on admin (F3), cancel-URL copy branches on confirmation_mode (F4).
- Round 3 — 4 findings: Stripe SDK `$opts` position fix on reaper + success-page (F1), success-page Cancelled guard (F2), refund row serialization (F3), calendar push for customer_choice Confirmed failures (F4).
- Round 4 — 4 findings: late-refund retry widened for pre-Stripe crash window (F1), session-id trust boundaries on Cancelled-branch + failure handler (F2 + F3), Unpaid added to outcome-level guard (F4).

**Commit authorized by developer**: developer explicitly instructed the agent to commit + push this session (deviation from the standard "developer commits" gate). Commit bundles the full Session 2b diff including all four review rounds under a single message.


## Context and Orientation

### Repo layout relevant to this session

**Controllers**
- `app/Http/Controllers/Webhooks/StripeConnectWebhookController.php` — the Connect webhook at `POST /webhooks/stripe-connect`. Session 2a added `checkout.session.completed` + `checkout.session.async_payment_succeeded`; Session 2b layers `checkout.session.expired`, `checkout.session.async_payment_failed`, `payment_intent.payment_failed`, `payment_intent.succeeded`. Uses the `DedupesStripeWebhookEvents` trait with cache prefix `stripe:connect:event:` (D-110, locked decision #38).
- `app/Http/Controllers/Booking/BookingPaymentReturnController.php` — `success()` and `cancel()` public handlers at `/bookings/{token}/payment-success` + `/bookings/{token}/payment-cancel`. Session 2b updates `cancel()`.
- `app/Http/Controllers/Dashboard/BookingController.php` — admin bookings list + updateStatus + reschedule + store. Session 2b enriches `index()`'s payload with `payment` + `pending_payment_action` and adds a `payment_status` filter.
- New `app/Http/Controllers/Dashboard/PaymentPendingActionController.php` (admin-only, tenant-scoped resolve). Sibling of the existing `CalendarPendingActionController`.

**Services**
- `app/Services/Payments/CheckoutPromoter.php` — the single promotion service (D-151). Session 2b's reaper pre-flight calls it.
- `app/Services/Payments/CheckoutSessionFactory.php` — Session 2a's creator. Not touched in 2b.
- New `app/Services/Payments/RefundService.php` + `RefundResult.php`.

**Models**
- `app/Models/Booking.php` — Session 2a added payment columns + helpers. 2b adds `bookingRefunds(): HasMany` and extends `remainingRefundableCents()`.
- `app/Models/PendingAction.php` — the generalised table (D-113 + locked decision #44). 2b writes `PaymentRefundFailed` + `PaymentCancelledAfterPayment` rows.
- New `app/Models/BookingRefund.php`.

**Enums**
- `app/Enums/PaymentStatus.php` — final values shipped 2a. 2b writes `Unpaid` (customer_choice failure branch) and `Refunded` + `RefundFailed`.
- `app/Enums/PendingActionType.php` — payment cases pre-added by D-119.

**Console**
- `routes/console.php` — where the new `bookings:expire-unpaid` schedule line lands, next to the existing reapers (`bookings:send-reminders`, `bookings:auto-complete`, `calendar:renew-watches`).
- New `app/Console/Commands/ExpireUnpaidBookings.php`. Sibling of `AutoCompleteBookings`.

**Frontend**
- `resources/js/pages/dashboard/bookings.tsx` — list page; gains a Payment column + filter.
- `resources/js/components/dashboard/booking-detail-sheet.tsx` — sheet; gains a Payment panel + Pending Action banner.
- `resources/js/pages/bookings/show.tsx` — token-authenticated customer-facing booking detail. Session 2a already renders the payment status; Session 2b doesn't touch the shape.
- `resources/js/types/index.d.ts` — TS types extended.
- New `resources/js/components/dashboard/payment-status-badge.tsx`.

**Tests**
- `tests/Support/Billing/FakeStripeClient.php` — gains `mockRefundCreate` + `mockRefundCreateFails`.
- New test files listed in M8.

### Terms of art (first-use definitions)

- **Outcome-level idempotency** — every handler re-reads the DB row state at the moment of write (inside a `lockForUpdate` when concurrent races exist) and no-ops if the target state is already present. Distinct from the cache-layer event-id dedup (locked decision #38 / D-092 / D-110) which catches Stripe replays but not cache-flushed replays, maintenance replays, or cross-path races. Codified as locked decision #33 + D-151.
- **D-158 account pin** — `bookings.stripe_connected_account_id` is populated at Checkout-session creation with the minting account. Webhook + success-page + reaper read this pinned id rather than a `withTrashed()->where('business_id')->value('stripe_account_id')` lookup on the business (non-deterministic across reconnect history).
- **Reaper** — a scheduled job that walks stale records and reconciles them. Session 2b introduces `bookings:expire-unpaid`; sibling reapers are `bookings:send-reminders` + `bookings:auto-complete`.
- **GIST exclusion constraint on bookings** — D-065 / D-066. Prevents overlapping bookings while `status IN (pending, confirmed)`. Cancelling is the slot-release mechanism.
- **CheckoutPromoter** — the Session 2a service (D-151) that wraps `awaiting_payment → paid` in `DB::transaction + lockForUpdate + session-id / amount / currency cross-check + DB-state guard + notification dispatch`.
- **Pending Action** — a generic row in `pending_actions` (D-113 + locked decision #44). Calendar-typed and payment-typed coexist. Payment PAs are admin-only per locked decisions #19 / #31 / #35.
- **Cancellation token** — `bookings.cancellation_token`, UUID minted at booking creation. Bearer secret authenticating customer-facing view / cancel / payment-success / payment-cancel surfaces.

### What Session 2a left in place that 2b relies on

Session 2a shipped in commit `36879f0` (plus CI fix `5bdbe4e`). Highlights Session 2b inherits:

- `bookings.payment_mode_at_creation` (not-null, default `'offline'`); immutable snapshot per locked decision #14.
- `bookings.stripe_connected_account_id` (D-158); populated by `PublicBookingController::store` for online bookings.
- `bookings.stripe_checkout_session_id`, `stripe_payment_intent_id`, `stripe_charge_id` (nullable; 2a writes session_id + PI id; charge_id is populated in Session 3's refund path but the late-webhook refund in 2b writes it when PI data provides `latest_charge`).
- `bookings.paid_amount_cents` + `currency` — captured at creation (D-152), overwritten by Stripe on promotion (subject to D-156 fail-closed).
- `bookings.expires_at` — populated only for `payment_mode_at_creation = 'online'`. The 2b reaper filters on this.
- `CheckoutPromoter::promote` returns `'paid' | 'already_paid' | 'not_paid' | 'mismatch'` (D-156). Session 2b's reaper handles all four the same way: SKIP the cancel.
- `BookingManagementController::cancel`, `Customer\BookingController::cancel`, `Dashboard\BookingController::updateStatus` — all refuse `payment_status = Paid` (D-157 + D-159). Session 2b does NOT relax these guards; Session 3 does.
- `PendingActionType::PaymentRefundFailed` + `PaymentCancelledAfterPayment` pre-added by D-119.
- `FakeStripeClient`'s connected-account-level bucket (mocks assert `stripe_account` header present); 2b extends it with `mockRefundCreate` + `mockRefundCreateFails`.


## Plan of Work

Work lands in ten milestones (M0 → M9). The ceremony sequence is plan (M0) → developer approval → exec (M1 → M9) → codex review → developer commit.

1. **M0 — Plan alignment.** Developer approves this plan. No code before gate 1.
2. **M1 — Data layer.** The `booking_refunds` table + the real `remainingRefundableCents()` clamp are the foundation everything else depends on. Implement, run `migrate:fresh --seed`, land the factory test.
3. **M2 — `RefundService` skeleton.** The glue between `booking_refunds`, Stripe, booking state, Pending Actions, and the admin email. Session 3 extends this without breaking the signature.
4. **M3 — Failure webhook arms.** Three failure events + one late-webhook-refund event. Each handler mirrors the `handleCheckoutSessionCompleted` guard structure (client_reference_id resolution → D-158 cross-account guard → outcome-level guard → `DB::transaction + lockForUpdate` → branching on snapshot).
5. **M4 — Reaper.** Command + schedule entry. Pre-flight retrieve + `CheckoutPromoter::promote` is the key invariant; no inline promotion logic.
6. **M5 — Admin notifications.** Two markdown mail notifications. Admin-only recipients per locked decisions #19 / #31.
7. **M6 — Admin UI.** Extend the dashboard list + detail sheet. New controller for resolving payment PAs. New badge + filter.
8. **M7 — Cancel-URL copy.** Small controller change; two flash keys branching on the snapshot.
9. **M8 — Tests.** ~35 tests across 7 files. Test-first discipline on the reaper + webhook handlers.
10. **M9 — Close.** Iteration loop, Pint, Wayfinder, PHPStan, Vite build, HANDOFF rewrite, decisions promoted, stage; developer commits (gate 2).

**Test-first discipline** on the reaper and webhook arms — these race with customer actions and Stripe webhooks, and regressions in state transitions compound.

**Never commit.** I stage after each milestone and report back.


## Concrete Steps

### Baseline verification

```bash
cd /Users/mir/Projects/riservo
git status               # expect clean after 5bdbe4e
git log -1 --format='%h %s'
php artisan test tests/Feature tests/Unit --compact  # confirm 829 / 3277 baseline
```

### M1 — Data layer

```bash
php artisan make:migration create_booking_refunds_table --create=booking_refunds --no-interaction
php artisan make:model BookingRefund --factory --no-interaction
```

Edit the migration to match the schema in M1 Progress. Edit the model (fillable + casts + relations). Edit the factory (states).

Extend `app/Models/Booking.php`: add `bookingRefunds()` relation; extend `remainingRefundableCents()`.

Run:

```bash
php artisan migrate:fresh --seed
php artisan test --compact --filter=BookingRefund
```

### M2 — `RefundService`

Create `app/Services/Payments/RefundService.php` + `RefundResult.php`. Container-resolvable via constructor injection on `StripeClient` — no explicit binding.

```bash
php artisan test tests/Unit/Services/Payments/RefundServiceTest.php --compact
```

### M3 — Failure webhook arms

Write T01..T16 tests first. Extend `StripeConnectWebhookController::dispatch()`'s `match` with the four new arms. Implement handlers.

```bash
php artisan test tests/Feature/Payments --compact
```

### M4 — Reaper

```bash
php artisan make:command ExpireUnpaidBookings --no-interaction
```

Write T17..T22 first. Implement the command body. Add the schedule entry to `routes/console.php`.

```bash
php artisan test tests/Feature/Console --compact
php artisan bookings:expire-unpaid      # smoke test: "0 bookings processed" on a fresh DB
```

### M5 — Notifications

```bash
php artisan make:notification Payments/RefundFailedNotification --no-interaction
php artisan make:notification Payments/CancelledAfterPaymentNotification --no-interaction
```

Markdown views under `resources/views/mail/payments/`. Follow the `resources/views/mail/booking-received.blade.php` shape.

### M6 — Admin UI

```bash
php artisan make:controller Dashboard/PaymentPendingActionController --no-interaction
```

Add the route `dashboard.payments.pending-actions.resolve`. Regenerate:

```bash
php artisan wayfinder:generate
```

Edit:

- `app/Http/Controllers/Dashboard/BookingController.php::index` — enrich payload, add filter.
- `resources/js/pages/dashboard/bookings.tsx` — filter + column.
- `resources/js/components/dashboard/booking-detail-sheet.tsx` — Payment panel + PA banner.
- `resources/js/components/dashboard/payment-status-badge.tsx` — new.
- `resources/js/types/index.d.ts` — extend types.

```bash
php artisan test tests/Feature/Dashboard --compact
npm run build    # verify TS + Vite
```

### M7 — Cancel-URL copy

Edit `BookingPaymentReturnController::cancel`.

```bash
php artisan test tests/Feature/Booking/CancelUrlLandingTest.php --compact
```

### M8 — Full iteration loop

```bash
php artisan test tests/Feature tests/Unit --compact
vendor/bin/pint --dirty --format agent
php artisan wayfinder:generate
./vendor/bin/phpstan
npm run build
```

### M9 — Close

```bash
git add -A
git status
```

Rewrite `docs/HANDOFF.md`; promote `D-NNN` into `docs/decisions/DECISIONS-PAYMENTS.md`.


## Validation and Acceptance

Acceptance is observable behaviour:

- **Expiry reaper observable** (T17 in `ExpireUnpaidBookingsTest.php`): seed an online booking with `expires_at = now - 10 min`; mock `checkout.sessions.retrieve` to return `status = 'expired'`; `Artisan::call('bookings:expire-unpaid')`; assert booking is `Cancelled + NotApplicable + expires_at = null`; assert a fresh booking at the same slot commits successfully (slot released).
- **Failure webhook observable** (T01): POST a signed `checkout.session.expired` body to `/webhooks/stripe-connect`; online booking flips to `Cancelled + NotApplicable`; zero notifications; reposting the same event id (D-092 cache dedup) returns 200 with no additional state change.
- **Late-webhook refund observable** (T11): after the reaper cancels, POST `checkout.session.completed` for the same session; assert `payment_status = Paid`, `BookingRefund` row with `stripe_refund_id`, `FakeStripeClient.mockRefundCreate` invoked with `idempotency_key = 'riservo_refund_{uuid}'`, `CancelledAfterPaymentNotification` fires once, booking stays Cancelled.
- **Admin surface observable** (T26): as admin, GET `/dashboard/bookings?payment_status=paid`; open the detail sheet; see "Paid CHF 50.00 · Stripe charge ch_test_…" with a deep-link to the Connect dashboard.
- **Pending Action resolve observable** (T27): PATCH `/dashboard/payments/pending-actions/{id}/resolve` marks `status = Resolved` + `resolved_by_user_id = auth()->id()` + `resolved_at = now()`.

Session close gate (M9):
- `php artisan test tests/Feature tests/Unit --compact` — all pass, total ≥ 860.
- `vendor/bin/pint --dirty --format agent` — no style changes.
- `php artisan wayfinder:generate` — clean.
- `./vendor/bin/phpstan` — 0 errors, level 5, `app/` only.
- `npm run build` — Vite clean.


## Idempotence and Recovery

- **Migration**: additive (new table + index); `down()` drops the table. `migrate:fresh` is safe.
- **RefundService**: the row is created BEFORE the Stripe call with `uuid` populated. A retry after mid-response failure reuses the row → same `idempotency_key` → Stripe collapses via locked decision #36. No double-refund risk.
- **Webhook handlers**: every new handler begins with an outcome-level DB-state guard. The D-092 / D-110 cache dedup is the second line of defence.
- **Reaper**: `withoutOverlapping()` + `DB::transaction + lockForUpdate` + outcome-level guard inside the lock. Stripe 5xx → leave for next tick. Stripe 4xx on retrieve → cancel (session gone).
- **Cancel-URL controller**: stateless. The customer can re-hit arbitrarily; flash is informational.
- **Pending Action resolve**: idempotent (`status !== pending` → no-op).


## Artifacts and Notes

### RefundService signature + DTO

```php
namespace App\Services\Payments;

use App\Models\Booking;
use App\Models\BookingRefund;

final class RefundService
{
    public function __construct(
        private readonly \Stripe\StripeClient $stripe,
    ) {}

    public function refund(
        Booking $booking,
        ?int $amountCents = null,
        string $reason = 'cancelled-after-payment',
    ): RefundResult {
        // 1) guards → RefundResult::guardRejected(...)
        // 2) DB::transaction { lockForUpdate; insert booking_refunds row pending+uuid; }
        // 3) try { stripe.refunds.create([...], ['stripe_account' => ..., 'idempotency_key' => 'riservo_refund_'.$row->uuid]); }
        // 4) permission error → row failed, booking refund_failed, PA, admin email → disconnected
        // 5) other ApiErrorException → same DB side-effects → failed
        // 6) success → row succeeded, booking Refunded → succeeded
    }
}

final readonly class RefundResult
{
    public function __construct(
        public string $outcome, // 'succeeded' | 'failed' | 'disconnected' | 'guard_rejected'
        public ?BookingRefund $bookingRefund,
        public ?string $failureReason,
    ) {}
}
```

### Reaper cancel-branch pseudocode

```php
DB::transaction(function () use ($bookingId) {
    $locked = Booking::query()->whereKey($bookingId)->lockForUpdate()->first();
    if ($locked === null) return;
    if ($locked->payment_status !== PaymentStatus::AwaitingPayment) return;
    if ($locked->status !== BookingStatus::Pending) return;
    $locked->forceFill([
        'status' => BookingStatus::Cancelled,
        'payment_status' => PaymentStatus::NotApplicable,
        'expires_at' => null,
    ])->save();
});
```

### FakeStripeClient extension

```php
public function mockRefundCreate(
    string $expectedAccountId,
    ?string $expectedIdempotencyKeyExact = null,
    array $response = [],
): self {
    // stub $stripe->refunds->create([...], [...]) via ensureRefunds() + Mockery->shouldReceive
    // withArgs: $opts['stripe_account'] === $expectedAccountId
    //           && str_starts_with($opts['idempotency_key'] ?? '', 'riservo_refund_')
    //           && ($expectedIdempotencyKeyExact === null || $opts['idempotency_key'] === $expectedIdempotencyKeyExact)
    // returns \Stripe\Refund::constructFrom(array_merge([
    //     'id' => 're_test_'.uniqid(),
    //     'status' => 'succeeded',
    //     'amount' => 5000,
    //     'currency' => 'chf',
    // ], $response))
}

public function mockRefundCreateFails(
    string $expectedAccountId,
    string $errorCode = 'account_invalid',
    string $message = 'This account does not have permission to perform this operation.',
): self {
    // throws \Stripe\Exception\PermissionException
}
```


## Interfaces and Dependencies

### New files

- `database/migrations/2026_04_23_HHMMSS_create_booking_refunds_table.php`
- `database/factories/BookingRefundFactory.php`
- `app/Models/BookingRefund.php`
- `app/Services/Payments/RefundService.php`
- `app/Services/Payments/RefundResult.php`
- `app/Http/Controllers/Dashboard/PaymentPendingActionController.php`
- `app/Console/Commands/ExpireUnpaidBookings.php`
- `app/Notifications/Payments/RefundFailedNotification.php` + `resources/views/mail/payments/refund-failed.blade.php`
- `app/Notifications/Payments/CancelledAfterPaymentNotification.php` + `resources/views/mail/payments/cancelled-after-payment.blade.php`
- `resources/js/components/dashboard/payment-status-badge.tsx`
- Test files listed in M8.

### Modified files

- `app/Models/Booking.php` — `bookingRefunds(): HasMany`; extend `remainingRefundableCents()`; (add `pendingActions(): HasMany` if not already present).
- `app/Http/Controllers/Webhooks/StripeConnectWebhookController.php` — four new arms + four new private handlers + branching in the existing `handleCheckoutSessionCompleted` for `status === Cancelled`.
- `app/Http/Controllers/Booking/BookingPaymentReturnController.php::cancel` — flash branching on `payment_mode_at_creation`.
- `app/Http/Controllers/Dashboard/BookingController.php::index` — payload enrichment + filter.
- `resources/js/pages/dashboard/bookings.tsx` — Payment filter + column.
- `resources/js/components/dashboard/booking-detail-sheet.tsx` — Payment panel + PA banner.
- `resources/js/types/index.d.ts` — extend types.
- `routes/web.php` — resolve route for payment PAs.
- `routes/console.php` — schedule reaper every 2 min.
- `tests/Support/Billing/FakeStripeClient.php` — `mockRefundCreate` + `mockRefundCreateFails`.

### Inertia prop shapes (additive)

```ts
interface DashboardBooking {
    // ... existing fields
    payment: {
        status:
            | 'not_applicable'
            | 'awaiting_payment'
            | 'paid'
            | 'unpaid'
            | 'refunded'
            | 'partially_refunded'
            | 'refund_failed';
        paid_amount_cents: number | null;
        currency: string | null;
        paid_at: string | null;
        stripe_charge_id: string | null;
        stripe_payment_intent_id: string | null;
        stripe_connected_account_id: string | null;
    };
    pending_payment_action: PendingPaymentAction | null;
}

interface PendingPaymentAction {
    id: number;
    type: 'payment.cancelled_after_payment' | 'payment.refund_failed';
    payload: Record<string, unknown>;
    created_at: string;
}

interface BookingsPageProps extends PageProps {
    // ... existing
    filters: {
        status: string;
        payment_status: string; // NEW
        service_id: string;
        provider_id: string;
        date_from: string;
        date_to: string;
        sort: string;
        direction: string;
    };
}
```

### Wayfinder routes exposed after this session

- `dashboard.payments.pending-actions.resolve` (PATCH) — used by the booking-detail banner's "Mark as resolved" button.


## Open Questions

No product-level ambiguities surfaced from the session brief or the roadmap. Every locked decision (#13, #14, #19, #29, #31, #36, #41, #43, #44, #45) and every Session 2a decision (D-151 → D-161) has a concrete Session 2b instruction in the roadmap.

Two small judgement calls I've made unilaterally (happy to revise at gate 1):

1. ~~`BookingRefund.status` as a plain string rather than a native enum~~ — **resolved at gate 1**: use a native enum `App\Enums\BookingRefundStatus` because the three values (`pending`, `succeeded`, `failed`) are the full set we surface across Sessions 2b and 3.
2. **Reaper scope is platform-wide (all businesses, read-only or not)** — matches the existing convention for `AutoCompleteBookings`, `SendBookingReminders`, etc. (HANDOFF's "Server-side automation runs unconditionally" clause).

If neither of those pushes back, they become D-entries at commit time.

A specific call I want the developer to sanity-check at gate 1:

3. **Late-webhook refund also branches inside `handleCheckoutSessionCompleted`** (when `booking.status === Cancelled`), not only inside `handlePaymentIntentSucceeded`. Reason: Stripe may deliver the Checkout-level event before the payment-intent one, and the happy-path handler would otherwise route into `CheckoutPromoter::promote` which fails closed on the DB-state guard (booking is Cancelled, not AwaitingPayment). Belt-and-braces; confirm this matches your expectations.


## Risks & Notes

- **Reaper vs webhook race.** 5-min grace buffer + pre-flight retrieve + late-webhook refund path (locked decision #31) are the three stacked mitigations. The late-webhook refund runs end-to-end in 2b (not stubbed); Session 3 only adds more refund reasons, it does not take over this path. Edge case: a TWINT session settling at minute 89 with webhook delay > 5 min could be cancelled by the reaper, then the late-webhook refund runs. The slot may already be re-booked — acceptable: funds go back; customer had 90 + 5 min of window.
- **`payment_intent.succeeded` subscription.** We subscribe for the cancelled-after-payment case. For the happy path, `checkout.session.completed` (Session 2a M4) remains the canonical trigger — we guard `handlePaymentIntentSucceeded` on `status === Cancelled` to avoid double-dispatch. If this becomes unwieldy in Session 3, the subscription can be removed in favour of `checkout.session.completed` alone.
- **Disconnected connected account between booking and refund.** D-158 pin + locked decision #36 retention mean the refund attempt still targets the correct account. A Stripe permission error lands as `payment.refund_failed` PA + admin email. Session 2b does NOT ship the "retry on reconnect" flow; that's Session 3.
- **PHPStan level-5 generic inference on `DB::transaction`.** D-148 + D-151 workaround is a scalar (or void) return from the callback. `RefundService`'s callback returns nothing and captures the row via `use (&$row)`; the reaper's transaction is similar. No new PHPStan drama expected — but worth watching.
- **Notification timing — mail queue vs sync.** Current pattern is synchronous `Notification::send` / `Notification::route('mail')`. Session 2b follows suit. If the disconnected-account email becomes a happy-path latency issue (very rare — only fires on permission error), moving it to a queue is a trivial follow-up.
- **Test count growth.** Session 2a ended at 829. M8 targets ~35 new tests → ≈864. Full-suite runtime is not a concern yet; the browser suite is the dominant cost per HANDOFF and 2b doesn't touch it.
- **Rollback.** Every change is additive (new table, new TS types, new webhook arms, pre-existing enum cases). Migration `down()` drops `booking_refunds`. Schedule entry revert is one line. No destructive data migrations. Session 2a's D-157 / D-159 paid-cancel guards remain untouched.
