# riservo.ch — Review Roadmap (Round 1)

> **Status**: **CLOSED**, 2026-04-16
> **Scope**: Remediation of REVIEW-1 findings (17 issues, consolidated into 16 R-NN items).
> **Outcome**: R-1 – R-15 complete. R-16 deferred to `docs/BACKLOG.md`. All 16 items dispositioned.
> **Source review**: `docs/archive/reviews/REVIEW-1.md`.
> **Next round**: REVIEW-2 (pending; will produce `ROADMAP-REVIEW-2.md` if remediation is needed).
> **Archived as**: `docs/archive/reviews/ROADMAP-REVIEW-1.md`.

---

## Overview

This roadmap addressed the issues found during the post-Session-11 code review (REVIEW-1.md). Items are grouped into sessions by theme and dependency order — data model and authorization first, then booking correctness, then frontend issues, then operations. The original review contained 17 findings; some were consolidated here into shared sessions, but all were covered.

Sessions were ordered so that later sessions did not have to work around unfixed earlier issues. Each session left all tests green.

---

## R-1 — Admin as Provider (Critical)

**Source**: REVIEW-1.md issue #1  
**Priority**: Critical — blocks solo-business use case  
**Status**: **complete**, 2026-04-16 — resolved via D-061 (`Provider` as first-class entity, `business_members` pivot with role-only semantics) and D-062 (launch requires at least one eligible provider per active service; one-click "be your own first provider" recovery).

### Context

The current model creates every business owner as `admin` only. `collaborators()` and `getEligibleCollaborators()` in the slot engine only look at the `collaborator` role on the `business_user` pivot. A solo operator (hairdresser, coach, consultant) who registers, completes onboarding, and publishes a booking page currently ends up with zero bookable providers — their public page lists services but generates no slots.

The fix must also work for a larger business where the owner is both admin and a provider simultaneously.

### What to deliver

- An admin can choose to be bookable as a provider. This is opt-in — not every admin wants to appear in the booking flow.
- The opt-in is surfaced in two places:
  1. **Onboarding step 2 or 3** — after setting business hours / services, a prompt: "Are you also taking bookings yourself?" If yes, the owner's weekly schedule is configured then and there (same UI as the collaborator schedule form).
  2. **Settings → Account** — a toggle to enable/disable being a bookable provider, with access to manage personal schedule and exceptions.
- When an admin is bookable, they must appear in:
  - The eligible collaborator list for slot generation
  - The public collaborator picker (when `allow_collaborator_choice` is on)
  - The manual-booking collaborator dropdown on the dashboard
  - The service assignment UI (assign the admin to services just like any collaborator)
- If an owner chooses **not** to be bookable, and there are no other active providers assigned to public services, the system must not allow a "launch" that produces a public page with services but no bookable provider behind them. The agent should choose the right UX: block launch, hide unstaffed services, or another explicit fallback, and document it.
- The agent must evaluate the model approach and record the decision in the appropriate topical file listed in `docs/DECISIONS.md`. Likely directions include:
  - add an explicit `is_provider` boolean / capability on `business_user`
  - refactor the role model so "admin" and "provider" are separate concerns
  - another explicit model that allows an owner to be both manager and provider
- The current schema cannot simply "store both roles" on the existing `business_user.role` column, so the plan must not assume that a single pivot row can hold `admin` and `collaborator` simultaneously without a schema change.
- Add an end-to-end test: a freshly onboarded solo business (owner opted in as provider, one service assigned) can receive a real booking via the public booking page.

---

## R-2 — Tenant Context and Multi-Business Membership (High)

**Source**: REVIEW-1.md issue #2  
**Priority**: High — security and correctness risk  
**Status**: **complete**, 2026-04-16 — resolved via D-063 (Option B: explicit tenant context per request via `App\Support\TenantContext` + `ResolveTenantContext` middleware). Multi-business membership remains a data-model capability; business-switcher UX deferred as R-2B (`docs/BACKLOG.md`).

### Context

`User::currentBusiness()` returns the first attached business, which is non-deterministic when a user belongs to more than one. Authorization middleware checks "any attached business" rather than the active one. This means a user who is an admin in Business A and a collaborator in Business B could be authorized as admin while their writes scope to whichever business the ORM returns first.

Multi-business membership is also an appealing product feature (a freelancer who works at multiple locations, each with their own riservo account). The value is real, but it introduces significant complexity in session management, tenant context, and authorization.

### What to deliver

The agent must evaluate two approaches and propose one in the plan:

**Option A — Enforce single-business membership at the model level**
- Add a unique constraint or equivalent application-level enforcement on `business_user.user_id`, so each user can belong to exactly one business.
- Make this explicit in registration, invitation, and role middleware.
- `currentBusiness()` becomes unambiguous.
- Multi-business support is deferred to a future feature with proper session-switching UI.

**Option B — Explicit tenant context per request**
- Store the active business in session at login (or resolve it from the route context).
- All authorization and scoping uses the session-resident business ID, not a "first attached" lookup.
- A business-switcher UI is added to the dashboard header for users who belong to multiple businesses.
- `currentBusiness()` reads from session, falls back to the first if no session value.

Regardless of which option is chosen:
- All Form Request `authorize()` methods must use the resolved, explicit tenant context — not `true`.
- `CollaboratorController::updateSchedule()` must scope `availabilityRules()` deletion to the current business, not the user globally.
- The agent records the choice in the appropriate topical file listed in `docs/DECISIONS.md`.
- Add tests for the cross-tenant scenario: a user with two businesses cannot read or write data belonging to the other when scoped to one.

---

## R-3 — Cross-Tenant Validation of Foreign Keys (High)

**Source**: REVIEW-1.md issue #6  
**Priority**: High — security, tenant isolation  
**Status**: **complete**, 2026-04-16 — resolved via D-064 (`App\Rules\BelongsToCurrentBusiness` reusable validation rule; Form Requests migrated; controllers re-filter FK arrays through the owning business's Eloquent relation as defense-in-depth).

### Context

Several Form Requests validate collaborator IDs and service IDs with plain `exists:users,id` or `exists:services,id` without checking business ownership. A crafted request can attach arbitrary users to a service or pre-assign services from another business to an invitation.

### What to deliver

- All Form Requests that accept `collaborator_id`, `user_id`, `service_ids`, or any foreign key referencing business-owned data must validate against the current business scope.
- The pattern should be a reusable Rule or closure that checks `business_id = currentBusiness()->id` alongside the existence check.
- Controllers that sync pivots (service collaborators, invitation service pre-assignment) must re-verify business ownership before writing, as defense-in-depth.
- Add tests that post a valid ID belonging to a different business and assert rejection with a 422 or 403.

---

## R-4 — Booking Race Condition (High)

**Source**: REVIEW-1.md issue #3  
**Priority**: High — data integrity  
**Status**: split into R-4A (database engine swap — **complete**, 2026-04-16) and R-4B (race guard — **complete**, 2026-04-16)

### Context

Both the public booking flow and the manual dashboard booking flow perform a slot availability check in application code and then insert the booking in a separate step. Two concurrent requests can both pass the check and both create overlapping bookings.

### What to deliver

The agent must evaluate and propose the right combination of the following approaches in the plan:

1. **Database transaction with locking**: wrap the availability re-check and the `Booking::create` in a transaction, using row-level locks (`SELECT FOR UPDATE` on existing bookings for the same collaborator + time window) to serialize concurrent writes.
2. **Persistence-level conflict guard**: if the agent proposes a DB-level invariant, it must genuinely prevent **overlapping** confirmed/pending bookings for the same collaborator. A plain unique constraint on `(collaborator_id, starts_at, ends_at)` is **not** sufficient because it only blocks identical rows, not interval overlap. Postgres `EXCLUDE USING GIST` with `btree_gist` is the canonical declarative invariant for this shape.
3. **Application-level re-check as last resort**: the second availability check already in the code can serve as a fast-fail before hitting the DB constraint.

The database engine is Postgres 16 across all environments (per D-065), so the guard can rely on Postgres-only features such as `SELECT FOR UPDATE` and `EXCLUDE USING GIST`.

Add a concurrency-focused test (or integration test) that simulates two near-simultaneous booking attempts for the same slot and asserts only one succeeds.

---

## R-5 — Deactivated Collaborators Still Appear in Booking Flows (High)

**Source**: REVIEW-1.md issue #4  
**Priority**: High — correctness  
**Status**: **complete**, 2026-04-16 — the original concern ("trashed providers leak into NEW booking flows") was already fully fixed by D-061's shift to `SoftDeletes` on `Provider`. The adjacent gap surfaced during investigation — display code would 500 on historical bookings with a trashed provider — is fixed by D-067 (`Booking::provider()` resolves trashed rows).

### Context

`business_user.is_active` is toggled by the deactivation UI but is never read by the slot engine, the public booking collaborator list, the manual booking collaborator dropdown, or the auto-assignment logic.

### What to deliver

- `SlotGeneratorService::getEligibleCollaborators()` must filter by `is_active = true` on the `business_user` pivot.
- `PublicBookingController::collaborators()` must filter by `is_active = true`.
- The manual booking collaborator dropdown in the dashboard must exclude inactive collaborators.
- Auto-assignment (both `first_available` and `round_robin`) must exclude inactive collaborators.
- Add tests: deactivating a collaborator removes them from the public booking picker and from slot generation results; they can be reactivated and reappear.

---

## R-6 — Timezone Rendering on Customer-Facing Pages (High)

**Source**: REVIEW-1.md issue #5  
**Priority**: High — correctness per SPEC §14  
**Status**: **complete**, 2026-04-16

### Context

The documented rule (D-005, SPEC §14) is that customers always see the business's local timezone. Booking management pages and customer booking lists currently use `new Date(...).toLocaleString()` without a timezone option, which renders in the browser's local timezone instead of the business's.

### What to deliver

- All customer-facing booking pages (`/bookings/{token}` and `/my-bookings`) must display times in the business timezone, not the browser's.
- The business `timezone` field must be passed as a prop to every customer-facing booking page that renders a time.
- A shared formatting utility (already likely used on the dashboard) should be used consistently — the agent should audit existing timezone-aware date utils and extend them to the customer pages, or create one if absent.
- The customer booking list controller must include `business.timezone` in its response.
- Add tests (or update existing ones) that assert a booking at a specific UTC time renders correctly for a business in `Europe/Zurich` when viewed from a client with a different local offset.

---

## R-7 — Collaborator-Choice Policy Enforcement (Medium)

**Source**: REVIEW-1.md issue #7  
**Priority**: Medium  
**Status**: **complete**, 2026-04-16 — server-side enforcement landed; `PublicBookingController::store()` ignores a submitted `provider_id` when `allow_provider_choice = false` and falls through to auto-assignment; public page skips the provider step consistently. (Terminology swap `collaborator_choice` → `provider_choice` per D-061.)

### Context

`allow_collaborator_choice` is respected in the happy-path UI but not enforced server-side. A pre-filtered service URL initializes the booking page to the collaborator step unconditionally. A crafted POST can pass a specific `collaborator_id` even when the business has disabled collaborator choice.

### What to deliver

- `PublicBookingController::store()` must reject or ignore a submitted `collaborator_id` when `business.allow_collaborator_choice = false`, and fall through to automatic assignment instead.
- The public booking page component must skip the collaborator step when `allow_collaborator_choice` is false — including when a service is pre-selected via URL param.
- Add a test: POST with `collaborator_id` to a business with `allow_collaborator_choice = false` → collaborator is ignored, booking is assigned automatically.

---

## R-8 — Calendar Bug Fixes and Mobile Improvements (Medium)

**Source**: REVIEW-1.md issue #8  
**Priority**: Medium — includes a confirmed hydration error  
**Status**: **complete**, 2026-04-16 — nested `<li>` hydration bug fixed; mobile view switcher added; week-view booking items rendered below `sm`. Developer-driven manual QA on real mobile devices remains a carry-over (`docs/HANDOFF.md`).

### Context

- The current-time indicator returns an `<li>` that is nested inside another `<li>` in the week view, producing invalid HTML and a confirmed React hydration warning.
- On small screens, the view switcher is hidden (`hidden md:block`) with no replacement, so mobile users cannot change view.
- Booking items are hidden below `sm` in week view (`hidden sm:flex`), making the week view empty on phones.

### What to deliver

- Fix the nested `<li>` structure in `week-view.tsx` / `current-time-indicator.tsx`. This is not theoretical: it is a **confirmed** browser-log hydration error and should be treated as an immediate correctness fix, not as optional polish.
- The current-time indicator should not be an `<li>` if it is nested inside one, or the week-view grid structure should accommodate it without invalid nesting.
- Add a mobile-friendly view switcher — at minimum a compact dropdown or segmented control visible on small screens replacing the hidden full switcher.
- Ensure booking items in the week view are visible and tappable on mobile — either by adapting the layout or providing a compact card variant below `sm`.
- The day view and month view should be verified for similar mobile issues while the agent is in this area.

---

## R-9 — Popup Embed: Service Pre-Filter and Modal Robustness (Medium)

**Source**: REVIEW-1.md issue #10  
**Priority**: Medium  
**Status**: **complete**, 2026-04-16 — resolved via D-070 (canonical service pre-filter is a URL path segment; applies to iframe, popup, and direct link). Popup became a proper modal (focus trap, scroll lock, Escape to close, single-instance guard). Developer-driven manual QA on keyboard + screen-reader behaviour remains a carry-over (`docs/HANDOFF.md`).

### Context

The popup embed JS snippet (`public/embed.js`) does not support service pre-filtering, even though the docs and the iframe variant both do. The popup also lacks focus management, scroll locking, and a guard against duplicate overlays.

### What to deliver

- `embed.js` must support service pre-filtering using the **same canonical mechanism** the app chooses for the public booking URL and iframe embed. This may be a path segment, a query parameter, or another explicit standard, but the planner should first unify the contract instead of hardcoding a second one only for the popup.
- The embed settings page must generate a per-service popup snippet alongside the existing iframe snippet.
- The popup must be a proper modal: trap focus inside while open, restore focus to the trigger on close, lock body scroll, handle Escape key to close, and prevent duplicate overlays if the trigger is clicked more than once.
- Update the embed settings copy snippets accordingly.

---

## R-10 — Reminder Scheduling: DST Safety and Delayed-Run Resilience (Medium)

**Source**: REVIEW-1.md issue #11  
**Priority**: Medium  
**Status**: **complete**, 2026-04-16

### Context

`SendBookingReminders` calculates due reminders by adding `hours_before` to `now()` in UTC and using a ±5-minute window. This is fragile in two ways: a delayed scheduler run can miss reminders entirely, and DST transitions mean "24 hours before local appointment time" is not always equal to 24 UTC hours before `starts_at`.

### What to deliver

- Reminder eligibility must be calculated relative to the booking's business timezone, not a fixed UTC offset. "24 hours before" means 24 hours before the appointment in the business's local time, accounting for DST.
- The planner must make this semantics explicit in the appropriate topical file listed in `docs/DECISIONS.md`: whether "24 hours before" is defined as wall-clock local time relative to the business timezone, or as an absolute 24-hour interval from `starts_at`. The implementation and tests must then follow that definition consistently.
- The command must be resilient to delayed scheduler runs: use a look-back window (e.g., "due in the last N minutes or the next M minutes") rather than a tight ±5-minute slice, or use a watermark-based approach. The agent should propose the right strategy in the plan.
- Add tests covering: a reminder near a DST boundary, a delayed run that would have missed a tight window, and the existing happy-path tests updated if needed.

---

## R-11 — Rate Limiting on Auth Recovery Endpoints (Medium)

**Source**: REVIEW-1.md issue #12  
**Priority**: Medium — security  
**Status**: **complete**, 2026-04-16

### Context

Magic-link request and password-reset request endpoints are not rate-limited, despite sending email on every call and being publicly accessible. Login already has throttling.

### What to deliver

- `POST /magic-link` and `POST /forgot-password` must have rate limiters applied, segmented by IP and by email address (to prevent both volume attacks and targeted user enumeration).
- The rate limit values should be conservative (e.g., 5 attempts per email per 15 minutes) and configurable via config.
- Add tests for throttle behavior on both endpoints.

---

## R-12 — Dashboard Welcome Links and Copy Drift (Medium)

**Source**: REVIEW-1.md issue #13  
**Priority**: Medium  
**Status**: **complete**, 2026-04-16

### Context

The post-onboarding welcome screen links to `/dashboard/settings/team` and `/dashboard/settings/notifications` which do not exist. The collaborator invite dialog says invites expire in "7 days" while the backend stores `now()->addHours(48)` and the invitation notification email says 48 hours.

### What to deliver

- Replace dead welcome links with real Wayfinder-generated routes pointing to existing pages, or remove the links if there is no equivalent page yet.
- Align all invite-expiry copy (dialog, email, any tooltip or helper text) to derive from the actual configured lifetime — the value should live in one place (a config key or a constant on the invitation model) and be referenced everywhere.

---

## R-13 — Customer Registration Scope Clarification (Medium)

**Source**: REVIEW-1.md issue #14  
**Priority**: Medium — product-fit  
**Status**: **complete**, 2026-04-16

### Context

Customer registration currently requires the email to already exist in the `customers` table (i.e., the person must have made at least one booking first). The SPEC describes optional customer registration as a general capability without this restriction. This may be intentional narrowing or an accidental gap.

### What to deliver

The agent must evaluate and propose one of:

**Option A — Widen the flow**: allow a new customer to register without a prior booking. A new Customer record is created at registration time. When they later make a booking, the existing customer record is linked.

**Option B — Keep the current restriction but make it explicit**: update the SPEC note, add UI copy that makes the restriction visible ("Create an account using the email you booked with"), and ensure the error message is helpful.

The agent should evaluate which option better matches the actual product intent and record the decision in the appropriate topical file listed in `docs/DECISIONS.md`.

---

## R-14 — Notification Delivery and Branding Cleanup (Low)

**Source**: REVIEW-1.md issue #15  
**Priority**: Low  
**Status**: **complete**, 2026-04-16

### What to deliver

- `InvitationNotification` and `MagicLinkNotification` must no longer run synchronously on the request path.
- The preferred direction is post-response delivery for these two interactive flows, using Laravel's `background` or `deferred` execution model, or an equivalent explicit mechanism chosen by the agent at plan time.
- The planner should treat these differently from the `Booking*Notification` classes:
  - `MagicLink` and `Invitation` are user-triggered, immediate UX flows and are good candidates for post-response sending
  - `Booking*Notification` remain good candidates for the real queue because they benefit from worker-based retries and async system processing
- `config/app.php` app name must be updated to `riservo.ch`.
- Mail `from.name` and `from.address` in `config/mail.php` must use riservo branding, not Laravel defaults.
- Audit all notification views for remaining "Laravel" branding and replace.

---

## R-15 — Dependency and URL Generation Cleanup (Low)

**Source**: REVIEW-1.md issue #16, #17  
**Priority**: Low — maintenance hygiene  
**Status**: **complete**, 2026-04-16

### What to deliver

- Remove `axios` from `package.json` (no longer used since Inertia v3 `useHttp`).
- Remove `geist` from `package.json` (Next.js-only, not used).
- Remove `php artisan serve` from `composer dev` script, or replace with a note pointing to Herd.
- Standardize storage URL generation across all controllers to use one consistent approach — either `Storage::disk('public')->url(...)` everywhere or `asset('storage/...')` everywhere. The agent picks one and audits all controllers.
- Standardize host / URL generation across app, emails, embed snippets, and local development assumptions. The planner should explicitly reconcile `APP_URL`, Herd-hosted URLs, local `localhost` usage, and any generated links that currently drift between those environments.
- Logo removal: normalize an empty/removed logo to `null` in the database and delete the stale file when the user clears it in profile settings and onboarding. Prevent orphaned files on removal.

---

## R-16 — Frontend Code Splitting (Performance)

**Source**: REVIEW-1.md issue #9  
**Priority**: Medium — performance  
**Status**: **deferred to BACKLOG**, 2026-04-16 — not a launch blocker. The ~958 kB main JS bundle is cached by the browser after first load, and public booking / auth are the only surfaces where first-paint latency materially matters. Post-launch real-user metrics will tell us whether the FCP cost on those surfaces justifies the refactor. Tracked in `docs/BACKLOG.md` under "R-16 — Frontend code splitting (deferred from ROADMAP-REVIEW-1)".

### Context

The Inertia page resolver uses `import.meta.glob(..., { eager: true })`, causing all pages to be bundled into a single 928 kB JS asset. Every user — including those on the lean public booking page — downloads all dashboard, settings, and calendar code upfront.

### What to deliver

- Switch the Inertia page resolver to lazy loading (`import.meta.glob(..., { eager: false })`), letting Vite split the bundle per page.
- Verify the build output shows multiple smaller chunks rather than one large one.
- Confirm no page breaks due to the change (all existing pages must render correctly).
- The agent should check whether any Inertia-specific configuration (e.g., `resolveComponent`) needs adjustment for the lazy pattern.
- Re-measure and document the resulting bundle sizes in a comment in the plan.

---

## Carry-over architectural notes

These are not separate severity items in this roadmap, but they should stay visible to the planning agent because they were important in REVIEW-1.md and can influence session design.

### Public slug stability

- Business slugs are public identifiers, but the settings UI allows them to change.
- The planner should explicitly evaluate whether old public URLs, bookmarks, and embed snippets need redirect / alias history when a slug changes.
- If this is not addressed in the current work, the planner should at least record it as an intentional deferred follow-up in the plan.

### Booking-flow state persistence

- The public booking flow is intentionally stateful in the browser.
- The planner should explicitly evaluate whether the user can safely refresh, navigate back, or recover progress mid-flow.
- If not addressed now, it should be recorded as a deliberate follow-up so the issue is not lost when only this roadmap is handed off.

---

## Session ordering recommendation

Given dependencies, a sensible execution order is:

1. **R-1** (admin as provider) — unblocks solo businesses, foundational
2. **R-2 + R-3** (tenant context + cross-tenant validation) — security foundation, can be one session
3. **R-4 + R-5** (race condition + deactivated collaborators) — booking correctness, can be one session
4. **R-6 + R-7** (timezone rendering + collaborator-choice enforcement) — customer-facing correctness
5. **R-8 + R-9** (calendar bug + popup embed) — frontend fixes
6. **R-10 + R-11** (reminders + rate limiting) — operational correctness
7. **R-12 + R-13 + R-14 + R-15** (copy drift + customer reg + notifications + cleanup) — polish, can be one session
8. **R-16** (code splitting) — last, standalone frontend performance pass

All tests must remain green after each session. Any session that introduces a new architectural choice must update the appropriate topical decision file listed in `docs/DECISIONS.md`.

---

*This roadmap defines the WHAT. The HOW — implementation approach, specific APIs, migration strategies, and edge case handling — is decided by the agent at plan time. Every session plan must be approved before implementation starts.*
