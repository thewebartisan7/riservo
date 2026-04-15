# Session 7 — Public Booking Flow

## Context

After Sessions 1-6, businesses are fully set up (profile, working hours, services, collaborators). The scheduling engine (`SlotGeneratorService`, `AvailabilityService`) is built and tested. Auth and guest booking management (`/bookings/{token}`) exist. Session 7 builds the customer-facing booking experience at `riservo.ch/{slug}`.

## Goal

Implement the complete public booking flow — from business landing page to booking confirmation — as a single Inertia page with client-side step transitions, backed by JSON API endpoints for dynamic slot data.

## Prerequisites

- 215 tests passing (verified)
- Business has slug, timezone, confirmation_mode, allow_collaborator_choice, assignment_strategy
- `SlotGeneratorService` works: `getAvailableSlots()`, `assignCollaborator()`
- Customer model is global by email with nullable user_id
- Booking model has cancellation_token, source, status fields
- `BookingManagementController` handles `/bookings/{token}` show/cancel
- Inertia client is v2 (`@inertiajs/react@^2.3.21`) — must upgrade to v3 for `useHttp`
- React 19 already installed (v3 compatible), no deprecated Inertia APIs in use

## Scope

**Included:**
- **Inertia client v3 upgrade** (`@inertiajs/react@^3.0`) — enables `useHttp` hook
- Public business page with service listing
- Multi-step booking flow (service → collaborator → date/time → details → summary → confirmation)
- JSON API endpoints for collaborators, available dates, time slots (using `useHttp`)
- Booking creation with customer find-or-create, slot re-verification, auto-assignment
- Confirmation email (placeholder, queued)
- Rate limiting on public endpoints
- Service pre-filter via URL param
- Honeypot spam protection
- Pre-fill for logged-in customers
- All strings use `t()` / `__()`

**Not included:**
- Embed mode (`?embed=1`) — Session 9
- Dashboard booking views — Session 8
- Email templates/styling — Session 10

## New Architectural Decisions

- D-043 — Single Inertia page with client-side booking steps
- D-044 — Available dates API returns month-level availability map
- D-045 — Honeypot rejects with 422
- D-046 — Add `booking` to reserved slugs
- D-047 — Booking layout with minimal riservo branding
- D-048 — Upgrade Inertia client to v3 for useHttp hook

## Route Structure

```
GET  /booking/{slug}/collaborators      → JSON: collaborators for a service
GET  /booking/{slug}/available-dates    → JSON: month availability map
GET  /booking/{slug}/slots              → JSON: time slots for a date
POST /booking/{slug}/book               → JSON: create booking
GET  /{slug}/{serviceSlug?}             → Inertia: booking page (CATCH-ALL, MUST BE LAST)
```

## Implementation Order

1. Inertia v3 upgrade: `npm install @inertiajs/react@^3.0`, remove bootstrap.js, verify
2. SlugService: add `'booking'` to reserved slugs
3. Rate limiters in bootstrap/app.php
4. Routes in web.php (API + catch-all)
5. StorePublicBookingRequest form request
6. PublicBookingController (show, API methods, store)
7. BookingConfirmedNotification
8. Tests (alongside controller methods)
9. TypeScript types
10. booking-layout.tsx
11. Step components using `useHttp`
12. booking/show.tsx (wire all together)
13. Translation keys
14. Full test suite, Pint, build

## File List

### New Files (11)
- `app/Http/Controllers/Booking/PublicBookingController.php`
- `app/Http/Requests/Booking/StorePublicBookingRequest.php`
- `app/Notifications/BookingConfirmedNotification.php`
- `resources/js/layouts/booking-layout.tsx`
- `resources/js/pages/booking/show.tsx`
- `resources/js/components/booking/service-list.tsx`
- `resources/js/components/booking/collaborator-picker.tsx`
- `resources/js/components/booking/date-time-picker.tsx`
- `resources/js/components/booking/customer-form.tsx`
- `resources/js/components/booking/booking-summary.tsx`
- `resources/js/components/booking/booking-confirmation.tsx`

### Modified Files (6)
- `package.json` — upgrade `@inertiajs/react` to ^3
- `resources/js/app.tsx` — remove bootstrap import
- `routes/web.php` — add booking API routes + catch-all
- `app/Services/SlugService.php` — add `'booking'` to reserved slugs
- `resources/js/types/index.d.ts` — add booking types
- `lang/en.json` — ~40 new translation keys

### Removed Files (1)
- `resources/js/bootstrap.js` — axios setup replaced by `useHttp`

### New Test Files (5)
- `tests/Feature/Booking/PublicBookingPageTest.php`
- `tests/Feature/Booking/AvailableDatesApiTest.php`
- `tests/Feature/Booking/SlotsApiTest.php`
- `tests/Feature/Booking/CollaboratorsApiTest.php`
- `tests/Feature/Booking/BookingCreationTest.php`

## Testing Plan

- Page rendering: loads by slug, 404 for non-existent/non-onboarded, pre-selection
- Available dates: correct map, past dates unavailable, collaborator filter
- Slots: correct times, collaborator filter, past dates
- Collaborators: correct list per service, avatar URLs
- Booking creation: happy path, auto/manual confirm, customer find-or-create, slot conflict 409, honeypot 422, auto-assignment, rate limiting, notification queued
