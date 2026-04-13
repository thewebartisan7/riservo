# Handoff

**Session**: 6 — Business Onboarding Wizard  
**Date**: 2026-04-13  
**Status**: Complete

---

## What Was Built

Session 6 implemented a 5-step onboarding wizard that new business admins must complete before accessing the dashboard. The wizard covers business profile setup, working hours, first service creation, collaborator invitations, and a summary/launch step.

### Backend

**2 migrations:**
- `add_onboarding_fields_to_businesses_table` — `onboarding_step` (default 1), `onboarding_completed_at` (nullable)
- `add_service_ids_to_business_invitations_table` — `service_ids` JSON nullable

**1 middleware:**
- `EnsureOnboardingComplete` — redirects unboarded admins to `/onboarding/step/{step}`, registered as `onboarded` alias

**2 controllers:**
- `OnboardingController` — `show(step)`, `store(step)`, `checkSlug()`, `uploadLogo()` — central hub for all 5 wizard steps
- `WelcomeController` — renders `/dashboard/welcome` after onboarding

**4 form requests** in `App\Http\Requests\Onboarding\`:
- `StoreProfileRequest` — validates business profile with slug uniqueness/reserved check
- `StoreHoursRequest` — validates nested array of days with time windows
- `StoreServiceRequest` — validates service fields including slot interval allowlist
- `StoreInvitationsRequest` — validates invitation emails and service_ids

**Model updates:**
- `Business` — added fillable `onboarding_step`, `onboarding_completed_at`; added `isOnboarded()` helper, `invitations()` relationship
- `BusinessInvitation` — added fillable `service_ids` with array cast
- `BusinessFactory` — added `onboarded()` state

**Other backend changes:**
- `SlugService` — added `onboarding` to reserved slugs, added `isTakenExcluding()` method
- `InvitationController@accept` — auto-assigns services from `service_ids` on invitation acceptance
- `bootstrap/app.php` — registered `onboarded` middleware alias
- `routes/web.php` — added 4 onboarding routes (show, store, slug-check, logo-upload), welcome route, added `onboarded` middleware to dashboard group

### Frontend

**1 layout:**
- `onboarding-layout.tsx` — minimal chrome with logo, logout, progress bar, clickable step navigation

**6 pages:**
- `onboarding/step-1.tsx` — business profile with live slug check and logo upload
- `onboarding/step-2.tsx` — working hours editor (7-day schedule with multiple time windows)
- `onboarding/step-3.tsx` — first service creation (name, duration, price, buffers, slot interval)
- `onboarding/step-4.tsx` — collaborator invitations with service assignment chips
- `onboarding/step-5.tsx` — summary view with edit links and launch button
- `dashboard/welcome.tsx` — celebration page with public URL and next-step cards

**3 components** in `components/onboarding/`:
- `week-schedule-editor.tsx` — manages 7 day rows
- `day-row.tsx` — toggle + time windows per day
- `time-window-row.tsx` — open/close time selects + remove button

**Updated:**
- `lang/en.json` — 60+ new translation keys
- `tests/Feature/Auth/MiddlewareTest.php` — updated to use `onboarded()` factory state

### Routes (4 new)

| Route | Purpose |
|-------|---------|
| GET/POST `/onboarding/step/{step}` | Wizard step show/store (1-5) |
| POST `/onboarding/slug-check` | Live slug availability check (JSON) |
| POST `/onboarding/logo-upload` | Immediate logo upload (JSON) |
| GET `/dashboard/welcome` | Post-onboarding welcome page |

---

## Current Project State

- **Backend**: 19 migrations, 11 models, 4 services, 1 DTO, 12 controllers, 8 form requests, 2 notifications, 3 custom middleware
- **Frontend**: 20 pages, 3 layouts, 55 COSS UI components, 4 helper/onboarding components
- **Tests**: 215 passing (566 assertions)
- **Build**: `npm run build` succeeds, `npx tsc --noEmit` clean, `vendor/bin/pint` clean

---

## Key Conventions Established

- **Onboarding controller** in `App\Http\Controllers\OnboardingController` — single controller with step-dispatch pattern
- **Onboarding form requests** in `App\Http\Requests\Onboarding\` namespace
- **Onboarding layout**: `resources/js/layouts/onboarding-layout.tsx` — no sidebar, progress indicator
- **Onboarding components**: `resources/js/components/onboarding/` — reusable schedule editor
- **BusinessFactory `onboarded()` state**: use in tests where business must be fully onboarded
- **JSON API endpoints**: slug check and logo upload return JSON, not Inertia responses; consumed via `fetch()` (see note below about useHttp)
- **Step data saves immediately**: each wizard step persists to real models, not temporary storage
- **Step tracking**: `onboarding_step` only advances forward, never backwards
- **Logo storage**: `Storage::disk('public')` under `logos/` directory

---

## What Session 7 Needs to Know

Session 7 implements the public booking flow at `riservo.ch/{slug}`.

- **Business is fully set up after onboarding**: after Session 6, a business has a profile, working hours, and at least one service. The public booking page can rely on this data existing.
- **Business hours exist**: `business_hours` table has records for open days. Use `AvailabilityService` from Session 3 for slot calculation.
- **Service has a slug**: auto-generated from name during onboarding. Used in `/{slug}/{service-slug}` URL routing.
- **Auth context**: the booking flow is public (no auth required). Guest customers create a Customer record at booking time.
- **Onboarding middleware**: the `onboarded` middleware is on the dashboard route group. Public routes at `/{slug}` should NOT have this middleware.
- **Existing rate limiting**: no rate limiting on public routes yet — Session 7 must add it per the roadmap.

---

## Decisions Recorded

- **D-040**: Onboarding state via `onboarding_step` + `onboarding_completed_at` on Business
- **D-041**: Service pre-assignment via `service_ids` JSON on `business_invitations`
- **D-042**: Logo uploaded immediately to `Storage::disk('public')`, path stored on Business

---

## Open Questions / Deferred Items

- **Inertia client v3 upgrade**: The project has `@inertiajs/react@2.3.21` (v2). The server adapter is v3 (`inertiajs/inertia-laravel@3`). Upgrading the client to v3 would enable the `useHttp` hook for standalone HTTP requests (currently using `fetch()`). This upgrade should be done carefully as it may have breaking changes — consider doing it as a dedicated task.
- **Chunk size warning**: Vite build produces a ~641 KB JS bundle (up from 607 KB in Session 5). Code splitting should be considered before adding many more pages.
- **Working hours edge cases**: The UI allows saving hours where a day has windows but the day is marked as disabled (the controller handles this correctly by filtering). No overlap detection between windows — if a user sets 09:00-17:00 and 10:00-14:00, both are saved. The `AvailabilityService` from Session 3 handles overlapping windows correctly by merging them.
- **CSRF meta tag**: The `fetch()` calls in step-1 rely on a `<meta name="csrf-token">` tag. Verify this is present in the root Blade template — Laravel includes it by default.
