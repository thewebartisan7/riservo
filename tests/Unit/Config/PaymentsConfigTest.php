<?php

use Tests\TestCase;

uses(TestCase::class);

test('payments config exposes the three keys with MVP defaults', function () {
    expect(config('payments.supported_countries'))->toBe(['CH']);
    expect(config('payments.default_onboarding_country'))->toBe('CH');
    expect(config('payments.twint_countries'))->toBe(['CH']);
});

test('PAYMENTS_SUPPORTED_COUNTRIES env var parses comma-separated list', function () {
    config()->set(
        'payments.supported_countries',
        array_values(array_filter(array_map('trim', explode(',', 'CH, DE,IT'))))
    );

    expect(config('payments.supported_countries'))->toBe(['CH', 'DE', 'IT']);
});
