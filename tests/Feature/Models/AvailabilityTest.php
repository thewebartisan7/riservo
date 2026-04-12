<?php

use App\Enums\DayOfWeek;
use App\Enums\ExceptionType;
use App\Models\AvailabilityException;
use App\Models\AvailabilityRule;
use App\Models\Business;
use App\Models\User;
use Illuminate\Support\Carbon;

test('availability rule belongs to a collaborator', function () {
    $user = User::factory()->create();
    $rule = AvailabilityRule::factory()->create(['collaborator_id' => $user->id]);

    expect($rule->collaborator)->toBeInstanceOf(User::class)
        ->and($rule->collaborator->id)->toBe($user->id);
});

test('availability rule belongs to a business', function () {
    $rule = AvailabilityRule::factory()->create();

    expect($rule->business)->toBeInstanceOf(Business::class);
});

test('availability rule casts day_of_week to DayOfWeek enum', function () {
    $rule = AvailabilityRule::factory()->create(['day_of_week' => DayOfWeek::Monday]);

    expect($rule->day_of_week)->toBe(DayOfWeek::Monday);
});

test('business-level exception has null collaborator_id', function () {
    $exception = AvailabilityException::factory()->create();

    expect($exception->collaborator_id)->toBeNull()
        ->and($exception->business)->toBeInstanceOf(Business::class);
});

test('collaborator-level exception has collaborator', function () {
    $user = User::factory()->create();
    $exception = AvailabilityException::factory()->forCollaborator($user)->create();

    expect($exception->collaborator)->toBeInstanceOf(User::class)
        ->and($exception->collaborator->id)->toBe($user->id);
});

test('exception supports date ranges', function () {
    $exception = AvailabilityException::factory()->multiDay(5)->create();

    expect($exception->start_date)->toBeInstanceOf(Carbon::class)
        ->and($exception->end_date)->toBeInstanceOf(Carbon::class)
        ->and($exception->end_date->gt($exception->start_date))->toBeTrue();
});

test('full day exception has null times', function () {
    $exception = AvailabilityException::factory()->create();

    expect($exception->start_time)->toBeNull()
        ->and($exception->end_time)->toBeNull();
});

test('partial day exception has specific times', function () {
    $exception = AvailabilityException::factory()->partialDay('10:00', '11:00')->create();

    expect($exception->start_time)->toContain('10:00')
        ->and($exception->end_time)->toContain('11:00');
});

test('exception casts type to ExceptionType enum', function () {
    $block = AvailabilityException::factory()->create(['type' => ExceptionType::Block]);
    $open = AvailabilityException::factory()->open()->create();

    expect($block->type)->toBe(ExceptionType::Block)
        ->and($open->type)->toBe(ExceptionType::Open);
});
