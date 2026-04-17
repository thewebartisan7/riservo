<?php

namespace Tests\Support\Calendar;

use App\Services\Calendar\CalendarProvider;
use App\Services\Calendar\DTOs\CalendarSummary;
use App\Services\Calendar\DTOs\SyncResult;
use App\Services\Calendar\DTOs\WatchResult;
use Carbon\CarbonImmutable;
use Throwable;

/**
 * Programmable CalendarProvider double shared across Calendar feature tests.
 *
 * Each beforeEach() must call `FakeCalendarProvider::reset()` to zero the
 * static state, then bind this class in place of `GoogleCalendarProvider`.
 */
class FakeCalendarProvider implements CalendarProvider
{
    /** @var array<int, int> */
    public static array $pushedBookings = [];

    /** @var array<int, int> */
    public static array $updatedBookings = [];

    /** @var array<int, array{externalCalendarId: string, externalEventId: string}> */
    public static array $deletedEvents = [];

    /** @var array<int, array{channelId: string, resourceId: string}> */
    public static array $stoppedWatches = [];

    /** @var array<int, string> */
    public static array $startedWatches = [];

    public static ?Throwable $throwOnPush = null;

    public static string $nextPushId = 'google-event-id-1';

    /** @var array<int, SyncResult|Throwable> */
    public static array $syncBatches = [];

    public function listCalendars($integration): array
    {
        return [
            new CalendarSummary('primary', 'Primary', true, 'owner'),
            new CalendarSummary('work@example.com', 'Work', false, 'writer'),
        ];
    }

    public function createCalendar($integration, string $name): string
    {
        return 'new-cal-'.$name;
    }

    public function pushEvent($booking): string
    {
        if (self::$throwOnPush) {
            throw self::$throwOnPush;
        }
        self::$pushedBookings[] = $booking->id;

        return self::$nextPushId;
    }

    public function updateEvent($booking): void
    {
        self::$updatedBookings[] = $booking->id;
    }

    public function deleteEvent($integration, string $externalCalendarId, string $externalEventId): void
    {
        self::$deletedEvents[] = compact('externalCalendarId', 'externalEventId');
    }

    public function startWatch($integration, string $calendarId): WatchResult
    {
        self::$startedWatches[] = $calendarId;

        return new WatchResult(
            channelId: 'channel-'.$calendarId,
            resourceId: 'resource-'.$calendarId,
            channelToken: 'token-'.$calendarId,
            expiresAt: CarbonImmutable::now()->addDays(7),
        );
    }

    public function stopWatch($integration, string $channelId, string $resourceId): void
    {
        self::$stoppedWatches[] = compact('channelId', 'resourceId');
    }

    public function syncIncremental($integration, string $calendarId): SyncResult
    {
        if (empty(self::$syncBatches)) {
            return new SyncResult([], 'empty-token');
        }

        $batch = array_shift(self::$syncBatches);

        if ($batch instanceof Throwable) {
            throw $batch;
        }

        return $batch;
    }

    public static function reset(): void
    {
        self::$pushedBookings = [];
        self::$updatedBookings = [];
        self::$deletedEvents = [];
        self::$stoppedWatches = [];
        self::$startedWatches = [];
        self::$throwOnPush = null;
        self::$nextPushId = 'google-event-id-1';
        self::$syncBatches = [];
    }
}
