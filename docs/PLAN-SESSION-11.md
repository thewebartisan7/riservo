# Session 11 — Calendar View

## Goal

Build a custom calendar component for the business dashboard with day, week, and month views, collaborator filtering, and booking detail integration.

## Prerequisites

- Sessions 1–10 complete, 369 tests passing
- Booking detail sheet (`booking-detail-sheet.tsx`) and manual booking dialog (`manual-booking-dialog.tsx`) exist from Session 8
- `DashboardBooking` TypeScript interface defined in `types/index.d.ts`
- TailwindPlus calendar templates available in `docs/calendar/`
- `date-fns` v4.1.0 available as transitive dependency of `react-day-picker`

## Scope

**Included:**
- Month, week, and day calendar views (week is default)
- View switcher and date navigation (prev / today / next)
- Admin: combined view of all collaborators' bookings with color-coded collaborator filter
- Collaborator: sees only their own bookings
- Click booking → open detail sheet (existing component)
- "New booking" button → open manual booking dialog (existing component)
- Current time indicator (red line in day/week views)
- Responsive: horizontal scroll for week/day on small screens
- Cross-collaborator overlap handling in day/week via side-by-side layout

**Not included:**
- Click-to-create on empty time slots (future enhancement)
- Full parallel column view per collaborator (post-MVP per scope notes)
- Year view (out of scope per ROADMAP)

## Architecture

### Server-driven calendar data

The controller computes the visible date range, queries bookings within it, and returns everything as Inertia props. Navigation between dates/views uses Inertia partial reloads (`only: ['bookings', 'view', 'date']`).

**Why server-driven**: The booking query with business scoping, collaborator access control, timezone conversion, and relationship loading is already well-established in the backend. The frontend only needs to position and render the data.

### Collaborator filter is client-side

Admin receives all bookings for the date range. The collaborator toggle filter hides/shows bookings in the UI without a server roundtrip. For MVP volumes (3-5 collaborators), this is efficient and responsive.

### Event positioning in time grids

Week and day views use a CSS grid with 288 rows (24 hours × 12 five-minute intervals). Each booking is positioned via `gridRow: startRow / span durationRows` computed from its start time and duration in the business timezone.

### Cross-collaborator overlap

When multiple collaborators have bookings at the same time in admin view:
- Group events that overlap in time
- Render them as flex items within the grid cell, dividing available width equally
- Each event gets its collaborator's color

A single collaborator never has overlapping bookings (enforced by AvailabilityService), so overlaps only happen between different collaborators.

### Collaborator color palette

A fixed array of 8 visually distinct colors. Each collaborator is assigned a color by their array index (mod palette length). Colors are consistent within a session.

## Implementation Steps

### Step 1: CalendarController (backend)

**File**: `app/Http/Controllers/Dashboard/CalendarController.php`

**`index()` method:**
- Accepts query params: `view` (day|week|month, default: week), `date` (Y-m-d, default: today in business timezone)
- Computes date range:
  - Day: `$date` start of day to end of day
  - Week: Monday to Sunday of the week containing `$date`
  - Month: first day of month to last day, padded to full weeks (Monday-based)
- Queries bookings: scoped to business, within UTC-converted date range, with service/collaborator/customer relationships
  - Non-admin: additional `where('collaborator_id', $user->id)`
- Returns Inertia `dashboard/calendar` with props:
  - `bookings`: array of booking data (same shape as `DashboardBooking`)
  - `collaborators`: array of `{ id, name, avatar_url }` (admin only, empty for collaborator role)
  - `view`: current view string
  - `date`: current date string (Y-m-d)
  - `isAdmin`: boolean
  - `timezone`: business timezone string

**Route**: `GET /dashboard/calendar` → `CalendarController@index`, name: `dashboard.calendar`
Added inside the existing dashboard route group (accessible to both admin and collaborator roles).

### Step 2: Navigation link

**File**: `resources/js/layouts/authenticated-layout.tsx`

Add "Calendar" nav item after "Bookings" in the `navItems` array. Import the Wayfinder route function. Accessible to both admin and collaborator roles.

### Step 3: Color palette utility

**File**: `resources/js/lib/calendar-colors.ts`

Export a `COLLABORATOR_COLORS` array and a `getCollaboratorColor(index: number)` function returning `{ bg, text, hoverBg, border }` Tailwind class sets. 8 distinct color entries (blue, pink, indigo, green, amber, purple, teal, rose).

### Step 4: Calendar page component

**File**: `resources/js/pages/dashboard/calendar.tsx`

Main page component:
- Uses `AuthenticatedLayout` with title "Calendar"
- Receives typed props (`CalendarPageProps`)
- Manages state: `selectedBooking`, `sheetOpen`, `dialogOpen`, `visibleCollaboratorIds` (admin only)
- Renders: CalendarHeader + the active view component + BookingDetailSheet + ManualBookingDialog
- Filters bookings by `visibleCollaboratorIds` before passing to view component
- Provides `onBookingClick` callback that opens detail sheet

### Step 5: Calendar header component

**File**: `resources/js/components/calendar/calendar-header.tsx`

Adapted from TailwindPlus header patterns. Contains:
- **Title**: formatted date/range (e.g., "January 2026", "Jan 13 – 19, 2026", "Monday, January 13")
- **Date navigation**: prev / today / next buttons
- **View switcher**: COSS UI Select (Day / Week / Month)
- **New booking button**: opens manual booking dialog (admin only)
- Navigation calls `router.get('/dashboard/calendar', { view, date }, { preserveState: true, only: ['bookings', 'view', 'date'] })`
- Uses `date-fns` for date arithmetic (addDays, addWeeks, addMonths, startOfWeek, etc.)

### Step 6: Collaborator filter component

**File**: `resources/js/components/calendar/collaborator-filter.tsx`

Admin-only toggle list:
- Shows each collaborator as a pill/chip with their assigned color dot
- Multi-select: click to toggle visibility
- "All" toggle to show/hide all
- State managed by parent (calendar page) via `visibleCollaboratorIds` + `onToggle`

### Step 7: Week view (default, most complex)

**File**: `resources/js/components/calendar/week-view.tsx`

Adapted from TailwindPlus `week-view.tsx`:
- Replace Headless UI Menu with COSS UI components (not needed in this component — menus are in header)
- 7-column grid (Mon–Sun) with hour rows (48 half-hour rows for visual lines, 288 five-minute rows for event positioning)
- Day header row showing abbreviated day name + date number, with today highlighted
- Events positioned via CSS grid rows calculated from start time and duration
- Overlap handling: within each column, detect overlapping events and render them as flex items splitting width
- Event click → `onBookingClick(booking)`
- Event rendering: colored bar showing service name, customer name, time
- Hour labels (sticky left) from business-hours-relevant range (default 6AM–10PM visible, full 24h scrollable)
- Auto-scroll to current time on mount
- Mobile: horizontal scroll (container `width: 165%` pattern from TailwindPlus)

### Step 8: Day view

**File**: `resources/js/components/calendar/day-view.tsx`

Adapted from TailwindPlus `day-view.tsx`:
- Single column time grid, same 288-row structure as week view
- Same event positioning logic (simpler — no column placement needed)
- Sidebar mini-calendar for quick date navigation (reuse COSS UI Calendar component)
- Event details are more spacious (wider events show more info)

### Step 9: Month view

**File**: `resources/js/components/calendar/month-view.tsx`

Adapted from TailwindPlus `month-view.tsx`:
- 7-column grid, 5-6 rows per month
- Each day cell shows date number + up to 2 booking entries + "+N more" overflow
- Booking entries show time + service name, colored by collaborator
- Today highlighted
- Days outside current month shown with reduced opacity
- Click on booking → detail sheet
- Mobile: compact layout with dots indicating bookings per day (from TailwindPlus mobile pattern), tapping a day shows that day's bookings below the grid

### Step 10: Current time indicator

**File**: `resources/js/components/calendar/current-time-indicator.tsx`

- Red horizontal line positioned at the current time within the time grid
- Positioned via CSS grid row calculated from current time in business timezone
- Updates every 60 seconds via `useEffect` + `setInterval`
- Only visible in day and week views
- Only visible if today is within the visible date range

### Step 11: Wire everything together

- Integrate all components in `calendar.tsx`
- Verify BookingDetailSheet opens correctly on event click
- Verify ManualBookingDialog opens from header button
- After manual booking creation, refresh calendar data via `router.reload({ only: ['bookings'] })`
- Run `php artisan wayfinder:generate` to generate route functions

### Step 12: Tests

**File**: `tests/Feature/Dashboard/CalendarControllerTest.php`

Feature tests covering:
1. Calendar page loads with default week view and today's date
2. Calendar returns bookings within the visible date range only
3. View parameter switches between day/week/month
4. Date parameter navigates to the specified date
5. Admin sees all business bookings
6. Collaborator sees only their own bookings
7. Unauthenticated user is redirected to login
8. Invalid view parameter falls back to week

### Step 13: Cleanup

- Run `vendor/bin/pint --dirty --format agent`
- Run `npm run build`
- Run `php artisan test --compact`

## File List

### New files
| File | Purpose |
|------|---------|
| `app/Http/Controllers/Dashboard/CalendarController.php` | Server-side calendar data |
| `resources/js/pages/dashboard/calendar.tsx` | Calendar page component |
| `resources/js/components/calendar/calendar-header.tsx` | Date nav, view switcher, new booking button |
| `resources/js/components/calendar/week-view.tsx` | Week time grid |
| `resources/js/components/calendar/day-view.tsx` | Day time grid + mini calendar |
| `resources/js/components/calendar/month-view.tsx` | Month grid |
| `resources/js/components/calendar/current-time-indicator.tsx` | Red line at current time |
| `resources/js/components/calendar/collaborator-filter.tsx` | Admin collaborator toggle filter |
| `resources/js/components/calendar/calendar-event.tsx` | Shared event rendering component |
| `resources/js/lib/calendar-colors.ts` | Collaborator color palette |
| `tests/Feature/Dashboard/CalendarControllerTest.php` | Feature tests |

### Modified files
| File | Change |
|------|--------|
| `routes/web.php` | Add calendar route |
| `resources/js/layouts/authenticated-layout.tsx` | Add Calendar nav item |
| `resources/js/types/index.d.ts` | Add CalendarPageProps, CalendarCollaborator types |

## Testing Plan

| Test | Covers |
|------|--------|
| Calendar page loads with week view | Default view, correct props |
| Bookings within date range returned | Date range query correctness |
| Day/week/month view switching | View parameter handling |
| Date navigation | Date parameter, range recalculation |
| Admin sees all bookings | Business-wide query |
| Collaborator sees own bookings only | Access control scoping |
| Auth redirect | Route protection |
| Invalid view fallback | Input validation |

## New Decisions to Record

### D-058 — Calendar default view is week
- **Context**: The calendar supports day, week, and month views. A default is needed.
- **Decision**: Default view is `week`. Week view provides the best balance of detail and overview for daily business operations.

### D-059 — Collaborator colors assigned from fixed palette by index
- **Context**: Admin calendar shows bookings from all collaborators, color-coded for visual distinction.
- **Decision**: A palette of 8 colors is defined. Each collaborator gets `palette[index % 8]`. Colors are assigned by array position, not stored in the database.
- **Consequences**: Colors are consistent within a view but may shift if collaborators are added/removed. Sufficient for MVP.

### D-060 — Calendar collaborator filter is client-side only
- **Context**: Admin sees all bookings; the collaborator filter toggles visibility.
- **Decision**: All bookings for the date range are loaded. The filter hides/shows them in the UI without server roundtrips. For MVP volumes this is efficient.
- **Consequences**: If a business has many collaborators with many bookings, this may need server-side filtering post-MVP.
