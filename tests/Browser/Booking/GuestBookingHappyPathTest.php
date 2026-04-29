<?php

declare(strict_types=1);

use App\Enums\BookingSource;
use App\Enums\BookingStatus;
use App\Enums\ConfirmationMode;
use App\Models\Booking;
use App\Models\Customer;
use Carbon\CarbonImmutable;
use Tests\Browser\Support\BookingFlowHelper;
use Tests\Browser\Support\BusinessSetup;

// Covers: POST /booking/{slug}/book — booking.store — guest happy path.

beforeEach(function () {
    // Pick the next real Monday; the helper navigates to its month because travelTo()
    // freezes PHP but not the browser-rendered calendar month.
    $this->targetDate = CarbonImmutable::now('Europe/Zurich')->next(Carbon\Carbon::MONDAY);
    // Freeze PHP to 07:30 on that Monday so the server-side availability computation
    // sees it as 'today' in the business timezone.
    $this->travelTo($this->targetDate->setTime(7, 30));
});

it('books a guest appointment end-to-end with a specific provider', function () {
    ['business' => $business, 'service' => $service, 'providers' => $providers]
        = BusinessSetup::createBusinessWithProviders(2);

    $firstProvider = $providers->first();
    // Rename the user so we can assert the provider name in the flow.
    $firstProvider->user->update(['name' => 'Alice Provider']);

    $page = visit('/'.$business->slug);

    $page->assertSee($service->name)
        ->click($service->name)
        ->assertSee('Who would you like to see?')
        ->click('Alice Provider');

    $page->assertSee('When works for you?');

    BookingFlowHelper::selectDateAndTime($page, $this->targetDate);

    $page->assertSee('Just a few details')
        ->type('name', 'Jane Customer')
        ->type('email', 'jane@example.com')
        ->type('phone', '+41 79 123 45 67');

    // The Continue button wraps its text inside <Display> which renders a <span>.
    // The press() helper does a text match against the button; fallback to an explicit click-by-text script.
    $page->script("Array.from(document.querySelectorAll('button[type=\"submit\"]')).find(b => (b.textContent || '').includes('Continue'))?.click();");

    $page->assertSee('Everything in order?')
        ->assertSee($service->name)
        ->assertSee('Alice Provider');

    // Same story for the Confirm button.
    $page->script("Array.from(document.querySelectorAll('button')).find(b => (b.textContent || '').includes('Confirm booking'))?.click();");

    // Confirmation screen.
    $page->assertSee('Confirmed')
        ->assertSee($service->name)
        ->assertSee('Alice Provider')
        ->assertSee($business->name)
        ->assertNoJavaScriptErrors();

    // Database invariants.
    $booking = Booking::first();
    expect($booking)->not->toBeNull()
        ->and($booking->source)->toBe(BookingSource::Riservo)
        ->and($booking->status)->toBe(BookingStatus::Confirmed)
        ->and($booking->provider_id)->toBe($firstProvider->id)
        ->and($booking->service_id)->toBe($service->id)
        ->and($booking->cancellation_token)->not->toBeNull();

    $customer = Customer::where('email', 'jane@example.com')->first();
    expect($customer)->not->toBeNull()
        ->and($customer->name)->toBe('Jane Customer')
        ->and($customer->phone)->toBe('+41 79 123 45 67');
});

it('creates a pending booking when confirmation_mode is manual', function () {
    ['business' => $business, 'service' => $service] = BusinessSetup::createLaunchedBusiness([
        'confirmation_mode' => ConfirmationMode::Manual,
    ]);

    $token = BookingFlowHelper::bookAsGuest($page = visit('/'), $business, $service, [
        'email' => 'pending-test@example.com',
    ]);

    $page->assertSee('Received')
        ->assertSee($business->name)
        ->assertNoJavaScriptErrors();

    $booking = Booking::where('cancellation_token', $token)->firstOrFail();
    expect($booking->status)->toBe(BookingStatus::Pending)
        ->and($booking->source)->toBe(BookingSource::Riservo);
});

it('reuses an existing Customer row when the email already exists', function () {
    ['business' => $business, 'service' => $service] = BusinessSetup::createLaunchedBusiness();

    // Seed a Customer with the email we will use during booking.
    $existing = Customer::factory()->create([
        'email' => 'repeat@example.com',
        'name' => 'Old Name',
        'phone' => '+00 000 000',
    ]);

    $token = BookingFlowHelper::bookAsGuest(visit('/'), $business, $service, [
        'name' => 'New Name',
        'email' => 'repeat@example.com',
        'phone' => '+41 79 000 00 00',
    ]);

    // Still only a single Customer row for that email.
    expect(Customer::where('email', 'repeat@example.com')->count())->toBe(1);

    $existing->refresh();
    expect($existing->name)->toBe('New Name')
        ->and($existing->phone)->toBe('+41 79 000 00 00');

    expect(Booking::where('cancellation_token', $token)->exists())->toBeTrue();
});
