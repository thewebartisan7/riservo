<?php

use App\Models\Business;
use App\Models\StripeConnectedAccount;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

test('canAcceptOnlinePayments is false when no connected account exists', function () {
    $business = Business::factory()->create();

    expect($business->canAcceptOnlinePayments())->toBeFalse();
});

test('canAcceptOnlinePayments is false when the connected account is incomplete', function () {
    $business = Business::factory()->create();
    StripeConnectedAccount::factory()->incomplete()->for($business)->create();

    expect($business->fresh()->canAcceptOnlinePayments())->toBeFalse();
});

test('canAcceptOnlinePayments is true when the connected account is fully verified', function () {
    $business = Business::factory()->create();
    StripeConnectedAccount::factory()->active()->for($business)->create();

    expect($business->fresh()->canAcceptOnlinePayments())->toBeTrue();
});

test('canAcceptOnlinePayments is false when the connected account row is soft-deleted', function () {
    $business = Business::factory()->create();
    $row = StripeConnectedAccount::factory()->active()->for($business)->create();
    $row->delete(); // soft-delete (D-111 / locked roadmap decision #36)

    expect($business->fresh()->canAcceptOnlinePayments())->toBeFalse();
});

test('canAcceptOnlinePayments is false when capabilities are active but country is unsupported (D-127, codex Round 3)', function () {
    // Codex Round 3 finding: the helper used to ignore the country gate
    // (locked roadmap decision #43). A Stripe-active account that resolved
    // to an unsupported country would still surface as "ready" everywhere
    // the helper is consulted (Inertia banner, webhook demotion, Settings
    // UI). Now the country must be in `config('payments.supported_countries')`.
    $business = Business::factory()->create();
    StripeConnectedAccount::factory()->active()->for($business)->create([
        'country' => 'DE', // unsupported in MVP (supported_countries = ['CH']).
    ]);

    expect($business->fresh()->canAcceptOnlinePayments())->toBeFalse();
});

test('canAcceptOnlinePayments is false when requirements_disabled_reason is non-null even with active-looking capabilities (D-138, codex Round 7)', function () {
    // Codex Round 7 finding: Stripe's `requirements_disabled_reason` is the
    // authoritative signal that an account can't process charges/transfers.
    // Stripe's capability booleans and disabled_reason can drift from each
    // other (eventual consistency), so the helper folds in disabled_reason
    // as a hard fail-closed check.
    $business = Business::factory()->create();
    StripeConnectedAccount::factory()->active()->for($business)->create([
        'requirements_disabled_reason' => 'rejected.fraud',
    ]);

    expect($business->fresh()->canAcceptOnlinePayments())->toBeFalse();
});

test('canAcceptOnlinePayments respects an expanded supported_countries set (D-127)', function () {
    // Forward-looking: when the supported set is config-flipped to include
    // additional countries, the helper picks it up immediately. Proves the
    // gate is truly config-driven and not hardcoded.
    config(['payments.supported_countries' => ['CH', 'DE']]);

    $business = Business::factory()->create();
    StripeConnectedAccount::factory()->active()->for($business)->create([
        'country' => 'DE',
    ]);

    expect($business->fresh()->canAcceptOnlinePayments())->toBeTrue();
});
