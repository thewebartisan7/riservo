<?php

namespace App\Exceptions\Payments;

use App\Models\Booking;
use RuntimeException;

/**
 * Thrown by `CheckoutSessionFactory::create` when the booking row's
 * `paid_amount_cents` or `currency` snapshot is null / invalid at
 * Checkout-creation time.
 *
 * D-177 makes the booking row the single source of truth for what Stripe
 * will charge; D-152 captures those columns at booking creation. If they
 * are missing here the writer-side invariant has been broken and we must
 * refuse the Stripe call rather than fall back to mutable upstream state.
 *
 * The caller (`PublicBookingController::mintCheckoutOrRollback`) catches
 * this, releases the slot, and surfaces a `checkout_failed` validation
 * error to the customer.
 */
final class InvalidBookingSnapshotForCheckout extends RuntimeException
{
    public function __construct(public readonly Booking $booking)
    {
        parent::__construct(sprintf(
            'Booking %d has invalid Checkout snapshot: paid_amount_cents=%s currency=%s',
            $booking->id,
            var_export($booking->paid_amount_cents, true),
            var_export($booking->currency, true),
        ));
    }
}
