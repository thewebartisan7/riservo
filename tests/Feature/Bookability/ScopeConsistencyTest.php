<?php

use App\Enums\DayOfWeek;
use App\Models\AvailabilityRule;
use App\Models\Business;
use App\Models\Service;
use App\Models\User;

beforeEach(function () {
    $this->withoutVite();
});

test('onboarding launch, public page, and dashboard banner agree on the unbookable set', function () {
    // Business still on step 5 so we can drive storeLaunch().
    $business = Business::factory()->create([
        'onboarding_step' => 5,
        'timezone' => 'Europe/Zurich',
    ]);
    $admin = User::factory()->create(['email_verified_at' => now()]);
    attachAdmin($business, $admin);

    // Service A — active, provider attached, availability rules present. Structurally bookable.
    $serviceA = Service::factory()->create([
        'business_id' => $business->id,
        'name' => 'Haircut',
        'is_active' => true,
    ]);
    $providerA = attachProvider($business, $admin);
    $providerA->services()->attach($serviceA->id);
    AvailabilityRule::factory()->create([
        'provider_id' => $providerA->id,
        'business_id' => $business->id,
        'day_of_week' => DayOfWeek::Monday->value,
        'start_time' => '09:00',
        'end_time' => '17:00',
    ]);

    // Service B — active, provider attached, ZERO availability rules. Structurally unbookable.
    $serviceB = Service::factory()->create([
        'business_id' => $business->id,
        'name' => 'Massage',
        'is_active' => true,
    ]);
    $staff = User::factory()->create();
    $providerB = attachProvider($business, $staff);
    $providerB->services()->attach($serviceB->id);
    // Note: no AvailabilityRule for $providerB.

    // Service C — active, NO providers attached. Structurally unbookable.
    $serviceC = Service::factory()->create([
        'business_id' => $business->id,
        'name' => 'Pedicure',
        'is_active' => true,
    ]);

    // 1. Launch gate agrees: {B, C} are unbookable, A is not.
    $launch = $this->actingAs($admin)->post('/onboarding/step/5');
    $launch->assertRedirect(route('onboarding.show', ['step' => 3]));
    $launchBlocked = session('launchBlocked');
    $blockedIds = collect($launchBlocked['services'])->pluck('id')->sort()->values()->all();
    $expectedUnbookable = collect([$serviceB->id, $serviceC->id])->sort()->values()->all();
    expect($blockedIds)->toBe($expectedUnbookable);

    // Force the business onboarded so the public page resolves and the dashboard banner fires.
    $business->update(['onboarding_completed_at' => now()]);

    // 2. Public page agrees: only A is listed.
    $public = $this->get('/'.$business->slug);
    $public->assertInertia(fn ($page) => $page
        ->component('booking/show')
        ->has('services', 1)
        ->where('services.0.id', $serviceA->id)
    );

    // 3. Dashboard banner agrees: {B, C} are flagged.
    $dashboard = $this->actingAs($admin)->get('/dashboard');
    $dashboard->assertInertia(fn ($page) => $page
        ->has('bookability.unbookableServices', 2)
        ->where('bookability.unbookableServices', function ($list) use ($expectedUnbookable) {
            $ids = collect($list)->pluck('id')->sort()->values()->all();
            expect($ids)->toBe($expectedUnbookable);

            return true;
        })
    );
});
