---
name: PLAN-R-1B-ADMIN-AS-PROVIDER
description: "R-1B: admin as provider (admin can be their own first Provider row)"
type: plan
status: shipped
created: 2026-04-16
updated: 2026-04-16
---

# Plan — R-1B: Admin as Provider

**Source**: `docs/reviews/ROADMAP-REVIEW.md` section R-1 / `docs/reviews/REVIEW-1.md` issue #1.
**Prerequisite**: R-1A must be merged and green.
**Status**: Draft — awaiting approval.

---

## 1. Context

R-1A fixed the data model: `providers` is a first-class entity, `business_members.role` describes dashboard permission only, soft-delete replaces the `is_active` flag, and every eligibility query in the slot engine, public booking, manual booking, and service assignment reads from `Business::providers()`.

What R-1A deliberately did *not* do: fix the product bug the review called out. A solo owner still completes registration with a `business_members(admin)` row and *no* `providers` row. Onboarding still does not surface the question "are you also the person taking bookings?". Settings has no page for the admin to turn that on later. Launch still succeeds with zero providers behind active services.

R-1B delivers that feature on top of the refactored model. Because the model is now correct, the feature is a UX build rather than a schema rescue.

---

## 2. Goal and Scope

### Goal

An admin can become a provider. The option surfaces during onboarding (opt-in when creating the first service) and in Settings (self-service toggle). Launch cannot complete with services that have no provider behind them. The public booking page never advertises a service with zero providers.

### In Scope

- Onboarding **step 3** extended with a provider opt-in: a toggle and, when enabled, a collapsible `WeekScheduleEditor` pre-filled from the step-2 business hours. On submit, create the admin's `providers` row, write their availability rules, and attach them to the service being created/edited — all in one request.
- A new **`Settings → Account`** page (`/dashboard/settings/account`, admin-only) that owns the admin's provider configuration: toggle (create / soft-delete the provider row), weekly schedule, exceptions, own service assignments. Reuses existing schedule-editor and exception UI components.
- **`Settings → Staff`** page updated to display admins who are also providers, with a role badge and provider-status chip. Admins with `is_provider = false` still appear in the staff list with just a role badge.
- **Launch gate** at onboarding step 5: block `storeLaunch()` if any active service has zero eligible providers. Response surfaces offending service names and a one-click "Be your own first provider" recovery CTA.
- **Public booking page defense-in-depth**: `PublicBookingController::show()` filters out services with zero providers so a post-launch deactivation does not leave a dead service on the page.
- **End-to-end test** the review requires: freshly registered → email verified → onboarding 1-5 with provider opt-in → public POST → booking created with the owner as the provider.
- One new architectural decision (D-062), recorded during the implementation session.

### Out of Scope

- Any data-model change (R-1A did it).
- Any rename of `collaborator` / `is_active` / `allow_collaborator_choice` (R-1A did it).
- Collaborator self-service for editing their own schedule (R-1A left the admin-only settings pattern intact; this plan does not change it).
- Review items R-2 through R-16.

### Deliberate UX decisions

- **Opt-in placement**: step **3**, not step 2. Step 2 stays focused on business hours. Step 3 is where the owner decides "there is a service someone performs" — the natural place to decide "and that someone may be me". One continuous flow, zero step-count increase.
- **Default schedule for the owner**: mirror the business hours saved in step 2. A "Match business hours" button resets the editor. Common case is zero manual input.
- **Settings surface**: a dedicated `Settings → Account` page, not a section inside `Settings → Profile` (which is business-level) and not the admin's entry in `Settings → Staff` (which is shared team management). The Account page is the admin's personal command-center and the natural home for future self-service (notification prefs, personal language, etc.).
- **Toggling off with existing bookings**: allowed; existing bookings remain valid (they reference the provider row, which soft-deletes — history intact). A warning banner surfaces if there are upcoming bookings for this provider when the admin toggles off.
- **Launch gate UX**: block with actionable copy + one-click recovery. Not "silent hide services" at launch — the admin needs to see the problem once; we do not hide symptoms during first-run.

---

## 3. New Architectural Decisions

### D-062 — Launch requires at least one eligible provider per active service

- **File**: `docs/decisions/DECISIONS-DASHBOARD-SETTINGS.md`
- **Status (proposed)**: accepted
- **Context**: Onboarding can complete with active services and zero providers, producing a public page that advertises services that generate no slots. The review elevated this to critical.
- **Decision**:
  - `OnboardingController::storeLaunch()` validates that every `Service` with `is_active = true` has at least one active provider via `provider_service` for the business. Active provider means a `providers` row with `deleted_at IS NULL` for this business.
  - On failure, the launch is aborted with a 422-style flow: redirect back to step 3, surfacing the offending service names. The step-3 page renders a banner with two CTAs: "Be your own first provider" (one-click) and "Invite a provider" (jumps to step 4).
  - `PublicBookingController::show()` additionally filters services to those with at least one active provider — defense-in-depth for post-launch deactivations. A service that loses its last provider disappears from the public page until a provider is re-attached.
- **Consequences**: Onboarding cannot produce a broken public page. Post-launch, the public page self-heals when providers change without requiring a re-launch step. Admins still own the "I want to remove a provider" action; the system never silently deletes or re-assigns.

---

## 4. Implementation Plan

### Step 1 — Onboarding Step 3: provider opt-in

Backend changes in `App\Http\Controllers\OnboardingController`:

- `showService(Business)` now also returns:
  - `adminProvider`: the admin's existing `providers` row for this business as `{ exists: bool, schedule: DaySchedule[], serviceIds: number[] }`. When absent, `exists = false` and `schedule` defaults to the shape derived from `business_hours` (one window per open day). When present, `schedule` reflects the admin provider's `availability_rules`.
  - `hasOtherProviders`: whether any non-soft-deleted `providers` row exists for this business whose `user_id` is not the current admin. Used by the frontend to tune copy ("Are you also taking bookings yourself?" vs "Take bookings yourself too?").
- `storeService(StoreServiceRequest)` extended to accept optional fields:
  - `provider_opt_in: bool`.
  - `provider_schedule: array<DaySchedule>` — same shape as `StoreHoursRequest`, required when `provider_opt_in = true`.
- Processing inside `storeService()` wraps in a transaction:
  1. Upsert the service as today.
  2. If `provider_opt_in = true`:
     - Find or soft-undelete the admin's `providers` row for this business. Use `Provider::withTrashed()` + `restore()` if a previously-soft-deleted row exists; otherwise create a new one.
     - Delete + bulk-insert `availability_rules` scoped to `provider_id = admin_provider.id` (same writer shape as `ProviderController::updateSchedule`).
     - Attach the admin's provider to the current service via `provider_service` (idempotent `syncWithoutDetaching`).
  3. If `provider_opt_in = false`:
     - If the admin has an existing, non-soft-deleted `providers` row: leave it alone (the admin may already be a provider via other services; the step-3 toggle is about *this service*, not a global demote). The UI makes this explicit: "This service" granularity.
     - If the admin had been a provider for *this* service only, detach from this service's `provider_service`. A helper warns the admin before detaching if this leaves the service with zero providers — the backend never silently demotes; the UI prompts.

Form Request: extend `StoreServiceRequest` with the two new fields. The `provider_schedule` rules mirror `UpdateProviderScheduleRequest` exactly.

Frontend `resources/js/pages/onboarding/step-3.tsx`:

- Add a switch labeled "I take bookings for this service myself" below the service fields.
- When the switch is on, render a `WeekScheduleEditor` (existing component) inside a bordered section. Default value: `adminProvider.schedule` from props.
- A secondary button inside the section: "Match business hours" → reset the editor to the step-2 business hours shape (pulled from Inertia props already present on step 3, or loaded afresh).
- A brief helper line: "You can change this later in Settings → Account."
- On submit, POST the existing service fields plus `provider_opt_in` and `provider_schedule`. No new HTTP endpoint — reuses the existing step-3 store.

Tests (under `tests/Feature/Onboarding/Step3ServiceTest.php`):

- Opt-in true + valid schedule → provider row created for admin, availability rules persisted, admin attached to the service via `provider_service`.
- Opt-in true + schedule invalid → 422, no provider row created.
- Opt-in false and no prior provider → no provider row. Admin remains non-provider.
- Opt-in false when admin already has a provider with attachments to other services → admin's provider row untouched, only this service's attachment is handled per the rules above.
- Re-running step 3 with opt-in off after opt-in on → detaches from this service but preserves the admin's provider row if they serve other services.

### Step 2 — Settings → Account page

Backend: new `App\Http\Controllers\Dashboard\Settings\AccountController`:

- `edit(Request)`:
  - Resolves the admin's `providers` row for the current business (including soft-deleted via `withTrashed()`); computes `isProvider = provider && !provider->trashed()`.
  - Builds the schedule shape from the provider's `availability_rules` if the provider exists (including if soft-deleted, so re-enabling can preserve their last schedule). Otherwise defaults from business hours.
  - Builds the exceptions list scoped to this provider (empty when no provider row).
  - Builds the services list (`business.services` with `is_active = true`) and marks which ones the admin's provider is attached to.
  - Renders `dashboard/settings/account.tsx`.
- `toggleProvider(Request)`:
  - If the admin has no `providers` row: create one, default-attach to all `is_active` services (same as the "Be your own first provider" helper — see step 4).
  - If the admin has a non-soft-deleted row: `$provider->delete()`. Respond with a warning flash if this leaves any active service with zero providers, and a second warning if upcoming bookings are attached.
  - If the admin has a soft-deleted row: `$provider->restore()`.
  - Redirect back to `settings.account` with appropriate flash.
- `updateSchedule(UpdateProviderScheduleRequest)` — writes `availability_rules` under the admin's `provider_id`. Errors if the admin has no provider.
- `storeException` / `updateException` / `destroyException` — scoped to the admin's provider. Reuse the provider request classes from R-1A.
- `updateServices(UpdateProviderServicesRequest)` — syncs `provider_service` for the admin's provider. Validates service ids belong to the current business.

Routes, under the existing `role:admin` settings prefix:

- `GET  /dashboard/settings/account` → `AccountController@edit` (name: `settings.account`).
- `POST /dashboard/settings/account/toggle-provider` → `AccountController@toggleProvider` (name: `settings.account.toggle-provider`).
- `PUT  /dashboard/settings/account/schedule` → `AccountController@updateSchedule`.
- `POST /dashboard/settings/account/exceptions` → `AccountController@storeException`.
- `PUT  /dashboard/settings/account/exceptions/{exception}` → `AccountController@updateException`.
- `DELETE /dashboard/settings/account/exceptions/{exception}` → `AccountController@destroyException`.
- `PUT  /dashboard/settings/account/services` → `AccountController@updateServices`.

Frontend:

- New `resources/js/pages/dashboard/settings/account.tsx`.
  - Top card: current user identity (name, email, avatar) — read-only in R-1B (editing delegated to a future "profile" enhancement; not part of this plan).
  - "Bookable provider" card: toggle (submits to `settings.account.toggle-provider`). Below the toggle, three sub-cards:
    - Weekly schedule (`WeekScheduleEditor`, submits to `settings.account.schedule`).
    - Exceptions list + create dialog (same components used by `StaffController@show` for other staff; submits to `settings.account.exceptions.*`).
    - Services (`CheckboxList` over active services; submits to `settings.account.services`).
  - Sub-cards are rendered but disabled with a muted overlay + CTA when `isProvider = false`. Toggling on POSTs and then the page re-renders with sub-cards enabled.
- Add "Account" to the settings sub-nav (`resources/js/components/settings/settings-nav.tsx`).

Tests (new `tests/Feature/Settings/AccountTest.php`):

- Admin can GET `settings.account` and sees `isProvider`, schedule, exceptions, services props.
- Collaborator (role=staff) GET returns 403 (covered by admin-only middleware; this is a regression check).
- Customer GET returns 403.
- Toggle from off to on → provider row created, default-attached to all active services.
- Toggle from on to off → provider row soft-deleted. Flash warning when leaving a service with zero providers.
- Toggle from off (but soft-deleted row exists) to on → provider restored; attachments preserved.
- `updateSchedule` writes the schedule.
- `storeException` / `destroyException` / `updateException` — happy paths + tenant scoping (providing another business's exception id returns 404/403).
- `updateServices` — syncs the attachment; providing a service id belonging to another business returns 422.
- Attempting any mutation while `isProvider = false` (e.g., `updateSchedule`) returns a 409-style error with copy pointing the user at the toggle.

### Step 3 — Settings → Staff page shows admin providers

Backend: update `StaffController::index` to include all business members (admins and staff). The existing response already lists staff; extend it to also include admin members. The front-end receives each member with:

- `role`: `'admin' | 'staff'`
- `is_provider`: `bool` (true when the member has a non-soft-deleted providers row).
- `services_count`: number of services attached via their provider row (zero when not a provider).

Frontend: `dashboard/settings/staff/index.tsx` adds a role badge column and a provider-status chip (Provider / Not bookable). Admin rows are rendered with a "You" indicator on the current user. The existing "deactivate" action on a row maps to the provider's toggle (if the member is a provider); rows that are admin-and-not-a-provider show a disabled chip with a link to their Account page.

Tests in `tests/Feature/Settings/StaffTest.php`:

- The index includes admins.
- The provider flag is correct for each row.

### Step 4 — Launch gate at step 5

Backend: extend `OnboardingController::storeLaunch()`:

- Before setting `onboarding_completed_at`, query active services joined through `provider_service` to `providers` (non-soft-deleted) for this business. Any active service with zero matching provider rows is an "unstaffed service".
- If the unstaffed-services list is non-empty:
  - Do not mark onboarded.
  - Redirect back to step 3 with session data: `launchBlocked = { services: [{ id, name }, ...] }`.
- New endpoint `POST /onboarding/enable-owner-as-provider` (method `OnboardingController::enableOwnerAsProvider()`):
  - Creates or restores the admin's `providers` row for this business.
  - Writes a default schedule mirroring `business_hours` if none exists (idempotent).
  - Attaches the admin's provider to every `is_active` service via `syncWithoutDetaching`.
  - Redirects to step 5 (`onboarding.show` step 5) with a success flash.

Frontend: extend `resources/js/pages/onboarding/step-3.tsx`:

- When `launchBlocked` is present in Inertia props, render a red banner above the service form listing the offending services and two CTAs:
  - "Be your own first provider" — POSTs to `onboarding.enable-owner-as-provider` and, on success, redirects to step 5.
  - "Invite a provider instead" — links to step 4.

Tests in `tests/Feature/Onboarding/Step5LaunchTest.php`:

- Launch with active service + zero providers → redirect back to step 3 with `launchBlocked` session data. `onboarding_completed_at` still null.
- Launch with active service + one provider → success (existing behavior).
- `enableOwnerAsProvider` endpoint creates/restores the provider, writes schedule, attaches to every active service. Admin can then launch successfully.

### Step 5 — Public booking page service filter

Backend: `PublicBookingController::show()` filters services to those that have at least one non-soft-deleted provider attached. Implementation: the existing `withCount('collaborators')` (which after R-1A is `withCount('providers')`) is replaced by a `whereHas('providers')` constraint on the services query. The `services` prop excludes any service with zero providers.

Tests in `tests/Feature/Booking/PublicBookingPageTest.php`:

- Service with one provider is visible on the public page.
- Service with zero providers is hidden.
- When a provider is soft-deleted and was the only one, the service hides without re-running onboarding.

### Step 6 — End-to-end solo booking test

New `tests/Feature/Onboarding/SoloBusinessBookingE2ETest.php`:

1. Register a new business via `POST /register`.
2. Mark email verified (direct on the user model, as existing onboarding tests do).
3. Walk steps 1 (profile), 2 (business hours), 3 (service + provider opt-in with a schedule), skip step 4, launch step 5.
4. Assert `onboarding_completed_at` is set and a `providers` row exists for the admin with `availability_rules` and a `provider_service` attachment.
5. `POST /booking/{slug}/book` with valid customer data, selecting the admin's provider and a slot inside the admin's schedule.
6. Assert:
   - Response 201.
   - `Booking::count() === 1`.
   - `Booking::first()->provider_id === $adminProvider->id`.
   - Notification sent to admin (assert via `Notification::fake`).

### Step 7 — Copy and docs

- User-facing strings introduced in this session go through `useTrans` / `__()`.
- New decision entries D-062 (this plan) land in `docs/decisions/DECISIONS-DASHBOARD-SETTINGS.md` during implementation.
- `docs/HANDOFF.md` is rewritten at end of session per the project workflow.
- The completed plan file moves to `docs/archive/plans/` at session close.

---

## 5. Files Created / Modified

### Created

- `app/Http/Controllers/Dashboard/Settings/AccountController.php`.
- `app/Http/Requests/Onboarding/StoreServiceRequest.php` — modified (see below); if request class extension is preferred, a new `StoreServiceAndProviderRequest` could be introduced, but reusing the existing request keeps the plan minimal.
- `resources/js/pages/dashboard/settings/account.tsx`.
- `tests/Feature/Settings/AccountTest.php`.
- `tests/Feature/Onboarding/SoloBusinessBookingE2ETest.php`.

### Modified

- `app/Http/Controllers/OnboardingController.php` — `showService()`, `storeService()`, new `enableOwnerAsProvider()`, `storeLaunch()` gate.
- `app/Http/Requests/Onboarding/StoreServiceRequest.php` — add `provider_opt_in`, `provider_schedule` rules.
- `app/Http/Controllers/Dashboard/Settings/StaffController.php` — index includes admins with provider status.
- `app/Http/Controllers/Booking/PublicBookingController.php` — `show()` service filter.
- `routes/web.php` — new Account routes, new onboarding "enable-owner-as-provider" route.
- `resources/js/pages/onboarding/step-3.tsx` — opt-in toggle, schedule editor, launch-blocked banner + CTAs.
- `resources/js/pages/onboarding/step-5.tsx` — no structural change; Step 5 just redirects back on gate failure, the UI work lives in step 3.
- `resources/js/pages/dashboard/settings/staff/index.tsx` — role badges and provider status.
- `resources/js/components/settings/settings-nav.tsx` — Account entry.
- `resources/js/layouts/settings-layout.tsx` — include Account in sub-nav.
- `docs/decisions/DECISIONS-DASHBOARD-SETTINGS.md` — append D-062 (at end of implementation).

---

## 6. Testing Plan

Priority order:

1. **E2E solo-onboarding → booking** — the test the review demands.
2. **Launch gate** — blocks correctly, unblocks via CTA, allows launch after fix.
3. **Onboarding step-3 opt-in** — matrix of on/off × existing-provider / no-provider.
4. **Account page CRUD** — schedule, exceptions, services sync, toggle transitions.
5. **Public page filter** — service hides and reappears on provider toggle.
6. **Staff index** — admin rows show with correct provider status.

No concurrency or performance tests in this plan. Race conditions on booking creation are R-4.

---

## 7. Verification

- `php artisan test --compact` — full suite green; expected net-positive test count vs. end of R-1A.
- `vendor/bin/pint --dirty --format agent` — clean.
- `npm run build` — clean.
- Manual smoke (during implementation, not ahead of approval):
  1. Fresh registration → verified → onboarding 1-3 with opt-in → skip step 4 → step 5 launches successfully.
  2. `/{slug}` public page shows the service.
  3. `POST /booking/{slug}/book` creates a booking with the owner as provider.
  4. `Settings → Account` shows the toggle on, schedule populated, service attached.
  5. Toggle off → public page filters the service out. Toggle on → service reappears.
  6. Launch blocked on the "admin not provider, no other providers" path; CTA recovers.

---

## 8. Risks and Mitigations

- **Risk**: Step 3's UX becomes cramped with the service form + opt-in + schedule editor vertically stacked.
  - **Mitigation**: Collapsible section. Default schedule mirrors business hours so the common path is one tap. If real usability testing later indicates density, promoting opt-in into its own sub-step is a non-breaking change (adds one step file, renumbers in the UI only).
- **Risk**: Admin toggles off with upcoming bookings, leaving customers with a provider who is no longer bookable but still holds the bookings.
  - **Mitigation**: Existing bookings stay valid — they reference the soft-deleted provider row, which the booking management pages load via `withTrashed()` (to be verified during implementation). A warning flash on toggle-off surfaces the upcoming-bookings count. No automatic cancellation.
- **Risk**: Launch gate redirect loses the user's form state on step 3.
  - **Mitigation**: The redirect carries the service form values via session flash, and the page re-hydrates them on render. Pattern matches existing form-error redirects in the onboarding flow.
- **Risk**: "Be your own first provider" CTA attaches the admin to every active service, including ones where that does not match the owner's intent.
  - **Mitigation**: The CTA's confirmation copy states: "You'll be added to all active services. You can adjust this in Settings → Account." The action is reversible via the Account page service sync.
- **Risk**: Public page filter hides a service silently and the admin does not notice.
  - **Mitigation**: The dashboard home page (out of scope for this plan, but worth noting) should surface a warning when any active service has zero providers. Tracked as a follow-up in `docs/BACKLOG.md` at session close.
- **Risk**: `settings.account.toggle-provider` can be hit by a non-admin via CSRF or request forgery.
  - **Mitigation**: Standard CSRF + `role:admin` middleware (already applied to the settings prefix) + `AccountController` resolves the current user; no way to toggle another user's provider row from this endpoint.

---

## 9. Open Questions for Review

1. **Identity editing on the Account page**: should the name/email/avatar be editable here in R-1B, or strictly read-only? Read-only in this plan; editable is a clean follow-up.
2. **Step-3 behavior when the admin is already a provider via another service**: the plan leaves the provider row intact and only manages this service's attachment. Confirm this matches product intent.
3. **Staff index: admin-who-is-not-a-provider action**: the row currently has no meaningful "deactivate" action. Plan renders a link to the admin's Account page with copy "Manage your provider settings". Confirm this is the desired treatment rather than, say, an inline toggle.

Stop after plan approval — no implementation before then.
