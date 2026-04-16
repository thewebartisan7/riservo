# Handoff

**Session**: R-2 — Tenant Context and Cross-Tenant Validation (bundles R-3)
**Date**: 2026-04-16
**Status**: Complete

---

## What Was Built

Request-level tenant resolution is now **explicit, deterministic, and session-pinned**, and cross-tenant FK validation is now **reusable, central, and required** wherever user input names a business-owned record. This closes REVIEW-1 issues #2 and #6. Two new architectural decisions **D-063** and **D-064** recorded in `docs/decisions/DECISIONS-AUTH.md`.

### The primitives

- `app/Support/TenantContext.php` — per-request singleton (scoped binding in `AppServiceProvider`). Exposes `business()`, `role()`, `businessId()`, `has()`, `set()`, `clear()`.
- `app/Support/helpers.php` — `tenant()` global helper, autoloaded via `composer.json` `autoload.files`.
- `app/Http/Middleware/ResolveTenantContext.php` — registered in the `web` group in `bootstrap/app.php`. Populates the context from the authenticated user + `session('current_business_id')`. Self-heals stale / missing session values by falling back to the user's oldest active membership (ordered by `business_members.created_at ASC, id ASC` — deterministic under ties) and rewriting the session.
- `app/Rules/BelongsToCurrentBusiness.php` — reusable `ValidationRule` that scopes an FK lookup to `tenant()->businessId()` and respects the model's `SoftDeletes` scope by default.

### Middleware + shared-props rewrites

- `EnsureUserHasRole` now authorises against `tenant()->role()` — a user who is admin in Business A and staff in Business B passes `role:admin` only when pinned to A. The customer branch (`role:customer`) still reads `User::isCustomer()` and is unchanged.
- `EnsureOnboardingComplete` reads `tenant()->business()` for its redirect decision.
- `HandleInertiaRequests` shared props `auth.role` and `auth.business` resolve through `tenant()`.
- `User::currentBusiness()` and `User::currentBusinessRole()` are **deleted** (not shimmed). `hasBusinessRole()` stays but gets a docblock clarifying it is only for "is this user a business user at all" discriminators; tenant-aware authorisation must go through `tenant()`.

### Auth events pin the session

`LoginController::store`, `MagicLinkController::verify`, `RegisterController::store`, and `InvitationController::accept` all write `current_business_id` into the session after `Auth::login` / `session()->regenerate()`. These writes are strictly defensive — the middleware self-heals anyway — but they eliminate a "no tenant yet" window on the first post-auth redirect.

### Form Request overhaul (covers R-3)

- 23 Form Requests; `authorize()` bodies rewritten per the archetype table:
  - Onboarding + dashboard/settings → `tenant()->role() === BusinessMemberRole::Admin`
  - `/dashboard/bookings/*` (admin+staff) → `tenant()->has()`
  - Public (`StorePublicBookingRequest`) + auth guest (`LoginRequest`, `RegisterRequest`, `AcceptInvitationRequest`, `CustomerRegisterRequest`) → `return true` (by design — no tenant context yet)
- Eight inline "scope FK to current business" closures migrated to `new BelongsToCurrentBusiness(Service::class)` / `Provider::class`:
  - `StoreSettingsServiceRequest`, `UpdateSettingsServiceRequest` (`provider_ids.*`)
  - `UpdateProviderServicesRequest`, `StoreStaffInvitationRequest` (`service_ids.*`)
  - `StoreManualBookingRequest` (`provider_id` AND the previously unprotected `service_id`)
- Two plain `exists:services,id` bypass paths plugged:
  - `StoreInvitationsRequest` (`invitations.*.service_ids.*`) — was polluting `business_invitations.service_ids` JSON with cross-tenant IDs on accept-side re-scope; now rejected at validation time.
  - `StoreManualBookingRequest::service_id` — controller defense existed, validation now matches.
- `StorePublicBookingRequest` explicitly keeps its slug-scoped closure with an in-file comment explaining why: it is unauthenticated, has no session pin, and resolves the business from the URL slug instead of `tenant()`.

### Defense-in-depth on invitation JSON writes

`StaffController::invite` and `OnboardingController::storeInvitations` re-filter the incoming `service_ids` through `$business->services()->whereIn(...)->pluck('id')` before persisting to the `business_invitations.service_ids` JSON column. Validation rejects foreign IDs already, but we refuse to trust the payload when writing a blob that later readers (`InvitationController::accept`) consume.

### Controller migrations

Every `$request->user()->currentBusiness()` / `$user->currentBusinessRole()` call is now `tenant()->business()` / `tenant()->role()`:
`OnboardingController`, `WelcomeController`, `Dashboard\{DashboardController, BookingController, CalendarController, CustomerController}`, and every `Dashboard\Settings\*Controller`.

### New tests

- `tests/Feature/Rules/BelongsToCurrentBusinessTest.php` — 6 tests: in-tenant passes, cross-tenant fails, soft-deleted fails, missing context, null skipped, column override.
- `tests/Feature/TenantContext/ResolveTenantContextTest.php` — 6 tests: unauth empty, single-business populated, valid pin wins, stale pin self-heals, oldest wins on multiple memberships, customer-only empty.
- `tests/Feature/TenantContext/CrossTenantAuthorizationTest.php` — 5 tests: admin-in-A / staff-in-B fixture (built via direct `business_members` inserts, since no product flow creates this today) asserts `role:admin` gate, bookings listing scope, Inertia props swap with pin, and provider-schedule rejects cross-tenant provider.
- `tests/Feature/TenantContext/CrossTenantValidationTest.php` — 8 tests: every migrated Form Request + the re-scoped onboarding invitations path + a soft-deleted foreign service guard.

`SoloBusinessBookingE2ETest` still passes (one incidental `$user->currentBusiness()` replaced with `$user->businesses()->first()` — same semantic, public API). The customer-only `/my-bookings` path still passes.

---

## Current Project State

- **Backend**: new `app/Support/TenantContext.php`, `app/Support/helpers.php`, `app/Http/Middleware/ResolveTenantContext.php`, `app/Rules/BelongsToCurrentBusiness.php`. Updated `bootstrap/app.php`, `AppServiceProvider`, `HandleInertiaRequests`, `EnsureUserHasRole`, `EnsureOnboardingComplete`, all 4 auth controllers, and every dashboard / onboarding / settings controller.
- **Frontend**: no changes (shared props still expose `auth.role` and `auth.business` with the same shape).
- **Routes**: no changes. Wayfinder is unaffected.
- **Tests**: full Pest suite green — **433 passed, 1695 assertions**. Baseline before R-2 was 379; R-2 added approximately 54 tests across the four new test files plus a handful of updates.
- **Decisions**: D-063 and D-064 appended to `docs/decisions/DECISIONS-AUTH.md`.
- **Composer**: `autoload.files` now includes `app/Support/helpers.php`. Run `composer dump-autoload` after pulling.

---

## What the Next Session Needs to Know

**R-2B — business-switcher UI is deferred to a separate future plan, but the infrastructure is ready.** Everything that switcher needs is already in place:

- `session('current_business_id')` is the **authoritative session pin**. Writing a new value, then visiting any authenticated page, re-scopes the next request. Nothing additional is required on the server to make a switcher work.
- `ResolveTenantContext` already self-heals: if the switcher writes an invalid id (shouldn't happen, but defensively), the middleware falls back to the oldest active membership and rewrites the session.
- All controllers, middleware, Form Requests, and shared Inertia props read through `tenant()` — nothing hardcodes "the user's first business" anymore.

The R-2B plan therefore reduces to: a dashboard-header dropdown listing the user's active memberships, a `PUT /dashboard/switch-business` endpoint that validates the requested id against `$user->businesses()` and writes `current_business_id` into session, the React component, and tests that switching re-scopes the next request. It is purely additive.

**Prerequisite for R-2B to be user-visible**: a product flow that actually creates more than one `business_members` row for a single `User`. Today no flow does — `RegisterController` creates User+Business+Admin-membership atomically, and `InvitationController::accept` creates a fresh User per invite (collides on the unique email index if one already exists). Either of those needs a "join an existing account to a new business" branch before a switcher moves the needle for any real user. Not in R-2B's remit, either.

**Policies remain out of scope.** REVIEW-1 flagged Policy classes as an INFO-level architectural note; explicit `TenantContext` + `BelongsToCurrentBusiness` + single-role middleware closes the holes R-2/R-3 identified. A full Policy rollout is a separate initiative that would sit on top of this foundation.

**R-1A and R-1B are unaffected.** Provider soft-delete, admin-as-provider onboarding, launch gate, and public-page filtering all behave identically — the Settings → Account and Staff pages read from `tenant()->business()` instead of `$user->currentBusiness()`, but the logic is unchanged.

---

## Open Questions / Deferred Items

- **Multi-business join flow** (`InvitationController::accept` for existing users). Prerequisite for R-2B user-visibility. Not scoped yet.
- **Business-switcher UI** (R-2B). Plan-once-the-join-flow-lands.
- **Policies for business-owned models**. Orthogonal to R-2/R-3; would benefit from landing on top of `TenantContext`.
- **Dashboard-level "unstaffed service" warning**. Carried over from the R-1B handoff — still not in scope.
