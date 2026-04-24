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

    /**
     * @param  bool  $refundIssued  PAYMENTS Session 3 (D-175): when true + the
     *                              email is customer-facing (`cancelledBy = 'business'`),
     *                              the blade template renders a "a full refund
     *                              has been issued" paragraph. Callers on refund-
     *                              dispatching paths (admin cancel of Confirmed+Paid,
     *                              admin-reject of Pending+Paid) pass true iff the
     *                              `RefundService::refund` call returned a
     *                              `succeeded` outcome. `Pending+Unpaid` rejection
     *                              (customer_choice + manual-confirm failed Checkout)
     *                              MUST pass false — there is nothing to refund and
     *                              promising one would be a regression per locked
     *                              decision #29 variant.
     */
    public function __construct(
        public Booking $booking,
        public string $cancelledBy,
        public bool $refundIssued = false,
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
                'refundIssued' => $this->refundIssued,
                'businessName' => $business->name,
                'customerName' => $this->booking->customer->name,
                'serviceName' => $this->booking->service->name,
                'providerName' => $this->booking->provider->user->name ?? '',
                'date' => $startsAt->format('d.m.Y'),
                'time' => $startsAt->format('H:i'),
            ]);
    }
}
