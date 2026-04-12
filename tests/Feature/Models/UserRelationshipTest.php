<?php

use App\Enums\BusinessUserRole;
use App\Models\AvailabilityException;
use App\Models\AvailabilityRule;
use App\Models\Booking;
use App\Models\Business;
use App\Models\CalendarIntegration;
use App\Models\Customer;
use App\Models\Service;
use App\Models\User;

test('it has many businesses through pivot', function () {
    $user = User::factory()->create();
    $business = Business::factory()->create();
    $business->users()->attach($user, ['role' => BusinessUserRole::Admin]);

    expect($user->businesses)->toHaveCount(1)
        ->and($user->businesses->first()->pivot->role)->toBe(BusinessUserRole::Admin);
});

test('it has many availability rules as collaborator', function () {
    $user = User::factory()->create();
    AvailabilityRule::factory()->count(3)->create(['collaborator_id' => $user->id]);

    expect($user->availabilityRules)->toHaveCount(3);
});

test('it has many availability exceptions as collaborator', function () {
    $user = User::factory()->create();
    AvailabilityException::factory()->count(2)->forCollaborator($user)->create();

    expect($user->availabilityExceptions)->toHaveCount(2);
});

test('it has many services as collaborator', function () {
    $user = User::factory()->create();
    $service1 = Service::factory()->create();
    $service2 = Service::factory()->create();

    $user->services()->attach([$service1->id, $service2->id]);

    expect($user->services)->toHaveCount(2);
});

test('it has many bookings as collaborator', function () {
    $user = User::factory()->create();
    Booking::factory()->count(3)->create(['collaborator_id' => $user->id]);

    expect($user->bookingsAsCollaborator)->toHaveCount(3);
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
