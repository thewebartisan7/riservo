<?php

namespace App\Http\Controllers\Dashboard;

use App\Enums\BusinessMemberRole;
use App\Enums\PendingActionStatus;
use App\Enums\PendingActionType;
use App\Http\Controllers\Controller;
use App\Models\Booking;
use App\Models\BookingRefund;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Log;

/**
 * PAYMENTS Hardening Round 2 — D-184.
 *
 * Three admin-only redirect endpoints that resolve a Stripe dashboard
 * deeplink server-side and 302 to it. The Codex Round 2 finding G-001
 * documented that the prior shape (raw `acct_…`, `pi_…`, `ch_…`, `re_…`,
 * `dp_…` IDs riding the Inertia booking-detail prop) violated the
 * prop-contract: any future XSS / browser extension / exception reporter
 * could harvest Stripe identifiers from page JSON.
 *
 * Pattern:
 *   - Admin-only role gate (locked decision #19, money surfaces).
 *   - Tenant scoping via `tenant()->business()` — the booking must belong
 *     to the current tenant (mirrors `BookingRefundController::store`'s
 *     `abort_unless` shape).
 *   - The dispute deeplink reads `payload->>'dispute_id'` from the booking's
 *     open dispute Pending Action — a dispute id is NEVER accepted from the
 *     URL.
 *   - The refund deeplink uses route-model-binding on `BookingRefund` and
 *     additionally asserts the refund row belongs to the route-bound
 *     booking; cross-booking refund attempts return 404.
 *   - Missing-id paths (booking has no `stripe_charge_id` yet, or no open
 *     dispute) return 404 with a logged warning, not a redirect to a
 *     malformed Stripe URL.
 */
class StripeDashboardLinkController extends Controller
{
    public function payment(Booking $booking): RedirectResponse
    {
        $this->assertAdminTenantOwns($booking);

        $accountId = $booking->stripe_connected_account_id;
        $chargeOrIntent = $booking->stripe_charge_id ?? $booking->stripe_payment_intent_id;

        if (! is_string($accountId) || $accountId === '' || ! is_string($chargeOrIntent) || $chargeOrIntent === '') {
            Log::info('StripeDashboardLinkController::payment requested for booking with no Stripe handle', [
                'booking_id' => $booking->id,
            ]);
            abort(404);
        }

        return redirect()->away(sprintf(
            'https://dashboard.stripe.com/%s/payments/%s',
            $accountId,
            $chargeOrIntent,
        ));
    }

    public function refund(Booking $booking, BookingRefund $refund): RedirectResponse
    {
        $this->assertAdminTenantOwns($booking);

        // Cross-booking refund: the route binds both ids, but the URL could
        // still nest a refund row from another booking. Refuse.
        abort_unless($refund->booking_id === $booking->id, 404);

        $accountId = $booking->stripe_connected_account_id;
        $refundId = $refund->stripe_refund_id;

        if (! is_string($accountId) || $accountId === '' || ! is_string($refundId) || $refundId === '') {
            abort(404);
        }

        return redirect()->away(sprintf(
            'https://dashboard.stripe.com/%s/refunds/%s',
            $accountId,
            $refundId,
        ));
    }

    public function dispute(Booking $booking): RedirectResponse
    {
        $this->assertAdminTenantOwns($booking);

        $accountId = $booking->stripe_connected_account_id;

        if (! is_string($accountId) || $accountId === '') {
            abort(404);
        }

        // Pull the dispute id from the booking's most-recent OPEN dispute PA's
        // payload — the URL never carries a user-controlled dispute id.
        //
        // H-001 (Codex Round 3): the prior implementation queried only on
        // `type` and would happily redirect using a resolved historical PA,
        // contradicting D-184's "no open dispute → 404" intent. The
        // `status = pending` filter mirrors the booking-list eager-load
        // (BookingController::index) so the deeplink behaviour matches the
        // Inertia banner's visibility contract.
        $disputeAction = $booking->pendingActions()
            ->where('type', PendingActionType::PaymentDisputeOpened->value)
            ->where('status', PendingActionStatus::Pending->value)
            ->latest('id')
            ->first();

        $disputeId = is_array($disputeAction?->payload)
            ? ($disputeAction->payload['dispute_id'] ?? null)
            : null;

        if (! is_string($disputeId) || $disputeId === '') {
            abort(404);
        }

        return redirect()->away(sprintf(
            'https://dashboard.stripe.com/%s/disputes/%s',
            $accountId,
            $disputeId,
        ));
    }

    private function assertAdminTenantOwns(Booking $booking): void
    {
        $business = tenant()->business();

        abort_unless($business !== null && $booking->business_id === $business->id, 404);
        abort_unless(tenant()->role() === BusinessMemberRole::Admin, 403);
    }
}
