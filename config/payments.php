<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Customer-to-Professional Payments — Country Gating
    |--------------------------------------------------------------------------
    |
    | These keys back the locked roadmap decisions in docs/ROADMAP.md
    | (PAYMENTS) and DECISIONS-PAYMENTS.md (D-112). Every gate that asks "may
    | this connected account take online payments?" reads these keys; no
    | application code, test, Inertia prop, or Tailwind class check carries a
    | hardcoded 'CH' literal. Extending to IT / DE / FR / AT / LI is a config
    | flip, not a refactor.
    |
    */

    /*
     * ISO-3166-1 alpha-2 country codes whose connected accounts are allowed to
     * set payment_mode = online or customer_choice. MVP value = ['CH']. Read by
     * Session 2a's Checkout-creation country assertion, Session 4's payout-page
     * non-CH banner, and Session 5's Settings → Booking gate.
     */
    'supported_countries' => array_values(array_filter(array_map(
        'trim',
        explode(',', (string) env('PAYMENTS_SUPPORTED_COUNTRIES', 'CH'))
    ))),

    /*
     * Country code passed to stripe.accounts.create() when creating a fresh
     * Express account. Business.address is freeform and not reliably parseable;
     * Stripe collects the real country during hosted KYC and the local row's
     * `country` column is overwritten by the first accounts.retrieve(). This
     * default is intentionally the only fallback (D-115).
     */
    'default_onboarding_country' => env('PAYMENTS_DEFAULT_ONBOARDING_COUNTRY', 'CH'),

    /*
     * ISO-3166-1 alpha-2 codes whose Stripe accounts get TWINT enabled on
     * Checkout (in addition to card). MVP value = ['CH']. Identical to
     * supported_countries today, but Session 2a's payment_method_types
     * branching reads this independently to keep the seam open for non-CH
     * supported_countries that fall back to card-only.
     */
    'twint_countries' => array_values(array_filter(array_map(
        'trim',
        explode(',', (string) env('PAYMENTS_TWINT_COUNTRIES', 'CH'))
    ))),

];
