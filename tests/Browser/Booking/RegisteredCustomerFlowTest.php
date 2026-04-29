<?php

declare(strict_types=1);

use App\Models\Booking;
use App\Models\Customer;
use App\Models\User;
use Carbon\CarbonImmutable;
use Tests\Browser\Support\BookingFlowHelper;
use Tests\Browser\Support\BusinessSetup;

// Covers: D-074 — registered-customer booking flow with customerPrefill + Customer linked to User.

beforeEach(function () {
    // Pick a full target date because travelTo() does not move the browser's calendar month.
    $next = CarbonImmutable::now('Europe/Zurich');
    if ($next->dayOfWeekIso !== 1) {
        $next = $next->next(Carbon\Carbon::MONDAY);
    }
    $this->targetDate = $next;
    $this->travelTo($next->setTime(7, 30));
});

it('pre-fills the customer form for a logged-in customer user', function () {
    ['business' => $business, 'service' => $service] = BusinessSetup::createLaunchedBusiness();

    $user = User::factory()->create(['email' => 'registered@example.com']);
    Customer::factory()->create([
        'user_id' => $user->id,
        'name' => 'Registered Reggie',
        'email' => 'registered@example.com',
        'phone' => '+41 79 111 22 33',
    ]);

    $page = visit('/login');
    $page->type('email', $user->email)
        ->type('password', 'password')
        ->click('button[type="submit"]');

    $page->navigate('/'.$business->slug)
        ->click($service->name);
    if ($business->allow_provider_choice) {
        $page->click('Any specialist');
    }

    BookingFlowHelper::selectDateAndTime($page, $this->targetDate);

    // Form is pre-filled from customerPrefill — assert the values directly.
    $page->assertSee('Just a few details')
        ->assertValue('name', 'Registered Reggie')
        ->assertValue('email', 'registered@example.com')
        ->assertValue('phone', '+41 79 111 22 33')
        ->assertNoJavaScriptErrors();
});

it('creates a booking linked to the existing Customer when a registered user books', function () {
    ['business' => $business, 'service' => $service] = BusinessSetup::createLaunchedBusiness();

    $user = User::factory()->create(['email' => 'registered2@example.com']);
    $customer = Customer::factory()->create([
        'user_id' => $user->id,
        'name' => 'Reggie Two',
        'email' => 'registered2@example.com',
        'phone' => '+41 79 222 33 44',
    ]);

    $page = visit('/login');
    $page->type('email', $user->email)
        ->type('password', 'password')
        ->click('button[type="submit"]');

    $token = BookingFlowHelper::bookAsRegistered($page, $business, $service, $customer);

    $booking = Booking::where('cancellation_token', $token)->firstOrFail();
    expect($booking->customer_id)->toBe($customer->id);

    // Only one Customer for this email — no duplicate Customer row.
    expect(Customer::where('email', 'registered2@example.com')->count())->toBe(1);

    // The existing Customer remains linked to the user.
    expect($customer->fresh()->user_id)->toBe($user->id);
});
