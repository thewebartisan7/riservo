<?php

use App\Enums\BookingSource;
use App\Enums\BookingStatus;
use App\Enums\PendingActionStatus;
use App\Enums\PendingActionType;
use App\Jobs\Calendar\PullCalendarEventsJob;
use App\Models\Booking;
use App\Models\Business;
use App\Models\CalendarIntegration;
use App\Models\CalendarWatch;
use App\Models\Customer;
use App\Models\PendingAction;
use App\Models\Provider;
use App\Models\Service;
use App\Models\User;
use App\Services\Calendar\CalendarProviderFactory;
use App\Services\Calendar\DTOs\ExternalEvent;
use App\Services\Calendar\DTOs\SyncResult;
use App\Services\Calendar\Exceptions\SyncTokenExpiredException;
use App\Services\Calendar\GoogleCalendarProvider;
use Carbon\CarbonImmutable;
use Tests\Support\Calendar\FakeCalendarProvider;

function ev(array $overrides = []): ExternalEvent
{
    return new ExternalEvent(
        id: $overrides['id'] ?? 'evt-1',
        calendarId: $overrides['calendarId'] ?? 'primary',
        status: $overrides['status'] ?? 'confirmed',
        summary: $overrides['summary'] ?? 'Team sync',
        description: $overrides['description'] ?? null,
        start: $overrides['start'] ?? CarbonImmutable::now()->addHour(),
        end: $overrides['end'] ?? CarbonImmutable::now()->addHours(2),
        attendeeEmails: $overrides['attendeeEmails'] ?? [],
        htmlLink: $overrides['htmlLink'] ?? 'https://calendar.google.com/event',
        extendedProperties: $overrides['extendedProperties'] ?? [],
        creatorEmail: $overrides['creatorEmail'] ?? null,
    );
}

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

    $this->integration = CalendarIntegration::factory()->configured($this->business->id)->create([
        'user_id' => $this->owner->id,
    ]);

    // Sync tokens are per-watch (round 2 fix).
    $this->watch = CalendarWatch::factory()->create([
        'integration_id' => $this->integration->id,
        'calendar_id' => 'primary',
    ]);
});

it('imports a foreign event as an external booking with null customer and service', function () {
    FakeCalendarProvider::$syncBatches = [
        new SyncResult([ev(['id' => 'new-evt'])], 'fresh-token'),
    ];

    (new PullCalendarEventsJob($this->integration->id, 'primary'))
        ->handle(app(CalendarProviderFactory::class));

    $booking = Booking::where('external_calendar_id', 'new-evt')->first();
    expect($booking)->not->toBeNull();
    expect($booking->source)->toBe(BookingSource::GoogleCalendar);
    expect($booking->customer_id)->toBeNull();
    expect($booking->service_id)->toBeNull();
    expect($booking->external_title)->toBe('Team sync');
    expect($booking->external_html_link)->toBe('https://calendar.google.com/event');
    expect($this->watch->fresh()->sync_token)->toBe('fresh-token');
});

it('links the first matching customer by attendee email scoped to the business', function () {
    $matching = Customer::factory()->create(['email' => 'client@example.com']);
    Booking::factory()->create([
        'business_id' => $this->business->id,
        'provider_id' => $this->provider->id,
        'customer_id' => $matching->id,
        'service_id' => Service::factory()->create(['business_id' => $this->business->id])->id,
    ]);

    FakeCalendarProvider::$syncBatches = [
        new SyncResult([ev([
            'id' => 'evt-attendee',
            'attendeeEmails' => ['unmatched@example.com', 'client@example.com'],
            'start' => CarbonImmutable::now()->addDays(2),
            'end' => CarbonImmutable::now()->addDays(2)->addHour(),
        ])], 'tok'),
    ];

    (new PullCalendarEventsJob($this->integration->id, 'primary'))
        ->handle(app(CalendarProviderFactory::class));

    $booking = Booking::where('external_calendar_id', 'evt-attendee')->first();
    expect($booking->customer_id)->toBe($matching->id);
});

it('creates an external_booking_conflict when the event overlaps a confirmed riservo booking', function () {
    $service = Service::factory()->create(['business_id' => $this->business->id]);
    $customer = Customer::factory()->create();

    $existing = Booking::factory()->create([
        'business_id' => $this->business->id,
        'provider_id' => $this->provider->id,
        'service_id' => $service->id,
        'customer_id' => $customer->id,
        'starts_at' => CarbonImmutable::now()->addHour(),
        'ends_at' => CarbonImmutable::now()->addHours(2),
        'status' => BookingStatus::Confirmed,
    ]);

    FakeCalendarProvider::$syncBatches = [
        new SyncResult([ev([
            'id' => 'conflict-evt',
            'start' => $existing->starts_at->toImmutable(),
            'end' => $existing->ends_at->toImmutable(),
        ])], 'tok'),
    ];

    (new PullCalendarEventsJob($this->integration->id, 'primary'))
        ->handle(app(CalendarProviderFactory::class));

    // No external booking materialises.
    expect(Booking::where('external_calendar_id', 'conflict-evt')->exists())->toBeFalse();

    $action = PendingAction::where('type', PendingActionType::ExternalBookingConflict->value)->first();
    expect($action)->not->toBeNull();
    expect($action->status)->toBe(PendingActionStatus::Pending);
    expect($action->payload['external_event_id'])->toBe('conflict-evt');
    expect($action->payload['conflict_booking_ids'])->toContain($existing->id);
    expect($action->booking_id)->toBe($existing->id);
});

it('creates a riservo_event_deleted_in_google pending action when a pushed event is deleted', function () {
    $service = Service::factory()->create(['business_id' => $this->business->id]);
    $customer = Customer::factory()->create();
    $booking = Booking::factory()->create([
        'business_id' => $this->business->id,
        'provider_id' => $this->provider->id,
        'service_id' => $service->id,
        'customer_id' => $customer->id,
        'status' => BookingStatus::Confirmed,
        'external_calendar_id' => 'gcal-xyz',
    ]);

    FakeCalendarProvider::$syncBatches = [
        new SyncResult([ev([
            'id' => 'gcal-xyz',
            'status' => 'cancelled',
            'extendedProperties' => [
                'riservo_booking_id' => (string) $booking->id,
                'riservo_business_id' => (string) $this->business->id,
            ],
        ])], 'tok'),
    ];

    (new PullCalendarEventsJob($this->integration->id, 'primary'))
        ->handle(app(CalendarProviderFactory::class));

    expect($booking->fresh()->status)->toBe(BookingStatus::Confirmed);

    $action = PendingAction::where('type', PendingActionType::RiservoEventDeletedInGoogle->value)->first();
    expect($action)->not->toBeNull();
    expect($action->booking_id)->toBe($booking->id);
});

it('cancels an external booking when the Google event is cancelled after prior import', function () {
    $existing = Booking::factory()->external()->create([
        'business_id' => $this->business->id,
        'provider_id' => $this->provider->id,
        'external_calendar_id' => 'evt-now-cancelled',
        'status' => BookingStatus::Confirmed,
    ]);

    FakeCalendarProvider::$syncBatches = [
        new SyncResult([ev([
            'id' => 'evt-now-cancelled',
            'status' => 'cancelled',
        ])], 'tok'),
    ];

    (new PullCalendarEventsJob($this->integration->id, 'primary'))
        ->handle(app(CalendarProviderFactory::class));

    expect($existing->fresh()->status)->toBe(BookingStatus::Cancelled);
});

it('clears the sync token and retries forward-only on 410', function () {
    // Round 2: sync tokens live on calendar_watches, not calendar_integrations.
    $this->watch->update(['sync_token' => 'stale-token']);

    FakeCalendarProvider::$syncBatches = [
        new SyncTokenExpiredException('expired'),
        new SyncResult([ev(['id' => 'fresh-evt'])], 'new-sync-token'),
    ];

    (new PullCalendarEventsJob($this->integration->id, 'primary'))
        ->handle(app(CalendarProviderFactory::class));

    expect($this->watch->fresh()->sync_token)->toBe('new-sync-token');
    expect(Booking::where('external_calendar_id', 'fresh-evt')->exists())->toBeTrue();
});

it('writes each calendar\'s nextSyncToken to its own watch row', function () {
    // Round 2 regression: sync tokens are per-watch, not per-integration.
    // Two calendars with different tokens must not clobber each other.
    $secondWatch = CalendarWatch::factory()->create([
        'integration_id' => $this->integration->id,
        'calendar_id' => 'work@example.com',
    ]);

    FakeCalendarProvider::$syncBatches = [
        new SyncResult([], 'token-primary'),
    ];
    (new PullCalendarEventsJob($this->integration->id, 'primary'))
        ->handle(app(CalendarProviderFactory::class));

    FakeCalendarProvider::$syncBatches = [
        new SyncResult([], 'token-work'),
    ];
    (new PullCalendarEventsJob($this->integration->id, 'work@example.com'))
        ->handle(app(CalendarProviderFactory::class));

    expect($this->watch->fresh()->sync_token)->toBe('token-primary');
    expect($secondWatch->fresh()->sync_token)->toBe('token-work');
});

it('records sync_error on failed()', function () {
    $job = new PullCalendarEventsJob($this->integration->id, 'primary');
    $job->failed(new RuntimeException('Google is down'));

    expect($this->integration->fresh()->sync_error)->toContain('Google is down');
    expect($this->integration->fresh()->sync_error_at)->not->toBeNull();
});
