# PLAN-MVPC-2 — Google Calendar Sync (Bidirectional)

- **Session**: MVPC-2 (second session of `docs/roadmaps/ROADMAP-MVP-COMPLETION.md`)
- **Source**: `ROADMAP-MVP-COMPLETION.md §Session 2 — Google Calendar Sync (Bidirectional)`
- **Cross-cutting locked decisions this session implements**: #1 (HTTP client/SDK), #2 (external booking schema), #3 (initial sync window), #4 (conflict-calendar default), #5 (pending actions UX), #6 (multi-attendee handling), #7 (notification suppression), #8 (settings access split — extend the shared group; no changes).
- **Related live decisions**: D-080 (Socialite + google/apiclient stack), D-081 (shared settings group), D-065 (GIST overlap constraint), D-066 (booking overlap invariant + read/write symmetry), D-067 (historical provider withTrashed), D-031 (only pending/confirmed block availability), D-005 / D-030 (business-timezone semantics), D-056 (booking_reminders deduplication).
- **New decisions recorded in this session**: **D-082** through **D-088** (see §10).
- **Baseline**: post-MVPC-1, Feature + Unit suite **535 passed / 2326 assertions** (run `php artisan test tests/Feature tests/Unit --compact`). Full suite 784 / 3264 (Browser 249 unchanged). Pint clean; Vite build clean; Wayfinder regenerated.
- **Review round 1 (2026-04-17) applied**: (1) dedicated `bookings.external_html_link` column replaces `internal_notes` overload (D-084 revised); (2) Google event DESCRIPTION drops the `?highlight={id}` deep link (out of scope); (3) `cancel_riservo_booking` resolver re-dispatches `PullCalendarEventsJob` for the source calendar so the external event materialises promptly (D-087 revised); (4) pending-action visibility + resolution narrowed to "owner-of-integration OR admin" (extends D-085 / D-088); (5) `business_id` column made explicit in the §3 step 2 migration enumeration; (6) Q7 cross-tenant notice kept, "Disconnect to reconfigure" CTA dropped.

---

## 1. Context

Session 1 stood up the OAuth round-trip: a user clicks "Connect Google Calendar", grants consent, and lands back with tokens encrypted-at-rest on a `CalendarIntegration` row scoped to `auth()->user()`. No sync logic — no webhooks, no push/pull, no `CalendarProvider` interface.

Session 2 is the core two-way sync. The `calendar_integrations` table must grow to carry the destination calendar, the conflict calendars, the sync-token / webhook plumbing, and per-integration error state. A new `calendar_pending_actions` table materialises the admin's work queue when Google-side activity cannot be auto-resolved (per locked decision #5). The `bookings` schema must relax `customer_id` and `service_id` to nullable and add an `external_title` column so Google events land as proper booking rows that participate in the D-065 / D-066 GIST overlap constraint without needing a placeholder service. `SlotGeneratorService` needs nullsafe access on `$booking->service?->buffer_*` so external bookings don't crash slot generation (they store their own `buffer_*_minutes` on the row, per D-066 — but the eager-load-service path still touches the service).

A `CalendarProvider` interface lives above Socialite + `google/apiclient`, with `GoogleCalendarProvider` as the first (and only, for MVP) implementation. The interface design must admit Outlook / Apple additions without touching `Booking`, `SlotGeneratorService`, or any notification / controller call-site.

The settings-page UX extends: after the OAuth callback, the user must complete a configuration step (pick destination calendar, pick conflict calendars, preview import, confirm). Only after this step does the integration finalise — the `CalendarIntegration` row without a `destination_calendar_id` is in a "pending configuration" state and push/pull are not yet active.

Every booking-related `Notification` dispatch site guards with `if ($booking->source !== BookingSource::GoogleCalendar)` — the guards live at the dispatch site, not inside the Notification classes (locked decision #7). `SendBookingReminders` also excludes `source = google_calendar` at query-time so we never pay the render cost for ineligible reminders.

Per-tenant ops: `calendar:renew-watches` Artisan command refreshes watches approaching their expiry (Google caps subscriptions at ~30 days), scheduled daily at 03:00 UTC.

## 2. Goal

Ship a fully two-way Google Calendar sync behind a `CalendarProvider` interface:

- A connected business user picks a destination calendar and zero-or-more conflict calendars during the connect flow.
- Every riservo booking mutation (create / update / cancel) pushes to the destination calendar via a queued job that tolerates transient failure.
- Every change on any of the configured Google calendars arrives via webhook, is pulled via `events.list` with the stored `syncToken`, and lands as an external `source = google_calendar` booking (blocking availability) **or** as a `calendar_pending_action` when the change would conflict with an existing riservo booking or represent a Google-side deletion of a riservo-originated event.
- Admins/staff resolve pending actions from a dashboard section; the system never silently cancels a riservo booking because of a Google-side change.
- External (`source = google_calendar`) bookings never trigger customer notifications, are visually distinct in the calendar views, and are surfaced with an "Open in Google Calendar" deep link.

## 3. Scope

### In scope

**Data layer**

1. Migration: `bookings.customer_id` → nullable; `bookings.service_id` → nullable; add `bookings.external_title` (string, nullable); add `bookings.external_html_link` (string, nullable — dedicated column for the Google event's `htmlLink` so the detail panel's "Open in Google Calendar" link doesn't overload the admin-notes column; per review-revised D-084). Keep `provider_id` non-nullable (every external event binds to the provider whose calendar it came from).
2. Migration: extend `calendar_integrations` with `business_id` (FK nullable → businesses, cascadeOnDelete — anchors the sync target per D-085; null means "MVPC-1 state, not yet configured"), `destination_calendar_id` (string, nullable, no default — `null` means "configuration step not yet completed"; we do not pre-pin `'primary'` in SQL because "primary" is the Google SDK alias for the user's primary calendar, not a calendar ID we persist), `conflict_calendar_ids` (JSON, default `'[]'`), `sync_token` (text, nullable), `webhook_resource_id` (string, nullable), `webhook_channel_token` (string, nullable — per-channel validation secret), `last_synced_at` (timestamp, nullable), `last_pushed_at` (timestamp, nullable), `sync_error` (text, nullable), `sync_error_at` (timestamp, nullable), `push_error` (text, nullable), `push_error_at` (timestamp, nullable). Existing `webhook_channel_id` + `webhook_expiry` are reused as-is (unused after MVPC-2; kept per D-086 to avoid schema churn).
3. New table `calendar_pending_actions`: `id`, `business_id` FK cascadeOnDelete, `integration_id` FK → `calendar_integrations`, `booking_id` FK nullable → `bookings` (set-null on booking delete), `type` (string — backed by `PendingActionType` enum: `riservo_event_deleted_in_google` | `external_booking_conflict`), `payload` JSON (arbitrary per-type detail — e.g. `external_event_id`, `external_summary`, `conflict_booking_ids[]`), `status` (string — backed by `PendingActionStatus` enum: `pending` | `resolved` | `dismissed`), `resolved_by_user_id` FK nullable → `users`, `resolution_note` (string, nullable — stores which option the user picked), `created_at`, `resolved_at` (nullable), `updated_at`. Index on `(business_id, status)`.
4. `Booking` model: `external_title` in Fillable; `customer()` relation remains (null-tolerant by virtue of the FK being nullable). Add `calendarIntegration()` helper — `$booking->provider->user->calendarIntegration` resolves the integration for push targeting (no new relation needed, but a `booking->externalHtmlLink()` accessor that returns the Google event's htmlLink is added for the detail panel).
5. `CalendarIntegration` model: new fillables (`destination_calendar_id`, `conflict_calendar_ids`, `sync_token`, `webhook_resource_id`, `webhook_channel_token`, `last_synced_at`, `last_pushed_at`, `sync_error`, `sync_error_at`, `push_error`, `push_error_at`), JSON cast on `conflict_calendar_ids`, datetime casts on the four new `*_at` fields. `isConfigured()` helper returns `destination_calendar_id !== null`.
6. `PendingAction` model + factory + `HasMany` from `CalendarIntegration` and `Business`.
7. `BookingSource` enum — unchanged (`GoogleCalendar` case already exists).

**Provider abstraction**

8. `app/Services/Calendar/CalendarProvider.php` interface:
    - `listCalendars(CalendarIntegration $integration): array<CalendarSummary>` — DTO per calendar (id, summary, primary bool, accessRole).
    - `createCalendar(CalendarIntegration $integration, string $name): string` — returns the new calendar ID.
    - `pushEvent(Booking $booking): string` — returns the created Google event ID.
    - `updateEvent(Booking $booking): void`.
    - `deleteEvent(CalendarIntegration $integration, string $externalCalendarId, string $externalEventId): void`.
    - `startWatch(CalendarIntegration $integration, string $calendarId): WatchResult` — DTO (resourceId, channelId, channelToken, expiresAt).
    - `stopWatch(CalendarIntegration $integration, string $channelId, string $resourceId): void`.
    - `syncIncremental(CalendarIntegration $integration, string $calendarId): SyncResult` — DTO containing the new `syncToken`, plus arrays of created/updated/deleted `ExternalEvent` DTOs (id, calendar_id, summary, description, start, end, attendees[], htmlLink, extendedProperties[]).
9. `app/Services/Calendar/GoogleCalendarProvider.php` — one class, constructor-injected with a `GoogleClientFactory` that produces a configured `Google\Client` with the **token-update callback** wired (see §4.3). Every method here is the thin adapter to the Google SDK with the response mapped to the interface's DTOs.
10. `app/Services/Calendar/CalendarProviderFactory.php` — resolves a `CalendarProvider` implementation from a `CalendarIntegration->provider` string. Bound in `AppServiceProvider::register()`. `$factory->for($integration)` returns the concrete provider; unknown provider string throws `UnsupportedCalendarProviderException`.
11. DTOs under `app/Services/Calendar/DTOs/`: `CalendarSummary`, `WatchResult`, `ExternalEvent`, `SyncResult`.

**OAuth connect — configuration step (extends MVPC-1)**

12. After `Socialite::driver('google')->user()` succeeds, the callback still upserts the row (tokens + google_account_email + expiry) but **does not redirect to the settings page**. Instead it redirects to a new configuration route `GET /dashboard/settings/calendar-integration/configure`.
13. The configure page renders a form that calls `listCalendars` (cached for the request) and shows:
    - Dropdown: "Add riservo bookings to..." — lists the user's Google calendars with write access, plus a "Create a dedicated 'riservo' calendar" option.
    - Multi-select: "Also watch these calendars for external events" — primary pre-selected (locked decision #4); user can add / remove.
    - Preview: count of upcoming events in the past 0 days forward that will import as external bookings.
    - Conflict preview: list of imported-preview events that overlap with existing confirmed riservo bookings for the user's provider row(s) in their current business — these will become `external_booking_conflict` pending actions on import.
14. `POST /dashboard/settings/calendar-integration/configure` finalises:
    - If user picked "create new calendar", call `createCalendar`, set `destination_calendar_id` to the returned ID.
    - Persist `conflict_calendar_ids` (JSON).
    - Dispatch `StartCalendarSyncJob` (queued) which calls `startWatch` for **each** calendar in `[destination_calendar_id, ...conflict_calendar_ids]` (dedup first) and runs an initial forward-only `syncIncremental` on each distinct watched calendar. Forward-only = the first `syncIncremental` call passes a time-bounded `timeMin=now` query until a `nextSyncToken` is returned; from that point onward, subsequent `syncIncremental` uses the stored `syncToken`.
    - Redirect to settings page with success flash.
15. `disconnect()` gains a `stopWatch` call per watched calendar before the row is deleted (`try/catch` around each; a dead/expired channel must not block deletion). Replaces the existing `// TODO Session 2: stop webhook watch here` comment.

**Push sync — outbound**

16. `app/Jobs/Calendar/PushBookingToCalendarJob` implements `ShouldQueue`, `Queueable`; public int `$tries = 3`; public function `backoff(): array { return [60, 300, 900]; }`; constructor signature `(int $bookingId, string $action)` where `$action ∈ ['create', 'update', 'delete']` (single-job design; see §4.4). `afterCommit()` in the constructor. Handler re-resolves the booking (withTrashed on the provider) and short-circuits if the booking no longer exists (a hard-delete race). On success, persists `last_pushed_at` on the integration; on the final failed retry (`failed()` method), writes `push_error` + `push_error_at`.
17. For `create`: the provider returns the Google event ID. The job stores it in `bookings.external_calendar_id`.
18. For `delete`: the job requires both the destination calendar ID (from the integration) and the Google event ID (from `bookings.external_calendar_id`). If either is missing (booking was never pushed), the job is a no-op.
19. Dispatch sites added to — every booking-mutating call site:
    - `PublicBookingController::store` — after booking create, `create`.
    - `Dashboard\BookingController::store` — after manual booking create, `create`.
    - `Dashboard\BookingController::updateStatus` — on each allowed transition (`confirmed`/`cancelled`/`completed`/`no_show`), dispatch with the right action: cancelled → `delete`, completed/no_show → `update` (event still exists but with appropriate title/status prefix), confirmed → `update` (promote a pending booking). Terminal-from-pending requires a `create` when the booking was pending and thus never pushed — see §4.4.
    - `Customer\BookingController::cancel` — `delete`.
    - `Booking\BookingManagementController::cancel` — `delete`.
    - Session 5 will add the reschedule dispatch — out of scope here.
20. Every dispatch is gated by two conditions: (a) the provider's user has a `CalendarIntegration` with `destination_calendar_id !== null`; (b) `$booking->source !== BookingSource::GoogleCalendar` (inbound-origin bookings are not pushed back out — they would round-trip to the same event). Gate via a tiny helper `Booking::shouldPushToCalendar(): bool` on the model, so the five dispatch sites call one method.
21. Google event format: `SUMMARY` = `[{Service name}] — [{Customer name}]`; `DESCRIPTION` includes customer phone and internal notes (the Google event already carries the full booking detail; **no deep link back to the riservo dashboard** — the "bookings list scroll-to-and-highlight" affordance that would back a `?highlight={id}` link is a real frontend feature, not trivial query-param work, and is out of scope for MVPC-2); `LOCATION` from `business.address`; `extendedProperties.private.riservo_booking_id` = `booking.id` cast to string; `extendedProperties.private.riservo_business_id` for defence-in-depth cross-tenant check on inbound; start/end in UTC. Service buffers are **not** mirrored to the Google event's start/end — the customer-visible appointment runs from `starts_at` to `ends_at`, not from `effective_starts_at` to `effective_ends_at` (D-082).

**Pull sync — inbound**

22. Route: `POST /webhooks/google-calendar` (no auth, named `webhooks.google-calendar`). The new route sits outside every auth/role/onboarded group. Excluded from CSRF in `bootstrap/app.php` by adding a `validateCsrfTokens(except: ['webhooks/google-calendar'])` call.
23. `Dashboard\...\WebhookController` — or simpler: `app/Http/Controllers/Webhooks/GoogleCalendarWebhookController.php` (new controller, outside Dashboard namespace) with one `store(Request)` action. Validates `X-Goog-Channel-Id` exists and resolves to a `CalendarIntegration` (via `webhook_channel_id` column). If no match → 404. Validates `X-Goog-Channel-Token` equals `calendar_integrations.webhook_channel_token` (constant-time string compare via `hash_equals`). On mismatch → 400. On match → dispatch `PullCalendarEventsJob($integrationId, $calendarId)` and return 200 immediately. We derive `$calendarId` from the watch registry — since we `startWatch` per calendar and store the channel → calendar mapping on a new `calendar_watches` sub-table OR by storing the mapping in the integration's JSON. **Picked: add a `calendar_watches` table** (integration_id, calendar_id, channel_id, resource_id, channel_token, expires_at) — cleaner than a JSON blob because per-watch rotation is a common case and querying by `channel_id` is the hot path for the webhook. See §4.6.
24. `app/Jobs/Calendar/PullCalendarEventsJob` implements `ShouldQueue`, `Queueable`; public int `$tries = 5`; public function `backoff(): array { return [60, 120, 300, 600, 1200]; }`; constructor `(int $integrationId, string $calendarId)`. `afterCommit()`. On resolution, uses the `CalendarProviderFactory` to pull `syncIncremental($integration, $calendarId)`. Handles:
    - **410 Gone** (expired sync token) → provider throws a typed `SyncTokenExpiredException`; the job clears the stored `sync_token`, issues a forward-only full re-sync (timeMin=now), then stores the new `nextSyncToken`.
    - For each `ExternalEvent` in the result:
      - If `extendedProperties.private.riservo_booking_id` is set **and** a matching active (non-cancelled) Booking row exists **and** we are the origin (the event.creator email matches the Google-account we pushed from), **skip**. Defence against echoing our own push back as an external event.
      - If `extendedProperties.private.riservo_booking_id` is set **but** the riservo booking still exists in a pending/confirmed state **and** the Google event was cancelled → create a `riservo_event_deleted_in_google` pending action; do not cancel the booking (locked decision #5).
      - If `extendedProperties.private.riservo_booking_id` is set **and** both sides are cancelled → no-op.
      - Otherwise (foreign event): upsert a Booking row keyed by `(business_id, external_calendar_id)` — `external_calendar_id` stores the Google event ID; `source = google_calendar`, `status = confirmed`, `customer_id = firstMatchingCustomer(attendees.emails)?->id` (locked decision #6), `service_id = null`, `external_title = event.summary`, `external_html_link = event.htmlLink` (dedicated column per review-revised D-084; `internal_notes` stays admin-notes-only), `buffer_before_minutes = 0`, `buffer_after_minutes = 0`, `cancellation_token = Str::uuid()`, `provider_id = <user's provider row in the current business scope>` — resolved once per job run (the user running the integration may have multiple provider rows across businesses; for MVP, the integration is user-scoped and we target the user's first non-trashed `providers` row in the business they enabled the integration from, captured on the `CalendarIntegration` row as `integration.business_id` — see §4.5).
      - For event **overlaps with a confirmed riservo booking**: wrap the upsert in a transaction; catch Postgres `23P01` (exclusion_violation, D-066). On catch, create an `external_booking_conflict` pending action carrying the external event payload + the conflicting riservo booking IDs; rollback the upsert; continue to next event. This is the single path turning the GIST constraint into a pending action (locked decision #5 + D-065 / D-066 interaction).
      - For external events where the Google event was cancelled after a prior import — auto-cancel the corresponding `source = google_calendar` booking (no customer notification, because there may be no customer; per locked decision #7 the dispatch-site guard suppresses it anyway).
    - Persist the new `sync_token` and `last_synced_at`; clear `sync_error` / `sync_error_at` on success.
25. On **persistent error** (every retry exhausted): `failed()` writes `sync_error` + `sync_error_at`, which the settings page surfaces as the `error` prop (already wired in MVPC-1).

**Webhook renewal**

26. Artisan command `calendar:renew-watches` (class `app/Console/Commands/RenewCalendarWatches.php`). Iterates `calendar_watches` where `expires_at < now()->addDay()`; for each, calls `stopWatch` (best-effort) then `startWatch` and rewrites the row. `Schedule::command('calendar:renew-watches')->dailyAt('03:00');` in `routes/console.php`.

**Pending actions UI**

27. Dashboard section `dashboard/calendar-pending-actions` — renders inline on `/dashboard` as a dismissable section above the existing content. **Visibility is narrower than the dashboard group itself**: a pending-action row is shown to user U only if `action.integration.user_id === U->id` (U owns the integration that produced the action) OR U is an admin of the business. Rationale: staff X's pending action can cancel a customer-facing booking, dispatch a cancellation email, and call `deleteEvent` against X's Google calendar; staff Y should not be able to trigger any of that on X's behalf. Admins retain oversight because they own the business (this is a clean extension of D-085 / D-088). Count badge in settings nav "Calendar Integration (N)" reflects the viewer-scoped count, not the business-wide count. Each row exposes the allowed action buttons:
    - `riservo_event_deleted_in_google`: "Cancel booking and notify customer" (transitions the booking to cancelled, dispatches `BookingCancelledNotification(cancelledBy: 'business')`, marks action resolved) | "Keep booking and dismiss" (no status change, marks action dismissed).
    - `external_booking_conflict`: "Keep both" (dismiss; external event remains in riservo as if the GIST constraint didn't fire — **this requires a manual override path**; see §4.7) | "Cancel external event" (call `deleteEvent` on the provider, mark action resolved) | "Cancel riservo booking" (status → cancelled, dispatch `BookingCancelledNotification(cancelledBy: 'business')` to the customer, mark action resolved).
28. `POST /dashboard/calendar-pending-actions/{action}/resolve` with a `choice` enum field. Controller `Dashboard\CalendarPendingActionController::resolve` — single controller, one method (small action set keeps split-controllers premature).
29. Shared Inertia prop `calendarPendingActionsCount: number` published from `HandleInertiaRequests::share`, **scoped per viewer** per the visibility rule in step 27: for admins, count = `business.calendarPendingActions.pending.count()`; for staff, count = `business.calendarPendingActions.where(integration.user_id, U->id).pending.count()`. Rendered as a badge next to "Calendar Integration" in the nav and next to the Pending Actions section header.

**Slot generator + UI**

30. `SlotGeneratorService`: the authoritative overlap check (`conflictsWithBookings`) already reads `effective_starts_at` / `effective_ends_at` raw columns (D-066 — these are generated columns populated from `buffer_*_minutes`; external bookings store both as 0 so the generated columns equal `starts_at` / `ends_at`). No functional change needed there. BUT `getBlockingBookings` eager-loads `->with('service')`, and downstream (notification render, booking-list serialisation, booking detail panel controllers) every read of `$booking->service->*` assumes non-null. Short-cut: add a tiny null-tolerant accessor `Booking::serviceLabel(): string` returning `$this->service?->name ?? $this->external_title ?? __('External event')` and use it in the controllers we ship serialisation for this session. Notification classes remain untouched because the dispatch-site guards ensure they're never called with a `google_calendar` booking (enforced by test — §5).
31. Booking detail panel / list serializer: when `source = google_calendar`, return shape with `external: true`, `external_title`, `external_html_link` (read from the dedicated `bookings.external_html_link` column — D-084 revised), hide customer/service blocks. The frontend branches on `external: true` to render the "External Event" variant.
32. Calendar views (`week-view`, `day-view`, `month-view`): when a booking has `external: true`, render with a lucide `CalendarDays` icon inside the event card and a neutral colour (`bg-muted/70 text-muted-foreground border-border`) visually distinct from riservo bookings (which use the business accent colour). Keep click behaviour identical. See D-084.
33. Bookings list (`dashboard/bookings`): add a filter toggle "Include external events" — default on. Backend accepts `?include_external=0` to exclude `source = google_calendar` rows.

**Notification suppression**

34. All existing `BookingConfirmedNotification`, `BookingCancelledNotification`, `BookingReceivedNotification`, `BookingReminderNotification` dispatch sites guard with `if (! $booking->shouldSuppressCustomerNotifications())`. The helper on `Booking` returns `$this->source === BookingSource::GoogleCalendar`. Staff notifications (`Notification::send($staffUsers, ...)`) also guard — staff shouldn't get "new booking received" spam for every Google-side event.
35. `SendBookingReminders` command adds `->where('source', '!=', BookingSource::GoogleCalendar->value)` to the candidate query (query-time, cheaper than per-row guard; the dispatch-site guard still runs but never fires).

**Settings page (extends MVPC-1)**

36. Connected state: account email, "Destination calendar: {name}", "Conflict calendars: {names, comma-separated}" with an inline "Change" button that reopens the configuration form in edit mode (reuses the POST /configure endpoint); `last_synced_at` formatted in business TZ, `Disconnect` button, pending-actions badge linking to the dashboard section.
37. Error state: `<Alert variant="error">` driven by the `error` prop, which is populated from `integration.sync_error`. "Reconnect" CTA reopens the Socialite flow (same POST /connect route).
38. "Sync now" button triggers `POST /dashboard/settings/calendar-integration/sync-now` which dispatches `PullCalendarEventsJob` for each configured calendar. Idempotent.

**Tests + ops**

39. Feature tests (see §5 for full list). Cover: OAuth configure step, push dispatch matrix per lifecycle transition, push job behaviour (no-op on google_calendar source, no-op on missing integration, `external_calendar_id` persisted on create, 3-retry delete semantics), webhook validation (valid / missing channel / bad token / unknown channel), pull sync upsert / skip-own-event / cancel / conflict-becomes-pending / 410-fallback, webhook renewal command, notification suppression across every dispatch site, slot generator with null-service external booking blocking slots, pending-actions resolution (all five choices across both types), calendar:renew-watches renews imminent expiries.
40. Update `docs/DEPLOYMENT.md` with Google Cloud Console OAuth setup, redirect URI exact-match, webhook endpoint HTTPS requirement, local tunnel guidance, queue worker requirement for the two jobs, `calendar:renew-watches` cron entry, new env var inventory (`GOOGLE_CLIENT_ID`, `GOOGLE_CLIENT_SECRET`, `GOOGLE_REDIRECT_URI` — all already present from MVPC-1 — plus the webhook URL that must match the Google Cloud verified domain).

### Explicitly out of scope

- Settings → Account and Settings → Availability pages — Session 4.
- Drag / resize / reschedule on the calendar — Session 5. The reschedule dispatch site will be wired to `PushBookingToCalendarJob` in Session 5 using the exact same helper (`Booking::shouldPushToCalendar()`).
- Outlook / Apple Calendar — post-MVP. The `CalendarProvider` interface design is the only contract we must hold to; adding a provider is purely additive (new class + factory binding + migration to allow a new `provider` string). Explicitly verified: no `google`-specific string leaks into `Booking`, `SlotGeneratorService`, or any notification.
- Group bookings — post-MVP. The inbound-sync attendee handling is deliberately shallow (first-match-by-email, null customer otherwise) so upgrading to multi-customer bookings later is a pure data-model change, not a sync change.
- ICS one-way feed — deferred to BACKLOG.
- Cashier billing — Session 3.

## 4. Key design decisions

### 4.1 `CalendarProvider` interface location and shape

Put the interface at `app/Services/Calendar/CalendarProvider.php`; the concrete Google implementation at `app/Services/Calendar/GoogleCalendarProvider.php`; the factory at `app/Services/Calendar/CalendarProviderFactory.php`; DTOs under `app/Services/Calendar/DTOs/`. Bind the factory in `AppServiceProvider::register()` as a singleton. Consumers depend on the interface or the factory, never on `GoogleCalendarProvider` directly. Record as **D-082**.

Interface methods are the eight listed in §3 step 8. Specifically rejected: a single `syncAll()` method, or a `Google\Client`-typed argument anywhere in the interface. Both would leak Google into the contract.

### 4.2 Token-refresh round-trip

`Google\Client` exposes `setTokenCallback($callable)` which is invoked whenever the SDK rotates an access token. The callback must persist the rotated token + the new expiry to `calendar_integrations`. Implement inside a `GoogleClientFactory` (injected into `GoogleCalendarProvider`) that builds a client per integration with the callback bound to `fn (string $cacheKey, string $accessToken) => $this->persist($integration, $accessToken)`. Without this, every SDK-driven refresh silently leaks the new token and the next call with the stored (stale) one fails. Covered by a dedicated test in §5.

### 4.3 External booking schema rationale (locked decision #2)

`customer_id` + `service_id` → nullable; `external_title` string nullable; `provider_id` stays non-nullable. Rationale: D-067 / D-066 both key on `provider_id`; keeping it non-nullable means the GIST exclusion constraint and the historical-provider resolution continue to work untouched for external bookings. A Google event by definition came from **a specific provider's Google calendar**, so the non-nullability models the world correctly.

Downstream: every read of `$booking->service->*` / `$booking->customer->*` in serialisers and notifications must be audited. Notification classes are safe because of the dispatch-site guard (no `google_calendar` booking reaches them). Serialisers / Inertia props are the other surface; each is addressed in §3 step 31.

### 4.4 `PushBookingToCalendarJob` signature — one job, three actions

Picked: **single job**, `(int $bookingId, string $action)`. Rationale:
- The three actions share the retry policy, the two gate conditions, the integration-lookup path, and the failure handling. Splitting would duplicate all four.
- Action-specific behaviour (`create` returns an event ID that must be stored; `delete` reads from `external_calendar_id`) is a `match` inside `handle()` — 10 lines, not enough to motivate a class hierarchy.
- The dispatch sites name the intent clearly: `PushBookingToCalendarJob::dispatch($booking->id, 'create')`. Readability equal to three named jobs.

Edge case: a booking that was created `pending` (no push until confirmed — see step 19), then confirmed via admin action, then cancelled. On the `confirmed` transition the job runs with action=`create` (first push), receives the Google event ID, stores it. On the `cancelled` transition the job runs with action=`delete` using the freshly-stored event ID. Order is guaranteed by the queue being FIFO within the same connection; both jobs are `afterCommit()`, so the DB state is consistent at each dispatch.

Alternative considered and rejected: three jobs. Rejected because all three need identical plumbing and the `action` enum is small and stable.

Record as **D-083**.

### 4.5 Provider resolution for external events

`CalendarIntegration` is **user-scoped**, not business-scoped (D-080 consequences). But every riservo booking needs a `provider_id`, which is business-scoped. Inbound external events must resolve to one specific `providers` row.

Picked: add `business_id` (FK nullable → businesses) to `calendar_integrations`, populated at configuration-step time from the user's currently-active tenant. This pins the integration to a single business — a user with two business memberships who wants to sync to both is a post-MVP workflow (explicitly in the carry-overs — R-2B business-switcher UI). MVP: one integration per user, one business. The configure step shows "Syncing to {business name}" so the user knows which business the integration targets. `PullCalendarEventsJob` resolves the provider via `$integration->business->providers()->where('user_id', $integration->user_id)->firstOrFail()`. If the user lost their provider row (e.g., admin deactivated them), the job raises a typed exception, marks `sync_error`, and stops — the pending-actions UI would display nothing since the integration itself is broken. Record as **D-085**.

Migration: `ALTER TABLE calendar_integrations ADD COLUMN business_id bigint NULL REFERENCES businesses(id) ON DELETE CASCADE`. Populated during the configure step; null means "MVPC-1 state, configuration not yet done".

### 4.6 Webhook bookkeeping — `calendar_watches` sub-table

A user with a destination calendar + three conflict calendars is four separate watches. Each watch has its own channel ID, resource ID, channel token (per-watch secret), and expiry. Options:

- **(a)** JSON column `watches` on `calendar_integrations`.
- **(b)** Dedicated `calendar_watches` table with `(integration_id, calendar_id, channel_id unique, resource_id, channel_token, expires_at)`.

Picked **(b)**. The webhook handler's hot path queries by `channel_id`; a unique index on `calendar_watches.channel_id` is faster and cleaner than a JSON lookup. Renewal iterates rows, not JSON array elements. Channel rotation (watches are capped ~30 days and must be stopped+restarted) is a per-row update, not a JSON splice. Record as **D-086**.

Migration: `create_calendar_watches_table` with the columns above, FK cascade on `integration_id`. Unique on `channel_id`.

The MVPC-1 migration's `webhook_channel_id`, `webhook_channel_token`, `webhook_expiry`, `webhook_resource_id` columns on `calendar_integrations` become unused after this session. I'll keep them (don't drop — zero-risk) and document that they are reserved for potential single-watch reuse in a future simplification. Rationale: dropping would be a schema churn on the same table we're already extending; leaving them nullable-unused is a 4-byte cost and zero test risk. The CalendarIntegrationFactory will stop writing to them.

### 4.7 Pending-action "Keep both" override

`external_booking_conflict` with choice = "Keep both" is the only place in the system where the GIST constraint is bypassed. Implementation: wrap the "keep both" resolver in a `DB::transaction` that `DELETE`s any blocking confirmed booking **temporarily** — no, that's not viable. Re-thinking.

Correct implementation: the inbound pull job never actually persists the conflicting external event on 23P01 — it rolls back and creates the pending action with the external-event payload (summary, start, end, event ID, calendar ID). The "Keep both" choice must then either:
  - **(i)** Insert the external booking anyway, accepting the constraint violation — impossible per the DB invariant.
  - **(ii)** Re-interpret: "Keep both" means "keep the riservo booking AS-IS, and accept that the external event will remain in Google unchanged but will not materialise as a riservo booking row". The external event is visible via the user's Google Calendar directly; riservo only reflects bookings that don't conflict with its own invariant.

Picked **(ii)**. This respects the D-065 / D-066 invariant unconditionally. The resolution text on the button is updated to: "**Keep riservo booking (ignore external)**" — clearer than "Keep both" for what actually happens. The external Google event is unaffected; only the riservo-side materialisation is skipped. Record as **D-087**.

### 4.8 External-event visuals and "Open in Google Calendar"

Icon: lucide-react `CalendarDays` placed next to the event title with `aria-label="External event"`. Rationale: Google brand-asset usage rules require specific spacing and unaltered aspect ratio for the "G" mark, which is a design burden for an inline 12×12 UI element; the generic calendar icon avoids the licensing/attribution surface while still being a recognisable "this is from somewhere else" signal. Colour: `bg-muted/70 text-muted-foreground border border-border` — the existing neutral COSS UI palette, distinct from the primary accent used by riservo bookings. Verified against `resources/css/theme.css` that these tokens resolve to a clearly different tone than the accent.

"Open in Google Calendar": event `htmlLink` is stored in a dedicated `bookings.external_html_link` column (string, nullable) alongside the existing external-event companions `external_calendar_id` and `external_title`. The detail panel renders it as an external link icon next to the external title. Rejected: overloading `bookings.internal_notes` (admin-written notes) to carry a URL — it would mix concerns, and a future Outlook/CalDAV provider would inherit the same overload or force a migration. The dedicated column is consistent with the two external-event columns already in the schema, not drift. Record as **D-084**.

### 4.9 Notification suppression — exact dispatch-site matrix

Audit of every Booking-related Notification in the codebase (from §Read step 10 of the brief + grep confirmation):

| Dispatch site | File | Line | Notification | Guard required |
|---|---|---|---|---|
| Public booking creation | `Booking/PublicBookingController.php` | 330 | `BookingConfirmedNotification` (customer) | Yes — but `store()` never sets `source = google_calendar`, so guard is defence-in-depth. |
| Public booking creation | same | 353 (`notifyStaff`) | `BookingReceivedNotification` (staff) | Yes — defence-in-depth. |
| Dashboard manual booking | `Dashboard/BookingController.php` | 335 | `BookingConfirmedNotification` | Defence-in-depth. |
| Dashboard manual booking | same | 337 (`notifyStaff`) | `BookingReceivedNotification` | Defence-in-depth. |
| Dashboard status change | same | 194 | `BookingConfirmedNotification` | Real guard — a google_calendar booking promoted to confirmed (rare but possible if admin manually resets) must not notify. |
| Dashboard status change | same | 196 (`notifyStaff`) | `BookingReceivedNotification(context='confirmed')` | Real guard. |
| Dashboard status change | same | 201 | `BookingCancelledNotification` | Real guard — pending-actions "Cancel riservo booking" path goes through `updateStatus` and must not notify if source=google_calendar. But wait — the PendingActions resolver branches; for external events that came from Google and are now being cancelled, customer_id may be null and there's no one to notify anyway. Guard still applies. |
| Customer cancel | `Customer/BookingController.php` | 69 (`Notification::send`) | `BookingCancelledNotification` | Real guard — a customer user cannot reach a `google_calendar` booking (different customer attachment), but the guard keeps the rule uniform. |
| Public (token-based) cancel | `Booking/BookingManagementController.php` | 75 | `BookingCancelledNotification` | Real guard. |
| Reminder command | `Console/Commands/SendBookingReminders.php` | 83 | `BookingReminderNotification` | Guard at query time (`->where('source', '!=', ...)`). |

The dispatch-site guards are wrapped in a single helper: `Booking::shouldSuppressCustomerNotifications(): bool` returning `$this->source === BookingSource::GoogleCalendar`. Every guard is `if (! $booking->shouldSuppressCustomerNotifications()) { Notification::...->notify(...); }`. Note: **staff** notifications (the `notifyStaff` helpers) also get guarded — the rationale is that a Google-origin event doesn't generate "new booking received" staff mail (the owner already sees it in their Google Calendar). All of this is one line per dispatch site.

Record the audit + guard strategy as **D-088**.

### 4.10 Migration ordering and backfill

Two migrations that mutate existing data:

- `bookings.customer_id` + `bookings.service_id` → nullable: no backfill (existing rows already non-null, nothing to migrate). The GIST constraint is unaffected (it keys on `provider_id`). The factory default stays non-null (factories pass real Customer+Service rows), but the test suite gains a `$factory->external()` state that sets both to null + adds an `external_title` + `external_html_link`.
- `bookings.external_title`: nullable string column, no backfill.
- `bookings.external_html_link`: nullable string column, no backfill.

The configure-step migration set (`calendar_integrations` extension + `calendar_pending_actions` + `calendar_watches` + `calendar_integrations.business_id`) is additive. No pre-existing rows need backfilling — MVPC-1 rows all have `destination_calendar_id = NULL`, which correctly puts them in the "needs configuration" state; if any exist in dev they re-enter the configure flow on next visit.

Migration files, in numeric order:

- `2026_04_17_100000_make_booking_customer_and_service_nullable_add_external_fields.php` (nullable customer_id + service_id, plus `external_title` and `external_html_link`)
- `2026_04_17_100001_extend_calendar_integrations_for_sync.php` (includes `business_id` per D-085; see §3 step 2 for the full column list)
- `2026_04_17_100002_create_calendar_watches_table.php`
- `2026_04_17_100003_create_calendar_pending_actions_table.php`

### 4.11 Pint, Wayfinder, types

Pint is run at the end (`--dirty --format agent`). Wayfinder is run **before** `npm run build` so the new controller actions (configuration, sync-now, webhook stub — although the webhook controller will be excluded from Wayfinder generation since it's not dashboard-facing) are picked up by the TS bundle. No TypeScript types for the webhook endpoint.

All new user-facing strings go through `__()` / `useTrans().t(...)`. Inertia partial reloads use `only: ['calendarPendingActions']` on the dashboard page so resolving an action doesn't re-fetch unchanged props.

## 5. Implementation steps, in order

Each numbered cluster is one logical commit. Cluster 12 (tests + docs) is the closing pass.

### Cluster 1 — schema

1. `make:migration` × 4 per §4.10. Each migration has a reverse-safe `down()`.
2. `php artisan migrate`.

### Cluster 2 — models and enums

3. Enums: `app/Enums/PendingActionType.php`, `app/Enums/PendingActionStatus.php`.
4. `Booking` model: add `external_title` to fillable; add `shouldSuppressCustomerNotifications()`, `shouldPushToCalendar()`, `serviceLabel()` helpers.
5. `CalendarIntegration` model: extend fillable + casts; add `isConfigured()`, `business()` BelongsTo, `watches()` HasMany, `pendingActions()` HasMany.
6. New `PendingAction` model + factory; BelongsTo `business`, `integration`, `booking`, `resolvedByUser`.
7. New `CalendarWatch` model + factory; BelongsTo `integration`. **No fillable attributes are Inertia-serialised** — the watch row is internal bookkeeping.

### Cluster 3 — provider abstraction

8. DTOs: `CalendarSummary`, `WatchResult`, `ExternalEvent`, `SyncResult` — readonly classes.
9. `CalendarProvider` interface.
10. `GoogleClientFactory` — constructs a `Google\Client` for a given `CalendarIntegration`; sets scopes, access token, refresh token, redirect URI; binds `setTokenCallback` to persist-to-row.
11. `GoogleCalendarProvider` implementing the interface. Every method maps SDK exceptions to typed domain exceptions (`SyncTokenExpiredException` on 410, `ChannelExpiredException` on 404 during stopWatch, etc.).
12. `CalendarProviderFactory::for(CalendarIntegration $integration): CalendarProvider` — singleton, bound in `AppServiceProvider`.

### Cluster 4 — push pipeline

13. `app/Jobs/Calendar/PushBookingToCalendarJob` per §3 step 16.
14. Dispatch sites gated via `Booking::shouldPushToCalendar()`:
    - `PublicBookingController::store` — after `Booking::create`, dispatch action='create'.
    - `Dashboard\BookingController::store` — after `Booking::create`, action='create'.
    - `Dashboard\BookingController::updateStatus` — dispatch with action mapped per allowed transition (table in §4.4).
    - `Customer\BookingController::cancel` — action='delete'.
    - `BookingManagementController::cancel` — action='delete'.
15. Guard each dispatch with `if ($booking->shouldPushToCalendar()) { PushBookingToCalendarJob::dispatch($booking->id, $action); }`.

### Cluster 5 — notification suppression

16. Add `Booking::shouldSuppressCustomerNotifications()` guard at each of the 10 dispatch sites (both customer and staff notifications, per the D-088 table).
17. `SendBookingReminders::handle()` adds `->where('source', '!=', BookingSource::GoogleCalendar->value)` to the candidate query.

### Cluster 6 — pull pipeline

18. `app/Http/Controllers/Webhooks/GoogleCalendarWebhookController@store` — validates `X-Goog-Channel-Id`, `X-Goog-Channel-Token`; dispatches `PullCalendarEventsJob`; returns 200.
19. Route in `routes/web.php` OUTSIDE all auth groups: `Route::post('/webhooks/google-calendar', [GoogleCalendarWebhookController::class, 'store'])->name('webhooks.google-calendar');`.
20. `bootstrap/app.php` — add `->validateCsrfTokens(except: ['webhooks/google-calendar'])` to the `withMiddleware` closure.
21. `app/Jobs/Calendar/PullCalendarEventsJob` per §3 step 24. Key logic: 410-fallback, upsert-with-23P01-catch, pending-action creation on overlap and on riservo-event-deleted-in-google.
22. `StartCalendarSyncJob` — called from configure-step finalisation; loops over distinct calendars in `[destination, ...conflicts]`, calls `startWatch` + writes `calendar_watches` row + `PullCalendarEventsJob::dispatch` for an initial forward-only sync.

### Cluster 7 — configure step + disconnect extension

23. Extend `CalendarIntegrationController`:
    - `callback()` — redirect to `settings.calendar-integration.configure` on success (instead of settings index).
    - `configure(Request)` GET — renders `dashboard/settings/calendar-integration-configure` Inertia page with `calendars: array`, `preview: { importCount, conflictingEvents }`, `currentDestination: string | null`, `currentConflicts: string[]`. Uses `$factory->for($integration)->listCalendars($integration)`.
    - `saveConfiguration(Request)` POST — validates destination, conflicts, optional `create_new_calendar_name`; persists; dispatches `StartCalendarSyncJob`; redirects to settings index with success.
    - `syncNow(Request)` POST — dispatches `PullCalendarEventsJob` per watched calendar; redirects with flash.
    - `disconnect(Request)` — iterate `$integration->watches` and call `$provider->stopWatch(...)` in try/catch per row; delete `$integration` (cascade deletes `calendar_watches` and `calendar_pending_actions`).
24. Routes added to the existing shared settings group (no middleware changes):
    - `GET /calendar-integration/configure` → `configure` (name `settings.calendar-integration.configure`)
    - `POST /calendar-integration/configure` → `saveConfiguration` (name `settings.calendar-integration.save-configuration`)
    - `POST /calendar-integration/sync-now` → `syncNow` (name `settings.calendar-integration.sync-now`)

### Cluster 8 — pending actions

25. `Dashboard\CalendarPendingActionController::resolve(Request, PendingAction)` — first authorises: `abort_unless($action->business_id === tenant()->business()->id, 404)` for tenant scoping, then `abort_unless(tenant()->role() === BusinessMemberRole::Admin || $action->integration->user_id === $request->user()->id, 403)` for owner-or-admin per step 27. Then validates `choice`, branches per `type`:
    - `riservo_event_deleted_in_google` + choice=`cancel_and_notify`: booking → cancelled, `BookingCancelledNotification(cancelledBy='business')` dispatched (this is the ONE place a google_calendar-related cancel notifies — but wait: the riservo booking is a riservo-originated booking that Google deleted. Its `source` is still `riservo` because the booking was a riservo-originated push. The guard therefore correctly allows the notification. Confirm in tests.), action.status=`resolved`.
    - `riservo_event_deleted_in_google` + choice=`keep_and_dismiss`: action.status=`dismissed`.
    - `external_booking_conflict` + choice=`keep_riservo_ignore_external`: action.status=`dismissed`. (Per D-087 rename.)
    - `external_booking_conflict` + choice=`cancel_external`: `$provider->deleteEvent($integration, $calendarId, $externalEventId)`; action.status=`resolved`.
    - `external_booking_conflict` + choice=`cancel_riservo_booking`: booking → cancelled, `BookingCancelledNotification(cancelledBy='business')` dispatched (source=`riservo`, guard allows), action.status=`resolved`. **Then dispatch `PullCalendarEventsJob($integration->id, $calendarId)` for the calendar that produced the conflict** — the calendar id is already persisted in the action's `payload`. Rationale: with the riservo booking cancelled, the GIST constraint no longer blocks the external event from materialising, but the external event only lives in Google until the next webhook fires for that calendar (potentially minutes away). Re-dispatching the pull job is how the admin's decision to "let the external event materialise" is honoured promptly and deterministically (D-087).
26. Route: `POST /dashboard/calendar-pending-actions/{action}/resolve` (name `dashboard.calendar-pending-actions.resolve`), under the admin+staff dashboard group (no inner middleware).
27. `HandleInertiaRequests::share` — `calendarPendingActionsCount` prop, viewer-scoped per §3 step 27: admin sees the business-wide pending count; staff sees only the count of pending actions whose integration's `user_id` equals their own user id. The scoping logic lives in a private helper on the middleware so the test can assert both branches directly.
28. Dashboard section component: `resources/js/components/dashboard/calendar-pending-actions-section.tsx` renders the list with per-row action buttons as Inertia `<Form>` elements. Count badge in `settings-nav.tsx` uses the shared prop.

### Cluster 9 — slot generator + UI touch-ups

29. `SlotGeneratorService::getBlockingBookings` — eager-load changed from `->with('service')` to `->with(['service', 'customer'])` — actually, no: `conflictsWithBookings` reads only `effective_starts_at`/`effective_ends_at`, which are DB columns, not relations. So eager-loading `service` is not needed for the overlap check. Drop the `->with('service')` to avoid the `$booking->service->*` nullability footgun and to reduce query cost. If anything downstream relied on the eager load (audit shows nothing does — `getBlockingBookings` is only called from `getSlotsForProvider`), no change. **This is a small incidental cleanup** — call out in the plan. See §4.3 for full audit.
30. Add `Booking::serviceLabel(): string` as above. Use in `CalendarController`, `DashboardController`, `CustomerController`, `BookingController` (dashboard), `BookingManagementController`, `CustomerController` bookings serialiser — only at sites that read `$booking->service->name` at the serialisation layer. Existing tests confirm which sites we actually ship.
31. Calendar views (React): `week-view.tsx`, `day-view.tsx`, `month-view.tsx` accept an `external: boolean` property on each event and branch rendering for the CalendarDays icon + muted tone.
32. Bookings list: add `?include_external=0` filter support in `Dashboard\BookingController::index`. Default = include.

### Cluster 10 — webhook renewal

33. `app/Console/Commands/RenewCalendarWatches` command. Iterates `CalendarWatch::where('expires_at', '<', now()->addDay())` with `integration` eager-loaded; for each, `$provider->stopWatch` in try/catch, then `$provider->startWatch` and update the row.
34. `routes/console.php` — `Schedule::command('calendar:renew-watches')->dailyAt('03:00');`.

### Cluster 11 — settings page polish

35. `calendar-integration.tsx` index page: connected state now displays destination + conflict summary + last_synced_at + sync-now button + pending-actions badge.
36. `calendar-integration-configure.tsx` new page: listCalendars-driven dropdown + multi-select + preview.

### Cluster 12 — tests, Wayfinder, Pint, docs

37. Write tests (§5).
38. `php artisan wayfinder:generate`.
39. `vendor/bin/pint --dirty --format agent`.
40. `npm run build`.
41. Update `docs/DEPLOYMENT.md`, `docs/HANDOFF.md`. Move plan file to archive. Record decisions D-082…D-088.

## 6. Tests

New and extended Pest tests — the minimum set required to cover every locked decision and every D-082…D-088. Feature path = `tests/Feature`; the iteration loop is `php artisan test tests/Feature tests/Unit --compact`.

### `tests/Feature/Calendar/PushBookingToCalendarJobTest.php` (new)

- Dispatch on booking create pushes the event and stores the returned Google event ID in `bookings.external_calendar_id`.
- Dispatch on cancel removes the Google event.
- Dispatch is skipped when `$booking->source === BookingSource::GoogleCalendar` (covers locked decision: we never push our inbound imports back out).
- Dispatch is skipped when the provider's user has no `CalendarIntegration` OR `destination_calendar_id` is null.
- On permanent failure, the job's `failed()` writes `push_error` + `push_error_at` on the integration.
- Token refresh callback persists rotated tokens (integration test against a mocked `Google\Client` surface that fires the callback).

### `tests/Feature/Calendar/PullCalendarEventsJobTest.php` (new)

- Uses a `FakeCalendarProvider` implementing `CalendarProvider` with programmable `syncIncremental` output, bound in `$this->app->bind(CalendarProvider::class, ...)` for the test. 
- Inserts an external event with no `riservo_booking_id` → creates a Booking row with `source = google_calendar`, `customer_id = null`, `service_id = null`, `external_title = event.summary`, `provider_id = <user's provider in business>`.
- Inserts an external event with attendees.emails matching an existing Customer → links the first matching customer (locked decision #6); unmatched emails are silently ignored.
- Inserts an external event that overlaps a confirmed riservo booking → creates an `external_booking_conflict` pending action + no Booking row (per §4.7).
- Skips events with `extendedProperties.private.riservo_booking_id` matching an active riservo booking (defence against our own push).
- A riservo-originated event whose Google side was deleted → `riservo_event_deleted_in_google` pending action, riservo booking stays confirmed.
- A cancelled external event whose prior import exists → the google_calendar booking transitions to cancelled (no notification — verified by Notification::fake assertion).
- 410 Gone on `syncIncremental` → clears stored sync_token, re-runs forward-only, persists the new token.
- Persistent-error path writes `sync_error` + `sync_error_at`.

### `tests/Feature/Calendar/GoogleCalendarWebhookControllerTest.php` (new)

- Valid channel + token → 200 + job dispatched (Queue::fake).
- Missing `X-Goog-Channel-Id` → 400.
- Unknown `X-Goog-Channel-Id` → 404.
- Valid channel + wrong `X-Goog-Channel-Token` → 400.
- CSRF is NOT enforced on the route (test via a plain POST without a session CSRF token).

### `tests/Feature/Calendar/CalendarIntegrationConfigureTest.php` (new)

- Admin after OAuth callback is redirected to the configure route (not the settings index).
- Configure page renders with `calendars` + `preview` props populated from the bound fake provider.
- `saveConfiguration` with a valid destination + conflict set dispatches `StartCalendarSyncJob`, persists the row, and redirects to the settings index.
- `saveConfiguration` with `create_new_calendar_name` set invokes `createCalendar` on the fake provider.
- `sync-now` dispatches `PullCalendarEventsJob` for each watched calendar.

### `tests/Feature/Calendar/CalendarPendingActionResolutionTest.php` (new)

- `riservo_event_deleted_in_google` + `cancel_and_notify` cancels the booking AND dispatches `BookingCancelledNotification` (the booking's source is `riservo` — guard allows).
- `riservo_event_deleted_in_google` + `keep_and_dismiss` leaves the booking pending+confirmed, marks action dismissed.
- `external_booking_conflict` + `keep_riservo_ignore_external` marks action dismissed, no DB mutation, no notification.
- `external_booking_conflict` + `cancel_external` calls the provider's `deleteEvent`, marks action resolved.
- `external_booking_conflict` + `cancel_riservo_booking` (a) cancels the riservo booking, (b) dispatches `BookingCancelledNotification(cancelledBy='business')`, (c) **dispatches `PullCalendarEventsJob` for the source calendar** (assert via `Queue::fake()` that the job class + args match, including the `calendarId` taken from the action payload), (d) marks action resolved.
- Authorization: staff and admin can resolve (admin+staff shared route); customer and guest cannot.
- **Owner-or-admin authorization** (per patched §27 + D-085 extension): the staff user who owns the integration (`action.integration.user_id`) can resolve their own action; a different staff user in the same business gets 403; any admin in the business can resolve any action.
- Tenant scoping: a user in business A cannot resolve a pending action belonging to business B (404).

### `tests/Feature/Calendar/RenewCalendarWatchesCommandTest.php` (new)

- Watches with `expires_at < now + 24h` are refreshed (stopWatch then startWatch + row update).
- Watches further in the future are untouched.
- `stopWatch` failure is swallowed; `startWatch` still runs.

### `tests/Feature/Calendar/NotificationSuppressionTest.php` (new)

- Creating a `source = google_calendar` booking dispatches zero notifications (neither customer nor staff) from every dispatch site (public booking, dashboard manual, status transitions). Exercised as a dataset — one test × N dispatch sites.
- `SendBookingReminders::handle()` never enqueues a reminder for `source = google_calendar`.

### `tests/Feature/Calendar/SlotGenerationWithExternalBookingTest.php` (new)

- A `source = google_calendar` booking with `service_id = null` and `buffer_*_minutes = 0` blocks the exact time window for `SlotGeneratorService::getAvailableSlots`, matching what the GIST constraint would enforce on write.
- Slot generation does not crash on the null service (regression test for the audited null-service path).

### Extended tests

- `tests/Feature/Settings/CalendarIntegrationTest.php` — extend: callback-success now redirects to configure (not index). Existing assertions stay except the redirect target. Disconnect test: when `calendar_watches` rows exist, they are cleaned up and `stopWatch` is called once per row (Queue::fake or provider spy).
- `tests/Feature/Booking/*Test.php` — existing tests MUST keep passing. Factory default stays non-null for customer+service. The Booking factory gains a `->external()` state used only by the new tests.
- `tests/Feature/Dashboard/ManualBookingTest.php` — existing green; extend with one case that verifies push dispatch fires with action=`create` when the acting admin has a configured integration.
- `tests/Feature/Commands/SendBookingRemindersTest.php` — extend with one case: `source = google_calendar` booking is never candidate-selected.
- `tests/Feature/Models/UserRelationshipTest.php` — existing `it has one calendar integration` unchanged.

### Pre-existing test contracts that evolve

- `tests/Feature/Settings/CalendarIntegrationTest.php::callback persists the integration with tokens encrypted at rest` — redirect target changes from `settings.calendar-integration` to `settings.calendar-integration.configure`. Genuine contract evolution (the callback no longer finalises the integration; the configure step does).
- `tests/Feature/Settings/CalendarIntegrationTest.php::disconnect deletes the row` — now also asserts `$provider->stopWatch` was called once per watch row.

No existing tests are expected to break beyond these two. Schema-wise, making `bookings.customer_id` + `service_id` nullable doesn't break any test because every existing factory call passes concrete values. If the suite surfaces an unexpected failure during implementation, the failing test's assertion is preserved and the implementation is adjusted — not the other way around — unless the failure is a real contract evolution (listed above).

## 7. Files to create / modify

### Create

- `database/migrations/2026_04_17_100000_make_booking_customer_and_service_nullable_add_external_fields.php`
- `database/migrations/2026_04_17_100001_extend_calendar_integrations_for_sync.php`
- `database/migrations/2026_04_17_100002_create_calendar_watches_table.php`
- `database/migrations/2026_04_17_100003_create_calendar_pending_actions_table.php`
- `app/Enums/PendingActionType.php`
- `app/Enums/PendingActionStatus.php`
- `app/Models/PendingAction.php`
- `app/Models/CalendarWatch.php`
- `database/factories/PendingActionFactory.php`
- `database/factories/CalendarWatchFactory.php`
- `app/Services/Calendar/CalendarProvider.php`
- `app/Services/Calendar/GoogleCalendarProvider.php`
- `app/Services/Calendar/GoogleClientFactory.php`
- `app/Services/Calendar/CalendarProviderFactory.php`
- `app/Services/Calendar/DTOs/CalendarSummary.php`
- `app/Services/Calendar/DTOs/WatchResult.php`
- `app/Services/Calendar/DTOs/ExternalEvent.php`
- `app/Services/Calendar/DTOs/SyncResult.php`
- `app/Services/Calendar/Exceptions/SyncTokenExpiredException.php`
- `app/Services/Calendar/Exceptions/UnsupportedCalendarProviderException.php`
- `app/Jobs/Calendar/PushBookingToCalendarJob.php`
- `app/Jobs/Calendar/PullCalendarEventsJob.php`
- `app/Jobs/Calendar/StartCalendarSyncJob.php`
- `app/Console/Commands/RenewCalendarWatches.php`
- `app/Http/Controllers/Webhooks/GoogleCalendarWebhookController.php`
- `app/Http/Controllers/Dashboard/CalendarPendingActionController.php`
- `resources/js/pages/dashboard/settings/calendar-integration-configure.tsx`
- `resources/js/components/dashboard/calendar-pending-actions-section.tsx`
- Test files per §6.

### Modify

- `app/Models/Booking.php` — fillable +`external_title`, +`external_html_link`; helpers `shouldPushToCalendar`, `shouldSuppressCustomerNotifications`, `serviceLabel`.
- `app/Models/CalendarIntegration.php` — fillable + casts + helpers + relations.
- `database/factories/BookingFactory.php` — `external()` state (populates `source = google_calendar`, `customer_id = null`, `service_id = null`, `external_title`, `external_html_link` with a faker URL).
- `database/factories/CalendarIntegrationFactory.php` — populate new fields; default `destination_calendar_id` to null; add `configured()` state.
- `app/Http/Controllers/Dashboard/Settings/CalendarIntegrationController.php` — callback redirect, configure, saveConfiguration, syncNow, disconnect+stopWatch.
- `app/Http/Controllers/Booking/PublicBookingController.php` — push dispatch + suppression guards.
- `app/Http/Controllers/Dashboard/BookingController.php` — push dispatch matrix + suppression guards + `include_external` filter.
- `app/Http/Controllers/Customer/BookingController.php` — push dispatch on cancel + suppression guard.
- `app/Http/Controllers/Booking/BookingManagementController.php` — push dispatch on cancel + suppression guard.
- `app/Console/Commands/SendBookingReminders.php` — query exclusion.
- `app/Http/Middleware/HandleInertiaRequests.php` — `calendarPendingActionsCount` shared prop.
- `app/Providers/AppServiceProvider.php` — bind `CalendarProvider::class` via factory; singleton `CalendarProviderFactory`.
- `app/Services/SlotGeneratorService.php` — drop `->with('service')` eager-load from `getBlockingBookings` (small cleanup per §5 cluster 9).
- `bootstrap/app.php` — CSRF except list.
- `routes/web.php` — configure/save/sync-now inside shared settings group; pending-action resolve under dashboard group; webhook route outside all auth groups.
- `routes/console.php` — schedule renew command.
- `resources/js/pages/dashboard/settings/calendar-integration.tsx` — connected-state enrichments.
- `resources/js/components/settings/settings-nav.tsx` — pending-actions count badge.
- `resources/js/pages/dashboard/calendar.tsx` and `week-view.tsx` / `day-view.tsx` / `month-view.tsx` — external-event visual.
- `resources/js/pages/dashboard/bookings.tsx` — include_external filter.
- Wayfinder-generated files (regenerate).
- Existing `CalendarIntegrationTest.php` and `SendBookingRemindersTest.php` — contract updates per §6.

### Docs

- `docs/decisions/DECISIONS-CALENDAR-INTEGRATIONS.md` — append D-082, D-083, D-084, D-085, D-086, D-087, D-088.
- `docs/DEPLOYMENT.md` — Google Cloud setup + webhook HTTPS + queue worker + renew cron + env vars.
- `docs/HANDOFF.md` — overwrite to reflect MVPC-2 close.
- `docs/roadmaps/ROADMAP-MVP-COMPLETION.md` — tick Session 2 checkboxes.
- Move `docs/plans/PLAN-MVPC-2-CALENDAR-SYNC.md` → `docs/archive/plans/`.

## 8. Verification at session close

```bash
php artisan test tests/Feature tests/Unit --compact     # Feature + Unit only, ~30s
vendor/bin/pint --dirty --format agent
php artisan wayfinder:generate                          # before npm run build
npm run build
```

Expected:

- `test tests/Feature tests/Unit --compact` — green; suite grows from the **535 / 2326** baseline by ~40 cases to roughly **575** (±5). Exact count depends on dataset splitting.
- `pint --dirty --format agent` — `{"result":"pass"}`.
- `wayfinder:generate` — auto-generated TS deltas present.
- `npm run build` — Vite build clean.

Browser/E2E suite (`tests/Browser`) is **not** run in the iteration loop — it's the developer's session-close check. Expected E2E breakage: `tests/Browser/Booking/*` scenarios that serialise a booking's service might shallow-break if any test seeds a `source = google_calendar` booking (none do today); flag to the developer but no change needed.

Manual smoke (documented in HANDOFF, not in CI):

1. Real `GOOGLE_CLIENT_ID` + `GOOGLE_CLIENT_SECRET` + `GOOGLE_REDIRECT_URI` in `.env`. Local tunnel (ngrok/Expose/Herd Share) configured and its HTTPS URL added as a redirect URI in Google Cloud Console. Webhook URL (tunnel + `/webhooks/google-calendar`) registered if the tunnel is used.
2. Connect → configure step shows calendars, pick a destination + at least primary as a conflict → Finish → see connected state with last_synced_at.
3. Create a dashboard manual booking → verify the event appears in the Google calendar within ~10s (queue worker running).
4. Cancel the riservo booking → verify the Google event is deleted.
5. Create a Google event (not from riservo) → verify it appears in the dashboard calendar as an external event with the CalendarDays icon + muted tone within ~10s of the webhook arriving.
6. Delete a riservo-originated event in Google → verify a pending action appears on the dashboard.
7. Click "Cancel booking and notify customer" → verify the riservo booking moves to cancelled and the customer receives the cancellation email.

## 9. Constraints carried in

- Inertia v3 + React. Forms via `<Form>` / `useForm`. Standalone HTTP (e.g., the configure-page calendar list refresh) via `useHttp`; no `fetch` / `axios`.
- All user-facing strings via `__()` / `useTrans().t(...)`. English base.
- Frontend route references via Wayfinder; no hardcoded paths in TSX.
- Tenant-scoped reads via `tenant()` / `App\Support\TenantContext`. The `CalendarIntegration` is user-scoped (D-080); its `business_id` (D-085) pins the sync target.
- `All datetimes in UTC; convert to business TZ for display only` (from CLAUDE.md) — preserved. The Google event start/end round-trip as UTC ISO 8601; the detail panel converts for display.
- Migrations extend; do not recreate. Confirmed in §4.10.
- No Laravel Fortify, no Jetstream, no Zap.
- No scope drift beyond what §3 lists. The one incidental cleanup (dropping `->with('service')` from `SlotGeneratorService::getBlockingBookings`) is called out in §5 cluster 9 and tested.

## 10. Decisions to record

### D-082 — `CalendarProvider` interface and factory placement

File: `docs/decisions/DECISIONS-CALENDAR-INTEGRATIONS.md`.

- **Date**: 2026-04-17
- **Status**: accepted
- **Context**: MVPC-2 needs a provider abstraction so Outlook/Apple/CalDAV can be added post-MVP without changes to `Booking`, `SlotGeneratorService`, or any notification.
- **Decision**: `app/Services/Calendar/CalendarProvider.php` interface with eight methods (listCalendars, createCalendar, pushEvent, updateEvent, deleteEvent, startWatch, stopWatch, syncIncremental); `GoogleCalendarProvider` + `GoogleClientFactory` adjacent; `CalendarProviderFactory::for($integration)` resolves by `integration.provider`; singleton-bound in `AppServiceProvider`; DTOs under `app/Services/Calendar/DTOs/`.
- **Consequences**: Adding a future provider is a new class + one factory-binding line; `Booking` and callers never know which provider.

### D-083 — Single `PushBookingToCalendarJob` with action parameter

File: `docs/decisions/DECISIONS-CALENDAR-INTEGRATIONS.md`.

- **Date**: 2026-04-17
- **Status**: accepted
- **Decision**: One queued job `(int $bookingId, string $action)` with `action ∈ {create, update, delete}`; `tries=3`, `backoff=[60,300,900]`; `afterCommit()`; all three actions share retry/backoff/gate/failure paths.
- **Consequences**: Six dispatch sites read identically (`PushBookingToCalendarJob::dispatch($booking->id, $action)`). `Booking::shouldPushToCalendar()` centralises the two gates (configured integration, non-google_calendar source).

### D-084 — External event visuals + htmlLink persistence

File: `docs/decisions/DECISIONS-CALENDAR-INTEGRATIONS.md`.

- **Date**: 2026-04-17
- **Status**: accepted
- **Decision**: External bookings render with a lucide-react `CalendarDays` icon and `bg-muted/70` tone from the existing COSS UI palette. The Google event's `htmlLink` is stored in a dedicated `bookings.external_html_link` column (string, nullable) alongside `external_calendar_id` and `external_title`. `internal_notes` stays admin-notes-only; overloading it was rejected on the review because it mixes concerns and would propagate to every future calendar provider.
- **Consequences**: No Google brand-asset integration required (avoids spacing/attribution rules); neutral tone reinforces that the item is not a bookable riservo slot. "Open in Google Calendar" is available from every surface that renders the detail panel via a typed column that composes cleanly with future providers.

### D-085 — `CalendarIntegration.business_id` anchors the sync target business

File: `docs/decisions/DECISIONS-CALENDAR-INTEGRATIONS.md`.

- **Date**: 2026-04-17
- **Status**: accepted
- **Decision**: Add `business_id` (FK nullable) to `calendar_integrations`. Populated during the configure step from the user's currently-active tenant. Pull jobs resolve `provider_id` via `$integration->business->providers()->where('user_id', $integration->user_id)->firstOrFail()`.
- **Consequences**: One Google integration per user, one business scope. A multi-business user wanting to sync into both is deferred (tracked with R-2B business-switcher carry-over). The provider-row resolution is deterministic and can be asserted in tests.

### D-086 — `calendar_watches` sub-table for per-calendar webhook registrations

File: `docs/decisions/DECISIONS-CALENDAR-INTEGRATIONS.md`.

- **Date**: 2026-04-17
- **Status**: accepted
- **Decision**: Dedicated `calendar_watches` table keyed by `channel_id` (unique). One row per watched Google calendar per integration. Webhook handler looks up by `channel_id`; renewal iterates expiring rows.
- **Consequences**: Faster webhook hot path; per-watch rotation is a row update, not a JSON splice. The existing (MVPC-1) `calendar_integrations.webhook_*` columns become unused but kept for zero-risk schema simplification later.

### D-087 — "Keep riservo booking (ignore external)" replaces the ambiguous "Keep both"

File: `docs/decisions/DECISIONS-CALENDAR-INTEGRATIONS.md`.

- **Date**: 2026-04-17
- **Status**: accepted
- **Decision**: The `external_booking_conflict` choice formerly labelled "Keep both" is renamed "Keep riservo booking (ignore external)". Under the D-065/D-066 GIST invariant, a conflicting external event cannot materialise as a riservo booking row; the admin's decision is one of three:
  1. **Keep riservo booking (ignore external)** — resolver marks the pending action `dismissed`. The external event stays in Google, invisible to riservo. No DB mutation, no notification.
  2. **Cancel external event** — resolver calls `$provider->deleteEvent(...)` on the Google event, marks the pending action `resolved`.
  3. **Cancel riservo booking** — resolver cancels the riservo booking, dispatches `BookingCancelledNotification(cancelledBy='business')` (the riservo booking's source is `riservo`, so the suppression guard allows it), marks the pending action `resolved`, **and immediately dispatches `PullCalendarEventsJob($integration->id, $calendarId)` for the calendar that produced the conflict**. The re-pull is part of the resolver, not a downstream concern: with the riservo booking cancelled, the GIST constraint no longer blocks the external event from materialising, but without re-pulling, the external event only appears in riservo when the next webhook fires for that calendar (potentially many minutes later). The calendar id is carried on the action's `payload`.
- **Consequences**: Button label is honest about what happens. No DB invariant is ever bypassed. The third choice is deterministic: the external event materialises as an external Booking row before the resolver returns (subject to queue worker latency on `PullCalendarEventsJob`, which is typically seconds). Tests assert the job is dispatched with the source calendar id.

### D-088 — Notification suppression at dispatch sites with a model helper

File: `docs/decisions/DECISIONS-CALENDAR-INTEGRATIONS.md`.

- **Date**: 2026-04-17
- **Status**: accepted
- **Context**: Locked decision #7 mandates dispatch-site guards, not notification-class guards. The audit in §4.9 enumerates the ten dispatch sites.
- **Decision**: `Booking::shouldSuppressCustomerNotifications(): bool` (true iff `source === google_calendar`) is called at every Notification dispatch site — customer and staff both. `SendBookingReminders` additionally excludes at query-time. No Notification class is modified.
- **Consequences**: A future `source` with suppression requirements (e.g., hypothetical Outlook inbound) adds a case to the helper; all dispatch sites inherit it. Tests assert zero notifications fire for every dispatch site when source=google_calendar.

## 11. Open questions to flag for the developer

All six open questions in the brief are resolved in §4:

- **Q1 (CalendarProvider location)** — §4.1, `app/Services/Calendar/`. Resolved, D-082.
- **Q2 (webhook URL shape)** — `POST /webhooks/google-calendar`, CSRF-excluded in `bootstrap/app.php`. Verified no collision with existing routes (`routes/web.php` has no `/webhooks` prefix today).
- **Q3 (PushBookingToCalendarJob signature)** — §4.4, single job with action parameter. Resolved, D-083.
- **Q4 (pending actions UI location)** — §3 step 27, inline section at top of `/dashboard`, admin+staff visible, count badge on settings page. Not a dedicated page.
- **Q5 (external event visuals)** — §4.8, lucide `CalendarDays` + `bg-muted/70` tone. `htmlLink` stored in `internal_notes`. D-084.
- **Q6 (cancellation copy)** — existing `BookingCancelledNotification(cancelledBy='business')` copy reused for pending-action-driven cancellations. A grep of the existing notification confirms the copy reads "Your booking has been cancelled — {business}" which is correct for this path. No new notification class or copy variant needed. If the developer wants a distinct subject for the "cancelled because the Google side was deleted" case, that's a one-line addition but I'm not taking it unprompted since the current copy is accurate.

One additional decision point I'm flagging but not blocking on:

- **(Q7 — resolved in review)** When the viewer's current tenant differs from `integration.business_id`, the settings page shows a muted one-sentence notice ("This integration is pinned to {business name}.") rendered as `<p class="text-muted-foreground text-sm">` under the connected-state header. **No CTA.** A user who switched businesses to consult something else does not necessarily want to reconfigure; surfacing a "Disconnect to reconfigure" call-to-action pushes a destructive action they may not want. If a user genuinely needs to reconfigure against a different business, the existing Disconnect button remains available — no extra surface is warranted.

## 12. Escape hatch

If implementation surfaces a material complication — e.g., `google/apiclient` v2.15 has a token-refresh callback API that differs from what §4.2 assumes, the `bookings` GIST constraint behaves unexpectedly with null `service_id` (it should not — the constraint keys only on `provider_id` + the generated intervals — but a test might disagree), the webhook `X-Goog-Channel-Token` header has character limits that clash with our random secret length, or the test suite surfaces a non-trivial breakage in an existing Booking serialisation path we didn't audit — I stop, report the specific problem with a concrete one-paragraph plan patch, and wait. I do not expect to trigger this: the audit of every touchpoint is in §4, and §6 names every test evolution we expect.

## 13. Session-close checklist

- `php artisan test tests/Feature tests/Unit --compact` — green, target ~575 passed.
- `vendor/bin/pint --dirty --format agent` — `{"result":"pass"}`.
- `php artisan wayfinder:generate` committed.
- `npm run build` — green.
- D-082..D-088 added to `docs/decisions/DECISIONS-CALENDAR-INTEGRATIONS.md`.
- `docs/HANDOFF.md` rewritten with MVPC-2 close state, suite-count delta vs MVPC-1 baseline (535 → ~575), and a short "what Session 3 needs to know" note.
- `docs/DEPLOYMENT.md` extended per §3 step 40.
- Roadmap checkboxes ticked on `docs/roadmaps/ROADMAP-MVP-COMPLETION.md` §Session 2.
- This plan moved from `docs/plans/` to `docs/archive/plans/`.

---

**Status**: draft, awaiting approval. No code will be written before approval.
