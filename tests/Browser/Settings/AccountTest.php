<?php

declare(strict_types=1);

use App\Enums\DayOfWeek;
use App\Models\AvailabilityException;
use App\Models\AvailabilityRule;
use App\Models\Booking;
use App\Models\Business;
use App\Models\Customer;
use App\Models\Provider;
use App\Models\Service;
use App\Models\User;
use Tests\Browser\Support\BusinessSetup;

// Covers: settings.account, settings.account.toggle-provider, settings.account.update-schedule,
// settings.account.store-exception, settings.account.update-exception, settings.account.destroy-exception,
// settings.account.update-services (E2E-5, D-062).
//
// HTTP-only tests precede browser tests to avoid RefreshDatabase flakiness
// in the Pest Browser suite — see BookingSettingsTest.php for details.

it('toggles admin on as provider via toggle-provider and attaches to all active services (D-062)', function () {
    $business = Business::factory()->onboarded()->create();
    $admin = User::factory()->create();
    attachAdmin($business, $admin);
    $service = Service::factory()->for($business)->create(['is_active' => true]);

    $this->actingAs($admin)
        ->post('/dashboard/settings/account/toggle-provider')
        ->assertRedirect('/dashboard/settings/account');

    $provider = Provider::where('business_id', $business->id)
        ->where('user_id', $admin->id)
        ->first();

    expect($provider)->not->toBeNull()
        ->and($provider->trashed())->toBeFalse()
        ->and($provider->services()->where('services.id', $service->id)->exists())->toBeTrue();
});

it('updates own weekly schedule via update-schedule', function () {
    ['admin' => $admin, 'provider' => $provider] = BusinessSetup::createLaunchedBusiness();

    $rules = collect(range(1, 7))->map(function (int $day) {
        if ($day === 2) {
            return [
                'day_of_week' => 2,
                'enabled' => true,
                'windows' => [['open_time' => '11:00', 'close_time' => '15:00']],
            ];
        }

        return ['day_of_week' => $day, 'enabled' => false, 'windows' => []];
    })->all();

    $this->actingAs($admin)
        ->put('/dashboard/settings/account/schedule', ['rules' => $rules])
        ->assertRedirect('/dashboard/settings/account');

    $ruleRows = AvailabilityRule::where('provider_id', $provider->id)->get();
    expect($ruleRows)->toHaveCount(1)
        ->and($ruleRows->first()->day_of_week)->toBe(DayOfWeek::Tuesday);
});

it('adds an own exception via store-exception', function () {
    ['business' => $business, 'admin' => $admin, 'provider' => $provider] = BusinessSetup::createLaunchedBusiness();

    $this->actingAs($admin)
        ->post('/dashboard/settings/account/exceptions', [
            'start_date' => now()->addDays(2)->format('Y-m-d'),
            'end_date' => now()->addDays(2)->format('Y-m-d'),
            'type' => 'block',
            'reason' => 'Personal day',
        ])
        ->assertRedirect('/dashboard/settings/account');

    expect(AvailabilityException::where('business_id', $business->id)
        ->where('provider_id', $provider->id)
        ->where('reason', 'Personal day')
        ->exists())->toBeTrue();
});

it('updates an own exception', function () {
    ['business' => $business, 'admin' => $admin, 'provider' => $provider] = BusinessSetup::createLaunchedBusiness();

    $exception = AvailabilityException::factory()
        ->for($business)
        ->create([
            'provider_id' => $provider->id,
            'reason' => 'Initial',
        ]);

    $this->actingAs($admin)
        ->put("/dashboard/settings/account/exceptions/{$exception->id}", [
            'start_date' => $exception->start_date->format('Y-m-d'),
            'end_date' => $exception->end_date->format('Y-m-d'),
            'type' => 'block',
            'reason' => 'Edited',
        ])
        ->assertRedirect('/dashboard/settings/account');

    expect($exception->fresh()->reason)->toBe('Edited');
});

it('deletes an own exception', function () {
    ['business' => $business, 'admin' => $admin, 'provider' => $provider] = BusinessSetup::createLaunchedBusiness();

    $exception = AvailabilityException::factory()
        ->for($business)
        ->create(['provider_id' => $provider->id]);

    $this->actingAs($admin)
        ->delete("/dashboard/settings/account/exceptions/{$exception->id}")
        ->assertRedirect('/dashboard/settings/account');

    expect(AvailabilityException::find($exception->id))->toBeNull();
});

it('syncs own services via update-services', function () {
    ['admin' => $admin, 'provider' => $provider, 'service' => $service] = BusinessSetup::createLaunchedBusiness();

    // Already attached during setup; first detach, then sync back via the endpoint.
    $provider->services()->detach();
    expect($provider->services()->count())->toBe(0);

    $this->actingAs($admin)
        ->put('/dashboard/settings/account/services', [
            'service_ids' => [$service->id],
        ])
        ->assertRedirect('/dashboard/settings/account');

    expect($provider->services()->count())->toBe(1);
});

it('toggles provider off and soft-deletes the Provider row (historical bookings remain, D-067)', function () {
    ['business' => $business, 'admin' => $admin, 'provider' => $provider, 'service' => $service] = BusinessSetup::createLaunchedBusiness();

    // Create a historical booking so we can confirm it survives the deactivation.
    $customer = Customer::factory()->create();
    $booking = Booking::factory()->create([
        'business_id' => $business->id,
        'provider_id' => $provider->id,
        'service_id' => $service->id,
        'customer_id' => $customer->id,
    ]);

    $this->actingAs($admin)
        ->post('/dashboard/settings/account/toggle-provider')
        ->assertRedirect('/dashboard/settings/account');

    expect($provider->fresh()->trashed())->toBeTrue()
        ->and(Booking::find($booking->id))->not->toBeNull();
});

it('denies staff members with a 403 on the account page', function () {
    ['staff' => $staffCollection] = BusinessSetup::createBusinessWithStaff(
        1,
        ['onboarding_step' => 5, 'onboarding_completed_at' => now()],
    );
    $staff = $staffCollection->first();

    $this->actingAs($staff)->get('/dashboard/settings/account')->assertForbidden();
});

it('renders the account settings page for an admin', function () {
    ['admin' => $admin] = BusinessSetup::createLaunchedBusiness();

    $this->actingAs($admin);

    $page = visit('/dashboard/settings/account');
    $page->assertPathIs('/dashboard/settings/account')
        ->assertSee('Your account')
        ->assertSee($admin->name)
        ->assertSee($admin->email)
        ->assertNoJavaScriptErrors();
});

it('admin who has not opted in sees the "Bookable provider" toggle (off) with no schedule', function () {
    // createLaunchedBusiness attaches the admin as a provider; build a bare admin case instead.
    $business = Business::factory()->onboarded()->create();
    $admin = User::factory()->create();
    attachAdmin($business, $admin);

    $this->actingAs($admin);

    $page = visit('/dashboard/settings/account');
    $page->assertSee('I take bookings myself')
        ->assertNoJavaScriptErrors();
});
