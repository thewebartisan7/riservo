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
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;
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
 * `business-rejected-pending`) and partial-refund support WITHOUT changing
 * the signature or the `booking_refunds` schema.
 *
 * Key invariants:
 *
 *  - One `booking_refunds` row per refund ATTEMPT (locked decision #36). The
 *    row's `uuid` is the source of the Stripe idempotency key. A mid-response
 *    retry reuses the row + UUID; Stripe collapses the duplicate.
 *  - Refund amount is always derived from `paid_amount_cents` / `currency`
 *    captured on the booking at creation (locked decisions #37 + #42 + D-152).
 *    `Service.price` is never read here.
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
     * only; other callers are added in Session 3.
     *
     * @param  int|null  $amountCents  when null, refund the full remaining
     *                                 refundable amount (the only Session 2b
     *                                 mode). Partial-refund support arrives
     *                                 in Session 3.
     */
    public function refund(
        Booking $booking,
        ?int $amountCents = null,
        string $reason = 'cancelled-after-payment',
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

        DB::transaction(function () use ($booking, $amountCents, $reason, &$row, &$guardRejection): void {
            Booking::query()->whereKey($booking->id)->lockForUpdate()->first();

            // Retry-path lookup: if a pending row exists for this
            // booking+reason, reuse it regardless of the remaining-cents
            // clamp (the row already represents this intent).
            $existing = $booking->bookingRefunds()
                ->where('status', BookingRefundStatus::Pending->value)
                ->where('reason', $reason)
                ->latest('id')
                ->first();

            if ($existing !== null) {
                $row = $existing;

                return;
            }

            // Fresh-insert path: recompute the clamp inside the lock.
            $requestedAmount = $amountCents ?? $booking->paid_amount_cents;
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

            if ($requestedAmount > $remaining) {
                // Session 2b only ever passes $amountCents = null (full
                // refund), so this path is defensive. Session 3's admin
                // UI clamps at the client; this is the server-side second
                // check per locked decision #37.
                Log::warning('RefundService: requested amount exceeds remaining refundable — clamping to remaining', [
                    'booking_id' => $booking->id,
                    'requested' => $requestedAmount,
                    'remaining' => $remaining,
                ]);
                $requestedAmount = $remaining;
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
                'initiated_by_user_id' => null,
                'failure_reason' => null,
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

        PendingAction::create([
            'business_id' => $booking->business_id,
            'booking_id' => $booking->id,
            'type' => PendingActionType::PaymentRefundFailed,
            'payload' => $payload,
            'status' => PendingActionStatus::Pending,
        ]);
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
