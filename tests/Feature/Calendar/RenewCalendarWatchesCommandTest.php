<?php

use App\Models\Business;
use App\Models\CalendarIntegration;
use App\Models\CalendarWatch;
use App\Models\User;
use App\Services\Calendar\GoogleCalendarProvider;
use Tests\Support\Calendar\FakeCalendarProvider;

beforeEach(function () {
    FakeCalendarProvider::reset();
    $this->app->bind(GoogleCalendarProvider::class, FakeCalendarProvider::class);

    $this->business = Business::factory()->onboarded()->create();
    $this->user = User::factory()->create(['email_verified_at' => now()]);
    attachAdmin($this->business, $this->user);

    $this->integration = CalendarIntegration::factory()
        ->configured($this->business->id)
        ->create(['user_id' => $this->user->id]);
});

it('refreshes watches expiring within 24 hours', function () {
    $watch = CalendarWatch::factory()->create([
        'integration_id' => $this->integration->id,
        'calendar_id' => 'primary',
        'channel_id' => 'old-channel',
        'resource_id' => 'old-resource',
        'expires_at' => now()->addHours(12),
    ]);

    $this->artisan('calendar:renew-watches')->assertSuccessful();

    $watch->refresh();
    expect($watch->channel_id)->toBe('channel-primary');
    expect($watch->resource_id)->toBe('resource-primary');
    expect(FakeCalendarProvider::$stoppedWatches)->toHaveCount(1);
    expect(FakeCalendarProvider::$stoppedWatches[0]['channelId'])->toBe('old-channel');
});

it('leaves watches with distant expiries untouched', function () {
    $watch = CalendarWatch::factory()->create([
        'integration_id' => $this->integration->id,
        'calendar_id' => 'primary',
        'channel_id' => 'stable-channel',
        'resource_id' => 'stable-resource',
        'expires_at' => now()->addDays(5),
    ]);

    $this->artisan('calendar:renew-watches')->assertSuccessful();

    $watch->refresh();
    expect($watch->channel_id)->toBe('stable-channel');
    expect(FakeCalendarProvider::$startedWatches)->toBeEmpty();
});

it('swallows stopWatch failures and still starts the new channel', function () {
    $watch = CalendarWatch::factory()->create([
        'integration_id' => $this->integration->id,
        'calendar_id' => 'primary',
        'channel_id' => 'dying',
        'resource_id' => 'dying',
        'expires_at' => now()->addHours(1),
    ]);

    // Replace stopWatch with a throwing version for this case.
    $this->app->bind(GoogleCalendarProvider::class, function () {
        return new class extends FakeCalendarProvider
        {
            public function stopWatch($integration, string $cid, string $rid): void
            {
                throw new RuntimeException('already dead');
            }
        };
    });

    $this->artisan('calendar:renew-watches')->assertSuccessful();

    $watch->refresh();
    expect($watch->channel_id)->toBe('channel-primary');
});
