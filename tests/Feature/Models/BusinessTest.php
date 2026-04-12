<?php

use App\Enums\AssignmentStrategy;
use App\Enums\BusinessUserRole;
use App\Enums\ConfirmationMode;
use App\Enums\PaymentMode;
use App\Models\AvailabilityException;
use App\Models\Booking;
use App\Models\Business;
use App\Models\BusinessHour;
use App\Models\Service;
use App\Models\User;
use Illuminate\Database\UniqueConstraintViolationException;

test('it creates a business with factory defaults', function () {
    $business = Business::factory()->create();

    expect($business)->toBeInstanceOf(Business::class)
        ->and($business->name)->toBeString()
        ->and($business->slug)->toBeString()
        ->and($business->timezone)->toBe('Europe/Zurich');
});

test('it casts payment_mode to PaymentMode enum', function () {
    $business = Business::factory()->create();

    expect($business->payment_mode)->toBeInstanceOf(PaymentMode::class)
        ->and($business->payment_mode)->toBe(PaymentMode::Offline);
});

test('it casts confirmation_mode to ConfirmationMode enum', function () {
    $business = Business::factory()->create();

    expect($business->confirmation_mode)->toBeInstanceOf(ConfirmationMode::class)
        ->and($business->confirmation_mode)->toBe(ConfirmationMode::Auto);
});

test('it casts assignment_strategy to AssignmentStrategy enum', function () {
    $business = Business::factory()->create();

    expect($business->assignment_strategy)->toBeInstanceOf(AssignmentStrategy::class)
        ->and($business->assignment_strategy)->toBe(AssignmentStrategy::FirstAvailable);
});

test('it casts reminder_hours to array', function () {
    $business = Business::factory()->create(['reminder_hours' => [24, 1]]);

    expect($business->reminder_hours)->toBeArray()
        ->and($business->reminder_hours)->toBe([24, 1]);
});

test('it has many users through pivot with role', function () {
    $business = Business::factory()->create();
    $admin = User::factory()->create();
    $collaborator = User::factory()->create();

    $business->users()->attach($admin, ['role' => BusinessUserRole::Admin]);
    $business->users()->attach($collaborator, ['role' => BusinessUserRole::Collaborator]);

    expect($business->users)->toHaveCount(2)
        ->and($business->users->first()->pivot->role)->toBe(BusinessUserRole::Admin);
});

test('it scopes admins and collaborators', function () {
    $business = Business::factory()->create();
    $admin = User::factory()->create();
    $collaborator = User::factory()->create();

    $business->users()->attach($admin, ['role' => BusinessUserRole::Admin]);
    $business->users()->attach($collaborator, ['role' => BusinessUserRole::Collaborator]);

    expect($business->admins)->toHaveCount(1)
        ->and($business->admins->first()->id)->toBe($admin->id)
        ->and($business->collaborators)->toHaveCount(1)
        ->and($business->collaborators->first()->id)->toBe($collaborator->id);
});

test('it has many business hours', function () {
    $business = Business::factory()->create();
    BusinessHour::factory()->count(3)->create(['business_id' => $business->id]);

    expect($business->businessHours)->toHaveCount(3);
});

test('it has many services', function () {
    $business = Business::factory()->create();
    Service::factory()->count(2)->create(['business_id' => $business->id]);

    expect($business->services)->toHaveCount(2);
});

test('it has many bookings', function () {
    $business = Business::factory()->create();
    Booking::factory()->count(2)->create(['business_id' => $business->id]);

    expect($business->bookings)->toHaveCount(2);
});

test('it has many availability exceptions', function () {
    $business = Business::factory()->create();
    AvailabilityException::factory()->count(2)->create(['business_id' => $business->id]);

    expect($business->availabilityExceptions)->toHaveCount(2);
});

test('it enforces unique slug', function () {
    Business::factory()->create(['slug' => 'test-slug']);

    expect(fn () => Business::factory()->create(['slug' => 'test-slug']))
        ->toThrow(UniqueConstraintViolationException::class);
});

test('manual confirmation state works', function () {
    $business = Business::factory()->manualConfirmation()->create();

    expect($business->confirmation_mode)->toBe(ConfirmationMode::Manual);
});
