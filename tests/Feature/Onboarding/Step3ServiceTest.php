<?php

use App\Enums\BusinessUserRole;
use App\Models\Business;
use App\Models\Service;
use App\Models\User;

beforeEach(function () {
    $this->withoutVite();
    $this->user = User::factory()->create(['email_verified_at' => now()]);
    $this->business = Business::factory()->create(['onboarding_step' => 3]);
    $this->business->users()->attach($this->user->id, ['role' => BusinessUserRole::Admin->value]);
});

test('step 3 page renders', function () {
    $response = $this->actingAs($this->user)->get('/onboarding/step/3');

    $response->assertSuccessful();
    $response->assertInertia(fn ($page) => $page
        ->component('onboarding/step-3')
        ->where('service', null)
    );
});

test('step 3 page renders with existing service', function () {
    Service::factory()->create([
        'business_id' => $this->business->id,
        'name' => 'Haircut',
    ]);

    $response = $this->actingAs($this->user)->get('/onboarding/step/3');

    $response->assertInertia(fn ($page) => $page
        ->where('service.name', 'Haircut')
    );
});

test('step 3 creates a service', function () {
    $response = $this->actingAs($this->user)->post('/onboarding/step/3', [
        'name' => 'Haircut',
        'duration_minutes' => 30,
        'price' => 45.00,
        'buffer_before' => 0,
        'buffer_after' => 10,
        'slot_interval_minutes' => 15,
    ]);

    $response->assertRedirect('/onboarding/step/4');

    $service = Service::where('business_id', $this->business->id)->first();
    expect($service)->not->toBeNull();
    expect($service->name)->toBe('Haircut');
    expect($service->slug)->toBe('haircut');
    expect($service->duration_minutes)->toBe(30);
    expect((float) $service->price)->toBe(45.00);
    expect($service->buffer_after)->toBe(10);
    expect($service->slot_interval_minutes)->toBe(15);

    $this->business->refresh();
    expect($this->business->onboarding_step)->toBe(4);
});

test('step 3 creates service with null price (on request)', function () {
    $this->actingAs($this->user)->post('/onboarding/step/3', [
        'name' => 'Consultation',
        'duration_minutes' => 60,
        'price' => null,
        'buffer_before' => 0,
        'buffer_after' => 0,
        'slot_interval_minutes' => 30,
    ]);

    $service = Service::where('business_id', $this->business->id)->first();
    expect($service->price)->toBeNull();
});

test('step 3 updates existing service on resubmit', function () {
    $service = Service::factory()->create([
        'business_id' => $this->business->id,
        'name' => 'Old Service',
    ]);

    $this->actingAs($this->user)->post('/onboarding/step/3', [
        'name' => 'New Service',
        'duration_minutes' => 45,
        'price' => 50,
        'buffer_before' => 5,
        'buffer_after' => 5,
        'slot_interval_minutes' => 15,
    ]);

    expect(Service::where('business_id', $this->business->id)->count())->toBe(1);
    $service->refresh();
    expect($service->name)->toBe('New Service');
});

test('step 3 validates required fields', function () {
    $response = $this->actingAs($this->user)->post('/onboarding/step/3', [
        'name' => '',
        'duration_minutes' => null,
        'slot_interval_minutes' => null,
    ]);

    $response->assertSessionHasErrors(['name', 'duration_minutes', 'slot_interval_minutes']);
});

test('step 3 validates duration range', function () {
    $response = $this->actingAs($this->user)->post('/onboarding/step/3', [
        'name' => 'Test',
        'duration_minutes' => 2,
        'slot_interval_minutes' => 15,
    ]);

    $response->assertSessionHasErrors(['duration_minutes']);
});

test('step 3 validates slot interval options', function () {
    $response = $this->actingAs($this->user)->post('/onboarding/step/3', [
        'name' => 'Test',
        'duration_minutes' => 30,
        'slot_interval_minutes' => 7,
    ]);

    $response->assertSessionHasErrors(['slot_interval_minutes']);
});
