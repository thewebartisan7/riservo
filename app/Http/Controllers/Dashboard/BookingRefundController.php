<?php

namespace App\Http\Controllers\Dashboard;

use App\Enums\BusinessMemberRole;
use App\Http\Controllers\Controller;
use App\Http\Requests\Dashboard\StoreBookingRefundRequest;
use App\Models\Booking;
use App\Services\Payments\RefundService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Log;
use Stripe\Exception\ApiConnectionException;
use Stripe\Exception\ApiErrorException;
use Stripe\Exception\RateLimitException;

/**
 * PAYMENTS Session 3 — admin-triggered manual refund endpoint.
 *
 * Admin-only per locked roadmap decision #19 (money surfaces are an
 * admin-only commercial decision; staff do not refund).
 *
 * Tenant-scoped per locked decision #45: cross-business access is a 404
 * via `abort_unless`.
 *
 * Flow:
 *   1. Validate shape (`kind=full|partial`, optional `amount_cents` +
 *      `reason`) via `StoreBookingRefundRequest`.
 *   2. Dispatch `RefundService::refund($booking, $amount, 'admin-manual',
 *      auth()->id())`. The service's row-UUID idempotency key (D-162)
 *      collapses double-clicks; its `DB::transaction + lockForUpdate`
 *      serialises concurrent admins.
 *   3. Branch on outcome for flash copy:
 *        - `succeeded` → "Refund issued."
 *        - `disconnected` / `failed` → "Stripe couldn't process this refund
 *          — see the pending action."
 *        - `guard_rejected` → "This booking is no longer refundable."
 *   4. Transient Stripe errors (5xx / rate-limit / connection) surface as
 *      a "try again" flash — the service left the pending row intact so
 *      a retry converges via Stripe's idempotency.
 *   5. A partial-amount overflow (D-169) raises `ValidationException` from
 *      the service, which Laravel renders as a 422 surfaced inline in the
 *      dialog (`useForm` error rendering).
 */
class BookingRefundController extends Controller
{
    public function __construct(
        private readonly RefundService $refundService,
    ) {}

    public function store(StoreBookingRefundRequest $request, Booking $booking): RedirectResponse
    {
        $business = tenant()->business();
        abort_unless($business !== null && $booking->business_id === $business->id, 404);

        // Locked decision #19: admin-only.
        abort_unless(tenant()->role() === BusinessMemberRole::Admin, 403);

        $validated = $request->validated();
        $amountCents = $validated['kind'] === 'full'
            ? null
            : (int) $validated['amount_cents'];

        // Codex Round 1 P2: persist the admin's free-form note on the
        // `booking_refunds.admin_note` column so the audit trail matches
        // the form's promise (the dialog labels the textarea as an
        // "internal note — not shared with the customer").
        $adminNote = isset($validated['reason']) && $validated['reason'] !== ''
            ? (string) $validated['reason']
            : null;

        try {
            $result = $this->refundService->refund(
                $booking,
                $amountCents,
                'admin-manual',
                $request->user()->id,
                $adminNote,
            );
        } catch (ApiConnectionException|RateLimitException|ApiErrorException $e) {
            Log::warning('Dashboard\\BookingRefundController::store transient Stripe error', [
                'booking_id' => $booking->id,
                'exception' => $e::class,
                'message' => $e->getMessage(),
            ]);

            return back()->with('error', __('Temporary Stripe issue — try again in a minute.'));
        }

        return match ($result->outcome) {
            'succeeded' => back()->with('success', __('Refund issued.')),
            'disconnected', 'failed' => back()->with(
                'error',
                __('Stripe couldn\'t process this refund — a pending action has been created with the details.'),
            ),
            'guard_rejected' => back()->with('error', __('This booking is no longer refundable.')),
        };
    }
}
