<?php

use App\Enums\BookingStatus;
use App\Models\Booking;
use App\Models\Business;
use App\Models\Customer;
use App\Models\Service;
use App\Models\User;

beforeEach(function () {
    $this->business = Business::factory()->onboarded()->create();
    $this->admin = User::factory()->create();
    $this->business->users()->attach($this->admin, ['role' => 'admin']);

    $this->collaborator = User::factory()->create();
    $this->business->users()->attach($this->collaborator, ['role' => 'collaborator']);

    $this->service = Service::factory()->create(['business_id' => $this->business->id]);
    $this->customer = Customer::factory()->create();
});

function createBooking(BookingStatus $status): Booking
{
    return Booking::factory()->create([
        'business_id' => test()->business->id,
        'collaborator_id' => test()->collaborator->id,
        'service_id' => test()->service->id,
        'customer_id' => test()->customer->id,
        'status' => $status,
    ]);
}

test('pending to confirmed succeeds', function () {
    $booking = createBooking(BookingStatus::Pending);

    $response = $this->actingAs($this->admin)
        ->patch("/dashboard/bookings/{$booking->id}/status", ['status' => 'confirmed']);

    $response->assertRedirect();
    expect($booking->fresh()->status)->toBe(BookingStatus::Confirmed);
});

test('pending to cancelled succeeds', function () {
    $booking = createBooking(BookingStatus::Pending);

    $response = $this->actingAs($this->admin)
        ->patch("/dashboard/bookings/{$booking->id}/status", ['status' => 'cancelled']);

    $response->assertRedirect();
    expect($booking->fresh()->status)->toBe(BookingStatus::Cancelled);
});

test('confirmed to no_show succeeds', function () {
    $booking = createBooking(BookingStatus::Confirmed);

    $response = $this->actingAs($this->admin)
        ->patch("/dashboard/bookings/{$booking->id}/status", ['status' => 'no_show']);

    $response->assertRedirect();
    expect($booking->fresh()->status)->toBe(BookingStatus::NoShow);
});

test('confirmed to completed succeeds', function () {
    $booking = createBooking(BookingStatus::Confirmed);

    $response = $this->actingAs($this->admin)
        ->patch("/dashboard/bookings/{$booking->id}/status", ['status' => 'completed']);

    $response->assertRedirect();
    expect($booking->fresh()->status)->toBe(BookingStatus::Completed);
});

test('cancelled to confirmed fails', function () {
    $booking = createBooking(BookingStatus::Cancelled);

    $response = $this->actingAs($this->admin)
        ->patch("/dashboard/bookings/{$booking->id}/status", ['status' => 'confirmed']);

    $response->assertRedirect();
    $response->assertSessionHas('error');
    expect($booking->fresh()->status)->toBe(BookingStatus::Cancelled);
});

test('completed to pending fails', function () {
    $booking = createBooking(BookingStatus::Completed);

    $response = $this->actingAs($this->admin)
        ->patch("/dashboard/bookings/{$booking->id}/status", ['status' => 'pending']);

    $response->assertRedirect();
    $response->assertSessionHas('error');
    expect($booking->fresh()->status)->toBe(BookingStatus::Completed);
});

test('collaborator can change own booking status', function () {
    $booking = createBooking(BookingStatus::Pending);

    $response = $this->actingAs($this->collaborator)
        ->patch("/dashboard/bookings/{$booking->id}/status", ['status' => 'confirmed']);

    $response->assertRedirect();
    expect($booking->fresh()->status)->toBe(BookingStatus::Confirmed);
});

test('collaborator cannot change other collaborator booking status', function () {
    $otherCollaborator = User::factory()->create();
    $this->business->users()->attach($otherCollaborator, ['role' => 'collaborator']);

    $booking = Booking::factory()->pending()->create([
        'business_id' => $this->business->id,
        'collaborator_id' => $otherCollaborator->id,
        'service_id' => $this->service->id,
        'customer_id' => $this->customer->id,
    ]);

    $response = $this->actingAs($this->collaborator)
        ->patch("/dashboard/bookings/{$booking->id}/status", ['status' => 'confirmed']);

    $response->assertForbidden();
});

test('internal notes update works', function () {
    $booking = createBooking(BookingStatus::Confirmed);

    $response = $this->actingAs($this->admin)
        ->patch("/dashboard/bookings/{$booking->id}/notes", ['internal_notes' => 'VIP customer']);

    $response->assertRedirect();
    expect($booking->fresh()->internal_notes)->toBe('VIP customer');
});

test('internal notes can be cleared', function () {
    $booking = createBooking(BookingStatus::Confirmed);
    $booking->update(['internal_notes' => 'Old note']);

    $response = $this->actingAs($this->admin)
        ->patch("/dashboard/bookings/{$booking->id}/notes", ['internal_notes' => null]);

    $response->assertRedirect();
    expect($booking->fresh()->internal_notes)->toBeNull();
});

test('booking from another business returns 404', function () {
    $otherBusiness = Business::factory()->onboarded()->create();
    $booking = Booking::factory()->pending()->create(['business_id' => $otherBusiness->id]);

    $response = $this->actingAs($this->admin)
        ->patch("/dashboard/bookings/{$booking->id}/status", ['status' => 'confirmed']);

    $response->assertNotFound();
});
