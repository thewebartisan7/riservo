<?php

use App\Models\AvailabilityException;
use App\Models\AvailabilityRule;
use App\Models\Booking;
use App\Models\Business;
use App\Models\BusinessHour;
use App\Models\Customer;
use App\Models\Provider;
use App\Models\Service;
use App\Models\User;

test('seeder runs without errors', function () {
    $this->seed();

    expect(Business::count())->toBe(1)
        ->and(User::count())->toBe(7)  // 1 test user + 1 admin + 3 staff + 2 registered customers
        ->and(Provider::count())->toBe(3)
        ->and(Service::count())->toBe(5)
        ->and(Customer::count())->toBe(106)
        ->and(Booking::count())->toBe(113)
        ->and(BusinessHour::count())->toBe(11)
        ->and(AvailabilityRule::count())->toBe(25)
        ->and(AvailabilityException::count())->toBe(5);
});

test('seeded business has correct attributes', function () {
    $this->seed();

    $business = Business::where('slug', 'salone-bella')->first();

    expect($business)->not->toBeNull()
        ->and($business->name)->toBe('Salone Bella')
        ->and($business->timezone)->toBe('Europe/Zurich');
});

test('seeded business has correct number of members and providers', function () {
    $this->seed();

    $business = Business::where('slug', 'salone-bella')->first();

    expect($business->admins)->toHaveCount(1)
        ->and($business->staff)->toHaveCount(3)
        ->and($business->providers)->toHaveCount(3);
});
