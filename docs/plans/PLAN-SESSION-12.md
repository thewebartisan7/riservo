# Session 12 — Google Calendar Sync

## Context

Scheduled late in the MVP sequence per D-010. Enables bidirectional sync between riservo.ch bookings and each collaborator's Google Calendar:

- **Outbound**: a riservo booking created/cancelled/completed → Google event created/deleted
- **Inbound**: a Google event created/updated/deleted → a riservo booking is created / updated / cancelled as an "External Booking" that blocks availability

Built behind a `CalendarProvider` interface per SPEC §11 so Outlook / Apple Calendar can be added post-MVP without touching booking logic.

---

## Goal

Per-collaborator Google OAuth + two-way sync (push + pull via webhooks), with a Settings > Calendar Integration page visible to admins and collaborators.

---

## Prerequisites

- Tests green: 379 passing (verified).
- Google Cloud Console OAuth 2.0 client created by the developer (Web app, redirect URI = `{APP_URL}/dashboard/settings/calendar-integration/callback`). `.env` filled with `GOOGLE_CLIENT_ID`, `GOOGLE_CLIENT_SECRET`, `GOOGLE_REDIRECT_URI`.
- For local pull-sync testing: a tunnel (ngrok, Expose) to expose the `.test` host over HTTPS publicly. If not available, pull-sync will be documented and tested in staging only (covered by unit tests locally).

---

## Scope

**In scope:**
- `laravel/socialite` + `google/apiclient` composer installs.
- Migrations: nullable `customer_id`/`service_id` on bookings, new `external_title` column, extend `calendar_integrations` with sync + webhook fields, unique index on `(user_id, provider)`.
- `CalendarProvider` interface + `GoogleCalendarProvider` implementation.
- OAuth connect/callback/disconnect controller + routes (Socialite stateless, per-user).
- Push sync via queued jobs at every booking mutation point.
- Extended properties on Google events storing `riservo_booking_id`.
- Webhook endpoint for Google push notifications, CSRF-excluded, validated via `X-Goog-Channel-Token` shared secret.
- Pull sync job using `syncToken` incremental API.
- External bookings (source=google_calendar) with null customer/service, `external_title` populated.
- Webhook renewal scheduled daily (Google channels expire ~7 days).
- Settings > Calendar Integration page (admin + collaborator access).
- Settings sub-nav: new "Calendar Integration" link visible to both roles.
- Sync status UI: connected state, `last_synced_at`, error banner when `sync_error` set.
- Calendar / dashboard: external booking display ("External Event" with Google summary, no customer details).
- Notification suppression for `source = google_calendar` bookings (customer may not exist; collaborator already knows).
- `SlotGeneratorService` nullsafe on `$booking->service?->buffer_*`.
- DEPLOYMENT.md: Google OAuth setup, webhook reachability, queue/scheduler notes.

**Out of scope (future / v2):**
- Outlook / Apple Calendar providers (interface is enough).
- Re-push when a user manually deletes a riservo-created event from Google (MVP rule: riservo is source of truth for riservo-sourced events; Google-side edits to those events are ignored).
- UI for resolving conflicts — we use a simple deterministic rule.
- Retroactive sync of historical bookings (only sync forward from the moment of connection).

---

## Key design decisions (new — will be recorded via `docs/DECISIONS.md`)

- **D-061** Booking `customer_id` and `service_id` become nullable. External Google events without a mappable customer or service are stored as bookings with these fields null and an `external_title` column populated from the Google event summary.
- **D-062** Settings area splits into admin-only routes and admin-or-collaborator routes. Calendar Integration is the only setting in the second group for MVP; other settings remain admin-only.
- **D-063** `CalendarProvider` interface in `app/Services/Calendar/` with methods: `pushEvent`, `updateEvent`, `deleteEvent`, `startWatch`, `stopWatch`, `syncIncremental`. Google is the only implementation for MVP.
- **D-064** riservo is the source of truth for riservo-sourced bookings. Webhooks about events carrying `riservo_booking_id` in `extendedProperties.private` are ignored (no-op). Collaborators modifying/deleting riservo events in Google Calendar is unsupported in MVP.
- **D-065** External Google events → Booking with `source=google_calendar`, `status=confirmed`, nullable customer/service, `external_title` = event `summary`. Customer is auto-created only if a Google attendee email is present.
- **D-066** Notifications are suppressed when `source = google_calendar`. Skipped at dispatch sites (not inside each Notification class).
- **D-067** Per-user Google OAuth via Laravel Socialite (`Socialite::driver('google')->scopes([calendar.events])`); API calls via `google/apiclient` with a short-lived `Google\Client` refreshed from the stored refresh token.
- **D-068** Webhook channel validation via a per-channel token stored on `calendar_integrations.webhook_channel_token`. Google echoes this token in the `X-Goog-Channel-Token` header; mismatches are rejected 400.
- **D-069** Webhook endpoint dispatches a queued `PullCalendarEventsJob` and returns 200 immediately. Actual event fetching + mutation happens asynchronously.

---

## Implementation steps

### 1. Dependencies + config

1. `composer require laravel/socialite google/apiclient:"^2.15"`
2. Add `services.google` block to `config/services.php` (`client_id`, `client_secret`, `redirect` from env).
3. Add entries to `.env.example`: `GOOGLE_CLIENT_ID=`, `GOOGLE_CLIENT_SECRET=`, `GOOGLE_REDIRECT_URI=${APP_URL}/dashboard/settings/calendar-integration/callback`.

### 2. Migrations

4. `php artisan make:migration relax_booking_customer_and_service_foreign_keys`
   - `bookings.customer_id` → nullable with `foreignId()->nullable()->constrained()->nullOnDelete()`
   - `bookings.service_id` → nullable, `nullOnDelete()`
   - Add `external_title` string nullable
5. `php artisan make:migration extend_calendar_integrations_table`
   - `sync_token` text nullable
   - `webhook_resource_id` string nullable (X-Goog-Resource-ID)
   - `webhook_channel_token` string nullable (shared secret for webhook auth)
   - `last_synced_at` datetime nullable
   - `sync_error` text nullable
   - `sync_error_at` datetime nullable
   - Unique index on `(user_id, provider)`
6. Run migrations.

### 3. Model updates

7. `app/Models/Booking.php`: add `external_title` to `#[Fillable]`; no relation changes (already BelongsTo which allows null).
8. `app/Models/CalendarIntegration.php`: add new columns to `#[Fillable]`; cast `last_synced_at`, `sync_error_at` as datetime; keep tokens `encrypted`.

### 4. Enum + existing bookings compatibility

9. `BookingSource::GoogleCalendar` already exists — no change.
10. `SlotGeneratorService::conflictsWithBookings()`: change `$booking->service->buffer_before ?? 0` → `$booking->service?->buffer_before ?? 0` (same for `buffer_after`). Eager load stays as `->with('service')`.

### 5. `CalendarProvider` abstraction

11. `app/Services/Calendar/CalendarProvider.php` — interface:
    - `pushEvent(Booking $booking): string` — returns `external_calendar_id`
    - `updateEvent(Booking $booking): void`
    - `deleteEvent(Booking $booking): void`
    - `startWatch(CalendarIntegration $integration): void`
    - `stopWatch(CalendarIntegration $integration): void`
    - `syncIncremental(CalendarIntegration $integration): void`
12. `app/Services/Calendar/GoogleCalendarProvider.php` — implements interface. Uses a private `clientFor(CalendarIntegration $integration): Google\Client` helper that injects tokens, enables offline refresh, and sets a token-updater callback to persist refreshed access tokens.
13. `app/Services/Calendar/CalendarProviderFactory.php` — resolves a provider from a `CalendarIntegration` or `Booking` based on `provider` string. Bound in `AppServiceProvider`.

### 6. OAuth flow + Settings controller

14. `app/Http/Controllers/Dashboard/Settings/CalendarIntegrationController.php`:
    - `index()` — Inertia render `Dashboard/settings/calendar-integration` with current `CalendarIntegration` (user-scoped), `last_synced_at`, `sync_error`.
    - `connect()` — `Socialite::driver('google')->scopes(['openid','email','https://www.googleapis.com/auth/calendar.events'])->with(['access_type' => 'offline','prompt' => 'consent'])->redirect()`.
    - `callback()` — on success: upsert `CalendarIntegration` for `auth()->user()` with access+refresh tokens, set `calendar_id='primary'`, dispatch `StartCalendarWatchJob` (sync) that calls `GoogleCalendarProvider::startWatch` + initial `syncIncremental` to prime the syncToken, redirect back with flash.
    - `disconnect()` — stop watch (best-effort try/catch), delete integration, redirect with flash.
15. Routes in `routes/web.php`:
    ```
    // Admin+collaborator settings group (new)
    Route::middleware(['auth','verified','role:admin,collaborator','onboarded'])
      ->prefix('dashboard/settings')->name('settings.')->group(function () {
        Route::get('calendar-integration', [CalendarIntegrationController::class, 'index'])->name('calendar-integration');
        Route::get('calendar-integration/connect', [CalendarIntegrationController::class, 'connect'])->name('calendar-integration.connect');
        Route::get('calendar-integration/callback', [CalendarIntegrationController::class, 'callback'])->name('calendar-integration.callback');
        Route::delete('calendar-integration', [CalendarIntegrationController::class, 'disconnect'])->name('calendar-integration.disconnect');
      });
    ```
16. `php artisan wayfinder:generate` to regenerate typed routes after additions.

### 7. Push sync — jobs

17. `app/Jobs/Calendar/PushBookingToCalendarJob.php`:
    - Implements `ShouldQueue`, `SerializesModels`.
    - Constructor: `Booking $booking`, `string $action` ('create' | 'update' | 'delete').
    - handle(): resolves the collaborator's `CalendarIntegration` (skip if none). Resolves provider. On create/update: `pushEvent` or `updateEvent`, saves returned `external_calendar_id` on the booking. On delete: `deleteEvent`.
    - `failed()` writes `sync_error` + `sync_error_at` on the integration for UI display.
18. Dispatch sites (non-breaking — wrap in `rescue()` or catch exceptions so user flow is not affected):
    - `PublicBookingController@store` — after `Booking::create`, dispatch `create`.
    - `Dashboard/BookingController@store` (manual) — dispatch `create`.
    - `Dashboard/BookingController@updateStatus` — if new status is cancelled or no_show: dispatch `delete`. If confirmed from pending (and not previously pushed): dispatch `create`.
    - `BookingManagementController@cancel` and `Customer/BookingController@cancel` — dispatch `delete`.
    - `AutoCompleteBookings` command — no push (Google events already passed; leave as-is).
    - **Exclude** bookings with `source=google_calendar` from dispatch (those are pull-originated; no push).

### 8. Pull sync — webhook + job

19. `app/Http/Controllers/Webhooks/GoogleCalendarWebhookController.php@receive`:
    - Accepts POST, no CSRF, no auth.
    - Read headers: `X-Goog-Channel-ID`, `X-Goog-Resource-ID`, `X-Goog-Resource-State`, `X-Goog-Channel-Token`.
    - Find `CalendarIntegration` by `webhook_channel_id`; verify token matches `webhook_channel_token`.
    - Return 200 immediately after dispatching `PullCalendarEventsJob`.
20. Route (new `routes/web.php` block): `POST /webhooks/google-calendar` → no middleware groups; CSRF excluded via `bootstrap/app.php` `validateCsrfTokens(except: ['webhooks/google-calendar'])`.
21. `app/Jobs/Calendar/PullCalendarEventsJob.php`:
    - Calls `GoogleCalendarProvider::syncIncremental($integration)`.
    - `syncIncremental` inside provider:
      - Lists events via `events.list` using stored `syncToken` (full sync fallback if 410).
      - For each event:
        - If `extendedProperties.private.riservo_booking_id` present → skip (D-064).
        - If event cancelled (`status=cancelled`) → find booking by `external_calendar_id`; if exists and is `source=google_calendar`, mark `cancelled`.
        - Else → upsert booking by `external_calendar_id`. Fields: collaborator = integration user, business = their business, starts_at/ends_at in UTC (from event dateTime), `source=google_calendar`, `status=confirmed`, nullable service/customer, `external_title = summary`. If attendee with email present, find-or-create `Customer` and link.
      - Persist new `nextSyncToken` + `last_synced_at`.
    - On transient Google errors: retry with backoff (`tries=5`, `backoff=[60,120,300,600,1200]`).
    - On persistent errors: write `sync_error`/`sync_error_at`.

### 9. Webhook renewal command

22. `app/Console/Commands/RenewCalendarWebhooks.php`:
    - Finds integrations where `webhook_expiry` is within 24 hours OR is null.
    - For each: `stopWatch` (if exists) then `startWatch`.
    - Handles transient errors gracefully; logs failures.
23. Schedule in `routes/console.php`: `Schedule::command('calendar:renew-watches')->dailyAt('03:00');`

### 10. Notification suppression

24. At each Notification dispatch site that touches a Booking, guard with `if ($booking->source !== BookingSource::GoogleCalendar)`. Alternatively, wrap in a small `BookingNotifier` helper. For MVP, inline guards at each site — 5 sites, all already enumerated.
25. Also guard `SendBookingReminders` query: add `->where('source', '!=', BookingSource::GoogleCalendar->value)` so reminder emails are never scheduled for external events.

### 11. Settings UI

26. `resources/js/pages/Dashboard/settings/calendar-integration.tsx`:
    - COSS UI Card: "Google Calendar"
    - If not connected: "Connect" button → `<Link method="get" href={route('settings.calendar-integration.connect')}>`.
    - If connected:
      - Status chip: "Connected" (green) or "Error" (red) if `sync_error`.
      - Line: "Last synced: {relative time}"
      - Disconnect button (COSS UI Button destructive) → `Form` with DELETE method.
      - If `sync_error`, show a COSS UI Alert with the error and an "Retry" link that hits `connect` again.
27. `resources/js/layouts/settings-layout.tsx`: add "Calendar Integration" nav item, visible for both `admin` and `collaborator` roles; other items stay admin-only (conditional render based on `auth.user.role`).
28. Booking detail panel (existing `resources/js/components/dashboard/booking-detail-panel.tsx` or equivalent): when `booking.source === 'google_calendar'`, replace customer section with "External Event" label + `external_title`; hide status actions except "Open in Google Calendar" link (external_calendar_id).
29. Calendar views (week/day/month event rendering): if `booking.source === 'google_calendar'`, prefix event title with a small Google icon + render in a neutral color.

### 12. DEPLOYMENT.md

30. Add section "Google Calendar sync":
    - Env vars list (GOOGLE_CLIENT_ID/SECRET/REDIRECT_URI).
    - Google Cloud Console steps (OAuth consent screen, scopes, authorized redirect URI).
    - Webhook endpoint must be publicly HTTPS-reachable (Google rejects plain HTTP).
    - Queue workers must be running (push + pull jobs).
    - Scheduled job `calendar:renew-watches` runs daily.
    - Local dev note: webhooks require a tunnel (ngrok/Expose) pointed at the Herd HTTPS port.

### 13. Wayfinder generation + pint

31. `php artisan wayfinder:generate`
32. `vendor/bin/pint --dirty --format agent`

---

## Files to create

Backend:
- `app/Services/Calendar/CalendarProvider.php`
- `app/Services/Calendar/GoogleCalendarProvider.php`
- `app/Services/Calendar/CalendarProviderFactory.php`
- `app/Jobs/Calendar/PushBookingToCalendarJob.php`
- `app/Jobs/Calendar/PullCalendarEventsJob.php`
- `app/Http/Controllers/Dashboard/Settings/CalendarIntegrationController.php`
- `app/Http/Controllers/Webhooks/GoogleCalendarWebhookController.php`
- `app/Console/Commands/RenewCalendarWebhooks.php`
- `database/migrations/YYYY_MM_DD_relax_booking_customer_and_service_foreign_keys.php`
- `database/migrations/YYYY_MM_DD_extend_calendar_integrations_table.php`

Frontend:
- `resources/js/pages/Dashboard/settings/calendar-integration.tsx`

Tests (Pest):
- `tests/Feature/Calendar/OAuthFlowTest.php` — connect redirects with correct scopes; callback stores integration + dispatches StartCalendarWatchJob.
- `tests/Feature/Calendar/PushSyncTest.php` — booking lifecycle dispatches `PushBookingToCalendarJob` with right action; google-sourced bookings do not.
- `tests/Feature/Calendar/PullSyncWebhookTest.php` — valid POST → dispatches PullCalendarEventsJob; invalid token → 400.
- `tests/Feature/Calendar/PullSyncJobTest.php` — skips events with riservo_booking_id; creates/updates/cancels external bookings; auto-creates Customer from attendee email.
- `tests/Feature/Calendar/WebhookRenewalTest.php` — expiring watches renewed; non-expiring untouched.
- `tests/Feature/Calendar/CalendarIntegrationPageTest.php` — collaborator + admin both 200; non-authed 302; disconnected/connected states render.
- `tests/Feature/Calendar/NotificationSuppressionTest.php` — `source=google_calendar` bookings do NOT fire customer confirmations, reminders, etc.
- `tests/Feature/Calendar/SlotGeneratorExternalBookingTest.php` — external booking (null service) blocks slots without crashing on null `service`.
- `tests/Feature/Calendar/BookingNullableFieldsTest.php` — existing flows unchanged; creating a booking with customer_id=null / service_id=null is valid.
- `tests/Unit/Calendar/GoogleCalendarProviderTest.php` — with a mock Google\Service\Calendar, verify that pushEvent sets `extendedProperties.private.riservo_booking_id`, that syncToken expiry triggers full sync, etc.

## Files to modify

- `composer.json` (require socialite + apiclient)
- `config/services.php` (google block)
- `.env.example` (GOOGLE_* vars)
- `bootstrap/app.php` (CSRF except for webhook)
- `routes/web.php` (new groups + routes)
- `routes/console.php` (schedule renewal command)
- `app/Models/Booking.php` (external_title fillable)
- `app/Models/CalendarIntegration.php` (new fillables + casts)
- `app/Services/SlotGeneratorService.php` (nullsafe on service)
- `app/Http/Controllers/Booking/PublicBookingController.php` (dispatch push + suppress notifications for google_calendar)
- `app/Http/Controllers/Dashboard/BookingController.php` (dispatch push on create + updateStatus)
- `app/Http/Controllers/Booking/BookingManagementController.php` (dispatch delete on cancel)
- `app/Http/Controllers/Customer/BookingController.php` (dispatch delete on cancel)
- `app/Console/Commands/SendBookingReminders.php` (exclude google_calendar source)
- `resources/js/layouts/settings-layout.tsx` (add nav link, role-based visibility)
- `resources/js/components/dashboard/booking-detail-panel.tsx` (handle null customer/service, external_title, external link)
- `resources/js/components/calendar/calendar-event.tsx` (external event visual variant)
- `resources/js/types/index.d.ts` (add `external_title`, optional customer/service on Booking; CalendarIntegration type)
- `docs/DEPLOYMENT.md`
- `docs/ROADMAP.md` (check off Session 12 items)
- `docs/HANDOFF.md` (overwrite)
- `docs/DECISIONS.md` (record D-061..D-069 in the appropriate topical decision file)

---

## Testing plan

Run after each phase: `php artisan test --compact`.

- **Unit**: `GoogleCalendarProvider` with mocked `Google\Service\Calendar` — pushEvent includes `riservo_booking_id` in extendedProperties; syncIncremental handles 410 sync token expiry; conflict detection on event upsert.
- **Feature — OAuth**: Socialite::shouldReceive(...). Verify scopes include `calendar.events`, access_type offline, prompt consent. Callback stores `CalendarIntegration`, dispatches watch start.
- **Feature — Push sync**: `Queue::fake()`; create/cancel booking via each controller path; assert `PushBookingToCalendarJob` dispatched with correct action. Assert `source=google_calendar` bookings do not dispatch.
- **Feature — Webhook**: POST to `/webhooks/google-calendar` with correct headers → dispatches `PullCalendarEventsJob` + returns 200. Wrong token → 400. Unknown channel id → 404.
- **Feature — Pull sync**: Given a mocked Google event list: (a) new event becomes a booking with source=google_calendar, (b) event with riservo_booking_id is skipped, (c) cancelled event → booking marked cancelled, (d) event with attendee email → Customer find-or-create.
- **Feature — Renewal command**: Integrations with `webhook_expiry < now+24h` get `stopWatch` then `startWatch`; stable ones untouched.
- **Feature — Settings UI**: Collaborator user hits `settings.calendar-integration` → 200 renders. Admin same. Unauth redirect to login.
- **Feature — Notification suppression**: Booking with source=google_calendar triggers no `Notification::send`. Other sources unaffected.
- **Feature — SlotGenerator with external booking**: External booking (null service) with status=confirmed blocks overlapping slots without fatal null access.
- **Feature — Nullable FK migration**: Existing tests (especially bookings-related) all still pass. Seeder works.

---

## Verification

1. `php artisan test --compact` — all tests green.
2. `php artisan migrate:fresh --seed` — seed works with new schema.
3. `npm run build` — bundle builds clean, no TS errors.
4. `vendor/bin/pint --dirty --format agent` — clean.
5. End-to-end against real Google (developer-driven, optional in this session):
   - Connect from Settings page → redirected to Google → granted → back with "Connected".
   - Create a booking in riservo → event appears in Google Calendar with `riservo_booking_id` in extendedProperties.
   - Cancel booking → Google event deleted.
   - Create an event in Google Calendar → webhook fires → appears as External Booking in calendar view within a minute.
   - Cancel the external event in Google → corresponding riservo booking marked cancelled.

---

## Open questions resolved with user (2026-04-14)

1. External events schema → **nullable customer_id/service_id** + `external_title` column.
2. Settings access → **admin + collaborator** can both see Calendar Integration; other settings stay admin-only.
3. SDK → **Socialite + google/apiclient**.
4. Notifications → **suppress for google_calendar source**.

## New decisions to record

D-061..D-069 as described above. Record them in the appropriate topical decision file listed in `docs/DECISIONS.md` before implementation.
