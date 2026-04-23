<?php

use App\Models\StripeConnectedAccount;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

test('verificationStatus returns disabled when Stripe disabled the account', function () {
    $row = StripeConnectedAccount::factory()->disabled()->create();

    expect($row->verificationStatus())->toBe('disabled');
});

test('verificationStatus returns active when capabilities are all enabled', function () {
    $row = StripeConnectedAccount::factory()->active()->create();

    expect($row->verificationStatus())->toBe('active');
});

test('verificationStatus returns unsupported_market when caps on but country is outside supported_countries (D-150, codex Round 13)', function () {
    // Codex Round 13 finding: a config/env flip that removes a row's
    // country from supported_countries (or a pre-launch operator
    // tightening the set) leaves the row with Stripe caps still on but
    // the backend refusing online payments via canAcceptOnlinePayments().
    // `verificationStatus()` used to return 'active' regardless, so the
    // UI showed "Verified" while the admin was silently ineligible.
    // The new `unsupported_market` state lets the page explain it.
    $row = StripeConnectedAccount::factory()->active()->create(['country' => 'CH']);
    config(['payments.supported_countries' => ['DE']]);

    expect($row->verificationStatus())->toBe('unsupported_market');
});

test('verificationStatus returns active once country enters supported_countries again (D-150)', function () {
    // Forward-looking: if riservo expands coverage, the same row flips
    // back to 'active' without any DB write. Proves the gate is truly
    // config-driven, not a persisted computed column.
    $row = StripeConnectedAccount::factory()->active()->create(['country' => 'DE']);
    config(['payments.supported_countries' => ['CH']]);
    expect($row->verificationStatus())->toBe('unsupported_market');

    config(['payments.supported_countries' => ['CH', 'DE']]);
    expect($row->verificationStatus())->toBe('active');
});

test('verificationStatus returns incomplete when KYC submitted but capabilities missing', function () {
    $row = StripeConnectedAccount::factory()->incomplete()->create();

    expect($row->verificationStatus())->toBe('incomplete');
});

test('verificationStatus returns pending for a freshly created account before KYC', function () {
    $row = StripeConnectedAccount::factory()->pending()->create();

    expect($row->verificationStatus())->toBe('pending');
});

test('matchesAuthoritativeState skips writes on a no-op nudge', function () {
    $row = StripeConnectedAccount::factory()->active()->create([
        'requirements_currently_due' => ['external_account', 'tos_acceptance.date'],
    ]);

    $matches = $row->matchesAuthoritativeState([
        'country' => 'CH',
        'charges_enabled' => true,
        'payouts_enabled' => true,
        'details_submitted' => true,
        // Reordered list — order-insensitive comparison must treat these as equal.
        'requirements_currently_due' => ['tos_acceptance.date', 'external_account'],
        'requirements_disabled_reason' => null,
        'default_currency' => 'chf',
    ]);

    expect($matches)->toBeTrue();
});

test('matchesAuthoritativeState detects a Stripe-side capability change', function () {
    $row = StripeConnectedAccount::factory()->active()->create();

    $matches = $row->matchesAuthoritativeState([
        'charges_enabled' => false,
    ]);

    expect($matches)->toBeFalse();
});

test('partial unique index rejects a second active row for the same business (D-122, codex Round 1)', function () {
    // Postgres treats every NULL as distinct in compound unique constraints,
    // so the original `unique(business_id, deleted_at)` did NOT enforce the
    // "one active row per business" invariant. The replacement is a partial
    // unique index on `business_id WHERE deleted_at IS NULL`. This test
    // proves the index actually blocks a duplicate active row.
    $row = StripeConnectedAccount::factory()->active()->create();

    expect(fn () => StripeConnectedAccount::factory()->active()->for($row->business)->create())
        ->toThrow(QueryException::class);
});

test('soft-deleted row coexists with a fresh active row for the same business (D-122)', function () {
    // Locked roadmap decision #36 retains `stripe_account_id` on the soft-
    // deleted row so 2b's late-webhook refund path can still target it. The
    // partial index must NOT treat a soft-deleted row as a conflict — only
    // active (deleted_at IS NULL) rows are unique per business.
    $first = StripeConnectedAccount::factory()->active()->create();
    $first->delete();

    $second = StripeConnectedAccount::factory()->active()->for($first->business)->create();

    expect($second->exists)->toBeTrue();
    expect($first->fresh()->trashed())->toBeTrue();
});
