<?php

use App\Enums\BookingStatus;
use App\Enums\PendingActionStatus;
use App\Jobs\Calendar\PullCalendarEventsJob;
use App\Models\Booking;
use App\Models\Business;
use App\Models\CalendarIntegration;
use App\Models\Customer;
use App\Models\PendingAction;
use App\Models\Provider;
use App\Models\Service;
use App\Models\User;
use App\Notifications\BookingCancelledNotification;
use App\Services\Calendar\GoogleCalendarProvider;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;
use Tests\Support\Calendar\FakeCalendarProvider;

beforeEach(function () {
    Notification::fake();
    FakeCalendarProvider::reset();
    $this->app->bind(GoogleCalendarProvider::class, FakeCalendarProvider::class);

    $this->business = Business::factory()->onboarded()->create();
    $this->admin = User::factory()->create(['email_verified_at' => now()]);
    attachAdmin($this->business, $this->admin);

    $this->integrationOwner = User::factory()->create(['email_verified_at' => now()]);
    attachStaff($this->business, $this->integrationOwner);

    $this->provider = Provider::factory()->create([
        'business_id' => $this->business->id,
        'user_id' => $this->integrationOwner->id,
    ]);

    $this->integration = CalendarIntegration::factory()
        ->configured($this->business->id)
        ->create(['user_id' => $this->integrationOwner->id]);

    $this->service = Service::factory()->create(['business_id' => $this->business->id]);
    $this->customer = Customer::factory()->create();
});

it('cancel_and_notify cancels the riservo booking and notifies the customer', function () {
    $booking = Booking::factory()->create([
        'business_id' => $this->business->id,
        'provider_id' => $this->provider->id,
        'service_id' => $this->service->id,
        'customer_id' => $this->customer->id,
        'status' => BookingStatus::Confirmed,
    ]);

    $action = PendingAction::factory()->riservoDeleted()->create([
        'business_id' => $this->business->id,
        'integration_id' => $this->integration->id,
        'booking_id' => $booking->id,
    ]);

    $this->actingAs($this->admin)
        ->post(route('dashboard.calendar-pending-actions.resolve', $action), [
            'choice' => 'cancel_and_notify',
        ])->assertRedirect();

    expect($booking->fresh()->status)->toBe(BookingStatus::Cancelled);
    expect($action->fresh()->status)->toBe(PendingActionStatus::Resolved);

    Notification::assertSentOnDemand(BookingCancelledNotification::class);
});

it('keep_and_dismiss leaves the booking untouched and marks the action dismissed', function () {
    $booking = Booking::factory()->create([
        'business_id' => $this->business->id,
        'provider_id' => $this->provider->id,
        'service_id' => $this->service->id,
        'customer_id' => $this->customer->id,
        'status' => BookingStatus::Confirmed,
    ]);

    $action = PendingAction::factory()->riservoDeleted()->create([
        'business_id' => $this->business->id,
        'integration_id' => $this->integration->id,
        'booking_id' => $booking->id,
    ]);

    $this->actingAs($this->admin)
        ->post(route('dashboard.calendar-pending-actions.resolve', $action), [
            'choice' => 'keep_and_dismiss',
        ])->assertRedirect();

    expect($booking->fresh()->status)->toBe(BookingStatus::Confirmed);
    expect($action->fresh()->status)->toBe(PendingActionStatus::Dismissed);
    Notification::assertNothingSent();
});

it('keep_riservo_ignore_external dismisses without mutation', function () {
    $action = PendingAction::factory()->conflict()->create([
        'business_id' => $this->business->id,
        'integration_id' => $this->integration->id,
        'payload' => ['external_event_id' => 'evt-x', 'external_calendar_id' => 'primary'],
    ]);

    $this->actingAs($this->admin)
        ->post(route('dashboard.calendar-pending-actions.resolve', $action), [
            'choice' => 'keep_riservo_ignore_external',
        ])->assertRedirect();

    expect($action->fresh()->status)->toBe(PendingActionStatus::Dismissed);
    Notification::assertNothingSent();
});

it('cancel_external calls provider deleteEvent', function () {
    $action = PendingAction::factory()->conflict()->create([
        'business_id' => $this->business->id,
        'integration_id' => $this->integration->id,
        'payload' => ['external_event_id' => 'evt-del', 'external_calendar_id' => 'primary'],
    ]);

    $this->actingAs($this->admin)
        ->post(route('dashboard.calendar-pending-actions.resolve', $action), [
            'choice' => 'cancel_external',
        ])->assertRedirect();

    expect(FakeCalendarProvider::$deletedEvents)->toHaveCount(1);
    expect(FakeCalendarProvider::$deletedEvents[0]['externalEventId'])->toBe('evt-del');
    expect($action->fresh()->status)->toBe(PendingActionStatus::Resolved);
});

it('cancel_external keeps the action pending when Google delete fails', function () {
    // Round 2 regression: a provider failure on deleteEvent must NOT mark the
    // action resolved. Otherwise staff cannot retry and the external event
    // keeps blocking availability.
    $this->app->bind(GoogleCalendarProvider::class, function () {
        return new class extends FakeCalendarProvider
        {
            public function deleteEvent($integration, string $cal, string $evt): void
            {
                throw new RuntimeException('Google is down');
            }
        };
    });

    $action = PendingAction::factory()->conflict()->create([
        'business_id' => $this->business->id,
        'integration_id' => $this->integration->id,
        'payload' => ['external_event_id' => 'evt-flaky', 'external_calendar_id' => 'primary'],
    ]);

    $response = $this->actingAs($this->admin)
        ->from('/dashboard')
        ->post(route('dashboard.calendar-pending-actions.resolve', $action), [
            'choice' => 'cancel_external',
        ]);

    $response->assertRedirect();
    $response->assertSessionHas('error');

    // Action must remain pending so staff can retry.
    expect($action->fresh()->status)->toBe(PendingActionStatus::Pending);
    expect($action->fresh()->resolved_at)->toBeNull();
});

it('cancel_riservo_booking cancels booking, notifies customer, and re-dispatches PullCalendarEventsJob', function () {
    Queue::fake();

    $booking = Booking::factory()->create([
        'business_id' => $this->business->id,
        'provider_id' => $this->provider->id,
        'service_id' => $this->service->id,
        'customer_id' => $this->customer->id,
        'status' => BookingStatus::Confirmed,
    ]);

    $action = PendingAction::factory()->conflict()->create([
        'business_id' => $this->business->id,
        'integration_id' => $this->integration->id,
        'booking_id' => $booking->id,
        'payload' => [
            'external_event_id' => 'evt-keep',
            'external_calendar_id' => 'primary',
        ],
    ]);

    $this->actingAs($this->admin)
        ->post(route('dashboard.calendar-pending-actions.resolve', $action), [
            'choice' => 'cancel_riservo_booking',
        ])->assertRedirect();

    expect($booking->fresh()->status)->toBe(BookingStatus::Cancelled);
    expect($action->fresh()->status)->toBe(PendingActionStatus::Resolved);

    Notification::assertSentOnDemand(BookingCancelledNotification::class);
    Queue::assertPushed(PullCalendarEventsJob::class, function ($job) {
        return $job->integrationId === $this->integration->id && $job->calendarId === 'primary';
    });
});

it('staff who does not own the integration gets 403', function () {
    $otherStaff = User::factory()->create(['email_verified_at' => now()]);
    attachStaff($this->business, $otherStaff);

    $action = PendingAction::factory()->conflict()->create([
        'business_id' => $this->business->id,
        'integration_id' => $this->integration->id,
    ]);

    $this->actingAs($otherStaff)
        ->post(route('dashboard.calendar-pending-actions.resolve', $action), [
            'choice' => 'keep_riservo_ignore_external',
        ])->assertForbidden();
});

it('staff who owns the integration can resolve their own action', function () {
    $action = PendingAction::factory()->conflict()->create([
        'business_id' => $this->business->id,
        'integration_id' => $this->integration->id,
    ]);

    $this->actingAs($this->integrationOwner)
        ->post(route('dashboard.calendar-pending-actions.resolve', $action), [
            'choice' => 'keep_riservo_ignore_external',
        ])->assertRedirect();

    expect($action->fresh()->status)->toBe(PendingActionStatus::Dismissed);
});

it('admin can resolve any action in the business', function () {
    $action = PendingAction::factory()->conflict()->create([
        'business_id' => $this->business->id,
        'integration_id' => $this->integration->id,
    ]);

    $this->actingAs($this->admin)
        ->post(route('dashboard.calendar-pending-actions.resolve', $action), [
            'choice' => 'keep_riservo_ignore_external',
        ])->assertRedirect();
});

it('a user from another business cannot resolve (returns 404)', function () {
    $otherBusiness = Business::factory()->onboarded()->create();
    $otherAdmin = User::factory()->create(['email_verified_at' => now()]);
    attachAdmin($otherBusiness, $otherAdmin);

    $action = PendingAction::factory()->conflict()->create([
        'business_id' => $this->business->id,
        'integration_id' => $this->integration->id,
    ]);

    $this->actingAs($otherAdmin)
        ->post(route('dashboard.calendar-pending-actions.resolve', $action), [
            'choice' => 'keep_riservo_ignore_external',
        ])->assertNotFound();
});
