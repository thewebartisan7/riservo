<?php

namespace App\Services\Payments;

use App\Enums\BookingStatus;
use App\Enums\ConfirmationMode;
use App\Enums\PaymentStatus;
use App\Models\Booking;
use App\Notifications\BookingConfirmedNotification;
use App\Notifications\BookingReceivedNotification;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Notification;
use Stripe\Checkout\Session as StripeCheckoutSession;

/**
 * Shared promoter for the PAYMENTS Session 2a happy path.
 *
 * Both the `checkout.session.completed` webhook handler on
 * `StripeConnectWebhookController` AND `BookingPaymentReturnController::success`
 * call this service. The DB-state guard (locked roadmap decision #33) lives
 * here ONCE so the two code paths cannot drift on maintenance.
 *
 * Serialisation: the state transition is wrapped in a `DB::transaction` with
 * `lockForUpdate` on the booking row (same pattern D-148 uses for the
 * Connect controller's refresh/resume handlers). Two concurrent paths
 * racing through the promoter either both converge on the same paid state
 * via the outcome-level guard, or serialise cleanly with one promoting and
 * the other no-opping.
 *
 * Return contract (scalar string, kept simple for PHPStan level-5 inference
 * of `DB::transaction`'s callback return):
 *   - 'paid'         — promoted in this call; notifications were dispatched.
 *   - 'already_paid' — outcome-level guard short-circuited; nothing changed.
 *   - 'not_paid'     — Stripe session's payment_status is not 'paid' (async
 *                      flow); caller should render a "processing" state.
 *
 * Branches on `$session->payment_status` per locked decision #41 — NOT on
 * event name. TWINT is nominally synchronous today but a future Stripe
 * flip to async won't regress this path.
 */
final class CheckoutPromoter
{
    /**
     * @return 'paid'|'already_paid'|'not_paid'|'mismatch'
     */
    public function promote(Booking $booking, StripeCheckoutSession $session): string
    {
        // Codex adversarial review Round 1 (F2): defend against a session
        // on the same connected account whose `client_reference_id` happens
        // to collide with a riservo booking id — operator running a second
        // Stripe integration, manual Stripe-dashboard session with a reused
        // client_reference_id, or a hostile submission. The webhook handler
        // looks bookings up by client_reference_id; the success-page
        // controller by the cancellation token + query session_id. Both
        // paths funnel here; enforcing the session-id match at the SHARED
        // boundary closes the trust hole in one place. A mismatch is
        // pathological — fail closed and log for manual reconciliation.
        $sessionId = is_string($session->id ?? null) ? $session->id : null;

        if ($sessionId === null || $booking->stripe_checkout_session_id === null
            || $sessionId !== $booking->stripe_checkout_session_id) {
            Log::critical('CheckoutPromoter: session id does not match booking.stripe_checkout_session_id', [
                'booking_id' => $booking->id,
                'session_id' => $sessionId,
                'expected_session_id' => $booking->stripe_checkout_session_id,
            ]);

            return 'mismatch';
        }

        $sessionPaymentStatus = (string) ($session->payment_status ?? '');

        if ($sessionPaymentStatus !== 'paid') {
            // Async flow: we'll hear back on
            // `checkout.session.async_payment_succeeded` and re-enter with
            // a paid session. No DB write here.
            return 'not_paid';
        }

        $outcome = 'already_paid';
        $promotedBooking = null;

        DB::transaction(function () use ($booking, $session, &$outcome, &$promotedBooking): void {
            $locked = Booking::query()
                ->whereKey($booking->id)
                ->lockForUpdate()
                ->first();

            if ($locked === null) {
                // The booking was hard-deleted between the caller's read and
                // our lock acquisition — extremely unlikely but don't explode.
                Log::warning('CheckoutPromoter: booking disappeared before lock acquisition', [
                    'booking_id' => $booking->id,
                ]);
                $outcome = 'already_paid';

                return;
            }

            // Outcome-level idempotency (locked decision #33). Replays,
            // inline-promotion-then-webhook races, and cache flushes are all
            // safe: the second caller through this branch no-ops.
            if ($locked->payment_status === PaymentStatus::Paid) {
                $outcome = 'already_paid';

                return;
            }

            // Codex adversarial review Round 1 (F2 — supersedes D-152's
            // "log and overwrite" stance): a divergence between the
            // booking's captured `paid_amount_cents` / `currency` and the
            // Stripe session's `amount_total` / `currency` is pathological.
            // For a fixed-amount Checkout session with a unit_amount we
            // set at creation, the values MUST match. A mismatch is either
            // a coding bug, a Stripe-side error, or a hostile reconciliation
            // via client_reference_id collision. Silently overwriting with
            // Stripe's figures would confirm an attack or bury a bug;
            // refuse to promote and log for manual investigation. Operators
            // see the critical log and reconcile the specific booking.
            $stripeAmount = (int) ($session->amount_total ?? 0);
            $stripeCurrency = is_string($session->currency ?? null)
                ? strtolower((string) $session->currency)
                : null;

            if ($locked->paid_amount_cents !== null && $locked->paid_amount_cents !== $stripeAmount) {
                Log::critical('CheckoutPromoter: paid_amount_cents mismatch — refusing to promote', [
                    'booking_id' => $locked->id,
                    'local_cents' => $locked->paid_amount_cents,
                    'stripe_cents' => $stripeAmount,
                    'session_id' => $session->id ?? null,
                ]);
                $outcome = 'mismatch';

                return;
            }

            if ($locked->currency !== null && $stripeCurrency !== null && $locked->currency !== $stripeCurrency) {
                Log::critical('CheckoutPromoter: currency mismatch — refusing to promote', [
                    'booking_id' => $locked->id,
                    'local_currency' => $locked->currency,
                    'stripe_currency' => $stripeCurrency,
                    'session_id' => $session->id ?? null,
                ]);
                $outcome = 'mismatch';

                return;
            }

            // Locked decision #29: manual-confirmation businesses keep the
            // booking at `pending` even after payment. Admin Confirm / Reject
            // (Session 3) promotes or triggers a refund.
            $targetStatus = $locked->business->confirmation_mode === ConfirmationMode::Manual
                ? BookingStatus::Pending
                : BookingStatus::Confirmed;

            $locked->forceFill([
                'status' => $targetStatus,
                'payment_status' => PaymentStatus::Paid,
                'stripe_payment_intent_id' => is_string($session->payment_intent ?? null)
                    ? $session->payment_intent
                    : null,
                'paid_amount_cents' => $stripeAmount,
                'currency' => $stripeCurrency ?? $locked->currency,
                'paid_at' => now(),
                'expires_at' => null,
            ])->save();

            $outcome = 'paid';
            $promotedBooking = $locked;
        });

        if ($outcome === 'paid' && $promotedBooking !== null) {
            $this->dispatchNotifications($promotedBooking);
        }

        return $outcome;
    }

    /**
     * Fire the appropriate customer + staff notifications for the post-
     * promotion state. Manual-confirmation businesses send the
     * `paid_awaiting_confirmation` context to the customer (locked decision
     * #29) — the admin receives the existing `'new'` context either way.
     *
     * google_calendar-sourced bookings never reach this path (they write
     * payment_mode_at_creation = 'offline' at creation, so no Checkout
     * session exists); `shouldSuppressCustomerNotifications()` is checked
     * defensively anyway.
     */
    private function dispatchNotifications(Booking $booking): void
    {
        $booking->loadMissing(['customer', 'business.admins', 'provider.user', 'service']);

        if ($booking->shouldSuppressCustomerNotifications()) {
            return;
        }

        $customer = $booking->customer;

        if ($booking->status === BookingStatus::Confirmed) {
            if ($customer !== null) {
                Notification::route('mail', $customer->email)
                    ->notify(new BookingConfirmedNotification($booking));
            }
            $this->notifyStaff($booking, 'new');

            return;
        }

        // Pending + Paid: manual-confirmation branch (locked decision #29).
        if ($customer !== null) {
            Notification::route('mail', $customer->email)
                ->notify(new BookingReceivedNotification($booking, 'paid_awaiting_confirmation'));
        }
        $this->notifyStaff($booking, 'new');
    }

    private function notifyStaff(Booking $booking, string $context): void
    {
        $notification = new BookingReceivedNotification($booking, $context);

        $staffUsers = $booking->business->admins
            ->when($booking->provider?->user, fn ($c) => $c->merge([$booking->provider->user]))
            ->unique('id');

        Notification::send($staffUsers, $notification);
    }
}
