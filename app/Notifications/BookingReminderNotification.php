<?php

namespace App\Notifications;

use App\Models\Booking;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class BookingReminderNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(
        public Booking $booking,
        public int $hoursBefore,
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
            ->subject(__('Reminder: Your appointment at :business', [
                'business' => $business->name,
            ]))
            ->markdown('mail.booking-reminder', [
                'businessName' => $business->name,
                'serviceName' => $this->booking->service->name,
                'providerName' => $this->booking->provider->user->name ?? '',
                'date' => $startsAt->format('d.m.Y'),
                'time' => $startsAt->format('H:i'),
                'viewUrl' => route('bookings.show', $this->booking->cancellation_token),
            ]);
    }
}
