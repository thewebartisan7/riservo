# Handoff

> **ROADMAP-MVP-COMPLETION fully shipped (2026-04-17).** All five sessions delivered:
> MVPC-1 (`5388d8a`) Google OAuth foundation · MVPC-2 (`132535e`) Google Calendar bidirectional sync · MVPC-3 (`8da1c5d`) Subscription billing (Cashier) · MVPC-4 (`3eb5bab`) Provider self-service settings · MVPC-5 **(this commit)** Advanced calendar interactions.
> Next active roadmap: `docs/roadmaps/ROADMAP-PAYMENTS.md` (customer-to-professional Stripe Connect payments, post-MVP).

**Session**: MVPC-5 — Advanced Calendar Interactions (Session 5 of `docs/roadmaps/ROADMAP-MVP-COMPLETION.md`, final)
**Date**: 2026-04-17
**Status**: Code complete; Feature + Unit suite **693 passed / 2814 assertions** (MVPC-4 reported 669; +22 cases delivered plus +2 cases of pre-session drift). Pint clean. `npm run build` green. Wayfinder regenerated. Plan archived to `docs/archive/plans/PLAN-MVPC-5-CALENDAR-INTERACTIONS.md`.

> Full-suite Browser/E2E run is the developer's session-close check (`tests/Browser` takes 2+ minutes). The iteration loop used `php artisan test tests/Feature tests/Unit --compact` throughout.

---

## What Was Built

MVPC-5 turns the dashboard calendar from a read-only view into an interactive workspace:

- **Click an empty cell → create booking.** Week + day views seed the clicked date + time; month view seeds date only. Existing multi-step `ManualBookingDialog` re-used with a new `initial` prop.
- **Drag a booking → reschedule.** Week + day views (locked decision #14). `@dnd-kit/core` is lazy-loaded via a React.lazy() shell (D-100). Optimistic UI via the new `PATCH /dashboard/bookings/{booking}/reschedule` endpoint. Cross-day drag within the visible week is supported; cross-provider drag is out of scope (D-104).
- **Resize a booking → change duration.** Bottom-edge resize handle (D-107), 8 px, always visible at low opacity. Snaps to 15 min client-side; server enforces `service.slot_interval_minutes` and rejects off-grid with 422 (D-106).
- **Hover preview on every view.** COSS UI tooltip primitive (`BookingHoverCard`) with 300 ms delay. Click still opens the full detail sheet.
- **UX polish**: Jump-to-date popover in the header (COSS Calendar inside Popover). Keyboard nav (`←` / `→` / `t`). Spinner during calendar Inertia partial reloads. Reschedule error banner.
- **Two REVIEW-1 #8 items**: audit found the nested-`<li>` hydration bug and the mobile-view-switcher hiding were both already closed by 191c029 + 9eea354 (D-069). Locked with pure-PHP regex guards in `tests/Unit/Frontend/CalendarMarkupContractTest.php` — regression-test strategy, not re-fix.
- **Staff can create manual bookings via click-to-create (D-103).** The dialog's `isAdmin` gate on the calendar page was purely frontend-cosmetic; server already accepted staff writes.

Plan: `docs/archive/plans/PLAN-MVPC-5-CALENDAR-INTERACTIONS.md`. Nine new decisions:

- **D-100** — `@dnd-kit/core` lazy-loaded via `<DndCalendarShell>`. Vite chunks split; main bundle shrank from ~999 kB → ~689 kB. Dnd-kit chunk 43.24 kB raw / **14.33 kB gzipped** (budget was < 50 kB gzipped).
- **D-101** — Drag preview uses `DragOverlay`. Source card fades to 40% opacity while overlay follows the cursor.
- **D-102** — Click-to-create does NOT seed `providerId` in MVP (combined view has no per-column signal).
- **D-103** — Staff can create manual bookings via click-to-create (pre-condition verified: `role:admin,staff` on route, `tenant()->has()` in FormRequest).
- **D-104** — Drag is scoped to same-provider same-business (cross-provider is out of scope per locked #14).
- **D-105** — Reschedule request shape is `{ starts_at, duration_minutes }` (server recomputes `ends_at`).
- **D-106** — Non-grid `starts_at` → 422 with a friendly error. No silent snap.
- **D-107** — Always-visible 8 px resize handle, low opacity at rest. Suppressed on tight events.
- **D-108** — `BookingRescheduledNotification` mirrors `BookingConfirmedNotification` shape and includes the `bookings.show` management link.

### Backend

- **New** `app/Http/Controllers/Dashboard/BookingController::reschedule(RescheduleBookingRequest, Booking)` — 404 on cross-business, 403 on cross-provider-as-staff, 422 on external source / terminal status / trashed provider / off-grid / straddle-two-days / occupied slot. Transaction + GIST 23P01 → 409. Reuses `SlotGeneratorService::getAvailableSlots($business, $service, $date, $provider, excluding: $booking)`. Dispatches `PushBookingToCalendarJob` (action='update') + `BookingRescheduledNotification`, both gated by the existing `shouldPushToCalendar()` / `shouldSuppressCustomerNotifications()` helpers (D-083 / D-088).
- **New** `app/Http/Requests/Dashboard/RescheduleBookingRequest.php` — shape-only validation (`starts_at: date`, `duration_minutes: int|1..1440`). Business-rule checks live in the controller.
- **Modified** `app/Services/SlotGeneratorService.php` — `getAvailableSlots()` + underlying `getSlotsForProvider()` / `getSlotsForAnyProvider()` / `getBlockingBookings()` take an optional `?Booking $excluding = null` parameter. When present, the blocking-booking query excludes that row so a booking cannot block its own move (D-066-aligned).
- **New** `app/Notifications/BookingRescheduledNotification.php` — queued, `afterCommit()`, mirrors `BookingConfirmedNotification` + `previousStartsAt` / `previousEndsAt` constructor args.
- **New** `resources/views/mail/booking-rescheduled.blade.php` — Markdown mail Blade mirroring the confirmed template.

### Routes

- **New** `PATCH /dashboard/bookings/{booking}/reschedule` — named `dashboard.bookings.reschedule`. Inside the existing `role:admin,staff` + `billing.writable` dashboard group (gate is inherited for free per D-090).

### Frontend

- **New** `resources/js/components/calendar/dnd-calendar-shell.tsx` — the only module that statically imports `@dnd-kit/core` + `@dnd-kit/modifiers`. React.lazy()-loaded from `calendar.tsx`. Provides `DraggableBooking` + `ResizeHandle` via `DndCalendarContext`. Drag math: `deltaMinutes = delta.y × minutesPerPixel` snapped to 15 min; `deltaDays = delta.x ÷ columnWidth` on week view only; cross-day cross-time support.
- **New** `resources/js/components/calendar/dnd-context.tsx` — pass-through context, no dnd-kit imports. Views read `DraggableBooking` / `ResizeHandle` from this context; when outside the shell, both fall back to render-pass-through / null.
- **New** `resources/js/components/calendar/booking-hover-card.tsx` — COSS UI Tooltip wrap around any calendar event. Shows service / customer / time / status chip. 300 ms delay open, 120 ms close.
- **New** `resources/js/hooks/use-reschedule.ts` — thin HTTP hook around the reschedule endpoint. Returns `{ success, booking?, message? }`. Reloads the calendar bookings on success; caller handles failure message.
- **Modified** `resources/js/pages/dashboard/calendar.tsx` — `<Suspense>` + lazy `<DndCalendarShell>`, click-to-create seed state, error banner, keyboard nav, loading indicator. Manual-booking dialog now mounts for admin + staff (D-103).
- **Modified** `resources/js/components/calendar/calendar-event.tsx` — renders `<BookingHoverCard>` around the event button; wraps in `DraggableBooking` + renders `ResizeHandle` when interactive (pending/confirmed and not external).
- **Modified** `resources/js/components/calendar/week-view.tsx` — empty-cell click handler on `<ol>` maps click coords to `{ date, time }` seed; grid container ref forwarded to the shell.
- **Modified** `resources/js/components/calendar/day-view.tsx` — same as week-view, single-day flavour.
- **Modified** `resources/js/components/calendar/month-view.tsx` — day-cell click (when not on a booking pill) seeds `{ date }` with no time.
- **Modified** `resources/js/components/calendar/calendar-header.tsx` — Jump-to-date popover between Today and view Select.
- **Modified** `resources/js/components/dashboard/manual-booking-dialog.tsx` — new `initial?: { date?, time?, providerId? }` prop seeds state on open.

### Tests

- **New** `tests/Unit/Frontend/CalendarMarkupContractTest.php` — 7 pure-PHP cases. Regression guards for REVIEW-1 #8: no `<li>` nested under `<li>`; no `<CurrentTimeIndicator>` wrapped in `<li>`; mobile agenda fallback in week-view; view Select not re-hidden below md. No Node.js dep; `file_get_contents()` + regex over the view files.
- **New** `tests/Feature/Dashboard/Booking/RescheduleBookingTest.php` — 13 cases covering the endpoint's full behaviour matrix: free slot succeed (admin + staff + with-integration push dispatched); staff cross-provider forbidden; trashed provider 422; occupied slot 422; external source 422; off-grid 422; cross-business 404; terminal status 422; straddle two days 422; GIST race (409 or 422); external notification suppression.
- **New** `tests/Feature/Calendar/SlotGenerationExcludingBookingTest.php` — 2 cases. Positive: booking's own slot is available when excluded. Negative: unrelated bookings still block.
- `tests/Feature/Billing/ReadOnlyEnforcementTest.php` — the structural invariant test (walks the route table) automatically covers the new reschedule route; no dataset change needed.

### Decisions recorded

D-100..D-103, D-107, D-108 in `docs/decisions/DECISIONS-FRONTEND-UI.md`. D-104, D-105, D-106 in `docs/decisions/DECISIONS-BOOKING-AVAILABILITY.md`.

### Dependencies

- **New**: `@dnd-kit/core@^6.3.1`, `@dnd-kit/modifiers@^9.0.0`.

---

## Current Project State

- **Backend**:
  - `App\Http\Controllers\Dashboard\BookingController::reschedule` — new JSON endpoint + private `bookingPayload()` helper.
  - `App\Http\Requests\Dashboard\RescheduleBookingRequest` — new.
  - `App\Notifications\BookingRescheduledNotification` — new, queued with `afterCommit()`.
  - `App\Services\SlotGeneratorService` — `$excluding` parameter added to `getAvailableSlots()` + internal helpers. Backwards-compatible default `null`.
- **Routes**: 1 new route (`dashboard.bookings.reschedule`). Inherits `billing.writable` from the outer group (verified by the existing structural invariant test).
- **Frontend**: 4 new files (`dnd-calendar-shell`, `dnd-context`, `booking-hover-card`, `use-reschedule`). 7 modified files (page, 3 view components, calendar-event, calendar-header, manual-booking-dialog).
- **Tests**: Feature + Unit **693 passed / 2814 assertions** (+22 cases over MVPC-4's 671-post-drift baseline). Browser suite untouched.
- **Bundle**: main chunk `app-*.js` = 689.30 kB (was 998.70 kB — shrank by 310 kB because calendar-related shared modules lifted to a sibling chunk). `dnd-calendar-shell-*.js` = 43.24 kB raw / **14.33 kB gzipped** (budget was < 50 kB gzipped). `dnd-context-*.js` = 317 kB (reshuffled calendar page code, not new dependency weight).
- **Decisions**: D-100..D-108 recorded across `DECISIONS-FRONTEND-UI.md` and `DECISIONS-BOOKING-AVAILABILITY.md`.

---

## How to Verify Locally

```bash
php artisan test tests/Feature tests/Unit --compact     # 693 passed (iteration loop)
php artisan test --compact                              # full suite incl. Browser (run by developer at close)
vendor/bin/pint --dirty --format agent                  # {"result":"pass"}
php artisan wayfinder:generate                          # idempotent
npm run build                                           # green, ~1.3s
```

Targeted:

```bash
php artisan test tests/Feature/Dashboard/Booking/RescheduleBookingTest.php --compact   # 13 cases
php artisan test tests/Feature/Calendar/SlotGenerationExcludingBookingTest.php --compact # 2 cases
php artisan test tests/Unit/Frontend/CalendarMarkupContractTest.php --compact          # 7 cases
php artisan route:list --path=dashboard/bookings                                       # shows reschedule row
```

Manual smoke (recommended before merge):

1. **Drag**: Admin on the week view drags a confirmed booking from 10:00 Wed to 14:00 Fri. Expect the event to move optimistically, a brief spinner, and a success refresh. Customer receives a "moved" email at MailHog. If a Google Calendar integration is configured, the Google event's time updates on the next queue tick.
2. **Resize**: Hover a booking; grab the bottom edge; drag down 60 minutes. Expect duration doubles, server accepts, refresh confirms.
3. **Click-to-create**: Click an empty 14:30 cell on Thursday in week view. Expect the manual-booking dialog to open with date = Thursday and time = 14:30 pre-filled. Continue through the flow; the booking lands at 14:30.
4. **Hover preview**: Hover a booking. After ~300 ms, a tooltip shows service / customer / time / status chip. Click still opens the detail sheet.
5. **REVIEW-1 #8 regression**: Open DevTools console; navigate to `/dashboard/calendar`. Expect zero hydration warnings. Resize the viewport to 375 px (iPhone SE). Expect the view-switcher Select still visible, week view falls back to agenda list, and click-to-create works on day view.
6. **Keyboard nav**: With no input focused, press `←` / `→` to navigate the view; press `t` to jump to today.
7. **Jump-to-date**: Click the calendar icon between Today and the view Select. Pick a date. Calendar navigates there.
8. **iPad Safari touch**: Drag and resize via touch events. dnd-kit's PointerSensor works on touch; if one doesn't, note and defer (desktop is the win).
9. **Read-only business**: Cancel the business's subscription via Stripe CLI to read_only state. Try to drag a booking. Expect toast "Cannot mutate while read-only" (redirect to `/dashboard/settings/billing`).

---

## What the Next Session Needs to Know

`ROADMAP-MVP-COMPLETION` is fully shipped. The next active roadmap is `docs/roadmaps/ROADMAP-PAYMENTS.md` (customer-to-professional Stripe Connect Express). That roadmap is independent of the MVP sessions and owned by its own planning artifact.

### Conventions MVPC-5 established that future work must not break

- **D-100 — dnd-kit stays behind the lazy shell.** Any new calendar interaction requiring `@dnd-kit/*` lives inside `dnd-calendar-shell.tsx`. Adding a static import elsewhere re-bloats the main bundle.
- **D-101 — `<DragOverlay>` is the only drag-preview strategy.** Do not revert to "source card moves" — cross-day drags break.
- **D-103 — The `isAdmin` gate on `<ManualBookingDialog>` is gone.** New admin-only booking actions go on `CalendarHeader`'s buttons, not on dialog mount guards.
- **D-104 — Reschedule is same-provider same-business.** A cross-provider reschedule needs a new decision, new server contract, and new calendar-push resolution logic.
- **D-105 — Reschedule shape is `{ starts_at, duration_minutes }`.** Do not add `ends_at` to the request — the server recomputes. Do not add `provider_id` / `service_id` — those are immutable through reschedule.
- **D-106 — Off-grid `starts_at` returns 422, not silent snap.** If a future intent wants soft-snap, write a new decision and change the contract explicitly.
- **`SlotGeneratorService::getAvailableSlots(..., excluding: $booking)` is the right primitive for "don't let a booking block itself".** Any future reschedule-like write path (e.g., customer-side "change my booking time" post-MVP) uses this instead of reimplementing the exclude clause inline.
- **`BookingRescheduledNotification` mirrors `BookingConfirmedNotification`**. Future booking-lifecycle notifications follow the same Markdown + "manage" button pattern.

### MVPC-5 hand-off notes

- **The reschedule endpoint JSON shape is `{ booking: DashboardBooking }`.** Used by the drag/resize flow only. Other callers should not depend on this shape surviving a future change without an explicit contract test.
- **dnd-kit chunk size is the main guardrail.** `npm run build` logs the chunk size; if a future change pulls dnd-kit into the main bundle, the main-chunk size will jump by ~45 kB. Watch for it.
- **The click-to-create overlay and the dnd-kit PointerSensor co-exist via `activationConstraint: { distance: 5 }`.** A pointer drag of < 5 px falls through as a click (routes to the `<ol>` click-to-create handler). If the UX feels off on a specific device, tune `{ distance: 8, delay: 100 }` in `dnd-calendar-shell.tsx`.
- **Month view drag is deliberately out.** Per locked decision #14; a future drag-on-month design is a new decision.
- **Baseline test count drift**: MVPC-4 HANDOFF reported 669; a clean re-run on the MVPC-4 commit produces 671 (factory randomness on a single `Customer.firstOrCreate` path under seed order). MVPC-5 shipped 693 (+22 cases).

---

## Open Questions / Deferred Items

New from MVPC-5:

- **Per-provider column calendar view**. Unlocks cross-provider drag and a richer click-to-create `providerId` seed (D-102 / D-104). Not scheduled.
- **REVIEW-1 #9 frontend bundle code splitting**. MVPC-5 moved the dnd-kit-specific code into a lazy chunk but did not tackle the eager page glob in `app.tsx:13`. The main bundle is still larger than it needs to be. Independent of any roadmap session.
- **Drag-to-reassign-provider**. Out of scope (D-104); future work.
- **Month view drag**. Out of scope (locked #14); future work.
- **Recurring bookings**. Post-MVP per SPEC §12.
- **Customer-side reschedule**. Post-MVP. If scheduled, reuses the `excluding: $booking` primitive and `BookingRescheduledNotification`.

Earlier carry-overs unchanged from MVPC-4 hand-off:
- Admin-driven email recovery from the database (typo user locked out before recovery).
- Per-business avatar opt-in (D-022 follow-up).
- Tighten billing freeload envelope (`past_due` write-allowed window — Stripe dunning).
- Tenancy (R-19 carry-overs): R-2B business-switcher UI; admin-driven member deactivation + re-invite; "leave business" self-serve.
- R-17 carry-overs: admin email/push notification on bookability flip; richer "vacation" UX; banner ack history.
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
