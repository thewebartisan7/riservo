<?php

declare(strict_types=1);

use App\Enums\BookingSource;
use App\Enums\BookingStatus;
use App\Models\Booking;
use App\Models\Customer;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Notification;
use Tests\Browser\Support\AuthHelper;
use Tests\Browser\Support\BusinessSetup;

// Covers: POST /dashboard/bookings (manual booking) — D-051 semantics
// (source=manual, status=confirmed, no pending state) and the slot-unavailable
// error surface (D-065/D-066 backstop) (E2E-4).

beforeEach(function () {
    Notification::fake();

    // Monday 2026-04-13 09:00 Zurich — inside the default Mon–Fri 09:00–18:00
    // business hours the BusinessSetup helper seeds.
    $this->travelTo(CarbonImmutable::parse('2026-04-13 08:00', 'Europe/Zurich'));
});

it('creates a manual booking for a new customer via the HTTP endpoint', function () {
    ['business' => $business, 'admin' => $admin, 'provider' => $provider, 'service' => $service]
        = BusinessSetup::createLaunchedBusiness();

    // Drive the backend directly (the in-dialog multi-step picker is exercised
    // separately below). This verifies the D-051 semantics.
    $this->actingAs($admin)
        ->post('/dashboard/bookings', [
            'customer_name' => 'Fresh Fiona',
            'customer_email' => 'fresh.fiona@example.com',
            'customer_phone' => '+41 79 222 00 00',
            'service_id' => $service->id,
            'provider_id' => $provider->id,
            'date' => '2026-04-13',
            'time' => '10:00',
            'notes' => 'Walk-in',
        ])
        ->assertRedirect('/dashboard/bookings');

    $booking = Booking::first();
    expect($booking)->not->toBeNull()
        ->and($booking->status)->toBe(BookingStatus::Confirmed)
        ->and($booking->source)->toBe(BookingSource::Manual)
        ->and($booking->notes)->toBe('Walk-in');

    $customer = Customer::where('email', 'fresh.fiona@example.com')->first();
    expect($customer)->not->toBeNull()
        ->and($customer->name)->toBe('Fresh Fiona');

    // The new booking is then visible in the list.
    visit('/dashboard/bookings')
        ->assertSee('Fresh Fiona')
        ->assertSee('Manual')
        ->assertNoJavaScriptErrors();
});

it('reuses an existing customer when the email matches the directory', function () {
    ['business' => $business, 'admin' => $admin, 'provider' => $provider, 'service' => $service]
        = BusinessSetup::createLaunchedBusiness();

    $existing = Customer::factory()->create([
        'email' => 'existing@example.com',
        'name' => 'Old Name',
    ]);

    $this->actingAs($admin)
        ->post('/dashboard/bookings', [
            'customer_name' => 'New Name',
            'customer_email' => 'existing@example.com',
            'service_id' => $service->id,
            'provider_id' => $provider->id,
            'date' => '2026-04-13',
            'time' => '10:00',
        ])
        ->assertRedirect('/dashboard/bookings');

    // The Customer row count must remain the same; the existing row is updated in place.
    expect(Customer::where('email', 'existing@example.com')->count())->toBe(1)
        ->and($existing->fresh()->name)->toBe('New Name');
});

it('shows a slot-unavailable error when manually booking an occupied slot', function () {
    ['business' => $business, 'admin' => $admin, 'provider' => $provider, 'service' => $service]
        = BusinessSetup::createLaunchedBusiness();

    // Pre-seed a confirmed booking that blocks the 10:00 slot.
    Booking::factory()->confirmed()->create([
        'business_id' => $business->id,
        'provider_id' => $provider->id,
        'service_id' => $service->id,
        'customer_id' => Customer::factory()->create()->id,
        'starts_at' => CarbonImmutable::parse('2026-04-13 10:00', 'Europe/Zurich')->utc(),
        'ends_at' => CarbonImmutable::parse('2026-04-13 11:00', 'Europe/Zurich')->utc(),
    ]);

    $response = $this->actingAs($admin)
        ->post('/dashboard/bookings', [
            'customer_name' => 'Clashing Clara',
            'customer_email' => 'clara@example.com',
            'service_id' => $service->id,
            'provider_id' => $provider->id,
            'date' => '2026-04-13',
            'time' => '10:00',
        ]);

    $response->assertRedirect();
    $response->assertSessionHas('error');

    // Only the seeded blocking booking exists — no second one was created.
    expect(Booking::count())->toBe(1);
});

it('opens the manual-booking dialog from the bookings page', function () {
    ['admin' => $admin] = BusinessSetup::createLaunchedBusiness();

    $page = AuthHelper::loginAs(visit('/'), $admin);

    $page->navigate('/dashboard/bookings')
        ->click('New booking')
        ->assertSee('Find a customer')
        ->assertSee('Create a new customer')
        ->assertNoJavaScriptErrors();
});
