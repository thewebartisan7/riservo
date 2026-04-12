<?php

use App\Enums\BookingSource;
use App\Enums\BookingStatus;
use App\Enums\PaymentStatus;
use App\Models\Booking;
use App\Models\Business;
use App\Models\Customer;
use App\Models\Service;
use App\Models\User;
use Illuminate\Support\Carbon;

test('it creates a booking with factory defaults', function () {
    $booking = Booking::factory()->create();

    expect($booking)->toBeInstanceOf(Booking::class)
        ->and($booking->status)->toBe(BookingStatus::Confirmed)
        ->and($booking->source)->toBe(BookingSource::Riservo)
        ->and($booking->payment_status)->toBe(PaymentStatus::Pending);
});

test('it belongs to a business', function () {
    $booking = Booking::factory()->create();

    expect($booking->business)->toBeInstanceOf(Business::class);
});

test('it belongs to a collaborator (user)', function () {
    $user = User::factory()->create();
    $booking = Booking::factory()->create(['collaborator_id' => $user->id]);

    expect($booking->collaborator)->toBeInstanceOf(User::class)
        ->and($booking->collaborator->id)->toBe($user->id);
});

test('it belongs to a service', function () {
    $booking = Booking::factory()->create();

    expect($booking->service)->toBeInstanceOf(Service::class);
});

test('it belongs to a customer', function () {
    $booking = Booking::factory()->create();

    expect($booking->customer)->toBeInstanceOf(Customer::class);
});

test('it casts starts_at and ends_at to datetime', function () {
    $booking = Booking::factory()->create();

    expect($booking->starts_at)->toBeInstanceOf(Carbon::class)
        ->and($booking->ends_at)->toBeInstanceOf(Carbon::class);
});

test('it casts status to BookingStatus enum', function () {
    $booking = Booking::factory()->create();

    expect($booking->status)->toBeInstanceOf(BookingStatus::class);
});

test('pending state works', function () {
    $booking = Booking::factory()->pending()->create();

    expect($booking->status)->toBe(BookingStatus::Pending);
});

test('cancelled state works', function () {
    $booking = Booking::factory()->cancelled()->create();

    expect($booking->status)->toBe(BookingStatus::Cancelled);
});

test('completed state works', function () {
    $booking = Booking::factory()->completed()->create();

    expect($booking->status)->toBe(BookingStatus::Completed);
});

test('no show state works', function () {
    $booking = Booking::factory()->noShow()->create();

    expect($booking->status)->toBe(BookingStatus::NoShow);
});

test('past state creates booking in the past', function () {
    $booking = Booking::factory()->past()->create();

    expect($booking->starts_at->isPast())->toBeTrue();
});

test('manual state sets source to manual', function () {
    $booking = Booking::factory()->manual()->create();

    expect($booking->source)->toBe(BookingSource::Manual);
});

test('cancellation token is unique', function () {
    $booking1 = Booking::factory()->create();
    $booking2 = Booking::factory()->create();

    expect($booking1->cancellation_token)->not->toBe($booking2->cancellation_token);
});
