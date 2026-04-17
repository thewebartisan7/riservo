<?php

use App\Models\Business;
use Illuminate\Support\Carbon;
use Laravel\Cashier\Subscription;

beforeEach(function () {
    $this->business = Business::factory()->onboarded()->create();
});

test('a business with no subscription row is on indefinite trial', function () {
    expect($this->business->onTrial())->toBeTrue()
        ->and($this->business->subscriptionState())->toBe('trial')
        ->and($this->business->canWrite())->toBeTrue();
});

test('subscriptionStateForPayload reports null period ends in trial', function () {
    $payload = $this->business->subscriptionStateForPayload();

    expect($payload)->toMatchArray([
        'status' => 'trial',
        'trial_ends_at' => null,
        'current_period_ends_at' => null,
    ]);
});

test('an active subscription transitions state to active', function () {
    Subscription::factory()
        ->for($this->business, 'owner')
        ->active()
        ->withPrice('price_test_monthly')
        ->create();

    $this->business->refresh();

    expect($this->business->onTrial())->toBeFalse()
        ->and($this->business->subscriptionState())->toBe('active')
        ->and($this->business->canWrite())->toBeTrue();
});

test('a past_due subscription is still write-allowed', function () {
    Subscription::factory()
        ->for($this->business, 'owner')
        ->pastDue()
        ->withPrice('price_test_monthly')
        ->create();

    $this->business->refresh();

    expect($this->business->subscriptionState())->toBe('past_due')
        ->and($this->business->canWrite())->toBeTrue();
});

test('a cancel-at-period-end subscription within grace reports canceled and stays writable', function () {
    Subscription::factory()
        ->for($this->business, 'owner')
        ->active()
        ->withPrice('price_test_monthly')
        ->create([
            'ends_at' => now()->addDays(10),
        ]);

    $this->business->refresh();

    expect($this->business->subscriptionState())->toBe('canceled')
        ->and($this->business->canWrite())->toBeTrue();
});

test('a fully ended subscription transitions to read_only and blocks writes', function () {
    Subscription::factory()
        ->for($this->business, 'owner')
        ->canceled()
        ->withPrice('price_test_monthly')
        ->create([
            'ends_at' => now()->subDay(),
        ]);

    $this->business->refresh();

    expect($this->business->subscriptionState())->toBe('read_only')
        ->and($this->business->canWrite())->toBeFalse();
});

test('subscriptionStateForPayload serialises trial_ends_at when set', function () {
    // trial_ends_at must be cast to datetime — without the cast, this call
    // would crash with "Call to a member function toISOString() on string".
    $trialEnds = now()->addDays(30);
    $this->business->update(['trial_ends_at' => $trialEnds]);

    $payload = $this->business->refresh()->subscriptionStateForPayload();

    expect($payload['trial_ends_at'])->not->toBeNull()
        ->and(Carbon::parse($payload['trial_ends_at'])->isSameMinute($trialEnds))->toBeTrue();
});

test('subscriptionStateForPayload includes ISO ends_at when canceling', function () {
    $endsAt = now()->addDays(5);

    Subscription::factory()
        ->for($this->business, 'owner')
        ->active()
        ->withPrice('price_test_monthly')
        ->create([
            'ends_at' => $endsAt,
        ]);

    $this->business->refresh();
    $payload = $this->business->subscriptionStateForPayload();

    expect($payload['status'])->toBe('canceled')
        ->and($payload['current_period_ends_at'])->not->toBeNull()
        ->and(Carbon::parse($payload['current_period_ends_at'])->isSameMinute($endsAt))->toBeTrue();
});
