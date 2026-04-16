<?php

declare(strict_types=1);

use App\Enums\BookingStatus;
use App\Models\Booking;
use App\Models\Customer;
use App\Models\User;
use Carbon\CarbonImmutable;
use Tests\Browser\Support\BusinessSetup;

// Covers: GET /my-bookings — customer.bookings — customer/bookings.tsx
// and POST /my-bookings/{booking}/cancel — customer.bookings.cancel.

beforeEach(function () {
    $next = CarbonImmutable::now('Europe/Zurich');
    if ($next->dayOfWeekIso !== 1) {
        $next = $next->next(Carbon\Carbon::MONDAY);
    }
    $this->targetMonday = $next;
    $this->travelTo($next->setTime(7, 30));
});

/**
 * Log in a given customer-role user directly via the /login form.
 */
function loginCustomer(mixed $page, User $user): mixed
{
    return $page->type('email', $user->email)
        ->type('password', 'password')
        ->click('button[type="submit"]');
}

it('renders upcoming and past sections with the expected bookings', function () {
    ['business' => $business, 'service' => $service, 'provider' => $provider]
        = BusinessSetup::createLaunchedBusiness();

    $user = User::factory()->create();
    $customer = Customer::factory()->create([
        'user_id' => $user->id,
        'email' => $user->email,
        'name' => 'Reggie',
    ]);

    // One upcoming confirmed booking (future), one past completed booking.
    $future = $this->targetMonday->addWeek()->setTime(10, 0)->setTimezone('UTC');
    $past = $this->targetMonday->subWeek()->setTime(10, 0)->setTimezone('UTC');

    Booking::factory()->confirmed()->create([
        'business_id' => $business->id,
        'provider_id' => $provider->id,
        'service_id' => $service->id,
        'customer_id' => $customer->id,
        'starts_at' => $future,
        'ends_at' => $future->addHour(),
    ]);
    Booking::factory()->create([
        'business_id' => $business->id,
        'provider_id' => $provider->id,
        'service_id' => $service->id,
        'customer_id' => $customer->id,
        'starts_at' => $past,
        'ends_at' => $past->addHour(),
        'status' => BookingStatus::Completed,
    ]);

    $page = loginCustomer(visit('/login'), $user);

    $page->assertPathIs('/my-bookings')
        ->assertSee('Upcoming')
        ->assertSee('Past')
        ->assertSee($business->name)
        ->assertSee($service->name)
        ->assertNoJavaScriptErrors();
});

it('cancels an upcoming booking from the list when within the cancellation window', function () {
    // window=1h so a booking 4.5 hours away can be cancelled.
    ['business' => $business, 'service' => $service, 'provider' => $provider]
        = BusinessSetup::createLaunchedBusiness(['cancellation_window_hours' => 1]);

    $user = User::factory()->create();
    $customer = Customer::factory()->create([
        'user_id' => $user->id,
        'email' => $user->email,
    ]);

    // Booking on the same Monday at 14:00 local — 6.5 hours from the frozen 07:30.
    $startsAt = $this->targetMonday->setTime(14, 0)->setTimezone('UTC');
    $booking = Booking::factory()->confirmed()->create([
        'business_id' => $business->id,
        'provider_id' => $provider->id,
        'service_id' => $service->id,
        'customer_id' => $customer->id,
        'starts_at' => $startsAt,
        'ends_at' => $startsAt->addHour(),
    ]);

    $page = loginCustomer(visit('/login'), $user);

    $page->assertPathIs('/my-bookings')
        ->assertSee($service->name)
        ->press('Cancel');

    expect($booking->fresh()->status)->toBe(BookingStatus::Cancelled);
});

it('hides the Cancel button for bookings outside the cancellation window', function () {
    ['business' => $business, 'service' => $service, 'provider' => $provider]
        = BusinessSetup::createLaunchedBusiness(['cancellation_window_hours' => 24]);

    $user = User::factory()->create();
    $customer = Customer::factory()->create([
        'user_id' => $user->id,
        'email' => $user->email,
    ]);

    // Appointment 2.5 hours from the frozen 07:30 Monday — inside the 24h window, so Cancel is not allowed.
    $startsAt = $this->targetMonday->setTime(10, 0)->setTimezone('UTC');
    Booking::factory()->confirmed()->create([
        'business_id' => $business->id,
        'provider_id' => $provider->id,
        'service_id' => $service->id,
        'customer_id' => $customer->id,
        'starts_at' => $startsAt,
        'ends_at' => $startsAt->addHour(),
    ]);

    $page = loginCustomer(visit('/login'), $user);

    $page->assertPathIs('/my-bookings')
        ->assertSee($service->name)
        ->assertDontSee('Cancel');
});

it('renders empty states when the customer has no upcoming or past bookings', function () {
    BusinessSetup::createLaunchedBusiness();

    $user = User::factory()->create();
    Customer::factory()->create([
        'user_id' => $user->id,
        'email' => $user->email,
    ]);

    $page = loginCustomer(visit('/login'), $user);

    $page->assertPathIs('/my-bookings')
        ->assertSee('No upcoming bookings')
        ->assertSee('No past bookings')
        ->assertNoJavaScriptErrors();
});

it('shows (deactivated) suffix for soft-deleted providers on the bookings list', function () {
    ['business' => $business, 'service' => $service, 'provider' => $provider]
        = BusinessSetup::createLaunchedBusiness();

    $provider->user->update(['name' => 'Former Staff']);

    $user = User::factory()->create();
    $customer = Customer::factory()->create([
        'user_id' => $user->id,
        'email' => $user->email,
    ]);

    $future = $this->targetMonday->addWeek()->setTime(10, 0)->setTimezone('UTC');
    Booking::factory()->confirmed()->create([
        'business_id' => $business->id,
        'provider_id' => $provider->id,
        'service_id' => $service->id,
        'customer_id' => $customer->id,
        'starts_at' => $future,
        'ends_at' => $future->addHour(),
    ]);

    // Soft-delete provider after booking exists (D-067).
    $provider->delete();

    $page = loginCustomer(visit('/login'), $user);

    $page->assertPathIs('/my-bookings')
        ->assertSee('Former Staff')
        ->assertSee('(deactivated)')
        ->assertNoJavaScriptErrors();
});
