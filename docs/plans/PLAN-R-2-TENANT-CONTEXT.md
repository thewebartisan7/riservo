---
name: PLAN-R-2-TENANT-CONTEXT
description: "R-2: tenant context + cross-tenant validation"
type: plan
status: shipped
created: 2026-04-16
updated: 2026-04-16
---

# PLAN-R-2 — Tenant Context and Cross-Tenant Validation

**Session**: R-2 (bundles R-3)
**Source**: `docs/reviews/ROADMAP-REVIEW.md` §R-2, §R-3; `docs/reviews/REVIEW-1.md` #2, #6
**Status**: Draft — awaiting approval
**Date**: 2026-04-16

---

## 1. Context

Post R-1A / R-1B the data model is correct: `business_members`, `providers`, soft-delete, role-as-permission-only. But the *request-level tenant resolution* is still implicit and non-deterministic, and several Form Requests still trust cross-business foreign keys.

### 1.1 R-2 — Implicit "first attached business"

- [app/Models/User.php:56](app/Models/User.php:56) — `currentBusiness()` returns `$this->businesses()->first()`. No deterministic ordering, no session pinning, no cross-tenant guard.
- [app/Models/User.php:64](app/Models/User.php:64) — `currentBusinessRole()` does the same and returns the pivot role from whichever business comes back first.
- [app/Http/Middleware/EnsureUserHasRole.php:31](app/Http/Middleware/EnsureUserHasRole.php:31) — `$user->hasBusinessRole($role)` authorizes against **any** active membership with that role, not against the currently-resolved business. A user who is admin in Business A and staff in Business B passes `role:admin` checks regardless of which business the subsequent controller is writing to.
- [app/Http/Middleware/HandleInertiaRequests.php:65,89](app/Http/Middleware/HandleInertiaRequests.php:65) — shared `auth.role` and `auth.business` inherit the same "first" semantics; the React app receives whichever business the ORM happened to return first, with no session pin.
- [app/Http/Middleware/EnsureOnboardingComplete.php:19](app/Http/Middleware/EnsureOnboardingComplete.php:19) — redirect decision also hinges on `currentBusiness()`.
- [app/Http/Controllers/OnboardingController.php](app/Http/Controllers/OnboardingController.php), [app/Http/Controllers/Dashboard/*](app/Http/Controllers/Dashboard/), [app/Http/Controllers/Dashboard/Settings/*](app/Http/Controllers/Dashboard/Settings/), [app/Http/Controllers/WelcomeController.php](app/Http/Controllers/WelcomeController.php) — every dashboard/onboarding/settings controller derives its "current business" by calling `$request->user()->currentBusiness()` directly.

The original review text also flagged `CollaboratorController::updateSchedule` for deleting `availabilityRules` via the *user* globally. Post R-1A that code path now lives on [app/Http/Controllers/Dashboard/Settings/ProviderController.php:39](app/Http/Controllers/Dashboard/Settings/ProviderController.php:39) and deletes through the `Provider` relation (`$provider->availabilityRules()->delete()`), scoped to the provider row. That specific leak is already resolved by R-1A; no further fix is required, but we verify it with a dedicated test.

### 1.2 R-3 — Cross-tenant FK validation gaps

Many Form Requests already use the correct pattern — a closure that queries via `$business->providers()->where('id', $value)->exists()` scoped to `currentBusiness()`:

- [app/Http/Requests/Dashboard/Settings/StoreSettingsServiceRequest.php:34](app/Http/Requests/Dashboard/Settings/StoreSettingsServiceRequest.php:34) (`provider_ids.*`)
- [app/Http/Requests/Dashboard/Settings/UpdateSettingsServiceRequest.php:34](app/Http/Requests/Dashboard/Settings/UpdateSettingsServiceRequest.php:34) (`provider_ids.*`)
- [app/Http/Requests/Dashboard/Settings/UpdateProviderServicesRequest.php:26](app/Http/Requests/Dashboard/Settings/UpdateProviderServicesRequest.php:26) (`service_ids.*`)
- [app/Http/Requests/Dashboard/Settings/StoreStaffInvitationRequest.php:26](app/Http/Requests/Dashboard/Settings/StoreStaffInvitationRequest.php:26) (`service_ids.*`)
- [app/Http/Requests/Dashboard/StoreManualBookingRequest.php:28](app/Http/Requests/Dashboard/StoreManualBookingRequest.php:28) (`provider_id`)
- [app/Http/Requests/Booking/StorePublicBookingRequest.php:28](app/Http/Requests/Booking/StorePublicBookingRequest.php:28) (`provider_id`, resolved from slug since the public route is unauthenticated)

But the pattern is duplicated (eight near-identical closures, each 15-plus lines) and has two real gaps:

- [app/Http/Requests/Onboarding/StoreInvitationsRequest.php:27](app/Http/Requests/Onboarding/StoreInvitationsRequest.php:27) — `'invitations.*.service_ids.*' => ['integer', 'exists:services,id']`. Plain `exists:`, no business scoping. A crafted payload can seed an invitation with service IDs from *any* business; those IDs sit dormant in `business_invitations.service_ids` JSON until the invitee accepts. The acceptance controller ([app/Http/Controllers/Auth/InvitationController.php:57](app/Http/Controllers/Auth/InvitationController.php:57)) does re-scope (`Service::where('business_id', ...)`), so no privilege escalation lands — but the persisted JSON blob is already polluted with foreign IDs by that point.
- [app/Http/Requests/Dashboard/StoreManualBookingRequest.php:24](app/Http/Requests/Dashboard/StoreManualBookingRequest.php:24) — `'service_id' => ['required', 'integer', 'exists:services,id']`. The controller does `$business->services()->where('id', ...)->firstOrFail()` defensively, so the bug cannot actually leak a cross-business booking, but validation is trusting the client. Inconsistent with the rest of the file.

Controllers that sync pivots already re-verify via Eloquent relations in most places ([ServiceController](app/Http/Controllers/Dashboard/Settings/ServiceController.php), [ProviderController::syncServices](app/Http/Controllers/Dashboard/Settings/ProviderController.php:100)), but:

- [StaffController::invite](app/Http/Controllers/Dashboard/Settings/StaffController.php:168) persists `service_ids` directly into the JSON column without re-scoping.
- [OnboardingController::storeInvitations](app/Http/Controllers/OnboardingController.php:402) does the same.

### 1.3 Evidence summary

- 23 Form Requests currently have `authorize(): bool { return true; }` — no real tenant authorization.
- 8 of those Form Requests re-implement the same "scope FK to current business" closure.
- 2 of those Form Requests still use plain `exists:` for business-owned FKs.
- 2 controllers persist `service_ids` JSON without defense-in-depth filtering.
- No test exercises a user who belongs to two businesses. The invitation-acceptance flow currently cannot even create such a user (it creates a new `User` row with the invitee's email, so an existing user email collides on the unique index) — the multi-business case is a latent correctness bug rather than a today-exploitable one.

---

## 2. Goal and Scope

### 2.1 Goal

Make tenant resolution **explicit, deterministic, session-pinned, and uniformly applied** for every authenticated request. Make cross-tenant FK validation **reusable, central, and required** wherever user input names a business-owned record.

### 2.2 In scope

- Introduce `App\Support\TenantContext` as the single source of truth for the active business and role in the current request.
- Introduce `App\Http\Middleware\ResolveTenantContext` to populate it from session + user, applied in the authenticated middleware stack.
- Delete `User::currentBusiness()` and `User::currentBusinessRole()`. Replace every caller with the explicit resolver (no silent fallback kept on `User`).
- Rewrite `EnsureUserHasRole` middleware so role checks run against the currently-resolved tenant, not "any tenant".
- Rewrite `HandleInertiaRequests` shared props to use the resolver.
- Rewrite `EnsureOnboardingComplete` middleware to use the resolver.
- Add a `tenant()` global helper (`App\Support\helpers.php` autoload) that returns the bound `TenantContext` singleton, for ergonomic controller / view / Form Request use.
- Populate `current_business_id` in the session on login (both password and magic-link flows) and on invitation acceptance.
- Rewrite every controller that reads `$request->user()->currentBusiness()` to read from `tenant()`.
- Rewrite every Form Request's `authorize()` method to return a real boolean — either "a tenant exists" or "the active role matches a required role", depending on the route.
- Introduce `App\Rules\BelongsToCurrentBusiness` — one reusable rule class, accepts a model class, returns a ValidationRule that scopes existence to `tenant()->businessId()`.
- Replace all eight inline closures with the rule.
- Plug the two remaining plain `exists:` gaps (`StoreInvitationsRequest`, `StoreManualBookingRequest`).
- Re-scope `service_ids` in both invitation-store paths (`StaffController::invite`, `OnboardingController::storeInvitations`) before writing to the JSON column, as defense-in-depth.
- Record two new architectural decisions (D-063, D-064).
- Add cross-tenant negative tests at every gate (middleware, Form Request, controller).

### 2.3 Out of scope

- **Business-switcher UI.** Today no flow allows a user to join a second business: registration creates User + Business atomically, invitation acceptance creates a fresh User per invite (the acceptance code calls `User::create(['email' => $invitation->email, ...])`, which fails the unique-email index if the invitee already has an account elsewhere). Multi-business membership is therefore a **latent capability the data model permits** but no product path surfaces. Shipping a switcher is premature — it needs a "join an existing account to a new business" flow first, which is not in this session's remit. Tracked as the R-2B follow-up.
- **Policies.** REVIEW-1 called out the absence of Laravel policies as an INFO-level architectural note, not a remediation task. We will not introduce `AuthorizesRequests`/`Policy` classes in this session. Explicit tenant context plus tenant-scoped validation plus a single-role middleware closes the specific holes R-2/R-3 identified; full Policy coverage is a separate initiative.
- **Unique constraint limiting users to one business.** Rejected under §3 ("alternatives rejected"). The mechanism we build works whether there is one membership per user or many.
- **Backward-compat shims.** Laravel's SPEC forbids backwards-compatibility hacks for internal code. `User::currentBusiness()` is deleted outright; every caller is audited.

---

## 3. Approach

### 3.1 Decision — Option B (explicit tenant context), not Option A (single-business constraint)

The roadmap names the two options:

| | A — One-business-per-user | B — Explicit tenant context |
|---|---|---|
| Code change | Unique index on `business_members.user_id`. `currentBusiness()` becomes unambiguous. | `TenantContext` singleton, middleware, session pin. Every caller migrates. |
| Permissions model | `hasRole()` is correct because there's only one business. | Middleware checks role on *the active* membership. |
| Multi-business future | Requires rebuilding the session/auth layer later. | Already built. Only the UI and the "join existing account" flow remain. |
| Solo operator (today's 99% case) | No change. | No behavioural change once session is pinned. |
| Freelancer at multiple salons (plausible future) | Blocked by design. | Supported once R-2B ships. |
| Impact on R-3 | `exists:services,id` is enough once user has only one business — but that's a weaker statement. | `BelongsToCurrentBusiness` slot fits regardless. |

**Chosen: B.** The deciding argument is that the current bug is not "users have two businesses" — it's that **the code pretends there's exactly one and picks non-deterministically when that pretence is violated**. The real fix is to make the choice explicit, even if today every user has exactly one membership. Option A closes off a plausible future product capability (freelance-at-multiple-locations) to simplify *today's* code, but it doesn't actually simplify today's code — we'd still need to fix every `currentBusiness()` caller to check "this is the right business", because the deleted `collaborator_id` role split and the new `providers` table no longer guarantee "one admin per user = one business per user" at the persistence level without active enforcement in the invitation flow. Option B builds the mechanism once. Option A defers the mechanism AND narrows the product.

### 3.2 The resolver

```
App\Support\TenantContext (class, singleton, scoped per request)
  - business(): ?Business
  - role():     ?BusinessMemberRole
  - businessId(): ?int
  - has(): bool
  - set(Business, BusinessMemberRole): void   // used by middleware and tests
  - clear(): void                              // used after logout
```

### 3.3 The middleware

```
App\Http\Middleware\ResolveTenantContext
  - runs after `auth`, before `role` and `onboarded`
  - applied to the `web` group (or attached explicitly to the authenticated
    route group in routes/web.php)
  - reads session('current_business_id')
  - validates that value against the user's active business_members rows
  - if invalid or missing, picks the user's oldest active membership
    (deterministic: order by business_members.created_at, then id)
  - persists the chosen id back to session
  - writes Business + role into TenantContext
  - if the user has no active memberships, leaves TenantContext empty (customers
    and freshly-invited users mid-acceptance both hit this path and are handled
    downstream)
```

Ordering: the `auth` middleware must run first so `$request->user()` is populated. The `verified`, `role`, and `onboarded` middlewares must run *after* `ResolveTenantContext` so they can read from the populated context. The right place is globally in the `web` middleware group after `auth` has a chance to run — but since `auth` is applied per-route, we attach `ResolveTenantContext` to the `auth` middleware **alias group** via a custom middleware group in `bootstrap/app.php` (Laravel 13 uses `withMiddleware` in the bootstrap).

### 3.4 Role middleware rewrite

```
EnsureUserHasRole (rewritten)
  - if no $user → 403
  - for each $role argument:
      - if 'customer' → check Customer record (unchanged)
      - else → $tenant = tenant(); return $tenant->has() && $tenant->role()->value === $role;
  - if none matched → 403
```

The key change: instead of `$user->hasBusinessRole($role)` (any-business), we ask "what role is this user playing *right now*?" and compare to the required role.

### 3.5 Shared Inertia props rewrite

```
HandleInertiaRequests
  resolveRole: reads tenant()->role()?->value first, falls through to customer check
  resolveBusiness: reads tenant()->business() directly
```

### 3.6 Form Request `authorize()` overhaul

Three archetypes:

| Route prefix | Example | `authorize()` body |
|---|---|---|
| `/onboarding/*` (admin-only, onboarding middleware absent) | `StoreProfileRequest` | `tenant()->role() === BusinessMemberRole::Admin` |
| `/dashboard/settings/*` (admin-only route group) | `UpdateProfileRequest`, `StoreSettingsServiceRequest`, all provider/staff/exception/service settings requests | `tenant()->role() === BusinessMemberRole::Admin` |
| `/dashboard/bookings/*` (admin+staff route group) | `StoreManualBookingRequest`, `UpdateBookingStatusRequest` | `tenant()->has()` (role is enforced by route middleware; Form Request just guarantees a tenant exists) |
| Public (no auth) | `StorePublicBookingRequest` | `return true` — by design; business is resolved from slug, not session |
| Auth guest | `LoginRequest`, `RegisterRequest`, `AcceptInvitationRequest`, `CustomerRegisterRequest` | `return true` — no tenant context yet |

The move from `return true` to an explicit check matters because if a future refactor ever loosens a route group's middleware, the Form Request remains secure. This is also why we push the check into the Form Request rather than relying solely on the route group.

### 3.7 The validation rule

```
App\Rules\BelongsToCurrentBusiness (implements ValidationRule)
  constructor:
    - /** @param class-string<Model> $modelClass */
    - string $modelClass
    - string $column = 'id'
  validate:
    - $tenant = app(TenantContext::class)
    - if (!$tenant->has()) { $fail('validation.invalid_tenant'); return; }
    - $exists = $this->modelClass::query()
                 ->where($this->column, $value)
                 ->where('business_id', $tenant->businessId())
                 ->exists();
    - if (!$exists) $fail('validation.belongs_to_business', ['attribute' => $attribute]);
```

Eloquent-based so `SoftDeletes` is automatically respected (a soft-deleted `Provider` row is invisible to the rule's default query — you cannot attach a soft-deleted provider to a new booking or to a service, which is the right behaviour).

### 3.8 Session + login + acceptance

- `LoginController::store` — after `session()->regenerate()`, resolve the user's oldest active membership and write its `business_id` into session. No-op if the user has no memberships (customer login).
- `MagicLinkController::verify` — same.
- `InvitationController::accept` — after the transaction that creates the new `business_members` row, set `session('current_business_id')` to the new `business_id` so the first dashboard visit is correctly scoped.
- `RegisterController::store` — after `Auth::login($user)`, set `session('current_business_id')` to the new `business_id`.
- `LoginController::destroy` — `session()->invalidate()` already clears everything; no extra work.

The self-healing fallback in `ResolveTenantContext` means none of these writes is strictly necessary for correctness today — the middleware will pick up the oldest membership on the next request. But writing it explicitly at login/accept time is one less round-trip and, more importantly, one fewer place where "it doesn't matter, the middleware will fix it" can drift.

### 3.9 Controller sync defense-in-depth

Two spots need a re-scope before writing `service_ids`:

- [StaffController::invite](app/Http/Controllers/Dashboard/Settings/StaffController.php:168): validate with `BelongsToCurrentBusiness(Service::class)`, **then** in the controller, re-filter via `$business->services()->whereIn('id', $serviceIds)->pluck('id')` before persisting the JSON blob.
- [OnboardingController::storeInvitations](app/Http/Controllers/OnboardingController.php:402): same pattern.

The rest (`ServiceController::store`/`update`, `ProviderController::syncServices`, etc.) already re-scope via Eloquent relations and need no change.

### 3.10 Alternatives rejected

- **Option A (unique constraint on `business_members.user_id`).** Rejected — closes off the freelance-at-multiple-locations scenario while still requiring a fix to every `currentBusiness()` caller because the non-determinism has to be removed one way or another.
- **Policies instead of a validation rule.** Rejected — policies work for "can this user perform this action on this model", not "is this ID one the user is allowed to name". FK validation happens before model loading, which is the right place to reject the ID. Policies would also be a much larger refactor than the review flagged.
- **Form Request base class `BusinessScopedFormRequest`.** Considered. Rejected because the three authorize-archetypes (§3.6) don't share enough to justify a common ancestor, and Laravel's Form Request inheritance is shallow by convention.
- **Route-based tenant resolution (active business inferred from URL).** Rejected — the current URL structure puts `/dashboard` at the top level with no slug; switching to `/{slug}/dashboard/*` is a much larger change than R-2 needs.
- **Tenant context as a request macro (`$request->tenant()`).** Considered. Equivalent to the helper; the helper is more ergonomic in views and Form Requests (which already access `$this->user()`).

---

## 4. New Architectural Decisions

### 4.1 D-063 — Explicit tenant context per request

- **Location**: `docs/decisions/DECISIONS-AUTH.md`
- **Supersedes**: nothing formally; implicitly the undocumented "first attached business" convention.
- **Context**: `User::currentBusiness()` returned the first attached business, and role middleware authorised against any attached business. Writes and authorization could diverge when a user belonged to more than one business. Multi-business membership is a plausible post-MVP product capability (a freelancer at two salons), so enforcing one-business-per-user would close off a real scenario.
- **Decision**: Introduce `App\Support\TenantContext` as the authoritative per-request source for the active business and the user's role within it. `App\Http\Middleware\ResolveTenantContext` populates it after `auth` from a session-pinned `current_business_id`, self-healing to the user's oldest active membership when the session value is missing or stale. `User::currentBusiness()` and `User::currentBusinessRole()` are removed. A `tenant()` global helper exposes the context to controllers, Form Requests, and views. Role middleware authorises against `tenant()->role()`, not "any business". Shared Inertia props (`auth.business`, `auth.role`) resolve through `tenant()`. Login, magic-link verify, register, and invitation-accept controllers pin the session's `current_business_id` explicitly.
- **Consequences**:
  - Authorization and scoping share the same tenant, eliminating the divergence risk.
  - Users with multiple memberships are correctly scoped to whichever business their session pins.
  - Multi-business membership remains a **data-model capability** but is not yet reachable through any product flow; the business-switcher UX is tracked as R-2B.
  - Every controller that read `$user->currentBusiness()` is migrated to `tenant()->business()`. The name change makes cross-tenant leakage a compile-error-visible concern in future work.

### 4.2 D-064 — `BelongsToCurrentBusiness` validation rule for tenant-scoped foreign keys

- **Location**: `docs/decisions/DECISIONS-AUTH.md` (security/validation belongs with the auth decisions that govern tenant boundaries).
- **Context**: Foreign-key validation to business-owned data was implemented as eight near-identical closures inside Form Requests, plus two plain `exists:` rules that trusted the client. The pattern was duplicated, easy to regress, and two bypass paths remained.
- **Decision**: Introduce `App\Rules\BelongsToCurrentBusiness` — a reusable `ValidationRule` implementation that takes a model class (class-string), queries it scoped to `tenant()->businessId()`, and respects the model's own `SoftDeletes` scope. All Form Requests that name a business-owned FK use this rule. Controllers that persist FK arrays (e.g., `business_invitations.service_ids`) re-filter through the owning business's Eloquent relation before writing, as defense-in-depth.
- **Consequences**:
  - The cross-tenant FK surface shrinks to one class.
  - New Form Requests adopting the pattern is a one-line import.
  - Soft-deleted providers/services are invisible to the rule by default — matching the "cannot attach a deactivated provider to a new booking" behaviour we already want.
  - The rule hard-depends on `TenantContext` being populated, which is guaranteed by `ResolveTenantContext` running before route validation on every authenticated request.

---

## 5. Implementation Order

Each step is a small, independently-testable increment. The order is chosen so the test suite stays green at every commit boundary — we land the new infrastructure, add coverage, migrate callers, and only then delete the old pass-throughs.

1. **Scaffold `TenantContext`** — `app/Support/TenantContext.php`; bind in `AppServiceProvider::register` as scoped (`$this->app->scoped(TenantContext::class)`). Add `tenant()` helper in `app/Support/helpers.php`; autoload it via `composer.json` `files`. Add unit test that `tenant()` returns the bound instance and that `set()` / `clear()` behave as specified.
2. **Write `ResolveTenantContext` middleware** — `app/Http/Middleware/ResolveTenantContext.php`. Add to the `web` group in `bootstrap/app.php` so it runs on every web request (it is a no-op for unauthenticated ones). Add middleware test: membership exists → context populated; session pins to a valid membership → that membership wins; session pins to an invalid / stale id → oldest-membership fallback kicks in and session is rewritten; no membership → context empty.
3. **Pin `current_business_id` on auth events** — `LoginController::store`, `MagicLinkController::verify`, `RegisterController::store`, `InvitationController::accept`. Each sets the session key after the Auth::login / regenerate call. Add tests: after login the session holds the pinned id; after invitation accept the session points at the new membership.
4. **Rewrite `EnsureUserHasRole`** — now consults `tenant()`. Update existing tests in `tests/Feature/Auth/MiddlewareTest.php` to keep passing (the single-business path is behaviourally unchanged). Add a new multi-business test: `user` is admin in A + staff in B, session pinned to B, `role:admin` middleware denies with 403.
5. **Rewrite `EnsureOnboardingComplete`** — reads `tenant()`. Add a multi-business test: admin in A (onboarded) and admin in B (not onboarded), session pinned to B → redirected to onboarding; session pinned to A → falls through.
6. **Rewrite `HandleInertiaRequests` shared props** — `auth.business` and `auth.role` resolve through `tenant()`. Update snapshot / prop assertions where needed.
7. **Introduce `BelongsToCurrentBusiness` rule** — `app/Rules/BelongsToCurrentBusiness.php`. Add a feature-test file `tests/Feature/Rules/BelongsToCurrentBusinessTest.php` that covers: valid in-tenant id passes; soft-deleted id fails by default; cross-tenant id fails; nullable column handling; no-tenant-context case.
8. **Migrate eight Form Requests off inline closures** — `StoreSettingsServiceRequest`, `UpdateSettingsServiceRequest`, `UpdateProviderServicesRequest`, `StoreStaffInvitationRequest`, `StoreManualBookingRequest`, plus the two plain `exists:` gaps (`StoreInvitationsRequest`, `StoreManualBookingRequest::service_id`). Each change drops 15-plus lines and gains `new BelongsToCurrentBusiness(...)`. Every edited file gets a negative test that posts a foreign-business id and asserts 422.
9. **`StorePublicBookingRequest` stays on its slug-scoped closure** — the public route has no `tenant()` (no auth, no session pin). Add a comment explaining why the rule is not used here.
10. **Rewrite every `authorize()` body** — 23 Form Requests, per the archetype table in §3.6. Replace `return true` with the real check. Update any existing tests that relied on `authorize(): true` passing (we do not expect any — every existing authorization test currently goes through route middleware, and role middleware is what gates access today).
11. **Replace `$request->user()->currentBusiness()` callers** — every controller listed in §6. The mechanical change is `$business = $request->user()->currentBusiness();` → `$business = tenant()->business();` and `$user->currentBusinessRole()` → `tenant()->role()`. Audit each for logic that depended on the null case; `tenant()->business()` remains nullable-by-type but every dashboard controller runs behind `auth + role + onboarded` middleware, so `tenant()->has()` is guaranteed true there.
12. **Defense-in-depth re-scope in two invitation paths** — `StaffController::invite` and `OnboardingController::storeInvitations` filter `service_ids` through `$business->services()->whereIn(...)->pluck('id')` before persisting.
13. **Delete `User::currentBusiness()` and `User::currentBusinessRole()`** — only possible once every caller is migrated. The compile / static-analysis failure pass is how we verify completeness (`vendor/bin/phpstan analyse` should report zero references; `grep -r currentBusiness` should return only test strings in historical fixtures).
14. **Write and run the cross-tenant negative-test suite** — `tests/Feature/TenantContext/*Test.php`. See §7 for the matrix.
15. **Update decision files** — append D-063 and D-064 to `docs/decisions/DECISIONS-AUTH.md`.
16. **Verification pass** — `php artisan test --compact`, `vendor/bin/pint --dirty --format agent`, `npm run build`.
17. **Rewrite HANDOFF** — summarise what was built and what the next session needs (R-2B follow-up, any residual technical debt).
18. **Archive the plan** — move `docs/plans/PLAN-R-2-TENANT-CONTEXT.md` to `docs/archive/plans/`.

---

## 6. File Changes

### 6.1 New files

- `app/Support/TenantContext.php` — the context class.
- `app/Support/helpers.php` — `tenant()` function. Autoload via `composer.json` `files`.
- `app/Http/Middleware/ResolveTenantContext.php` — the resolver middleware.
- `app/Rules/BelongsToCurrentBusiness.php` — the validation rule.
- `tests/Feature/TenantContext/ResolveTenantContextTest.php` — middleware-level tests.
- `tests/Feature/TenantContext/CrossTenantAuthorizationTest.php` — the multi-business auth matrix.
- `tests/Feature/TenantContext/CrossTenantValidationTest.php` — the Form Request FK matrix.
- `tests/Feature/Rules/BelongsToCurrentBusinessTest.php` — unit-style coverage of the rule itself.

### 6.2 Modified files (backend)

- `app/Models/User.php` — delete `currentBusiness()`, `currentBusinessRole()`, keep `hasBusinessRole()` (still useful for "is this user a member anywhere" checks like the customer-vs-business-user discriminator at login) but add a docblock clarifying that *role-based authorisation* should go through `tenant()`, not this method.
- `bootstrap/app.php` — register `ResolveTenantContext` in the `web` middleware group after `auth`.
- `app/Http/Middleware/EnsureUserHasRole.php` — reads `tenant()`.
- `app/Http/Middleware/EnsureOnboardingComplete.php` — reads `tenant()`.
- `app/Http/Middleware/HandleInertiaRequests.php` — `resolveRole()`, `resolveBusiness()` read `tenant()`.
- `app/Providers/AppServiceProvider.php` — bind `TenantContext` as scoped.
- `composer.json` — add `files: ["app/Support/helpers.php"]` under `autoload.files`.
- `app/Http/Controllers/Auth/LoginController.php` — set session key.
- `app/Http/Controllers/Auth/MagicLinkController.php` — set session key on verify.
- `app/Http/Controllers/Auth/RegisterController.php` — set session key.
- `app/Http/Controllers/Auth/InvitationController.php` — set session key on accept.
- `app/Http/Controllers/OnboardingController.php` — callers migrated.
- `app/Http/Controllers/WelcomeController.php` — callers migrated.
- `app/Http/Controllers/Dashboard/DashboardController.php` — callers migrated.
- `app/Http/Controllers/Dashboard/BookingController.php` — callers migrated.
- `app/Http/Controllers/Dashboard/CalendarController.php` — callers migrated.
- `app/Http/Controllers/Dashboard/CustomerController.php` — callers migrated.
- `app/Http/Controllers/Dashboard/Settings/AccountController.php` — callers migrated.
- `app/Http/Controllers/Dashboard/Settings/BookingSettingsController.php` — callers migrated.
- `app/Http/Controllers/Dashboard/Settings/BusinessExceptionController.php` — callers migrated.
- `app/Http/Controllers/Dashboard/Settings/EmbedController.php` — callers migrated.
- `app/Http/Controllers/Dashboard/Settings/ProfileController.php` — callers migrated.
- `app/Http/Controllers/Dashboard/Settings/ProviderController.php` — callers migrated (no logic change; only the `$business =` source line).
- `app/Http/Controllers/Dashboard/Settings/ServiceController.php` — callers migrated.
- `app/Http/Controllers/Dashboard/Settings/StaffController.php` — callers migrated; `invite()` also adds the re-scope of `service_ids` before persisting.
- `app/Http/Controllers/Dashboard/Settings/WorkingHoursController.php` — callers migrated.
- All 19 Form Requests listed in `app/Http/Requests/**/*.php`: `authorize()` bodies rewritten, plus 8 migrated to `BelongsToCurrentBusiness`, plus the 2 plain `exists:` gaps plugged.

### 6.3 Modified files (tests)

- `tests/Pest.php` — consider adding a `pinTenant(User $user, Business $business): void` helper that calls `tenant()->set($business, $role)` *and* `session(['current_business_id' => $business->id])` for the rare unit-style test that bypasses HTTP. Most existing tests run through HTTP and don't need it.
- `tests/Feature/Auth/MiddlewareTest.php` — update to exercise both the single-business unchanged path and a new multi-business negative case.
- `tests/Feature/Settings/SettingsAuthorizationTest.php` — extend with a multi-business admin+staff fixture asserting scoping.
- Each Form Request migrated in step 8 gets a cross-tenant negative test added to the corresponding test file (e.g., `tests/Feature/Settings/ServiceTest.php` gains "cannot assign a provider from another business"; `StaffTest.php` gains "cannot attach a service from another business to an invite"; etc.).

### 6.4 Deleted

- `User::currentBusiness()` and `User::currentBusinessRole()` — both methods are removed.
- Eight inline closures in the five settings / manual-booking / staff-invite Form Requests — replaced by one-line `new BelongsToCurrentBusiness(...)`.

### 6.5 Renamed

- Nothing is renamed. The new names (`TenantContext`, `tenant()`, `BelongsToCurrentBusiness`, `ResolveTenantContext`) are all new introductions.

---

## 7. Testing Plan

### 7.1 Unit-level coverage (`tests/Feature/Rules/BelongsToCurrentBusinessTest.php`)

- In-tenant id passes.
- Cross-tenant id fails with 422 + message.
- Soft-deleted Provider id in the same tenant fails by default.
- Missing tenant context (`tenant()->clear()` before validating) fails with the "invalid tenant" message.
- Column override works (`new BelongsToCurrentBusiness(Service::class, 'slug')`).

### 7.2 Middleware-level coverage (`tests/Feature/TenantContext/ResolveTenantContextTest.php`)

- Unauthenticated request: context empty, no session write.
- Authenticated single-business user, no session value: context is populated to that membership; session is written.
- Authenticated user with session pinned to a valid membership: context matches the pin.
- Authenticated user with session pinned to a stale / deleted / foreign membership id: context falls back to the oldest active membership; the session value is corrected.
- Authenticated customer-only user (Customer but no business_member rows): context remains empty.

### 7.3 Cross-tenant authorization (`tests/Feature/TenantContext/CrossTenantAuthorizationTest.php`)

A fixture: `$user` is `admin` in `$businessA` and `staff` in `$businessB` (built through direct `business_members` inserts to simulate the post-R-2B state, since no product flow creates this today).

- Session pinned to A, hits `/dashboard/settings/*` (admin-only): allowed.
- Session pinned to B, hits `/dashboard/settings/*`: 403.
- Session pinned to A, hits `/dashboard/bookings` (admin+staff): allowed, all A bookings visible, no B bookings.
- Session pinned to B, hits `/dashboard/bookings`: allowed, only provider-scoped B bookings visible.
- Session pinned to A, reads `HandleInertiaRequests` props via any page: `auth.business.id === $businessA->id`, `auth.role === 'admin'`.
- Session pinned to B, reads props: `auth.business.id === $businessB->id`, `auth.role === 'staff'`.
- `ProviderController::updateSchedule` on a `Provider` belonging to B, with session pinned to A: 403 (confirms the R-1A-era `$provider->availabilityRules()->delete()` never touches A's providers, and the tenant-scope check rejects the attempt).

### 7.4 Cross-tenant FK validation (`tests/Feature/TenantContext/CrossTenantValidationTest.php`)

For each migrated Form Request:

- Valid in-tenant id: the original happy-path test keeps passing.
- Valid in-tenant id + foreign-tenant id mixed in an array: 422; the foreign id is rejected by field.
- Purely foreign-tenant id: 422.
- Soft-deleted in-tenant provider id (where relevant): 422.
- Empty / missing array (where `present` is the rule): existing behaviour unchanged.

Routes under test:

- `POST /dashboard/settings/services` — `provider_ids`
- `PUT /dashboard/settings/services/{id}` — `provider_ids`
- `PUT /dashboard/settings/providers/{id}/services` — `service_ids`
- `POST /dashboard/settings/staff/invite` — `service_ids`
- `POST /onboarding/step/4` — `invitations.*.service_ids`
- `POST /dashboard/bookings` — `provider_id`, `service_id`

### 7.5 Solo-business regression guard

The `SoloBusinessBookingE2ETest` already walks the register → onboard → launch → receive-booking path. It must keep passing byte-for-byte after R-2 lands. Any failure here means the new middleware broke the single-business path.

### 7.6 Full suite

`php artisan test --compact` passes. Baseline is 379 tests; R-2 adds approximately 25–40 new tests across the four new / modified test files.

---

## 8. Verification Steps

Run in order from the project root:

1. `php artisan test --compact` — full Pest suite is green.
2. `vendor/bin/pint --dirty --format agent` — formatter passes.
3. `npm run build` — frontend builds. Wayfinder regeneration is not needed (no route changes).
4. `php artisan route:list --except-vendor` — confirm `ResolveTenantContext` appears in the middleware stack for authenticated routes.
5. `grep -R "currentBusiness" app/` — expect zero matches. `grep -R "currentBusinessRole" app/` — expect zero matches.

---

## 9. Risks and Mitigations

### 9.1 Every existing session becomes invalid at deploy

- **Risk**: sessions before the deploy have no `current_business_id`. On first request after the deploy, `ResolveTenantContext` sees a missing session key.
- **Mitigation**: the middleware self-heals — it picks the oldest active membership and writes it to session. The user experiences no interruption. No manual session-clearing is required.

### 9.2 Customer-only sessions (`/my-bookings`)

- **Risk**: A customer-only user (has `Customer` row, no business memberships) would fall through the middleware with empty context. Any downstream code that assumes `tenant()->has()` would throw.
- **Mitigation**: the customer branch of every flow never reads `tenant()` — customer routes are gated by `role:customer` middleware which consults `$user->isCustomer()` directly. The shared Inertia props for customer pages would return `auth.business = null` and `auth.role = 'customer'` (resolved via the unchanged customer branch). Verified by the existing `customer can access my-bookings` test staying green.

### 9.3 Tests that `actingAs($user)` without hitting the HTTP middleware stack

- **Risk**: a unit test that instantiates a controller directly would bypass `ResolveTenantContext` and see an empty context.
- **Mitigation**: add `pinTenant($user, $business)` test helper. Audit the current test suite — the vast majority are feature tests making real HTTP calls, so the middleware runs. The few that don't get the helper.

### 9.4 Deleting `User::currentBusiness()` breaks silently

- **Risk**: a forgotten caller slips through and is discovered at runtime.
- **Mitigation**: the grep check in §8 is mandatory in the verification step. Larastan (`vendor/bin/phpstan analyse`) will also flag missing method references if any third-party or new code calls it.

### 9.5 Invitation-accept flow in production

- **Risk**: the invitation acceptance test already creates a fresh User per invite; there is no current path where an existing logged-in user accepts an invite to join a second business. After R-2, if a future story implements that, we want `current_business_id` to follow the newly-joined business — not strand the user in their prior session.
- **Mitigation**: `InvitationController::accept` explicitly writes the new `business_id` to session. The behaviour is correct for both today's "new user" flow and a future "existing user joins second business" flow.

### 9.6 The oldest-membership fallback is still non-deterministic if `created_at` ties

- **Risk**: two memberships created in the same second could return in either order.
- **Mitigation**: order by `business_members.created_at ASC, business_members.id ASC`. Both columns together are unique, so the order is fully determined.

### 9.7 Rule class resolution in Form Requests instantiated for tinker / unit tests

- **Risk**: `BelongsToCurrentBusiness::validate` calls `app(TenantContext::class)` lazily. In a non-bootstrapped context (e.g., raw `Validator::make` in a test without the TestCase trait), no context is bound.
- **Mitigation**: the rule fails safely when `tenant()->has()` is false — it emits the "invalid tenant context" validation error rather than throwing. Tests exercising the rule outside HTTP must pin the tenant explicitly (helper from §9.3).

---

## 10. Follow-ups (out of scope for this session)

- **R-2B — business-switcher UI**. Only unlocks value once there is a product flow that creates more than one membership for a single user. Needs its own plan: UI component in the dashboard header, a `PUT /dashboard/switch-business` endpoint, session-key validation against live memberships, and tests that switching re-scopes the next request.
- **Policy layer for business-owned models**. REVIEW-1 INFO note. Orthogonal to R-2/R-3; would benefit from landing on top of the explicit `TenantContext`.
- **"Invite an existing user" invitation flow**. Prerequisite for R-2B to be user-visible. Not part of either R-2A or R-2B.

---

## 11. Approval

This plan is ready for review. No product code has been written. Awaiting developer sign-off before implementation starts.
