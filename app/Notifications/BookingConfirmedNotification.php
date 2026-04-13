<?php

namespace App\Notifications;

use App\Models\Booking;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Placeholder confirmation email — Session 10 replaces with styled template.
 */
class BookingConfirmedNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(
        public Booking $booking,
    ) {}

    /** @return array<int, string> */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $this->booking->loadMissing(['service', 'collaborator', 'business']);

        $business = $this->booking->business;
        $startsAt = $this->booking->starts_at->setTimezone($business->timezone);

        return (new MailMessage)
            ->subject(__('Booking :status — :business', [
                'status' => $this->booking->status->value,
                'business' => $business->name,
            ]))
            ->greeting(__('Hello!'))
            ->line(__('Your booking at :business has been :status.', [
                'business' => $business->name,
                'status' => $this->booking->status->value,
            ]))
            ->line(__('Service: :service', ['service' => $this->booking->service->name]))
            ->line(__('With: :collaborator', ['collaborator' => $this->booking->collaborator->name]))
            ->line(__('Date: :date', ['date' => $startsAt->format('d.m.Y')]))
            ->line(__('Time: :time', ['time' => $startsAt->format('H:i')]))
            ->action(__('View booking'), url('/bookings/'.$this->booking->cancellation_token))
            ->line(__('Thank you for your booking!'));
    }
}
