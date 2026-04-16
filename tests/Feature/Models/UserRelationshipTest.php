<?php

use App\Enums\BusinessMemberRole;
use App\Models\Business;
use App\Models\CalendarIntegration;
use App\Models\Customer;
use App\Models\Provider;
use App\Models\User;

test('it has many businesses through pivot', function () {
    $user = User::factory()->create();
    $business = Business::factory()->create();
    $business->members()->attach($user, ['role' => BusinessMemberRole::Admin]);

    expect($user->businesses)->toHaveCount(1)
        ->and($user->businesses->first()->pivot->role)->toBe(BusinessMemberRole::Admin);
});

test('it has many providers', function () {
    $user = User::factory()->create();
    $business1 = Business::factory()->create();
    $business2 = Business::factory()->create();

    attachProvider($business1, $user);
    attachProvider($business2, $user);

    expect($user->providers)->toHaveCount(2)
        ->and($user->providers->first())->toBeInstanceOf(Provider::class);
});

test('it has one calendar integration', function () {
    $user = User::factory()->create();
    CalendarIntegration::factory()->create(['user_id' => $user->id]);

    expect($user->calendarIntegration)->toBeInstanceOf(CalendarIntegration::class);
});

test('it has one customer record', function () {
    $user = User::factory()->create();
    Customer::factory()->create(['user_id' => $user->id]);

    expect($user->customer)->toBeInstanceOf(Customer::class);
});
