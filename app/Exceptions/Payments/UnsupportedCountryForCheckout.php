<?php

namespace App\Exceptions\Payments;

use App\Models\StripeConnectedAccount;
use RuntimeException;

/**
 * Thrown by `CheckoutSessionFactory::assertSupportedCountry` when the
 * connected account's country is not in `config('payments.supported_countries')`.
 *
 * Locked roadmap decision #43's defense-in-depth: Session 2a's public-booking
 * Checkout-creation path asserts the country BEFORE calling Stripe, so a
 * DB tamper, migration bug, or `payment_mode_at_creation` drift class of bug
 * cannot reach Stripe with a non-supported account. The caller marks the
 * just-created booking Cancelled (slot released) and returns 422 to the
 * customer.
 */
final class UnsupportedCountryForCheckout extends RuntimeException
{
    /**
     * @param  array<int, string>  $supported
     */
    public function __construct(
        public readonly StripeConnectedAccount $account,
        public readonly array $supported,
    ) {
        parent::__construct(sprintf(
            'Connected account %s country %s is not in supported set [%s]',
            $account->stripe_account_id,
            (string) $account->country,
            implode(', ', $supported),
        ));
    }
}
