<?php

namespace App\Http\Controllers\Customer;

use App\Enums\BookingStatus;
use App\Enums\PaymentStatus;
use App\Http\Controllers\Controller;
use App\Jobs\Calendar\PushBookingToCalendarJob;
use App\Models\Booking;
use App\Models\Customer;
use App\Models\StripeConnectedAccount;
use App\Notifications\BookingCancelledNotification;
use App\Services\Payments\RefundService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Notification;
use Inertia\Inertia;
use Inertia\Response;
use Stripe\Exception\ApiConnectionException;
use Stripe\Exception\ApiErrorException;
use Stripe\Exception\RateLimitException;

class BookingController extends Controller
{
    public function index(Request $request): Response
    {
        $customer = Customer::where('user_id', $request->user()->id)->first();

        // Codex Round 2 P3: eager-load `bookingRefunds` so `refundStatusLine()`
        // reads from the loaded collection instead of hitting the DB per
        // booking (N+1). Also batch-resolve the set of disconnected pinned
        // connected accounts ONCE so the per-booking status line skips the
        // `stripe_connected_accounts` lookup.
        $bookings = $customer
            ? $customer->bookings()
                ->with(['service', 'provider.user', 'business', 'bookingRefunds'])
                ->orderBy('starts_at', 'desc')
                ->get()
            : collect();

        $pinnedAccountIds = $bookings
            ->pluck('stripe_connected_account_id')
            ->filter()
            ->unique()
            ->all();

        $disconnectedAccountIds = count($pinnedAccountIds) > 0
            ? StripeConnectedAccount::withTrashed()
                ->whereIn('stripe_account_id', $pinnedAccountIds)
                ->whereNotNull('deleted_at')
                ->pluck('stripe_account_id')
                ->all()
            : [];

        return Inertia::render('customer/bookings', [
            'upcoming' => $bookings
                ->filter(fn (Booking $b) => $b->starts_at->isFuture() && in_array($b->status, [BookingStatus::Pending, BookingStatus::Confirmed]))
                ->values()
                ->map(fn (Booking $b) => $this->formatBooking($b, $disconnectedAccountIds)),
            'past' => $bookings
                ->filter(fn (Booking $b) => $b->starts_at->isPast() || in_array($b->status, [BookingStatus::Cancelled, BookingStatus::Completed, BookingStatus::NoShow]))
                ->values()
                ->map(fn (Booking $b) => $this->formatBooking($b, $disconnectedAccountIds)),
        ]);
    }

    public function cancel(Request $request, Booking $booking, RefundService $refundService): RedirectResponse
    {
        $customer = Customer::where('user_id', $request->user()->id)->first();

        if (! $customer || $booking->customer_id !== $customer->id) {
            abort(403);
        }

        if (! in_array($booking->status, [BookingStatus::Pending, BookingStatus::Confirmed])) {
            return back()->with('error', __('This booking cannot be cancelled.'));
        }

        $booking->load('business');
        $windowHours = $booking->business->cancellation_window_hours ?? 0;

        if ($windowHours > 0 && now()->diffInHours($booking->starts_at, false) < $windowHours) {
            return back()->with('error', __('This booking can no longer be cancelled. The cancellation window has passed.'));
        }

        // PAYMENTS Session 3 (locked decisions #15 / #16 — authenticated
        // customer branch). Mirrors `BookingManagementController::cancel`:
        // the D-159 paid-cancel block is replaced with a
        // `customer-requested` refund dispatch. Refund outcome drives the
        // customer-facing flash copy; booking always flips Cancelled so the
        // slot releases.
        $refundOutcome = null;
        $isPaid = in_array($booking->payment_status, [PaymentStatus::Paid, PaymentStatus::PartiallyRefunded], true);

        if ($isPaid) {
            try {
                $result = $refundService->refund($booking, null, 'customer-requested');
            } catch (ApiConnectionException|RateLimitException|ApiErrorException $e) {
                Log::warning('Customer\\BookingController::cancel transient Stripe error', [
                    'booking_id' => $booking->id,
                    'exception' => $e::class,
                    'message' => $e->getMessage(),
                ]);

                return back()->with('error', __('Temporary Stripe issue — please try again in a minute.'));
            }

            $refundOutcome = $result->outcome;
            $booking->refresh();
        }

        $booking->update(['status' => BookingStatus::Cancelled]);

        if (! $booking->shouldSuppressCustomerNotifications()) {
            $booking->loadMissing(['business.admins', 'provider.user']);

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

    /**
     * @param  array<int, string>  $disconnectedAccountIds  precomputed at the controller level
     *                                                      per Codex Round 2 P3 to avoid N+1.
     * @return array<string, mixed>
     */
    private function formatBooking(Booking $booking, array $disconnectedAccountIds = []): array
    {
        $windowHours = $booking->business->cancellation_window_hours ?? 0;
        $canCancel = in_array($booking->status, [BookingStatus::Pending, BookingStatus::Confirmed])
            && $booking->starts_at->isFuture()
            && ($windowHours === 0 || now()->diffInHours($booking->starts_at, false) >= $windowHours);

        $pinnedAccountDisconnected = is_string($booking->stripe_connected_account_id)
            && in_array($booking->stripe_connected_account_id, $disconnectedAccountIds, true);

        return [
            'id' => $booking->id,
            'token' => $booking->cancellation_token,
            'starts_at' => $booking->starts_at->toISOString(),
            'ends_at' => $booking->ends_at->toISOString(),
            'status' => $booking->status->value,
            'service' => [
                'name' => $booking->service->name,
            ],
            'provider' => [
                'name' => $booking->provider->user->name ?? '',
                'is_active' => ! $booking->provider->trashed(),
            ],
            'business' => [
                'name' => $booking->business->name,
                'timezone' => $booking->business->timezone,
            ],
            'can_cancel' => $canCancel,
            // PAYMENTS Session 3: customer-facing refund status line per
            // booking card. Null when the booking has no refund attempts.
            // Codex Round 2 P3: uses the eager-loaded `bookingRefunds`
            // relation + the batched disconnect set.
            'refund_status_line' => $booking->refundStatusLine($pinnedAccountDisconnected),
        ];
    }
}
