<?php

use App\Jobs\Calendar\StartCalendarSyncJob;
use App\Models\Business;
use App\Models\CalendarIntegration;
use App\Models\CalendarWatch;
use App\Models\User;
use App\Services\Calendar\CalendarProviderFactory;
use App\Services\Calendar\GoogleCalendarProvider;
use Tests\Support\Calendar\FakeCalendarProvider;

beforeEach(function () {
    FakeCalendarProvider::reset();
    $this->app->bind(GoogleCalendarProvider::class, FakeCalendarProvider::class);

    $this->business = Business::factory()->onboarded()->create();
    $this->user = User::factory()->create(['email_verified_at' => now()]);
    attachAdmin($this->business, $this->user);
});

it('creates watches for each distinct calendar in the configured set', function () {
    $integration = CalendarIntegration::factory()->create([
        'user_id' => $this->user->id,
        'business_id' => $this->business->id,
        'destination_calendar_id' => 'primary',
        'conflict_calendar_ids' => ['primary', 'work@example.com'],
    ]);

    (new StartCalendarSyncJob($integration->id))
        ->handle(app(CalendarProviderFactory::class));

    // primary is deduped across destination + conflicts.
    expect(CalendarWatch::where('integration_id', $integration->id)->count())->toBe(2);
    expect(FakeCalendarProvider::$startedWatches)->toEqualCanonicalizing(['primary', 'work@example.com']);
});

it('tears down watches for calendars removed from the configuration', function () {
    // Round 2 regression: a reconfigure that unchecks a calendar must stop
    // its channel in Google and delete the watch row. Without this, the old
    // channel keeps delivering webhooks.
    $integration = CalendarIntegration::factory()->create([
        'user_id' => $this->user->id,
        'business_id' => $this->business->id,
        'destination_calendar_id' => 'primary',
        'conflict_calendar_ids' => ['primary'],
    ]);

    // Pre-existing watches from a previous configuration — "primary" stays,
    // "work@example.com" and "old-cal" are no longer in the desired set.
    CalendarWatch::factory()->create([
        'integration_id' => $integration->id,
        'calendar_id' => 'primary',
        'channel_id' => 'primary-chan',
        'resource_id' => 'primary-rs',
    ]);
    CalendarWatch::factory()->create([
        'integration_id' => $integration->id,
        'calendar_id' => 'work@example.com',
        'channel_id' => 'work-chan',
        'resource_id' => 'work-rs',
    ]);
    CalendarWatch::factory()->create([
        'integration_id' => $integration->id,
        'calendar_id' => 'old-cal',
        'channel_id' => 'old-chan',
        'resource_id' => 'old-rs',
    ]);

    (new StartCalendarSyncJob($integration->id))
        ->handle(app(CalendarProviderFactory::class));

    // primary stays (already watched, no new startWatch needed).
    // work@example.com + old-cal get stopped and deleted.
    $remainingIds = CalendarWatch::where('integration_id', $integration->id)
        ->pluck('calendar_id')->all();
    expect($remainingIds)->toEqual(['primary']);

    expect(FakeCalendarProvider::$stoppedWatches)->toHaveCount(2);
    $stoppedChannelIds = collect(FakeCalendarProvider::$stoppedWatches)
        ->pluck('channelId')->all();
    expect($stoppedChannelIds)->toEqualCanonicalizing(['work-chan', 'old-chan']);

    // primary already had a watch; no new startWatch fires.
    expect(FakeCalendarProvider::$startedWatches)->toBeEmpty();
});

it('still deletes stale watch rows even when stopWatch fails', function () {
    // A dead Google channel must not block local cleanup.
    $this->app->bind(GoogleCalendarProvider::class, function () {
        return new class extends FakeCalendarProvider
        {
            public function stopWatch($integration, string $cid, string $rid): void
            {
                throw new RuntimeException('channel long gone');
            }
        };
    });

    $integration = CalendarIntegration::factory()->create([
        'user_id' => $this->user->id,
        'business_id' => $this->business->id,
        'destination_calendar_id' => 'primary',
        'conflict_calendar_ids' => [],
    ]);

    CalendarWatch::factory()->create([
        'integration_id' => $integration->id,
        'calendar_id' => 'removed-cal',
    ]);

    (new StartCalendarSyncJob($integration->id))
        ->handle(app(CalendarProviderFactory::class));

    expect(CalendarWatch::where('calendar_id', 'removed-cal')->exists())->toBeFalse();
});
