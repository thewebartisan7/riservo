<?php

namespace App\Notifications\Payments;

use App\Models\Booking;
use App\Models\BookingRefund;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * PAYMENTS Session 2b — locked roadmap decision #36 (disconnected-account
 * fallback + admin-only recipients). Dispatched by `RefundService::refund`
 * whenever Stripe refuses the refund (permission error on a soft-deleted
 * connected account, or any other `ApiErrorException`).
 *
 * Recipients are admins only, matching locked decisions #19 / #35 / #36 —
 * money surfaces are an admin-only commercial decision; staff do not
 * receive refund-failure notifications.
 */
class RefundFailedNotification extends Notification implements ShouldQueue
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
            ->subject(__('Refund could not be processed automatically — :service on :date', [
                'service' => $this->booking->service->name,
                'date' => $startsAt->format('d.m.Y'),
            ]))
            ->markdown('mail.payments.refund-failed', [
                'businessName' => $business->name,
                'customerName' => $this->booking->customer?->name,
                'customerEmail' => $this->booking->customer?->email,
                'customerPhone' => $this->booking->customer?->phone,
                'serviceName' => $this->booking->service->name,
                'date' => $startsAt->format('d.m.Y'),
                'time' => $startsAt->format('H:i'),
                'amountFormatted' => $this->formatAmount(),
                'failureReason' => $this->bookingRefund->failure_reason ?? __('Unknown error'),
                'bookingRefundId' => $this->bookingRefund->id,
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
