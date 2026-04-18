---
name: PLAN-SESSION-6
description: "Session 6: Business Onboarding Wizard"
type: plan
status: shipped
created: 2026-04-15
updated: 2026-04-15
---

# Session 6 Plan: Business Onboarding Wizard

## Context

After a business owner registers and verifies their email, they currently land on a placeholder dashboard with only a minimal Business record (name + slug). There's no way to configure working hours, services, or invite collaborators. Session 6 builds a distraction-free multi-step wizard that guides the owner through the essential setup before they can access the dashboard.

## Goal

Build a 5-step onboarding wizard that new business owners must complete before accessing the dashboard, covering business profile, working hours, first service, collaborator invites, and launch.

## Prerequisites

- All 163 tests pass (verified)
- Business, BusinessHour, Service, BusinessInvitation models exist (Session 2)
- Auth system with registration, email verification, role middleware complete (Session 5)
- SlugService with reserved slugs and generation logic exists (Session 5)
- InvitationNotification exists (Session 5)

## Scope

**Included:**
- 5-step wizard (profile, hours, service, invites, summary/launch)
- Onboarding middleware that redirects unboarded admins to the wizard
- Dedicated onboarding layout with progress indicator
- Logo upload with immediate storage
- Live slug availability check
- Working hours visual editor (multiple time windows per day)
- Collaborator invitation with service pre-assignment (`service_ids` JSON on invitations)
- Summary page with public booking URL
- Dedicated welcome page at `/dashboard/welcome`
- Step tracking (resumable at exact step)

**Not included:**
- Timezone selection (default Europe/Zurich, editable in Session 9)
- Service description field (kept minimal for wizard — full editor in Session 9)
- Collaborator schedule setup (done by the collaborator themselves or admin in Session 9)

## Decisions to Record

- **D-040**: Onboarding state via `onboarding_step` + `onboarding_completed_at` on Business
- **D-041**: Service pre-assignment via `service_ids` JSON on `business_invitations`
- **D-042**: Logo uploaded immediately to `Storage::disk('public')`, path stored on Business

## Implementation Steps

### Phase 1: Database & Models

**1a. Migration: add onboarding fields to businesses**
```
onboarding_step: unsignedTinyInteger, default 1
onboarding_completed_at: timestamp, nullable
```

**1b. Migration: add service_ids to business_invitations**
```
service_ids: json, nullable
```

**1c. Update Business model**
- Add to fillable: `onboarding_step`, `onboarding_completed_at`
- Add cast: `'onboarding_completed_at' => 'datetime'`
- Add helper: `isOnboarded(): bool`

**1d. Update BusinessInvitation model**
- Add to fillable: `service_ids`
- Add cast: `'service_ids' => 'array'`

**1e. Add `services()` relationship to User model** (inverse of Service::collaborators)

### Phase 2: Middleware & Routes

**2a. Create `EnsureOnboardingComplete` middleware**
- If user is admin and `business.onboarding_completed_at` is null → redirect to `/onboarding/step/{onboarding_step}`
- Collaborators of unboarded businesses pass through (they can't exist yet in practice)

**2b. Register middleware alias** in `bootstrap/app.php`: `'onboarded' => EnsureOnboardingComplete::class`

**2c. Routes** in `routes/web.php`:
```
# Onboarding (auth + verified + role:admin, NO onboarded middleware)
GET  /onboarding/step/{step}  → OnboardingController@show     (step 1-5)
POST /onboarding/step/{step}  → OnboardingController@store     (step 1-5)
POST /onboarding/slug-check   → OnboardingController@checkSlug (JSON)
POST /onboarding/logo-upload  → OnboardingController@uploadLogo (JSON)

# Dashboard (add 'onboarded' middleware)
GET  /dashboard               → existing dashboard
GET  /dashboard/welcome       → WelcomeController@show
```

**2d. Add `onboarding` to `SlugService::RESERVED_SLUGS`**

### Phase 3: Backend Controllers

**3a. OnboardingController** (`app/Http/Controllers/OnboardingController.php`)

`show(int $step)`:
- Validate step 1-5, cannot skip ahead past `$business->onboarding_step`
- If already onboarded, redirect to dashboard
- Load step-specific data, render `onboarding/step-{step}`

`store(Request $request, int $step)`:
- Delegates to per-step private methods
- Each method validates, persists, advances `onboarding_step`, redirects to next

Step-specific logic:
- **Step 1 (profile)**: Update Business fields (name, slug, description, logo, phone, email, address)
- **Step 2 (hours)**: Delete existing hours, bulk insert from nested array `[{day_of_week, windows: [{open_time, close_time}]}]`
- **Step 3 (service)**: Create or update first service, auto-generate slug from name
- **Step 4 (invites)**: Create BusinessInvitation records with `service_ids`, send InvitationNotification. Empty array = skip.
- **Step 5 (launch)**: Set `onboarding_completed_at = now()`, redirect to `/dashboard/welcome`

`checkSlug(Request $request)`:
- Returns JSON response: `{ available: bool }` (consumed by `useHttp` on frontend)
- Uses SlugService, excludes current business from "taken" check

`uploadLogo(Request $request)`:
- Validate: image, max 2MB, jpg/png/webp
- Store via `Storage::disk('public')->putFile('logos', ...)`
- Update business.logo
- Returns JSON response: `{ path: string, url: string }` (consumed by `useHttp` on frontend)

**3b. WelcomeController** (`app/Http/Controllers/WelcomeController.php`)
- Renders `dashboard/welcome` with public booking URL

**3c. Form Requests** (4 files in `app/Http/Requests/Onboarding/`):
- `StoreProfileRequest` — name, slug (unique excluding self, not reserved, regex), description, phone, email, address, logo path
- `StoreHoursRequest` — hours array with nested day_of_week + windows (open_time, close_time, close > open)
- `StoreServiceRequest` — name, duration_minutes (5-480), price (nullable), buffer_before/after (0-60), slot_interval (5/10/15/20/30/60)
- `StoreInvitationsRequest` — invitations array with email + service_ids

### Phase 4: Modify InvitationController

In `InvitationController@accept`, after creating User and BusinessUser pivot, auto-assign services:
```php
if ($invitation->service_ids) {
    $validServiceIds = Service::where('business_id', $invitation->business_id)
        ->whereIn('id', $invitation->service_ids)->pluck('id');
    $user->services()->attach($validServiceIds);
}
```

### Phase 5: Frontend Layout

**5a. `resources/js/layouts/onboarding-layout.tsx`**
- Minimal chrome: logo top-left, logout top-right
- Progress indicator: step dots/labels, clickable for completed steps
- Labels: ["Business Profile", "Working Hours", "First Service", "Invite Team", "Review & Launch"]
- Centered content container (max-w-2xl)

### Phase 6: Frontend Pages

**6a. `resources/js/pages/onboarding/step-1.tsx`** (Business Profile)
- Pre-filled form with current business data
- Slug field: URL prefix display (`riservo.ch/`), debounced availability check via Inertia v3 `useHttp` hook
- Logo upload: file input → immediate POST via `useHttp` → preview thumbnail
- All standalone HTTP requests (slug check, logo upload) use `useHttp` from `@inertiajs/react` — no fetch/axios
- COSS UI: Card, Input, Textarea, Button

**6b. `resources/js/pages/onboarding/step-2.tsx`** (Working Hours)
- Week schedule editor with 7 day rows
- Each day: toggle open/closed, multiple time windows
- Time selects in 15-minute increments
- Default: Mon-Fri 09:00-18:00, Sat-Sun closed
- Sub-components in `resources/js/components/onboarding/`:
  - `week-schedule-editor.tsx` — manages 7 days
  - `day-row.tsx` — toggle + time window list
  - `time-window-row.tsx` — open/close selects + remove button

**6c. `resources/js/pages/onboarding/step-3.tsx`** (First Service)
- Name, duration (number input), price (with "on request" checkbox)
- Buffer before/after, slot interval (select)
- Pre-populated if service exists (resume)

**6d. `resources/js/pages/onboarding/step-4.tsx`** (Invite Collaborators)
- Dynamic list of invitation rows (email + service checkboxes)
- "Add another" and "Skip this step" buttons
- Shows existing pending invitations if resuming

**6e. `resources/js/pages/onboarding/step-5.tsx`** (Summary & Launch)
- Read-only cards for each section with "Edit" links to previous steps
- Public booking URL with copy button
- "Launch your business" button

**6f. `resources/js/pages/dashboard/welcome.tsx`** (Welcome Page)
- Uses AuthenticatedLayout (sidebar visible — user is now onboarded)
- Celebration heading, public URL with copy, next-step suggestion cards

### Phase 7: TypeScript Types

Update `resources/js/types/index.d.ts` with onboarding-related interfaces.

### Phase 8: Translation Keys

Add all new strings to `resources/lang/en.json`.

## File List

### New files (PHP — ~14)
- `database/migrations/..._add_onboarding_fields_to_businesses_table.php`
- `database/migrations/..._add_service_ids_to_business_invitations_table.php`
- `app/Http/Middleware/EnsureOnboardingComplete.php`
- `app/Http/Controllers/OnboardingController.php`
- `app/Http/Controllers/WelcomeController.php`
- `app/Http/Requests/Onboarding/StoreProfileRequest.php`
- `app/Http/Requests/Onboarding/StoreHoursRequest.php`
- `app/Http/Requests/Onboarding/StoreServiceRequest.php`
- `app/Http/Requests/Onboarding/StoreInvitationsRequest.php`

### New files (TypeScript — ~9)
- `resources/js/layouts/onboarding-layout.tsx`
- `resources/js/pages/onboarding/step-1.tsx`
- `resources/js/pages/onboarding/step-2.tsx`
- `resources/js/pages/onboarding/step-3.tsx`
- `resources/js/pages/onboarding/step-4.tsx`
- `resources/js/pages/onboarding/step-5.tsx`
- `resources/js/pages/dashboard/welcome.tsx`
- `resources/js/components/onboarding/week-schedule-editor.tsx`
- `resources/js/components/onboarding/day-row.tsx`
- `resources/js/components/onboarding/time-window-row.tsx`

### Modified files
- `app/Models/Business.php` — fillable, cast, `isOnboarded()`
- `app/Models/BusinessInvitation.php` — fillable, cast for `service_ids`
- `app/Models/User.php` — add `services()` relationship
- `app/Services/SlugService.php` — add `onboarding` to reserved, add `isTakenExcluding()`
- `app/Http/Controllers/Auth/InvitationController.php` — auto-assign services on acceptance
- `routes/web.php` — onboarding routes, `onboarded` middleware on dashboard
- `bootstrap/app.php` — register middleware alias
- `resources/js/types/index.d.ts` — onboarding interfaces
- `resources/lang/en.json` — new translation keys

### Test files (~9)
- `tests/Feature/Onboarding/OnboardingMiddlewareTest.php`
- `tests/Feature/Onboarding/Step1ProfileTest.php`
- `tests/Feature/Onboarding/Step2HoursTest.php`
- `tests/Feature/Onboarding/Step3ServiceTest.php`
- `tests/Feature/Onboarding/Step4InvitationsTest.php`
- `tests/Feature/Onboarding/Step5LaunchTest.php`
- `tests/Feature/Onboarding/NavigationTest.php`
- `tests/Feature/Onboarding/SlugCheckTest.php`
- `tests/Feature/Onboarding/WelcomePageTest.php`

## Testing Plan

1. **Middleware tests**: redirect when not onboarded, pass when onboarded, correct step in URL
2. **Step 1**: profile update, slug validation (unique, reserved, format), logo upload
3. **Step 2**: default hours template, custom hours with multiple windows, validation (close > open)
4. **Step 3**: service creation, "on request" pricing, slug auto-generation, resumability
5. **Step 4**: invitation creation with service_ids, notification sent, skip with empty array
6. **Step 5**: sets onboarding_completed_at, redirects to welcome
7. **Navigation**: cannot skip ahead, can go back, correct step on resume
8. **Slug check**: available/taken/reserved/own slug/invalid format
9. **Invitation acceptance**: service auto-assignment from `service_ids`
10. **Welcome page**: renders after completion, shows correct URL

## Verification

1. Run `php artisan test --compact` — all tests pass (old + new)
2. Run `vendor/bin/pint --dirty --format agent` — no style issues
3. Run `npm run build` — builds cleanly
4. Manual browser test: register → verify email → complete wizard → see welcome page → access dashboard
