<?php

namespace App\Http\Controllers\Customer;

use App\Enums\BookingStatus;
use App\Http\Controllers\Controller;
use App\Models\Booking;
use App\Models\Customer;
use App\Notifications\BookingCancelledNotification;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Notification;
use Inertia\Inertia;
use Inertia\Response;

class BookingController extends Controller
{
    public function index(Request $request): Response
    {
        $customer = Customer::where('user_id', $request->user()->id)->first();

        $bookings = $customer
            ? $customer->bookings()
                ->with(['service', 'collaborator', 'business'])
                ->orderBy('starts_at', 'desc')
                ->get()
            : collect();

        $now = now();

        return Inertia::render('customer/bookings', [
            'upcoming' => $bookings
                ->filter(fn (Booking $b) => $b->starts_at->isFuture() && in_array($b->status, [BookingStatus::Pending, BookingStatus::Confirmed]))
                ->values()
                ->map(fn (Booking $b) => $this->formatBooking($b)),
            'past' => $bookings
                ->filter(fn (Booking $b) => $b->starts_at->isPast() || in_array($b->status, [BookingStatus::Cancelled, BookingStatus::Completed, BookingStatus::NoShow]))
                ->values()
                ->map(fn (Booking $b) => $this->formatBooking($b)),
        ]);
    }

    public function cancel(Request $request, Booking $booking): RedirectResponse
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

        $booking->update(['status' => BookingStatus::Cancelled]);

        $booking->loadMissing(['business.admins', 'collaborator']);

        $notification = new BookingCancelledNotification($booking, 'customer');
        $staffUsers = $booking->business->admins
            ->merge([$booking->collaborator])
            ->unique('id');

        Notification::send($staffUsers, $notification);

        return back()->with('success', __('Booking cancelled successfully.'));
    }

    /**
     * @return array<string, mixed>
     */
    private function formatBooking(Booking $booking): array
    {
        $windowHours = $booking->business->cancellation_window_hours ?? 0;
        $canCancel = in_array($booking->status, [BookingStatus::Pending, BookingStatus::Confirmed])
            && $booking->starts_at->isFuture()
            && ($windowHours === 0 || now()->diffInHours($booking->starts_at, false) >= $windowHours);

        return [
            'id' => $booking->id,
            'token' => $booking->cancellation_token,
            'starts_at' => $booking->starts_at->toISOString(),
            'ends_at' => $booking->ends_at->toISOString(),
            'status' => $booking->status->value,
            'service' => [
                'name' => $booking->service->name,
            ],
            'collaborator' => [
                'name' => $booking->collaborator->name,
            ],
            'business' => [
                'name' => $booking->business->name,
            ],
            'can_cancel' => $canCancel,
        ];
    }
}
