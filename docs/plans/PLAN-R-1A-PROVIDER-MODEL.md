---
name: PLAN-R-1A-PROVIDER-MODEL
description: "R-1A: Provider model refactor (rename CollaboratorService to ProviderService)"
type: plan
status: shipped
created: 2026-04-16
updated: 2026-04-16
---

# Plan — R-1A: Provider Model Refactor

**Source**: prerequisite for `docs/reviews/ROADMAP-REVIEW.md` section R-1.
**Session type**: structural refactor, no user-visible feature changes.
**Status**: Draft — awaiting approval.

---

## 1. Context

REVIEW-1 issue #1 ("solo-business onboarding can produce an unbookable business") surfaced a structural conflation in the data model: the `business_user` pivot mixes two independent concerns — *dashboard access and permissions* (role) and *bookability* (whether customers can reserve time with this person). Every downstream problem in the review on this axis (admins excluded from service assignment, admins cannot be scheduled, the slot engine branching on role) is a symptom of that conflation.

Fixing this with a flag would patch the surface. This session refactors the model so that "staff membership" and "provider" are orthogonal first-class concepts, renames every outdated identifier across backend and frontend, and replaces the `is_active` pivot flag with soft-delete semantics where that better captures the lifecycle. No new user-facing features ship in this session — all behavior is preserved. R-1B builds the admin-as-provider feature on top of the refactored model.

---

## 2. Goal and Scope

### Goal

Put the data model, models, controllers, routes, frontend types, tests, seeders, factories, notifications, and documentation on a clean, orthogonal, accurately-named foundation:

- `business_members` holds "who has dashboard access to this business and at what permission level".
- `providers` holds "who is bookable in this business".
- `admin` and `staff` are dashboard roles; "collaborator" is retired.
- Provider and staff lifecycle is expressed via soft-delete, not a boolean flag.
- Bookings, availability rules, and availability exceptions reference providers, not users.

### In Scope

- All database migrations listed in §5.
- All model, controller, request, route, enum, factory, seeder, and test renames in §6–§10.
- All frontend file, type, and string renames in §11.
- `SlotGeneratorService`, `AvailabilityService`, and `Provider::displayName()` helper.
- Preservation of existing test behavior (every existing test still passes after renames).
- Soft-delete behavior for `business_members` and `providers` using Laravel's `SoftDeletes` trait.
- One new architectural decision (D-061), recorded during the implementation session at the end of R-1A.

### Out of Scope

- Admin-as-provider opt-in flow (onboarding and Settings → Account). That is R-1B.
- Launch gate at step 5. R-1B.
- Any change to the invitation UX surface beyond what the enum/role rename forces.
- Any data model change to `customers`, `services`, `business_hours`, `business_invitations` beyond references to the renamed role enum.
- Billing / Cashier changes — none needed.
- Addressing other review findings (R-2 through R-16 remain independent).

### Invariants after R-1A

- Every row previously in `business_user` with `role = collaborator, is_active = true` is now: one `business_members` row (`role = staff`, not soft-deleted) + one `providers` row (not soft-deleted).
- Every row previously in `business_user` with `role = collaborator, is_active = false` is now: one `business_members` row (`role = staff`, not soft-deleted) + one `providers` row (soft-deleted).
- Every row previously in `business_user` with `role = admin` is one `business_members` row (`role = admin`, not soft-deleted) + **no** `providers` row. (The admin-as-provider opt-in lives in R-1B.)
- Every `collaborator_service` row becomes one `provider_service` row pointing at the matching provider.
- Every `bookings.collaborator_id` value becomes a `bookings.provider_id` value pointing at the matching provider. Historical bookings for soft-deleted staff are preserved; they reference a soft-deleted provider row.
- Every `availability_rules.collaborator_id` and `availability_exceptions.collaborator_id` becomes `provider_id` accordingly.

---

## 3. New Architectural Decisions

### D-061 — Provider is a first-class entity; role governs dashboard access only

- **File**: `docs/decisions/DECISIONS-AUTH.md`
- **Status (proposed)**: accepted
- **Context**: D-014 defined three roles (admin, collaborator, customer). The role model conflated "dashboard permissions" and "customer can book this person". Eligibility queries branched on role string across the slot engine, public booking, manual booking, settings, and calendar. Solo-business owners could not be providers; admins were excluded from schedules and service assignment.
- **Decision**:
  - Rename the `business_user` pivot to `business_members`; retire the `collaborator` role value and replace it with `staff`. The role now names a permission level only (`admin`, `staff`), not a bookability capability.
  - Introduce `providers` as a first-class table: one row per bookable person per business, with soft-delete, and its own schedule, exceptions, service attachments, and bookings. `providers.user_id` is kept nullable-capable in schema (for a future subcontractor-without-login case) but enforced `NOT NULL` in application logic for MVP.
  - Rename `collaborator_service` to `provider_service`. Repoint `bookings.collaborator_id`, `availability_rules.collaborator_id`, and `availability_exceptions.collaborator_id` to `provider_id` (FK → providers).
  - Replace the `is_active` pivot flag with `SoftDeletes` on both `business_members` and `providers`.
- **Consequences**:
  - Role-based authorization continues to use `business_members.role`; bookability-based eligibility uses `Business::providers()`. They no longer share a column.
  - Admin-as-provider becomes a data-model-supported state in R-1B (admin is a member with role=admin, plus optionally a providers row).
  - The legacy term "collaborator" is fully removed. Identifiers that previously carried the word are renamed: relations, enum values, middleware role strings, route segments, Inertia props, frontend types, translation keys, and file names.
  - The `collaborator_id` column on existing tables ceases to exist. Application code, Form Requests, URLs, and API payloads use `provider_id`.
  - Soft-delete is authoritative for "deactivation": `$provider->delete()` makes a provider unbookable without losing history; `$provider->restore()` brings them back. The same applies to `business_members` (though no UI removes a staff member in R-1A).
  - The `businesses.allow_collaborator_choice` column is renamed `allow_provider_choice` to match the new vocabulary.

---

## 4. Naming Cascade (Canonical Reference)

| Kind | Old | New |
|---|---|---|
| Table | `business_user` | `business_members` |
| Table | `collaborator_service` | `provider_service` |
| Table | — | `providers` (new) |
| Column | `business_user.is_active` | removed; replaced by `business_members.deleted_at` |
| Column | `business_user.role = 'collaborator'` | `business_members.role = 'staff'` |
| Column | `bookings.collaborator_id` → FK users | `bookings.provider_id` → FK providers |
| Column | `availability_rules.collaborator_id` → FK users | `availability_rules.provider_id` → FK providers |
| Column | `availability_exceptions.collaborator_id` → FK users nullable | `availability_exceptions.provider_id` → FK providers nullable |
| Column | `businesses.allow_collaborator_choice` | `businesses.allow_provider_choice` |
| Enum | `App\Enums\BusinessUserRole` | `App\Enums\BusinessMemberRole` |
| Enum case | `BusinessUserRole::Collaborator` / `'collaborator'` | `BusinessMemberRole::Staff` / `'staff'` |
| Model | `App\Models\BusinessUser` (pivot) | `App\Models\BusinessMember` |
| Model | — | `App\Models\Provider` (new) |
| Relation | `Business::users()` | `Business::members()` |
| Relation | `Business::collaborators()` | `Business::staff()` (still a User-BelongsToMany, role-filtered) |
| Relation | `Business::admins()` | unchanged |
| Relation | — | `Business::providers()` (HasMany Provider, new) |
| Relation | `Service::collaborators()` | `Service::providers()` (BelongsToMany Provider via provider_service) |
| Relation | `User::businesses()` | unchanged, repointed to `BusinessMember` pivot |
| Relation | `User::services()` | removed (moves to `Provider::services()`) |
| Relation | `User::availabilityRules()` | removed (moves to `Provider::availabilityRules()`) |
| Relation | `User::availabilityExceptions()` | removed (moves to `Provider::availabilityExceptions()`) |
| Relation | `User::bookingsAsCollaborator()` | removed (replaced by `Provider::bookings()` and, if needed, `User::providers()`) |
| Relation | — | `User::providers()` (HasMany Provider, new) |
| Relation | `Booking::collaborator` | `Booking::provider` |
| Relation | `AvailabilityRule::collaborator` | `AvailabilityRule::provider` |
| Relation | `AvailabilityException::collaborator` | `AvailabilityException::provider` |
| Middleware | `role:collaborator` | `role:staff` |
| Controller | `Dashboard\Settings\CollaboratorController` | split into `Dashboard\Settings\StaffController` + `Dashboard\Settings\ProviderController` |
| Request | `StoreCollaboratorInvitationRequest` | `StoreStaffInvitationRequest` |
| Request | `UpdateCollaboratorScheduleRequest` | `UpdateProviderScheduleRequest` |
| Request | `StoreCollaboratorExceptionRequest` | `StoreProviderExceptionRequest` |
| Request | `UpdateCollaboratorExceptionRequest` | `UpdateProviderExceptionRequest` |
| Request field | `collaborator_id` (public + manual booking) | `provider_id` |
| Request field | `collaborator_ids` (service form) | `provider_ids` |
| Request field | `allow_collaborator_choice` | `allow_provider_choice` |
| Route | `/dashboard/settings/collaborators` | `/dashboard/settings/staff` |
| Route | `/dashboard/settings/collaborators/{user}` | `/dashboard/settings/staff/{user}` |
| Route | `/dashboard/settings/collaborators/{user}/toggle-active` | `/dashboard/settings/providers/{provider}/toggle` |
| Route | `/dashboard/settings/collaborators/{user}/schedule` | `/dashboard/settings/providers/{provider}/schedule` |
| Route | `/dashboard/settings/collaborators/{user}/exceptions/*` | `/dashboard/settings/providers/{provider}/exceptions/*` |
| Route | `/booking/{slug}/collaborators` | `/booking/{slug}/providers` |
| Route | `/booking/{slug}/slots?collaborator_id=...` | `/booking/{slug}/slots?provider_id=...` |
| Route name | `booking.collaborators` | `booking.providers` |
| Route name | `settings.collaborators.*` | `settings.staff.*` + `settings.providers.*` |
| Inertia shared | `auth.role = 'collaborator'` | `auth.role = 'staff'` |
| Inertia page path | `dashboard/settings/collaborators/index.tsx` | `dashboard/settings/staff/index.tsx` |
| Inertia page path | `dashboard/settings/collaborators/show.tsx` | `dashboard/settings/staff/show.tsx` |
| Frontend type | `CalendarCollaborator` | `CalendarProvider` |
| Frontend type | `Collaborator` (booking context) | `Provider` |
| Frontend component | `components/calendar/collaborator-filter.tsx` | `components/calendar/provider-filter.tsx` |
| Frontend component | `components/booking/collaborator-picker.tsx` | `components/booking/provider-picker.tsx` |
| Frontend component | `components/settings/collaborator-invite-dialog.tsx` | `components/settings/staff-invite-dialog.tsx` |
| Frontend util | `lib/calendar-colors.ts :: getCollaboratorColor` | `getProviderColor` (same file renamed or kept) |
| Wayfinder | `routes/settings/collaborators/*`, actions for `Dashboard\Settings\CollaboratorController` | regenerate to match new routes/controllers |
| Test file | `tests/Feature/Booking/CollaboratorsApiTest.php` | `ProvidersApiTest.php` |
| Test file | `tests/Feature/Settings/CollaboratorTest.php` | `StaffTest.php` + `ProviderTest.php` (split per controller split) |
| Test file | `tests/Feature/Services/CollaboratorAssignmentTest.php` | `ProviderAssignmentTest.php` |
| Translation keys | any string containing `collaborator` in `lang/*.json` | rewritten to `provider` / `staff` matching context |

No exceptions anywhere — every identifier above migrates. If the implementation surfaces a reference not in this table, the rule is: name it to match the new vocabulary.

---

## 5. Migration Plan

Nine ordered migrations. Each is a standalone atomic unit with up + down. Order matters because later migrations read from tables set up by earlier ones.

1. **`rename_business_user_table_to_business_members`**
   - `Schema::rename('business_user', 'business_members')`.
   - Update the `role` values in-place: `UPDATE business_members SET role = 'staff' WHERE role = 'collaborator'`.
   - Update `business_invitations.role` values the same way: `UPDATE business_invitations SET role = 'staff' WHERE role = 'collaborator'`.
   - **Does not drop `is_active` yet** — the providers backfill (step 3) reads it to decide `deleted_at`.

2. **`create_providers_table`**
   - `id`, `business_id` (FK businesses, cascadeOnDelete), `user_id` (FK users, nullable, cascadeOnDelete), timestamps, `softDeletes()`.
   - Unique composite index `(business_id, user_id, deleted_at)` — allows one active row plus any number of soft-deleted rows per (business, user).
   - Index on `business_id`.

3. **`backfill_providers_from_business_members`**
   - For every `business_members` row with `role = 'staff'`:
     - Insert a `providers` row with matching `business_id`, `user_id`, `created_at`, `updated_at`.
     - `deleted_at = NULL` when `is_active = true`; `deleted_at = updated_at` when `is_active = false`.
   - Admin rows are skipped (no provider is created for them — admin-as-provider opt-in lives in R-1B).
   - Uses chunked inserts for scale, but on MVP volumes a single pass is sufficient.
   - Add a safety assertion: post-insert, the count of `providers` equals the count of `business_members` rows where `role = 'staff'`.

4. **`add_deleted_at_and_drop_is_active_on_business_members`**
   - `$table->softDeletes()` — add `deleted_at` column. No backfill: the old `is_active` flag meant "provider deactivated", not "member removed from the team", so there is no semantically correct mapping to `business_members.deleted_at`. All members stay active.
   - Drop `is_active` column.

5. **`create_provider_service_table_and_migrate_collaborator_service`**
   - Create `provider_service` table: `id`, `provider_id` (FK providers, cascadeOnDelete), `service_id` (FK services, cascadeOnDelete), timestamps; unique `(provider_id, service_id)`.
   - Backfill: for each `collaborator_service` row, join `services` to determine the owning business, then resolve `providers.id` by `(providers.business_id = services.business_id, providers.user_id = collaborator_service.collaborator_id)` — including soft-deleted providers. Insert into `provider_service`, carrying over timestamps.
   - Drop `collaborator_service` table.
   - Safety assertion: row counts match (minus orphans — zero expected in current data).

6. **`repoint_bookings_collaborator_id_to_provider_id`**
   - Add `provider_id` BIGINT UNSIGNED nullable column (no FK yet — populated by backfill).
   - Backfill: `UPDATE bookings b JOIN providers p ON p.business_id = b.business_id AND p.user_id = b.collaborator_id SET b.provider_id = p.id` (expressed via query builder chunks on SQLite). Include soft-deleted providers in the lookup.
   - Add FK constraint `provider_id` → `providers.id` (`restrictOnDelete` — we do not cascade-delete bookings when a provider is hard-deleted; soft-delete is the only deactivation path).
   - Make `provider_id` NOT NULL.
   - Drop FK and `collaborator_id` column. Drop the old `(collaborator_id, starts_at)` index and add `(provider_id, starts_at)`.

7. **`repoint_availability_rules_collaborator_id_to_provider_id`**
   - Same shape as step 6. FK restrictOnDelete on `provider_id`. Replace `(collaborator_id, day_of_week)` index with `(provider_id, day_of_week)`.

8. **`repoint_availability_exceptions_collaborator_id_to_provider_id`**
   - Same shape; column stays nullable (business-level exceptions have `provider_id = NULL` — retains existing semantics of D-021).
   - Backfill: join on providers when `collaborator_id` is non-null; leave `provider_id = NULL` where `collaborator_id` is null. Include soft-deleted providers in the lookup.
   - FK on `provider_id` with `nullOnDelete` (a business-level exception keeps its row if the referenced provider were ever truly removed, but the restrict-on-hard-delete stance of step 6 prevents that in practice).
   - Replace `(collaborator_id, start_date, end_date)` index with `(provider_id, start_date, end_date)`.

9. **`rename_allow_collaborator_choice_on_businesses_to_allow_provider_choice`**
   - Rename column: `allow_collaborator_choice` → `allow_provider_choice`. Preserve existing boolean data.

All nine run in one `php artisan migrate`. Down-migrations invert each step, but hard rollback across data migrations is only safe with a database snapshot — documented as standard for structural migrations.

---

## 6. Model Changes

### New: `App\Models\Provider`

- `use SoftDeletes`.
- Fillable: `business_id`, `user_id`.
- Relations:
  - `business(): BelongsTo<Business>`
  - `user(): BelongsTo<User>`
  - `services(): BelongsToMany<Service>` via `provider_service`
  - `availabilityRules(): HasMany<AvailabilityRule>` (FK `provider_id`)
  - `availabilityExceptions(): HasMany<AvailabilityException>` (FK `provider_id`, nullable so `whereNotNull` scoping applies)
  - `bookings(): HasMany<Booking>` (FK `provider_id`)
- Accessor `displayName()`: returns `$this->user?->name ?? ''`. Callers use `$provider->displayName` or `$provider->user->name`. Avatar still lives on the user.

### Renamed: `App\Models\BusinessUser` → `App\Models\BusinessMember`

- `use SoftDeletes`.
- Fillable keeps `role`; loses `is_active`.
- Cast `role` to `BusinessMemberRole`.

### Renamed: `App\Enums\BusinessUserRole` → `App\Enums\BusinessMemberRole`

- Cases: `Admin = 'admin'`, `Staff = 'staff'`.

### Updated: `App\Models\Business`

- Drop `withPivot(['role', 'is_active'])`; use `withPivot(['role'])` on `members()`. Soft-delete on the pivot is handled by the pivot model's `SoftDeletes` trait.
- Rename `users()` → `members()`. `belongsToMany(User::class)->using(BusinessMember::class)->withPivot(['role'])->withTimestamps()`.
- Rename `collaborators()` → `staff()`. Remains a user-belongsToMany, filtered by `role = 'staff'`. This relation survives primarily for rare places where we need "users attached to this business in the staff role" (distinct from "providers"). Callers that meant "bookable person" must use `providers()`.
- `admins()` unchanged.
- **New** `providers(): HasMany<Provider>` — the canonical bookability relation. Soft-deleted providers are excluded by the default SoftDeletes scope.

### Updated: `App\Models\User`

- `businesses()` uses `BusinessMember` pivot.
- Remove `services()`, `availabilityRules()`, `availabilityExceptions()`, `bookingsAsCollaborator()`.
- **New** `providers(): HasMany<Provider>` — a user may have multiple provider records across businesses (post-MVP; in MVP, at most one per business).
- `hasBusinessRole()` and `currentBusinessRole()` continue to work; they query `BusinessMember` now.

### Updated: `App\Models\Service`

- Rename `collaborators()` → `providers()`. Relation: `belongsToMany(Provider::class, 'provider_service')->withTimestamps()`.

### Updated: `App\Models\Booking`

- Rename `collaborator()` → `provider()`. FK column `provider_id` → `Provider`.

### Updated: `App\Models\AvailabilityRule`, `App\Models\AvailabilityException`

- Rename `collaborator()` → `provider()`. FK `provider_id` → `Provider`.
- `AvailabilityException.provider_id` stays nullable.

### Updated: `App\Models\BusinessInvitation`

- Role cast stays `BusinessMemberRole`. Column data is migrated in step 1.

---

## 7. Service Layer Changes

### `App\Services\SlotGeneratorService`

- Method signature changes: replace `User $collaborator = null` parameters with `Provider $provider = null` in all public and private methods.
- `getEligibleCollaborators()` → `getEligibleProviders(Business $business, Service $service): Collection<int, Provider>`.
  - Query: `$service->providers()` filtered by the non-soft-deleted scope and `whereHas('business', ...)` restricting to the current `$business->id`. The default SoftDeletes scope already excludes deactivated providers — no explicit `is_active` filter.
- `assignCollaborator()` → `assignProvider()`. Same logic, returns a `Provider`.
- `leastBusyCollaborator()` → `leastBusyProvider()`. Counts bookings by `provider_id`.
- `getBlockingBookings()` still queries `bookings` scoped to a provider; change `where('collaborator_id', ...)` to `where('provider_id', ...)`.

### `App\Services\AvailabilityService`

- Any method accepting `User $collaborator` becomes `Provider $provider`.
- Queries for availability rules and exceptions move from `$user->availabilityRules()` to `$provider->availabilityRules()`.

### `App\DTOs\TimeWindow` and friends

- No data shape change; only parameter types in call sites.

---

## 8. Controller Changes

### Split: `Dashboard\Settings\CollaboratorController` → `StaffController` + `ProviderController`

**`Dashboard\Settings\StaffController`** owns team membership and invitations.

- `index(Request)` — list all `business_members` with eager-loaded provider (to show bookable badge) and aggregate `services_count` from the provider when present.
- `show(Request, User $user)` — detail page for a staff member. Eager-loads their `Provider` for this business (if any), their `availabilityRules` via provider, their `availabilityExceptions` for this business+provider, and the business's services with assignment state. Backing Inertia page: `dashboard/settings/staff/show.tsx`. In R-1A the admin case (no provider) renders a stripped detail page without schedule/services; R-1B fills that with the opt-in UI.
- `invite(StoreStaffInvitationRequest)` — unchanged logic beyond the rename.
- `resendInvitation`, `cancelInvitation` — unchanged beyond the rename.
- `uploadAvatar(Request, User $user)` — unchanged beyond the rename; still writes to `users.avatar`.
- `ensureUserBelongsToBusiness(User, Business)` helper — replaces the old `ensureCollaboratorBelongsToBusiness` and accepts any `business_members` row regardless of role.

**`Dashboard\Settings\ProviderController`** owns provider-specific CRUD.

- `toggle(Request, Provider $provider)` — flips `deleted_at`. If currently null, calls `$provider->delete()`; otherwise `$provider->restore()`. Replaces the old `toggleActive`. Implicit scoping via route model binding scoped to the current business is enforced by an `ensureProviderBelongsToBusiness` helper.
- `updateSchedule(UpdateProviderScheduleRequest, Provider $provider)` — delete + bulk-insert `availability_rules` under `provider_id = $provider->id`, `business_id = $business->id`.
- `storeException`, `updateException`, `destroyException` — scoped to `provider_id = $provider->id`.
- `syncServices(UpdateProviderServicesRequest, Provider $provider)` — `$provider->services()->sync($validated['service_ids'])` after validating the service ids belong to the business.

### `App\Http\Controllers\Auth\InvitationController::accept()`

- Transaction-wrapped:
  1. Create `User`.
  2. Mark email verified (unchanged).
  3. Attach to business via `business_members` with `role = staff`.
  4. Create a `providers` row for the new user in this business.
  5. Attach services from `service_ids` via `provider_service` using the new `provider->id`.
  6. Mark invitation accepted.
  7. `Auth::login($user)`.

### `App\Http\Controllers\Auth\RegisterController::store()`

- Create admin with `role = admin` only. No provider row. (R-1B handles opt-in.) This is already the case; only the enum identifier changes.

### `App\Http\Controllers\OnboardingController`

- Step-4 invitations use `BusinessMemberRole::Staff`.
- No other changes in R-1A.

### `App\Http\Controllers\Booking\PublicBookingController`

- `collaborators()` → `providers()`. Returns providers (non-soft-deleted) attached to the requested service. Response field is an array of `{ id: providerId, name: userName, avatar_url }`.
- `availableDates()` and `slots()` — accept `provider_id` query param instead of `collaborator_id`. Resolve to a `Provider` model. Pass the provider to the slot engine.
- `store()` — `collaborator_id` field on the Form Request becomes `provider_id`. Resolution: `$service->providers()->where('providers.id', $validated['provider_id'])->first()`. Auto-assignment uses `SlotGeneratorService::assignProvider()`. The created `Booking` stores `provider_id`.
- `notifyStaff()` — notifies admins and the provider's user. Method is renamed; `$booking->provider->user` is the person receiving the collaborator-side notification.

### `App\Http\Controllers\Dashboard\BookingController` (manual booking)

- Dropdown query: `$business->providers()->with('user:id,name')->get()`. UI still shows names; underlying id is `provider_id`.
- `store()` — `collaborator_id` → `provider_id`. Same resolution pattern as the public controller.

### `App\Http\Controllers\Dashboard\CalendarController`

- Collaborator filter list → provider filter list. Props renamed from `collaborators` to `providers` (keeping backward compatibility is not a goal — clean rename).
- Bookings eager-load `provider.user` instead of `collaborator`. The calendar event payload still carries `{ id, name, avatar_url }` under a `provider` key.

### `App\Http\Controllers\Dashboard\Settings\ServiceController`

- `create()` and `edit()` collaborator dropdowns → provider dropdowns. Query: `$business->providers()->with('user:id,name')->get()`.
- `store()` and `update()` — `collaborator_ids` → `provider_ids`. `$service->providers()->sync($providerIds)`.

### `App\Http\Controllers\Dashboard\Settings\EmbedController`

- No functional change; any references to collaborator choice in view text go via `allow_provider_choice`.

### `App\Http\Controllers\Dashboard\Settings\BookingSettingsController`

- `allow_collaborator_choice` → `allow_provider_choice` in request/update/response mapping.

### `App\Http\Controllers\Booking\BookingManagementController`

- References `$booking->provider` instead of `$booking->collaborator`.

### `App\Http\Controllers\Customer\BookingController`

- Same: `$booking->provider->user->name` when rendering.

---

## 9. Form Request Changes

- `StorePublicBookingRequest`: `collaborator_id` → `provider_id` (`nullable`, `exists:providers,id`). Additional rule: provider must belong to the current business (use a closure rule that looks up `Provider::where('id', $value)->where('business_id', $business->id)->exists()`).
- `StoreManualBookingRequest`: `collaborator_id` → `provider_id` with the same tenant-scoped rule.
- `StoreSettingsServiceRequest` / `UpdateSettingsServiceRequest`: `collaborator_ids` → `provider_ids` with `exists:providers,id` plus the same scoping closure.
- `UpdateBookingSettingsRequest`: `allow_collaborator_choice` → `allow_provider_choice`.
- Existing `StoreCollaboratorInvitationRequest` renamed to `StoreStaffInvitationRequest`.
- Existing `UpdateCollaboratorScheduleRequest` renamed to `UpdateProviderScheduleRequest`.
- Existing `StoreCollaboratorExceptionRequest` / `UpdateCollaboratorExceptionRequest` renamed to `StoreProviderExceptionRequest` / `UpdateProviderExceptionRequest`.
- New `UpdateProviderServicesRequest`.

(R-3 "cross-tenant validation" adds stricter tenant-scoped rules project-wide. R-1A introduces the pattern in the renamed requests; R-3 rolls it out everywhere.)

---

## 10. Routes

- Replace `/dashboard/settings/collaborators/*` subtree with `/dashboard/settings/staff/*` + `/dashboard/settings/providers/*`. Route names moved to `settings.staff.*` / `settings.providers.*`.
- Replace `/booking/{slug}/collaborators` with `/booking/{slug}/providers`. Route name `booking.providers`.
- `slot` and `available-dates` public endpoints keep their paths; only the query parameter name changes (`collaborator_id` → `provider_id`).
- Route-model-binding: bind `{provider}` to `Provider` (scoped by current business in the controller helper).

Regenerate Wayfinder actions after the route rename so the frontend action imports resolve.

---

## 11. Frontend Changes

### Renamed / moved files

- `resources/js/components/calendar/collaborator-filter.tsx` → `components/calendar/provider-filter.tsx`.
- `resources/js/components/booking/collaborator-picker.tsx` → `components/booking/provider-picker.tsx`.
- `resources/js/components/settings/collaborator-invite-dialog.tsx` → `components/settings/staff-invite-dialog.tsx`.
- `resources/js/pages/dashboard/settings/collaborators/index.tsx` → `dashboard/settings/staff/index.tsx`.
- `resources/js/pages/dashboard/settings/collaborators/show.tsx` → `dashboard/settings/staff/show.tsx` (keeps the combined identity + provider panel; the admin-no-provider case shows the identity card only in R-1A).

### Type renames in `resources/js/types/index.d.ts`

- `CalendarCollaborator` → `CalendarProvider`.
- `Collaborator` (booking context) → `Provider` — shape: `{ id: number; name: string; avatar_url: string | null }`. `id` now refers to `providers.id`, not `users.id`.
- Anywhere a shared type used `collaborator` / `collaborators`, rename the field (not just the type alias — the JSON payload shape changes).
- Role union in `PageProps['auth']['role']` becomes `'admin' | 'staff' | 'customer' | null`.

### Component renames and prop renames

Every page and component that currently destructures `collaborators`, `collaborator`, `collaborator_id`, `collaborator_ids` re-exports the concept as `providers`, `provider`, `provider_id`, `provider_ids`. This includes calendar views, booking page steps, dashboard bookings table, manual booking dialog, customer-show page, welcome page, onboarding step 4, service-form, settings booking page, settings services create/edit, settings staff pages, and booking management page.

### Utility renames

- `lib/calendar-colors.ts`: `getCollaboratorColor` → `getProviderColor`, `getCollaboratorColorMap` → `getProviderColorMap`. The palette stays.

### Wayfinder-generated files

- `resources/js/actions/App/Http/Controllers/Dashboard/Settings/CollaboratorController.ts` is deleted on regeneration; replaced by `StaffController.ts` + `ProviderController.ts`.
- `resources/js/routes/settings/collaborators/index.ts` removed; `resources/js/routes/settings/staff/index.ts` and `resources/js/routes/settings/providers/index.ts` generated.
- `resources/js/actions/App/Http/Controllers/Booking/PublicBookingController.ts` regenerated with `providers()` action instead of `collaborators()`.

### i18n

- Any key in `lang/en.json` (and any other locale file) containing "collaborator" is renamed to the appropriate new word in both key and value. Customer-facing copy prefers "provider" where semantics match; internal team UI prefers "staff" or "team member".

---

## 12. Factories, Seeders, Enums

- `database/factories/BusinessInvitationFactory.php`: default role `BusinessMemberRole::Staff`.
- `database/seeders/BusinessSeeder.php`: `$business->users()->attach(..., ['role' => BusinessMemberRole::Staff])` becomes `$business->members()->attach(...)`, plus a `$business->providers()->create(['user_id' => $user->id])` for each staff member, plus `$provider->services()->sync(...)` for their assignments. Booking seeds reference `provider_id` and look up the provider by (business_id, user_id).
- New `database/factories/ProviderFactory.php`: default attributes `business_id => Business::factory()`, `user_id => User::factory()`; state `::softDeleted()` for soft-deleted fixtures.
- Rename `BusinessUserRole` imports project-wide.
- `BookingFactory`, `AvailabilityRuleFactory`, `AvailabilityExceptionFactory`: default `provider_id` to `Provider::factory()` instead of `collaborator_id => User::factory()`.

---

## 13. Test Updates

All 379 existing tests must still pass. Expected categories of change:

1. **Rename-only updates**: occurrences of `collaborator` / `Collaborator` in test code renamed per the cascade table. Most tests only need identifier renames.
2. **Fixture shape changes**: any test that did `$business->users()->attach($user, ['role' => 'collaborator', 'is_active' => true])` becomes `$business->members()->attach($user, ['role' => 'staff']); Provider::create([...])`. A small test helper — `attachProvider(Business $business, User $user, bool $active = true): Provider` — is added under `tests/Support/` (or reused from Pest helpers) to keep fixture blocks readable.
3. **Booking fixture FK**: tests that build bookings with `collaborator_id => $user->id` switch to `provider_id => $provider->id`.
4. **API payload shape**: public booking tests and manual booking tests that POST `collaborator_id` switch to `provider_id`; response expectations for `/booking/{slug}/providers` update.
5. **Test file renames** per the cascade table.
6. **Relation tests**: `tests/Feature/Models/UserRelationshipTest.php` updates to assert User's new provider-facing relations and the removal of the old user-centric ones.

No new test coverage is added in R-1A (that is R-1B). Every passing test before the refactor passes after.

---

## 14. Files Created / Modified / Deleted

### Created

- 9 migration files under `database/migrations/` (per §5).
- `app/Models/Provider.php`.
- `app/Http/Controllers/Dashboard/Settings/StaffController.php` (carries over the team-management half of the old controller).
- `app/Http/Controllers/Dashboard/Settings/ProviderController.php` (carries over the schedule/service/exception half).
- `app/Http/Requests/Dashboard/Settings/StoreStaffInvitationRequest.php` (renamed from collaborator variant).
- `app/Http/Requests/Dashboard/Settings/UpdateProviderScheduleRequest.php`.
- `app/Http/Requests/Dashboard/Settings/StoreProviderExceptionRequest.php`.
- `app/Http/Requests/Dashboard/Settings/UpdateProviderExceptionRequest.php`.
- `app/Http/Requests/Dashboard/Settings/UpdateProviderServicesRequest.php`.
- `database/factories/ProviderFactory.php`.
- `resources/js/components/calendar/provider-filter.tsx` (renamed).
- `resources/js/components/booking/provider-picker.tsx` (renamed).
- `resources/js/components/settings/staff-invite-dialog.tsx` (renamed).
- `resources/js/pages/dashboard/settings/staff/index.tsx` (renamed path).
- `resources/js/pages/dashboard/settings/staff/show.tsx` (renamed path).

### Deleted

- `app/Models/BusinessUser.php` (replaced by `BusinessMember.php`).
- `app/Enums/BusinessUserRole.php` (replaced by `BusinessMemberRole.php`).
- `app/Http/Controllers/Dashboard/Settings/CollaboratorController.php` (split).
- `app/Http/Requests/Dashboard/Settings/StoreCollaboratorInvitationRequest.php` (renamed).
- `app/Http/Requests/Dashboard/Settings/UpdateCollaboratorScheduleRequest.php` (renamed).
- `app/Http/Requests/Dashboard/Settings/StoreCollaboratorExceptionRequest.php` (renamed).
- `app/Http/Requests/Dashboard/Settings/UpdateCollaboratorExceptionRequest.php` (renamed).
- `resources/js/components/calendar/collaborator-filter.tsx`.
- `resources/js/components/booking/collaborator-picker.tsx`.
- `resources/js/components/settings/collaborator-invite-dialog.tsx`.
- `resources/js/pages/dashboard/settings/collaborators/` (entire subtree).
- `resources/js/actions/App/Http/Controllers/Dashboard/Settings/CollaboratorController.ts` (Wayfinder regenerates fresh files).
- `resources/js/routes/settings/collaborators/` (Wayfinder regenerates).
- Test files renamed per the cascade table — old files deleted.

### Modified

- `app/Models/Business.php`, `User.php`, `Booking.php`, `Service.php`, `AvailabilityRule.php`, `AvailabilityException.php`, `BusinessInvitation.php`.
- `app/Services/SlotGeneratorService.php`, `AvailabilityService.php`.
- Every controller that referenced collaborator identifiers (§8).
- Every Form Request listed in §9.
- `app/Http/Middleware/EnsureUserHasRole.php` — string `'collaborator'` → `'staff'`.
- `app/Http/Middleware/HandleInertiaRequests.php` — role value resolution.
- `routes/web.php` — full rewrite of the collaborators subtree.
- `database/seeders/BusinessSeeder.php`, `DatabaseSeeder.php`.
- `database/factories/BookingFactory.php`, `AvailabilityRuleFactory.php`, `AvailabilityExceptionFactory.php`, `BusinessInvitationFactory.php`.
- `app/Notifications/*` — any booking notification that reads `$booking->collaborator` reads `$booking->provider->user` (or the `displayName` accessor).
- `app/Console/Commands/*` (reminders, auto-complete) if they reference collaborator identifiers.
- `resources/js/types/index.d.ts`, every page and component listed in §11.
- `lang/en.json` (and other locale files) — key rewrites.

---

## 15. Verification

- `php artisan migrate:fresh --seed` succeeds on SQLite; resulting schema matches the post-migration shape.
- `php artisan test --compact` — all 379 existing tests pass after the rename churn. (Count may shift by a handful due to test-file splits; the net is ≥ green.)
- `vendor/bin/pint --dirty --format agent` — clean.
- `npm run build` — clean.
- Laravel Boost `database-schema` inspection on `providers`, `provider_service`, and `business_members` — shape matches §5.
- Manual smoke via `php artisan tinker --execute 'App\Models\Business::first()->providers;'` returns providers with active-only scope by default.

---

## 16. Risks and Mitigations

- **Risk**: Large rename PR causes reviewer fatigue; something gets missed.
  - **Mitigation**: The cascade table in §4 is the rename contract. A final `grep -rE 'collaborator|Collaborator'` over `app/`, `resources/`, `database/`, `tests/`, `routes/`, `lang/` must return zero matches at the end of the session. Any lingering occurrence is in scope for this session.
- **Risk**: Data loss on migration rollback.
  - **Mitigation**: Expected and documented. No one is depending on clean down-migrations for these structural changes; a rollback requires a database snapshot. The dev environment uses `migrate:fresh --seed` liberally.
- **Risk**: Booking history rendered incorrectly after rename because a template accesses `$booking->collaborator`.
  - **Mitigation**: The model method is removed cleanly; any missed call site raises `BadMethodCallException` in dev. Notification views, mail templates, and frontend page prop handlers are explicitly listed in §11.
- **Risk**: SQLite backfill in migrations is slow or hits driver edge cases.
  - **Mitigation**: Chunked inserts, `DB::transaction()` wrappers per migration, plus a small self-check at the tail of each backfill migration that asserts row counts.
- **Risk**: The public booking API contract changes (`/collaborators` → `/providers`, `collaborator_id` → `provider_id`) break any external integration.
  - **Mitigation**: None needed — pre-launch, no external consumers exist. The change is documented here; future public-API versioning is a separate concern.
- **Risk**: Wayfinder regeneration leaves stale action imports on the frontend.
  - **Mitigation**: Run `php artisan wayfinder:generate` after route changes; commit the regenerated files.
- **Risk**: Tests that attach admin without an `is_active` now find no such column and fail.
  - **Mitigation**: The migration drops `is_active`. Every test that referenced it is updated as part of this session.
- **Risk**: Multiple active providers per (business, user) bypass the unique constraint because one has `deleted_at = NULL` and another has a distinct non-NULL `deleted_at`.
  - **Mitigation**: That is the intended behavior — one active row, history preserved across re-adds. The unique `(business_id, user_id, deleted_at)` tuple enforces "no two active rows for the same (business, user)".

---

## 17. Handoff to R-1B

After R-1A lands:

- The solo-business issue from REVIEW-1 is **not yet fixed** — admins still have no provider row by default. But the model can now express the fix cleanly.
- R-1B adds: onboarding step-3 opt-in, Settings → Account page with the toggle, step-5 launch gate, public-page service filtering, and the end-to-end test the review demands. All on top of a clean foundation.

Stop after plan approval — no implementation before then.
