<?php

use App\Enums\BookingStatus;
use App\Models\Booking;
use App\Models\Business;
use App\Models\Service;
use App\Models\User;
use Carbon\CarbonImmutable;

beforeEach(function () {
    $this->business = Business::factory()->onboarded()->create();
    $this->collaborator = User::factory()->create();
    $this->business->users()->attach($this->collaborator, ['role' => 'collaborator']);
    $this->service = Service::factory()->create(['business_id' => $this->business->id]);
});

test('confirmed bookings past ends_at transition to completed', function () {
    $now = CarbonImmutable::parse('2026-04-13 12:00:00', 'UTC');
    $this->travelTo($now);

    $booking = Booking::factory()->confirmed()->create([
        'business_id' => $this->business->id,
        'collaborator_id' => $this->collaborator->id,
        'service_id' => $this->service->id,
        'starts_at' => $now->subHours(2),
        'ends_at' => $now->subHour(),
    ]);

    $this->artisan('bookings:auto-complete')->assertSuccessful();

    expect($booking->fresh()->status)->toBe(BookingStatus::Completed);
});

test('pending bookings are NOT auto-completed', function () {
    $now = CarbonImmutable::parse('2026-04-13 12:00:00', 'UTC');
    $this->travelTo($now);

    $booking = Booking::factory()->pending()->create([
        'business_id' => $this->business->id,
        'collaborator_id' => $this->collaborator->id,
        'service_id' => $this->service->id,
        'starts_at' => $now->subHours(2),
        'ends_at' => $now->subHour(),
    ]);

    $this->artisan('bookings:auto-complete')->assertSuccessful();

    expect($booking->fresh()->status)->toBe(BookingStatus::Pending);
});

test('future bookings are NOT touched', function () {
    $now = CarbonImmutable::parse('2026-04-13 12:00:00', 'UTC');
    $this->travelTo($now);

    $booking = Booking::factory()->confirmed()->create([
        'business_id' => $this->business->id,
        'collaborator_id' => $this->collaborator->id,
        'service_id' => $this->service->id,
        'starts_at' => $now->addHour(),
        'ends_at' => $now->addHours(2),
    ]);

    $this->artisan('bookings:auto-complete')->assertSuccessful();

    expect($booking->fresh()->status)->toBe(BookingStatus::Confirmed);
});

test('cancelled bookings are NOT touched', function () {
    $now = CarbonImmutable::parse('2026-04-13 12:00:00', 'UTC');
    $this->travelTo($now);

    $booking = Booking::factory()->cancelled()->create([
        'business_id' => $this->business->id,
        'collaborator_id' => $this->collaborator->id,
        'service_id' => $this->service->id,
        'starts_at' => $now->subHours(2),
        'ends_at' => $now->subHour(),
    ]);

    $this->artisan('bookings:auto-complete')->assertSuccessful();

    expect($booking->fresh()->status)->toBe(BookingStatus::Cancelled);
});
