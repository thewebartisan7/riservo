<?php

namespace App\Http\Controllers\Booking;

use App\Enums\BookingStatus;
use App\Enums\PaymentStatus;
use App\Http\Controllers\Controller;
use App\Jobs\Calendar\PushBookingToCalendarJob;
use App\Models\Booking;
use App\Notifications\BookingCancelledNotification;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Notification;
use Inertia\Inertia;
use Inertia\Response;

class BookingManagementController extends Controller
{
    public function show(string $token): Response
    {
        $booking = Booking::where('cancellation_token', $token)
            ->with(['service', 'provider.user', 'business', 'customer'])
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
            ],
        ]);
    }

    public function cancel(string $token): RedirectResponse
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

        // Codex adversarial review Round 1 (F3): paid bookings cannot be
        // self-cancelled by the customer until Session 3 ships
        // `RefundService::refund($booking, null, 'customer-requested')`.
        // Cancelling a paid booking today would leave the system at
        // `cancelled + paid` — slot freed, money still on the connected
        // account — which creates manual-reconciliation liability. Session 3
        // will relax this to "in-window → automatic full refund; out-of-
        // window → cancel with no refund + 'contact the business' copy"
        // per locked decisions #15 / #16. For Session 2a the safe move is
        // a server-side block + guidance to contact the business. The
        // `can_cancel` Inertia prop stays true so the button still renders
        // and this error flash is the user's feedback — matching the
        // existing cancellation-window-exceeded UX shape.
        if ($booking->payment_status === PaymentStatus::Paid) {
            return back()->with('error', __('Please contact :business to cancel this booking — refunds are handled directly with the business for now.', [
                'business' => $booking->business->name,
            ]));
        }

        $booking->update(['status' => BookingStatus::Cancelled]);

        if (! $booking->shouldSuppressCustomerNotifications()) {
            $booking->loadMissing(['business.admins', 'provider.user']);

            $notification = new BookingCancelledNotification($booking, 'customer');
            $staffUsers = $booking->business->admins
                ->when($booking->provider?->user, fn ($c) => $c->merge([$booking->provider->user]))
                ->unique('id');

            Notification::send($staffUsers, $notification);
        }

        if ($booking->shouldPushToCalendar()) {
            PushBookingToCalendarJob::dispatch($booking->id, 'delete');
        }

        return back()->with('success', __('Booking cancelled successfully.'));
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
