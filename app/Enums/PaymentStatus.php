<?php

namespace App\Enums;

/**
 * Booking payment state (PAYMENTS Session 2a, locked roadmap decision #28).
 *
 * - NotApplicable: offline / never-charged booking. The default for manual
 *   bookings, google_calendar bookings, and online-flow bookings when the
 *   service is price-null/0 or the business is payment_mode=offline.
 * - AwaitingPayment: pending + in-flight Stripe Checkout.
 * - Paid: Checkout succeeded; funds settled to the connected account.
 * - Unpaid: customer_choice booking whose online attempt failed/expired;
 *   booking is confirmed, customer pays at appointment (Session 2b writes).
 * - Refunded: full refund issued (Session 3 writes).
 * - PartiallyRefunded: partial refund(s) issued, balance still stands
 *   (Session 3 writes).
 * - RefundFailed: Stripe refused the refund (e.g. disconnected account,
 *   locked decision #36) — admin notified via Pending Action (Sessions 2b/3).
 *
 * The pre-2a `Pending` case is retired without a rename: riservo is pre-launch
 * and `migrate:fresh` is the dev-DB reset path (roadmap Session 2a Data layer
 * clause).
 */
enum PaymentStatus: string
{
    case NotApplicable = 'not_applicable';
    case AwaitingPayment = 'awaiting_payment';
    case Paid = 'paid';
    case Unpaid = 'unpaid';
    case Refunded = 'refunded';
    case PartiallyRefunded = 'partially_refunded';
    case RefundFailed = 'refund_failed';
}
