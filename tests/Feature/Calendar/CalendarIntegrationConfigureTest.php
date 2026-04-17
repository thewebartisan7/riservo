<?php

use App\Jobs\Calendar\PullCalendarEventsJob;
use App\Jobs\Calendar\StartCalendarSyncJob;
use App\Models\Business;
use App\Models\CalendarIntegration;
use App\Models\CalendarWatch;
use App\Models\PendingAction;
use App\Models\User;
use App\Services\Calendar\GoogleCalendarProvider;
use Illuminate\Support\Facades\Queue;
use Laravel\Socialite\Facades\Socialite;
use Tests\Support\Calendar\FakeCalendarProvider;

beforeEach(function () {
    FakeCalendarProvider::reset();
    $this->app->bind(GoogleCalendarProvider::class, FakeCalendarProvider::class);

    $this->business = Business::factory()->onboarded()->create();
    $this->admin = User::factory()->create(['email_verified_at' => now()]);
    attachAdmin($this->business, $this->admin);
});

it('redirects post-callback to the configure page', function () {
    Socialite::fake('google', (function () {
        $u = new Laravel\Socialite\Two\User;
        $u->map(['id' => 'x', 'name' => 'x', 'email' => 'x@example.com', 'avatar' => null]);
        $u->token = 'tok';
        $u->refreshToken = 'refresh';
        $u->expiresIn = 3600;

        return $u;
    })());

    $this->actingAs($this->admin)
        ->get(route('settings.calendar-integration.callback'))
        ->assertRedirect(route('settings.calendar-integration.configure'));
});

it('renders the configure page with listCalendars results', function () {
    CalendarIntegration::factory()->create([
        'user_id' => $this->admin->id,
    ]);

    $response = $this->actingAs($this->admin)
        ->get(route('settings.calendar-integration.configure'));

    $response->assertOk();
    $response->assertInertia(fn ($page) => $page
        ->component('dashboard/settings/calendar-integration-configure')
        ->where('calendars.0.id', 'primary')
        ->where('calendars.0.primary', true)
    );
});

it('saves configuration, dispatches StartCalendarSyncJob, and redirects to the settings index', function () {
    Queue::fake();

    $integration = CalendarIntegration::factory()->create([
        'user_id' => $this->admin->id,
    ]);

    $response = $this->actingAs($this->admin)->post(
        route('settings.calendar-integration.save-configuration'),
        [
            'destination_calendar_id' => 'primary',
            'conflict_calendar_ids' => ['primary'],
        ],
    );

    $response->assertRedirect(route('settings.calendar-integration'));
    $integration->refresh();
    expect($integration->destination_calendar_id)->toBe('primary');
    expect($integration->conflict_calendar_ids)->toBe(['primary']);
    expect($integration->business_id)->toBe($this->business->id);

    Queue::assertPushed(StartCalendarSyncJob::class, function ($job) use ($integration) {
        return $job->integrationId === $integration->id;
    });
});

it('creates a new calendar when requested', function () {
    Queue::fake();

    CalendarIntegration::factory()->create([
        'user_id' => $this->admin->id,
    ]);

    $this->actingAs($this->admin)->post(
        route('settings.calendar-integration.save-configuration'),
        [
            'destination_calendar_id' => null,
            'conflict_calendar_ids' => [],
            'create_new_calendar_name' => 'Riservo bookings',
        ],
    )->assertRedirect(route('settings.calendar-integration'));

    $integration = $this->admin->fresh()->calendarIntegration;
    expect($integration->destination_calendar_id)->toBe('new-cal-Riservo bookings');
});

it('dispatches PullCalendarEventsJob for each watched calendar on sync-now', function () {
    Queue::fake();

    $integration = CalendarIntegration::factory()
        ->configured($this->business->id)
        ->create(['user_id' => $this->admin->id]);

    CalendarWatch::factory()->create([
        'integration_id' => $integration->id,
        'calendar_id' => 'cal-a',
    ]);
    CalendarWatch::factory()->create([
        'integration_id' => $integration->id,
        'calendar_id' => 'cal-b',
    ]);

    $this->actingAs($this->admin)->post(route('settings.calendar-integration.sync-now'));

    Queue::assertPushed(PullCalendarEventsJob::class, 2);
});

it('resets integration-scoped state when repinning to a different business', function () {
    // Round 2 review: saveConfiguration must tear down watches, pending actions,
    // and timing state when `business_id` changes. Otherwise old watches keep
    // delivering webhooks under the new business, and old pending actions
    // orphan out of view for both tenants.
    Queue::fake();

    $businessA = $this->business;
    $businessB = Business::factory()->onboarded()->create();
    attachAdmin($businessB, $this->admin);

    // Integration pinned to A with active watches + a pending action + sync state.
    $integration = CalendarIntegration::factory()
        ->configured($businessA->id)
        ->create([
            'user_id' => $this->admin->id,
            'last_synced_at' => now(),
            'last_pushed_at' => now(),
            'sync_error' => 'previous failure',
            'sync_error_at' => now(),
        ]);

    CalendarWatch::factory()->create([
        'integration_id' => $integration->id,
        'calendar_id' => 'primary',
        'channel_id' => 'old-chan-A',
        'resource_id' => 'old-rs-A',
        'sync_token' => 'token-from-A',
    ]);
    PendingAction::factory()->conflict()->create([
        'business_id' => $businessA->id,
        'integration_id' => $integration->id,
    ]);

    // Switch tenant to B, then Save settings. The controller resolves the
    // active tenant from the session.
    $this->actingAs($this->admin)
        ->withSession(['current_business_id' => $businessB->id])
        ->post(route('settings.calendar-integration.save-configuration'), [
            'destination_calendar_id' => 'primary',
            'conflict_calendar_ids' => ['primary'],
        ])->assertRedirect(route('settings.calendar-integration'));

    // Old channel was stopped in Google, row deleted, pending action deleted.
    expect(FakeCalendarProvider::$stoppedWatches)->toHaveCount(1);
    expect(FakeCalendarProvider::$stoppedWatches[0]['channelId'])->toBe('old-chan-A');
    expect(CalendarWatch::where('integration_id', $integration->id)->count())->toBe(0);
    expect(PendingAction::where('integration_id', $integration->id)->count())->toBe(0);

    // Timing + error state cleared; integration repinned to B.
    $integration->refresh();
    expect($integration->business_id)->toBe($businessB->id);
    expect($integration->last_synced_at)->toBeNull();
    expect($integration->last_pushed_at)->toBeNull();
    expect($integration->sync_error)->toBeNull();

    // StartCalendarSyncJob dispatched — it will recreate watches under B.
    Queue::assertPushed(StartCalendarSyncJob::class);
});

it('does NOT tear down state when saving without changing business_id', function () {
    // Same-business "Change settings" (e.g., adding a conflict calendar) must
    // preserve existing watches so sync tokens survive.
    Queue::fake();

    $integration = CalendarIntegration::factory()
        ->configured($this->business->id)
        ->create(['user_id' => $this->admin->id]);

    $watch = CalendarWatch::factory()->create([
        'integration_id' => $integration->id,
        'calendar_id' => 'primary',
        'sync_token' => 'valuable-token',
    ]);
    $pendingAction = PendingAction::factory()->conflict()->create([
        'business_id' => $this->business->id,
        'integration_id' => $integration->id,
    ]);

    $this->actingAs($this->admin)->post(route('settings.calendar-integration.save-configuration'), [
        'destination_calendar_id' => 'primary',
        'conflict_calendar_ids' => ['primary', 'work@example.com'],
    ])->assertRedirect(route('settings.calendar-integration'));

    // Existing watches + sync tokens + pending actions preserved.
    expect(FakeCalendarProvider::$stoppedWatches)->toBeEmpty();
    expect($watch->fresh()->sync_token)->toBe('valuable-token');
    expect(PendingAction::where('id', $pendingAction->id)->exists())->toBeTrue();
});

it('disconnect calls stopWatch for each watch and deletes integration rows', function () {
    $integration = CalendarIntegration::factory()
        ->configured($this->business->id)
        ->create(['user_id' => $this->admin->id]);

    CalendarWatch::factory()->create([
        'integration_id' => $integration->id,
        'channel_id' => 'chan-1',
        'resource_id' => 'rs-1',
    ]);
    CalendarWatch::factory()->create([
        'integration_id' => $integration->id,
        'channel_id' => 'chan-2',
        'resource_id' => 'rs-2',
    ]);

    $this->actingAs($this->admin)
        ->delete(route('settings.calendar-integration.disconnect'))
        ->assertRedirect(route('settings.calendar-integration'));

    expect(FakeCalendarProvider::$stoppedWatches)->toHaveCount(2);
    expect(CalendarIntegration::where('id', $integration->id)->exists())->toBeFalse();
});
