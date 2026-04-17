# riservo.ch — MVP Completion Roadmap

> Version: 1.0
> **Status: FULLY SHIPPED (2026-04-17)** — all five sessions delivered. See `docs/roadmaps/ROADMAP-PAYMENTS.md` for the next active roadmap (customer-to-professional Stripe Connect payments, post-MVP).
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
- [x] Migration: `bookings.customer_id` → nullable; `bookings.service_id` → nullable; add `bookings.external_title` + `bookings.external_html_link` (per locked decision #2, D-084 revised)
- [x] Migration: extend `calendar_integrations` with `business_id` (FK, per D-085), `destination_calendar_id`, `conflict_calendar_ids` (JSON), `sync_token`, `webhook_resource_id`, `webhook_channel_token`, `last_synced_at`, `last_pushed_at`, `sync_error`, `sync_error_at`, `push_error`, `push_error_at`
- [x] New table `calendar_pending_actions`: `id`, `business_id`, `integration_id`, `booking_id` (nullable), `type`, `payload` JSON, `status`, `resolved_by_user_id`, `resolution_note`, `resolved_at`, timestamps
- [x] New table `calendar_watches`: one row per watched Google calendar (D-086)
- [x] `Booking` model: `external_title` + `external_html_link` fillable; helpers `shouldPushToCalendar`, `shouldSuppressCustomerNotifications`, `serviceLabel`
- [x] `CalendarIntegration` model: new fillables/casts; `isConfigured()`, `watches()`, `pendingActions()`, `business()` relations

### Provider abstraction
- [x] `app/Services/Calendar/CalendarProvider.php` interface with `listCalendars`, `createCalendar`, `pushEvent`, `updateEvent`, `deleteEvent`, `startWatch`, `stopWatch`, `syncIncremental` (D-082)
- [x] `GoogleCalendarProvider` implementing the interface; `GoogleClientFactory` wires `setTokenCallback` so rotated access tokens persist to the row
- [x] `CalendarProviderFactory::for($integration)` bound as a singleton in `AppServiceProvider::register()`

### OAuth connect — configuration step (extends Session 1)
- [x] Callback redirects to new `settings.calendar-integration.configure` step instead of finalising directly
- [x] Configure page renders the `listCalendars` dropdown + multi-select with primary pre-selected
- [x] "Create a new dedicated calendar" option calls `createCalendar` on save
- [x] Save-configuration pins `business_id` (D-085), persists destination + conflicts, dispatches `StartCalendarSyncJob`
- [x] `StartCalendarSyncJob` (queued) calls `startWatch` + initial `syncIncremental` per distinct calendar (forward-only per locked decision #3)
- [x] `disconnect()` iterates `$integration->watches` and calls `stopWatch` best-effort before deleting

### Push sync — outbound
- [x] `PushBookingToCalendarJob(int $bookingId, string $action)`, `tries=3`, `backoff=[60,300,900]`, `afterCommit()` (D-083)
- [x] On `create`: stores returned Google event ID in `bookings.external_calendar_id`
- [x] On final failure: writes `push_error` + `push_error_at`
- [x] Dispatch sites wired: public booking create, manual dashboard create, status transitions, customer cancel, token cancel
- [x] `Booking::shouldPushToCalendar()` gates every dispatch (skips `source = google_calendar` + unconfigured integrations)
- [x] Google event format: summary `[Service] — [Customer]`, description = phone + notes, location = business address, `extendedProperties.private.riservo_booking_id` + `riservo_business_id`, UTC start/end. No deep-link in description (review: out of scope for MVPC-2).

### Pull sync — inbound
- [x] `POST /webhooks/google-calendar` (no auth, CSRF-excluded in `bootstrap/app.php`); validates `X-Goog-Channel-Id` and `X-Goog-Channel-Token` (constant-time via `hash_equals`), dispatches `PullCalendarEventsJob`, returns 200
- [x] `PullCalendarEventsJob(int $integrationId, string $calendarId)`, `tries=5`, `backoff=[60,120,300,600,1200]`, `afterCommit()`
- [x] 410 → clear sync token → forward-only retry
- [x] Own-event echo detection via `extendedProperties.private.riservo_booking_id`
- [x] Riservo event deleted in Google → `riservo_event_deleted_in_google` pending action (no auto-cancel)
- [x] New/updated external events → upsert external booking; first-match customer by attendee email (locked decision #6); `external_title` + `external_html_link` persisted
- [x] Overlap with confirmed riservo booking → rollback, create `external_booking_conflict` pending action with `conflict_booking_ids[]` in payload
- [x] Cancelled foreign events → auto-cancel the corresponding external booking
- [x] Persists `sync_token` + `last_synced_at`; `failed()` writes `sync_error` + `sync_error_at`

### Webhook renewal
- [x] `calendar:renew-watches` artisan command: refreshes `calendar_watches` expiring within 24h
- [x] Scheduled daily at 03:00 in `routes/console.php`

### Pending actions UI
- [x] Dashboard section "Calendar sync — pending actions" with per-row resolution buttons (viewer-scoped per D-085 + D-088)
- [x] `riservo_event_deleted_in_google`: "Cancel booking and notify customer" | "Keep booking and dismiss"
- [x] `external_booking_conflict`: "Keep riservo booking (ignore external)" | "Cancel external event" | "Cancel riservo booking" (D-087 revised — cancel path re-dispatches `PullCalendarEventsJob`)
- [x] Pending-actions count surfaced in settings nav + settings page header via `calendarPendingActionsCount` shared Inertia prop

### Slot generator + UI
- [x] `SlotGeneratorService::getBlockingBookings` no longer eager-loads `service` (D-066 already reads effective_* columns directly; removes null-service footgun)
- [x] Booking detail sheet: external events render with `CalendarDays` icon, hide customer/service blocks, surface "Open in Google Calendar" link
- [x] Calendar views (day/week/month): external events use `EXTERNAL_EVENT_COLOR` neutral palette (D-084)
- [x] Bookings list: `?include_external=0` filter support; default is "include"

### Notification suppression
- [x] All customer + staff notification dispatch sites guard with `Booking::shouldSuppressCustomerNotifications()` (D-088)
- [x] `SendBookingReminders` excludes `source = google_calendar` at query time

### Settings page (extends Session 1)
- [x] Connected state: account email, destination calendar, conflict summary, last synced, disconnect, pending-actions badge, "Change settings", "Sync now", pinned-business notice
- [x] Error state: Alert banner driven by `integration.sync_error`
- [x] "Sync now" button dispatches `PullCalendarEventsJob` per watched calendar

### Tests + ops
- [x] Feature tests: push job, pull job (seven cases), webhook controller (five cases), configure flow, pending-action resolution (including owner-or-admin matrix), renew watches, notification suppression, slot generation with null service
- [x] `docs/DEPLOYMENT.md` updated: Google Cloud OAuth setup, HTTPS webhook requirement, local tunnel guidance, queue worker requirement, daily `calendar:renew-watches` cron, env var inventory
- [x] Pint clean, Feature + Unit suite **574 passed / 2438 assertions** (+39 vs MVPC-1 baseline 535), `npm run build` clean, Wayfinder regenerated

**Session 2 closed 2026-04-17.** D-082 through D-088 recorded in `docs/decisions/DECISIONS-CALENDAR-INTEGRATIONS.md`.

---

## Session 3 — Subscription Billing (Cashier)

**Owner**: single agent. **Prerequisites**: Session 2 (no hard dependency, but billing UX should land after the calendar feature so the upsell page can list it). **Unblocks**: nothing in this roadmap.

Stripe Cashier on the `Business` model with one paid tier (monthly + annual), indefinite trial, billing portal in the dashboard.

- [x] Install Cashier; configure on the `Business` model (per locked decision #9; trait, migrations, casts, billable contract)
- [x] Define one product in Stripe (test mode) with two prices (monthly + annual); store price IDs in `config/billing.php` reading from env
- [x] Trial setup: new businesses are placed on indefinite trial automatically at registration; **no card collected at signup** (per locked decision #10)
- [x] Billing portal page in the dashboard (admin-only) with sections: current plan + trial status, "Subscribe" CTA, manage payment method (Cashier's `redirectToBillingPortal`), download invoices (Cashier PDF), cancel subscription
- [x] Stripe Checkout flow for new subscriptions; success and cancel URLs back to the billing page
- [x] Webhook handler for: `customer.subscription.created`, `customer.subscription.updated`, `customer.subscription.deleted`, `invoice.payment_succeeded`, `invoice.payment_failed`
- [x] Subscription state surfaced as Inertia shared prop (`auth.business.subscription`) so the UI can show "trial / active / past_due / canceled / read-only"
- [x] Cancellation semantics: `cancel_at_period_end` (per locked decision #12); on period end, dashboard transitions to read-only with a "Resubscribe" CTA — exact enforcement layer (middleware? policy?) decided at plan time
- [x] No hard plan limits in MVP (per locked decision #11) — do not add staff/booking caps
- [x] Feature tests against Stripe test mode (mocked via Cashier's `Stripe::fake()` where possible) — full subscribe flow, webhook signature validation, plan-state transitions, billing portal redirect, invoice listing, cancel-at-period-end behaviour
- [x] Update `docs/DEPLOYMENT.md` with: Stripe webhook endpoint URL, secret rotation procedure, switch from test to live keys (deferred to pre-launch), env var inventory
- [x] Add new shared decision: subscription state model and the read-only enforcement strategy
- [x] Pint clean, full Pest suite green, `npm run build` clean

**Session 3 closed 2026-04-17.** D-089 through D-095 recorded in `docs/decisions/DECISIONS-FOUNDATIONS.md`. Cashier resolved to v16 (v15 is incompatible with Laravel 13). Feature + Unit suite at **638 passed / 2640 assertions** (+56 cases vs MVPC-2 baseline 582; includes fourteen cases added across three review rounds — see HANDOFF "Post-review fixes" for the seven bugs addressed).

---

## Session 4 — Provider Self-Service Settings

**Owner**: single agent. **Prerequisites**: Session 1 (settings role split established) + Session 2 (Calendar Integration page already exists in this group). **Unblocks**: Session 5 indirectly.

Open the settings area to providers (staff with a Provider row) for two self-managed surfaces: their own account and their own availability. Today both are admin-only and force admins to manage every provider's schedule on their behalf.

### Role split (extends Session 1's split)
- [x] Audit current settings routes and document admin-only vs admin+staff in the session plan before any code change (the split must be explicit and reviewable)
- [x] `settings-layout.tsx` derives nav visibility from role, not hardcoded lists; admin nav is the union (existing items + Account + Availability + Calendar Integration)
- [x] Admin-only settings routes remain guarded; new shared routes added under the same `role:admin,staff` group used in Session 1
- [x] Staff users without an active Provider row see Account + Calendar Integration, but **not** Availability (nothing to manage)
- [x] Deactivated staff (soft-deleted `business_members` row) cannot reach any settings page — `ResolveTenantContext`'s `BusinessMember::query()` already auto-applies the SoftDeletingScope, so no new middleware needed; locked by `SettingsAuthorizationTest`

### Account page
- [x] Route `GET /dashboard/settings/account` rendering `dashboard/settings/account.tsx` (admin + staff)
- [x] Sections: profile (name, email — re-verification flow on email change, consistent with existing `verification.notice`), password (require current password, OR set first password if user is magic-link-only, OR change), avatar (upload + remove)
- [x] Avatar upload reuses the immediate-upload endpoint pattern from D-042 (new self-scoped endpoint, validation rules + JSON shape mirrored from `StaffController.uploadAvatar`)
- [x] Controller `Dashboard\Settings\AccountController` with `edit`, `updateProfile`, `updatePassword`, `uploadAvatar`, `removeAvatar`, plus admin-only `toggleProvider`
- [x] Feature tests: admin can update own profile; staff can update own profile; password update rejects wrong current password; magic-link-only user can set first password without current; avatar upload + remove work; email-change nulls verified_at + dispatches `VerifyEmail`

### Availability page
- [x] Route `GET /dashboard/settings/availability` rendering `dashboard/settings/availability.tsx` (admin + staff with active Provider row)
- [x] Always scoped to `auth()->user()` within the current business — never accepts a `provider_id` from the request
- [x] Sections: weekly schedule editor (reuses existing `WeekScheduleEditor`), exceptions (add / edit / delete via existing `ExceptionDialog`), service attachments (read-only display for staff, editable for admin-as-self-provider)
- [x] Controller `Dashboard\Settings\AvailabilityController` with `show`, `updateSchedule`, `storeException`, `updateException`, `destroyException`, `updateServices` (admin-only)
- [x] Reuse existing form requests (`UpdateProviderScheduleRequest`, `StoreProviderExceptionRequest`, `UpdateProviderExceptionRequest`, `UpdateProviderServicesRequest`) with `authorize()` relaxed to `return true` and route middleware as the gate; schedule helpers extracted to `App\Services\ProviderScheduleService` and shared with `AccountController.toggleProvider`
- [x] Feature tests: provider can update own schedule; provider can CRUD own exceptions; provider cannot edit another provider's data via this route; admin opted-in as their own provider edits their own data through this page (not all providers); staff cannot edit services they perform; cross-business isolation locked

### Out of scope (do not refactor)
- [x] Did not touch admin-only `StaffController`, `ProviderController`, `BillingController`
- [x] Did not expose business-level settings to staff
- [x] Did not change which services a provider performs from the staff side (admin-only)

### Tests + ops
- [x] Pint clean, Feature + Unit suite **669 passed / 2758 assertions** (+31 vs MVPC-3 baseline 638), `npm run build` clean, Wayfinder regenerated
- [x] Update `docs/HANDOFF.md`; record D-096..D-099 under `docs/decisions/DECISIONS-DASHBOARD-SETTINGS.md` with cross-reference in DECISIONS-AUTH.md (D-097)

**Session 4 closed 2026-04-17.** D-096 through D-099 recorded in `docs/decisions/DECISIONS-DASHBOARD-SETTINGS.md`. Feature + Unit suite at **669 passed / 2758 assertions** (+31 cases vs MVPC-3 baseline 638).

---

## Session 5 — Advanced Calendar Interactions

**Owner**: single agent. **Prerequisites**: Sessions 1–4. Session 2 is required because rescheduled bookings push to Google. Session 4 is required because providers must be able to drag their own bookings.

Transform the dashboard calendar from passive read view into an interactive workspace. Also fix two correctness bugs surfaced by REVIEW-1 (issue #8) before adding new interactions.

### Bug fixes (do first)
- [x] Fix nested `<li>` / `<li>` hydration error in `current-time-indicator.tsx` and `week-view.tsx` (audit: already closed by D-069; locked with pure-PHP regex guards in `tests/Unit/Frontend/CalendarMarkupContractTest.php`)
- [x] Add a mobile view-switcher so the calendar is usable on small screens (already shipped per D-069; regression-tested)
- [x] Verify week-view booking blocks are visible on small screens or add a sensible mobile fallback (agenda fallback already shipped per D-069; regression-tested)

### Click-to-create
- [x] Week and day views: clicking an empty time cell opens the existing manual-booking dialog pre-populated with the clicked date and time
- [x] Month view: clicking an empty day cell opens the dialog pre-populated with that date and time defaulting to the business's opening hour
- [x] Admin clicking a cell in another provider's column pre-selects that provider in the dialog (deferred to per-provider column view via D-102 — combined view has no column signal; dialog's auto-assign branch handles the MVP case)
- [x] The existing manual booking dialog is reused with minimal changes — wiring is the agent's call

### Drag and drop — move a booking
- [x] Use `@dnd-kit/core` (per locked decision #13), lazy-loaded only on the calendar route to keep the bundle bounded (D-100)
- [x] Week and day views only (per locked decision #14): drag a booking event to a new time slot on the same day or a different day within the visible range
- [x] Optimistic UI: move the event immediately on drop, revert on validation failure
- [x] Validation: call the new `reschedule` endpoint (below); show inline error if the new slot is taken
- [x] Staff can only drag their own bookings; admin can drag anyone's
- [x] Month view drag is out of scope

### Resize — change duration
- [x] Bottom-edge resize handle on the booking event in week and day views; vertical drag changes duration
- [x] Snap to `service.slot_interval_minutes` (per locked decision #15); enforce server-side, snap client-side (D-106)
- [x] Optimistic UI consistent with drag-and-drop; revert on conflict

### Hover preview
- [x] All views: hover shows a small popover/tooltip with customer name, service name, start–end time, status chip
- [x] 250–400 ms delay to avoid flicker (300 ms open / 120 ms close)
- [x] Use the COSS UI Tooltip / Popover primitive — no custom implementation
- [x] Click still opens the full detail panel

### UX polish (agent evaluates and ships what is clearly worth it)
- [x] Today's date / column visually distinct in all three views (not only via the time indicator) — already shipped pre-session; verified
- [x] "Jump to date" quick-nav: compact date picker that navigates directly to a chosen date
- [x] Keyboard navigation: arrow keys to move between dates in week/day; `t` to jump to today
- [x] Loading state for calendar navigation (skeleton or spinner during Inertia partial reload)

### Backend
- [x] `PATCH /dashboard/bookings/{booking}/reschedule` — validates new `starts_at` + `duration_minutes` (D-105), runs the same availability rules booking creation does (via new `SlotGeneratorService::getAvailableSlots(excluding: $booking)`), updates the booking, returns JSON
- [x] Endpoint dispatches `PushBookingToCalendarJob` on success when the booking's provider has a `CalendarIntegration` (Session 2 dependency)
- [x] Dispatches the customer-facing reschedule notification (per locked decision #16); suppressed when `source = google_calendar`
- [x] Authorization: staff can reschedule only their own bookings; deactivated providers' bookings cannot be rescheduled

### Tests + ops
- [x] Feature tests: reschedule to a free slot succeeds; reschedule to an occupied slot returns a validation error; cross-provider reschedule rejected; deactivated provider's bookings cannot be rescheduled; GIST race returns 409 or 422; external-booking reschedule refused; terminal-status reschedule refused; off-grid reschedule refused; straddling-two-days reschedule refused
- [x] Existing calendar tests stay green
- [x] Pint clean, Feature + Unit suite **693 passed / 2814 assertions** (+22 cases vs MVPC-4 baseline 671 — drift from 669 reported in MVPC-4 HANDOFF), `npm run build` green (main chunk -310 KB, dnd-kit chunk 14.33 KB gzipped)
- [x] Update `docs/HANDOFF.md`; record D-100..D-108 across `DECISIONS-FRONTEND-UI.md` and `DECISIONS-BOOKING-AVAILABILITY.md`

**Session 5 closed 2026-04-17.** D-100 through D-108 recorded. ROADMAP-MVP-COMPLETION fully shipped. Feature + Unit suite at **693 passed / 2814 assertions**.

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
