---
name: ROADMAP-CALENDAR
description: Calendar integration phases - Phase 2 folded into ROADMAP-MVP-COMPLETION; Phase 3 Outlook/Apple still post-MVP reference
type: roadmap
status: superseded
created: 2026-04-17
updated: 2026-04-17
supersededBy: roadmaps/ROADMAP-MVP-COMPLETION.md
---

# riservo.ch — Calendar Integration Roadmap

> **SUPERSEDED 2026-04-16** — Phase 2 (Google bidirectional sync) is now Session 2 of `docs/roadmaps/ROADMAP-MVP-COMPLETION.md` with definitive decisions locked (HTTP client, schema, sync window, pending actions UX, etc.). Phase 1 (ICS one-way feed) is deferred to `docs/BACKLOG.md`. Phase 3 (Outlook / Apple Calendar) remains post-MVP — refer to this file for that scope only. Kept for historical context.

> Version: 1.1  
> Status: Superseded (Phase 2); Phase 3 historical reference only  
> Scope: External calendar sync (Google Calendar Phase 1–2, multi-provider Phase 3+)

---

## Overview

This roadmap defines the full calendar integration strategy for riservo.ch — from a simple one-way ICS feed to a complete bidirectional integration where businesses can manage their appointments entirely from their external calendar if they choose.

The integration is designed to be built in phases. Each phase delivers standalone value and is fully usable on its own. No phase requires the next to be useful. The phasing is:

- **Phase 1** — ICS one-way feed (read-only, works with any calendar, zero OAuth)
- **Phase 2** — Full bidirectional Google Calendar sync (OAuth, push + pull in one release)
- **Phase 3** — Multi-provider (Outlook, Apple Calendar)

Phase 1 and Phase 2 are separate releases. There is no intermediate "OAuth one-way" release — ICS already covers the one-way use case, and OAuth is only introduced when the full bidirectional value justifies the infrastructure complexity.

---

## Philosophy

### Per-user, not per-business

Every major competitor (Calendly, Cal.com, Acuity Scheduling) uses the same model: **each collaborator connects their own personal calendar account**. There is no single company-wide calendar. This is the correct model because:

- Collaborators have their own accounts and their own private calendars that need to be checked for conflicts (personal appointments, other jobs, etc.)
- A business owner connecting one account cannot represent the availability of multiple collaborators
- It respects the collaborator's privacy — they control what goes into their personal calendar

The admin can connect their own account as well, but as a collaborator of their own business, not as an observer of everyone else's calendars. The unified view across all collaborators remains inside riservo's dashboard calendar.

### riservo is the source of truth for availability rules

The rules of availability (working hours, exceptions, slot intervals, buffer times) are always managed in riservo. The external calendar can *block* availability by marking time as busy, but it cannot *create* availability rules. This matches what every competitor does and is the only sustainable model — a raw Google Calendar event does not carry the information needed to reconstruct a riservo booking (service, duration, buffer, collaborator assignment logic).

### Absences and blocks in Google Calendar

Google Calendar has no concept of "absence" vs "appointment" — everything is an event. The correct model (used by all competitors) is: **any event marked "Busy" in the collaborator's selected conflict calendars blocks that time in riservo**, regardless of what it represents. If a collaborator marks "Ferie" as Busy in Google, riservo blocks those slots.

In the context of Phase 2 inbound sync, a "Ferie" event from Google becomes an External Booking in riservo (not an AvailabilityException). The native absence/exception management in riservo remains the primary way to manage absences — riservo propagates to Google, not the other way around. If a collaborator wants to record a holiday in riservo, they add an exception in riservo (which then appears in their Google Calendar via push sync).

### Cancellations and changes from Google — no silent actions

When a collaborator deletes or modifies a riservo-originated event from their Google Calendar, the system **never silently cancels the riservo booking**. Instead, it creates a **pending action** in the dashboard: "Collaborator X removed this booking from their Google Calendar — do you want to cancel the riservo booking and notify the customer?" The admin or collaborator confirms, and only then is the booking cancelled and the customer notified.

### Conflicts surface as pending actions, not automatic resolutions

No conflict (overlap on initial import, concurrent edits, cancellation of riservo events from Google) is resolved automatically. All conflicts create **pending actions** visible in the dashboard — a dedicated section listing items that require a human decision. Webhook-triggered syncs happen in the background; the dashboard pending actions system is the correct mechanism to surface issues that require user input.

---

## HTTP client: google/apiclient vs Guzzle

The implementation agent must evaluate this decision at the start of Phase 2 and record it in the appropriate topical file listed in `docs/DECISIONS.md`. The key tradeoff:

**`google/apiclient` (official Google SDK)**
- Pros: handles OAuth token refresh automatically, typed event objects, handles API edge cases
- Cons: heavy dependency tree, designed for single-account use (less natural for per-user OAuth flows), adds significant vendor bloat for a single API surface (Calendar Events only)

**Raw HTTP with Guzzle (already in Laravel)**
- Pros: zero new dependencies, full control, lightweight, natural for per-user token storage and refresh
- Cons: more code to write, must handle token refresh and API error codes manually

**`league/oauth2-google` + raw Guzzle**
- A middle ground: handles the OAuth flow cleanly without the full Google SDK weight

The agent should make an explicit decision and not default to `google/apiclient` without weighing the Guzzle alternative. For a single-endpoint integration (Calendar Events only), the overhead of the full SDK may not be justified.

---

## Out of scope for this roadmap

**Group bookings / multi-customer per slot** — a use case where a single slot accepts multiple customers (courses, workshops, group sessions). This requires significant data model changes: a `capacity` field on Service, a `booking_participants` relation replacing the single `customer_id` on Booking, and different slot calculation logic (a slot remains open until capacity is reached). It is a post-MVP feature entirely independent of calendar sync. The calendar integration should be designed not to block this future feature — for example, do not hard-assume a single customer per booking when processing Google Calendar attendee lists.

---

## Scope summary per phase

| Phase | What it delivers | Complexity |
|-------|-----------------|------------|
| **Phase 1** — ICS one-way | Read-only feed, no OAuth, works with any calendar | Low |
| **Phase 2** — Full bidirectional (Google) | OAuth per-collaborator, push + pull in one release | High |
| **Phase 3** — Multi-provider | Outlook, Apple Calendar | High |

---

## Phase 1 — ICS One-Way Sync (Read-Only Feed)

> Delivers: collaborators can subscribe to their riservo appointments in *any* calendar (Google, Apple, Outlook) with zero OAuth complexity. Fully usable as a standalone feature.

riservo generates a private, signed ICS feed URL per collaborator. The collaborator adds it to any calendar app as a subscribed calendar. The calendar app polls the feed periodically and shows riservo appointments.

**Limitation**: ICS subscription is read-only and poll-based (typically every 1–24 hours depending on the calendar app). It is not real-time and does not block availability in riservo based on external events.

### Session C1 — ICS Feed

- [ ] Add `ics_token` (nullable, unique string) column to the `users` table for per-collaborator feed authentication
- [ ] Add `ics_token` (nullable, unique string) column to the `businesses` table for the combined admin feed
- [ ] ICS feed endpoint: `GET /calendar/feed/{token}.ics`
  - Returns valid RFC 5545 `.ics` file with all bookings for that collaborator
  - Event fields: `SUMMARY` (service name + customer name), `DTSTART`, `DTEND`, `DESCRIPTION` (booking details: duration, customer phone, notes), `LOCATION` (business address if set), `UID` (stable, keyed on booking UUID), `STATUS`
  - Window: events from today - 30 days to today + 12 months
  - Cancelled bookings included with `STATUS:CANCELLED` so calendar apps remove them on next poll
  - Feed is always live — no active push needed, changes are reflected on next poll
- [ ] Combined admin feed: `GET /calendar/feed/business/{token}.ics` — all collaborators' bookings for the business in one feed
- [ ] Regenerate token endpoint: invalidates old URL, issues a new one
- [ ] Settings > Calendar Integration page (new, accessible to both admin and collaborator):
  - Per-collaborator ICS feed URL with copy button
  - Step-by-step instructions for adding to Google Calendar, Apple Calendar, Outlook
  - "Regenerate link" button with warning that the old link will stop working
  - Admin section: combined business feed URL (admin-only)
- [ ] Settings sub-nav: add "Calendar Integration" link, visible to both admin and collaborator roles

---

## Phase 2 — Full Bidirectional Sync (Google Calendar OAuth)

> Delivers: riservo bookings appear in the collaborator's Google Calendar in real time (push), and events created/modified/deleted in Google Calendar are reflected in riservo (pull). One release, both directions.

This phase introduces OAuth per-collaborator, push jobs, webhooks, and the pending actions system. It should only be started once Phase 1 is stable in production.

### Which calendars to use — configuration at connect time

At the moment of connecting, the collaborator configures two things:

1. **Destination calendar** — which Google Calendar receives riservo bookings (e.g. "Work", or a new dedicated "Riservo Appointments" calendar created via API during the connect flow).
2. **Conflict calendars** — which Google Calendars are read for busy-time blocks. Multiple can be selected. Any event marked "Busy" on any of these calendars blocks the corresponding slots in riservo. Events marked "Free" are ignored (standard behaviour across all competitors).

This configuration is stored on `CalendarIntegration` and is editable after connect.

### Session C2 — Google OAuth + Push + Pull (full bidirectional)

**Dependencies + Config**
- [ ] Decide and document the HTTP client choice in the appropriate topical file listed in `docs/DECISIONS.md`
- [ ] Install chosen packages
- [ ] Add `services.google` block to `config/services.php`
- [ ] Add `GOOGLE_CLIENT_ID`, `GOOGLE_CLIENT_SECRET`, `GOOGLE_REDIRECT_URI` to `.env.example`

**Data layer**
- [ ] `bookings.customer_id` → nullable (external events may have no identifiable customer)
- [ ] `bookings.external_title` → nullable string (Google event summary for external events)
- [ ] `bookings.external_calendar_id` — verify already exists; add if not
- [ ] **`service_id` on external bookings**: the agent should evaluate whether to use a system-level "External Appointment" placeholder service per business (non-bookable, non-visible on public page) rather than leaving `service_id` null. A placeholder avoids null propagation throughout the codebase and is more legible in the UI. The decision must be recorded in the appropriate topical file listed in `docs/DECISIONS.md`.
- [ ] Extend `calendar_integrations` table:
  - `access_token` (encrypted), `refresh_token` (encrypted), `token_expires_at`
  - `destination_calendar_id` (default `'primary'`)
  - `conflict_calendar_ids` JSON array
  - `sync_token` (for incremental Google sync)
  - `webhook_resource_id`, `webhook_channel_id`, `webhook_channel_token`, `webhook_expiry`
  - `last_synced_at`, `last_pushed_at`
  - `sync_error`, `sync_error_at`, `push_error`, `push_error_at`
  - Unique index on `(user_id, provider)`
- [ ] New `calendar_pending_actions` table: `id`, `business_id`, `integration_id`, `booking_id` (nullable FK), `type` (enum: `riservo_event_deleted_in_google` | `external_booking_conflict`), `payload` JSON, `status` (enum: `pending` | `resolved` | `dismissed`), `created_at`, `resolved_at`

**CalendarProvider abstraction**
- [ ] `CalendarProvider` interface in `app/Services/Calendar/` with methods:
  - `pushEvent(Booking): string` — returns external event ID
  - `updateEvent(Booking): void`
  - `deleteEvent(Booking): void`
  - `startWatch(CalendarIntegration): void`
  - `stopWatch(CalendarIntegration): void`
  - `syncIncremental(CalendarIntegration): void`
  - `listCalendars(CalendarIntegration): array` — for the configuration UI
  - `createCalendar(CalendarIntegration, string $name): string` — for "Create dedicated calendar" option
- [ ] `GoogleCalendarProvider` implementing the interface
- [ ] `CalendarProviderFactory` — resolves provider from a `CalendarIntegration`

**OAuth connect flow**
- [ ] `Settings\CalendarIntegrationController`:
  - `index()` — renders Settings > Calendar Integration with ICS section + Google OAuth section
  - `connect()` — redirects to Google OAuth (scopes: `calendar.events`, `calendar.readonly`, offline access, consent prompt)
  - `callback()` — stores tokens; fetches user's Google Calendar list; renders a **configuration step** before finalising connect (see below)
  - `configure()` — POST: saves destination calendar + conflict calendars; dispatches `StartCalendarSyncJob`; redirects with flash
  - `disconnect()` — stops watch (best-effort), deletes integration, redirects with flash
- [ ] **Connect configuration step** (rendered after callback, before finalising):
  - Dropdown: "Add riservo bookings to..." (lists Google Calendars + "Create a new dedicated calendar" option)
  - Multi-select: "Check these calendars for conflicts" (lists all Google Calendars; primary pre-selected)
  - Preview: "X upcoming events found in selected calendars that will be imported as External Bookings"
  - Conflict warning: if any imported events overlap with existing confirmed riservo bookings, list them with a warning before proceeding
  - "Connect and import" confirmation button
- [ ] `StartCalendarSyncJob` — queued: calls `startWatch` + initial `syncIncremental` (future events only, from today)

**Push sync — outbound**
- [ ] `PushBookingToCalendarJob` — queued, serializes Booking + action ('create' | 'update' | 'delete')
  - Skips silently if collaborator has no `CalendarIntegration`
  - Handles token refresh before API call
  - On create: stores returned Google event ID in `bookings.external_calendar_id`
  - On failure: writes `push_error` + `push_error_at` on integration
  - Retry with backoff: `tries=3`, `backoff=[60, 300, 900]`
- [ ] Dispatch at every booking mutation point:
  - Booking created (public flow, manual from dashboard)
  - Booking status changed to cancelled, no_show, completed
  - Booking confirmed from pending
  - **Do not dispatch** for bookings with `source = google_calendar`
- [ ] Google event format:
  - `SUMMARY`: `[Service Name] — [Customer Name]`
  - `DESCRIPTION`: service duration, customer phone, internal notes if any, link to riservo booking detail
  - `LOCATION`: business address if set
  - `extendedProperties.private.riservo_booking_id`: booking UUID — used in pull sync to identify riservo-originated events
  - Start/end in UTC (Google handles timezone display for the user)

**SlotGeneratorService compatibility**
- [ ] Nullsafe access on `$booking->service?->buffer_before ?? 0` throughout SlotGeneratorService
- [ ] External bookings (`source = google_calendar`, `status = confirmed`) must block slots — verify this works regardless of placeholder service vs null approach

**Pull sync — inbound**
- [ ] `PullCalendarEventsJob` — queued
  - Calls `GoogleCalendarProvider::syncIncremental($integration)`
  - `syncIncremental` logic:
    - Lists changed events via `events.list` with stored `syncToken`; falls back to full sync on 410 (Gone)
    - **Skip riservo-originated events**: if event has `extendedProperties.private.riservo_booking_id` set and event is *active* → skip entirely. If the same event is `status=cancelled` (the collaborator deleted it from Google) → create a `calendar_pending_actions` record of type `riservo_event_deleted_in_google`. Do not auto-cancel the riservo booking.
    - For new/updated external events: upsert booking by `external_calendar_id`
      - `source = google_calendar`, `status = confirmed`
      - `collaborator_id` = integration owner
      - `service_id` = placeholder service ID (or null, per decision)
      - `external_title` = Google event summary
      - `starts_at` / `ends_at` from Google event dateTime (converted to UTC)
      - **Attendees**: attempt to match attendee emails to existing `Customer` records. Link the first match as `customer_id`. Do not auto-create Customer records from unrecognised Google attendee emails. Multiple attendees is an edge case — link the first matching customer only; full group booking support is post-MVP.
      - If the external event overlaps with an existing confirmed riservo booking: import it but create a `calendar_pending_actions` record of type `external_booking_conflict`
    - For cancelled external events: find booking by `external_calendar_id`; if `source = google_calendar`, auto-cancel (no customer notification needed as there may be no customer)
    - Persist new `nextSyncToken` + `last_synced_at`
  - Retry on transient errors: `tries=5`, `backoff=[60, 120, 300, 600, 1200]`
  - On persistent failure: write `sync_error` + `sync_error_at`

**Webhook endpoint**
- [ ] `POST /webhooks/google-calendar` — no auth, CSRF excluded
  - Validate `X-Goog-Channel-Token` against `calendar_integrations.webhook_channel_token` → 400 if mismatch
  - Find integration by `X-Goog-Channel-ID`
  - Dispatch `PullCalendarEventsJob`, return 200 immediately
- [ ] CSRF exclusion in `bootstrap/app.php`

**Webhook renewal**
- [ ] `RenewCalendarWebhooks` artisan command: finds integrations where `webhook_expiry < now + 24h`; calls `stopWatch` + `startWatch` for each
- [ ] Schedule daily at 03:00 in `routes/console.php`

**Pending actions system**
- [ ] Dashboard: "Calendar Sync" notification section showing unresolved `calendar_pending_actions` count + list
- [ ] For `riservo_event_deleted_in_google`:
  - "Collaborator X removed [Service] with [Customer] from their Google Calendar. Cancel the riservo booking and notify the customer?"
  - Actions: "Cancel booking and notify customer" / "Keep booking and dismiss"
- [ ] For `external_booking_conflict`:
  - "An imported Google event ([title]) overlaps with an existing booking ([booking details])."
  - Actions: "Keep both" / "Cancel external event" / "Cancel riservo booking" (with confirmation + customer notification)
- [ ] Pending actions count shown in Settings > Calendar Integration header

**Notification suppression**
- [ ] All notification dispatch sites: guard with `if ($booking->source !== BookingSource::GoogleCalendar)`
- [ ] `SendBookingReminders` command: exclude `source = google_calendar` bookings from reminder queries

**Dashboard + Calendar UI**
- [ ] Booking detail panel: when `source = google_calendar`, show "External Event" label + `external_title`; hide customer/service sections if null; show "Open in Google Calendar" link (use the event htmlLink from Google API, not the raw event ID)
- [ ] Calendar views: external events rendered with a Google Calendar icon + neutral color, visually distinct from riservo bookings
- [ ] Booking list: filter to show/hide external events

**Settings > Calendar Integration (updated from Phase 1)**
- [ ] Two sections: ICS (Phase 1, always visible) + Google Calendar OAuth (Phase 2)
  - Not connected: "Connect Google Calendar" button
  - Connected: account email, destination calendar, conflict calendars, last synced, disconnect button, pending actions count
  - Error state: banner with "Reconnect" CTA
  - "Sync now" button for manual sync (debugging)

---

## Phase 3 — Multi-Provider (Outlook, Apple Calendar)

> Delivers: collaborators who use Outlook or Apple Calendar get the same integration. Leverages the `CalendarProvider` interface built in Phase 2.

### Session C3 — Outlook (Microsoft Graph API)

- [ ] `OutlookCalendarProvider` implementing `CalendarProvider`
- [ ] Microsoft OAuth flow (Socialite or MSAL)
- [ ] Push sync via Microsoft Graph Calendar API
- [ ] Pull sync via Microsoft Graph change notifications (subscriptions expire every ~3 days — renewal command must handle this)
- [ ] Same calendar configuration UX as Google (destination calendar + conflict calendars)
- [ ] Settings UI: "Connect Outlook" option alongside Google

### Session C4 — Apple Calendar (CalDAV)

- [ ] `AppleCalDAVProvider` implementing `CalendarProvider` (push methods only)
- [ ] Auth via app-specific password (no OAuth)
- [ ] Outbound push via CalDAV PUT/DELETE requests
- [ ] **No inbound pull** — Apple does not support server-side push notifications; polling is impractical for this use case
- [ ] UI limitation disclosure: "Apple Calendar: one-way sync only. Bookings created in riservo will appear in your Apple Calendar. Events created directly in Apple Calendar will not sync back to riservo."
- [ ] Settings UI: "Connect Apple Calendar" with app-specific password input + setup instructions

---

## Cross-phase decisions to record via `docs/DECISIONS.md`

- **HTTP client choice** (Phase 2): agent evaluates at planning time
- **Placeholder service vs nullable `service_id`** (Phase 2): agent evaluates based on full codebase context
- **Initial sync window** (Phase 2): future events only (from today) — no retroactive import
- **Multi-attendee handling** (Phase 2): first matching customer only; group booking support is post-MVP
- **Conflict calendar defaults** (Phase 2): which calendars are pre-selected at connect time
- **Outlook renewal frequency** (Phase 3): Microsoft subscriptions expire every ~3 days vs Google's ~7 days — ensure `RenewCalendarWebhooks` handles both

---

## Deployment notes (per phase, to be added to DEPLOYMENT.md)

**Phase 1**: No new server requirements. ICS endpoint is a standard HTTP route.

**Phase 2**: Queue workers required. `GOOGLE_*` env vars required. OAuth redirect URI must match Google Cloud Console exactly. Webhook endpoint must be publicly HTTPS-reachable (Google rejects HTTP). Local development requires a tunnel (ngrok, Expose, Herd sharing). `calendar:renew-watches` must run daily via cron.

**Phase 3 (Outlook)**: Additional Azure AD OAuth app required. Microsoft subscription renewal is more frequent than Google — verify the renewal command runs often enough.

---

*This document defines the WHAT and the phasing. The HOW is decided per session in dedicated plan documents. Each phase is independently shippable. Phase 1 can go live in a single session. Phase 2 is a full session. Phase 3 is post-MVP v2 work.*
