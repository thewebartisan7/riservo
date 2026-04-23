<?php

/**
 * Codex Round 2 (D-159) regression guards: paid bookings cannot be
 * cancelled via any endpoint until Session 3 ships `RefundService`. The
 * token-based `BookingManagementController::cancel` guard already exists
 * from Round 1 (D-157); this file covers the authenticated-customer
 * (`Customer\BookingController::cancel`) and staff/admin
 * (`Dashboard\BookingController::updateStatus`) paths.
 */

use App\Enums\BookingStatus;
use App\Enums\BusinessMemberRole;
use App\Enums\PaymentStatus;
use App\Models\Booking;
use App\Models\Business;
use App\Models\Customer;
use App\Models\Provider;
use App\Models\Service;
use App\Models\User;

test('D-159 customer path: authenticated customer cannot cancel a paid booking', function () {
    $user = User::factory()->create();
    $customer = Customer::factory()->for($user)->create();

    $business = Business::factory()->create(['cancellation_window_hours' => 0]);
    $provider = Provider::factory()->for($business)->create();
    $service = Service::factory()->for($business)->create();

    $booking = Booking::factory()->paid()->create([
        'business_id' => $business->id,
        'provider_id' => $provider->id,
        'service_id' => $service->id,
        'customer_id' => $customer->id,
        'starts_at' => now()->addDays(7),
        'ends_at' => now()->addDays(7)->addHour(),
    ]);

    $response = $this->actingAs($user)->post("/my-bookings/{$booking->id}/cancel");

    $response->assertRedirect();
    expect($booking->fresh()->status)->toBe(BookingStatus::Confirmed);
    expect($booking->fresh()->payment_status)->toBe(PaymentStatus::Paid);
});

test('D-159 dashboard path: admin cannot transition a paid booking to cancelled', function () {
    $business = Business::factory()->onboarded()->create();
    $admin = User::factory()->create();
    $business->members()->attach($admin, ['role' => BusinessMemberRole::Admin->value]);

    $provider = Provider::factory()->for($business)->create();
    $service = Service::factory()->for($business)->create();
    $customer = Customer::factory()->create();

    $booking = Booking::factory()->paid()->create([
        'business_id' => $business->id,
        'provider_id' => $provider->id,
        'service_id' => $service->id,
        'customer_id' => $customer->id,
        'starts_at' => now()->addDays(7),
        'ends_at' => now()->addDays(7)->addHour(),
    ]);

    $response = $this->actingAs($admin)
        ->withSession(['current_business_id' => $business->id])
        ->patch("/dashboard/bookings/{$booking->id}/status", ['status' => 'cancelled']);

    // Either redirected back with an error flash, or 302 to login/etc; what
    // matters is the booking state did NOT change.
    expect($booking->fresh()->status)->toBe(BookingStatus::Confirmed);
    expect($booking->fresh()->payment_status)->toBe(PaymentStatus::Paid);
});
