<?php

namespace App\Enums;

/**
 * Refund attempt lifecycle for the `booking_refunds` table (PAYMENTS Session 2b,
 * locked roadmap decision #36).
 *
 * - Pending: row inserted BEFORE the Stripe call; Stripe has not yet responded
 *   (or the response was lost mid-flight).
 * - Succeeded: Stripe accepted the refund; funds are returning to the customer
 *   via Stripe's own async settlement pipeline. The `stripe_refund_id` column
 *   is populated.
 * - Failed: Stripe refused the refund (disconnected account / permission error
 *   per locked decision #36's disconnected-account fallback, or any other
 *   Stripe-side failure). The `failure_reason` column carries the verbatim
 *   Stripe error message; the booking's `payment_status` is updated to
 *   `refund_failed` and a `payment.refund_failed` Pending Action is surfaced.
 *
 * The three values are the full set we surface across Sessions 2b + 3 —
 * Stripe's `requires_action` and `canceled` refund states are not modelled
 * (our flow doesn't trigger them).
 */
enum BookingRefundStatus: string
{
    case Pending = 'pending';
    case Succeeded = 'succeeded';
    case Failed = 'failed';
}
