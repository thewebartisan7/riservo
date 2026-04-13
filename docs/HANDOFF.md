# Handoff

**Session**: 7 — Public Booking Flow  
**Date**: 2026-04-13  
**Status**: Complete

---

## What Was Built

Session 7 implemented the complete public booking flow at `riservo.ch/{slug}` — a single Inertia page with client-side step transitions, backed by JSON API endpoints for slot data. Also upgraded the Inertia client from v2 to v3 and fixed a timezone bug in the slot generator.

### Inertia v3 Upgrade

- Upgraded `@inertiajs/react` from `^2.3.21` to `^3.0.3`
- Removed `resources/js/bootstrap.js` (axios setup, no longer needed)
- Removed `import './bootstrap'` from `resources/js/app.tsx`
- All new AJAX calls use `useHttp` hook from Inertia v3
- Existing `fetch()` calls in onboarding pages are outside scope but noted for future migration
- Bundle size decreased from 642 KB to 660 KB (net increase from new booking pages, but axios removal saved ~70 KB)

### Bug Fix: SlotGeneratorService timezone issue

- Fixed `app/Services/SlotGeneratorService.php` `conflictsWithBookings` method
- When Carbon's `setTestNow` is active with a non-UTC timezone, `CarbonImmutable::parse($booking->starts_at)` loses the UTC timezone. The booking time `08:00 UTC` was interpreted as `08:00 CEST` (= `06:00 UTC`), causing conflict checks to miss overlapping bookings.
- Fix: use `$booking->getRawOriginal('starts_at')` with explicit `CarbonImmutable::createFromFormat(..., 'UTC')` to bypass Eloquent's datetime cast
- This fix is also relevant in production if the app timezone ever differs from UTC

### Backend

**1 controller:**
- `PublicBookingController` — 5 methods: `show`, `collaborators`, `availableDates`, `slots`, `store`

**1 form request:**
- `StorePublicBookingRequest` — validates booking creation (service_id, date, time, name, email, phone, notes, honeypot)

**1 notification:**
- `BookingConfirmedNotification` — queued placeholder email (Session 10 replaces)

**Rate limiters:**
- `booking-api`: 60 requests/min per IP (read endpoints)
- `booking-create`: 5 requests/min per IP (booking creation)

**Modified files:**
- `SlugService` — added `'booking'` to reserved slugs
- `AppServiceProvider` — registered rate limiters
- `routes/web.php` — 5 new routes + catch-all (must remain last)

### Frontend

**1 layout:**
- `booking-layout.tsx` — minimal riservo branding, business name prominent

**1 page:**
- `booking/show.tsx` — single-page booking flow with 6 steps managed by `useState`

**6 components in `components/booking/`:**
- `service-list.tsx` — grid of service cards (name, duration, price)
- `collaborator-picker.tsx` — avatar list with "Any available" option (uses `useHttp` GET)
- `date-time-picker.tsx` — COSS Calendar + time slot grid (uses `useHttp` for available-dates and slots)
- `customer-form.tsx` — name/email/phone/notes form with hidden honeypot field
- `booking-summary.tsx` — review + confirm (uses `useHttp` POST to create booking)
- `booking-confirmation.tsx` — success/pending message with management link

### Routes (5 new + 1 catch-all)

| Route | Purpose |
|-------|---------|
| GET `/booking/{slug}/collaborators` | JSON: collaborators for a service |
| GET `/booking/{slug}/available-dates` | JSON: month availability map |
| GET `/booking/{slug}/slots` | JSON: time slots for a date |
| POST `/booking/{slug}/book` | JSON: create booking |
| GET `/{slug}/{serviceSlug?}` | Inertia: public booking page (CATCH-ALL, LAST) |

### Tests (5 new test files, 36 tests)

- `PublicBookingPageTest` — page rendering, pre-selection, customer prefill
- `CollaboratorsApiTest` — collaborator list, service filtering, avatars
- `AvailableDatesApiTest` — month availability, past dates, collaborator filter
- `SlotsApiTest` — slot times, collaborator filter, past dates
- `BookingCreationTest` — happy path, auto/manual confirm, find-or-create customer, slot conflict 409, honeypot 422, auto-assignment, notification

---

## Current Project State

- **Backend**: 19 migrations, 11 models, 4 services, 1 DTO, 13 controllers, 9 form requests, 3 notifications, 3 custom middleware
- **Frontend**: 21 pages, 4 layouts, 55 COSS UI components, 4 onboarding components, 6 booking components
- **Tests**: 251 passing (704 assertions)
- **Build**: `npm run build` succeeds, `npx tsc --noEmit` clean, `vendor/bin/pint` clean

---

## Key Conventions Established

- **Public booking controller** at `App\Http\Controllers\Booking\PublicBookingController` — resolves business by slug, checks onboarding
- **Booking components** in `resources/js/components/booking/` — each step is a separate component
- **useHttp for AJAX**: All new standalone HTTP requests use Inertia v3's `useHttp` hook. GET requests use URL query params directly. POST requests use form data via `useHttp`.
- **Catch-all route**: `/{slug}/{serviceSlug?}` is the LAST route in `web.php` — all new routes must be registered BEFORE it
- **Rate limiting**: `booking-api` and `booking-create` rate limiters defined in `AppServiceProvider`
- **Honeypot field**: hidden `website` field rendered off-screen in `customer-form.tsx`, checked in controller
- **Customer find-or-create**: `Customer::firstOrCreate(['email' => ...])` with name/phone update on each booking

---

## What Session 8 Needs to Know

Session 8 implements the business dashboard (calendar view, booking management, manual booking creation, customer directory).

- **Booking creation exists**: the `store` method in `PublicBookingController` handles public bookings. Dashboard manual booking should follow a similar flow but with `source: manual`.
- **BookingManagementController**: already handles `/bookings/{token}` for guest management. Dashboard booking actions (confirm, cancel, no-show, complete) are separate.
- **Business model**: `confirmation_mode` affects whether public bookings are `confirmed` or `pending`. Dashboard bookings can be created directly as `confirmed`.
- **Customer model**: global by email, not per-business. CRM queries filter via `Customer::whereHas('bookings', fn($q) => $q->where('business_id', $id))`.
- **Inertia v3**: the client is now v3. Use `useHttp` for standalone HTTP requests, `useForm` for Inertia form submissions.
- **SlotGeneratorService**: use for manual booking creation to verify slot availability. The `assignCollaborator` method is available for auto-assignment.

---

## Decisions Recorded

- **D-043**: Public booking uses single Inertia page with client-side steps
- **D-044**: Available dates API returns month-level availability map
- **D-045**: Honeypot field rejects with 422
- **D-046**: `booking` added to reserved slugs
- **D-047**: Booking layout with minimal riservo branding
- **D-048**: Upgrade Inertia client to v3 for useHttp hook

---

## Open Questions / Deferred Items

- **Onboarding `fetch()` migration**: The onboarding pages (step-1.tsx) still use raw `fetch()` for slug check and logo upload. These should be migrated to `useHttp` — could be done in Session 9 when business settings are built.
- **Chunk size**: Vite build produces ~660 KB JS bundle. Code splitting should be considered before adding many more pages.
- **COSS calendar particles**: The roadmap suggested evaluating `@coss/p-calendar-19` and `@coss/p-calendar-24`. The built-in COSS Calendar (`react-day-picker`) was used instead — it provides the needed functionality. The particles could be evaluated for a UX polish pass.
- **Booking flow URL history**: Currently, page refresh returns to step 1. Browser back/forward doesn't track steps. This could be improved with `pushState` for each step.
