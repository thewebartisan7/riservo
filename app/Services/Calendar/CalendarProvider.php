<?php

namespace App\Services\Calendar;

use App\Models\Booking;
use App\Models\CalendarIntegration;
use App\Services\Calendar\DTOs\CalendarSummary;
use App\Services\Calendar\DTOs\SyncResult;
use App\Services\Calendar\DTOs\WatchResult;

/**
 * Abstract external calendar operations (D-082).
 *
 * `GoogleCalendarProvider` is the only MVP implementation. Adding Outlook or
 * Apple (via CalDAV) post-MVP is purely additive: a new class + one line in
 * CalendarProviderFactory. No method exposes a provider-specific SDK type.
 */
interface CalendarProvider
{
    /**
     * List the authenticated user's writable calendars plus any read-only
     * calendars they can watch for conflicts.
     *
     * @return array<int, CalendarSummary>
     */
    public function listCalendars(CalendarIntegration $integration): array;

    /**
     * Create a new calendar in the user's account; return its external id.
     */
    public function createCalendar(CalendarIntegration $integration, string $name): string;

    /**
     * Push a riservo-created booking to the configured destination calendar.
     * Returns the external event id the caller must store.
     */
    public function pushEvent(Booking $booking): string;

    public function updateEvent(Booking $booking): void;

    public function deleteEvent(
        CalendarIntegration $integration,
        string $externalCalendarId,
        string $externalEventId,
    ): void;

    /**
     * Subscribe to push notifications for a calendar; the provider persists
     * nothing — the caller writes the returned values to calendar_watches.
     */
    public function startWatch(CalendarIntegration $integration, string $calendarId): WatchResult;

    public function stopWatch(
        CalendarIntegration $integration,
        string $channelId,
        string $resourceId,
    ): void;

    /**
     * Pull incremental changes for a single calendar. If the integration's
     * stored sync token has expired, callers must clear it and re-invoke —
     * this method signals expiry via SyncTokenExpiredException.
     */
    public function syncIncremental(CalendarIntegration $integration, string $calendarId): SyncResult;
}
