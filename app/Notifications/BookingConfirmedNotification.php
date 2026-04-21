<?php

namespace App\Notifications;

use App\Models\Booking;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class BookingConfirmedNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(
        public Booking $booking,
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
        $startsAt = $this->booking->starts_at->setTimezone($business->timezone);

        return (new MailMessage)
            ->subject(__('Booking Confirmed — :business', [
                'business' => $business->name,
            ]))
            ->markdown('mail.booking-confirmed', [
                'businessName' => $business->name,
                'serviceName' => $this->booking->service->name,
                'providerName' => $this->booking->provider->user->name ?? '',
                'date' => $startsAt->format('d.m.Y'),
                'time' => $startsAt->format('H:i'),
                'viewUrl' => route('bookings.show', $this->booking->cancellation_token),
            ]);
    }
}
