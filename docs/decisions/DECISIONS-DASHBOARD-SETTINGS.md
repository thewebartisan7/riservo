# Dashboard and Settings Decisions

This file contains live decisions about onboarding, settings architecture, embed/share management, and staff lifecycle rules.

---

### D-040 — Onboarding state via onboarding_step + onboarding_completed_at
- **Date**: 2026-04-13
- **Status**: accepted
- **Context**: New business owners must complete a multi-step onboarding wizard before accessing the dashboard. The system needs to know (a) whether onboarding is complete, and (b) which step the user was on if they left mid-flow.
- **Decision**: Two fields on the `businesses` table: `onboarding_step` (unsignedTinyInteger, default 1) tracks the current/furthest step, and `onboarding_completed_at` (nullable timestamp) marks completion. An `EnsureOnboardingComplete` middleware redirects unboarded admins to `/onboarding/step/{step}`. Each step saves data immediately to the real models (Business, BusinessHour, Service, BusinessInvitation), so the wizard is naturally resumable with pre-populated data.
- **Consequences**: Each step is independently persistent — no temporary draft storage needed. The `onboarding_step` value only advances forward, never backwards, even if the user re-edits an earlier step.

---

### D-042 — Logo uploaded immediately via separate endpoint
- **Date**: 2026-04-13
- **Status**: accepted
- **Context**: The onboarding wizard step 1 includes a logo upload. Two approaches: (a) upload the file with the form submission, or (b) upload immediately via a separate endpoint and store the path.
- **Decision**: Logo is uploaded immediately via `POST /onboarding/logo-upload`, which stores the file to `Storage::disk('public')` under `logos/`, updates `business.logo` with the relative path, and returns the path and public URL as JSON. The main profile form stores only the path string, not the file.
- **Consequences**: Instant preview feedback for the user. Old logos are deleted on replacement. The endpoint returns JSON, not an Inertia response — consumed via `fetch()` on the frontend (will use `useHttp` after upgrading to Inertia client v3).

---

### D-052 — Settings controllers under Dashboard\Settings namespace
- **Date**: 2026-04-13
- **Status**: accepted
- **Context**: Settings pages need their own controllers separate from onboarding, even though they share similar logic, because they operate under different middleware (admin-only, onboarded) and serve different UX flows (edit-in-place vs wizard).
- **Decision**: Create a `Dashboard\Settings` controller namespace with 7 controllers (Profile, BookingSettings, WorkingHours, BusinessException, Service, Collaborator, Embed). Validation rules are duplicated from onboarding form requests rather than shared via a service class, because the code is thin enough that extraction would be premature abstraction.
- **Consequences**: Some validation rule duplication between onboarding and settings form requests. If rules diverge in the future, this is actually desirable.

---

### D-053 — Collaborator is_active on pivot, not soft delete
- **Date**: 2026-04-13
- **Status**: accepted
- **Context**: Deactivating a collaborator should preserve their booking history and data but exclude them from scheduling. Soft-deleting the `User` would affect their ability to log in to other businesses. Soft-deleting the pivot row would lose the role information.
- **Decision**: Add `is_active` boolean (default true) to the `business_user` pivot. Deactivated collaborators are excluded from slot generation and booking but their data and history remain intact. They can be reactivated at any time.
- **Consequences**: `SlotGeneratorService` and public booking flow should filter for `is_active = true` on the pivot when listing available collaborators. The migration is additive and non-breaking.

---

### D-054 — Embed mode via query parameter, not separate route
- **Date**: 2026-04-13
- **Status**: accepted
- **Context**: The public booking page at `/{slug}` needs to be embeddable in iframes on third-party sites with a stripped-down UI (no nav/footer).
- **Decision**: `?embed=1` query parameter on the existing `/{slug}` route triggers embed mode. The same controller and page component are used; the React layout conditionally hides header and footer. A separate `public/embed.js` file provides a popup modal script for third-party sites — a vanilla JS file that is not bundled through Vite.
- **Consequences**: No route duplication. The booking page component receives an `embed` boolean prop. The popup JS script reads `data-slug` from its own script tag and opens an iframe overlay when any `[data-riservo-open]` element is clicked.

---

### D-055 — Settings sub-navigation via nested layout
- **Date**: 2026-04-13
- **Status**: accepted
- **Context**: Settings pages need consistent left-side navigation across 7 sections without repeating the nav markup in each page.
- **Decision**: `settings-layout.tsx` wraps `authenticated-layout.tsx` and renders a sub-navigation sidebar specific to settings. Each settings page uses `settings-layout` as its layout.
- **Consequences**: Two levels of layout nesting: `authenticated-layout` (sidebar + header) > `settings-layout` (sub-nav + content area). This follows the existing layout composition pattern.

---

### D-062 — Launch requires at least one eligible provider per active service
- **Date**: 2026-04-16
- **Status**: accepted
- **Context**: Before R-1B, onboarding could complete with active services and zero providers, producing a public page that advertised services that generated no slots. A solo admin was never prompted to become a provider, so the common single-person path created a broken business on launch.
- **Decision**: `OnboardingController::storeLaunch()` refuses to mark the business onboarded when any active service has zero non-soft-deleted providers attached. On failure, it redirects to step 3 with `launchBlocked = { services: [...] }` so the wizard can surface the offending service names. A new `POST /onboarding/enable-owner-as-provider` endpoint creates (or restores) the admin's providers row, writes a default schedule from `business_hours`, and attaches the admin to every active service — the "Be your own first provider" one-click recovery. Defense-in-depth on the public page: `PublicBookingController::show()` filters services with `whereHas('providers')` so a post-launch deactivation self-heals the public page without a re-launch step.
- **Consequences**: Onboarding cannot produce a broken public page. When a service loses its last provider post-launch it disappears from `/{slug}` until a provider is re-attached — no silent admin action, no hidden failures. Admin-driven toggles remain the only way to add or remove providers; the system never auto-assigns.

---

### D-070 — Service prefilter for embed and direct link is a URL path segment
- **Date**: 2026-04-16
- **Status**: accepted
- **Context**: The public booking page, iframe embed, and popup embed all need to support landing a visitor directly on a single service's booking flow (skipping the service picker). Pre-R-9, two forms were in play simultaneously: SPEC §8 (lines 269-273) documented a query form `?embed=1&service=<slug>`, while the code already used a path form `/{slug}/{service-slug}?embed=1` (`embed.tsx:40`, `PublicBookingController::show($slug, ?$serviceSlug)`, the catch-all route `/{slug}/{serviceSlug?}` in `routes/web.php:201`, and three tests in `tests/Feature/Booking/PublicBookingPageTest.php`). `?service=` is never read by the controller. The popup embed supports neither. R-9 unifies the contract before teaching `public/embed.js` about services, because shipping a third divergent URL shape inside the popup would compound the drift.
- **Decision**:
  1. **Path form is canonical.** A prefiltered embed URL is `/{slug}/{service-slug}?embed=1`. A prefiltered direct link is `/{slug}/{service-slug}`. The `{service-slug}` segment is the business-scoped `services.slug`.
  2. **`?service=` is not supported.** The controller does not read `request('service')`, and we do not add it. If a host accidentally produces `/salon?embed=1&service=foo`, the `?service=foo` is silently ignored and the visitor lands on the service picker — the same behaviour as an unknown path-form slug.
  3. **Invalid path slugs are silently ignored.** `PublicBookingController::show()` already checks that the submitted service slug matches an active service for the business; unknown slugs fall through to the default service-picker flow (no 404). This is preserved.
  4. **SPEC is updated to match.** `docs/SPEC.md` §8 lines 269-279 rewrite the "Service Pre-filter" block to document path form and drop the query-form example.
  5. **Popup snippet emits the path form via `data-riservo-service`.** A per-button attribute on the `<button data-riservo-open>` element carries the service slug. `public/embed.js` builds the URL as `{base}/{slug}/{service}?embed=1` when present, else `{base}/{slug}?embed=1`. The `<script>` tag's `data-slug` is the only required attribute; an optional script-tag `data-service` sets a page-wide default that per-button attributes override.
  6. **Iframe snippet is unchanged in shape.** `embed.tsx:40-41` already emits path form; R-9 ratifies it.
  7. **D-054 is preserved.** `?embed=1` remains the embed-mode switch. D-070 specifies the service axis; D-054 specifies the layout axis. The two are orthogonal.
- **Consequences**:
  - One canonical URL shape across SPEC, iframe snippet, popup snippet, direct link, and tests.
  - Zero server-side work for R-9 — `show()` and routing are unchanged.
  - The popup gains feature parity with the iframe.
  - Analytics on the embedded page see a service-scoped pathname by default (`/salon/haircut`) rather than a query parameter on a shared path — cleaner segmentation without additional plumbing on the host.
  - Future service-rename workflows must either keep the old slug as an alias or accept that embed links break (slug-alias history is already flagged as a carry-over in ROADMAP-REVIEW.md "Public slug stability"; D-070 does not solve it, only names it).
- **Rejected alternatives**:
  - *Query form `?embed=1&service=<slug>`.* Would require a controller change (`$request->input('service')`), invalidates every iframe snippet already copied out of the dashboard (path form is already emitted), and analytics on host pages would have to parse query strings rather than read `location.pathname`. Net-negative on every column of the decision matrix except matching the (stale) SPEC.
  - *Dual-read (accept path + query, emit path).* Buys no compat — no deployed snippet uses the query form today (audit confirmed only SPEC drifts). Adds surface area and a silent-rewrite-vs-301 decision for no benefit.
  - *URL fragment `#service=<slug>`.* Client-only; the server never sees fragments, so SSR hydration can't pre-select the service. Would require a client-side redirect after mount — worse UX and worse analytics.
  - *A new `/embed/{slug}/{service}` route.* Adds a second embed surface for no reason. `?embed=1` (D-054) already scopes the layout axis; mixing a route-level axis with a query-level axis is redundant.
  - *`data-service` only on the `<script>` tag (no per-button override).* Forces a host page with multiple services to include multiple `<script>` tags with different `data-slug` values (which re-execute the module IIFE each time and collide on the module-level `overlay` variable). The per-button override is a trivial snippet change with materially better ergonomics.

---

### D-081 — Settings routes split into admin-only and admin+staff shared groups
- **Date**: 2026-04-16
- **Status**: accepted
- **Extends**: D-052 (Dashboard\\Settings namespace), D-055 (settings sub-navigation via nested layout).
- **Context**: Pre-MVPC-1, every settings route sat inside a single `Route::middleware('role:admin')->prefix('dashboard/settings')->group(...)`. The MVP-completion roadmap (`docs/roadmaps/ROADMAP-MVP-COMPLETION.md` Session 1 locked decision #8) opens three settings pages to `staff`: Calendar Integration (MVPC-1, this session) plus Account and Availability (MVPC-4). Every other settings page remains admin-only.
- **Decision**:
  1. Settings routes split into two sibling groups under `prefix('dashboard/settings')`, both nested inside the outer dashboard group at `routes/web.php:98` that already wraps with `['verified', 'role:admin,staff', 'onboarded']`:
     - `Route::middleware('role:admin')->prefix('dashboard/settings')->group(...)` — admin-only (existing pages; unchanged).
     - `Route::prefix('dashboard/settings')->group(...)` — shared. **No additional middleware** on the inner group: the outer dashboard group already enforces `role:admin,staff`, so re-declaring it would be redundant and read as if the inner group narrowed access. Calendar Integration lands here in MVPC-1; Account and Availability move here (or their new sibling routes land here) in MVPC-4.
  2. The shared group is named by intent, not by middleware. Its role is "no inner guard beyond what the outer group already enforces." No route-name prefix, no new middleware alias, no page-specific name. A future shared page is a one-line addition to the shared group.
  3. Settings nav (`resources/js/components/settings/settings-nav.tsx`) derives visibility from the shared `auth.role` prop. Admin sees the union of items across both groups (existing pages plus Calendar Integration under the "You" grouping); staff sees only the shared group's items.
  4. `tests/Feature/Settings/SettingsAuthorizationTest.php` splits into two matrices: "staff cannot access any admin-only settings page" (all existing admin-only routes) and "staff can access shared settings pages" (Calendar Integration today). Admin's matrix is the union. Staff users who lack a Provider row still reach every shared page the permission layer permits; Session 4 will add a provider-row check at the controller level for Availability, not at the middleware level.
- **Consequences**:
  - Adding a new shared settings page in future sessions is a one-line route file change (add the route into the shared group).
  - The access model is honest in both the route file and the test file — admin-only and shared are named and asserted distinctly.
  - Admin-only pages remain admin-only indefinitely; no staff access is implied or granted by this split.

---

### D-078 — Structural bookability is a single query scope; public page hides, dashboard banner surfaces
- **Date**: 2026-04-16
- **Status**: accepted
- **Context**: D-062 tightened the launch gate to require `Service::whereHas('providers')` per active service. REVIEW-2 HIGH-1 found that this is necessary but not sufficient — a provider row can be attached to a service while holding **zero `availability_rules`**, in which case `AvailabilityService::getAvailableWindows()` returns empty for every date and the public page advertises a service that can never produce a slot. The review also flagged the risk of conflating **structural** misconfiguration with **temporary** unavailability (vacation, full agenda, blocked day), which are legitimate runtime states that must not trigger "broken business" UI.
- **Decision**:
  1. **Single predicate, single home.** `Service::scopeStructurallyBookable()` and `Service::scopeStructurallyUnbookable()` in `app/Models/Service.php` are the canonical definition. All bookability questions flow through one of these two scopes.
  2. **Three structural conditions** define a structurally bookable service (all must hold):
     - `services.is_active = true`.
     - At least one non-soft-deleted `providers` row is attached via `provider_service`.
     - At least one of those providers holds ≥1 `availability_rules` row.
  3. **Explicit exclusions.** Temporary unavailability is **not** part of the definition:
     - Slots exist in the next N days.
     - `availability_exceptions` (vacation blocks, opens, partial blocks).
     - Full agenda for the visible horizon.
     - Closed business hours for the current day.
     These states correctly produce "no slots" through the availability engine and must not trigger the banner or the public-page hide.
  4. **Four callers share the scope** (and only these):
     - `OnboardingController::storeLaunch()` — `structurallyUnbookable()` drives the `launchBlocked` payload.
     - `PublicBookingController::show()` — `structurallyBookable()` filters the `/{slug}` service listing.
     - `HandleInertiaRequests::share()` — `structurallyUnbookable()` populates the `bookability.unbookableServices` shared prop.
     - `Dashboard\Settings\AccountController::toggleProvider()` — `structurallyUnbookable()->count()` chooses the flash-message copy when the admin self-deactivates as provider.
  5. **Public-page UX: hide.** A structurally unbookable service does not render on `/{slug}`. Rationale: `ServiceList` already handles the zero-state ("Nothing to book just yet."); a visible "currently unavailable" row offers no affordance and adds noise. Extends the D-062 `whereHas('providers')` hide pattern.
  6. **Dashboard banner.** When `bookability.unbookableServices` is non-empty, `AuthenticatedLayout` renders a persistent `Alert variant="warning"` above page content, admin-only, listing affected service names with a "Fix" link to `/dashboard/settings/services`. The banner auto-clears when the list becomes empty — no manual dismiss for MVP.
  7. **Defense-in-depth.** `StoreServiceRequest::withValidator()` rejects opt-in payloads with zero enabled-with-windows days; `OnboardingController::writeProviderSchedule()` throws a `LogicException` on empty schedules. Either layer alone would close HIGH-1; together they block future callers that skip validation.
- **Consequences**:
  - Onboarding cannot produce a structurally unbookable public page.
  - Post-launch, a service that crosses into structural unbookability (admin detaches the last provider, or deletes the last availability rule) vanishes from the public listing **and** surfaces on the dashboard banner until fixed.
  - Temporary unavailability states remain invisible to misconfiguration UIs: a vacation block or a full week doesn't trigger the banner and doesn't hide the service.
  - The four callers move together — adding a fifth consumer is a documented one-liner. Diverging consumers are caught by the integration-level consistency test (`tests/Feature/Bookability/ScopeConsistencyTest.php`).
- **Supersedes**: none. Extends D-061 (soft-delete semantics for providers) and D-062 (launch gate requires a provider) without replacing either — D-062's "at least one eligible provider per active service" still holds; D-078 sharpens "eligible".

---

### D-096 — Account/Availability route split for provider self-service
- **Date**: 2026-04-17
- **Status**: accepted
- **Extends**: D-081 (settings shared/admin sub-group split), D-062 (admin opted-in as own provider).
- **Context**: Pre-MVPC-4, `Dashboard\Settings\AccountController` was misnamed — every action it owned (`edit`, `toggleProvider`, `updateSchedule`, `storeException`, `updateException`, `destroyException`, `updateServices`) was about admin-as-own-provider availability, not about identity. The MVP-completion roadmap (Session 4 + locked decision #8) opens Account and Availability to staff. The misnaming meant building a real Account page on top of `AccountController` would either run two controllers fighting over the same name and route prefix, or refactor in place. Refactoring also resolves the structural smell.
- **Decision**:
  1. **Refactor `AccountController` in place.** It now manages profile (name, email), password, avatar (D-098 + D-097 + D-042 lineage), plus the kept admin-only `toggleProvider`. The schedule / exception / services bodies move to a new `Dashboard\Settings\AvailabilityController`. The schedule-rebuild helpers extract to `App\Services\ProviderScheduleService` so both `AccountController.toggleProvider` (seeds a new provider's schedule from business hours) and `AvailabilityController.updateSchedule` consume one implementation.
  2. **Route placement** under the `dashboard/settings` prefix:
     - **Shared sub-group** (admin + staff per D-081): `GET /account`, `PUT /account/profile`, `PUT /account/password`, `POST /account/avatar`, `DELETE /account/avatar`, `GET /availability`, `PUT /availability/schedule`, `POST /availability/exceptions`, `PUT /availability/exceptions/{exception}`, `DELETE /availability/exceptions/{exception}`.
     - **Admin-only sub-group**: `POST /account/toggle-provider` (admin chooses to be a bookable provider — D-062), `PUT /availability/services` (admin owns which services a provider performs; staff sees services read-only on the Availability page).
  3. **Self-scoped semantics.** Both controllers derive the active provider from `auth()->user() + tenant()->business()`. They never accept a `provider_id` from the request. `AvailabilityController` aborts 404 when the actor has no active Provider row. Form requests (`UpdateProviderScheduleRequest`, `StoreProviderExceptionRequest`, `UpdateProviderExceptionRequest`, `UpdateProviderServicesRequest`) relax `authorize()` to `return true` — auth is enforced by route middleware, and both `ProviderController` (admin managing others) and `AvailabilityController` (self) reuse the same validation rules.
  4. **Avatar self-endpoint is new, not a reuse of `StaffController.uploadAvatar`.** StaffController's endpoint takes `{user}` in the URL (admin acting on anyone). Self-upload needs no `{user}` binding; the actor is always `auth()->user()`. New endpoints reuse the validation rule shape (`File::image()->max(2048)->types([...])`) and the JSON `{ path, url }` response from D-042. Avatar removal is `DELETE /dashboard/settings/account/avatar`.
  5. **D-079 lockout still works** without new middleware. `BusinessMember` uses SoftDeletes with the SoftDeletingScope; `ResolveTenantContext::resolveMembership` queries `BusinessMember::query()` (scoped). A soft-deleted member resolves to no tenant → `EnsureUserHasRole` aborts 403 on every settings route, including the new ones. Locked by `SettingsAuthorizationTest::soft-deleted staff member cannot reach any settings page`.
- **Consequences**:
  - `AccountController.edit` returns a profile/password/avatar payload. The legacy schedule/exception/services keys are gone — any consumer that reads them now reads from `AvailabilityController.show` instead.
  - Staff-with-Provider get a real availability surface. Staff-without-Provider see Account but Availability is hidden in the nav (D-099) and 404s if accessed directly.
  - The `account.update-schedule`, `account.{store,update,destroy}-exception`, `account.update-services` route names are deleted; all callers now use the `availability.*` names.
  - Both `ProviderController` (admin-managing-others) and `AvailabilityController` (self) share one set of FormRequests and one schedule service. No parallel validation; no parallel persistence logic.

---

### D-097 — Email change commits immediately, nulls verified_at, dispatches re-verification
- **Date**: 2026-04-17
- **Status**: accepted
- **Cross-references**: DECISIONS-AUTH.md D-038 (verified middleware required for dashboard).
- **Context**: The MVPC-4 Account page lets users change their own email. Two semantics were available: (a) commit the new email immediately + null `email_verified_at` + dispatch a verification notification, or (b) shadow staging — keep the old email active and store the pending email in a new column until verification. Option (b) forces every login, magic-link, and password-reset path to branch on staging state.
- **Decision**:
  1. **Commit immediately.** `AccountController::updateProfile` writes the new email, sets `email_verified_at = null` if changed, and calls `$user->sendEmailVerificationNotification()` (Laravel core's standard `MustVerifyEmail` flow — already used by `RegisterController::store`).
  2. **No shadow staging.** No new column, no two-state semantics. Single state matches Laravel's default and reuses the existing `verification.notice` page.
  3. **Notification dispatch is queued, not after-response.** `VerifyEmail` from Laravel core implements `ShouldQueue`. We do not wrap it in `dispatch(...)->afterResponse()` (that's the D-075 carve-out for magic-link / invitation interactivity). Email-change is closer to registration — let it queue.
  4. **Typo recovery via a new auth-only endpoint.** A user who typo'd their new email is locked behind `verified` middleware and cannot reach `settings.account` to fix it. New `POST /email/change` route lives in the `auth` middleware group (NOT `verified`), backed by `EmailVerificationController::changeEmail` and a slim `ChangeEmailRequest`. The `verification.notice` Inertia page renders an inline "Wrong email? Change it." form that POSTs to this endpoint. Route is throttled `6,1` to mirror the existing `verification.send` rate limit.
- **Consequences**:
  - `users.email_verified_at` is null between commit and click-the-link. The user keeps their session but `verified`-gated routes redirect to `verification.notice` until they re-verify. Customer auth is not affected (D-038 — customers don't require verified email).
  - The OLD email is gone from `users.email` immediately. It no longer routes magic links, password resets, or notifications. A typo can be recovered through the new `verification.change-email` form before logout, OR by the user logging out and asking an admin to update them via the database (no admin UI for that today; backlog if it happens often).
  - The `verification.change-email` endpoint introduces a new auth-only mutation outside the dashboard group; it does NOT inherit `billing.writable`. Justified: subscription state is irrelevant to email-typo recovery, and the route can only be reached when the user is already locked behind `verified` middleware (not actively using the dashboard).

---

### D-098 — Password update branches on null vs non-null `password` column
- **Date**: 2026-04-17
- **Status**: accepted
- **Context**: Magic-link-only users have `users.password = null` (the column is nullable per the original users-table migration; `MagicLinkController::verify` does not set a password on first sign-in). They need a way to set a first password without supplying a "current password" they don't have. Once set, subsequent changes must require the current password to prevent a session-hijacker from rotating credentials silently.
- **Decision**:
  1. **One controller method, branching FormRequest.** `AccountController::updatePassword` accepts `UpdateAccountPasswordRequest`. The FormRequest's `rules()` reads `$this->user()->password === null` (server-side state, not a request flag) to decide:
     - `password === null`: rules are `password` (required + confirmed + `Password::defaults()`); no `current_password`.
     - `password !== null`: rules add `current_password` (required + Laravel's `current_password` validator, which uses `Hash::check` against the authenticated user's hash).
  2. **Client side is stateless about it.** The Account page reads a new `hasPassword: bool` Inertia prop (returned by `AccountController::edit`) to render the right form (toggle the `current_password` field, switch button label between "Set password" and "Change password"). Lying about `hasPassword` from the client cannot bypass the validator — the FormRequest re-reads server state.
  3. **Flash message switches on prior state.** `__('Password set.')` for the null → set transition; `__('Password changed.')` for the change. Read in the controller from the pre-update value.
- **Consequences**:
  - Magic-link-only users have a frictionless first-password path. They keep magic-link auth as well (the password is additive, not exclusive).
  - Once a password exists, the standard "current password required" gate applies.
  - No new column, no flag, no migration. The branch is derived from existing schema state.

---

### D-099 — `auth.has_active_provider` shared Inertia prop drives Availability nav visibility
- **Date**: 2026-04-17
- **Status**: accepted
- **Context**: Settings → Availability is a shared admin+staff page, but only meaningful when the actor has an active Provider row in the current business. The nav must hide it for users who have no provider row to manage; the page itself must defend with a 404 if reached directly. Both surfaces need a single source of truth on whether the actor has an active provider row.
- **Decision**:
  1. **Shared Inertia prop** `auth.has_active_provider: boolean` resolved lazily in `HandleInertiaRequests::share()`. Computed as `Provider::where('business_id', tenant()->businessId())->where('user_id', $user->id)->exists()` (the model auto-applies the SoftDeletingScope, so a trashed provider returns false). Returns false when no tenant or no user.
  2. **Settings nav** (`resources/js/components/settings/settings-nav.tsx`) reads the prop and filters out items marked `requiresActiveProvider: true` when it's false. Both admin and staff nav apply the same filter — admins who have not opted in as their own provider also do not see the Availability item until they toggle it on.
  3. **Page-level defense.** `AvailabilityController::show` aborts 404 when the actor has no active Provider row. No new middleware alias; the controller-level `abort(404)` is sufficient because the only entry point is the nav (which hides the link). Direct URL navigation by a user without a provider row 404s — the same shape as any non-existent settings page.
- **Consequences**:
  - The Availability nav item appears the moment an admin flips "Bookable provider" on (D-062), because the next Inertia visit re-resolves `has_active_provider` from server state.
  - A staff user invited as a provider sees Availability immediately on first sign-in.
  - A staff user whose admin has toggled them off (soft-deleted Provider row) loses the Availability item — but their schedule and exceptions are preserved in the database for restoration (D-061).
