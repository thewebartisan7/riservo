---
name: PLAN-SESSION-8
description: "Session 8: Business Dashboard"
type: plan
status: shipped
created: 2026-04-15
updated: 2026-04-15
---

# Session 8 Plan — Business Dashboard

## Goal

Build the business dashboard: home page with stats, bookings list with filters and status management, manual booking creation, and customer directory (CRM).

## Prerequisites

- All 251 tests from Sessions 1–7 pass ✓
- Booking model, AvailabilityService, SlotGeneratorService exist ✓
- Authenticated layout with sidebar exists ✓
- Public booking flow complete (reusable patterns for manual booking) ✓
- COSS UI components available: Table, Sheet, Dialog, Select, Badge, Pagination ✓

## Scope

**Included:**
- Dashboard home page (today's appointments, quick stats)
- Bookings list with server-side filters, sorting, pagination
- Booking detail sheet (slide-over) with full info and status actions
- Booking status transitions (confirm, cancel, no-show, complete)
- Internal notes on bookings (single `internal_notes` text column)
- Manual booking creation (multi-step dialog)
- Customer Directory: list with search/pagination, detail page with booking history
- Role scoping: admins see all, collaborators see own bookings only

**Not included:**
- Calendar view (Session 12)
- Notification email templates (Session 10 — use existing placeholder)
- Business settings editing (Session 9)
- Embed settings (Session 9)

## New Decisions

- **D-049**: Internal notes stored as single `internal_notes` nullable text column on bookings (not a separate table). Sufficient for MVP — can be upgraded to a notes table post-MVP if multi-note/author tracking is needed.
- **D-050**: Booking status transitions encoded on `BookingStatus` enum via `allowedTransitions()` method. Valid transitions: pending → confirmed/cancelled; confirmed → cancelled/completed/no_show; cancelled/completed/no_show → terminal (none).
- **D-051**: Manual booking creation uses a multi-step dialog (not a dedicated page). Steps: customer → service → collaborator → date/time → confirm. Keeps user in context on the dashboard. Manual bookings are created with `source: manual` and always `status: confirmed` (no pending state for staff-created bookings).

## Implementation Steps

### 1. Migration: add internal_notes to bookings

Create migration adding nullable `internal_notes` text column to `bookings` table.

**File:** `database/migrations/XXXX_add_internal_notes_to_bookings_table.php`

### 2. Model & Enum updates

**Booking model** (`app/Models/Booking.php`):
- Add `internal_notes` to `#[Fillable]`

**BookingStatus enum** (`app/Enums/BookingStatus.php`):
- Add `allowedTransitions(): array` — returns array of valid target statuses
- Add `canTransitionTo(BookingStatus $target): bool` — convenience method
- Add `label(): string` — human-readable labels for display

### 3. Dashboard controllers

All in `app/Http/Controllers/Dashboard/` namespace.

**DashboardController** — home page
- `index()`: Queries today's appointments, this week's booking count, upcoming count, pending count. Admin sees business-wide; collaborator sees own. Renders `dashboard` page.

**BookingController** — booking CRUD + APIs
- `index()`: Bookings list with filters (date_from, date_to, collaborator_id, service_id, status), sorting (starts_at, created_at), pagination (20/page). Eager-loads service, collaborator, customer. Admin sees all; collaborator sees own. Passes services and collaborators lists for filter dropdowns and manual booking dialog. Renders `dashboard/bookings`.
- `updateStatus(Request, Booking)`: Validates transition is allowed via BookingStatus enum. Updates status. Returns Inertia back with flash.
- `updateNotes(Request, Booking)`: Updates `internal_notes`. Returns Inertia back.
- `store(StoreManualBookingRequest)`: Creates manual booking. Finds or creates customer. Verifies slot availability via AvailabilityService. Sets `source: manual`, `status: confirmed`. Sends placeholder notification. Returns Inertia redirect to bookings list.
- `availableDates(Request)`: JSON endpoint. Same logic as PublicBookingController but uses auth business.
- `slots(Request)`: JSON endpoint. Same logic as PublicBookingController but uses auth business.

**CustomerController** — CRM
- `index()`: Customers with at least one booking for this business. Server-side search (name, email, phone via LIKE). Pagination (20/page). Includes aggregates: total bookings count, last booking date. Renders `dashboard/customers`.
- `show(Customer)`: Customer detail with contact info, stats (total visits, last visit, first visit), full booking history for this business. Renders `dashboard/customer-show`.
- `search(Request)`: JSON endpoint for manual booking dialog autocomplete. Returns top 10 matching customers by name/email/phone.

### 4. Form Requests

**UpdateBookingStatusRequest** (`app/Http/Requests/Dashboard/UpdateBookingStatusRequest.php`):
- Validates `status` is a valid BookingStatus value
- Authorization: user must be admin or collaborator for the booking's business

**StoreManualBookingRequest** (`app/Http/Requests/Dashboard/StoreManualBookingRequest.php`):
- Validates: customer_name, customer_email, customer_phone (optional), service_id (exists), collaborator_id (exists), date (date format), time (time format), notes (optional)
- Authorization: user must be admin or collaborator for the auth business

### 5. Routes

Add to existing dashboard middleware group in `routes/web.php`:

```
GET  /dashboard                              → DashboardController@index
GET  /dashboard/bookings                     → BookingController@index
POST /dashboard/bookings                     → BookingController@store
PATCH /dashboard/bookings/{booking}/status   → BookingController@updateStatus
PATCH /dashboard/bookings/{booking}/notes    → BookingController@updateNotes
GET  /dashboard/customers                    → CustomerController@index
GET  /dashboard/customers/{customer}         → CustomerController@show

# JSON API endpoints (same middleware group)
GET  /dashboard/api/available-dates          → BookingController@availableDates
GET  /dashboard/api/slots                    → BookingController@slots
GET  /dashboard/api/customers/search         → CustomerController@search
```

### 6. TypeScript types

Add to `resources/js/types/index.d.ts`:

```typescript
export interface DashboardBooking {
    id: number;
    starts_at: string;
    ends_at: string;
    status: string;
    source: string;
    notes: string | null;
    internal_notes: string | null;
    created_at: string;
    cancellation_token: string;
    service: { id: number; name: string; duration_minutes: number; price: number | null };
    collaborator: { id: number; name: string; avatar_url: string | null };
    customer: { id: number; name: string; email: string; phone: string | null };
}

export interface DashboardCustomer {
    id: number;
    name: string;
    email: string;
    phone: string | null;
    bookings_count: number;
    last_booking_at: string | null;
}

export interface DashboardCustomerDetail extends DashboardCustomer {
    first_booking_at: string | null;
    bookings: DashboardBooking[];
}

export interface DashboardStats {
    today_count: number;
    week_count: number;
    upcoming_count: number;
    pending_count: number;
}

export interface FilterOption {
    id: number;
    name: string;
}
```

### 7. Sidebar navigation update

Update `resources/js/layouts/authenticated-layout.tsx`:
- Add "Bookings" link → `/dashboard/bookings`
- Add "Customers" link → `/dashboard/customers` (admin only — hide for collaborators)
- Use Wayfinder route functions for URLs
- Highlight active link based on current URL

### 8. Dashboard home page

Rewrite `resources/js/pages/dashboard.tsx`:
- Stats cards row: Today's bookings, This week, Upcoming, Pending confirmation
- Today's appointments list: time, customer name, service, collaborator, status badge
- Each appointment is clickable → navigates to bookings list with date filter
- Empty state if no appointments today

### 9. Bookings list page

Create `resources/js/pages/dashboard/bookings.tsx`:
- Filter bar: date range (from/to inputs), collaborator select (admin only), service select, status select
- Filters use Inertia `<Link>` with query params for server-side filtering
- Table: Date/Time, Customer, Service, Collaborator (admin), Status (Badge), Actions (button)
- Pagination at bottom using COSS Pagination
- Click row → opens booking detail sheet
- "New Booking" button → opens manual booking dialog
- Empty state when no bookings match filters

### 10. Booking detail sheet

Create `resources/js/components/dashboard/booking-detail-sheet.tsx`:
- Opens as right-side Sheet (COSS Sheet component)
- Shows: date/time, duration, service name, collaborator name, customer info (name, email, phone), source badge, customer notes, internal notes
- Status actions section: buttons for valid transitions based on current status
- Internal notes: inline textarea with save button (PATCH via useHttp)
- Status change: buttons trigger useHttp PATCH calls

### 11. Status badge component

Create `resources/js/components/dashboard/booking-status-badge.tsx`:
- Maps booking status to Badge variant (pending=warning, confirmed=success, cancelled=destructive, completed=default, no_show=error)

### 12. Manual booking dialog

Create `resources/js/components/dashboard/manual-booking-dialog.tsx`:
- Multi-step Dialog using COSS Dialog
- **Step 1 — Customer**: Search existing (autocomplete via useHttp GET) or create new (name, email, phone fields)
- **Step 2 — Service**: Select from list of active services (passed as prop)
- **Step 3 — Collaborator**: Select from collaborators assigned to selected service (from prop data) or "Auto-assign"
- **Step 4 — Date/Time**: Calendar + time slots (reuses availability API pattern from public booking flow, fetches via useHttp)
- **Step 5 — Confirm**: Summary of selections → "Create Booking" button (useHttp POST)
- On success: close dialog, reload bookings list via Inertia router.reload()

### 13. Customer Directory page

Create `resources/js/pages/dashboard/customers.tsx`:
- Search bar (text input, debounced, triggers Inertia visit with search param)
- Table: Name, Email, Phone, Total Bookings, Last Visit
- Pagination
- Click row → navigate to customer detail page
- Admin only (collaborators don't see this page — enforced by route middleware)

### 14. Customer detail page

Create `resources/js/pages/dashboard/customer-show.tsx`:
- Back link to customer directory
- Customer info card: name, email, phone
- Stats: total bookings, first visit, last visit
- Booking history table: Date/Time, Service, Collaborator, Status
- Click booking row → navigate to bookings list (future: deep-link to sheet)

### 15. Wayfinder generation

Run `php artisan wayfinder:generate` after adding all routes/controllers to generate TypeScript route functions.

## File List

### New files
| File | Purpose |
|------|---------|
| `database/migrations/XXXX_add_internal_notes_to_bookings_table.php` | Add internal_notes column |
| `app/Http/Controllers/Dashboard/DashboardController.php` | Dashboard home |
| `app/Http/Controllers/Dashboard/BookingController.php` | Booking management + APIs |
| `app/Http/Controllers/Dashboard/CustomerController.php` | CRM |
| `app/Http/Requests/Dashboard/UpdateBookingStatusRequest.php` | Status change validation |
| `app/Http/Requests/Dashboard/StoreManualBookingRequest.php` | Manual booking validation |
| `resources/js/pages/dashboard/bookings.tsx` | Bookings list page |
| `resources/js/pages/dashboard/customers.tsx` | Customer directory page |
| `resources/js/pages/dashboard/customer-show.tsx` | Customer detail page |
| `resources/js/components/dashboard/booking-detail-sheet.tsx` | Booking detail slide-over |
| `resources/js/components/dashboard/manual-booking-dialog.tsx` | Manual booking multi-step dialog |
| `resources/js/components/dashboard/booking-status-badge.tsx` | Status badge component |
| `tests/Feature/Dashboard/DashboardHomeTest.php` | Dashboard home tests |
| `tests/Feature/Dashboard/DashboardBookingsTest.php` | Bookings list + filters tests |
| `tests/Feature/Dashboard/BookingStatusTest.php` | Status transition tests |
| `tests/Feature/Dashboard/ManualBookingTest.php` | Manual booking creation tests |
| `tests/Feature/Dashboard/CustomerDirectoryTest.php` | CRM tests |

### Modified files
| File | Change |
|------|--------|
| `app/Models/Booking.php` | Add `internal_notes` to fillable |
| `app/Enums/BookingStatus.php` | Add `allowedTransitions()`, `canTransitionTo()`, `label()` |
| `routes/web.php` | Add dashboard routes |
| `resources/js/types/index.d.ts` | Add dashboard-specific interfaces |
| `resources/js/layouts/authenticated-layout.tsx` | Add sidebar navigation links |
| `resources/js/pages/dashboard.tsx` | Rewrite with actual dashboard content |

## Testing Plan

### DashboardHomeTest (~8 tests)
- Admin sees business-wide stats
- Collaborator sees own stats only
- Today's appointments display correctly
- Empty state when no appointments
- Unauthenticated user redirected to login

### DashboardBookingsTest (~12 tests)
- List shows bookings with relationships
- Admin sees all business bookings
- Collaborator sees own bookings only
- Filter by status works
- Filter by date range works
- Filter by service works
- Filter by collaborator works (admin only)
- Pagination works
- Default sort by starts_at desc
- Booking from another business not visible

### BookingStatusTest (~8 tests)
- Pending → confirmed succeeds
- Pending → cancelled succeeds
- Confirmed → no_show succeeds
- Confirmed → completed succeeds
- Invalid transition returns 422 (e.g., cancelled → confirmed)
- Collaborator can change own booking status
- Collaborator cannot change other's booking status
- Internal notes update works

### ManualBookingTest (~8 tests)
- Happy path: creates booking with source=manual, status=confirmed
- Customer find-or-create works
- Slot conflict returns 409
- Validation errors for missing fields
- Collaborator can create booking (for themselves)
- Available dates API returns correct data
- Slots API returns correct data
- Notification sent on creation

### CustomerDirectoryTest (~8 tests)
- Lists customers with booking counts
- Search by name works
- Search by email works
- Search by phone works
- Pagination works
- Customer from another business not shown
- Customer detail shows booking history
- Collaborator cannot access customer directory (admin only)

**Total: ~44 new tests**

## New Decisions to Record

- **D-049** — Internal notes as single column on bookings
- **D-050** — Status transitions on BookingStatus enum
- **D-051** — Manual bookings use multi-step dialog, source=manual, status=confirmed
