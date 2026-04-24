<?php

namespace App\Http\Controllers\Booking;

use App\Enums\BookingStatus;
use App\Enums\ConfirmationMode;
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

        // Codex Round 3 (F2): Session 2b's late-webhook refund path lands
        // bookings at `Cancelled + Refunded` / `Cancelled + RefundFailed`
        // / transient `Cancelled + Paid`. The customer's browser still
        // redirects here with the same `session_id`; without this guard
        // the promoter would see `payment_status !== Paid` (if Refunded),
        // call retrieve, and `forceFill(['status' => Confirmed, ...])` —
        // reopening a slot the reaper already released and potentially
        // already refunded. Redirect to the booking-detail page with a
        // neutral flash; the booking page itself shows the accurate
        // Cancelled + Refunded state.
        if ($booking->status === BookingStatus::Cancelled) {
            return redirect()
                ->route('bookings.show', $token)
                ->with('error', __("Your booking wasn't confirmed in time. Your slot has been released; please contact the business if you have questions."));
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
            // Codex Round 3 (F1 sibling): Stripe PHP SDK signature is
            // `retrieve($id, $params = null, $opts = null)`. Pass the
            // `stripe_account` header as the 3rd arg (options), not the
            // 2nd (params) — otherwise Stripe treats it as a request
            // parameter, drops the header, and the call falls back to
            // the platform account which 404s. The existing Session 2a
            // FakeStripeClient mock matched positional args loosely so
            // the bug didn't surface in tests.
            $session = $this->stripe->checkout->sessions->retrieve(
                $sessionId,
                null,
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
     * PAYMENTS Session 2b: `cancel_url` landing. The state transition is
     * OWNED by the webhook path (locked decision #31 idempotency contract +
     * D-151 — `CheckoutPromoter` is the single source of truth). This
     * handler mutates nothing; it only chooses the flash copy based on
     * the booking's immutable `payment_mode_at_creation` snapshot.
     *
     * Why branch on the snapshot and not on current status: the cancel_url
     * typically lands BEFORE the webhook's state transition has fired.
     * Reading `status` or `payment_status` here would show stale copy;
     * the snapshot reflects the business's INTENT at booking time and
     * drives the expected landing regardless of race timing.
     *
     * Copy branches per the roadmap:
     *  - `online` → "Payment not completed. Your slot has been released."
     *    (The webhook failure arm will Cancel the booking; slot frees.)
     *  - `customer_choice` → "Your booking is confirmed — pay at the
     *    appointment." (The webhook failure arm promotes to
     *    `Confirmed + Unpaid` or `Pending + Unpaid` under manual-confirm.)
     *  - Disconnected-account race (business disconnected Stripe between
     *    booking creation and customer return) → a stable "contact them
     *    directly" copy. Session 5 polishes.
     */
    public function cancel(string $token): RedirectResponse
    {
        $booking = Booking::where('cancellation_token', $token)
            ->with('business')
            ->firstOrFail();

        // Default-scoped `stripeConnectedAccount` relation is SoftDeletes-
        // aware — returns null when the business has no active connected
        // account (disconnected between booking creation and return).
        // Distinct from the D-158 pinned column on the booking, which is
        // always populated for online bookings and survives disconnect.
        $connectedAccount = $booking->business->stripeConnectedAccount;

        if ($connectedAccount === null) {
            return redirect()
                ->route('bookings.show', $token)
                ->with('error', __('This business is no longer accepting online payments — please contact them directly.'));
        }

        if ($booking->payment_mode_at_creation === 'customer_choice') {
            // Codex Round 2 (F4): the webhook failure branch lands
            // customer_choice + manual-confirm bookings at
            // `Pending + Unpaid` per locked decision #29 variant, not
            // `Confirmed`. Flashing "your booking is confirmed" would
            // contradict the booking-detail page + the pending-awaiting-
            // confirmation customer email. Branch on the business's
            // confirmation_mode snapshot.
            $copy = $booking->business->confirmation_mode === ConfirmationMode::Manual
                ? __('Your booking request has been received — the business will confirm, and you can pay at the appointment.')
                : __('Your booking is confirmed — pay at the appointment.');

            return redirect()
                ->route('bookings.show', $token)
                ->with('success', $copy);
        }

        return redirect()
            ->route('bookings.show', $token)
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
