# Handoff

**Session**: 9 — Business Settings  
**Date**: 2026-04-13  
**Status**: Complete

---

## What Was Built

Session 9 implemented the full business settings area under `/dashboard/settings/*` — admin-only pages for managing all business configuration after onboarding.

### Migration

- Added `is_active` boolean (default true) to `business_user` pivot table (D-053)

### Model Updates

- `BusinessUser`: added `is_active` to casts
- `Business::users()`: updated `withPivot` to include `is_active`
- `User::businesses()`: updated `withPivot` to include `is_active`

### Backend (7 controllers, 11 form requests)

**Controllers in `App\Http\Controllers\Dashboard\Settings\`:**

1. **ProfileController** — edit profile, upload logo, slug check (reuses SlugService)
2. **BookingSettingsController** — confirmation mode, collaborator choice, cancellation window, payment mode, assignment strategy, reminder hours
3. **WorkingHoursController** — weekly schedule editor (same pattern as onboarding)
4. **BusinessExceptionController** — CRUD for business-level availability exceptions
5. **ServiceController** — CRUD with collaborator assignment, auto-slug generation
6. **CollaboratorController** — list, invite, schedule editing, exception CRUD, avatar upload, toggle active, invitation management (resend/cancel)
7. **EmbedController** — embed settings page with snippet generation

**Form Requests in `App\Http\Requests\Dashboard\Settings\`:**
- `UpdateProfileRequest`, `UpdateBookingSettingsRequest`, `UpdateWorkingHoursRequest`
- `StoreBusinessExceptionRequest`, `UpdateBusinessExceptionRequest`
- `StoreSettingsServiceRequest`, `UpdateSettingsServiceRequest`
- `StoreCollaboratorInvitationRequest`, `UpdateCollaboratorScheduleRequest`
- `StoreCollaboratorExceptionRequest`, `UpdateCollaboratorExceptionRequest`

### Frontend (10 pages, 5 components, 1 layout)

**Layout:**
- `settings-layout.tsx` — wraps `authenticated-layout`, adds settings sub-navigation

**Components in `components/settings/`:**
- `settings-nav.tsx` — left sidebar navigation for 7 settings sections
- `exception-dialog.tsx` — shared create/edit dialog for availability exceptions (business + collaborator)
- `service-form.tsx` — shared form for service create/edit with collaborator assignment
- `collaborator-invite-dialog.tsx` — invite form with service pre-assignment
- `embed-snippet.tsx` — code snippet with copy-to-clipboard

**Pages:**
- `dashboard/settings/profile.tsx` — business profile editing
- `dashboard/settings/booking.tsx` — booking configuration
- `dashboard/settings/hours.tsx` — weekly schedule editor
- `dashboard/settings/exceptions.tsx` — business exception list + dialog
- `dashboard/settings/services/index.tsx` — service list
- `dashboard/settings/services/create.tsx` — new service form
- `dashboard/settings/services/edit.tsx` — edit service form
- `dashboard/settings/collaborators/index.tsx` — collaborator list + pending invitations
- `dashboard/settings/collaborators/show.tsx` — individual collaborator: schedule, exceptions, avatar
- `dashboard/settings/embed.tsx` — embed snippets + preview iframe

### Embed Implementation

- `PublicBookingController::show()` now passes `embed` boolean from `?embed=1` query param
- `booking-layout.tsx` conditionally strips header/footer in embed mode
- `public/embed.js` — vanilla JS popup script (not Vite-bundled) that opens booking in a modal overlay

### Routes (35+ new)

All admin-only under `dashboard/settings` prefix. Includes CRUD routes for profile, booking settings, working hours, exceptions, services, collaborators, and embed.

### Tests (8 files, 52 tests)

- `ProfileTest` — edit, update, slug validation, logo upload, auth (7 tests)
- `BookingSettingsTest` — edit, update, enum validation, reminders (5 tests)
- `WorkingHoursTest` — edit, update, validation (4 tests)
- `BusinessExceptionTest` — CRUD, validation, scoping (8 tests)
- `ServiceTest` — CRUD, slug uniqueness, collaborator sync, scoping (9 tests)
- `CollaboratorTest` — list, invite, schedule, exceptions, avatar, toggle, scoping (13 tests)
- `EmbedTest` — page render, embed param (4 tests)
- `SettingsAuthorizationTest` — role-based access (3 tests covering all routes)

---

## Current Project State

- **Backend**: 21 migrations, 11 models, 4 services, 1 DTO, 23 controllers, 22 form requests, 3 notifications, 3 custom middleware
- **Frontend**: 34 pages, 5 layouts, 55 COSS UI components, 4 onboarding components, 6 booking components, 3 dashboard components, 5 settings components
- **Tests**: 346 passing (1238 assertions)
- **Build**: `npm run build` succeeds (~811 KB JS), `npx tsc --noEmit` clean, `vendor/bin/pint` clean

---

## Key Conventions Established

- **Settings controllers** in `App\Http\Controllers\Dashboard\Settings\` namespace — one per section
- **Settings layout** (`settings-layout.tsx`) wraps `authenticated-layout` and adds sub-nav — all settings pages use this
- **Settings routes** are admin-only within the existing dashboard middleware group, prefixed with `dashboard/settings`
- **Collaborator deactivation** uses `is_active` boolean on pivot (D-053) — reversible, preserves data
- **Exception dialog** is shared between business-level and collaborator-level exceptions — same component, different URLs
- **Service form** is shared between create and edit pages — receives action prop
- **Embed mode** via `?embed=1` — strips booking layout chrome; `public/embed.js` provides popup overlay for third-party sites
- **Schedule editing** reuses `WeekScheduleEditor` component from onboarding — same component, different page context

---

## What Session 10 Needs to Know

Session 10 implements notifications (email).

- **Reminder hours setting** is already configurable in booking settings (`reminder_hours` JSON array on Business, D-019). Session 10 just needs to read this field for the scheduled reminder job
- **Booking confirmation email** already exists as a placeholder from Session 7 — Session 10 replaces with styled templates
- **Notification classes** exist: `BookingConfirmedNotification`, `InvitationNotification` — new notification classes for other events follow the same pattern
- **`is_active` on pivot** (D-053): the scheduled job for auto-completing bookings should not be affected by collaborator active status — completed/no-show transitions apply to existing bookings regardless

---

## Decisions Recorded

- **D-052**: Settings controllers under Dashboard\Settings namespace
- **D-053**: Collaborator is_active on pivot (not soft delete)
- **D-054**: Embed mode via ?embed=1 query parameter
- **D-055**: Settings sub-navigation via nested layout

---

## Open Questions / Deferred Items

- **Bundle size**: Vite build produces ~811 KB JS (up from ~675 KB). Code splitting should be considered before adding more pages
- **is_active filtering in public booking**: The `SlotGeneratorService` and `PublicBookingController::collaborators()` should filter out deactivated collaborators (`is_active = false` on pivot). This was noted in D-053 but not implemented in Session 9 to avoid modifying scheduling engine code outside of scope. Should be addressed when next touching the booking flow
- **Onboarding fetch() migration**: Onboarding step-1 still uses raw `fetch()` for slug check and logo upload. The equivalent settings forms use `useHttp` correctly. Migration deferred
