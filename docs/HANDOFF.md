# Handoff

**Session**: R-1A — Provider Model Refactor
**Date**: 2026-04-16
**Status**: Complete

---

## What Was Built

A structural refactor that separates "dashboard access and permissions" (role) from "bookability" (provider) as orthogonal first-class concepts. No new user-visible features; every identifier carrying the legacy "collaborator" term was renamed across backend and frontend. New architectural decision **D-061** recorded in `docs/decisions/DECISIONS-AUTH.md`.

### Data Model

- **New table `providers`**: one row per bookable person per business. Columns: `id`, `business_id`, `user_id`, `created_at`, `updated_at`, `deleted_at` (soft-delete). Unique index on `(business_id, user_id, deleted_at)`.
- **Renamed `business_user` → `business_members`**: pivot now carries `role` only (`admin` | `staff`). `is_active` is dropped; lifecycle is expressed via soft-delete (`SoftDeletes`).
- **Renamed `collaborator_service` → `provider_service`** (FK → `providers`).
- **Repointed FKs** `bookings.collaborator_id`, `availability_rules.collaborator_id`, `availability_exceptions.collaborator_id` → `provider_id` (FK → `providers`). `availability_exceptions.provider_id` stays nullable (D-021: business-level exceptions have `NULL`).
- **Renamed column** `businesses.allow_collaborator_choice` → `allow_provider_choice`.
- **Retired enum case** `BusinessUserRole::Collaborator` / `'collaborator'` → `BusinessMemberRole::Staff` / `'staff'`.

### Migrations

Nine ordered migrations under `database/migrations/2026_04_16_*`: rename pivot, create providers, backfill providers from staff members, drop `is_active`, create `provider_service` and migrate from old table, repoint bookings/rules/exceptions FKs, and rename the boolean column. Each migration is atomic with up + down. `php artisan migrate:fresh --seed` leaves the DB in the new shape.

### Models

- New `App\Models\Provider` with `SoftDeletes`, relations to `Business`, `User`, `Service` (many-to-many via `provider_service`), `AvailabilityRule` (has-many), `AvailabilityException` (has-many), and `Booking` (has-many). `displayName` accessor returns `user?->name`.
- New `App\Models\BusinessMember` pivot (replaces `BusinessUser`) with `SoftDeletes`.
- `Business` gains `providers()` (HasMany), drops `users()` in favor of `members()`; `collaborators()` renamed to `staff()`.
- `User` gets `providers()` HasMany; loses `services()`, `availabilityRules()`, `availabilityExceptions()`, `bookingsAsCollaborator()` — all moved to Provider.
- `Service`, `Booking`, `AvailabilityRule`, `AvailabilityException` — relation `collaborator()` renamed to `provider()`.

### Services

- `SlotGeneratorService` methods now accept `Provider $provider` instead of `User $collaborator`. Public methods `getEligibleProviders`, `assignProvider`, `leastBusyProvider`. Booking-conflict queries use `provider_id`.
- `AvailabilityService` — parameter-type change across the board; queries read from `$provider->availabilityRules()` / `$provider->availabilityExceptions()`.

### Controllers

- `Dashboard\Settings\CollaboratorController` split into **`StaffController`** (team membership, invitations, avatar, show/index) and **`ProviderController`** (schedule, exceptions, toggle soft-delete, sync services).
- `Booking\PublicBookingController` — `collaborators()` renamed to `providers()`; slot/date endpoints accept `provider_id` query param.
- `Dashboard\BookingController` and `Dashboard\CalendarController` — provider dropdown / filter; eager-load `provider.user`.
- `Auth\InvitationController::accept()` is transaction-wrapped and creates: User → `business_members` (role=staff) → `providers` row → `provider_service` attachments → mark invitation accepted → login.

### Form Requests

Renamed: `StoreStaffInvitationRequest`, `UpdateProviderScheduleRequest`, `StoreProviderExceptionRequest`, `UpdateProviderExceptionRequest`, new `UpdateProviderServicesRequest`. All `collaborator_id(s)` fields renamed to `provider_id(s)` with `exists:providers,id` plus business-scoped closure rules.

### Routes

- `/dashboard/settings/collaborators/*` removed. New: `/dashboard/settings/staff/*` (index, show, invite, resend, cancel, avatar) and `/dashboard/settings/providers/{provider}/*` (toggle, schedule, exceptions, services).
- `/booking/{slug}/collaborators` → `/booking/{slug}/providers` (route name `booking.providers`).
- Slot and available-dates endpoints keep paths, but the query param `collaborator_id` is now `provider_id`.
- Wayfinder regenerated; `CollaboratorController.ts` replaced by `StaffController.ts` + `ProviderController.ts`; `routes/settings/staff/` and `routes/settings/providers/` generated.

### Frontend

- Pages: `dashboard/settings/collaborators/{index,show}.tsx` moved to `dashboard/settings/staff/{index,show}.tsx`. The show page gates schedule/exceptions blocks on `staff.provider_id !== null` (R-1A shows admin as staff-only with no schedule; R-1B adds the opt-in).
- Components renamed: `collaborator-filter` → `provider-filter`, `collaborator-picker` → `provider-picker`, `collaborator-invite-dialog` → `staff-invite-dialog`.
- Types renamed: `CalendarCollaborator` → `CalendarProvider`, `Collaborator` (booking) → `Provider`. Role union in `PageProps['auth']['role']` is `'admin' | 'staff' | 'customer' | null`.
- Utilities: `getCollaboratorColor(Map)` → `getProviderColor(Map)`.
- Props/keys: `collaborators`/`collaborator`/`collaborator_id(s)` → `providers`/`provider`/`provider_id(s)` everywhere.
- `lang/en.json` — keys and values rewritten to "staff"/"provider"/"team member" per context.

### Factories, Seeders, Tests

- New `ProviderFactory`. `BookingFactory`, `AvailabilityRuleFactory`, `AvailabilityExceptionFactory` default to `provider_id => Provider::factory()`.
- `BusinessSeeder` creates `business_members` rows, then a `providers` row per staff member (admin stays member-only per D-061; admin-as-provider is R-1B), then attaches services via `provider_service`.
- New Pest global helpers in `tests/Pest.php`: `attachAdmin`, `attachStaff`, `attachProvider`.
- `tests/Feature/Settings/CollaboratorTest.php` split into `StaffTest.php` (membership) + `ProviderTest.php` (bookability).
- Renamed: `CollaboratorsApiTest` → `ProvidersApiTest`, `CollaboratorAssignmentTest` → `ProviderAssignmentTest`.
- Every test file with `collaborator_id` / `collaborator` / `$this->collaborator` updated to the provider vocabulary and the new fixture helpers.

---

## Current Project State

- **Backend**: models now include `Provider` and `BusinessMember`; `BusinessUser` and `BusinessUserRole` deleted.
- **Migrations**: 9 new rename/repoint/create migrations under `database/migrations/2026_04_16_*`. Original `2026_04_12_*` migrations still exist and are part of the migration chain.
- **Tests**: **377 passing** (1391 assertions). (Net −2 from the previous handoff's 379 because `CollaboratorTest.php` was split into `StaffTest.php` + `ProviderTest.php` and several legacy fixtures were consolidated; the split added more targeted tests but removed a few duplicate assertions.)
- **Build**: `npm run build` clean; `vendor/bin/pint --dirty` clean.
- **Grep for `collaborator|Collaborator`** across `app/`, `resources/`, `tests/`, `routes/`, `lang/`: **zero matches**. Migration files under `database/migrations/` legitimately retain the old names because their purpose is to document the rename from the old columns (per §5 of PLAN-R-1A).

---

## What the Next Session (R-1B) Needs to Know

R-1B is the admin-as-provider opt-in: the feature that actually closes REVIEW-1 issue #1.

- The data model already supports an admin being a provider — it just requires inserting a `providers` row for an admin `business_members` record. The R-1A refactor preserved the legacy behavior that admins have NO provider row by default.
- R-1B tasks:
  - Onboarding step-3 (business details) adds an "I provide services to customers" opt-in. Creating the business inserts both an admin `business_members` row AND a `providers` row when checked.
  - New Settings → Account page with the same toggle — flipping it on creates/restores the provider row; flipping it off soft-deletes it.
  - Step-5 launch gate: the business cannot be activated if no provider exists.
  - Public-page service filtering: services show only if at least one non-soft-deleted provider is assigned to them.
  - End-to-end test: solo admin completes onboarding with the toggle on → is bookable.
- The `dashboard/settings/staff/show.tsx` page gates the schedule/services/exceptions sections on `staff.provider_id !== null`. R-1B can reuse this shape by surfacing the toggle on the admin's own row.

---

## Open Questions / Deferred Items

- **Migration history tidying**: The nine R-1A rename migrations retain the `collaborator` word in their filenames and contents by design (they describe the rename FROM old columns). For a pre-launch product with no production data, these could be collapsed into the `2026_04_12_*` creates to eliminate every trace of "collaborator" — but that deviates from the PLAN-R-1A §5 migration plan. Propose to the developer whether to consolidate before launch.
- **R-1B still owed**: the actual fix for the "unbookable solo business" in REVIEW-1. R-1A only clears the path.
- **Other review items**: R-2 through R-16 from `docs/reviews/ROADMAP-REVIEW.md` remain independent.
