# Handoff

**Session**: 8 — Business Dashboard  
**Date**: 2026-04-13  
**Status**: Complete

---

## What Was Built

Session 8 implemented the business dashboard: home page with stats, bookings list with filters and status management, manual booking creation, internal notes, and customer directory (CRM).

### Migration

- Added `internal_notes` nullable text column to `bookings` table

### Model & Enum Updates

- `Booking` model: added `internal_notes` to `#[Fillable]`
- `BookingStatus` enum: added `allowedTransitions()`, `canTransitionTo()`, and `label()` methods

### Backend (3 controllers, 2 form requests)

**Controllers in `App\Http\Controllers\Dashboard\`:**

1. **DashboardController** — home page with today's appointments and stats (today, this week, upcoming, pending)
2. **BookingController** — bookings list with server-side filters (status, service, collaborator, date range), sorting, pagination; status updates; internal notes; manual booking creation; available dates and slots JSON APIs
3. **CustomerController** — customer directory with search/pagination; customer detail page; customer search JSON API

**Form Requests in `App\Http\Requests\Dashboard\`:**
- `UpdateBookingStatusRequest` — validates status transition
- `StoreManualBookingRequest` — validates manual booking fields

### Frontend (3 pages, 3 components, 1 layout update)

**Pages:**
- `pages/dashboard.tsx` — rewritten with stats cards + today's appointments list
- `pages/dashboard/bookings.tsx` — bookings table with filter bar, pagination, sheet + dialog
- `pages/dashboard/customers.tsx` — customer directory with debounced search, pagination
- `pages/dashboard/customer-show.tsx` — customer detail with contact info, stats, booking history

**Components in `components/dashboard/`:**
- `booking-status-badge.tsx` — status and source badges with color mapping
- `booking-detail-sheet.tsx` — right-side Sheet with full booking info, status actions, inline notes editing
- `manual-booking-dialog.tsx` — 5-step Dialog (customer → service → collaborator → date/time → confirm)

**Layout update:**
- `authenticated-layout.tsx` — sidebar now has Dashboard, Bookings, and Customers (admin only) navigation links using Wayfinder routes; active link highlighting

### Routes (10 new)

| Route | Purpose |
|-------|---------|
| GET `/dashboard` | Dashboard home (rewritten) |
| GET `/dashboard/bookings` | Bookings list |
| POST `/dashboard/bookings` | Create manual booking |
| PATCH `/dashboard/bookings/{booking}/status` | Change booking status |
| PATCH `/dashboard/bookings/{booking}/notes` | Update internal notes |
| GET `/dashboard/api/available-dates` | JSON: month availability map |
| GET `/dashboard/api/slots` | JSON: time slots for a date |
| GET `/dashboard/customers` | Customer directory (admin only) |
| GET `/dashboard/customers/{customer}` | Customer detail (admin only) |
| GET `/dashboard/api/customers/search` | JSON: customer autocomplete (admin only) |

### Tests (5 files, 43 tests)

- `DashboardHomeTest` — stats, role scoping, empty state (5 tests)
- `DashboardBookingsTest` — list, filters, pagination, role scoping (10 tests)
- `BookingStatusTest` — valid/invalid transitions, role auth, notes (12 tests)
- `ManualBookingTest` — creation, customer reuse, slot conflict, validation, APIs (8 tests)
- `CustomerDirectoryTest` — list, search, pagination, detail, role restriction (10 tests - note: includes search API test that was noted as 10 tests total but labeled 8 in plan)

---

## Current Project State

- **Backend**: 20 migrations, 11 models, 4 services, 1 DTO, 16 controllers, 11 form requests, 3 notifications, 3 custom middleware
- **Frontend**: 24 pages, 4 layouts, 55 COSS UI components, 4 onboarding components, 6 booking components, 3 dashboard components
- **Tests**: 294 passing (984 assertions)
- **Build**: `npm run build` succeeds, `npx tsc --noEmit` clean, `vendor/bin/pint` clean

---

## Key Conventions Established

- **Dashboard controllers** in `App\Http\Controllers\Dashboard\` namespace — one per domain (DashboardController, BookingController, CustomerController)
- **Dashboard components** in `resources/js/components/dashboard/` — booking-detail-sheet, manual-booking-dialog, booking-status-badge
- **Status transitions** centralized on `BookingStatus` enum — `allowedTransitions()` and `canTransitionTo()` used by controllers
- **Role scoping pattern**: every dashboard query checks `currentBusinessRole()` — admins see all, collaborators get `->where('collaborator_id', $user->id)` 
- **Customer directory is admin-only** — routes wrapped in `middleware('role:admin')`
- **Manual bookings** always `source: manual`, `status: confirmed` (D-051)
- **Dashboard JSON APIs** at `/dashboard/api/*` — same middleware group as Inertia pages, use `useHttp` on frontend
- **Sidebar navigation** uses Wayfinder action imports with `isActive` based on `window.location.pathname`

---

## What Session 9 Needs to Know

Session 9 implements business settings (profile editing, working hours, exceptions, service/collaborator management, embed settings).

- **Existing models**: Business, Service, AvailabilityRule, AvailabilityException, BusinessHour, BusinessInvitation — all have factories and established patterns
- **Onboarding already creates**: business profile (step 1), working hours (step 2), first service (step 3), collaborator invites (step 4). Settings pages should let admins edit all of this after onboarding
- **SlugService**: handles slug generation and validation — reuse for slug editing in settings
- **Collaborator invite flow**: `BusinessInvitation` model exists, `InvitationController` handles acceptance. Settings just needs the admin UI for sending invites
- **Service management**: services have `collaborators()` pivot — the dialog/form needs to support multi-select collaborator assignment
- **Embed `?embed=1`**: the public booking page at `/{slug}` already works. Settings needs: (a) detect and strip nav/footer when `?embed=1`, (b) generate JS popup snippet, (c) copy buttons
- **Onboarding `fetch()` migration**: step-1.tsx still uses raw `fetch()` for slug check and logo upload. These should be migrated to `useHttp` when building the equivalent settings forms
- **Authenticated layout sidebar**: currently has Dashboard, Bookings, Customers. Settings link needs to be added

---

## Decisions Recorded

- **D-049**: Internal notes as single `internal_notes` column on bookings
- **D-050**: Status transitions encoded on BookingStatus enum
- **D-051**: Manual bookings use multi-step dialog, source=manual, status=confirmed

---

## Open Questions / Deferred Items

- **Bundle size**: Vite build produces ~675 KB JS. Code splitting should be considered before adding more pages
- **Booking detail deep-linking**: clicking a booking opens a sheet; no URL change. Could add query param (`?booking=123`) for deep-linking if needed
- **Customer notes from booking detail**: could add a link from booking detail sheet to customer detail page for CRM context
