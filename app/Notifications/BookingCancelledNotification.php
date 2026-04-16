<?php

namespace App\Notifications;

use App\Models\Booking;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class BookingCancelledNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(
        public Booking $booking,
        public string $cancelledBy,
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
        $this->booking->loadMissing(['service', 'provider.user', 'business', 'customer']);

        $business = $this->booking->business;
        $startsAt = $this->booking->starts_at->setTimezone($business->timezone);

        $subject = $this->cancelledBy === 'customer'
            ? __('Booking Cancelled — :service on :date', [
                'service' => $this->booking->service->name,
                'date' => $startsAt->format('d.m.Y'),
            ])
            : __('Your booking has been cancelled — :business', [
                'business' => $business->name,
            ]);

        return (new MailMessage)
            ->subject($subject)
            ->markdown('mail.booking-cancelled', [
                'cancelledBy' => $this->cancelledBy,
                'businessName' => $business->name,
                'customerName' => $this->booking->customer->name,
                'serviceName' => $this->booking->service->name,
                'providerName' => $this->booking->provider->user?->name ?? '',
                'date' => $startsAt->format('d.m.Y'),
                'time' => $startsAt->format('H:i'),
            ]);
    }
}
