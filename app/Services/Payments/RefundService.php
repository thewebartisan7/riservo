<?php

namespace App\Services\Payments;

use App\Enums\BookingRefundStatus;
use App\Enums\PaymentStatus;
use App\Enums\PendingActionStatus;
use App\Enums\PendingActionType;
use App\Models\Booking;
use App\Models\BookingRefund;
use App\Models\PendingAction;
use App\Notifications\Payments\RefundFailedNotification;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Stripe\Exception\ApiConnectionException;
use Stripe\Exception\ApiErrorException;
use Stripe\Exception\AuthenticationException;
use Stripe\Exception\PermissionException;
use Stripe\Exception\RateLimitException;
use Stripe\StripeClient;

/**
 * PAYMENTS Session 2b — minimal refund executor for the
 * `cancelled-after-payment` reason (the late-webhook path per locked roadmap
 * decision #31.3). Session 3 extends this service with additional reasons
 * (`customer-requested`, `business-cancelled`, `admin-manual`,
 * `business-rejected-pending`), partial-refund support, an optional initiator
 * user id, and three public settlement methods (`recordStripeState`,
 * `recordSettlementSuccess`, `recordSettlementFailure`) consumed by the
 * `charge.refunded` / `charge.refund.updated` / `refund.updated` webhook arms.
 *
 * Key invariants:
 *
 *  - One `booking_refunds` row per refund ATTEMPT (locked decision #36). The
 *    row's `uuid` is the source of the Stripe idempotency key. A mid-response
 *    retry reuses the row + UUID; Stripe collapses the duplicate.
 *  - Refund amount is always derived from `paid_amount_cents` / `currency`
 *    captured on the booking at creation (locked decisions #37 + #42 + D-152).
 *    `Service.price` is never read here. A partial refund exceeding
 *    `remainingRefundableCents()` throws a 422 `ValidationException` (D-169)
 *    rather than silently clamping — the admin's client-side clamp is the
 *    first line of defence, this is the authoritative second check.
 *  - Connected-account id is read from `booking.stripe_connected_account_id`
 *    (D-158 pin). This works across disconnect+reconnect history and still
 *    resolves when the original row has been soft-deleted.
 *  - Disconnected-account fallback (locked decision #36): on a Stripe
 *    permission error the row lands at `failed`, the booking's
 *    `payment_status` is set to `refund_failed`, a `payment.refund_failed`
 *    Pending Action is upserted, and admins receive
 *    `RefundFailedNotification`. Staff do not receive payment notifications.
 */
final class RefundService
{
    public function __construct(
        private readonly StripeClient $stripe,
    ) {}

    /**
     * Refund a booking. Session 2b writes `reason = 'cancelled-after-payment'`
     * only; Session 3 adds `customer-requested`, `business-cancelled`,
     * `admin-manual`, `business-rejected-pending`.
     *
     * @param  int|null  $amountCents  when null, refund the full remaining
     *                                 refundable amount. Non-null = partial
     *                                 refund (Session 3 admin-manual path).
     *                                 A non-null value exceeding
     *                                 `remainingRefundableCents()` raises a
     *                                 422 `ValidationException` (D-169).
     * @param  int|null  $initiatedByUserId  the admin user id for the
     *                                       `admin-manual` path; null for every
     *                                       system-dispatched refund.
     * @param  string|null  $adminNote  free-form note entered by the admin in
     *                                  the manual-refund dialog. Persisted on
     *                                  the `booking_refunds.admin_note` column
     *                                  so the audit trail survives. Only the
     *                                  admin-manual caller passes this; every
     *                                  system-dispatched refund leaves it null.
     *
     * @throws ValidationException on overflow (`$amountCents > remaining`).
     * @throws ApiConnectionException on a Stripe transport-layer failure (the
     *                                pending row is left intact so a retry converges via the Stripe
     *                                idempotency key — callers should surface a 503 / "try again").
     * @throws RateLimitException on a Stripe 429 (same retry contract).
     * @throws ApiErrorException on a Stripe 5xx (same retry contract). Non-
     *                           5xx `ApiErrorException` is caught internally and mapped to a
     *                           `failed` outcome.
     */
    public function refund(
        Booking $booking,
        ?int $amountCents = null,
        string $reason = 'cancelled-after-payment',
        ?int $initiatedByUserId = null,
        ?string $adminNote = null,
    ): RefundResult {
        // Pre-flight guards: nothing to refund against.
        if ($booking->paid_amount_cents === null || $booking->paid_amount_cents <= 0) {
            Log::warning('RefundService: booking has no paid_amount_cents — refusing to create refund row', [
                'booking_id' => $booking->id,
                'reason' => $reason,
            ]);

            return RefundResult::guardRejected('no_paid_amount');
        }

        if (! is_string($booking->stripe_payment_intent_id) || $booking->stripe_payment_intent_id === '') {
            Log::warning('RefundService: booking has no stripe_payment_intent_id — refusing to create refund row', [
                'booking_id' => $booking->id,
                'reason' => $reason,
            ]);

            return RefundResult::guardRejected('no_payment_intent');
        }

        if (! is_string($booking->stripe_connected_account_id) || $booking->stripe_connected_account_id === '') {
            // D-158: refunds target the pinned minting account. A null here
            // is a data anomaly — every online booking is supposed to have
            // this column populated at Checkout-session creation. Surface
            // critical for operator investigation.
            Log::critical('RefundService: booking has no stripe_connected_account_id — refusing to create refund row', [
                'booking_id' => $booking->id,
                'reason' => $reason,
            ]);

            return RefundResult::guardRejected('no_connected_account');
        }

        // Codex Round 2 (F2 follow-up) + Round 3 (F3): on the transient-
        // retry path a previous refund attempt may have left a `pending`
        // row with the same UUID — reuse it so Stripe's idempotency-key
        // dedup collapses the duplicate. A fresh row with a new UUID
        // would make Stripe see a brand-new refund attempt, potentially
        // double-charging the merchant's balance.
        //
        // The full remaining-cents check + row creation runs INSIDE a
        // `DB::transaction + lockForUpdate` so two concurrent refund
        // callers (e.g. a webhook delivery + a manual admin retry
        // arriving before the cache-level dedup has a chance to kick in)
        // serialise: the second caller sees the first's row already
        // consuming the clamp, takes the retry branch, and reuses the
        // UUID. Without this lock-then-recheck sequence both callers
        // could both see the full refundable amount and insert duplicate
        // full-amount pending rows, dispatching two refunds for the same
        // intent.
        $row = null;
        $guardRejection = null;

        DB::transaction(function () use ($booking, $amountCents, $reason, $initiatedByUserId, $adminNote, &$row, &$guardRejection): void {
            Booking::query()->whereKey($booking->id)->lockForUpdate()->first();

            // Retry-path lookup: if a pending row exists for this
            // booking+reason, reuse it so Stripe's idempotency-key dedup
            // collapses the duplicate call.
            //
            // Codex Round 2 P1: for `admin-manual` the reason isn't enough
            // — an admin could submit CHF 30 while a previous CHF 20
            // attempt is still Pending (e.g. Stripe timed out). Reusing
            // the 20-cent row would silently re-dispatch the old amount
            // under the new request. Two safe choices per Codex: (a)
            // distinguish manual refunds by amount; (b) block new manual
            // refunds while one is pending. We pick (b) by construction:
            // any `admin-manual` pending row whose `amount_cents` differs
            // from the newly-requested `$amountCents` raises a 422, so
            // the admin sees "a refund of CHF 20 is already pending — wait
            // for it to settle". A double-click with the SAME amount still
            // collapses onto the pending row (retry idempotency preserved).
            $existing = $booking->bookingRefunds()
                ->where('status', BookingRefundStatus::Pending->value)
                ->where('reason', $reason)
                ->latest('id')
                ->first();

            if ($existing !== null) {
                if ($reason === 'admin-manual'
                    && $amountCents !== null
                    && $amountCents !== $existing->amount_cents) {
                    throw ValidationException::withMessages([
                        'amount_cents' => [__('A refund of :pending is already pending for this booking. Wait for it to settle before issuing another.', [
                            'pending' => number_format($existing->amount_cents / 100, 2, '.', "'"),
                        ])],
                    ]);
                }

                $row = $existing;

                return;
            }

            // Fresh-insert path: recompute the clamp inside the lock.
            $remaining = $booking->remainingRefundableCents();

            if ($remaining <= 0) {
                Log::warning('RefundService: no refundable amount remaining — refusing to create refund row', [
                    'booking_id' => $booking->id,
                    'reason' => $reason,
                    'remaining_cents' => $remaining,
                ]);
                $guardRejection = 'no_remaining';

                return;
            }

            // `$amountCents = null` is the "refund everything remaining" contract
            // (Session 2b's only mode + Session 3 system-dispatched paths). Non-
            // null is a partial-refund request: if it exceeds the remaining,
            // throw 422 per D-169 rather than silently clamp — hiding a
            // 5000-requested/3000-remaining mismatch behind a silent clamp would
            // misreport the refunded figure in the admin UI.
            if ($amountCents === null) {
                $requestedAmount = $remaining;
            } else {
                if ($amountCents > $remaining) {
                    throw ValidationException::withMessages([
                        'amount_cents' => [__('Refund exceeds the remaining refundable amount. Maximum allowed is :max.', [
                            'max' => number_format($remaining / 100, 2, '.', "'"),
                        ])],
                    ]);
                }
                $requestedAmount = $amountCents;
            }

            $newRow = new BookingRefund;
            $newRow->forceFill([
                'uuid' => (string) Str::uuid(),
                'booking_id' => $booking->id,
                'stripe_refund_id' => null,
                'amount_cents' => $requestedAmount,
                'currency' => $booking->currency ?? 'chf',
                'status' => BookingRefundStatus::Pending,
                'reason' => $reason,
                'initiated_by_user_id' => $initiatedByUserId,
                'failure_reason' => null,
                'admin_note' => $adminNote,
            ])->save();
            $row = $newRow;
        });

        if ($row === null) {
            return RefundResult::guardRejected($guardRejection ?? 'no_row');
        }

        $idempotencyKey = 'riservo_refund_'.$row->uuid;

        try {
            $refund = $this->stripe->refunds->create(
                [
                    'payment_intent' => $booking->stripe_payment_intent_id,
                    'amount' => $row->amount_cents,
                ],
                [
                    'stripe_account' => $booking->stripe_connected_account_id,
                    'idempotency_key' => $idempotencyKey,
                ],
            );
        } catch (PermissionException|AuthenticationException $e) {
            // Locked decision #36 disconnected-account fallback: Stripe no
            // longer accepts calls against this connected account. Mark
            // failed, surface to admins, return disconnected.
            return $this->recordFailure($booking, $row, $e->getMessage(), 'disconnected');
        } catch (ApiConnectionException|RateLimitException $e) {
            // Codex Round 2 (F2): a network error or Stripe 429 may mean
            // the refund WAS accepted by Stripe before the transport fell
            // over. Marking the row `failed` and flipping the booking to
            // `refund_failed` would short-circuit every subsequent late-
            // webhook delivery at the outcome guard, leaving an already-
            // processed refund hidden under a permanent-failure label.
            // Leave the row at `pending` (idempotency key + amount
            // preserved) so a later retry with the same UUID converges
            // via Stripe's idempotency; let the exception propagate so
            // the webhook caller returns non-2xx and Stripe retries.
            Log::warning('RefundService: transient Stripe error — row left pending, bubbling for retry', [
                'booking_id' => $booking->id,
                'booking_refund_id' => $row->id,
                'exception' => $e::class,
                'message' => $e->getMessage(),
            ]);
            throw $e;
        } catch (ApiErrorException $e) {
            // Non-transient 4xx that isn't a permission error (e.g.
            // `charge_already_refunded`, `invalid_request_error` on the
            // refund shape). Mark terminal.
            $status = $e->getHttpStatus();
            if ($status !== null && $status >= 500) {
                // Stripe 5xx is transient. Same logic as the connection
                // exception above — leave row pending and bubble.
                Log::warning('RefundService: Stripe 5xx on refunds.create — row left pending, bubbling for retry', [
                    'booking_id' => $booking->id,
                    'booking_refund_id' => $row->id,
                    'http_status' => $status,
                    'message' => $e->getMessage(),
                ]);
                throw $e;
            }

            return $this->recordFailure($booking, $row, $e->getMessage(), 'failed');
        }

        // Happy path: Stripe accepted the refund (succeeded or pending on
        // Stripe's side — we mark Succeeded here because the initial
        // response is terminal-enough for our state; Session 3's
        // charge.refunded / refund.updated webhooks will flip to Failed
        // if Stripe later rejects during async settlement).
        DB::transaction(function () use ($booking, $row, $refund): void {
            Booking::query()->whereKey($booking->id)->lockForUpdate()->first();

            $row->forceFill([
                'status' => BookingRefundStatus::Succeeded,
                'stripe_refund_id' => $refund->id,
            ])->save();

            $this->reconcilePaymentStatus($booking);
        });

        return RefundResult::succeeded($row->fresh() ?? $row);
    }

    /**
     * Shared sad path for PermissionException / AuthenticationException
     * (disconnected) and any other `ApiErrorException` (generic failed):
     * mark the row failed, flip the booking's payment_status, upsert the
     * Pending Action, dispatch the admin email.
     *
     * @param  'disconnected'|'failed'  $category
     */
    private function recordFailure(
        Booking $booking,
        BookingRefund $row,
        string $message,
        string $category,
    ): RefundResult {
        DB::transaction(function () use ($booking, $row, $message): void {
            Booking::query()->whereKey($booking->id)->lockForUpdate()->first();

            $row->forceFill([
                'status' => BookingRefundStatus::Failed,
                'failure_reason' => $message,
            ])->save();

            $booking->forceFill([
                'payment_status' => PaymentStatus::RefundFailed,
            ])->save();
        });

        $row = $row->fresh() ?? $row;

        $this->upsertRefundFailedPendingAction($booking, $row);
        $this->dispatchRefundFailedEmail($booking, $row);

        return $category === 'disconnected'
            ? RefundResult::disconnected($row, $message)
            : RefundResult::failed($row, $message);
    }

    /**
     * PAYMENTS Session 3 — webhook settlement dispatcher (D-171 + D-172).
     *
     * Called from the refund-settlement arms on `StripeConnectWebhookController`
     * (`charge.refunded`, `charge.refund.updated`, `refund.updated`). Maps
     * Stripe's refund-state vocabulary onto our three-value
     * `BookingRefundStatus` enum (locked D-167):
     *
     *  - `succeeded` → Succeeded (reconciles payment_status);
     *  - `failed` / `canceled` → Failed (flips payment_status to
     *    refund_failed, upserts PA, dispatches admin email);
     *  - `requires_action` / `pending` → no-op (row stays Pending);
     *  - anything else → log + no-op.
     *
     * Every branch is idempotent (outcome-level guards inside the settlement
     * helpers handle already-terminal rows).
     */
    public function recordStripeState(
        BookingRefund $row,
        string $stripeStatus,
        ?string $failureReason = null,
    ): void {
        switch ($stripeStatus) {
            case 'succeeded':
                $this->recordSettlementSuccess($row, (string) ($row->stripe_refund_id ?? ''));

                return;
            case 'failed':
                $this->recordSettlementFailure($row, $failureReason ?? 'Stripe reported refund failed');

                return;
            case 'canceled':
                // D-172: Stripe's `canceled` means the refund was reversed
                // (e.g. ACH NACK). Map onto Failed so the admin-facing PA
                // fires; operators need to know the funds are back on the
                // connected account.
                Log::critical('RefundService: Stripe reported refund canceled — mapping to Failed', [
                    'booking_refund_id' => $row->id,
                    'stripe_refund_id' => $row->stripe_refund_id,
                ]);
                $this->recordSettlementFailure($row, 'Stripe cancelled the refund');

                return;
            case 'requires_action':
            case 'pending':
                // No-op: leave the row at Pending. Stripe will follow up
                // with a subsequent refund.updated event; we'll converge
                // then.
                return;
            default:
                Log::warning('RefundService: unknown Stripe refund status — no state change', [
                    'booking_refund_id' => $row->id,
                    'stripe_status' => $stripeStatus,
                ]);
        }
    }

    /**
     * PAYMENTS Session 3 — idempotent settlement success.
     *
     * The webhook path calls this after matching a `booking_refunds` row by
     * `stripe_refund_id`. Already-Succeeded rows are a no-op (outcome-level
     * idempotency per locked decision #33).
     */
    public function recordSettlementSuccess(BookingRefund $row, string $stripeRefundId): void
    {
        DB::transaction(function () use ($row, $stripeRefundId): void {
            $booking = Booking::query()->whereKey($row->booking_id)->lockForUpdate()->firstOrFail();

            $fresh = $row->fresh();
            if ($fresh !== null && $fresh->status === BookingRefundStatus::Succeeded) {
                return;
            }

            $row->forceFill([
                'status' => BookingRefundStatus::Succeeded,
                'stripe_refund_id' => $stripeRefundId !== '' ? $stripeRefundId : $row->stripe_refund_id,
            ])->save();

            $this->reconcilePaymentStatus($booking);
        });
    }

    /**
     * PAYMENTS Session 3 — idempotent settlement failure.
     *
     * The webhook path calls this when `charge.refund.updated` arrives with
     * `status = failed` / `canceled`. Flips the row to Failed, sets the
     * booking's `payment_status` to `refund_failed`, upserts the PA, and
     * dispatches the admin email — same sad-path surface as the synchronous
     * disconnected-account fallback.
     */
    public function recordSettlementFailure(BookingRefund $row, ?string $failureReason): void
    {
        // F-004 (PAYMENTS Hardening Round 1): the transaction returns whether
        // the row actually transitioned from a non-terminal state to Failed.
        // Only the transition fires the side effects (Pending Action upsert
        // + admin email). A duplicate webhook for an already-failed refund
        // sees `$didTransition === false` and skips both — closing the
        // double-email path Codex F-004 documented.
        $didTransition = DB::transaction(function () use ($row, $failureReason): bool {
            $booking = Booking::query()->whereKey($row->booking_id)->lockForUpdate()->firstOrFail();

            $fresh = $row->fresh();
            if ($fresh !== null && $fresh->status === BookingRefundStatus::Failed) {
                return false;
            }

            $row->forceFill([
                'status' => BookingRefundStatus::Failed,
                'failure_reason' => $failureReason,
            ])->save();

            $booking->forceFill([
                'payment_status' => PaymentStatus::RefundFailed,
            ])->save();

            return true;
        });

        if (! $didTransition) {
            return;
        }

        $row = $row->fresh() ?? $row;
        $row->loadMissing('booking');

        if ($row->booking === null) {
            return;
        }

        $this->upsertRefundFailedPendingAction($row->booking, $row);
        $this->dispatchRefundFailedEmail($row->booking, $row);
    }

    /**
     * Recompute `booking.payment_status` from the sum of succeeded refund
     * attempts. Session 2b only hits the full-refund branch; Session 3
     * adds partial-refund logic that lands on the same helper.
     */
    private function reconcilePaymentStatus(Booking $booking): void
    {
        $succeeded = (int) $booking->bookingRefunds()
            ->where('status', BookingRefundStatus::Succeeded->value)
            ->sum('amount_cents');

        if ($succeeded <= 0) {
            return;
        }

        $paid = $booking->paid_amount_cents ?? 0;

        $newStatus = $succeeded >= $paid
            ? PaymentStatus::Refunded
            : PaymentStatus::PartiallyRefunded;

        $booking->forceFill(['payment_status' => $newStatus])->save();
    }

    /**
     * One `payment.refund_failed` Pending Action per `booking_refunds` row.
     * Keying on `payload->>'booking_refund_id'` prevents double-surface on
     * webhook replays or retries that hit the same row.
     *
     * F-004 (PAYMENTS Hardening Round 1): the insert path is wrapped in a
     * savepoint + `UniqueConstraintViolationException` catch, mirroring the
     * dispute PA pattern (D-126). The Postgres partial unique index added by
     * `2026_04_28_194851_add_refund_failed_unique_index_to_pending_actions`
     * is the DB-enforced invariant; the race-loser silently no-ops because
     * the winner already has the row.
     */
    private function upsertRefundFailedPendingAction(Booking $booking, BookingRefund $row): void
    {
        $payload = [
            'booking_refund_id' => $row->id,
            'booking_id' => $booking->id,
            'stripe_payment_intent_id' => $booking->stripe_payment_intent_id,
            'amount_cents' => $row->amount_cents,
            'currency' => $row->currency,
            'failure_reason' => $row->failure_reason,
        ];

        $existing = PendingAction::where('business_id', $booking->business_id)
            ->where('type', PendingActionType::PaymentRefundFailed->value)
            ->where('payload->booking_refund_id', $row->id)
            ->first();

        if ($existing !== null) {
            $existing->forceFill(['payload' => $payload])->save();

            return;
        }

        $insertAttributes = [
            'business_id' => $booking->business_id,
            'booking_id' => $booking->id,
            'type' => PendingActionType::PaymentRefundFailed,
            'payload' => $payload,
            'status' => PendingActionStatus::Pending,
        ];

        try {
            DB::transaction(static fn () => PendingAction::create($insertAttributes));
        } catch (UniqueConstraintViolationException) {
            // Race-loser path: a concurrent webhook for the same failed
            // refund row inserted first. The winner has the PA; we no-op.
        }
    }

    private function dispatchRefundFailedEmail(Booking $booking, BookingRefund $row): void
    {
        $booking->loadMissing(['business.admins']);

        $admins = $booking->business->admins;

        if ($admins->isEmpty()) {
            return;
        }

        Notification::send($admins, new RefundFailedNotification($booking, $row));
    }
}
