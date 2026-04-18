---
name: PLAN-MVPC-5-CALENDAR-INTERACTIONS
description: "Advanced calendar interactions: drag, resize, click-to-create, hover preview (MVPC-5)"
type: plan
status: shipped
created: 2026-04-17
updated: 2026-04-17
---

# PLAN-MVPC-5 — Advanced Calendar Interactions

> Session 5 (final) of `docs/roadmaps/ROADMAP-MVP-COMPLETION.md`.
> Status: Draft, awaiting developer approval.
> Baseline: Feature + Unit suite **669 passed / 2758 assertions** (post-MVPC-4).

---

## Context

Sessions 1–4 of the MVP completion roadmap shipped:

- MVPC-1 (`5388d8a`): Google OAuth foundation + `role:admin,staff` settings split (D-081).
- MVPC-2 (`132535e`): Bidirectional Google Calendar sync. `PushBookingToCalendarJob`, `Booking::shouldPushToCalendar()`, `Booking::shouldSuppressCustomerNotifications()`, `external_event_calendar_id` pinning (D-082..D-088).
- MVPC-3 (`8da1c5d`): Cashier billing. `billing.writable` middleware gates every mutating dashboard verb (D-090).
- MVPC-4 (`3eb5bab`): Provider self-service (Account + Availability) opened to staff (D-096..D-099).

Dashboard calendar today is a passive read view. Bookings render in day / week / month cells. Clicking a booking opens `BookingDetailSheet`; the manual-booking dialog is reachable only from the "New booking" CTA in `CalendarHeader` (desktop, admin-only). There is no click-to-create, no drag-to-move, no resize, no hover preview.

MVPC-2 already round-trips booking mutations to Google via `PushBookingToCalendarJob`. MVPC-4 put providers in control of their own availability. The missing piece is the calendar workspace itself.

Locked decisions binding this session (from `ROADMAP-MVP-COMPLETION.md §Cross-cutting decisions`):

- **#13** — `@dnd-kit/core`, **lazy-loaded only on the calendar route**.
- **#14** — Drag / resize scope: **week + day views only**. Month view is click-to-create only.
- **#15** — Resize granularity = `service.slot_interval_minutes`. Client snaps for UX, server is authoritative.
- **#16** — Reschedule dispatches a customer-facing "time changed" notification. Suppressed when `source = google_calendar`.

Binding decisions from topical files:

- **D-016** — Cancellation window is customer-only; admins can always reschedule. No reschedule-window enforcement.
- **D-031** — Only `pending` + `confirmed` bookings block availability. Reschedule validation reads the same list.
- **D-051** — Manual bookings are `source=manual`, `status=confirmed`. Click-to-create reuses the existing dialog → same contract.
- **D-065 / D-066** — GIST exclusion on `(provider_id, tsrange(effective_starts_at, effective_ends_at, '[)'))` is the race-safe backstop. Reschedule endpoint wraps the UPDATE in a transaction and catches `23P01`.
- **D-067** — Soft-deleted provider's booking: `$booking->provider` still resolves (withTrashed). But a trashed provider is ineligible for new work — so **reschedule of a booking whose provider is trashed is refused** (the booking would fail availability against an empty eligible set anyway; refusing explicitly keeps the error message honest).
- **D-069** — Mobile week view is an agenda list below `sm`; view-switcher is always visible. Reschedule drag on the agenda list is NOT supported (no time grid to drop onto). Mobile users still get click-to-create and hover preview.
- **D-083** — `PushBookingToCalendarJob(bookingId, action)`. Reschedule dispatches with `action='update'`, guarded by `Booking::shouldPushToCalendar()`.
- **D-088** — Notification suppression via `Booking::shouldSuppressCustomerNotifications()`. Reschedule notification dispatch site gates on this helper.
- **D-090** — `billing.writable` wraps every dashboard mutating verb. The new reschedule route sits inside the gated group → a read-only business cannot reschedule. No new middleware needed.

---

## Goal

Make the dashboard calendar an interactive workspace:

- Drag a booking on the week / day time grid to a new time slot → event moves, customer is emailed, Google is updated.
- Resize a booking by its bottom edge → duration changes, snapped to the service's slot interval.
- Click an empty cell (week / day / month) → manual booking dialog opens pre-populated with date / time / provider.
- Hover any booking → compact popover with customer, service, time, status.
- Mobile remains usable (view-switcher reachable; agenda fallback works; hover preview on tap-hold).
- Two REVIEW-1 correctness items land first so the interactions sit on clean markup.

End state on close: ROADMAP-MVP-COMPLETION is fully shipped.

---

## Scope

### In

**Cluster 1 — REVIEW-1 bug audit (do first)**
- Audit the nested-`<li>` claim from REVIEW-1 issue #8 against current code. If the bug is truly closed (per D-069), add a permanent regression guard (see Tests). If still present, fix.
- Audit the "mobile view-switcher" claim. Per D-069 the switcher was already de-hidden; verify the 375px viewport is usable end-to-end.
- Audit the "week-view booking blocks invisible on mobile" claim. Per D-069 the agenda-list fallback exists; verify.

**Cluster 2 — Hover preview (low-risk, unlocks confidence for the drag wiring)**
- `BookingHoverCard` wraps each `<CalendarEvent>` in all three views. Uses the existing `@/components/ui/tooltip.tsx` primitive (delay 300 ms). External events render the external variant (no customer / no service). Click still opens the detail sheet.
- Tooltip is suppressed for the tight-variant card (which already uses `Popover` on click-tap). Avoids a tooltip + popover fight on tight events.

**Cluster 3 — Click-to-create**
- `useCalendarCreate()` hook exposes an `openDialog({ date, time?, providerId? })` imperative API. The `ManualBookingDialog` gains an `initial` prop (`{ date, time?, providerId? }`) that seeds state on open. Service and customer steps are unchanged.
- Week / Day view: an invisible grid overlay reads the click's row index and maps it back to the clicked slot (snap to the service's default slot interval — use the first active service's `slot_interval_minutes` for empty-cell snap; the dialog re-asks slots once a service is picked, so this is purely a seed).
- Month view: clicking an empty area of a day cell (not a booking pill) opens the dialog with `date` seeded and `time` left empty. Dialog defaults time to business opening hour on that day-of-week when `time` is undefined.
- Admin multi-provider column: the column is encoded per cell; click inside another provider's column pre-selects that provider. For MVP the calendar shows a combined view (no per-provider columns yet) — click-to-create always seeds `providerId = undefined` and the admin picks in the dialog. Documented as D-102 (see Decisions below).
- Staff without admin role: the dialog is currently admin-only (`ManualBookingDialog` renders only for `isAdmin`). The click-to-create flow opens the same dialog for staff; the route `dashboard.bookings.store` already allows `role:admin,staff`. The "New booking" header button stays admin-only (staff's "create booking" entry point is click-to-create). Documented as D-103.

**Cluster 4 — Drag to reschedule (week + day)**
- `@dnd-kit/core` added to `package.json`. Lazy-loaded via a `<DndCalendarShell>` wrapper: `const DndCalendarShell = React.lazy(() => import('@/components/calendar/dnd-calendar-shell'))`. The shell module is the only site that statically imports `@dnd-kit/core` — Vite splits it into its own chunk because the parent import is dynamic.
- Drag preview uses `DragOverlay` (z-index + cursor clarity is worth the second render).
- On drop: optimistic update + PATCH to `dashboard.bookings.reschedule`. On 200 the local booking state is kept; on 4xx/5xx the revert runs and a toast surfaces the server message.
- Cross-day drag within the visible week is allowed. Cross-provider drag is **not** allowed this session (stays on the source provider, matches locked #14 + keeps the reschedule endpoint's service/provider invariants trivial). Documented as D-104.

**Cluster 5 — Resize (week + day)**
- `<CalendarEvent>` grows a bottom-edge handle, 8 px tall, always visible at low opacity, full opacity on hover. Hit target is expanded with a `-mb-1` pointer-events zone so drag is comfortable at small row heights. Handle is hidden on the tight-variant card (too small to drag reliably → the user clicks to open).
- Drag the handle → optimistic `ends_at` update, PATCH reschedule with new `ends_at`. Server snaps and validates to `service.slot_interval_minutes`.
- Resize and drag share `useReschedule()` — single hook owns the optimistic-update / revert / toast / dispatch flow.

**Cluster 6 — UX polish (evaluate, ship what is clearly worth it)**
- Today's day header already stands out in week + month views. Day view's mobile day strip also highlights today. No change needed; documented in plan's verification.
- "Jump to date" date picker is added to `CalendarHeader` (COSS UI calendar inside a popover next to the Today button). Ships.
- Keyboard navigation: `←` / `→` moves the current view, `t` jumps to today. Focus guard (only fires when no input / textarea is focused). Ships — low-effort.
- Loading state: a 1-line `<Spinner>` + dimming overlay during calendar-navigation Inertia partial reloads. Ships.

**Cluster 7 — Backend reschedule endpoint**
- `PATCH /dashboard/bookings/{booking}/reschedule` on `DashboardBookingController::reschedule`. Lives inside the outer `billing.writable` group (inherits the gate for free).
- Request: `{ starts_at: ISO-8601 string, ends_at: ISO-8601 string }` — both required, both UTC. Frontend sends UTC; controller converts the business-timezone grid to UTC once and sends it.
- Authorization: `abort_unless($booking->business_id === $business->id, 404)`; staff may only reschedule their own bookings (`$booking->provider->user_id === auth()->id()`); if `$booking->provider->trashed()` refuse with 422 "cannot reschedule a deactivated provider's bookings"; external bookings (`source = google_calendar`) cannot be rescheduled from the dashboard (they're mirrors — refuse with 422).
- Validation: `starts_at` snaps to `service.slot_interval_minutes` (reject 422 with a friendly message, do NOT silent-snap — see §Open Questions); `ends_at = starts_at + service.duration_minutes` is recomputed server-side; the client's `ends_at` is used only for resize operations where the duration itself is changing (we treat the request as "one shape": the controller always recomputes `ends_at` from `starts_at` + explicit duration; see §Key design decisions for the shape).
- Availability: reuse `SlotGeneratorService::getAvailableSlots($business, $service, $date, $provider)` but exclude the booking being rescheduled. Concretely: add an optional `?Booking $excludingBooking` parameter to `SlotGeneratorService::getAvailableSlots()` and its `getSlotsForProvider()` / `getBlockingBookings()` helpers; when present, skip that booking in the blocking query. Matches D-066's "the booking being rescheduled is the one we're freeing up".
- Transaction + GIST backstop: wrap the availability check and the UPDATE in `DB::transaction(...)`. Catch `Illuminate\Database\QueryException` with Postgres `23P01` exclusion violation → return 409 `{ message: "This slot was just taken. Pick another time." }`.
- Post-commit: dispatch `PushBookingToCalendarJob::dispatch($booking->id, 'update')` gated by `$booking->shouldPushToCalendar()`. The job's `afterCommit()` hook is already in place. Dispatch the new `BookingRescheduledNotification($booking, $previousStartsAt, $previousEndsAt)` gated by `! $booking->shouldSuppressCustomerNotifications()`. No staff notification (reschedule is a staff action — staff know).

**Cluster 8 — `BookingRescheduledNotification`**
- `app/Notifications/BookingRescheduledNotification.php` + `resources/views/mail/booking-rescheduled.blade.php`. Subject: `"Your booking has been moved — :business"`. Body: "your booking was moved from {old time} to {new time}, with a manage-booking link `route('bookings.show', $booking->cancellation_token)`". Queued, `afterCommit()`, mirrors `BookingConfirmedNotification`'s shape.

### Out

- Drag on month view (locked #14).
- Cross-provider drag (future extension; D-104 documents the decision).
- Multi-booking drag.
- Recurring bookings (post-MVP per SPEC §12).
- Changing service or provider via drag — only time / duration changes. Service and provider edits continue to flow through the `booking-detail-sheet` cancel-and-rebook dance (manual, low-volume).
- The "New booking" CTA in the header moving to mobile (still `hidden md:inline-flex`; tracked in backlog per D-069).
- Frontend code splitting pass on the whole bundle (REVIEW-1 issue #9; separate backlog item).
- Browser/E2E tests (developer runs at session close per `docs/TESTS.md`; this session covers Feature + Unit + one Pest browser smoke for the reschedule-drag round-trip only if low-effort — otherwise deferred).

---

## Audit — REVIEW-1 issue #8 (bug fix cluster)

I read `current-time-indicator.tsx`, `week-view.tsx`, `day-view.tsx`, `month-view.tsx`, and `calendar-header.tsx` end-to-end.

| Claim from REVIEW-1 #8 | Current code (2026-04-17) | Decision |
|------------------------|---------------------------|----------|
| `<li>` nested in `<li>` between `WeekView` and `CurrentTimeIndicator` | `week-view.tsx:230` renders `<ol>`. Booking `<li>` items (line 252) and `<CurrentTimeIndicator>` (line 285, renders its own `<li>`) are **sibling** children of the `<ol>`, not nested. Same shape in `day-view.tsx:104-150`. The mobile agenda uses `<ol><li><ul><li>…</li></ul></li></ol>` which is valid HTML. | **Bug already closed**, acknowledged by D-069's pre-existing note. Add a React unit test that renders each view and asserts the rendered DOM has zero `li > li` direct nesting, so a future edit can't regress it. Additionally add a lint-style guard inside `CurrentTimeIndicator` that documents its parent contract (comment + `// @ts-expect` on any `<li>` wrapping it). |
| Mobile view switcher hidden below `md` | `calendar-header.tsx:159-168` Select uses `w-[110px] sm:w-[130px]` — always visible. | **Resolved by D-069**. Smoke test at 375 px viewport passes; no change needed. |
| Week-view booking blocks `hidden sm:flex`, invisible on mobile | `week-view.tsx:125-193` renders a mobile agenda list (`block sm:hidden`); the time grid is `hidden sm:flex`. Bookings are discoverable. | **Resolved by D-069**. Regression test asserts the agenda list contains each booking's service name when the viewport is narrow. |

Net: the bug-fix cluster is a **verification + regression-test** cluster, not a code-change cluster. The roadmap entry (written before D-069 landed) is stale; I preserve the intent by locking the behavior with tests so future edits can't accidentally re-introduce the issues.

If the developer disagrees with the above audit and wants code changes, I'll reverse in ≤1 hour by changing `CurrentTimeIndicator` to render `<div>` and restructuring the `<ol>`s to `<div role="list">` — but I recommend the test-lock route because the markup today is semantically correct.

---

## Audit — where things are today

- `resources/js/pages/dashboard/calendar.tsx` — page; owns view state, selected booking, dialog open state, provider filter. `ManualBookingDialog` renders conditionally on `isAdmin` (will change — see Cluster 3).
- `resources/js/components/calendar/week-view.tsx`, `day-view.tsx`, `month-view.tsx` — render the three views. Each receives `bookings`, `date`, `timezone`, `colorMap`, `onBookingClick`. They will grow: `onSlotClick`, `onBookingDragEnd`, `onBookingResizeEnd`.
- `resources/js/components/calendar/calendar-event.tsx` — event card + `getBookingGridPosition` + `layoutOverlappingEvents`. Resize handle lands here; the reverse (grid row → `starts_at`) lands as a sibling helper `gridPositionToTime(gridRow, dayDate, timezone)`.
- `resources/js/components/calendar/current-time-indicator.tsx` — returns `<li>`; parent must be `<ol>`. Documented.
- `resources/js/components/calendar/calendar-header.tsx` — already wires prev/next/today + view select + mobile "New booking" hidden. Adds "Jump to date" picker and keyboard-nav effect.
- `resources/js/components/dashboard/manual-booking-dialog.tsx` — multi-step; `open`, `onOpenChange`, `services`, `timezone`. Gains `initial?: { date?: string; time?: string; providerId?: number }`.
- `app/Http/Controllers/Dashboard/BookingController.php` — `index`, `updateStatus`, `updateNotes`, `store`, `availableDates`, `slots`. Gains `reschedule`.
- `app/Services/SlotGeneratorService.php` — adds `?Booking $excludingBooking` to `getAvailableSlots()` signature + underlying helpers.
- `app/Services/AvailabilityService.php` — no change.
- `app/Notifications/Booking*Notification.php` — pattern to mirror for `BookingRescheduledNotification`.
- `routes/web.php:130-134` — booking routes. New `PATCH /dashboard/bookings/{booking}/reschedule` added here, inside `billing.writable`.

---

## Key design decisions (locked at plan time, recorded D-100+ in topical decision files)

**D-100 — `@dnd-kit/core` loaded via a React.lazy() shell.** `<DndCalendarShell>` is the only module that `import`s from `@dnd-kit/core`. The calendar page renders `<Suspense fallback={<NonInteractiveCalendar …/>}><DndCalendarShell …/></Suspense>` on desktop; on mobile (< sm) the non-interactive calendar is used directly (no drag / no resize). `npm run build` verifies the dnd-kit chunk lands as a separate asset and the main chunk does not grow more than ~10 kB. Recorded in `DECISIONS-FRONTEND-UI.md`.

**D-101 — `DragOverlay` for drag preview.** The original card becomes semi-transparent (`opacity-40`) while the overlay follows the cursor at `opacity-90` with a 2-px ring. Alternative "move the original card" was rejected: it fights the CSS grid positioning during partial moves and misrenders when the drag crosses days. Recorded in `DECISIONS-FRONTEND-UI.md`.

**D-102 — Click-to-create does NOT seed `providerId` in MVP.** The calendar view is currently a combined view — it does not have per-provider columns. The dialog's auto-assign path (existing behavior) handles "admin clicks an empty slot" correctly. When per-provider column view lands (post-MVP), the click-to-create handler will encode the column's `providerId` into the seed. Recorded in `DECISIONS-FRONTEND-UI.md`.

**D-103 — Staff can create manual bookings via click-to-create.** Staff's dashboard entry point for manual booking creation is the empty-cell click (no header button). **Pre-condition verified at plan time**: `routes/web.php:132` places `dashboard.bookings.store` inside the `role:admin,staff` group (line 111) → `billing.writable` (line 126); `StoreManualBookingRequest::authorize()` returns `tenant()->has()` (no role scope). Server already accepts staff manual-booking writes — the current `isAdmin` gate in `calendar.tsx:113` is frontend-cosmetic. Dropping it is a one-line UI change, not a server-side scope expansion. The header "New booking" button stays admin-only (staff's entry point is click-to-create). This extends MVPC-4's self-service spirit. Recorded in `DECISIONS-FRONTEND-UI.md`.

**D-104 — Drag is scoped to same-provider same-business.** Cross-provider drag requires re-validating service eligibility, re-assigning calendar integration destination, and re-acquiring GIST lock under the new provider — non-trivial and out of scope for MVP per locked #14. Attempting to drop onto a different provider's column (when that column view lands) is a no-op; current combined view means this never triggers. Recorded in `DECISIONS-BOOKING-AVAILABILITY.md`.

**D-105 — Reschedule request shape: `{ starts_at, duration_minutes }`.** Sending both `starts_at` and `ends_at` invites drift (client can send an `ends_at` that doesn't match its own `starts_at + duration`). The server recomputes `ends_at = starts_at + duration_minutes`. For a drag (no duration change) the client sends the booking's current `duration_minutes`; for a resize the client sends the new duration it drew. Snap + bounds enforcement is server-side. Recorded in `DECISIONS-BOOKING-AVAILABILITY.md`.

**D-106 — Slot-interval snap enforcement: 422 with a friendly error, not silent-snap.** Silent-snap hides user error and disagrees with the client's drawn position — a bad trust signal. The client snaps its own drag to the grid, so a 422 from the server means either (a) the client is out of date, or (b) something weird happened. 422 surfaces a fixable condition rather than papering over it. Recorded in `DECISIONS-BOOKING-AVAILABILITY.md`.

**D-107 — Resize handle UX: always-visible, 8 px, low opacity.** Hover-only handles are undiscoverable on touch (no hover). Always-visible + low opacity is the compromise; matches Google Calendar. Handles are suppressed on the "tight" event variant (< 4 grid rows — fewer than 20 minutes) because the hit target would collide with the card body. Recorded in `DECISIONS-FRONTEND-UI.md`.

**D-108 — Reschedule notification copy.** `BookingRescheduledNotification` includes a link to the `bookings.show` management page (customer can re-cancel from the new time). Mirrors `BookingConfirmedNotification`. Recorded in `DECISIONS-FRONTEND-UI.md`.

---

## Implementation steps (ordered)

### Step 0 — Baseline + wiring verification (before any code)

1. `php artisan test tests/Feature tests/Unit --compact` — confirm **669 / 2758**.
2. `npm run build` — record the current main-chunk size (998 698 bytes of `app-*.js` per `public/build/assets`).
3. `php artisan route:list --path=dashboard/bookings` — confirm current routes.

### Step 1 — Cluster 1 (bug audit + regression tests)

1. Add `tests/Unit/Frontend/CurrentTimeIndicatorContractTest.php` — **pure PHP**. Each test case: `file_get_contents()` the view file, `preg_match` a regex that detects `<CurrentTimeIndicator` textually wrapped by an unclosed `<li ...>` parent, OR a literal `<li ...>\s*<li ` nesting. No Node.js, no AST, no new CI dep — keeps the test in the existing Pest harness. Three cases, one per view file (`week-view.tsx`, `day-view.tsx`, `month-view.tsx`). The guarantee is "no calendar view file textually places a `<li>` inside a `<li>` or a `<CurrentTimeIndicator>` inside a `<li>`" — a regex job, not an AST job.
2. Add `tests/Feature/Dashboard/CalendarPageTest.php::it('mobile viewport renders agenda list with booking names')` — render `/dashboard/calendar` as HTML (Inertia SSR via `InertiaTestResponse->viewData`) and assert the mobile agenda list class and at least one booking name are present.
3. No code change in `current-time-indicator.tsx`, `week-view.tsx`, `day-view.tsx`, `month-view.tsx`, `calendar-header.tsx`.

### Step 2 — Cluster 7 (reschedule endpoint) — backend-first so frontend has a real endpoint to PATCH

1. `app/Http/Requests/Dashboard/RescheduleBookingRequest.php`: validates `starts_at: ISO-8601`, `duration_minutes: int|min:service.slot_interval_minutes|max:24*60`. Slot-interval snap check deferred to the controller (needs service context).
2. `SlotGeneratorService::getAvailableSlots($business, $service, $date, $provider, ?Booking $excluding = null)` — pass `$excluding` into `getSlotsForProvider()` → `getBlockingBookings()`, which appends `when($excluding, fn ($q) => $q->where('id', '!=', $excluding->id))`. Existing callers adopt the default `null`.
3. `DashboardBookingController::reschedule(RescheduleBookingRequest $request, Booking $booking)`:
   - Tenant + auth gate: 404 on cross-business; 403 if staff editing a non-own booking; 422 for external source; 422 for trashed provider.
   - Parse `starts_at` (UTC) → business timezone → assert `minute % slot_interval === 0`; else 422.
   - Compute `ends_at = starts_at + duration_minutes`. Upper-bound: `ends_at <= starts_at->endOfDay()` in business-tz (a booking cannot straddle two days; the GIST also implicitly enforces, but clear server error wins).
   - Wrap in `DB::transaction`:
     - Load `service` + `provider`. Re-run `SlotGeneratorService::getAvailableSlots($business, $service, $date, $provider, excluding: $booking)` for the target date, assert `$startsAtLocal` is in the result.
     - `$booking->update(['starts_at' => $startsAtUtc, 'ends_at' => $endsAtUtc])`.
   - Catch `QueryException` 23P01 → `abort(409, __('This slot was just taken. Pick another time.'))`.
   - Post-commit: dispatch push + notification per §Cluster 7 above.
   - Return JSON `{ booking: { … } }` mirroring the calendar page's booking payload shape.
4. Route: `Route::patch('/dashboard/bookings/{booking}/reschedule', [DashboardBookingController::class, 'reschedule'])->name('dashboard.bookings.reschedule');` — placed after `update-notes`.
5. `php artisan wayfinder:generate` — regenerates `@/actions/…`.

### Step 3 — `BookingRescheduledNotification`

1. `php artisan make:notification BookingRescheduledNotification`.
2. Constructor: `public Booking $booking, public CarbonInterface $previousStartsAt, public CarbonInterface $previousEndsAt`. `afterCommit()`.
3. `toMail()` mirrors `BookingConfirmedNotification` plus a `previousDate` / `previousTime` stanza.
4. `resources/views/mail/booking-rescheduled.blade.php` — copy the confirmed template, retitle, add "Previously on {date} at {time}, now on {date} at {time}" block, keep the "manage" button.
5. Dispatch site wired in `reschedule()` per above. Suppression gated by `! $booking->shouldSuppressCustomerNotifications()`.

### Step 4 — Backend tests (cluster 7 + cluster 8)

`tests/Feature/Dashboard/Booking/RescheduleBookingTest.php`:

1. Admin reschedules a confirmed booking to a free slot → 200 + booking moves + `BookingRescheduledNotification` queued + `PushBookingToCalendarJob` queued with `update`.
2. Staff reschedules own booking → 200.
3. Staff reschedules another provider's booking → 403.
4. Admin reschedules booking owned by soft-deleted provider → 422.
5. Reschedule to an occupied slot → 422 with availability error; GIST never fires (app-side check wins).
6. Reschedule race: manufacture a GIST violation by inserting a conflicting booking mid-transaction → 409.
7. Reschedule a `source=google_calendar` booking → 422 (external bookings are mirrors).
8. Reschedule that doesn't snap to `slot_interval_minutes` → 422.
9. Cross-business booking reschedule → 404.
10. `source=google_calendar` suppression: external booking reschedule (admin-side, which is refused above) never dispatches the notification — covered by the 422 path (no dispatch ever reached).
11. Read-only business reschedule → 302 redirect to `settings.billing` (via existing `billing.writable` dataset test extension rather than a fresh test).

Extend `tests/Feature/Billing/ReadOnlyEnforcementTest.php`'s mutating-route dataset with the new reschedule route → structural invariant holds.

### Step 5 — Frontend scaffolding (cluster 4 entry point)

1. `npm install @dnd-kit/core @dnd-kit/modifiers` — `modifiers` carries `restrictToVerticalAxis` + `restrictToWindowEdges` which we need for resize UX.
2. `resources/js/components/calendar/dnd-calendar-shell.tsx` — statically imports `@dnd-kit/core` and `@dnd-kit/modifiers`. Wraps children in `<DndContext sensors={PointerSensor({activationConstraint:{distance:5}})} collisionDetection={closestCenter}>`. Exposes an inner `useReschedule()` context provider.
3. `resources/js/pages/dashboard/calendar.tsx`:
   - Replace `<ViewComponent …/>` with `<Suspense fallback={<ViewComponent …interactive={false} />}><DndCalendarShell><ViewComponent …interactive /></DndCalendarShell></Suspense>`.
   - Drop the `isAdmin` gate on `<ManualBookingDialog>` (D-103); dialog now always mounts. Header "New booking" button still `hidden md:inline-flex` and `isAdmin`-gated.

### Step 6 — `useReschedule()` hook

- Encapsulates: optimistic update of the local booking list, PATCH to `dashboard.bookings.reschedule`, revert + toast on failure.
- Surface: `const { reschedule } = useReschedule(); reschedule({ bookingId, startsAtUtc, durationMinutes })` → Promise.
- Owns the "pending reschedule" map so the draggable shows a subtle busy state until the server confirms.
- Toast uses `useToast()` from COSS UI (already present).

### Step 7 — Cluster 2 (hover preview) — ships first to de-risk rendering / overflow

1. `resources/js/components/calendar/booking-hover-card.tsx` — wraps a `<CalendarEvent>` in `<Tooltip><TooltipTrigger render={children} /><TooltipContent>…</TooltipContent></Tooltip>`. Delay open 300 ms, close 120 ms.
2. Tooltip content: dot + service / title / time + customer + status chip (COSS Badge with color by status).
3. Suppressed on the tight variant (already owns a Popover click-tap surface).
4. Week / Day views wrap each `<CalendarEvent>` with this component. Month view's pill uses the same.

### Step 8 — Cluster 3 (click-to-create)

1. Week / Day views: add `onEmptyCellClick(date: string, time: string) => void`. Implementation: a transparent absolute overlay over each day column that handles `onClick` — compute the y-offset in px → grid row → time in business TZ. Guard against clicks that land on an existing booking (stop propagation in the event `<button>`).
2. Month view: add `onEmptyDayClick(date: string)`. Each day cell already is a `<div>` / `<button>` — extend to open the dialog when the click target is not a booking pill.
3. `calendar.tsx` holds a `const [initialSeed, setInitialSeed] = useState<DialogSeed | null>(null)` and opens the dialog with that seed.
4. `ManualBookingDialog` accepts `initial?: DialogSeed` and threads it into `selectedDate` / `selectedTime` in the `useEffect(open)` reset block.

### Step 9 — Cluster 4 (drag) + Cluster 5 (resize)

1. `calendar-event.tsx`: wrap the top-level button in `useDraggable({ id: bookingId, data: { kind: 'move' } })`. Add a resize handle `<div {...useDraggable({ id: \`${bookingId}-resize\`, data: { kind: 'resize' } })} />`.
2. Week / Day views: place `useDroppable`-bearing invisible rows (every slot-interval × day), each carrying `data: { date, time, column }`.
3. `<DndCalendarShell>` owns the `onDragEnd`: reads the source's `kind`, the target's `date/time`, computes the new `starts_at` / `duration`, and calls `reschedule(...)`.
4. Drag preview: `<DragOverlay>` renders a copy of the card at cursor + 2 px primary ring.

### Step 10 — Cluster 6 (UX polish)

1. "Jump to date" — popover in `calendar-header.tsx` between Today and the view select; uses the existing `Calendar` primitive. On select: `navigateCalendar(view, format(date, 'yyyy-MM-dd'))`.
2. Keyboard handler — `useEffect` on the page, `document.addEventListener('keydown', fn)`. `e.key === 'ArrowLeft' / 'ArrowRight' / 't'`. Guard: bail if `document.activeElement?.tagName` is INPUT / TEXTAREA / SELECT or has `contenteditable`.
3. Loading — `router.on('start') / on('finish')` within `calendar.tsx`, toggles a `Spinner` inside the calendar area.

### Step 11 — Verification + close

Per § Verification below.

---

## Files to create / modify

**New**
- `app/Http/Requests/Dashboard/RescheduleBookingRequest.php`
- `app/Notifications/BookingRescheduledNotification.php`
- `resources/views/mail/booking-rescheduled.blade.php`
- `resources/js/components/calendar/dnd-calendar-shell.tsx`
- `resources/js/components/calendar/booking-hover-card.tsx`
- `resources/js/hooks/use-reschedule.ts`
- `tests/Feature/Dashboard/Booking/RescheduleBookingTest.php`
- `tests/Unit/Frontend/CurrentTimeIndicatorContractTest.php`
- `tests/Feature/Dashboard/CalendarPageTest.php` (if not already present; augment if it is)

**Modified**
- `app/Http/Controllers/Dashboard/BookingController.php` — add `reschedule()`.
- `app/Services/SlotGeneratorService.php` — optional `?Booking $excluding` threading.
- `routes/web.php` — add the reschedule route.
- `resources/js/pages/dashboard/calendar.tsx` — Suspense + DndCalendarShell, drop admin gate on ManualBookingDialog, wire click-to-create seed + keyboard nav + loading state.
- `resources/js/components/calendar/week-view.tsx`, `day-view.tsx`, `month-view.tsx` — `onEmptyCellClick` overlay, `useDroppable` slots (week/day), hover-card wrap.
- `resources/js/components/calendar/calendar-event.tsx` — `useDraggable`, resize handle, hover-card integration.
- `resources/js/components/calendar/calendar-header.tsx` — Jump-to-date popover.
- `resources/js/components/dashboard/manual-booking-dialog.tsx` — `initial?` prop + seed threading.
- `resources/js/components/calendar/current-time-indicator.tsx` — doc comment clarifying the parent-`<ol>` contract (no behavior change).
- `tests/Feature/Billing/ReadOnlyEnforcementTest.php` — dataset extension for the reschedule route.
- `package.json` — `@dnd-kit/core`, `@dnd-kit/modifiers`.
- `docs/HANDOFF.md` — rewritten (session close).
- `docs/roadmaps/ROADMAP-MVP-COMPLETION.md` — tick Session 5 checkboxes + add "roadmap fully shipped" header note.
- `docs/decisions/DECISIONS-FRONTEND-UI.md` — record D-100, D-101, D-102, D-103, D-107, D-108.
- `docs/decisions/DECISIONS-BOOKING-AVAILABILITY.md` — record D-104, D-105, D-106.

---

## Tests (minimum set — all in Feature/Unit)

**Backend (Feature)**

- `RescheduleBookingTest` — 10 cases per §Step 4.
- `ReadOnlyEnforcementTest` — +1 dataset row.
- `CalendarPageTest` — mobile agenda smoke (from cluster 1 audit).

**Backend (Unit)**

- `CurrentTimeIndicatorContractTest` — text-level `li > li` guard on the three view files.
- `SlotGeneratorServiceTest` — new case: `getAvailableSlots` with `excluding: $booking` returns slots that include the booking's own starts_at (which would otherwise be blocked by itself).

**Out of scope this session**

- Pest browser test for the drag round-trip (developer runs the Browser suite at session close; if we add one it's wrapped in `describe` with a `skip` marker until E2E-7's infra lands — cheaper to document manual smoke steps).

---

## Verification (before reporting "done")

```bash
php artisan test tests/Feature tests/Unit --compact     # expect 669 + ~13 new = ~682
vendor/bin/pint --dirty --format agent
php artisan wayfinder:generate
npm run build                                           # main chunk delta <= 10 KB vs 998 698-byte baseline;
                                                        # dnd-kit chunk appears as its own asset, < 50 KB gzipped.
                                                        # If the dnd-kit chunk is over budget, evaluate dropping
                                                        # @dnd-kit/modifiers and hand-rolling restrictToVerticalAxis
                                                        # (~10 lines) — flag at build time for developer review.
php artisan route:list --path=dashboard/bookings        # new reschedule row visible
```

Manual smoke the developer should run at close:

1. As admin, drag a confirmed booking one hour later → event moves, customer receives "moved" email at MailHog, Google test calendar updates (if calendar integration wired in dev).
2. Resize a booking from 30 → 60 min → ends_at updates, payload matches.
3. Click an empty week-view cell → dialog opens at that date / time.
4. Click a month-view day → dialog opens at that date, time blank → dialog defaults to business opening hour.
5. Hover a booking in week view → popover appears after ~300 ms, click still opens the sheet.
6. iPhone SE (375 px) in DevTools: view switcher visible + operable, week view shows agenda, tap-hold on an event shows hover preview (or not — tap still opens the sheet), click-to-create works on day view.
7. DevTools console: zero hydration warnings (REVIEW-1 #8 regression check).
8. Cancel the active subscription → reschedule drag → toast "business is read-only", optimistic move reverts.

---

## Decisions to record

See §Key design decisions above — D-100 through D-108. Recorded in the specified topical files at the end of Cluster 11.

---

## Open questions (resolved in this plan, flagged for developer sanity check)

1. **dnd-kit lazy-load mechanism** — `React.lazy()` around a shell module. Vite chunks on dynamic import boundaries. Verified with `npm run build` bundle delta. (Locked — D-100.)
2. **Drag preview rendering** — `<DragOverlay>`. (Locked — D-101.)
3. **Resize handle UX** — always-visible 8 px, low opacity. (Locked — D-107.)
4. **Slot-interval enforcement** — 422 with a friendly message, no silent snap. (Locked — D-106.)
5. **Reschedule notification copy** — includes the `bookings.show` management link. (Locked — D-108.)
6. **Mobile view-switcher pattern** — already shipped per D-069; no new pattern needed. Plan confirms via regression test.
7. **Click-to-create on a partially-blocked cell** — seed the dialog with the clicked time; the dialog then asks the server for actual slots (already does) and surfaces the conflict there. Option (b) — trust the existing slot-picker step to show what fits. Rejected (a) because the user may pick a different shorter service; rejected (c) because pre-computing per-cell eligibility requires knowing the service before the click. (Implied by reuse; no new decision needed.)

Flagged for developer attention:

- **The REVIEW-1 #8 bug audit landed on "already fixed by D-069, add regression guards".** If the developer wants explicit markup changes anyway, I'll do them — but I recommend the test-lock route.
- **Cross-provider drag is out of scope this session** — captured in D-104. Any future "admin drags across provider columns" work writes a new decision.
- **Click-to-create in the combined calendar view does not seed providerId** — per D-102. This is a product constraint of the current view, not a limitation of the click-handler. When per-provider columns land post-MVP the seed extends trivially.

---

## Implementation notes (developer-raised refinements — tracked here so they don't get lost at code time)

- **Mail Blade location + i18n**: `resources/views/mail/booking-rescheduled.blade.php`. English-only copy is intentional MVP state; pre-launch i18n pass applies via the existing `__()` pipeline.
- **dnd-kit PointerSensor vs click-to-create overlay**: `activationConstraint: { distance: 5 }` filters non-drag clicks. During implementation, if a slow click (held > 150 ms, < 5 px movement) resolves as a stalled drag attempt instead of a click, bump to `{ distance: 8, delay: 100 }`. Don't over-tune at plan time.
- **Keyboard nav guard**: bail if `e.target.closest('input, textarea, select, [contenteditable], [role="textbox"]')`. One `closest()` call covers richer editors that may land later.
- **iPad touch drag**: dnd-kit Pointer Events work on Safari iPad. DndCalendarShell is active on `md`+ (iPad lands there). Include "iPad Safari touch drag" in the manual smoke checklist at session close. If broken, document and defer — desktop is the win.
- **Dnd-kit chunk budget**: if `@dnd-kit/core + @dnd-kit/modifiers` blows past 50 KB gzipped as a single chunk, evaluate dropping `@dnd-kit/modifiers` for a hand-rolled `restrictToVerticalAxis` (~10 lines wrapping a modifier function). Flag for developer at `npm run build` time; don't pre-optimise.
