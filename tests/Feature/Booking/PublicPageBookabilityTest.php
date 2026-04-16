<?php

use App\Enums\DayOfWeek;
use App\Models\AvailabilityRule;
use App\Models\Business;
use App\Models\Service;
use App\Models\User;

beforeEach(function () {
    $this->withoutVite();
    $this->business = Business::factory()->onboarded()->create(['timezone' => 'Europe/Zurich']);
    $this->user = User::factory()->create();
});

test('public page hides a service whose provider has no availability rules', function () {
    $service = Service::factory()->create([
        'business_id' => $this->business->id,
        'name' => 'Haircut',
        'is_active' => true,
    ]);
    $provider = attachProvider($this->business, $this->user);
    $provider->services()->attach($service->id);
    // Note: no AvailabilityRule rows — structurally unbookable per D-078.

    $response = $this->get('/'.$this->business->slug);

    $response->assertInertia(fn ($page) => $page
        ->component('booking/show')
        ->where('services', [])
    );
});

test('public page shows a service whose provider has at least one availability rule', function () {
    $service = Service::factory()->create([
        'business_id' => $this->business->id,
        'name' => 'Haircut',
        'is_active' => true,
    ]);
    $provider = attachProvider($this->business, $this->user);
    $provider->services()->attach($service->id);
    AvailabilityRule::factory()->create([
        'provider_id' => $provider->id,
        'business_id' => $this->business->id,
        'day_of_week' => DayOfWeek::Monday->value,
        'start_time' => '09:00',
        'end_time' => '17:00',
    ]);

    $response = $this->get('/'.$this->business->slug);

    $response->assertInertia(fn ($page) => $page
        ->component('booking/show')
        ->has('services', 1)
        ->where('services.0.name', 'Haircut')
    );
});
