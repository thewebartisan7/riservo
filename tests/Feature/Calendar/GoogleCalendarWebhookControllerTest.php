<?php

use App\Jobs\Calendar\PullCalendarEventsJob;
use App\Models\Business;
use App\Models\CalendarIntegration;
use App\Models\CalendarWatch;
use App\Models\User;
use Illuminate\Support\Facades\Queue;

beforeEach(function () {
    Queue::fake();

    $this->business = Business::factory()->onboarded()->create();
    $this->user = User::factory()->create(['email_verified_at' => now()]);
    attachAdmin($this->business, $this->user);

    $this->integration = CalendarIntegration::factory()->configured($this->business->id)->create([
        'user_id' => $this->user->id,
    ]);

    $this->watch = CalendarWatch::factory()->create([
        'integration_id' => $this->integration->id,
        'calendar_id' => 'primary',
        'channel_id' => 'chan-xyz',
        'channel_token' => 'secret-abc',
    ]);
});

it('dispatches PullCalendarEventsJob on a valid webhook', function () {
    $response = $this->post('/webhooks/google-calendar', [], [
        'X-Goog-Channel-Id' => 'chan-xyz',
        'X-Goog-Channel-Token' => 'secret-abc',
    ]);

    $response->assertOk();
    Queue::assertPushed(PullCalendarEventsJob::class, function ($job) {
        return $job->integrationId === $this->integration->id && $job->calendarId === 'primary';
    });
});

it('returns 400 when X-Goog-Channel-Id is missing', function () {
    $this->post('/webhooks/google-calendar', [], [])
        ->assertStatus(400);

    Queue::assertNothingPushed();
});

it('returns 404 when the channel id is unknown', function () {
    $this->post('/webhooks/google-calendar', [], [
        'X-Goog-Channel-Id' => 'unknown-channel',
        'X-Goog-Channel-Token' => 'whatever',
    ])->assertStatus(404);

    Queue::assertNothingPushed();
});

it('returns 400 when the channel token is wrong', function () {
    $this->post('/webhooks/google-calendar', [], [
        'X-Goog-Channel-Id' => 'chan-xyz',
        'X-Goog-Channel-Token' => 'wrong-token',
    ])->assertStatus(400);

    Queue::assertNothingPushed();
});

it('is exempt from CSRF protection', function () {
    // A POST without a session CSRF token would normally 419. Webhook route
    // is listed in the validateCsrfTokens(except: [...]) list.
    $response = $this->post('/webhooks/google-calendar', [], [
        'X-Goog-Channel-Id' => 'chan-xyz',
        'X-Goog-Channel-Token' => 'secret-abc',
    ]);

    // Not 419
    expect($response->status())->not->toBe(419);
});
