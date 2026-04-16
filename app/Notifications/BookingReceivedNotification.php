<?php

namespace App\Notifications;

use App\Models\Booking;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class BookingReceivedNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(
        public Booking $booking,
        public string $context = 'new',
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

        $subject = $this->context === 'confirmed'
            ? __('Booking Confirmed — :service on :date', [
                'service' => $this->booking->service->name,
                'date' => $startsAt->format('d.m.Y'),
            ])
            : __('New Booking — :service on :date', [
                'service' => $this->booking->service->name,
                'date' => $startsAt->format('d.m.Y'),
            ]);

        return (new MailMessage)
            ->subject($subject)
            ->markdown('mail.booking-received', [
                'context' => $this->context,
                'businessName' => $business->name,
                'customerName' => $this->booking->customer->name,
                'serviceName' => $this->booking->service->name,
                'providerName' => $this->booking->provider->user?->name ?? '',
                'date' => $startsAt->format('d.m.Y'),
                'time' => $startsAt->format('H:i'),
                'status' => $this->booking->status->label(),
                'notes' => $this->booking->notes,
                'dashboardUrl' => route('dashboard.bookings'),
            ]);
    }
}
