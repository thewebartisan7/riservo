<?php

namespace App\Http\Controllers\Booking;

use App\Enums\BookingStatus;
use App\Enums\PaymentStatus;
use App\Http\Controllers\Controller;
use App\Jobs\Calendar\PushBookingToCalendarJob;
use App\Models\Booking;
use App\Notifications\BookingCancelledNotification;
use App\Services\Payments\RefundService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Notification;
use Inertia\Inertia;
use Inertia\Response;
use Stripe\Exception\ApiConnectionException;
use Stripe\Exception\ApiErrorException;
use Stripe\Exception\RateLimitException;

class BookingManagementController extends Controller
{
    public function show(string $token): Response
    {
        // Codex Round 2 P3: eager-load `bookingRefunds` so
        // `refundStatusLine()` reads from the loaded collection instead of
        // hitting the DB on every render of a single booking page.
        $booking = Booking::where('cancellation_token', $token)
            ->with(['service', 'provider.user', 'business', 'customer', 'bookingRefunds'])
            ->firstOrFail();

        return Inertia::render('bookings/show', [
            'booking' => [
                'id' => $booking->id,
                'token' => $booking->cancellation_token,
                'starts_at' => $booking->starts_at->toISOString(),
                'ends_at' => $booking->ends_at->toISOString(),
                'status' => $booking->status->value,
                'notes' => $booking->notes,
                'service' => [
                    'name' => $booking->service->name,
                    'duration_minutes' => $booking->service->duration_minutes,
                    'price' => $booking->service->price,
                ],
                'provider' => [
                    'name' => $booking->provider->user->name ?? '',
                    'is_active' => ! $booking->provider->trashed(),
                ],
                'business' => [
                    'name' => $booking->business->name,
                    'timezone' => $booking->business->timezone,
                    'cancellation_window_hours' => $booking->business->cancellation_window_hours,
                ],
                'customer' => [
                    'name' => $booking->customer->name,
                ],
                'can_cancel' => $this->canCancel($booking),
                // PAYMENTS Session 2a: the bookings/show page renders a
                // payment badge + paid-amount row when this branch is
                // populated. awaiting_payment bookings surface a resume
                // link built from stripe_checkout_session_id.
                'payment' => [
                    'status' => $booking->payment_status->value,
                    'paid_amount_cents' => $booking->paid_amount_cents,
                    'currency' => $booking->currency,
                    'paid_at' => $booking->paid_at?->toISOString(),
                    'expires_at' => $booking->expires_at?->toISOString(),
                    'stripe_checkout_session_id' => $booking->stripe_checkout_session_id,
                ],
                // PAYMENTS Session 3: customer-facing refund status line
                // (null when no refund attempts). Copy branches on the
                // latest attempt + remaining refundable + pinned-account
                // disconnect state. See Booking::refundStatusLine().
                'refund_status_line' => $booking->refundStatusLine(),
            ],
        ]);
    }

    public function cancel(string $token, RefundService $refundService): RedirectResponse
    {
        $booking = Booking::where('cancellation_token', $token)
            ->with('business')
            ->firstOrFail();

        if (! in_array($booking->status, [BookingStatus::Pending, BookingStatus::Confirmed])) {
            return back()->with('error', __('This booking cannot be cancelled.'));
        }

        if (! $this->canCancel($booking)) {
            return back()->with('error', __('This booking can no longer be cancelled. The cancellation window has passed.'));
        }

        // PAYMENTS Session 3 (locked decisions #15 / #16): an in-window
        // customer cancellation on a paid (or partially-refunded) booking
        // dispatches an automatic full refund BEFORE the status transition.
        // D-157 / D-159 used to block this path with an error flash; now
        // `RefundService::refund($booking, null, 'customer-requested')`
        // handles the refund + sad-path surfaces, the booking flips to
        // Cancelled regardless of the refund outcome, and the customer gets
        // tailored flash copy per outcome.
        //
        // Transient Stripe errors (5xx / rate-limit / connection drop) abort
        // with 503 so the user can retry without a status transition — the
        // service leaves its pending row intact so the retry converges via
        // the Stripe idempotency key (locked decision #36 + D-162).
        $refundOutcome = null;
        $isPaid = in_array($booking->payment_status, [PaymentStatus::Paid, PaymentStatus::PartiallyRefunded], true);

        if ($isPaid) {
            try {
                $result = $refundService->refund($booking, null, 'customer-requested');
            } catch (ApiConnectionException|RateLimitException|ApiErrorException $e) {
                Log::warning('BookingManagementController::cancel transient Stripe error', [
                    'booking_id' => $booking->id,
                    'exception' => $e::class,
                    'message' => $e->getMessage(),
                ]);

                return back()->with('error', __('Temporary Stripe issue — please try again in a minute.'));
            }

            $refundOutcome = $result->outcome;

            // `guard_rejected` on a booking we just read as Paid is a race:
            // a concurrent admin refund or webhook may have terminally settled
            // the refund between our read and the service's lock. Re-read the
            // booking; the row is authoritative. We still proceed with the
            // Cancel transition — the slot must be released regardless.
            $booking->refresh();
        }

        $booking->update(['status' => BookingStatus::Cancelled]);

        if (! $booking->shouldSuppressCustomerNotifications()) {
            $booking->loadMissing(['business.admins', 'provider.user']);

            // Staff-facing email ("the customer cancelled this booking").
            // The refund clause is only rendered on the business-cancelled
            // branch per locked decision #29 variant — staff don't need
            // refund copy here; the dashboard detail sheet surfaces the
            // refund state directly. `refundIssued` is still passed for
            // consistency; the blade template gates on `$cancelledBy`.
            $notification = new BookingCancelledNotification(
                $booking,
                'customer',
                refundIssued: $refundOutcome === 'succeeded',
            );
            $staffUsers = $booking->business->admins
                ->when($booking->provider?->user, fn ($c) => $c->merge([$booking->provider->user]))
                ->unique('id');

            Notification::send($staffUsers, $notification);
        }

        if ($booking->shouldPushToCalendar()) {
            PushBookingToCalendarJob::dispatch($booking->id, 'delete');
        }

        $flash = match ($refundOutcome) {
            'succeeded' => __('Booking cancelled. Refund initiated — you\'ll receive it in your original payment method within 5–10 business days.'),
            'disconnected', 'failed' => __('Booking cancelled. The business couldn\'t process the refund automatically — they will contact you directly.'),
            default => __('Booking cancelled successfully.'),
        };

        return back()->with('success', $flash);
    }

    private function canCancel(Booking $booking): bool
    {
        if (! in_array($booking->status, [BookingStatus::Pending, BookingStatus::Confirmed])) {
            return false;
        }

        $windowHours = $booking->business->cancellation_window_hours ?? 0;

        if ($windowHours === 0) {
            return true;
        }

        return now()->diffInHours($booking->starts_at, false) >= $windowHours;
    }
}
