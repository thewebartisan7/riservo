<?php

namespace App\Services\Calendar;

use App\Models\Booking;
use App\Models\CalendarIntegration;
use App\Services\Calendar\DTOs\CalendarSummary;
use App\Services\Calendar\DTOs\ExternalEvent;
use App\Services\Calendar\DTOs\SyncResult;
use App\Services\Calendar\DTOs\WatchResult;
use App\Services\Calendar\Exceptions\SyncTokenExpiredException;
use Carbon\CarbonImmutable;
use Google\Service\Calendar;
use Google\Service\Calendar\Calendar as CalendarResource;
use Google\Service\Calendar\CalendarListEntry;
use Google\Service\Calendar\Channel;
use Google\Service\Calendar\Event;
use Google\Service\Calendar\EventAttendee;
use Google\Service\Calendar\EventDateTime;
use Google\Service\Calendar\EventExtendedProperties;
use Google\Service\Exception as GoogleServiceException;
use Illuminate\Support\Str;

/**
 * Google Calendar implementation of CalendarProvider (D-082).
 *
 * Thin adapter: map to/from Google SDK types, translate SDK exceptions to
 * typed domain exceptions (SyncTokenExpiredException on 410). The caller is
 * the job/controller — all persistence, retry, and business rules live
 * outside this class.
 */
class GoogleCalendarProvider implements CalendarProvider
{
    public function __construct(
        private readonly GoogleClientFactory $clientFactory,
    ) {}

    public function listCalendars(CalendarIntegration $integration): array
    {
        $service = $this->service($integration);
        $calendars = [];

        $pageToken = null;
        do {
            $response = $service->calendarList->listCalendarList(array_filter([
                'pageToken' => $pageToken,
                'minAccessRole' => 'reader',
            ]));

            /** @var CalendarListEntry $entry */
            foreach ($response->getItems() as $entry) {
                $calendars[] = new CalendarSummary(
                    id: $entry->getId(),
                    summary: (string) ($entry->getSummaryOverride() ?: $entry->getSummary()),
                    primary: (bool) $entry->getPrimary(),
                    accessRole: (string) $entry->getAccessRole(),
                );
            }

            $pageToken = $response->getNextPageToken();
        } while ($pageToken);

        return $calendars;
    }

    public function createCalendar(CalendarIntegration $integration, string $name): string
    {
        $service = $this->service($integration);

        $calendar = new CalendarResource;
        $calendar->setSummary($name);
        $calendar->setTimeZone($integration->business?->timezone ?? 'UTC');

        $created = $service->calendars->insert($calendar);

        return $created->getId();
    }

    public function pushEvent(Booking $booking): string
    {
        $integration = $booking->provider->user->calendarIntegration;
        $service = $this->service($integration);

        $event = $this->buildEventFromBooking($booking);

        $created = $service->events->insert(
            $integration->destination_calendar_id,
            $event,
        );

        return $created->getId();
    }

    public function updateEvent(Booking $booking): void
    {
        $integration = $booking->provider->user->calendarIntegration;
        $service = $this->service($integration);

        $event = $this->buildEventFromBooking($booking);

        // Route the update to the calendar the event was originally pushed to
        // (round 2 review). If the integration has been reconfigured since,
        // the current destination may be a different calendar entirely.
        $calendarId = $booking->external_event_calendar_id ?? $integration->destination_calendar_id;

        $service->events->update(
            $calendarId,
            $booking->external_calendar_id,
            $event,
        );
    }

    public function deleteEvent(
        CalendarIntegration $integration,
        string $externalCalendarId,
        string $externalEventId,
    ): void {
        $service = $this->service($integration);

        try {
            $service->events->delete($externalCalendarId, $externalEventId);
        } catch (GoogleServiceException $e) {
            // 404/410 mean the event is already gone — treat as success.
            if (in_array($e->getCode(), [404, 410], true)) {
                return;
            }
            throw $e;
        }
    }

    public function startWatch(CalendarIntegration $integration, string $calendarId): WatchResult
    {
        $service = $this->service($integration);

        $channelId = (string) Str::uuid();
        $channelToken = Str::random(40);
        $webhookUrl = url('/webhooks/google-calendar');

        $channel = new Channel;
        $channel->setId($channelId);
        $channel->setType('web_hook');
        $channel->setAddress($webhookUrl);
        $channel->setToken($channelToken);

        $response = $service->events->watch($calendarId, $channel);

        $expirationMs = $response->getExpiration();
        $expiresAt = $expirationMs !== null
            ? CarbonImmutable::createFromTimestampMs((int) $expirationMs)
            : CarbonImmutable::now()->addDays(7);

        return new WatchResult(
            channelId: $channelId,
            resourceId: (string) $response->getResourceId(),
            channelToken: $channelToken,
            expiresAt: $expiresAt,
        );
    }

    public function stopWatch(
        CalendarIntegration $integration,
        string $channelId,
        string $resourceId,
    ): void {
        $service = $this->service($integration);

        $channel = new Channel;
        $channel->setId($channelId);
        $channel->setResourceId($resourceId);

        try {
            $service->channels->stop($channel);
        } catch (GoogleServiceException $e) {
            // 404: channel already expired / stopped. Swallow — idempotent.
            if ($e->getCode() === 404) {
                return;
            }
            throw $e;
        }
    }

    public function syncIncremental(CalendarIntegration $integration, string $calendarId): SyncResult
    {
        $service = $this->service($integration);

        // D-086 (round 2): the sync token is per-watch. Google rejects a token
        // from calendar A when listing events on calendar B with 410, so this
        // must be scoped.
        $watch = $integration->watches()->where('calendar_id', $calendarId)->first();
        $syncToken = $watch?->sync_token;

        $params = ['showDeleted' => true, 'singleEvents' => true];
        if ($syncToken) {
            $params['syncToken'] = $syncToken;
        } else {
            // First sync is forward-only (locked decision #3): no retroactive import.
            $params['timeMin'] = now()->toRfc3339String();
        }

        $events = [];
        $pageToken = null;
        $nextSyncToken = null;

        try {
            do {
                if ($pageToken) {
                    $params['pageToken'] = $pageToken;
                }

                $response = $service->events->listEvents($calendarId, $params);

                foreach ($response->getItems() as $item) {
                    $events[] = $this->mapEvent($item, $calendarId);
                }

                $pageToken = $response->getNextPageToken();
                $nextSyncToken = $response->getNextSyncToken() ?: $nextSyncToken;
            } while ($pageToken);
        } catch (GoogleServiceException $e) {
            if ($e->getCode() === 410) {
                throw new SyncTokenExpiredException('Sync token expired for calendar '.$calendarId, previous: $e);
            }
            throw $e;
        }

        return new SyncResult(events: $events, nextSyncToken: $nextSyncToken);
    }

    private function service(CalendarIntegration $integration): Calendar
    {
        return new Calendar($this->clientFactory->make($integration));
    }

    private function buildEventFromBooking(Booking $booking): Event
    {
        $booking->loadMissing(['service', 'customer', 'business', 'provider.user']);

        $event = new Event;

        $serviceName = $booking->service?->name ?? __('Appointment');
        $customerName = $booking->customer?->name ?? __('Customer');
        $event->setSummary("[{$serviceName}] — {$customerName}");

        $description = [];
        if ($booking->customer?->phone) {
            $description[] = __('Phone: :phone', ['phone' => $booking->customer->phone]);
        }
        if ($booking->notes) {
            $description[] = __('Customer notes: :notes', ['notes' => $booking->notes]);
        }
        if ($booking->internal_notes) {
            $description[] = __('Internal notes: :notes', ['notes' => $booking->internal_notes]);
        }
        $event->setDescription(implode("\n\n", $description));

        if ($booking->business?->address) {
            $event->setLocation($booking->business->address);
        }

        $start = new EventDateTime;
        $start->setDateTime($booking->starts_at->toIso8601String());
        $start->setTimeZone('UTC');
        $event->setStart($start);

        $end = new EventDateTime;
        $end->setDateTime($booking->ends_at->toIso8601String());
        $end->setTimeZone('UTC');
        $event->setEnd($end);

        $extended = new EventExtendedProperties;
        $extended->setPrivate([
            'riservo_booking_id' => (string) $booking->id,
            'riservo_business_id' => (string) $booking->business_id,
        ]);
        $event->setExtendedProperties($extended);

        return $event;
    }

    private function mapEvent(Event $event, string $calendarId): ExternalEvent
    {
        $start = $this->parseDateTime($event->getStart());
        $end = $this->parseDateTime($event->getEnd());

        $attendees = [];
        foreach ((array) $event->getAttendees() as $attendee) {
            /** @var EventAttendee $attendee */
            if ($attendee->getEmail()) {
                $attendees[] = $attendee->getEmail();
            }
        }

        $extended = [];
        $props = $event->getExtendedProperties();
        if ($props && is_array($props->getPrivate())) {
            foreach ($props->getPrivate() as $key => $value) {
                $extended[$key] = (string) $value;
            }
        }

        return new ExternalEvent(
            id: (string) $event->getId(),
            calendarId: $calendarId,
            status: (string) $event->getStatus(),
            summary: $event->getSummary(),
            description: $event->getDescription(),
            start: $start,
            end: $end,
            attendeeEmails: $attendees,
            htmlLink: $event->getHtmlLink(),
            extendedProperties: $extended,
            creatorEmail: $event->getCreator()?->getEmail(),
        );
    }

    private function parseDateTime(?EventDateTime $dateTime): ?CarbonImmutable
    {
        if ($dateTime === null) {
            return null;
        }

        $value = $dateTime->getDateTime() ?: $dateTime->getDate();
        if (! $value) {
            return null;
        }

        return CarbonImmutable::parse($value)->utc();
    }
}
