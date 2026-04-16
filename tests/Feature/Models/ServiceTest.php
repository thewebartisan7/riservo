<?php

use App\Models\Booking;
use App\Models\Business;
use App\Models\Provider;
use App\Models\Service;
use Illuminate\Database\UniqueConstraintViolationException;

test('it creates a service with factory defaults', function () {
    $service = Service::factory()->create();

    expect($service)->toBeInstanceOf(Service::class)
        ->and($service->is_active)->toBeTrue();
});

test('it belongs to a business', function () {
    $service = Service::factory()->create();

    expect($service->business)->toBeInstanceOf(Business::class);
});

test('it has many providers through pivot', function () {
    $service = Service::factory()->create();
    $provider1 = Provider::factory()->create();
    $provider2 = Provider::factory()->create();

    $service->providers()->attach([$provider1->id, $provider2->id]);

    expect($service->providers)->toHaveCount(2);
});

test('it has many bookings', function () {
    $service = Service::factory()->create();
    Booking::factory()->count(2)->create(['service_id' => $service->id]);

    expect($service->bookings)->toHaveCount(2);
});

test('nullable price means on request', function () {
    $service = Service::factory()->onRequest()->create();

    expect($service->price)->toBeNull();
});

test('zero price means free', function () {
    $service = Service::factory()->free()->create();

    expect((float) $service->price)->toBe(0.00);
});

test('inactive state works', function () {
    $service = Service::factory()->inactive()->create();

    expect($service->is_active)->toBeFalse();
});

test('slug is unique within a business', function () {
    $business = Business::factory()->create();
    Service::factory()->create(['business_id' => $business->id, 'slug' => 'haircut']);

    expect(fn () => Service::factory()->create(['business_id' => $business->id, 'slug' => 'haircut']))
        ->toThrow(UniqueConstraintViolationException::class);
});

test('same slug is allowed in different businesses', function () {
    $business1 = Business::factory()->create();
    $business2 = Business::factory()->create();

    Service::factory()->create(['business_id' => $business1->id, 'slug' => 'haircut']);
    $service2 = Service::factory()->create(['business_id' => $business2->id, 'slug' => 'haircut']);

    expect($service2->slug)->toBe('haircut');
});
