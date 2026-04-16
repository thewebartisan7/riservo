<?php

use App\Enums\DayOfWeek;
use App\Enums\ExceptionType;
use App\Models\AvailabilityException;
use App\Models\AvailabilityRule;
use App\Models\Business;
use App\Models\Service;
use App\Models\User;

beforeEach(function () {
    $this->withoutVite();
    $this->business = Business::factory()->onboarded()->create(['timezone' => 'Europe/Zurich']);
    $this->admin = User::factory()->create();
    attachAdmin($this->business, $this->admin);
});

test('admin dashboard exposes unbookable services via bookability shared prop', function () {
    $service = Service::factory()->create([
        'business_id' => $this->business->id,
        'name' => 'Haircut',
        'is_active' => true,
    ]);
    // Service with no providers at all — structurally unbookable.

    $response = $this->actingAs($this->admin)->get('/dashboard');

    $response->assertSuccessful();
    $response->assertInertia(fn ($page) => $page
        ->has('bookability.unbookableServices', 1)
        ->where('bookability.unbookableServices.0.id', $service->id)
        ->where('bookability.unbookableServices.0.name', 'Haircut')
    );
});

test('banner prop is empty when every active service is structurally bookable', function () {
    $service = Service::factory()->create([
        'business_id' => $this->business->id,
        'is_active' => true,
    ]);
    $provider = attachProvider($this->business, $this->admin);
    $provider->services()->attach($service->id);
    AvailabilityRule::factory()->create([
        'provider_id' => $provider->id,
        'business_id' => $this->business->id,
        'day_of_week' => DayOfWeek::Monday->value,
        'start_time' => '09:00',
        'end_time' => '17:00',
    ]);

    $response = $this->actingAs($this->admin)->get('/dashboard');

    $response->assertSuccessful();
    $response->assertInertia(fn ($page) => $page
        ->where('bookability.unbookableServices', [])
    );
});

test('staff never sees unbookable services in the banner prop', function () {
    // Create a structurally unbookable service so the admin view would see it.
    Service::factory()->create([
        'business_id' => $this->business->id,
        'name' => 'Haircut',
        'is_active' => true,
    ]);

    $staff = User::factory()->create();
    attachStaff($this->business, $staff);

    $response = $this->actingAs($staff)->get('/dashboard');

    $response->assertSuccessful();
    $response->assertInertia(fn ($page) => $page
        ->where('bookability.unbookableServices', [])
    );
});

test('temporary unavailability (full-day block exception) does not trigger the banner', function () {
    $service = Service::factory()->create([
        'business_id' => $this->business->id,
        'is_active' => true,
    ]);
    $provider = attachProvider($this->business, $this->admin);
    $provider->services()->attach($service->id);
    // Provider has rules — structurally bookable.
    AvailabilityRule::factory()->create([
        'provider_id' => $provider->id,
        'business_id' => $this->business->id,
        'day_of_week' => DayOfWeek::Monday->value,
        'start_time' => '09:00',
        'end_time' => '17:00',
    ]);
    // But a full-day block exception is active right now.
    AvailabilityException::create([
        'business_id' => $this->business->id,
        'provider_id' => $provider->id,
        'start_date' => now()->startOfDay(),
        'end_date' => now()->endOfDay()->addWeek(),
        'start_time' => null,
        'end_time' => null,
        'type' => ExceptionType::Block,
        'reason' => 'Vacation',
    ]);

    $response = $this->actingAs($this->admin)->get('/dashboard');

    $response->assertInertia(fn ($page) => $page
        ->where('bookability.unbookableServices', [])
    );
});
