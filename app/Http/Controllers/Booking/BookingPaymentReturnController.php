<?php

namespace App\Http\Controllers\Booking;

use App\Enums\BookingStatus;
use App\Http\Controllers\Controller;
use App\Models\Booking;
use App\Services\Payments\CheckoutPromoter;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Inertia\Inertia;
use Inertia\Response as InertiaResponse;
use Stripe\Exception\ApiErrorException;
use Stripe\StripeClient;

/**
 * PAYMENTS Session 2a: return-URL landings for the hosted Stripe Checkout
 * flow.
 *
 *  - `success()` (GET /bookings/{token}/payment-success) — Stripe's
 *    `success_url`. Per locked decision #32 the handler performs a
 *    synchronous `checkout.sessions.retrieve(...)` on the connected
 *    account and promotes the booking inline if the session's
 *    `payment_status = 'paid'`. This eliminates the "return page shows
 *    awaiting_payment" race — the webhook remains the authoritative
 *    backstop for customers who close the tab before the redirect.
 *  - `cancel()` (GET /bookings/{token}/payment-cancel) — Stripe's
 *    `cancel_url`. Session 2a keeps this a stub: redirect back to the
 *    public booking page with an informational flash. The actual slot
 *    release + customer_choice unpaid promotion are webhook-driven
 *    (Session 2b).
 *
 * Token auth: the booking's `cancellation_token` is the bearer secret,
 * reused from the existing `bookings.show` / `bookings.cancel` shape.
 * The handler additionally cross-checks the query `session_id` against
 * the booking's persisted `stripe_checkout_session_id` to reject a
 * session-substitution attempt.
 */
final class BookingPaymentReturnController extends Controller
{
    public function __construct(
        private readonly StripeClient $stripe,
        private readonly CheckoutPromoter $checkoutPromoter,
    ) {}

    public function success(string $token, Request $request): RedirectResponse|InertiaResponse
    {
        $booking = Booking::where('cancellation_token', $token)->firstOrFail();

        $sessionId = $request->query('session_id');

        // Token auth covers "who can view this booking"; the session_id
        // cross-check closes the hostile-substitution hole: a malicious
        // actor can't redeem another customer's session by swapping the
        // query param, because the booking row carries its own
        // authoritative session id.
        if (! is_string($sessionId) || $sessionId === '' || $booking->stripe_checkout_session_id !== $sessionId) {
            if ($sessionId !== null && $sessionId !== '') {
                Log::warning('BookingPaymentReturnController::success: session_id query mismatch', [
                    'booking_id' => $booking->id,
                    'query_session_id' => $sessionId,
                    'expected_session_id' => $booking->stripe_checkout_session_id,
                ]);
            }

            return redirect()->route('bookings.show', $token);
        }

        // Outcome-level fast path: the webhook (or a prior success-page
        // hit) may already have promoted the booking. No retrieve needed.
        if ($booking->payment_status->value === 'paid') {
            return redirect()
                ->route('bookings.show', $token)
                ->with('success', $this->successFlashFor($booking));
        }

        // Codex Round 2 (D-158): read the minting account off the booking
        // itself, not by looking up the business's connected-account rows.
        // A business with reconnect history has multiple rows; a
        // `withTrashed()->value()` lookup returned a non-deterministic
        // match. The booking remembers exactly which account minted its
        // Checkout session, so the retrieve reliably targets that account
        // even after disconnect.
        $acct = $booking->stripe_connected_account_id;

        if (! is_string($acct) || $acct === '') {
            Log::critical('BookingPaymentReturnController::success: booking missing stripe_connected_account_id', [
                'booking_id' => $booking->id,
                'business_id' => $booking->business_id,
            ]);

            return redirect()
                ->route('bookings.show', $token)
                ->with('error', __("Your booking is pending — we'll follow up."));
        }

        try {
            $session = $this->stripe->checkout->sessions->retrieve(
                $sessionId,
                ['stripe_account' => $acct],
            );
        } catch (ApiErrorException $e) {
            report($e);

            return redirect()
                ->route('bookings.show', $token)
                ->with('error', __('Still processing — check back in a moment.'));
        }

        $outcome = $this->checkoutPromoter->promote($booking, $session);

        if ($outcome === 'paid' || $outcome === 'already_paid') {
            // Codex Round 2 (D-160): manual-confirmation businesses land
            // the booking at `pending + paid` per locked decision #29.
            // Flashing "your booking is confirmed" unconditionally would
            // contradict the booking's actual status, the paid_awaiting_
            // confirmation email, and the business's own admin queue.
            // Branch on the fresh booking state.
            return redirect()
                ->route('bookings.show', $token)
                ->with('success', $this->successFlashFor($booking->fresh() ?? $booking));
        }

        // Codex adversarial review Round 1 (F2): `'mismatch'` means the
        // session id, amount, or currency didn't line up — the promoter
        // already logged critical for operator reconciliation. Don't
        // render the success flash (that would mislead the customer);
        // instead redirect to the booking page with a neutral
        // "we'll follow up" message. The alternative — rendering a
        // hard error — surfaces too much internal detail.
        if ($outcome === 'mismatch') {
            return redirect()
                ->route('bookings.show', $token)
                ->with('error', __("Your booking is pending — we'll follow up."));
        }

        // $outcome === 'not_paid': async-pending session. Render the
        // processing landing and let the webhook resolve state.
        return Inertia::render('booking/payment-success', [
            'state' => 'processing',
            'booking' => [
                'token' => $booking->cancellation_token,
                'business' => ['name' => $booking->business->name],
            ],
        ]);
    }

    /**
     * Session 2a stub for Stripe's `cancel_url`. Session 2b wires the
     * branching (online → slot released; customer_choice → confirmed +
     * unpaid) on the webhook path; this URL is informational only.
     */
    public function cancel(string $token): RedirectResponse
    {
        $booking = Booking::where('cancellation_token', $token)
            ->with('business')
            ->firstOrFail();

        return redirect()
            ->route('booking.show', ['slug' => $booking->business->slug])
            ->with('error', __('Payment not completed. Your slot has been released.'));
    }

    /**
     * Codex Round 2 (D-160): flash copy must match the booking's actual
     * landing state. Manual-confirmation businesses (locked decision #29)
     * keep the booking at `Pending` even after payment — "your booking is
     * confirmed" would contradict the business's approval queue and the
     * paid_awaiting_confirmation email the customer just received.
     */
    private function successFlashFor(Booking $booking): string
    {
        return $booking->status === BookingStatus::Pending
            ? __('Payment received — your booking is pending confirmation from the business.')
            : __('Payment received — your booking is confirmed.');
    }
}
