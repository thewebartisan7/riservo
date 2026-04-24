<?php

namespace App\Http\Controllers\Dashboard;

use App\Enums\BusinessMemberRole;
use App\Enums\PendingActionStatus;
use App\Enums\PendingActionType;
use App\Http\Controllers\Controller;
use App\Models\PendingAction;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * PAYMENTS Session 2b — resolve payment-typed Pending Actions (admin-only
 * per locked roadmap decisions #19 / #31 / #35).
 *
 * Session 2b owns two types:
 *  - `payment.cancelled_after_payment` — late-webhook refund dispatched,
 *    admin needs to reach out to the customer.
 *  - `payment.refund_failed` — Stripe refused the refund (typically a
 *    disconnected connected account); admin reconnects or refunds offline.
 *
 * Session 3 adds `payment.dispute_opened` resolution; this controller is
 * the shared surface. The existing `CalendarPendingActionController` stays
 * calendar-only (type-bucketed per D-113).
 *
 * Tenant scoping per locked decision #45: `abort_unless($action->business_id
 * === tenant()->businessId(), 404)` rejects cross-tenant access as a
 * 404 not-found (never a silent read, never a silent write).
 */
class PaymentPendingActionController extends Controller
{
    public function resolve(Request $request, PendingAction $action): RedirectResponse
    {
        $business = tenant()->business();

        abort_unless($business !== null && $action->business_id === $business->id, 404);

        // Admin-only per locked roadmap decisions #19 / #31 / #35. Staff
        // handle bookings; admins handle money.
        abort_unless(tenant()->role() === BusinessMemberRole::Admin, 403);

        if (! in_array($action->type, [
            PendingActionType::PaymentCancelledAfterPayment,
            PendingActionType::PaymentRefundFailed,
        ], true)) {
            abort(404);
        }

        if ($action->status !== PendingActionStatus::Pending) {
            return back()->with('error', __('This action has already been resolved.'));
        }

        $action->forceFill([
            'status' => PendingActionStatus::Resolved,
            'resolved_by_user_id' => $request->user()->id,
            'resolved_at' => now(),
        ])->save();

        return back()->with('success', __('Action resolved.'));
    }
}
