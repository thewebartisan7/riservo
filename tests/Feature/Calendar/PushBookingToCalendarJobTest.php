<?php

use App\Enums\BookingSource;
use App\Jobs\Calendar\PushBookingToCalendarJob;
use App\Models\Booking;
use App\Models\Business;
use App\Models\CalendarIntegration;
use App\Models\Customer;
use App\Models\Provider;
use App\Models\Service;
use App\Models\User;
use App\Services\Calendar\CalendarProviderFactory;
use App\Services\Calendar\GoogleCalendarProvider;
use Tests\Support\Calendar\FakeCalendarProvider;

beforeEach(function () {
    FakeCalendarProvider::reset();

    $this->app->bind(GoogleCalendarProvider::class, FakeCalendarProvider::class);

    $this->business = Business::factory()->onboarded()->create();
    $this->owner = User::factory()->create(['email_verified_at' => now()]);
    attachAdmin($this->business, $this->owner);

    $this->provider = Provider::factory()->create([
        'business_id' => $this->business->id,
        'user_id' => $this->owner->id,
    ]);

    $this->service = Service::factory()->create(['business_id' => $this->business->id]);
    $this->customer = Customer::factory()->create();

    $this->integration = CalendarIntegration::factory()->configured($this->business->id)->create([
        'user_id' => $this->owner->id,
    ]);
});

it('pushes a create and stores the returned external_calendar_id', function () {
    FakeCalendarProvider::$nextPushId = 'gcal-evt-abc';

    $booking = Booking::factory()->create([
        'business_id' => $this->business->id,
        'provider_id' => $this->provider->id,
        'service_id' => $this->service->id,
        'customer_id' => $this->customer->id,
        'source' => BookingSource::Riservo,
    ]);

    (new PushBookingToCalendarJob($booking->id, 'create'))->handle(app(CalendarProviderFactory::class));

    expect(FakeCalendarProvider::$pushedBookings)->toContain($booking->id);
    expect($booking->fresh()->external_calendar_id)->toBe('gcal-evt-abc');
    expect($this->integration->fresh()->last_pushed_at)->not->toBeNull();
});

it('pushes a delete when the booking carries an external_calendar_id', function () {
    $booking = Booking::factory()->create([
        'business_id' => $this->business->id,
        'provider_id' => $this->provider->id,
        'service_id' => $this->service->id,
        'customer_id' => $this->customer->id,
        'external_calendar_id' => 'existing-gcal-id',
    ]);

    (new PushBookingToCalendarJob($booking->id, 'delete'))->handle(app(CalendarProviderFactory::class));

    expect(FakeCalendarProvider::$deletedEvents)->toHaveCount(1);
    expect(FakeCalendarProvider::$deletedEvents[0]['externalEventId'])->toBe('existing-gcal-id');
});

it('persists the destination calendar on create and uses it on delete after reconfigure', function () {
    // Round 2 regression: a reconfigure moves destination_calendar_id; existing
    // events must still be deleted / updated in the calendar they were pushed to.
    FakeCalendarProvider::$nextPushId = 'evt-pushed-to-primary';

    $booking = Booking::factory()->create([
        'business_id' => $this->business->id,
        'provider_id' => $this->provider->id,
        'service_id' => $this->service->id,
        'customer_id' => $this->customer->id,
    ]);

    (new PushBookingToCalendarJob($booking->id, 'create'))
        ->handle(app(CalendarProviderFactory::class));

    expect($booking->fresh()->external_event_calendar_id)->toBe('primary');

    // Reconfigure — admin moves destination to a different calendar.
    $this->integration->update(['destination_calendar_id' => 'work@example.com']);

    (new PushBookingToCalendarJob($booking->id, 'delete'))
        ->handle(app(CalendarProviderFactory::class));

    // Delete must target the ORIGINAL calendar, not the new destination.
    expect(FakeCalendarProvider::$deletedEvents)->toHaveCount(1);
    expect(FakeCalendarProvider::$deletedEvents[0]['externalCalendarId'])->toBe('primary');
    expect(FakeCalendarProvider::$deletedEvents[0]['externalEventId'])->toBe('evt-pushed-to-primary');
});

it('skips when the booking is sourced from Google Calendar', function () {
    $booking = Booking::factory()->external()->create([
        'business_id' => $this->business->id,
        'provider_id' => $this->provider->id,
    ]);

    expect($booking->shouldPushToCalendar())->toBeFalse();
});

it('skips when the provider has no configured integration', function () {
    $this->integration->update(['destination_calendar_id' => null]);

    $booking = Booking::factory()->create([
        'business_id' => $this->business->id,
        'provider_id' => $this->provider->id,
        'service_id' => $this->service->id,
        'customer_id' => $this->customer->id,
    ]);

    expect($booking->shouldPushToCalendar())->toBeFalse();
});

it('records push_error on failed() when all retries are exhausted', function () {
    $booking = Booking::factory()->create([
        'business_id' => $this->business->id,
        'provider_id' => $this->provider->id,
        'service_id' => $this->service->id,
        'customer_id' => $this->customer->id,
    ]);

    $job = new PushBookingToCalendarJob($booking->id, 'create');
    $job->failed(new RuntimeException('Google is down'));

    expect($this->integration->fresh()->push_error)->toContain('Google is down');
    expect($this->integration->fresh()->push_error_at)->not->toBeNull();
});
