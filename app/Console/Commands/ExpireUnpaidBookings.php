<?php

namespace App\Console\Commands;

use App\Enums\BookingStatus;
use App\Enums\PaymentStatus;
use App\Models\Booking;
use App\Services\Payments\CheckoutPromoter;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Stripe\Exception\ApiConnectionException;
use Stripe\Exception\ApiErrorException;
use Stripe\Exception\InvalidRequestException;
use Stripe\Exception\RateLimitException;
use Stripe\StripeClient;

/**
 * PAYMENTS Session 2b — locked roadmap decision #13 + #31 (reaper with
 * defense-in-depth).
 *
 * Cancels stale `pending + awaiting_payment` online bookings. "Online" here
 * means `payment_mode_at_creation = 'online'` — the reaper NEVER touches
 * `customer_choice` bookings, whose failure path (webhook-driven) keeps the
 * slot held and promotes to `Confirmed + Unpaid` (locked decision #14). The
 * base filter enforces this policy gate.
 *
 * Three stacked mitigations against the reaper-vs-webhook race per locked
 * decision #31:
 *  1. 5-minute grace buffer on `expires_at` (the reaper filter reads
 *     `expires_at < now - 5min`, not `< now`).
 *  2. Pre-flight `checkout.sessions.retrieve` on the pinned connected
 *     account (D-158). If Stripe reports the session paid or complete,
 *     `CheckoutPromoter::promote` runs inline and the cancel is SKIPPED
 *     regardless of the promoter's return value (`'paid'`, `'already_paid'`,
 *     `'not_paid'`, `'mismatch'`).
 *  3. Late-webhook refund path (see `StripeConnectWebhookController::
 *     applyLateWebhookRefund`) — if the webhook arrives after we already
 *     cancelled, the customer gets an automatic refund + admin email.
 */
#[Signature('bookings:expire-unpaid')]
#[Description('Cancel stale pending+awaiting_payment online bookings after the 90-minute Checkout window + 5-minute grace buffer elapses. Pre-flight retrieve promotes paid-but-webhook-delayed bookings inline.')]
class ExpireUnpaidBookings extends Command
{
    public function __construct(
        private readonly StripeClient $stripe,
        private readonly CheckoutPromoter $checkoutPromoter,
    ) {
        parent::__construct();
    }

    public function handle(): int
    {
        $cancelled = 0;
        $promoted = 0;
        $skipped = 0;

        Booking::query()
            ->where('status', BookingStatus::Pending)
            ->where('payment_status', PaymentStatus::AwaitingPayment)
            ->where('payment_mode_at_creation', 'online')
            ->where('expires_at', '<', now()->subMinutes(5))
            ->chunkById(100, function ($chunk) use (&$cancelled, &$promoted, &$skipped): void {
                foreach ($chunk as $booking) {
                    try {
                        $outcome = $this->processBooking($booking);

                        match ($outcome) {
                            'cancelled' => $cancelled++,
                            'promoted' => $promoted++,
                            default => $skipped++,
                        };
                    } catch (\Throwable $e) {
                        // One booking's failure must not abort the batch.
                        report($e);
                        Log::warning('ExpireUnpaidBookings: exception while processing booking — skipping this tick', [
                            'booking_id' => $booking->id,
                            'exception' => $e::class,
                            'message' => $e->getMessage(),
                        ]);
                        $skipped++;
                    }
                }
            });

        $this->info("Reaper: cancelled={$cancelled} promoted={$promoted} skipped={$skipped}");

        return self::SUCCESS;
    }

    /** @return 'cancelled'|'promoted'|'skipped' */
    private function processBooking(Booking $booking): string
    {
        $sessionId = $booking->stripe_checkout_session_id;
        $accountId = $booking->stripe_connected_account_id;

        if (! is_string($sessionId) || $sessionId === ''
            || ! is_string($accountId) || $accountId === '') {
            Log::critical('ExpireUnpaidBookings: booking missing session_id or connected_account_id — cannot pre-flight', [
                'booking_id' => $booking->id,
                'stripe_checkout_session_id' => $sessionId,
                'stripe_connected_account_id' => $accountId,
            ]);

            return 'skipped';
        }

        // Pre-flight retrieve (locked decision #31.2).
        // Codex Round 3 (F1): Stripe PHP SDK signature is
        // `retrieve($id, $params = null, $opts = null)`. Passing the
        // `stripe_account` as the 2nd arg treats it as request params
        // (which the Checkout endpoint ignores), dropping the
        // Stripe-Account header entirely — the lookup then runs against
        // the platform account and 404s, which the 4xx catch below
        // treats as "session gone" and cancels the booking. The header
        // MUST ride the 3rd arg so the call targets the connected
        // account that minted the session.
        try {
            $session = $this->stripe->checkout->sessions->retrieve(
                $sessionId,
                null,
                ['stripe_account' => $accountId],
            );
        } catch (RateLimitException $e) {
            // Codex Round 2 (F1): `RateLimitException` (HTTP 429) extends
            // `InvalidRequestException` in the Stripe PHP SDK, so without
            // this narrower catch a throttle response would fall into the
            // 4xx=cancel branch below and free a slot for a booking that
            // is still legitimately awaiting payment. Throttles are
            // retryable — leave for next tick.
            Log::warning('ExpireUnpaidBookings: Stripe 429 on retrieve — leaving for next tick', [
                'booking_id' => $booking->id,
                'session_id' => $sessionId,
                'message' => $e->getMessage(),
            ]);

            return 'skipped';
        } catch (InvalidRequestException $e) {
            // HTTP 4xx — most commonly "No such checkout session". The
            // session really is gone; proceed with the cancel.
            Log::info('ExpireUnpaidBookings: Stripe returned 4xx on retrieve — treating as session-gone and cancelling', [
                'booking_id' => $booking->id,
                'session_id' => $sessionId,
                'message' => $e->getMessage(),
            ]);

            return $this->cancelBooking($booking) ? 'cancelled' : 'skipped';
        } catch (ApiConnectionException $e) {
            // Network / 5xx — leave for next tick.
            Log::warning('ExpireUnpaidBookings: Stripe connection error on retrieve — leaving for next tick', [
                'booking_id' => $booking->id,
                'session_id' => $sessionId,
                'message' => $e->getMessage(),
            ]);

            return 'skipped';
        } catch (ApiErrorException $e) {
            $status = $e->getHttpStatus();
            if ($status !== null && $status >= 500) {
                Log::warning('ExpireUnpaidBookings: Stripe 5xx on retrieve — leaving for next tick', [
                    'booking_id' => $booking->id,
                    'session_id' => $sessionId,
                    'http_status' => $status,
                    'message' => $e->getMessage(),
                ]);

                return 'skipped';
            }

            Log::warning('ExpireUnpaidBookings: Stripe 4xx on retrieve — cancelling', [
                'booking_id' => $booking->id,
                'session_id' => $sessionId,
                'http_status' => $status,
                'message' => $e->getMessage(),
            ]);

            return $this->cancelBooking($booking) ? 'cancelled' : 'skipped';
        }

        $stripeStatus = (string) ($session->status ?? '');
        $paymentStatus = (string) ($session->payment_status ?? '');

        if ($stripeStatus === 'complete' || $paymentStatus === 'paid') {
            // Paid but webhook-delayed: let the promoter run inline. Skip
            // the cancel regardless of the promoter's return — mismatch
            // case already logged critical inside the promoter; cancelling
            // on mismatch could free a paid slot.
            $this->checkoutPromoter->promote($booking, $session);

            return 'promoted';
        }

        // Stripe says `expired` or `open + unpaid` — safe to cancel.
        return $this->cancelBooking($booking) ? 'cancelled' : 'skipped';
    }

    private function cancelBooking(Booking $booking): bool
    {
        $cancelled = false;

        DB::transaction(function () use ($booking, &$cancelled): void {
            $locked = Booking::query()
                ->whereKey($booking->id)
                ->lockForUpdate()
                ->first();

            if ($locked === null) {
                return;
            }

            // Outcome-level guard inside the lock: concurrent
            // webhook-promotion or admin action may have moved the row.
            if ($locked->status !== BookingStatus::Pending) {
                return;
            }

            if ($locked->payment_status !== PaymentStatus::AwaitingPayment) {
                return;
            }

            $locked->forceFill([
                'status' => BookingStatus::Cancelled,
                'payment_status' => PaymentStatus::NotApplicable,
                'expires_at' => null,
            ])->save();

            $cancelled = true;
        });

        if ($cancelled) {
            Log::info('ExpireUnpaidBookings: booking cancelled', [
                'booking_id' => $booking->id,
            ]);
        }

        return $cancelled;
    }
}
