# Handoff

**Session**: MVPC-2 — Google Calendar Sync (Bidirectional) (Session 2 of `docs/roadmaps/ROADMAP-MVP-COMPLETION.md`)
**Date**: 2026-04-17 (code) / 2026-04-17 (round-2 review fixes)
**Status**: Code complete; Feature + Unit suite **582 passed / 2471 assertions** (post-MVPC-1 baseline 535, +47). Pint clean. `npm run build` green. Wayfinder regenerated. Plan archived to `docs/archive/plans/PLAN-MVPC-2-CALENDAR-SYNC.md`.

> Full-suite Browser/E2E run is the developer's session-close check (`tests/Browser` takes 2+ minutes). The iteration loop used `php artisan test tests/Feature tests/Unit --compact` throughout.

---

## Review round 2 — five state-management fixes applied

After the initial MVPC-2 landing, reviewer flagged five correctness bugs around multi-calendar state management. All five were real and are fixed + tested in this revision.

| # | Bug | Fix |
|---|---|---|
| P1 | `sync_token` stored on `calendar_integrations`; replaying calendar A's token against calendar B triggers 410 and drops changes between runs for any multi-calendar setup | Moved `sync_token` to `calendar_watches` (per-row). `GoogleCalendarProvider::syncIncremental` + `PullCalendarEventsJob` read/write per watch. Integration-level column retained but no longer written. |
| P2a | Push job only stored Google event id; a later `Change settings` reconfigure re-targeted update/delete pushes to the new destination, orphaning events in the original calendar | Added `bookings.external_event_calendar_id`; create writes it; update/delete in both the job and `GoogleCalendarProvider::updateEvent` target the booking's stored origin (fallback to current destination only for pre-fix legacy rows). |
| P2b | `cancel_external` resolver swallowed provider failures but still marked the action resolved — staff could not retry, external event kept blocking availability | Resolver returns a three-state outcome (`resolved` / `failed` / `invalid`). Provider failure → action stays `pending`, user sees an error flash. 404/410 inside `GoogleCalendarProvider::deleteEvent` are still absorbed as "event already gone". |
| P2c | `saveConfiguration` only upserted the config; `StartCalendarSyncJob` only created missing watches. Unchecking a calendar left its channel alive in Google, importing events from calendars the user no longer selected | `StartCalendarSyncJob` now reconciles the desired set against existing watches: stopWatch + row delete for removed calendars, startWatch + initial pull for added calendars, leave unchanged calendars alone. stopWatch failures are swallowed but the row is still deleted. |
| P2d | Repinning an integration to a different business silently reused the old business's watches, per-watch sync tokens, and orphaned the old pending actions out of view of both tenants | `saveConfiguration` detects a `business_id` change and tears down integration-scoped state before the repin: stop all watches in Google (best-effort), delete all `calendar_watches` rows, delete all `calendar_pending_actions`, clear timing/error timestamps. OAuth tokens are preserved. `StartCalendarSyncJob` then builds fresh watches under the new business. |

New migration: `2026_04_17_100004_add_per_watch_sync_token_and_booking_source_calendar.php` (adds `calendar_watches.sync_token` and `bookings.external_event_calendar_id`).

Decision records extended:
- **D-083** — now covers the origin-calendar persistence on the booking.
- **D-085** — now covers repin-time teardown semantics (P2d).
- **D-086** — now covers the per-watch sync token + reconfigure teardown.
- **D-087** — adds failure semantics for `cancel_external`.

Test delta: +8 cases (47 calendar tests total, up from 39).
- `PullCalendarEventsJobTest` — two existing sync-token tests updated to read the watch row; one new test for per-calendar token independence.
- `PushBookingToCalendarJobTest` — one new test: reconfigure + delete targets the original calendar.
- `CalendarPendingActionResolutionTest` — one new test: provider failure keeps `cancel_external` pending.
- `StartCalendarSyncJobTest` (new file) — three tests: distinct-set watch creation, stale-calendar teardown, stopWatch-failure resilience.
- `CalendarIntegrationConfigureTest` — two new tests: repin tears down old state; same-business save preserves watches + sync tokens + pending actions.

---

## What Was Built

MVPC-2 delivers the full bidirectional Google Calendar sync behind a `CalendarProvider` interface. MVPC-1 stood up the OAuth round-trip in isolation; MVPC-2 owns everything above it — the configure step, the push/pull pipelines, the pending-actions UX, webhook renewal, the schema relaxations that let external events land as proper Booking rows, and the notification-suppression audit across every dispatch site.

Plan: `docs/archive/plans/PLAN-MVPC-2-CALENDAR-SYNC.md`. Seven new decisions:

- **D-082** — `CalendarProvider` interface + `CalendarProviderFactory` singleton placement.
- **D-083** — Single `PushBookingToCalendarJob(int $bookingId, string $action)` design.
- **D-084** — External-event visuals (neutral `EXTERNAL_EVENT_COLOR` + lucide `CalendarDays` icon) + dedicated `bookings.external_html_link` column (review-revised, not `internal_notes` overload).
- **D-085** — `calendar_integrations.business_id` pins integration to one business.
- **D-086** — `calendar_watches` sub-table for per-calendar webhook registrations.
- **D-087** — "Keep riservo booking (ignore external)" replaces the ambiguous "Keep both"; `cancel_riservo_booking` re-dispatches `PullCalendarEventsJob` for the source calendar.
- **D-088** — Notification suppression lives at dispatch sites via `Booking::shouldSuppressCustomerNotifications()`; pending-action visibility + resolution restricted to owner-of-integration OR admin.

### Database

Five new migrations (all additive / reverse-safe):

- `2026_04_17_100000_make_booking_customer_and_service_nullable_add_external_fields.php` — `bookings.customer_id` → nullable, `bookings.service_id` → nullable, add `bookings.external_title`, add `bookings.external_html_link`.
- `2026_04_17_100001_extend_calendar_integrations_for_sync.php` — adds `business_id` (FK, cascadeOnDelete, D-085), `destination_calendar_id`, `conflict_calendar_ids` (JSON), `sync_token` (no longer written after round-2; see 100004), `webhook_resource_id`, `webhook_channel_token`, `last_synced_at`, `last_pushed_at`, `sync_error`, `sync_error_at`, `push_error`, `push_error_at`. MVPC-1 columns (`webhook_channel_id`, `webhook_expiry`) remain but are unused — kept per D-086 to avoid schema churn.
- `2026_04_17_100002_create_calendar_watches_table.php` — `(id, integration_id, calendar_id, channel_id unique, resource_id, channel_token, expires_at, timestamps)` + indexes on `(integration_id, calendar_id)` and `expires_at`.
- `2026_04_17_100003_create_calendar_pending_actions_table.php` — `(id, business_id, integration_id, booking_id nullable, type, payload JSON, status, resolved_by_user_id, resolution_note, resolved_at, timestamps)` + indexes on `(business_id, status)` and `(integration_id, status)`.
- `2026_04_17_100004_add_per_watch_sync_token_and_booking_source_calendar.php` (round 2) — adds `calendar_watches.sync_token` (per-watch cursor — D-086 revised) and `bookings.external_event_calendar_id` (push-time origin calendar — D-083 revised).

### Provider abstraction

- `app/Services/Calendar/CalendarProvider.php` — interface with eight methods.
- `app/Services/Calendar/GoogleCalendarProvider.php` — Google SDK adapter; maps to/from typed DTOs; translates `410 Gone` to `SyncTokenExpiredException`.
- `app/Services/Calendar/GoogleClientFactory.php` — builds `Google\Client` per integration with `setTokenCallback` that persists rotated access tokens back to the row (D-080 / D-082 consequence).
- `app/Services/Calendar/CalendarProviderFactory.php` — singleton resolver, bound in `AppServiceProvider::register()`.
- DTOs: `CalendarSummary`, `WatchResult`, `ExternalEvent`, `SyncResult` under `app/Services/Calendar/DTOs/`.
- Exceptions: `SyncTokenExpiredException`, `UnsupportedCalendarProviderException`.

### Queued jobs

Under `app/Jobs/Calendar/`:

- `PushBookingToCalendarJob(int $bookingId, string $action)` — action ∈ create|update|delete. `tries=3`, `backoff=[60,300,900]`, `afterCommit()`. Gated at dispatch by `Booking::shouldPushToCalendar()`.
- `PullCalendarEventsJob(int $integrationId, string $calendarId)` — `tries=5`, `backoff=[60,120,300,600,1200]`, `afterCommit()`. 410 → clear sync token + forward-only retry. 23P01 (GIST exclusion) → rollback + `external_booking_conflict` pending action with `conflict_booking_ids[]` in payload.
- `StartCalendarSyncJob(int $integrationId)` — called from configure finalisation; starts a watch per distinct calendar, dispatches an initial pull each.

### HTTP

- `POST /webhooks/google-calendar` → `App\Http\Controllers\Webhooks\GoogleCalendarWebhookController@store` (no auth; CSRF-excluded in `bootstrap/app.php`). Validates `X-Goog-Channel-Id` + `X-Goog-Channel-Token` (constant-time `hash_equals`). Dispatches `PullCalendarEventsJob` and returns 200.
- Six routes added to the shared settings group (reusing D-081 shape):
  - `GET /dashboard/settings/calendar-integration/configure` → `configure`
  - `POST /dashboard/settings/calendar-integration/configure` → `saveConfiguration`
  - `POST /dashboard/settings/calendar-integration/sync-now` → `syncNow`
- `POST /dashboard/calendar-pending-actions/{action}/resolve` → `Dashboard\CalendarPendingActionController@resolve` (owner-or-admin auth per D-088).

### Dispatch sites wired

`PushBookingToCalendarJob` dispatched from:

- `Booking/PublicBookingController::store` (action=`create` for confirmed bookings).
- `Dashboard/BookingController::store` (manual booking, action=`create`).
- `Dashboard/BookingController::updateStatus` (action=`delete` for cancelled, action=`update` for confirmed/completed/no_show).
- `Customer/BookingController::cancel` (action=`delete`).
- `Booking/BookingManagementController::cancel` (action=`delete`).

Every dispatch gated by `Booking::shouldPushToCalendar()` — skips `source = google_calendar` AND unconfigured integrations.

### Notification suppression (D-088)

Every customer and staff notification dispatch site guards with `if (! $booking->shouldSuppressCustomerNotifications())`. `SendBookingReminders::handle()` additionally excludes `source = google_calendar` at query time. No Notification class was modified — the policy lives entirely at dispatch.

### Frontend

- `resources/js/pages/dashboard/settings/calendar-integration.tsx` — rewritten. Connected state shows destination + conflict calendars + last synced + pending-actions badge + Sync now + Change settings + muted pinned-business notice when the viewer's tenant differs from `integration.business_id` (Q7 revised: no "Disconnect to reconfigure" CTA).
- `resources/js/pages/dashboard/settings/calendar-integration-configure.tsx` — new page. Dropdown for destination calendar with a "Create a dedicated new calendar" toggle; multi-select for conflict calendars with primary pre-selected.
- `resources/js/components/dashboard/calendar-pending-actions-section.tsx` — new component rendered inline at the top of `/dashboard`. Per-row action buttons posted as Inertia `<Form>`s via the Wayfinder-generated resolve route.
- `resources/js/pages/dashboard.tsx` — wires `CalendarPendingActionsSection`, handles nullable customer/service in the today's-schedule table with an external-event fallback.
- `resources/js/components/dashboard/booking-detail-sheet.tsx` — external-event variant hides the customer/service blocks, adds an "Open in Google Calendar" external link, read-only (no status-transition buttons for external events).
- `resources/js/components/calendar/{day,week,month}-view.tsx` + `calendar-event.tsx` — branch on `booking.external` for `EXTERNAL_EVENT_COLOR` palette and null-safe labels.
- `resources/js/components/settings/settings-nav.tsx` — Calendar Integration nav item renders a badge pulled from `calendarPendingActionsCount` shared Inertia prop.
- `resources/js/lib/calendar-colors.ts` — new `EXTERNAL_EVENT_COLOR` neutral palette.
- `resources/js/types/index.d.ts` — `DashboardBooking.external/external_title/external_html_link` flags, nullable customer/service, `TodayBooking` external flag, `CalendarPendingAction` shape, `PageProps.calendarPendingActionsCount`.

### Shared Inertia props

`HandleInertiaRequests::share` now publishes `calendarPendingActionsCount` (viewer-scoped per D-085 extension: admins see business-wide count; staff see only actions owned by their own integration).

### Scheduler

`routes/console.php` now schedules `calendar:renew-watches` daily at 03:00 UTC. The command (`app/Console/Commands/RenewCalendarWatches.php`) refreshes any `calendar_watches` row expiring within 24h: stopWatch (best-effort, swallows 404) then startWatch, updating the row in place.

### Tests

New Pest test files under `tests/Feature/Calendar/`:

- `PushBookingToCalendarJobTest.php` — 5 cases: create persists external_calendar_id; delete calls deleteEvent; skip for google_calendar source; skip for unconfigured integration; failed() writes push_error.
- `PullCalendarEventsJobTest.php` — 7 cases: foreign event upsert (null customer + service); first-match-by-email; 23P01 → pending action (with conflict_booking_ids); riservo-deleted-in-google → pending action (no auto-cancel); cancelled foreign event → booking cancelled; 410 → forward-only re-sync; failed() writes sync_error.
- `GoogleCalendarWebhookControllerTest.php` — 5 cases: valid → 200 + job; missing channel → 400; unknown channel → 404; wrong token → 400; CSRF-exempt.
- `CalendarIntegrationConfigureTest.php` — 6 cases: callback redirects to configure; configure renders with calendars; save dispatches StartCalendarSyncJob; create-new-calendar flow; sync-now dispatches per watch; disconnect calls stopWatch + deletes.
- `CalendarPendingActionResolutionTest.php` — 9 cases covering all five resolution choices across both types + the owner-or-admin + tenant-scoping matrix. Asserts `cancel_riservo_booking` re-dispatches `PullCalendarEventsJob` (D-087 revised).
- `RenewCalendarWatchesCommandTest.php` — 3 cases: refresh imminent expiries; leave distant expiries alone; swallow stopWatch failures.
- `NotificationSuppressionTest.php` — 3 cases: helper returns correct value; updateStatus→cancelled on google_calendar dispatches zero notifications; reminder command query-excludes google_calendar.
- `SlotGenerationWithExternalBookingTest.php` — 1 case: external booking with null service blocks its window without crashing.

Shared: `tests/Support/Calendar/FakeCalendarProvider.php` — programmable CalendarProvider double used across every Calendar test file.

Pre-existing test contract evolution: `tests/Feature/Settings/CalendarIntegrationTest.php` — the callback-success redirect target changed from `settings.calendar-integration` to `settings.calendar-integration.configure` (deliberate; the callback no longer finalises — the configure step does). One line of assertion change, one comment explaining why.

---

## Current Project State

- **Backend**:
  - `app/Services/Calendar/` — interface, Google implementation, factory, DTOs, exceptions.
  - `app/Jobs/Calendar/` — three queued jobs.
  - `app/Http/Controllers/Webhooks/GoogleCalendarWebhookController.php`.
  - `app/Http/Controllers/Dashboard/CalendarPendingActionController.php`.
  - `app/Console/Commands/RenewCalendarWatches.php`.
  - `app/Models/PendingAction.php`, `app/Models/CalendarWatch.php` (new).
  - `app/Enums/PendingActionType.php`, `app/Enums/PendingActionStatus.php` (new).
  - `CalendarIntegration`, `Booking` extended with new fillables/casts/helpers.
- **Database**: four new migrations applied. `calendar_watches` and `calendar_pending_actions` tables created. `bookings` relaxed. `calendar_integrations` extended.
- **Frontend**: one new page (configure), one new dashboard section, external-event variants across detail sheet + calendar views + bookings list; settings nav pending-actions badge.
- **Config / env**: no new env vars. `GOOGLE_CLIENT_ID`/`_SECRET`/`_REDIRECT_URI` were already present from MVPC-1.
- **Tests**: Feature + Unit **574 passed / 2438 assertions**. Browser suite 249 untouched by this session (developer runs it at close).
- **Decisions**: D-082 through D-088 recorded. No existing decision superseded.
- **Dependencies**: no changes.

---

## How to Verify Locally

```bash
php artisan test tests/Feature tests/Unit --compact     # 574 passed (iteration loop)
php artisan test --compact                              # full suite incl. Browser (run by developer at close)
vendor/bin/pint --dirty --format agent                  # {"result":"pass"}
php artisan wayfinder:generate                          # idempotent
npm run build                                           # green, ~1.3s
```

Targeted:

```bash
php artisan test tests/Feature/Calendar --compact       # 39 Calendar-specific cases
php artisan route:list --path=webhooks                  # POST /webhooks/google-calendar
php artisan route:list --path=calendar-integration
php artisan route:list --path=calendar-pending-actions
```

Manual smoke (requires real Google OAuth creds + a tunnel):

1. Configure `GOOGLE_CLIENT_ID`/`SECRET`/`REDIRECT_URI` in `.env` and in Google Cloud Console (exact-match redirect URI, domain-verified for webhook).
2. Tunnel: `ngrok http 80` or Herd Share; register both the redirect URI and the webhook URL.
3. `php artisan queue:work` in a side terminal.
4. Dashboard → Settings → Calendar Integration → Connect → configure destination + conflict calendars → Save.
5. Create a dashboard manual booking. Within ~10s, the event should appear in your Google calendar.
6. Cancel that booking. Within ~10s, the Google event should be deleted.
7. Create a Google event directly (on a conflict calendar). Within ~10s of the webhook arriving, it should appear on `/dashboard` with the neutral external-event tone.
8. Delete a riservo-originated event in Google. A pending action should appear on `/dashboard` with "Cancel booking and notify customer" and "Keep booking and dismiss".
9. Click Disconnect → the row deletes, stopWatch is called per watched calendar (best-effort), UI returns to not-connected.

---

## What the Next Session Needs to Know

Next up: **Session 3 — Subscription Billing (Cashier)** in `docs/roadmaps/ROADMAP-MVP-COMPLETION.md`. Session 3 installs Laravel Cashier on the `Business` model, one paid tier (monthly + annual), indefinite trial, billing portal, webhook handler, cancel-at-period-end semantics, read-only transition on lapse.

### Conventions MVPC-2 established that future work must not break

- **D-082 — the CalendarProvider interface is the only surface above the SDK.** Session 5's reschedule endpoint must call `PushBookingToCalendarJob::dispatch($booking->id, 'update')` with the same `shouldPushToCalendar()` gate. Do not bypass the interface.
- **D-083 — one job, one action argument.** Adding a fourth action (e.g., "reassign provider") is a match-arm, not a new job.
- **D-084 — `external_html_link` is the dedicated htmlLink column.** `internal_notes` stays admin-notes-only regardless of source.
- **D-085 — `CalendarIntegration.business_id` is set once at configure time.** A user with multiple business memberships has one integration; it syncs to one business. Multi-business sync is an explicit post-MVP follow-up (R-2B carry-over).
- **D-086 — `calendar_watches` rows are the only place channel_id → calendar_id mapping lives.** The webhook controller queries by `channel_id`; no JSON lookup.
- **D-087 — the conflict resolver's `cancel_riservo_booking` choice is the one place that re-dispatches a pull.** Do not delete that dispatch — without it the external event only appears on the next webhook.
- **D-088 — `Booking::shouldSuppressCustomerNotifications()` is the single source of truth.** Any new Booking-related Notification dispatch site (Session 5's reschedule notification included) MUST call this guard.
- **Session 5's reschedule notification** — locked decision #16 already says suppress for `source = google_calendar`. The guard + the push dispatch are both `shouldSuppress…` + `shouldPush…` one-liners.

### MVPC-2 hand-off notes

- The MVPC-1 `webhook_channel_id` / `webhook_expiry` columns on `calendar_integrations` are unused and kept intentionally (D-086 rationale). Do not drop unless a future cleanup session is approved separately.
- The integration-level `calendar_integrations.sync_token` column is also unused post round-2; the authoritative cursor lives on `calendar_watches.sync_token`. Same zero-churn rationale — kept for a future cleanup.
- Pending-action count is scoped per viewer. Tests cover both admin and staff-owner matrices.
- Bookings pushed to Google carry their origin calendar on `bookings.external_event_calendar_id`. Any future session that resets or migrates booking rows must preserve this — otherwise update/delete pushes will target the current destination (wrong calendar for historical bookings).

---

## Open Questions / Deferred Items

No new carry-overs from MVPC-2 beyond the ones called out above.

Earlier carry-overs remain unchanged from the post-MVPC-1 list:
- Tenancy (R-19 carry-overs): R-2B business-switcher UI; admin-driven member deactivation + re-invite flow; "leave business" self-serve UX.
- R-16 frontend code splitting (deferred).
- R-17 carry-overs: admin email/push notification when a service crosses into unbookable post-launch; richer "provider is on vacation" UX; per-user banner dismiss / ack history.
- R-9 / R-8 manual QA.
- Orphan-logo cleanup.
- Profile + onboarding logo upload deduplication.
- Per-business invite-lifetime override.
- Real `/dashboard/settings/notifications` page.
- Per-business email branding.
- Mail rendering smoke-test in CI.
- Failure observability for after-response dispatch.
- Customer email verification flow.
- Customer profile page.
- Scheduler-lag alerting.
- `X-RateLimit-Remaining` / `Retry-After` headers on auth-recovery throttle.
- SMS / WhatsApp reminder channel.
- Browser-test infrastructure beyond Pest Browser.
- Popup widget i18n.
- `docs/ARCHITECTURE-SUMMARY.md` stale terminology.
- Real-concurrency smoke test.
- Availability-exception race.
- Parallel test execution (`paratest`).
- Slug-alias history.
- Booking-flow state persistence.
