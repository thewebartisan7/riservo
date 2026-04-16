# Handoff

**Session**: R-1B — Admin as Provider
**Date**: 2026-04-16
**Status**: Complete

---

## What Was Built

The product half of REVIEW-1 issue #1: an admin can now become a bookable provider, onboarding cannot complete with unstaffed services, and the public page hides services that have no one to perform them. R-1A set the data model for this; R-1B delivers the UX and the launch gate on top. New architectural decision **D-062** recorded in `docs/decisions/DECISIONS-DASHBOARD-SETTINGS.md`.

### Onboarding step 3 — provider opt-in

- `OnboardingController::showService()` returns `adminProvider = { exists, schedule, serviceIds }`, `businessHoursSchedule`, and `hasOtherProviders` so the step-3 UI can pre-fill the schedule editor and tailor its copy.
- `OnboardingController::storeService()` accepts optional `provider_opt_in: bool` and `provider_schedule: DaySchedule[]`. Inside a transaction: upserts the service, and when opt-in is on: restores / creates the admin's `providers` row, writes availability rules, and idempotently attaches the admin to the service. Opt-in off detaches the admin from *this* service only — other service attachments stay intact.
- `StoreServiceRequest` adds conditional rules: `provider_schedule` is required when `provider_opt_in = true`, nullable otherwise.
- Front end (`resources/js/pages/onboarding/step-3.tsx`): switch + collapsible `WeekScheduleEditor` that defaults from step-2 business hours, "Match business hours" reset button, and the launch-blocked banner with "Be your own first provider" / "Invite a provider instead" CTAs.

### Settings → Account page

New `App\Http\Controllers\Dashboard\Settings\AccountController` at `/dashboard/settings/account` (admin-only). The page is the admin's self-service command-center for their bookability:

- `edit()` — resolves the admin's provider row (including soft-deleted), computes `isProvider`, builds schedule from provider rules or from business hours as fallback, lists the admin's exceptions, and returns active services with an `assigned` flag. Also returns `upcomingBookingsCount` so the turn-off confirmation can warn about in-flight bookings.
- `toggleProvider()` — create + default-attach active services + write schedule from business hours, OR restore, OR soft-delete. Flash `warning` when toggling off leaves any active service unstaffed; otherwise flash `success` with variant copy ("now bookable", "bookable again", "no longer bookable").
- `updateSchedule`, `storeException`, `updateException`, `destroyException`, `updateServices` — full CRUD for the admin's provider, each gated by `activeProviderOrFail()` (aborts 409 with copy pointing at the toggle).
- Routes under the existing `role:admin` settings prefix; Wayfinder regenerated (`resources/js/actions/.../AccountController.ts`).
- Front end (`resources/js/pages/dashboard/settings/account.tsx`): read-only profile card, Bookable-provider Switch wrapped in an `AlertDialog` that shows the upcoming-bookings count on turn-off, inline `WeekScheduleEditor`, exceptions list reusing `ExceptionDialog`, and a checkbox list for services. Submits via `useHttp` with the pending-ref pattern borrowed from `staff/show.tsx`.
- New "You" group (with Account entry) prepended to `resources/js/components/settings/settings-nav.tsx`.

### Settings → Staff page

- `StaffController::index` now returns **all** members (admins + staff) with `role`, `is_provider`, `is_self`, `provider_id`, `services_count`. Admins are ordered first, then staff, each alphabetized.
- Front end (`resources/js/pages/dashboard/settings/staff/index.tsx`): each row shows a "You" badge for the current user, a role pill (Admin / Staff), and a provider-status chip (Provider / Not bookable). The services count is only rendered when the member is an active provider. Non-self admin rows link to the staff detail page; the self admin row links to `/dashboard/settings/account` instead. Toggle is only rendered on staff rows — admins manage themselves via the Account page.

### Launch gate (step 5)

- `OnboardingController::storeLaunch()` blocks when any active service has zero non-soft-deleted providers via `whereDoesntHave('providers')`. On failure it redirects to step 3 with `launchBlocked = { services: [{ id, name }, ...] }` session data, and bumps `onboarding_step` down to 3 if needed so the wizard actually renders step 3.
- New `POST /onboarding/enable-owner-as-provider` → `OnboardingController::enableOwnerAsProvider()`: creates / restores the admin's provider row, writes a default schedule from business hours only if no rules exist yet, and `syncWithoutDetaching`s the admin to every active service. Redirects to step 5 with a success flash.

### Public page defense-in-depth

- `PublicBookingController::show()` now filters services with `whereHas('providers')` instead of `withCount('providers')`. A service that loses its last provider — post-launch or via an admin toggle — disappears from `/{slug}` until a provider is re-attached, with no admin action required.

### End-to-end test

New `tests/Feature/Onboarding/SoloBusinessBookingE2ETest.php`: register → mark verified → walk steps 1–3 with provider opt-in → skip step 4 → launch step 5 → public `POST /booking/{slug}/book` → assert `Booking::count() === 1`, `provider_id` is the admin provider, status `confirmed`, and `BookingReceivedNotification` sent to the admin (the solo provider).

---

## Current Project State

- **Backend**: new `AccountController`, extended `OnboardingController`, extended `StaffController`, updated `PublicBookingController::show()` and `StoreServiceRequest`.
- **Frontend**: new `dashboard/settings/account.tsx`, extended `dashboard/settings/staff/index.tsx`, extended `onboarding/step-3.tsx`, "You" group added to `settings-nav.tsx`.
- **Routes**: 7 new `/dashboard/settings/account/*` routes + 1 new `/onboarding/enable-owner-as-provider`. Wayfinder regenerated.
- **Tests**: full Pest suite green. Key new files: `tests/Feature/Settings/AccountTest.php` (15 tests), `tests/Feature/Onboarding/SoloBusinessBookingE2ETest.php` (1 E2E). Extended: `Step3ServiceTest`, `Step5LaunchTest`, `StaffTest`, `PublicBookingPageTest`, `SettingsAuthorizationTest`.
- **Decisions**: D-062 appended to `docs/decisions/DECISIONS-DASHBOARD-SETTINGS.md`.

---

## What the Next Session Needs to Know

- REVIEW-1 issue #1 is closed. R-2 through R-16 from `docs/reviews/ROADMAP-REVIEW.md` remain independent.
- The "Be your own first provider" CTA attaches the admin to **every** active service. The Account page is the single place to adjust those attachments afterwards; the banner copy already states this on click. No follow-up is required unless real usage surfaces pain.
- Dashboard home does not currently warn when an active service has zero providers — noted as a product-polish follow-up in the R-1B plan (§8 Risks). Worth surfacing before launch so admins notice a disappeared service, but not blocking.
- `upcomingBookingsCount` on the Account page is computed at render time via the bookings relation filtered by `starts_at >= now()` and status `Pending|Confirmed`. It is not persisted. No observer needed; the page always reflects the current truth.

---

## Open Questions / Deferred Items

- **Identity editing on the Account page**: the plan explicitly kept name / email / avatar read-only in R-1B. Folding editing in is a clean follow-up (reuse `uploadAvatar` from `StaffController` — pattern already exists).
- **Provider toggle on another admin's staff row**: currently the row renders no toggle for admin members (admins manage themselves). If a product need emerges where one admin needs to forcibly remove another admin's bookability, revisit. For now the explicit boundary (admins are self-service) is a feature.
- **Dashboard-level "unstaffed service" warning**: tracked informally in this handoff. Not in scope for R-1B.
