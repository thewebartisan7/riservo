# Handoff

**Session**: 11 — Calendar View  
**Date**: 2026-04-13  
**Status**: Complete

---

## What Was Built

A full calendar page for the business dashboard with three view modes, collaborator filtering, booking detail integration, and current time indicator.

### Backend

- **`CalendarController`** (`app/Http/Controllers/Dashboard/CalendarController.php`): Single `index()` method that accepts `view` (day/week/month) and `date` query params. Computes visible date range, queries bookings within it (UTC-converted), returns as Inertia props. Collaborator role sees only own bookings. Route: `GET /dashboard/calendar`.

### Frontend — Calendar Components

All in `resources/js/components/calendar/`:

- **`calendar-header.tsx`**: Date navigation (prev/today/next), view switcher (COSS UI Select), "New booking" button (admin only). Uses `date-fns` for date arithmetic. Navigation via Inertia partial reloads (`only: ['bookings', 'view', 'date']`).
- **`week-view.tsx`**: 7-column time grid (Mon–Sun) with 288 five-minute rows. Events positioned via CSS grid `gridRow`/`gridColumnStart` inline styles (not dynamic Tailwind classes). Overlap handling via `layoutOverlappingEvents` — side-by-side flex items splitting width. Auto-scrolls to 7 AM on mount. Mobile: horizontal scroll (`width: 165%` pattern).
- **`day-view.tsx`**: Single-column time grid with same 288-row structure. Desktop sidebar has a mini calendar for quick date navigation. Mobile shows a week day strip.
- **`month-view.tsx`**: 7-column grid with `gridTemplateRows` via inline style. Desktop shows up to 2 booking entries per day with "+N more". Mobile shows colored dots per day; tapping a day shows its bookings in a list below the grid.
- **`calendar-event.tsx`**: Shared event rendering component + utility functions: `getBookingGridPosition`, `getDateInTimezone`, `layoutOverlappingEvents` (overlap detection and column assignment).
- **`current-time-indicator.tsx`**: Red line + dot positioned at current time via `Intl.DateTimeFormat` in business timezone. Updates every 60 seconds.
- **`collaborator-filter.tsx`**: Admin-only toggle pills with color dots. Client-side filter — no server roundtrip.

### Frontend — Support Files

- **`resources/js/lib/calendar-colors.ts`**: Palette of 8 colors (blue, pink, indigo, green, amber, purple, teal, rose). `getCollaboratorColor(index)` and `getCollaboratorColorMap(ids)` utilities.
- **`resources/js/pages/dashboard/calendar.tsx`**: Page component. Manages state for booking detail sheet, manual booking dialog, and collaborator visibility. Filters bookings client-side by visible collaborators (D-060).
- **`resources/js/types/index.d.ts`**: Added `CalendarCollaborator` interface.
- **`resources/js/layouts/authenticated-layout.tsx`**: Added "Calendar" nav item (visible to both admin and collaborator roles).

### Tests

- `tests/Feature/Dashboard/CalendarControllerTest.php`: 10 tests covering default view, date range queries, view switching, date navigation, day-only filtering, month padded range, admin vs collaborator access, auth redirect, and invalid view fallback.

---

## Current Project State

- **Backend**: 22 migrations, 12 models, 4 services, 1 DTO, 24 controllers, 22 form requests, 5 notifications, 3 custom middleware, 2 scheduled commands
- **Frontend**: 35 pages, 5 layouts, 55 COSS UI components, 4 onboarding components, 6 booking components, 3 dashboard components, 5 settings components, 8 calendar components
- **Tests**: 379 passing (1387 assertions)
- **Build**: `npm run build` succeeds, `vendor/bin/pint` clean

---

## Key Conventions Established

1. **No dynamic Tailwind classes**: Calendar uses inline `style` props for grid positioning (`gridRow`, `gridColumnStart`, `gridTemplateRows`) instead of dynamic class strings like `` `sm:col-start-${n}` `` — Tailwind tree-shakes them.
2. **Overlap layout algorithm**: `layoutOverlappingEvents()` assigns column/totalColumns to events. Width and margin-left are calculated as percentages via inline styles.
3. **Time grid structure**: 288 rows = 24 hours × 12 five-minute intervals. Row offset of +2 accounts for the header. Both week and day views share this grid system.
4. **Timezone rendering**: `Intl.DateTimeFormat` with `timeZone` option for rendering dates/times in business timezone. `date-fns` for date arithmetic (which is timezone-naive — works on local dates from the server).
5. **Calendar navigation**: `router.get()` with `preserveState: true` and `only: ['bookings', 'view', 'date']` for fast partial reloads when switching dates/views.

---

## What Session 12 Needs to Know

Session 12 implements billing (Laravel Cashier).

- **Calendar is built and functional** — no dependencies on billing.
- **`date-fns`** is now used in the frontend (transitive dependency of `react-day-picker`, v4.1.0). Available for import but only used in calendar components.
- **Existing patterns**: Follow the CLAUDE.md files in `resources/js/` and `resources/js/components/ui/` for forms, HTTP requests, and UI components.
- **Services and collaborators props** are shared to both the bookings page and the calendar page for the manual booking dialog.

---

## Open Questions / Deferred Items

- **is_active filtering in public booking**: Still deferred from Session 9 — `SlotGeneratorService` and `PublicBookingController::collaborators()` should filter out deactivated collaborators.
- **Click-to-create on empty time slots**: Not implemented — future enhancement. Currently only the "New booking" button in the header opens the manual booking dialog.
- **Bundle size warning**: The JS bundle is 866 KB (254 KB gzipped). The chunk size warning from Vite is informational — code-splitting can be done post-MVP if needed.
- **Email template translations**: All templates use `__()` but only English keys exist. IT/DE/FR translations are pre-launch work per D-008.
