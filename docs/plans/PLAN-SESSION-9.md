---
name: PLAN-SESSION-9
description: "Session 9: Business Settings"
type: plan
status: shipped
created: 2026-04-15
updated: 2026-04-15
---

# Session 9 — Business Settings — Implementation Plan

## Context

After onboarding (Session 6), business admins need to edit their configuration: profile, booking preferences, working hours, exceptions, services, collaborators, and embed settings. Session 9 builds the full settings area accessible from the dashboard sidebar.

## Goal

Build the complete business settings area under `/dashboard/settings/*` — admin-only pages for managing all business configuration after onboarding.

## Prerequisites

- All 294 tests from Sessions 1-8 pass ✅
- Models exist: Business, Service, BusinessHour, AvailabilityRule, AvailabilityException, BusinessInvitation ✅
- Onboarding creates initial data; settings lets admins edit it afterwards ✅
- HANDOFF.md has no blocking issues ✅

## Scope

**Included:**
1. Business profile editing (name, description, logo, contact, slug)
2. Booking settings (confirmation mode, collaborator choice, cancellation window, payment mode, assignment strategy, reminder hours)
3. Business working hours editor
4. Business-level exceptions (closures, special hours)
5. Service management (CRUD + collaborator assignment + deactivate)
6. Collaborator management (list, invite, schedule, exceptions, avatar, deactivate)
7. Embed & Share (iframe `?embed=1`, popup JS snippet, copy buttons, preview)

**Not included:**
- Calendar view (Session 12)
- Notifications (Session 10)
- Billing (Session 11)

## Decisions to Record

- **D-052** — Settings controllers under `Dashboard\Settings` namespace
- **D-053** — Collaborator `is_active` on pivot (not soft delete)
- **D-054** — Embed mode via `?embed=1` query parameter
- **D-055** — Settings sub-navigation via nested layout

## Migration

One migration: `add_is_active_to_business_user_table`
- Add `boolean is_active default true` to `business_user` pivot
- Update `BusinessUser` model casts
- Update `Business::users()` to `withPivot(['role', 'is_active'])`

## Controller Structure

7 controllers under `App\Http\Controllers\Dashboard\Settings\`:

| Controller | Methods |
|---|---|
| `ProfileController` | `edit`, `update`, `uploadLogo`, `checkSlug` |
| `BookingSettingsController` | `edit`, `update` |
| `WorkingHoursController` | `edit`, `update` |
| `BusinessExceptionController` | `index`, `store`, `update`, `destroy` |
| `ServiceController` | `index`, `create`, `store`, `edit`, `update` |
| `CollaboratorController` | `index`, `show`, `updateSchedule`, `storeException`, `updateException`, `destroyException`, `invite`, `resendInvitation`, `cancelInvitation`, `toggleActive`, `uploadAvatar` |
| `EmbedController` | `edit` |

## Form Requests

Under `App\Http\Requests\Dashboard\Settings\`:

- `UpdateProfileRequest` — name, slug, description, phone, email, address
- `UpdateBookingSettingsRequest` — confirmation_mode, allow_collaborator_choice, cancellation_window_hours, payment_mode, assignment_strategy, reminder_hours
- `UpdateWorkingHoursRequest` — hours array (7 days with windows)
- `StoreBusinessExceptionRequest` / `UpdateBusinessExceptionRequest` — start_date, end_date, start_time, end_time, type, reason
- `StoreSettingsServiceRequest` / `UpdateSettingsServiceRequest` — name, description, duration, price, buffers, slot_interval, is_active, collaborator_ids
- `StoreCollaboratorInvitationRequest` — email, service_ids
- `UpdateCollaboratorScheduleRequest` — rules array
- `StoreCollaboratorExceptionRequest` / `UpdateCollaboratorExceptionRequest` — same as business exception

## Frontend Pages

Under `resources/js/pages/dashboard/settings/`:

| Page | Purpose |
|---|---|
| `profile.tsx` | Business profile form |
| `booking.tsx` | Booking settings form |
| `hours.tsx` | Weekly schedule editor (reuses `WeekScheduleEditor`) |
| `exceptions.tsx` | Business exceptions table + dialog |
| `services/index.tsx` | Service list |
| `services/create.tsx` | Service creation form |
| `services/edit.tsx` | Service edit form |
| `collaborators/index.tsx` | Collaborator list + pending invitations |
| `collaborators/show.tsx` | Individual collaborator: schedule, exceptions, avatar |
| `embed.tsx` | Embed snippets + copy buttons + preview |

## Frontend Components

Under `resources/js/components/settings/`:

- `settings-nav.tsx` — sub-navigation for settings sections
- `exception-dialog.tsx` — create/edit AvailabilityException dialog (shared business + collaborator)
- `service-form.tsx` — shared service form (create + edit)
- `collaborator-invite-dialog.tsx` — invite dialog
- `embed-snippet.tsx` — code snippet with copy button

New layout: `resources/js/layouts/settings-layout.tsx` — wraps `authenticated-layout`, adds settings sub-nav.

## Routes

All admin-only under `dashboard/settings` prefix:

```
GET    /dashboard/settings/profile           → ProfileController@edit
PUT    /dashboard/settings/profile           → ProfileController@update
POST   /dashboard/settings/profile/logo      → ProfileController@uploadLogo
POST   /dashboard/settings/profile/slug-check → ProfileController@checkSlug

GET    /dashboard/settings/booking           → BookingSettingsController@edit
PUT    /dashboard/settings/booking           → BookingSettingsController@update

GET    /dashboard/settings/hours             → WorkingHoursController@edit
PUT    /dashboard/settings/hours             → WorkingHoursController@update

GET    /dashboard/settings/exceptions        → BusinessExceptionController@index
POST   /dashboard/settings/exceptions        → BusinessExceptionController@store
PUT    /dashboard/settings/exceptions/{e}    → BusinessExceptionController@update
DELETE /dashboard/settings/exceptions/{e}    → BusinessExceptionController@destroy

GET    /dashboard/settings/services          → ServiceController@index
GET    /dashboard/settings/services/create   → ServiceController@create
POST   /dashboard/settings/services          → ServiceController@store
GET    /dashboard/settings/services/{s}      → ServiceController@edit
PUT    /dashboard/settings/services/{s}      → ServiceController@update

GET    /dashboard/settings/collaborators          → CollaboratorController@index
GET    /dashboard/settings/collaborators/{u}      → CollaboratorController@show
PUT    /dashboard/settings/collaborators/{u}/schedule    → updateSchedule
POST   /dashboard/settings/collaborators/{u}/exceptions → storeException
PUT    /dashboard/settings/collaborators/{u}/exceptions/{e}    → updateException
DELETE /dashboard/settings/collaborators/{u}/exceptions/{e}    → destroyException
POST   /dashboard/settings/collaborators/{u}/toggle-active    → toggleActive
POST   /dashboard/settings/collaborators/{u}/avatar           → uploadAvatar
POST   /dashboard/settings/collaborators/invite               → invite
POST   /dashboard/settings/collaborators/invitations/{i}/resend → resendInvitation
DELETE /dashboard/settings/collaborators/invitations/{i}       → cancelInvitation

GET    /dashboard/settings/embed             → EmbedController@edit
```

## Embed Implementation

1. `PublicBookingController::show()` passes `embed` boolean from `request('embed')` to Inertia props
2. `booking-layout.tsx` strips header/footer when `embed` prop is true
3. `public/embed.js` — vanilla JS file (not Vite-bundled) that:
   - Reads `data-slug` from its own script tag
   - Creates an iframe overlay/modal when trigger element is clicked
   - `data-riservo-open` attribute on any element triggers the modal
   - Appends `?embed=1` to the iframe src

## Implementation Order

1. **Foundation**: migration, model updates, settings-layout, settings-nav, routes, sidebar link
2. **Profile + Booking Settings**: simplest pages, validates the pattern
3. **Working Hours + Exceptions**: reuses onboarding component
4. **Service Management**: CRUD with collaborator assignment
5. **Collaborator Management**: most complex section
6. **Embed & Share**: standalone feature, built last

Each phase: implement → write tests → run tests → confirm green.

## Testing Plan

8 test files under `tests/Feature/Settings/`:

| File | Est. Tests | Covers |
|---|---|---|
| `ProfileTest` | ~8 | edit renders, update saves, slug validation, logo upload, auth |
| `BookingSettingsTest` | ~6 | edit renders, update saves, enum validation, reminder_hours |
| `WorkingHoursTest` | ~5 | edit renders, update replaces hours, validation |
| `BusinessExceptionTest` | ~8 | CRUD, date validation, partial vs full-day, scoping |
| `ServiceTest` | ~10 | CRUD, slug uniqueness, collaborator sync, toggle active, scoping |
| `CollaboratorTest` | ~12 | list, invite, resend, cancel, schedule CRUD, exception CRUD, avatar, toggle active, scoping |
| `EmbedTest` | ~4 | page renders, snippets contain slug, embed param strips layout |
| `SettingsAuthorizationTest` | ~6 | all routes 403 for collaborator, 302 for guest |

~60 tests total.

## File List

### Created (backend — 20 files)
- `database/migrations/..._add_is_active_to_business_user_table.php`
- `app/Http/Controllers/Dashboard/Settings/ProfileController.php`
- `app/Http/Controllers/Dashboard/Settings/BookingSettingsController.php`
- `app/Http/Controllers/Dashboard/Settings/WorkingHoursController.php`
- `app/Http/Controllers/Dashboard/Settings/BusinessExceptionController.php`
- `app/Http/Controllers/Dashboard/Settings/ServiceController.php`
- `app/Http/Controllers/Dashboard/Settings/CollaboratorController.php`
- `app/Http/Controllers/Dashboard/Settings/EmbedController.php`
- `app/Http/Requests/Dashboard/Settings/UpdateProfileRequest.php`
- `app/Http/Requests/Dashboard/Settings/UpdateBookingSettingsRequest.php`
- `app/Http/Requests/Dashboard/Settings/UpdateWorkingHoursRequest.php`
- `app/Http/Requests/Dashboard/Settings/StoreBusinessExceptionRequest.php`
- `app/Http/Requests/Dashboard/Settings/UpdateBusinessExceptionRequest.php`
- `app/Http/Requests/Dashboard/Settings/StoreSettingsServiceRequest.php`
- `app/Http/Requests/Dashboard/Settings/UpdateSettingsServiceRequest.php`
- `app/Http/Requests/Dashboard/Settings/StoreCollaboratorInvitationRequest.php`
- `app/Http/Requests/Dashboard/Settings/UpdateCollaboratorScheduleRequest.php`
- `app/Http/Requests/Dashboard/Settings/StoreCollaboratorExceptionRequest.php`
- `app/Http/Requests/Dashboard/Settings/UpdateCollaboratorExceptionRequest.php`

### Created (frontend — 17 files)
- `resources/js/layouts/settings-layout.tsx`
- `resources/js/components/settings/settings-nav.tsx`
- `resources/js/components/settings/exception-dialog.tsx`
- `resources/js/components/settings/service-form.tsx`
- `resources/js/components/settings/collaborator-invite-dialog.tsx`
- `resources/js/components/settings/embed-snippet.tsx`
- `resources/js/pages/dashboard/settings/profile.tsx`
- `resources/js/pages/dashboard/settings/booking.tsx`
- `resources/js/pages/dashboard/settings/hours.tsx`
- `resources/js/pages/dashboard/settings/exceptions.tsx`
- `resources/js/pages/dashboard/settings/services/index.tsx`
- `resources/js/pages/dashboard/settings/services/create.tsx`
- `resources/js/pages/dashboard/settings/services/edit.tsx`
- `resources/js/pages/dashboard/settings/collaborators/index.tsx`
- `resources/js/pages/dashboard/settings/collaborators/show.tsx`
- `resources/js/pages/dashboard/settings/embed.tsx`
- `public/embed.js`

### Created (tests — 8 files)
- `tests/Feature/Settings/ProfileTest.php`
- `tests/Feature/Settings/BookingSettingsTest.php`
- `tests/Feature/Settings/WorkingHoursTest.php`
- `tests/Feature/Settings/BusinessExceptionTest.php`
- `tests/Feature/Settings/ServiceTest.php`
- `tests/Feature/Settings/CollaboratorTest.php`
- `tests/Feature/Settings/EmbedTest.php`
- `tests/Feature/Settings/SettingsAuthorizationTest.php`

### Modified (7 files)
- `routes/web.php` — add settings route group
- `resources/js/layouts/authenticated-layout.tsx` — add Settings nav item
- `resources/js/layouts/booking-layout.tsx` — embed mode support
- `resources/js/pages/booking/show.tsx` — pass embed prop
- `app/Http/Controllers/Booking/PublicBookingController.php` — pass embed query param
- `app/Models/BusinessUser.php` — add is_active cast
- `app/Models/Business.php` — update withPivot

## Verification

1. Run `php artisan test --compact` — all tests pass (294 existing + ~60 new)
2. Run `npm run build` — no build errors
3. Run `npx tsc --noEmit` — no TypeScript errors
4. Run `vendor/bin/pint --dirty --format agent` — no style violations
5. Manual: navigate dashboard sidebar → Settings → each section loads and forms submit
6. Manual: visit `/{slug}?embed=1` — stripped layout renders
7. Manual: test embed.js popup on a test HTML page
