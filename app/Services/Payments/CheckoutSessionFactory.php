<?php

namespace App\Services\Payments;

use App\Exceptions\Payments\UnsupportedCountryForCheckout;
use App\Models\Booking;
use App\Models\Business;
use App\Models\Service;
use App\Models\StripeConnectedAccount;
use Stripe\Checkout\Session as StripeCheckoutSession;
use Stripe\Exception\ApiErrorException;
use Stripe\StripeClient;

/**
 * Builds Stripe Checkout sessions on a connected account for PAYMENTS
 * Session 2a.
 *
 * Two responsibilities:
 *   - `assertSupportedCountry()` — the locked-decision-#43 defense-in-depth
 *     check, thrown as `UnsupportedCountryForCheckout` so the caller can
 *     mark the booking Cancelled and return a 422 without touching Stripe.
 *   - `create()` — mints a hosted Checkout session with direct-charge
 *     semantics (locked decision #5), card + TWINT (locked decision #3) per
 *     `config('payments.twint_countries')`, success/cancel URLs anchored on
 *     the booking's cancellation token, 90-minute expiry (locked decision #13).
 *
 * All Stripe API calls carry the per-request option `['stripe_account' => $acct]`
 * so the D-109 FakeStripeClient contract's connected-account-level bucket
 * matches. Platform-level methods (no header) live on a distinct bucket; a
 * call that crosses categories fails the Mockery `withArgs` matcher by
 * construction.
 */
final class CheckoutSessionFactory
{
    /**
     * Stripe's own supported Checkout locales (locked roadmap decision #39).
     * riservo ships IT/DE/FR/EN per D-008 — all four are supported; any
     * future app locale not in this list falls back to Stripe's `'auto'`
     * browser detection.
     */
    private const SUPPORTED_STRIPE_LOCALES = ['it', 'de', 'fr', 'en'];

    public function __construct(private readonly StripeClient $stripe) {}

    /**
     * Defense-in-depth per locked roadmap decision #43. The Settings gate
     * (Session 5) and the Session 1 onboarding gate (D-141) already refuse
     * connected accounts whose country is not in the supported set, but
     * DB tamper / migration bug / drift-class bugs could still deliver an
     * unsupported-country account to this call site. Assert loudly.
     *
     * @throws UnsupportedCountryForCheckout
     */
    public function assertSupportedCountry(StripeConnectedAccount $account): void
    {
        $supported = (array) config('payments.supported_countries');

        if (! in_array($account->country, $supported, true)) {
            throw new UnsupportedCountryForCheckout($account, $supported);
        }
    }

    /**
     * Create the Stripe Checkout session on the connected account.
     *
     * @throws ApiErrorException
     */
    public function create(
        Booking $booking,
        Service $service,
        Business $business,
        StripeConnectedAccount $account,
    ): StripeCheckoutSession {
        $currency = $account->default_currency ?? 'chf';
        $amountCents = (int) round((float) $service->price * 100);

        $params = [
            'mode' => 'payment',
            'client_reference_id' => (string) $booking->id,
            'customer_email' => $booking->customer?->email,
            'payment_method_types' => $this->paymentMethodTypes((string) $account->country),
            'line_items' => [[
                'price_data' => [
                    'currency' => $currency,
                    'product_data' => ['name' => $service->name],
                    'unit_amount' => $amountCents,
                ],
                'quantity' => 1,
            ]],
            'metadata' => [
                'riservo_booking_id' => (string) $booking->id,
                'riservo_business_id' => (string) $business->id,
                'riservo_payment_mode_at_creation' => (string) $booking->payment_mode_at_creation,
            ],
            'success_url' => route('bookings.payment-success', $booking->cancellation_token)
                .'?session_id={CHECKOUT_SESSION_ID}',
            'cancel_url' => route('bookings.payment-cancel', $booking->cancellation_token),
            'locale' => $this->resolveStripeLocale((string) app()->getLocale()),
            // Stripe's `expires_at` is a Unix epoch. We mirror the 90-minute
            // window on the booking row's own `expires_at` (locked decision
            // #13) so Session 2b's reaper has an authoritative column to
            // filter on independent of Stripe round-trips.
            'expires_at' => now()->addMinutes(90)->timestamp,
        ];

        return $this->stripe->checkout->sessions->create(
            $params,
            ['stripe_account' => (string) $account->stripe_account_id],
        );
    }

    /**
     * Locked roadmap decisions #3 + #43: TWINT is mandatory (not opt-in) on
     * CH-located connected accounts today; the seam is a config flip for
     * future non-CH countries in `supported_countries`. Non-TWINT entries in
     * the supported set fall back to card-only — unreachable in MVP
     * (`twint_countries` == `supported_countries` == `['CH']`) but wired for
     * the fast-follow.
     *
     * @return array<int, string>
     */
    private function paymentMethodTypes(string $country): array
    {
        $twintCountries = (array) config('payments.twint_countries');

        if (in_array($country, $twintCountries, true)) {
            return ['card', 'twint'];
        }

        return ['card'];
    }

    /**
     * Locked roadmap decision #39: pass the customer's current app locale to
     * Stripe Checkout so the hosted page renders in the same language as the
     * booking flow. Unsupported locales fall back to Stripe's browser-based
     * `'auto'` detection.
     */
    private function resolveStripeLocale(string $appLocale): string
    {
        if (in_array($appLocale, self::SUPPORTED_STRIPE_LOCALES, true)) {
            return $appLocale;
        }

        return 'auto';
    }
}
