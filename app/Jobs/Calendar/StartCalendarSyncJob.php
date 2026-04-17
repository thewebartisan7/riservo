<?php

namespace App\Jobs\Calendar;

use App\Models\CalendarIntegration;
use App\Models\CalendarWatch;
use App\Services\Calendar\CalendarProviderFactory;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Throwable;

/**
 * Ignite sync for a newly configured integration.
 *
 * Iterates the distinct set of {destination_calendar_id} ∪ conflict_calendar_ids,
 * calls startWatch for each (persisting a calendar_watches row), and dispatches
 * PullCalendarEventsJob for each so the forward-only first-sync runs.
 */
class StartCalendarSyncJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public function __construct(
        public readonly int $integrationId,
    ) {
        $this->afterCommit();
    }

    public function handle(CalendarProviderFactory $factory): void
    {
        $integration = CalendarIntegration::find($this->integrationId);

        if ($integration === null || ! $integration->isConfigured()) {
            return;
        }

        $provider = $factory->for($integration);

        $desiredCalendarIds = collect([
            $integration->destination_calendar_id,
            ...(array) ($integration->conflict_calendar_ids ?? []),
        ])->filter()->unique()->values()->all();

        // Round 2 review: reconcile the existing watch set to the desired set.
        // A reconfigure that removes a calendar MUST stop its channel in
        // Google and delete the row — otherwise the old webhook keeps firing
        // and imports external bookings from calendars the user unchecked.
        $existingWatches = $integration->watches()->get()->keyBy('calendar_id');
        $staleCalendarIds = $existingWatches->keys()->diff($desiredCalendarIds);

        foreach ($staleCalendarIds as $calendarId) {
            $watch = $existingWatches[$calendarId];
            try {
                $provider->stopWatch($integration, $watch->channel_id, $watch->resource_id);
            } catch (Throwable $e) {
                // A dead/expired channel must not block cleanup of our row.
                report($e);
            }
            $watch->delete();
        }

        foreach ($desiredCalendarIds as $calendarId) {
            if ($existingWatches->has($calendarId)) {
                continue; // already watching
            }

            try {
                $result = $provider->startWatch($integration, $calendarId);

                CalendarWatch::create([
                    'integration_id' => $integration->id,
                    'calendar_id' => $calendarId,
                    'channel_id' => $result->channelId,
                    'resource_id' => $result->resourceId,
                    'channel_token' => $result->channelToken,
                    'expires_at' => $result->expiresAt,
                ]);
            } catch (Throwable $e) {
                report($e);

                continue;
            }

            PullCalendarEventsJob::dispatch($integration->id, $calendarId);
        }
    }
}
