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
