<?php

namespace App\Http\Controllers\Booking;

use App\Enums\BookingStatus;
use App\Http\Controllers\Controller;
use App\Models\Booking;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;
use Inertia\Response;

class BookingManagementController extends Controller
{
    public function show(string $token): Response
    {
        $booking = Booking::where('cancellation_token', $token)
            ->with(['service', 'collaborator', 'business', 'customer'])
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
                'collaborator' => [
                    'name' => $booking->collaborator->name,
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

        $booking->update(['status' => BookingStatus::Cancelled]);

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
