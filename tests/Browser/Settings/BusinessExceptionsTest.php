<?php

declare(strict_types=1);

use App\Enums\ExceptionType;
use App\Models\AvailabilityException;
use Tests\Browser\Support\BusinessSetup;

// Covers: settings.exceptions, settings.exceptions.store, settings.exceptions.update,
// settings.exceptions.destroy (E2E-5).
//
// HTTP-only tests precede browser tests to avoid RefreshDatabase flakiness
// in the Pest Browser suite — see BookingSettingsTest.php for details.

it('creates a full-day closure exception via the store endpoint', function () {
    ['business' => $business, 'admin' => $admin] = BusinessSetup::createLaunchedBusiness();

    $this->actingAs($admin)
        ->post('/dashboard/settings/exceptions', [
            'start_date' => now()->addDays(5)->format('Y-m-d'),
            'end_date' => now()->addDays(5)->format('Y-m-d'),
            'type' => 'block',
            'reason' => 'Public holiday',
        ])
        ->assertRedirect('/dashboard/settings/exceptions');

    expect(AvailabilityException::where('business_id', $business->id)
        ->whereNull('provider_id')
        ->where('reason', 'Public holiday')
        ->exists())->toBeTrue();
});

it('creates a partial-day block exception (10:00 – 11:00)', function () {
    ['business' => $business, 'admin' => $admin] = BusinessSetup::createLaunchedBusiness();

    $this->actingAs($admin)
        ->post('/dashboard/settings/exceptions', [
            'start_date' => now()->addDays(3)->format('Y-m-d'),
            'end_date' => now()->addDays(3)->format('Y-m-d'),
            'start_time' => '10:00',
            'end_time' => '11:00',
            'type' => 'block',
            'reason' => 'Staff training',
        ])
        ->assertRedirect('/dashboard/settings/exceptions');

    $exception = AvailabilityException::where('business_id', $business->id)
        ->where('reason', 'Staff training')
        ->first();

    expect($exception)->not->toBeNull()
        ->and($exception->start_time)->toContain('10:00')
        ->and($exception->end_time)->toContain('11:00')
        ->and($exception->type)->toBe(ExceptionType::Block);
});

it('creates an open exception that adds extra availability', function () {
    ['business' => $business, 'admin' => $admin] = BusinessSetup::createLaunchedBusiness();

    $this->actingAs($admin)
        ->post('/dashboard/settings/exceptions', [
            'start_date' => now()->addDays(7)->format('Y-m-d'),
            'end_date' => now()->addDays(7)->format('Y-m-d'),
            'start_time' => '19:00',
            'end_time' => '21:00',
            'type' => 'open',
            'reason' => 'Late night event',
        ])
        ->assertRedirect('/dashboard/settings/exceptions');

    $exception = AvailabilityException::where('business_id', $business->id)
        ->where('reason', 'Late night event')
        ->first();

    expect($exception->type)->toBe(ExceptionType::Open);
});

it('edits an exception and persists the updated values', function () {
    ['business' => $business, 'admin' => $admin] = BusinessSetup::createLaunchedBusiness();

    $exception = AvailabilityException::factory()
        ->for($business)
        ->create([
            'provider_id' => null,
            'reason' => 'Original reason',
        ]);

    $this->actingAs($admin)
        ->put("/dashboard/settings/exceptions/{$exception->id}", [
            'start_date' => $exception->start_date->format('Y-m-d'),
            'end_date' => $exception->end_date->format('Y-m-d'),
            'type' => 'block',
            'reason' => 'Updated reason',
        ])
        ->assertRedirect('/dashboard/settings/exceptions');

    expect($exception->fresh()->reason)->toBe('Updated reason');
});

it('deletes an exception via the destroy endpoint', function () {
    ['business' => $business, 'admin' => $admin] = BusinessSetup::createLaunchedBusiness();

    $exception = AvailabilityException::factory()
        ->for($business)
        ->create(['provider_id' => null]);

    $this->actingAs($admin)
        ->delete("/dashboard/settings/exceptions/{$exception->id}")
        ->assertRedirect('/dashboard/settings/exceptions');

    expect(AvailabilityException::find($exception->id))->toBeNull();
});

it('denies staff members with a 403', function () {
    ['staff' => $staffCollection] = BusinessSetup::createBusinessWithStaff(
        1,
        ['onboarding_step' => 5, 'onboarding_completed_at' => now()],
    );
    $staff = $staffCollection->first();

    $this->actingAs($staff)->get('/dashboard/settings/exceptions')->assertForbidden();
});

it('renders an empty business exceptions list with the empty-state copy', function () {
    ['admin' => $admin] = BusinessSetup::createLaunchedBusiness();

    $this->actingAs($admin);

    $page = visit('/dashboard/settings/exceptions');
    $page->assertPathIs('/dashboard/settings/exceptions')
        ->assertSee('Business exceptions')
        ->assertSee('No exceptions yet.')
        ->assertNoJavaScriptErrors();
});

it('lists existing business-level exceptions (not provider-level ones)', function () {
    ['business' => $business, 'admin' => $admin] = BusinessSetup::createLaunchedBusiness();

    AvailabilityException::factory()
        ->for($business)
        ->create([
            'provider_id' => null,
            'reason' => 'Holiday closure',
        ]);

    $this->actingAs($admin);

    $page = visit('/dashboard/settings/exceptions');
    $page->assertSee('Holiday closure')
        ->assertSee('Closed')
        ->assertNoJavaScriptErrors();
});
