<?php

use App\Enums\BookingStatus;
use App\Models\Booking;
use App\Models\Business;
use App\Models\Customer;
use App\Models\Service;
use App\Models\User;

test('booking can be viewed by cancellation token', function () {
    $this->withoutVite();
    $business = Business::factory()->create();
    $staff = User::factory()->create();
    $provider = attachProvider($business, $staff);
    $service = Service::factory()->create(['business_id' => $business->id]);
    $customer = Customer::factory()->create();

    $booking = Booking::factory()->create([
        'business_id' => $business->id,
        'provider_id' => $provider->id,
        'service_id' => $service->id,
        'customer_id' => $customer->id,
        'cancellation_token' => 'test-token-123',
    ]);

    $response = $this->get('/bookings/test-token-123');

    $response->assertStatus(200);
});

test('invalid token returns 404', function () {
    $response = $this->get('/bookings/non-existent-token');

    $response->assertStatus(404);
});

test('booking can be cancelled via token', function () {
    $business = Business::factory()->create(['cancellation_window_hours' => 0]);
    $staff = User::factory()->create();
    $provider = attachProvider($business, $staff);
    $service = Service::factory()->create(['business_id' => $business->id]);
    $customer = Customer::factory()->create();

    $booking = Booking::factory()->future()->confirmed()->create([
        'business_id' => $business->id,
        'provider_id' => $provider->id,
        'service_id' => $service->id,
        'customer_id' => $customer->id,
        'cancellation_token' => 'cancel-token-123',
    ]);

    $response = $this->post('/bookings/cancel-token-123/cancel');

    expect($booking->fresh()->status)->toBe(BookingStatus::Cancelled);
    $response->assertSessionHas('success');
});

test('already cancelled booking cannot be cancelled again', function () {
    $business = Business::factory()->create(['cancellation_window_hours' => 0]);
    $staff = User::factory()->create();
    $provider = attachProvider($business, $staff);
    $service = Service::factory()->create(['business_id' => $business->id]);
    $customer = Customer::factory()->create();

    $booking = Booking::factory()->cancelled()->create([
        'business_id' => $business->id,
        'provider_id' => $provider->id,
        'service_id' => $service->id,
        'customer_id' => $customer->id,
        'cancellation_token' => 'cancel-token-456',
    ]);

    $response = $this->post('/bookings/cancel-token-456/cancel');

    $response->assertSessionHas('error');
});

test('cancellation within window is blocked', function () {
    $business = Business::factory()->create(['cancellation_window_hours' => 24]);
    $staff = User::factory()->create();
    $provider = attachProvider($business, $staff);
    $service = Service::factory()->create(['business_id' => $business->id]);
    $customer = Customer::factory()->create();

    // Booking starts in 2 hours (within 24h window)
    $booking = Booking::factory()->confirmed()->create([
        'business_id' => $business->id,
        'provider_id' => $provider->id,
        'service_id' => $service->id,
        'customer_id' => $customer->id,
        'starts_at' => now()->addHours(2),
        'ends_at' => now()->addHours(3),
        'cancellation_token' => 'cancel-token-789',
    ]);

    $response = $this->post('/bookings/cancel-token-789/cancel');

    expect($booking->fresh()->status)->toBe(BookingStatus::Confirmed);
    $response->assertSessionHas('error');
});

test('booking management page renders with deactivated provider', function () {
    $business = Business::factory()->create(['timezone' => 'Europe/Zurich']);
    $staff = User::factory()->create(['name' => 'Trashed Staff']);
    $provider = attachProvider($business, $staff);
    $service = Service::factory()->create(['business_id' => $business->id]);
    $customer = Customer::factory()->create();

    Booking::factory()->confirmed()->create([
        'business_id' => $business->id,
        'provider_id' => $provider->id,
        'service_id' => $service->id,
        'customer_id' => $customer->id,
        'cancellation_token' => 'deactivated-provider-token',
    ]);

    $provider->delete();

    $response = $this->get('/bookings/deactivated-provider-token');

    $response->assertSuccessful()
        ->assertInertia(fn ($page) => $page
            ->component('bookings/show')
            ->where('booking.provider.name', 'Trashed Staff')
            ->where('booking.provider.is_active', false)
        );
});

test('booking management page passes business.timezone through to the page', function () {
    $business = Business::factory()->create(['timezone' => 'Asia/Tokyo']);
    $staff = User::factory()->create();
    $provider = attachProvider($business, $staff);
    $service = Service::factory()->create(['business_id' => $business->id]);
    $customer = Customer::factory()->create();

    Booking::factory()->confirmed()->create([
        'business_id' => $business->id,
        'provider_id' => $provider->id,
        'service_id' => $service->id,
        'customer_id' => $customer->id,
        'cancellation_token' => 'timezone-token',
    ]);

    $response = $this->get('/bookings/timezone-token');

    $response->assertSuccessful()
        ->assertInertia(fn ($page) => $page
            ->component('bookings/show')
            ->where('booking.business.timezone', 'Asia/Tokyo')
        );
});
