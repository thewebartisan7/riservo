<?php

use App\Models\Booking;
use App\Models\Business;
use App\Models\Customer;
use App\Models\Service;
use App\Models\User;
use Carbon\CarbonImmutable;

beforeEach(function () {
    $this->user = User::factory()->create();
    $this->customer = Customer::factory()->create([
        'user_id' => $this->user->id,
        'email' => $this->user->email,
    ]);

    $this->business = Business::factory()->onboarded()->create(['timezone' => 'Europe/Zurich']);
    $this->service = Service::factory()->create(['business_id' => $this->business->id]);
});

test('customer bookings list renders with deactivated provider', function () {
    $staff = User::factory()->create(['name' => 'Trashed Staff']);
    $provider = attachProvider($this->business, $staff);

    Booking::factory()->confirmed()->create([
        'business_id' => $this->business->id,
        'provider_id' => $provider->id,
        'service_id' => $this->service->id,
        'customer_id' => $this->customer->id,
        'starts_at' => CarbonImmutable::parse('2030-05-01 09:00', 'UTC'),
        'ends_at' => CarbonImmutable::parse('2030-05-01 10:00', 'UTC'),
    ]);

    $provider->delete();

    $response = $this->actingAs($this->user)->get('/my-bookings');

    $response->assertSuccessful()
        ->assertInertia(fn ($page) => $page
            ->component('customer/bookings')
            ->has('upcoming', 1)
            ->where('upcoming.0.provider.name', 'Trashed Staff')
            ->where('upcoming.0.provider.is_active', false)
        );
});

test('customer bookings list passes business.timezone per booking', function () {
    $this->business->update(['timezone' => 'Asia/Tokyo']);

    $staff = User::factory()->create();
    $provider = attachProvider($this->business, $staff);

    Booking::factory()->confirmed()->create([
        'business_id' => $this->business->id,
        'provider_id' => $provider->id,
        'service_id' => $this->service->id,
        'customer_id' => $this->customer->id,
        'starts_at' => CarbonImmutable::parse('2030-05-01 09:00', 'UTC'),
        'ends_at' => CarbonImmutable::parse('2030-05-01 10:00', 'UTC'),
    ]);

    $response = $this->actingAs($this->user)->get('/my-bookings');

    $response->assertSuccessful()
        ->assertInertia(fn ($page) => $page
            ->has('upcoming', 1)
            ->where('upcoming.0.business.timezone', 'Asia/Tokyo')
        );
});
