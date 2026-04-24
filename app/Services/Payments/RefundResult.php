<?php

namespace App\Services\Payments;

use App\Models\BookingRefund;

/**
 * PAYMENTS Session 2b — return shape of `RefundService::refund`.
 *
 * The service has four terminal outcomes:
 *  - `succeeded`      — Stripe accepted the refund; `bookingRefund->status = Succeeded`.
 *  - `failed`         — Stripe returned a non-permission error; `bookingRefund->status = Failed`.
 *  - `disconnected`   — Stripe returned a permission / account-invalid error,
 *                       consistent with a soft-deleted connected account
 *                       (locked decision #36 disconnected-account fallback).
 *                       `bookingRefund->status = Failed`.
 *  - `guard_rejected` — the service's pre-flight guards refused (null
 *                       `paid_amount_cents` / `stripe_payment_intent_id`, or
 *                       `remainingRefundableCents() <= 0`). No `booking_refunds`
 *                       row is created; no Stripe call is made.
 *
 * Kept as a small readonly class rather than a scalar union so Session 3's
 * multi-trigger expansion (customer cancel, admin manual, business cancel,
 * manual-confirm rejection) has room to grow without a signature change.
 */
final readonly class RefundResult
{
    /** @param 'succeeded'|'failed'|'disconnected'|'guard_rejected' $outcome */
    public function __construct(
        public string $outcome,
        public ?BookingRefund $bookingRefund = null,
        public ?string $failureReason = null,
    ) {}

    public static function succeeded(BookingRefund $bookingRefund): self
    {
        return new self('succeeded', $bookingRefund);
    }

    public static function failed(BookingRefund $bookingRefund, string $reason): self
    {
        return new self('failed', $bookingRefund, $reason);
    }

    public static function disconnected(BookingRefund $bookingRefund, string $reason): self
    {
        return new self('disconnected', $bookingRefund, $reason);
    }

    public static function guardRejected(string $reason): self
    {
        return new self('guard_rejected', null, $reason);
    }
}
