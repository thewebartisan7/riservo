<?php

namespace App\Enums;

enum PendingActionType: string
{
    case RiservoEventDeletedInGoogle = 'riservo_event_deleted_in_google';
    case ExternalBookingConflict = 'external_booking_conflict';

    // PAYMENTS Session 1 pre-adds the three payment.* cases (D-119) so
    // Sessions 2b / 3 land their writers without a cross-session enum edit.
    // - PaymentDisputeOpened: written by Session 3 (charge.dispute.created).
    // - PaymentRefundFailed: written by Session 2b (RefundService disconnected-
    //   account fallback) and Session 3 (every additional refund trigger).
    // - PaymentCancelledAfterPayment: written by Session 2b (late-webhook
    //   refund path per locked roadmap decision #31).
    case PaymentDisputeOpened = 'payment.dispute_opened';
    case PaymentRefundFailed = 'payment.refund_failed';
    case PaymentCancelledAfterPayment = 'payment.cancelled_after_payment';

    /**
     * Type-bucket helper used by calendar-only readers to filter out
     * payment-typed rows on a generalised pending_actions table (D-113).
     *
     * @return array<int, string>
     */
    public static function calendarValues(): array
    {
        return [
            self::RiservoEventDeletedInGoogle->value,
            self::ExternalBookingConflict->value,
        ];
    }
}
