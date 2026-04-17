# riservo.ch — MVP Completion Roadmap

> Version: 1.0 — Draft
> Status: Planning
> Scope: All remaining work to close the MVP plus the immediate post-MVP polish that depends on it.
> Format: WHAT only. The HOW is decided per-session by the implementing agent in a dedicated plan document.
> Each session is a focused, reviewable unit handed to a single agent. Sessions run sequentially in the order listed below — each session is a hard prerequisite for the next.

---

## Overview

Sessions 1–11 of the original `docs/ROADMAP.md` are complete. This roadmap consolidates and supersedes the remaining work, which was previously fragmented across:

- `docs/ROADMAP.md` Sessions 12 (Google Calendar) and 13 (Cashier billing)
- `docs/roadmaps/ROADMAP-CALENDAR.md` Phase 2 (the only phase we ship for MVP)
- `docs/roadmaps/ROADMAP-FEATURES.md` Sessions F1, F2, F3

The new sequence is five sessions, each with a clear deliverable and a single owning agent:

| # | Session | Was previously | Outcome |
|---|---------|---------------|---------|
| 1 | Google OAuth Foundation | F1 (ROADMAP-FEATURES) | Socialite scaffold + Settings page that connects/disconnects a Google account |
| 2 | Google Calendar Sync (Bidirectional) | Session 12 / Calendar Phase 2 | Push + pull + webhooks; external bookings block availability |
| 3 | Subscription Billing (Cashier) | Session 13 | Stripe Cashier on `Business`; one paid tier; indefinite trial |
| 4 | Provider Self-Service Settings | F2 (ROADMAP-FEATURES) | Account + Availability pages accessible to providers, not only admins |
| 5 | Advanced Calendar Interactions | F3 (ROADMAP-FEATURES) | Drag/resize/click-to-create/hover-preview in dashboard calendar |

ICS one-way feed (formerly Calendar Phase 1) is **not** in this roadmap. It would dilute the calendar work without adding meaningful product value once OAuth ships. Tracked in `docs/BACKLOG.md` as a post-MVP option if user research justifies it.

Outlook / Apple Calendar (formerly Calendar Phase 3) and group bookings remain post-MVP. `ROADMAP-GROUP-BOOKINGS.md` and `ROADMAP-CALENDAR.md §Phase 3` continue to hold those plans.

E2E test coverage (`ROADMAP-E2E.md`) is independent of this roadmap. E2E-1 through E2E-6 already shipped (commit `ec7a65b`); E2E-7 (Calendar Sync UI coverage) unblocks once Session 2 lands and is owned by that roadmap, not this one.

---

## Cross-cutting decisions locked in this roadmap

These were left as "agent should evaluate" in the previous roadmaps. They are now decided. The implementing agent must record them with fresh decision IDs (next available is **D-080**) in the appropriate topical file under `docs/decisions/`.

### Calendar integration (applies to Sessions 1 and 2)

1. **HTTP client / SDK** — use `laravel/socialite` for OAuth and `google/apiclient` (`^2.15`) for Calendar API calls. The SDK weight is justified by built-in token refresh, typed event objects, batch support, and resilience to API edge cases that we would otherwise have to re-implement on top of Guzzle. Locked.
2. **External booking schema** — `bookings.customer_id` and `bookings.service_id` both become **nullable**. A new `bookings.external_title` column stores the Google event summary. We do **not** introduce a per-business "External Appointment" placeholder service. Frontend null-handling is bounded to one render variant ("External Event"). Locked.
3. **Initial sync window** — forward-only, from the moment of connect. No retroactive import of pre-existing Google events. Locked.
4. **Conflict-calendar default** — at connect time the user's primary Google calendar is pre-selected as a conflict source; additional calendars can be added in the configuration step. Locked.
5. **Pending actions UX** — overlaps and Google-side deletions of riservo-originated events surface as items in a "Pending Actions" dashboard section. The system never silently cancels a riservo booking based on a Google-side change. Locked.
6. **Multi-attendee handling** — when an inbound external event has attendee emails, link the **first** matching `Customer` row by email; do **not** auto-create new Customer rows from unmatched attendees. Group bookings remain post-MVP. Locked.
7. **Notification suppression** — bookings with `source = google_calendar` do not trigger any customer-facing notifications. Suppress at dispatch sites (controller + reminder query), not inside Notification classes. Locked.
8. **Settings access split** — `Calendar Integration`, `Account`, and `Availability` settings pages are accessible to both `admin` and `staff` (when the staff user has a Provider row). All other settings pages remain admin-only. This split is implemented in Session 1 (one page) and re-applied in Session 4 (two pages). Locked.

### Billing (applies to Session 3)

9. **Plan structure** — one paid tier with a monthly and an annual price. No multi-tier (Starter/Pro) at launch. Pricing numbers and limits are decided at session-plan time. Locked.
10. **Trial** — indefinite trial at signup, **no card required**. Card is collected when the business clicks "Subscribe". Locked.
11. **Plan limits** — no hard limits enforced in MVP. Reasonable abuse rate-limits exist already. Tier-based caps (max staff, max bookings/month) are deferred to v2. Locked.
12. **Cancellation semantics** — `cancel_at_period_end` (Stripe default). Business retains full access until period ends, then the dashboard becomes read-only with a "Resubscribe" CTA. The exact read-only enforcement strategy is decided at session-plan time. Locked.

### Calendar interactions (applies to Session 5)

13. **Drag library** — `@dnd-kit/core`. Lazy-loaded only on the calendar route. Locked.
14. **Drag/resize scope** — week and day views only. Month view supports click-to-create only. Locked.
15. **Resize granularity** — minimum increment is the service's `slot_interval_minutes`. Server-side validation is authoritative; the client snaps to the same grid for UX. Locked.
16. **Reschedule notifications** — the customer receives a "your booking time has changed" email when an admin or staff reschedules via drag/resize. Suppressed for `source = google_calendar`. Locked.

---

## Session 1 — Google OAuth Foundation

**Owner**: single agent. **Prerequisites**: none. **Unblocks**: Session 2.

Stand up the OAuth plumbing in isolation so Session 2 can focus entirely on sync logic. Deliverable is a verified OAuth round-trip: a user clicks "Connect Google Account", grants the requested scopes, and lands back in the app with their tokens encrypted-at-rest. No sync logic is written here.

- [x] Install `laravel/socialite` and `google/apiclient:^2.15` (per locked decision #1)
- [x] Add `services.google` block to `config/services.php` with `client_id`, `client_secret`, `redirect`
- [x] Add `GOOGLE_CLIENT_ID`, `GOOGLE_CLIENT_SECRET`, `GOOGLE_REDIRECT_URI` to `.env.example`
- [x] Extend `calendar_integrations` with OAuth columns: `access_token` (encrypted), `refresh_token` (encrypted), `token_expires_at`; add unique index on `(user_id, provider)`
- [x] `Dashboard\Settings\CalendarIntegrationController` with three actions: `index`, `connect`, `callback`, `disconnect` — minimal bodies; the only persistent side-effect is upsert/delete of a `CalendarIntegration` row
- [x] `connect()` redirects via Socialite with scopes `openid`, `email`, `https://www.googleapis.com/auth/calendar.events` and `access_type=offline`, `prompt=consent` (so the refresh token is always returned)
- [x] `callback()` stores tokens encrypted on the `CalendarIntegration` row scoped to `auth()->user()`; flashes success
- [x] `disconnect()` deletes the `CalendarIntegration` row; leave a `// TODO Session 2: stop webhook watch here` comment for the next session
- [x] Routes under a new `role:admin,staff` settings middleware group (per locked decision #8) — admin-only group remains unchanged
- [x] `resources/js/pages/Dashboard/settings/calendar-integration.tsx` — minimal UI: not-connected state with "Connect Google Calendar" button; connected state showing the linked Google account email and a "Disconnect" button; reserved slot for the error banner Session 2 will write to
- [x] `resources/js/layouts/settings-layout.tsx` — add "Calendar Integration" nav item, role-aware visibility (visible to both admin and staff)
- [x] Feature tests: connect redirects with the correct scopes and `access_type=offline`; callback persists encrypted tokens; disconnect removes the row; both admin and staff reach the page (200); guest is redirected (302)
- [x] `php artisan wayfinder:generate` after route additions
- [x] Pint clean, full Pest suite green, `npm run build` clean

**Session 1 closed 2026-04-17.** D-080 (Socialite + google/apiclient stack) and D-081 (settings routes split into admin-only + shared groups) recorded.

**Out of scope**: webhooks, push, pull, sync token, event mutation. All of that lives in Session 2.

---

## Session 2 — Google Calendar Sync (Bidirectional)

**Owner**: single agent. **Prerequisites**: Session 1. **Unblocks**: Session 5 (drag/resize benefits from Calendar interactions; Session 5 must reschedule events that already round-trip through Google).

Full bidirectional sync: riservo bookings push to the user's chosen Google calendar, and Google events on the user's selected conflict calendars pull into riservo as `External Event` bookings that block availability.

### Data layer
- [ ] Migration: `bookings.customer_id` → nullable; `bookings.service_id` → nullable; add `bookings.external_title` (per locked decision #2)
- [ ] Migration: extend `calendar_integrations` with `destination_calendar_id` (default `'primary'`), `conflict_calendar_ids` (JSON), `sync_token` (text), `webhook_resource_id`, `webhook_channel_id`, `webhook_channel_token`, `webhook_expiry`, `last_synced_at`, `last_pushed_at`, `sync_error`, `sync_error_at`, `push_error`, `push_error_at`
- [ ] New table `calendar_pending_actions`: `id`, `business_id`, `integration_id`, `booking_id` (nullable FK), `type` (enum: `riservo_event_deleted_in_google` | `external_booking_conflict`), `payload` JSON, `status` (enum: `pending` | `resolved` | `dismissed`), `created_at`, `resolved_at`
- [ ] `Booking` model: `external_title` fillable; `BelongsTo` for customer and service stay (already null-tolerant)
- [ ] `CalendarIntegration` model: new fillables and casts; tokens encrypted

### Provider abstraction
- [ ] `app/Services/Calendar/CalendarProvider.php` interface with `pushEvent`, `updateEvent`, `deleteEvent`, `startWatch`, `stopWatch`, `syncIncremental`, `listCalendars`, `createCalendar`
- [ ] `GoogleCalendarProvider` implementing the interface; uses a private `clientFor(CalendarIntegration)` helper for the `Google\Client` with token-refresh callback that persists rotated access tokens
- [ ] `CalendarProviderFactory` resolving from a `CalendarIntegration` or `Booking`, bound in `AppServiceProvider`

### OAuth connect — configuration step (extends Session 1)
- [ ] After Google callback, render a configuration step before finalising the integration:
  - Dropdown: "Add riservo bookings to..." — lists the user's Google calendars plus a "Create a new dedicated calendar" option (which calls `createCalendar` if chosen)
  - Multi-select: "Check these calendars for conflicts" — primary pre-selected (per locked decision #4)
  - Preview: count of upcoming events that will become External Bookings
  - Conflict warning: list any imported events that overlap with confirmed riservo bookings
  - "Connect and import" finalises by saving config and dispatching `StartCalendarSyncJob`
- [ ] `StartCalendarSyncJob` (queued): calls `startWatch` and an initial `syncIncremental` (forward-only, per locked decision #3)
- [ ] `disconnect()` action now also calls `stopWatch` (best-effort try/catch) before deleting the integration

### Push sync — outbound
- [ ] `PushBookingToCalendarJob` (queued, `tries=3`, `backoff=[60,300,900]`); constructor takes `Booking` + action (`create` | `update` | `delete`)
- [ ] On `create`: store returned Google event ID in `bookings.external_calendar_id`
- [ ] On failure: write `push_error` and `push_error_at` on the integration
- [ ] Dispatch sites: every booking mutation point — public booking, manual dashboard booking, status transitions to cancelled / no_show / completed, confirmation of pending bookings, customer-side cancel, dashboard cancel, reschedule (Session 5 will reuse this)
- [ ] Skip dispatch when `booking.source === google_calendar` (those are pull-originated)
- [ ] Google event format: `SUMMARY` = `[Service] — [Customer]`; `DESCRIPTION` includes phone, internal notes, deep link back to riservo; `LOCATION` from business address; `extendedProperties.private.riservo_booking_id` = booking UUID; start/end in UTC

### Pull sync — inbound
- [ ] `POST /webhooks/google-calendar` endpoint, no auth, CSRF excluded in `bootstrap/app.php`; validate `X-Goog-Channel-Token` against `calendar_integrations.webhook_channel_token`; dispatch `PullCalendarEventsJob` and return 200 immediately
- [ ] `PullCalendarEventsJob` (queued, `tries=5`, `backoff=[60,120,300,600,1200]`)
- [ ] `syncIncremental` logic:
  - Use stored `syncToken`; full-sync fallback on 410
  - **Skip** events with `extendedProperties.private.riservo_booking_id` set if active
  - If a riservo-originated event was deleted in Google → create a `riservo_event_deleted_in_google` pending action (no auto-cancel, per locked decision #5)
  - For new/updated external events: upsert booking by `external_calendar_id` with `source = google_calendar`, `status = confirmed`, nullable customer/service, `external_title` from event summary; attempt to link first matching customer by attendee email (per locked decision #6)
  - For external events that overlap a confirmed riservo booking: import + create an `external_booking_conflict` pending action (per locked decision #5)
  - For cancelled external events: auto-cancel the corresponding `source = google_calendar` booking (no customer notification — there may be no customer)
  - Persist new `nextSyncToken` and `last_synced_at`
- [ ] On persistent error: write `sync_error` / `sync_error_at`

### Webhook renewal
- [ ] Artisan command `calendar:renew-watches`: integrations with `webhook_expiry < now + 24h` get `stopWatch` then `startWatch`
- [ ] Schedule daily at 03:00 in `routes/console.php`

### Pending actions UI
- [ ] Dashboard section "Calendar Sync — Pending Actions" listing unresolved items with count badge
- [ ] `riservo_event_deleted_in_google` row: actions "Cancel booking and notify customer" | "Keep booking and dismiss"
- [ ] `external_booking_conflict` row: actions "Keep both" | "Cancel external event" | "Cancel riservo booking" (with confirmation + customer notification on the last)
- [ ] Pending actions count surfaced in the Settings → Calendar Integration header

### Slot generator + UI
- [ ] `SlotGeneratorService`: nullsafe access on `$booking->service?->buffer_*` (external bookings have no service)
- [ ] Booking detail panel: when `source = google_calendar` show "External Event" + `external_title`, hide customer/service blocks, show "Open in Google Calendar" link (event htmlLink)
- [ ] Calendar views: external events get a Google icon and a neutral colour, visually distinct from riservo bookings
- [ ] Bookings list: filter to show/hide external events

### Notification suppression
- [ ] All Notification dispatch sites guard with `if ($booking->source !== BookingSource::GoogleCalendar)` (per locked decision #7)
- [ ] `SendBookingReminders` query excludes `source = google_calendar`

### Settings page (extends Session 1)
- [ ] Connected state: account email, destination calendar, conflict calendars summary, last synced timestamp, disconnect button, pending-actions count
- [ ] Error state: banner with error message and "Reconnect" CTA
- [ ] "Sync now" button for manual sync (debugging)

### Tests + ops
- [ ] Feature tests for OAuth callback config step, push dispatch matrix, webhook validation, pull-sync upsert/skip/cancel paths, webhook renewal, notification suppression, slot generator with null service, pending actions resolution
- [ ] Update `docs/DEPLOYMENT.md` with: Google Cloud Console OAuth setup; redirect URI exact match; webhook endpoint must be HTTPS-reachable (Google rejects HTTP); local dev requires a tunnel (ngrok / Expose / Herd Share); queue worker required for push and pull jobs; `calendar:renew-watches` daily cron
- [ ] Pint clean, full Pest suite green, `npm run build` clean

---

## Session 3 — Subscription Billing (Cashier)

**Owner**: single agent. **Prerequisites**: Session 2 (no hard dependency, but billing UX should land after the calendar feature so the upsell page can list it). **Unblocks**: nothing in this roadmap.

Stripe Cashier on the `Business` model with one paid tier (monthly + annual), indefinite trial, billing portal in the dashboard.

- [ ] Install Cashier; configure on the `Business` model (per locked decision #9; trait, migrations, casts, billable contract)
- [ ] Define one product in Stripe (test mode) with two prices (monthly + annual); store price IDs in `config/billing.php` reading from env
- [ ] Trial setup: new businesses are placed on indefinite trial automatically at registration; **no card collected at signup** (per locked decision #10)
- [ ] Billing portal page in the dashboard (admin-only) with sections: current plan + trial status, "Subscribe" CTA, manage payment method (Cashier's `redirectToBillingPortal`), download invoices (Cashier PDF), cancel subscription
- [ ] Stripe Checkout flow for new subscriptions; success and cancel URLs back to the billing page
- [ ] Webhook handler for: `customer.subscription.created`, `customer.subscription.updated`, `customer.subscription.deleted`, `invoice.payment_succeeded`, `invoice.payment_failed`
- [ ] Subscription state surfaced as Inertia shared prop (`auth.business.subscription`) so the UI can show "trial / active / past_due / canceled / read-only"
- [ ] Cancellation semantics: `cancel_at_period_end` (per locked decision #12); on period end, dashboard transitions to read-only with a "Resubscribe" CTA — exact enforcement layer (middleware? policy?) decided at plan time
- [ ] No hard plan limits in MVP (per locked decision #11) — do not add staff/booking caps
- [ ] Feature tests against Stripe test mode (mocked via Cashier's `Stripe::fake()` where possible) — full subscribe flow, webhook signature validation, plan-state transitions, billing portal redirect, invoice listing, cancel-at-period-end behaviour
- [ ] Update `docs/DEPLOYMENT.md` with: Stripe webhook endpoint URL, secret rotation procedure, switch from test to live keys (deferred to pre-launch), env var inventory
- [ ] Add new shared decision: subscription state model and the read-only enforcement strategy
- [ ] Pint clean, full Pest suite green, `npm run build` clean

---

## Session 4 — Provider Self-Service Settings

**Owner**: single agent. **Prerequisites**: Session 1 (settings role split established) + Session 2 (Calendar Integration page already exists in this group). **Unblocks**: Session 5 indirectly.

Open the settings area to providers (staff with a Provider row) for two self-managed surfaces: their own account and their own availability. Today both are admin-only and force admins to manage every provider's schedule on their behalf.

### Role split (extends Session 1's split)
- [ ] Audit current settings routes and document admin-only vs admin+staff in the session plan before any code change (the split must be explicit and reviewable)
- [ ] `settings-layout.tsx` derives nav visibility from role, not hardcoded lists; admin nav is the union (existing items + Account + Availability + Calendar Integration)
- [ ] Admin-only settings routes remain guarded; new shared routes added under the same `role:admin,staff` group used in Session 1
- [ ] Staff users without an active Provider row see Account + Calendar Integration, but **not** Availability (nothing to manage)
- [ ] Deactivated staff (soft-deleted `business_members` row) cannot reach any settings page — verify the existing `is_active` middleware applies, add it if missing

### Account page
- [ ] Route `GET /dashboard/settings/account` rendering `Dashboard/settings/account.tsx` (admin + staff)
- [ ] Sections: profile (name, email — re-verification flow on email change, consistent with existing `verification.notice`), password (require current password, OR set first password if user is magic-link-only, OR change), avatar (upload + remove)
- [ ] Avatar upload reuses the immediate-upload endpoint pattern from D-042
- [ ] Controller `Dashboard\Settings\AccountController` with `show`, `updateProfile`, `updatePassword`, `uploadAvatar`, `removeAvatar`
- [ ] Feature tests: admin can update own profile; staff can update own profile; staff cannot update another user; password update rejects wrong current password; magic-link-only user can set first password without current; avatar upload + remove work

### Availability page
- [ ] Route `GET /dashboard/settings/availability` rendering `Dashboard/settings/availability.tsx` (admin + staff with active Provider row)
- [ ] Always scoped to `auth()->user()` within the current business — never accepts a `provider_id` from the request
- [ ] Sections: weekly schedule editor (reuse the admin-side `provider-schedule-form`, scoped to the current user — extract as a shared component if not already shared), exceptions (add / edit / delete), service attachments (read-only display; admin still manages which services a provider performs)
- [ ] Controller `Dashboard\Settings\AvailabilityController` with `show`, `updateSchedule`, `storeException`, `updateException`, `destroyException`
- [ ] Reuse existing form requests and `AvailabilityService` calls — no parallel logic
- [ ] Feature tests: provider can update own schedule; provider can CRUD own exceptions; provider cannot edit another provider's data via this route; admin opted-in as their own provider edits their own data through this page (not all providers)

### Out of scope (do not refactor)
- [ ] Do not touch admin-only settings controllers
- [ ] Do not expose business-level settings to staff
- [ ] Do not change which services a provider performs from the staff side (admin-only)

### Tests + ops
- [ ] Pint clean, full Pest suite green, `npm run build` clean
- [ ] Update `docs/HANDOFF.md`; record any new decision (e.g., the explicit settings access matrix) under `docs/decisions/DECISIONS-DASHBOARD-SETTINGS.md`

---

## Session 5 — Advanced Calendar Interactions

**Owner**: single agent. **Prerequisites**: Sessions 1–4. Session 2 is required because rescheduled bookings push to Google. Session 4 is required because providers must be able to drag their own bookings.

Transform the dashboard calendar from passive read view into an interactive workspace. Also fix two correctness bugs surfaced by REVIEW-1 (issue #8) before adding new interactions.

### Bug fixes (do first)
- [ ] Fix nested `<li>` / `<li>` hydration error in `current-time-indicator.tsx` and `week-view.tsx`
- [ ] Add a mobile view-switcher so the calendar is usable on small screens (pattern is the agent's call — bottom bar, dropdown, sheet — pick the one that matches the existing layout)
- [ ] Verify week-view booking blocks are visible on small screens or add a sensible mobile fallback (e.g., collapse to a list below the grid)

### Click-to-create
- [ ] Week and day views: clicking an empty time cell opens the existing manual-booking dialog pre-populated with the clicked date and time
- [ ] Month view: clicking an empty day cell opens the dialog pre-populated with that date and time defaulting to the business's opening hour
- [ ] Admin clicking a cell in another provider's column pre-selects that provider in the dialog
- [ ] The existing manual booking dialog is reused with minimal changes — wiring is the agent's call

### Drag and drop — move a booking
- [ ] Use `@dnd-kit/core` (per locked decision #13), lazy-loaded only on the calendar route to keep the bundle bounded
- [ ] Week and day views only (per locked decision #14): drag a booking event to a new time slot on the same day or a different day within the visible range
- [ ] Optimistic UI: move the event immediately on drop, revert on validation failure
- [ ] Validation: call the new `reschedule` endpoint (below); show inline error if the new slot is taken
- [ ] Staff can only drag their own bookings; admin can drag anyone's
- [ ] Month view drag is out of scope

### Resize — change duration
- [ ] Bottom-edge resize handle on the booking event in week and day views; vertical drag changes duration
- [ ] Snap to `service.slot_interval_minutes` (per locked decision #15); enforce server-side, snap client-side
- [ ] Optimistic UI consistent with drag-and-drop; revert on conflict

### Hover preview
- [ ] All views: hover shows a small popover/tooltip with customer name, service name, start–end time, status chip
- [ ] 250–400 ms delay to avoid flicker
- [ ] Use the COSS UI Tooltip / Popover primitive — no custom implementation
- [ ] Click still opens the full detail panel

### UX polish (agent evaluates and ships what is clearly worth it)
- [ ] Today's date / column visually distinct in all three views (not only via the time indicator)
- [ ] "Jump to date" quick-nav: compact date picker that navigates directly to a chosen date
- [ ] Keyboard navigation: arrow keys to move between dates in week/day; `t` to jump to today (only if low-effort within this session)
- [ ] Loading state for calendar navigation (skeleton or spinner during Inertia partial reload)

### Backend
- [ ] `PATCH /dashboard/bookings/{booking}/reschedule` — validates new `starts_at` + `ends_at`, runs the same availability rules booking creation does, updates the booking, returns JSON
- [ ] Endpoint dispatches `PushBookingToCalendarJob` on success when the booking's provider has a `CalendarIntegration` (Session 2 dependency)
- [ ] Dispatches the customer-facing reschedule notification (per locked decision #16); suppressed when `source = google_calendar`
- [ ] Authorization: staff can reschedule only their own bookings; deactivated providers' bookings cannot be rescheduled

### Tests + ops
- [ ] Feature tests: reschedule to a free slot succeeds; reschedule to an occupied slot returns a validation error; cross-provider reschedule rejected; deactivated provider's bookings cannot be rescheduled; smoke test for click-to-create reuses the manual-booking test infrastructure
- [ ] Existing calendar tests stay green
- [ ] Pint clean, full Pest suite green, `npm run build` clean
- [ ] Update `docs/HANDOFF.md`; record `@dnd-kit/core` choice and the reschedule notification policy under `docs/decisions/DECISIONS-FRONTEND-UI.md` (or the relevant topical file)

---

## Cleanup tasks (after this roadmap is approved)

These are housekeeping moves to make the docs reflect the new single-source-of-truth, performed before Session 1 starts. Listed here so they are not forgotten — they are not part of the sessions themselves.

- [ ] Mark Sessions 12 and 13 in `docs/ROADMAP.md` as superseded by this roadmap (or remove them, leaving a one-line pointer)
- [ ] Move `docs/roadmaps/ROADMAP-FEATURES.md` to `docs/archive/roadmaps/` — fully superseded
- [ ] Move `docs/roadmaps/ROADMAP-CALENDAR.md` to `docs/archive/roadmaps/` and add a one-line pointer note that Phase 2 is now Session 2 here, Phase 1 (ICS) is in `docs/BACKLOG.md`, Phase 3 (Outlook/Apple) remains post-MVP reference
- [ ] Delete the obsolete `docs/plans/PLAN-SESSION-12.md` (it pre-dates current decisions and re-uses occupied D-IDs); the Session 2 agent will write a fresh plan
- [ ] Add an "ICS one-way feed" note to `docs/BACKLOG.md` so it is recoverable if user research justifies it later
- [ ] Update `docs/README.md` Read-First section to point to this roadmap as the active delivery plan; `docs/ROADMAP.md` becomes the historical MVP plan
- [ ] Leave `docs/roadmaps/ROADMAP-E2E.md` and `docs/roadmaps/ROADMAP-GROUP-BOOKINGS.md` in place — independent of this work

---

*This roadmap defines the WHAT. The HOW is decided per session by the implementing agent in a dedicated plan document under `docs/plans/`. Each session leaves the full Pest suite green, Pint clean, and the Vite build green before close.*
