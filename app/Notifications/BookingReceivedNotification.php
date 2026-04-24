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

        // PAYMENTS Session 2a (locked decision #29): when a manual-confirmation
        // business is paid via Stripe Checkout, the booking lands at
        // `pending + paid`. The customer has paid BUT the business still has
        // to approve; the notification must make that contract explicit so
        // the customer isn't surprised by a refund if the admin rejects.
        // PAYMENTS Session 2b: `pending_unpaid_awaiting_confirmation` is the
        // context for customer_choice + manual-confirm bookings whose Checkout
        // failed (locked decision #29 variant + #14). The customer didn't pay
        // but the booking is pending confirmation; they can pay at the
        // appointment if accepted. Distinct from `paid_awaiting_confirmation`
        // which promises a refund on rejection — here there's nothing to
        // refund.
        $subject = match ($this->context) {
            'confirmed' => __('Booking Confirmed — :service on :date', [
                'service' => $this->booking->service->name,
                'date' => $startsAt->format('d.m.Y'),
            ]),
            'paid_awaiting_confirmation' => __('Payment received — :business will confirm your booking', [
                'business' => $business->name,
            ]),
            'pending_unpaid_awaiting_confirmation' => __('Booking request received — :business will confirm', [
                'business' => $business->name,
            ]),
            default => __('New Booking — :service on :date', [
                'service' => $this->booking->service->name,
                'date' => $startsAt->format('d.m.Y'),
            ]),
        };

        return (new MailMessage)
            ->subject($subject)
            ->markdown('mail.booking-received', [
                'context' => $this->context,
                'businessName' => $business->name,
                'customerName' => $this->booking->customer->name,
                'serviceName' => $this->booking->service->name,
                'providerName' => $this->booking->provider->user->name ?? '',
                'date' => $startsAt->format('d.m.Y'),
                'time' => $startsAt->format('H:i'),
                'status' => $this->booking->status->label(),
                'notes' => $this->booking->notes,
                'dashboardUrl' => route('dashboard.bookings'),
            ]);
    }
}
