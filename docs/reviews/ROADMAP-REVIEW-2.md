---
name: ROADMAP-REVIEW-2
description: Round 2 remediation roadmap
type: review
status: shipped
created: 2026-04-16
updated: 2026-04-16
---

# riservo.ch — Review Roadmap (Round 2)

> **Status**: **CLOSED**, 2026-04-16
> **Scope**: Remediation of REVIEW-2 findings. REVIEW-2 produced **4 residual issues** on top of the 14/15 closed items from Round 1: 2 High, 1 Medium, 1 Info.
> **Outcome**: R-17, R-18, R-19 complete. All 4 residual findings dispositioned; new decisions D-078 and D-079 recorded. Pest suite green at **518 passed / 2248 assertions**.
> **Baseline**: commit `aee896f02f70b24acc134049bdf94fc83a27617e`; Pest suite green at **496 passed / 2073 assertions**.
> **Source review**: `docs/archive/reviews/REVIEW-2.md`.
> **Previous round**: `docs/archive/reviews/REVIEW-1.md` + `docs/archive/reviews/ROADMAP-REVIEW-1.md` (closed 2026-04-16).
> **Archived as**: `docs/archive/reviews/ROADMAP-REVIEW-2.md`.

---

## Overview

REVIEW-2 confirmed that Round 1 remediation landed cleanly on 14 of 15 non-deferred items. The residual surface is concentrated in two themes:

1. **Bookability consistency** — Round 1 split `providers` into a first-class entity (D-061) and added a launch gate keyed on "provider row exists" (D-062). The gate and the onboarding schedule validation both still allow states where a provider row exists but no bookable slot can ever be produced. The scheduling engine is strict about real availability, so the UI and the pipeline disagree.

2. **Multi-business membership parity** — D-063 made tenant context explicit and kept multi-business membership as a data-model capability. The invitation flow was not updated in the same pass and still assumes every invite creates a brand-new `User`, which breaks as soon as an invited email already exists. The adjacent `business_members` uniqueness index also drifts from D-061.

Both themes are correctness issues, not performance or polish. They also both surface through tests that REVIEW-2 explicitly called out as missing.

Items are grouped into **three sessions**, each sized for a single agent pass:

| Session | Item | Summary | Priority |
| --- | --- | --- | --- |
| A | R-17 | Bookability enforcement (structural vs temporary) | High |
| B | R-18 | Slot generator must read snapped booking buffers | High |
| C | R-19 | Existing-user invitation flow + `business_members` schema drift | Medium / Info |

Sessions are ordered so later work does not need to re-touch surfaces already stabilised: R-17 sets the bookability definition that R-18 and future work can lean on; R-18 aligns read and write paths so a later bookability refactor cannot accidentally reintroduce the mutable-buffer divergence; R-19 closes tenancy loose ends without depending on either.

All tests must remain green after each session. Any session that introduces a new architectural choice must update the appropriate topical decision file listed in `docs/DECISIONS.md`.

---

## R-17 — Bookability enforcement (Session A, High)

**Source**: REVIEW-2 HIGH-1
**Priority**: High — launch can still produce a structurally unbookable public page
**Related decisions**: D-061, D-062 (extended by D-078)
**Status**: **complete** (closed 2026-04-16)

### Context

The R-1/D-062 launch gate uses `Service::whereDoesntHave('providers')` to decide whether onboarding can complete. This blocks the narrow "service has zero providers attached" state but does not catch the adjacent state a solo-business owner can still reach:

- owner opts in as provider in onboarding step 3
- the `provider_schedule` payload passes `StoreServiceRequest` validation even if every day is disabled or every `windows` array is empty
- `OnboardingController::writeProviderSchedule()` then persists zero `availability_rules` rows
- `AvailabilityService::getAvailableWindows()` returns empty for any date, so the public service page renders but never produces a slot

REVIEW-2 explicitly confirmed this with `tests/Feature/Booking/AvailableDatesApiTest.php:83-95`: a provider with no availability rules yields no available dates.

The review also opened a product question: post-launch, a provider may legitimately have no slots for a temporary reason (vacation, full agenda for the visible horizon). That state should not be treated as "broken service". The current code path would conflate the two.

### Bookability definition (to be adopted and recorded as a decision)

This session must introduce a **single definition of structural bookability** and apply it everywhere that today asks "does this service have a provider at all?". The definition is structural only — it does not look at the calendar.

**Structurally bookable service** means all of the following:

1. The service is active (`is_active = true`).
2. At least one non-soft-deleted provider is attached (via `provider_service`) to the service.
3. At least one of those providers has at least one `availability_rules` row for this business.

What is explicitly **not** part of structural bookability:

- Whether slots exist in the next N days.
- Whether the provider has an exception blocking the current week.
- Whether the provider's agenda is full.
- Whether business hours for today happen to be closed.

Those are **temporary unavailability** states and must not trigger the "misconfigured" branch. The distinction keeps misconfiguration and legitimate unavailability separately visible to the admin and to customers.

### What to deliver

**Onboarding (rigid)**

- `StoreServiceRequest` (or an equivalent check in `OnboardingController::storeService`) must reject a `provider_opt_in = true` payload whose `provider_schedule` contains zero enabled windows across all seven days. The user-facing message must explain that the owner needs at least one bookable time window before they can save the service.
- `OnboardingController::writeProviderSchedule()` must not be able to persist a zero-rule state for an opted-in provider in the MVP onboarding path. Either the validation blocks it upstream, or the method itself rejects an empty rule set — the planner picks the cleaner layer but the outcome is the same: no opted-in provider exits step 3 with zero rules.
- The launch gate (`OnboardingController::storeLaunch`) must switch from `whereDoesntHave('providers')` to the structural-bookability query defined above. The blocked-launch payload should list services that fail any of the three structural conditions, not only the "no provider attached" case.

**Post-launch (distinguish structural vs temporary)**

- Public page `/{slug}`: a structurally unbookable service must be hidden or explicitly rendered as "currently unavailable" (the planner chooses the UX that best matches the existing service-list component, and records the choice in the decision). A structurally bookable service with no slots in the visible horizon continues to render normally — it just produces no slots, which is correct existing behaviour.
- Dashboard: when any active service becomes structurally unbookable, the admin must see a strong banner (persistent dashboard-wide, not a toast) that names the affected services and links to the fix. Temporary no-slot states must **not** trigger this banner.
- The banner disappears automatically once all active services become structurally bookable again (no manual dismiss for MVP).

**Centralisation**

- The structural-bookability predicate must live in exactly one place — a model method, scope, or query object — and be reused by:
  - `OnboardingController::storeLaunch`
  - the public `/{slug}` service-listing code path
  - the dashboard banner query
  - any new callers introduced later
- The session must include a test that locks this: an integration-level check that onboarding-launch and the public page and the dashboard banner all agree on the same service set.

**Tests to add**

- An onboarding-step-3 test that posts `provider_opt_in = true` with a schedule of seven disabled days and asserts 422 / redirect-with-errors.
- An onboarding-launch test that creates an active service with a provider row attached but zero `availability_rules` and asserts launch is blocked.
- A public-page test that creates the same state and asserts the service is hidden / marked unavailable on `/{slug}`.
- A dashboard banner test that creates the same state and asserts the banner renders with the affected service listed.
- A regression test that confirms a provider who is **temporarily** unavailable (availability rules exist but next N days are fully booked or exception-blocked) does **not** trigger the banner and does **not** hide the service.

### Out of scope (record in `docs/BACKLOG.md`)

- Email/push notification to the admin when a service crosses into structurally-unbookable post-launch.
- Auto-hiding services when a provider's attachment is removed by another admin (D-062 already covers this, but the specific notification story is backlog).
- A richer "provider is on vacation" UX on the public page.

### Decision to record

A new decision in `docs/decisions/DECISIONS-DASHBOARD-SETTINGS.md` (or a topical split at the planner's discretion) that:

1. Names the structural-bookability predicate and its canonical location.
2. States the three structural conditions.
3. States explicitly that temporary unavailability is **not** part of the definition.
4. States the public-page behaviour (hide vs mark unavailable — planner's choice) and the dashboard-banner behaviour.

This extends D-062; it does not supersede it. D-062's core rule ("launch requires at least one eligible provider per active service") stands — R-17 sharpens the definition of "eligible".

---

## R-18 — Slot generator must read snapped booking buffers (Session B, High)

**Source**: REVIEW-2 HIGH-2
**Priority**: High — UI and DB-level invariants can diverge on buffer edits
**Related decisions**: D-066 (snapshot buffers + EXCLUDE GIST); no new decision required, this is a consistency fix
**Status**: **complete** (closed 2026-04-16)

### Context

D-066 moved the booking overlap invariant to a Postgres `EXCLUDE USING GIST` constraint over the generated `effective_starts_at` / `effective_ends_at` columns, which are derived from `bookings.buffer_before_minutes` / `bookings.buffer_after_minutes` — the **snapped** buffer values captured at booking time. Both controller write paths (`PublicBookingController::store`, `Dashboard\BookingController::store`) correctly persist the snapshot.

`SlotGeneratorService::conflictsWithBookings()` still rehydrates the occupied interval from the **live** service relation:

```php
$bookingBufferBefore = $booking->service->buffer_before ?? 0;
$bookingBufferAfter = $booking->service->buffer_after ?? 0;
```

Consequence: if an admin edits the service's buffers after bookings exist, the slot generator and the DB constraint use different intervals for the same booking. Customers and staff may see slots that `23P01` at create time, or lose slots that should now be available. This breaks D-066's intended "booking is a contract for a specific occupied window" semantic on the read path.

### What to deliver

- `SlotGeneratorService::conflictsWithBookings()` must compute the occupied interval from the booking's own snapped fields. The planner chooses between:
  - reading `buffer_before_minutes` / `buffer_after_minutes` off the booking model and computing start/end inline, or
  - selecting `effective_starts_at` / `effective_ends_at` directly from the DB (they already exist as generated columns) and dropping the local arithmetic entirely.
  The second is cleaner and stays truthful to D-066. The planner records the choice in the plan.
- Any other read-path code that rehydrates occupied intervals from `$booking->service->buffer_*` must be migrated in the same pass. Grep for `buffer_before` / `buffer_after` through `$booking->service` on the read path and migrate each call site.
- The `services` relation on `Booking` should remain eager-loaded where needed for unrelated display reasons (e.g. service name on the calendar card), but the slot-generation math must no longer depend on it.

**Tests to add**

- A regression test that:
  1. Creates a service with `buffer_before = 0`, `buffer_after = 0`.
  2. Creates a confirmed booking for that service.
  3. Edits the service's `buffer_after` to a value large enough to change slot visibility.
  4. Asserts the slot generator returns the same available set as a direct check against the DB exclusion invariant (i.e. the booking still occupies its snapped interval, not the new inflated one).
- A mirror test with the opposite direction (buffers shrunk after booking creation) to confirm previously-blocked slots stay blocked by the snapshot and not silently freed.
- Keep `BookingBufferGuardTest.php` and `BookingOverlapConstraintTest.php` green; extend them if the cleanest regression lives in their shape.

### Out of scope (record or confirm in `docs/BACKLOG.md`)

- Migrating historical bookings that pre-date D-066's schema change. The full data set was reseeded pre-launch; there is no historical backfill gap.
- Re-examining whether `buffer_before` / `buffer_after` on `services` should become a pure read-only display value going forward — tracked as a future consolidation, not a REVIEW-2 deliverable.

---

## R-19 — Existing-user invitation + `business_members` schema drift (Session C, Medium / Info)

**Source**: REVIEW-2 MEDIUM-1 + REVIEW-2 INFO-1
**Priority**: Medium (invitation correctness) + Info (schema consistency)
**Related decisions**: D-061 (uniqueness shape), D-063 (tenant context); a new decision records the existing-user invite semantics
**Status**: **complete** (closed 2026-04-16)

This session bundles two small-to-medium items that share the same surface (`business_members`, invitations, tenancy). The planner should keep the option to split: **if during planning the existing-user-invite branch grows a non-trivial UX / login sub-flow, R-19 may be split into R-19A (invite) and R-19B (schema)**. The roadmap treats them as a single session by default because each is too small alone and they share tests and migrations.

### R-19A — Existing-user invitation flow (MEDIUM-1)

#### Context

After D-063, multi-business membership is a data-model capability: the same user can be attached to more than one business with an explicit, session-pinned tenant. The invitation flow was not updated in the same pass:

- `StaffController::invite()` only rejects emails that are already active invitations or already members of the **current** business. It does not reject emails that are already a `User` globally.
- `InvitationController::accept()` always calls `User::create([...])`. `users.email` is globally unique, so the acceptance path crashes for any invitee whose email already exists anywhere on the platform.

This makes "invite an already-registered user into a second business" a real but broken scenario.

The fix must explicitly split the acceptance flow into **new user** and **existing user** branches. Treating an existing user as "new user with password to set" is wrong and risky — it would either rotate credentials silently or produce a `UNIQUE` violation.

#### What to deliver

**Acceptance path split**

- `InvitationController::accept()` must branch on `User::where('email', $invitation->email)->exists()`:
  - **New user**: current behaviour — create the `User` with the submitted name + password, mark email verified, attach to business, create provider, etc.
  - **Existing user**: do **not** call `User::create()`, do **not** update name or password, do **not** touch the existing `User` row's fields. Attach a `business_members` row for this business, create the provider row, attach services. Pin `current_business_id` in session to the invitation's business.

**Authentication of existing users at acceptance**

- An already-registered user accepting an invite must authenticate before the attach executes. The planner chooses between:
  - **(a) Login required**: the accept page shows a "sign in to accept" form for existing emails and only executes the attach after successful auth. Simple, no new flow.
  - **(b) Magic-link redemption**: the accept URL includes a signed link so the accepter does not need to remember their riservo password. Consistent with existing magic-link capability.
  The planner picks one based on minimum UX friction and records it in the decision. Whatever is chosen must:
  - verify the authenticating user's email matches the invitation's email exactly,
  - reject mismatches cleanly (the invite page must not allow attaching a session user whose email differs from the invitation email),
  - only then run the DB attach transaction and redirect to `dashboard`.

**Invite-time validation**

- `StaffController::invite()` must not change its contract to reject existing emails — that would be the "defer to R-2B" direction we explicitly rejected. It continues to accept existing emails. The rejection happens only at the acceptance layer if auth fails.

**Acceptance UI**

- The `auth/accept-invitation` Inertia page must render differently for the existing-user branch: instead of a "choose name and password" form, it shows a "you're already on riservo.ch — sign in to accept" form (or equivalent for magic-link). The component receives an `isExistingUser` boolean from the controller.

**Tests to add / extend**

- Invite an email that already belongs to a `User`; accept as that existing user; assert:
  - the `User` row is untouched (name, password, email_verified_at unchanged),
  - a `business_members` row exists for the new business,
  - a `provider` row exists for the new business,
  - `service_ids` on the invitation are attached to the new `provider`,
  - `current_business_id` in session equals the new business,
  - the old business's membership is still intact.
- Invite an existing user but attempt to accept while logged in as a **different** user. Assert the attach does not run and a clear error is returned.
- Invite a new (non-existing) email and accept. Assert the legacy "create user + attach" path still works and still passes.
- Existing `InvitationTest.php` and `StaffTest.php` cases remain green.

### R-19B — `business_members` uniqueness shape + membership re-entry semantics (INFO-1)

#### Context

- `providers` correctly use a deleted-at-aware unique index: `(business_id, user_id, deleted_at)`. Soft-deleted rows do not block a new active row for the same `(business, user)`.
- `business_members` live schema defines `UNIQUE(business_id, user_id)` — soft-deleted rows still occupy the slot, so `restore()` is the only re-entry path today.
- D-061, `SPEC.md`, and `ARCHITECTURE-SUMMARY.md` describe the deleted-at-aware shape for both tables.

No user-visible bug today because the product has no "deactivate member then re-invite" flow yet. The drift matters as soon as that flow exists, and the drift itself is a decision / implementation mismatch that will confuse future work.

#### What to deliver

**Schema alignment**

- A new migration drops the `UNIQUE(business_id, user_id)` index on `business_members` and replaces it with `UNIQUE(business_id, user_id, deleted_at)`. Same shape as `providers`.
- The migration must be safe to re-run against the seeder and fresh DB (the project reseeds pre-launch, so there is no data-migration complexity).

**Application semantics**

- The planner adopts **restore-or-create** as the preferred re-entry semantic:
  - If a soft-deleted `business_members` row exists for this `(business, user)`, call `restore()` on it.
  - Otherwise insert a new row.
- The `InvitationController::accept()` existing-user branch (see R-19A) must use this helper instead of a raw `attach()` that would silently fail on the new index if a historical row exists with `deleted_at IS NULL` stuck in a conflicting state.
- Where the helper lives (model method, action class, or service) is a planner call; it must be the single home for this logic.

**Audit of soft-delete-aware reads**

- While touching `business_members`, audit and fix any consumer that reads memberships without accounting for soft-deletes:
  - `Business::members()` — confirm it returns only active rows.
  - `User::businesses()` — confirm the same, especially for the `TenantContext` hydration path.
  - `ResolveTenantContext` middleware and any `tenant()->*` consumer — confirm no surface can end up pinned to a soft-deleted membership.
  - Settings / staff listing code — confirm deactivated members do not appear in the active staff table (but remain retrievable for historical context if any existing UI already supports that).
- Any consumer that leaks soft-deleted members into live flows must be fixed in the same session. No behaviour change to controllers that intentionally include trashed rows for historical display (if any) — document them explicitly.

**Tests to add / extend**

- Insert a `business_members` row, soft-delete it, insert a new row for the same `(business, user)`. Assert the DB accepts both rows under the new index.
- Confirm `Business::members()` excludes soft-deleted rows and `User::businesses()` does the same.
- Confirm the `restore-or-create` helper restores an existing soft-deleted row instead of inserting a duplicate.
- Wire the same helper through the R-19A existing-user invite branch and assert the full invite → soft-delete → re-invite → accept cycle lands on a restored row, not a duplicate.

### Decision to record

A single new decision in `docs/decisions/DECISIONS-AUTH.md` that covers both sub-items together:

1. `business_members` uniqueness is `(business_id, user_id, deleted_at)`, matching D-061 on `providers`.
2. Membership re-entry semantics are **restore-or-create**, homed in a single helper.
3. `InvitationController::accept()` splits on existing-user and uses the helper for the attach.
4. The chosen existing-user authentication mechanism (login-required vs magic-link-redemption).

This decision extends D-061 and D-063; it does not supersede either.

---

## Session ordering recommendation

Given dependencies and risk surface, a sensible execution order is:

1. **R-17** — bookability enforcement. Sets the structural definition everywhere; simplest to work on in an otherwise untouched tree.
2. **R-18** — snapped buffers. Read-path-only refactor with a focused regression test; no dependency on R-17, but it benefits from the stable tree.
3. **R-19** — existing-user invite + schema drift. Touches tenancy and auth; best done last so it does not interleave with bookability or scheduling edits.

Sessions are intentionally independent at the code level. The order above is a risk-reduction recommendation, not a hard dependency.

All sessions must leave `php artisan test --compact` green and `vendor/bin/pint --dirty --format agent` reporting `{"result":"pass"}`. Frontend work (R-17 banner, R-19A accept-invitation page branch) must leave `npm run build` green.

---

## Carry-over architectural notes

These are not separate REVIEW-2 findings, but they remain visible to the planning agent because they influence session design.

### Multi-business switcher UI (R-2B)

R-19A makes multi-business membership reachable through the invite flow in practice, even without a dedicated switcher UI — the session will pin on the invitation's business. The R-2B backlog item (visible business-switcher UX in the dashboard header) remains deferred and is still tracked in `docs/BACKLOG.md`. R-19 must not claim to close R-2B; it only makes the underlying model coherent.

### R-16 (frontend code splitting)

Deferred in Round 1 and still deferred. Not touched by any R-17/R-18/R-19 session. Post-launch real-user metrics remain the decision signal. Tracked in `docs/BACKLOG.md`.

### Slug-alias history and booking-flow state persistence

Both carry over from Round 1's roadmap. Not REVIEW-2 findings. If any R-17/R-19 change touches these surfaces, it must leave them no worse than today; otherwise they remain unchanged.

---

## Session-done checklist (per session)

Each session closes only when:

- All Pest tests pass (`php artisan test --compact`).
- Pint is clean (`vendor/bin/pint --dirty --format agent`).
- Frontend builds (`npm run build`) where the session touched frontend.
- `docs/HANDOFF.md` is rewritten (not appended) to reflect the new state.
- Any new architectural choice is recorded in the appropriate topical file under `docs/decisions/`.
- The completed plan file is moved from `docs/plans/` to `docs/archive/plans/`.
- This roadmap's R-NN status line is updated to **complete** with the closing date.

---

*This roadmap defines the WHAT. The HOW — implementation approach, specific APIs, migration strategy, UX choice for R-17's public-page behaviour, and R-19A's auth mechanism for existing users — is decided by the agent at plan time. Every session plan must be approved before implementation starts.*
