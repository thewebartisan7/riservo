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
