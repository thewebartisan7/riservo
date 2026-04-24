<?php

namespace App\Notifications\Payments;

use App\Models\Booking;
use App\Models\BookingRefund;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * PAYMENTS Session 2b — locked roadmap decision #31.3 (late-webhook refund
 * path). Dispatched when a `checkout.session.completed` or
 * `payment_intent.succeeded` event arrives for a booking that the reaper
 * already cancelled. An automatic refund has been dispatched against the
 * connected account; admins get this email so they can reach out to the
 * customer.
 *
 * Recipients are admins only (locked decisions #19 / #31 — staff handle
 * bookings, admins handle money).
 */
class CancelledAfterPaymentNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(
        public Booking $booking,
        public BookingRefund $bookingRefund,
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
        $this->booking->loadMissing(['customer', 'service', 'business']);

        $business = $this->booking->business;
        $startsAt = $this->booking->starts_at->setTimezone($business->timezone);

        return (new MailMessage)
            ->subject(__('A cancelled booking was paid — refund dispatched'))
            ->markdown('mail.payments.cancelled-after-payment', [
                'businessName' => $business->name,
                'customerName' => $this->booking->customer?->name,
                'customerEmail' => $this->booking->customer?->email,
                'customerPhone' => $this->booking->customer?->phone,
                'serviceName' => $this->booking->service->name,
                'date' => $startsAt->format('d.m.Y'),
                'time' => $startsAt->format('H:i'),
                'amountFormatted' => $this->formatAmount(),
                'bookingRefundId' => $this->bookingRefund->id,
                'stripeRefundId' => $this->bookingRefund->stripe_refund_id,
                'dashboardUrl' => route('dashboard.bookings'),
            ]);
    }

    private function formatAmount(): string
    {
        $cents = $this->bookingRefund->amount_cents;
        $currency = strtoupper($this->bookingRefund->currency);

        return $currency.' '.number_format($cents / 100, 2, '.', "'");
    }
}
