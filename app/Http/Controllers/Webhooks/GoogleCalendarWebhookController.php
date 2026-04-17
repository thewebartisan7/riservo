<?php

namespace App\Http\Controllers\Webhooks;

use App\Http\Controllers\Controller;
use App\Jobs\Calendar\PullCalendarEventsJob;
use App\Models\CalendarWatch;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * Receives Google Calendar push notifications (D-086).
 *
 * Google's push fires a POST with headers `X-Goog-Channel-Id`, `X-Goog-Channel-Token`,
 * `X-Goog-Resource-Id`, `X-Goog-Resource-State`. The body is empty. We authenticate the
 * channel, dispatch the pull job asynchronously, and return 200 immediately (Google
 * retries on non-2xx — keeping the hot path fast matters).
 *
 * Security posture: unknown channel id → 404 (no information leak about which channels
 * exist); wrong channel token → 400; missing channel id → 400. Token comparison uses
 * `hash_equals` (constant-time).
 */
class GoogleCalendarWebhookController extends Controller
{
    public function store(Request $request): Response
    {
        $channelId = $request->header('X-Goog-Channel-Id');
        $channelToken = $request->header('X-Goog-Channel-Token', '');

        if (! $channelId) {
            return response('Missing channel id', 400);
        }

        $watch = CalendarWatch::where('channel_id', $channelId)->first();

        if ($watch === null) {
            return response('Unknown channel', 404);
        }

        if (! hash_equals((string) $watch->channel_token, (string) $channelToken)) {
            return response('Invalid channel token', 400);
        }

        PullCalendarEventsJob::dispatch($watch->integration_id, $watch->calendar_id);

        return response('', 200);
    }
}
