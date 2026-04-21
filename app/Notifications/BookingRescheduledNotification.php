<?php

namespace App\Notifications;

use App\Models\Booking;
use Carbon\CarbonInterface;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Customer-facing "your booking has been moved" notification. Dispatched by
 * DashboardBookingController::reschedule (drag / resize from the admin
 * calendar). Per locked roadmap decision #16, suppressed for
 * `source = google_calendar` bookings at the dispatch site via
 * Booking::shouldSuppressCustomerNotifications() (D-088). Mirrors the
 * BookingConfirmedNotification shape so the customer can re-manage from the
 * same link.
 */
class BookingRescheduledNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(
        public Booking $booking,
        public CarbonInterface $previousStartsAt,
        public CarbonInterface $previousEndsAt,
    ) {
        $this->afterCommit();
    }

    /** @return array<int, string> */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $this->booking->loadMissing(['service', 'provider.user', 'business']);

        $business = $this->booking->business;
        $timezone = $business->timezone;
        $previous = $this->previousStartsAt->copy()->setTimezone($timezone);
        $current = $this->booking->starts_at->copy()->setTimezone($timezone);

        return (new MailMessage)
            ->subject(__('Your booking has been moved — :business', [
                'business' => $business->name,
            ]))
            ->markdown('mail.booking-rescheduled', [
                'businessName' => $business->name,
                'serviceName' => $this->booking->service->name ?? '',
                'providerName' => $this->booking->provider->user->name ?? '',
                'previousDate' => $previous->format('d.m.Y'),
                'previousTime' => $previous->format('H:i'),
                'newDate' => $current->format('d.m.Y'),
                'newTime' => $current->format('H:i'),
                'viewUrl' => route('bookings.show', $this->booking->cancellation_token),
            ]);
    }
}
