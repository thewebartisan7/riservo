<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Subscription Prices
    |--------------------------------------------------------------------------
    |
    | Stripe price IDs for the single paid tier (D-093). Two cadences only:
    | monthly (CHF 29.00) and annual (CHF 290.00 — ~17% discount). Price
    | values are tunable in the Stripe dashboard without code changes; the
    | env vars below carry the IDs.
    |
    */

    'prices' => [
        'monthly' => env('STRIPE_PRICE_MONTHLY'),
        'annual' => env('STRIPE_PRICE_ANNUAL'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Display Amounts
    |--------------------------------------------------------------------------
    |
    | Labels shown on the billing page next to each plan. These are display-
    | only — Stripe is the authoritative source for the actual charge.
    |
    */

    'display' => [
        'monthly' => ['amount' => 29, 'currency' => 'CHF', 'interval' => 'month'],
        'annual' => ['amount' => 290, 'currency' => 'CHF', 'interval' => 'year'],
    ],

];
