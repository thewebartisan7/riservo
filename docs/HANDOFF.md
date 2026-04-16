# Handoff

**Session**: R-19 — Existing-user invitation flow + `business_members` schema drift (Round 2, Session C of 3 — final)
**Date**: 2026-04-16
**Status**: Code complete; full Pest suite green (518 passed / 2248 assertions); Pint clean; `npm run build` green; R-19 closed and **Review Round 2 fully closed** in `docs/archive/reviews/ROADMAP-REVIEW-2.md`.

---

## Review Round 2 — CLOSED

All three sessions complete:

- **R-17** — Bookability enforcement (closed 2026-04-16, D-078).
- **R-18** — Slot generator reads snapped buffers (closed 2026-04-16, no new decision).
- **R-19** — Existing-user invitation + `business_members` schema drift (closed this session, D-079).

Round 2 archived to `docs/archive/reviews/`:

- `docs/archive/reviews/REVIEW-2.md`
- `docs/archive/reviews/ROADMAP-REVIEW-2.md`

`docs/reviews/` is empty again, per `docs/reviews/CLAUDE.md`.

---

## What Was Built

Session closes REVIEW-2 **MEDIUM-1** (invitation flow must branch on existing-user) and **INFO-1** (`business_members` uniqueness shape drift vs. D-061). One new decision — **D-079** — covers both items together in `docs/decisions/DECISIONS-AUTH.md`.

Plan: `docs/plans/PLAN-R-19-INVITE-AND-SCHEMA.md`, moved to `docs/archive/plans/` at session close.

### R-19A — Existing-user invitation flow

`InvitationController::accept()` now branches on `User::where('email', $invitation->email)->exists()`:

- **New-user branch** (`acceptAsNewUser`): unchanged contract — create the user, mark email verified, attach membership via the new helper, create a provider row, attach services, `Auth::login()`, pin session.
- **Existing-user branch** (`acceptAsExistingUser`): no `User::create()`; no mutation of `name`, `password`, or `email_verified_at`. Three acceptance states handled:
  - **No session** — `AcceptInvitationRequest` requires only `password`; controller calls `Auth::attempt(['email' => $invitation->email, 'password' => ...])`; failure throws a `ValidationException` on the `password` field so the field-level error renders in the same shape as `/login`.
  - **Session already matches invitation email** — `AcceptInvitationRequest::rules()` returns an empty array (no password required); controller runs the attach directly.
  - **Session does not match** — controller redirects to `invitation.show` with a flash error naming both the current and target emails; the accept page renders a "Sign out and try again" button that posts to the Wayfinder `logout` action.

On success in either branch, `current_business_id` is pinned to the invitation's business, matching the LoginController / MagicLinkController convention (D-063).

**Route relocation**: `GET` and `POST /invite/{token}` leave the `guest` middleware group. `guest` redirects authenticated users to `/dashboard` before the controller runs, which would make the "already signed in as invitee" and "signed in as other user" branches unreachable. The routes now sit in the top-level public routing space in `routes/web.php`.

**Frontend**: `resources/js/pages/auth/accept-invitation.tsx` receives two new props from the controller — `isExistingUser: boolean` and `authUserEmail: string | null` — and renders the four UI states (new user / existing-not-signed-in / existing-signed-in-matches / existing-signed-in-mismatches). Each state uses the same Wayfinder `accept()` action; the mismatch state adds a `logout()` action for the sign-out button.

**Invite-time contract unchanged**: `StaffController::invite()` still accepts emails that already belong to a `User`. Rejection is at the acceptance layer (wrong password → field error; wrong session user → redirect + flash). This matches the roadmap's explicit choice to keep admin workflow frictionless and defer the decision to acceptance.

### R-19B — `business_members` uniqueness + restore-or-create + soft-delete audit

**Migration**: `database/migrations/2026_04_16_100013_update_business_members_unique_index.php` drops `UNIQUE(business_id, user_id)` and installs `UNIQUE(business_id, user_id, deleted_at)`. Matches the `providers` index shape from D-061. Safe against fresh DB + seeder; no data migration needed (project reseeds pre-launch).

**Helper**: `Business::attachOrRestoreMember(User $user, BusinessMemberRole $role): BusinessMember`. Queries `BusinessMember::withTrashed()` for `(business, user)`; if a trashed row exists, restores + updates the role; otherwise attaches a new row. Returns the active pivot.

Every caller that adds a user to a business now routes through the helper:

- `InvitationController::accept()` — both branches.
- `RegisterController::store()` — the initial admin attach on new business registration.

No raw `$business->members()->attach()` outside the helper's body.

**Soft-delete audit**. Eloquent's `BelongsToMany` does NOT auto-apply a pivot model's `SoftDeletes` scope — `BusinessMember` carries the trait, but `$business->members()` and `$user->businesses()` would return trashed pivot rows unless filtered explicitly. Both relations now apply `->wherePivotNull('deleted_at')`. The filter propagates to:

- `Business::admins()` / `Business::staff()` (via `members()`).
- `User::hasBusinessRole()` (via `businesses()`).
- `StaffController::index` / `show` / `invite` / `ensureUserBelongsToBusiness` (all via `members()`).

Other consumers use `BusinessMember::query()`, which applies the model's `SoftDeletingScope` automatically. Verified in-session, unchanged:

- `ResolveTenantContext::resolveMembership()`
- `LoginController::pinCurrentBusiness()`
- `MagicLinkController::verify()`

No consumer intentionally reads trashed rows for historical display in the app today, so there is no "preserve trashed-inclusive behaviour" exception to document.

**Provider semantics** are unchanged. The existing-user branch still calls `Provider::create()` for a fresh row per acceptance. D-061's `UNIQUE(business_id, user_id, deleted_at)` permits one active + any number of trashed provider rows per `(business, user)`, so historical bookings remain attached to their original (possibly trashed) provider via `Booking::provider()->withTrashed()` (D-067).

### Tests added (+10, suite 508 → 518)

**`tests/Feature/Auth/InvitationTest.php` — +5 cases**:

1. *existing user accepting invite does not recreate user or touch user fields* — invite an email already registered to a user in another business; accept with that user's existing password; assert `User` row count unchanged, `name`/`password`/`email_verified_at` untouched, new membership + provider + services present, session pinned to the new business, old membership intact, invitation marked accepted.
2. *existing user invite rejects wrong password and does not attach* — same fixture, submit wrong password; assert `password` field error, guest session, no membership, no provider, invitation not accepted.
3. *cannot accept existing-user invite while signed in as a different user* — `actingAs($other)`; assert redirect back to `invitation.show` with a flash error and no attach.
4. *existing user already signed in as invitee accepts without password* — `actingAs($invitee)`, POST with no password; assert redirect to dashboard, attach ran, session pinned.
5. *accept-invitation page signals new-user vs existing-user branch* — render `GET /invite/{token}` for two invitations (one for a new email, one for an existing user); assert `isExistingUser` Inertia prop matches the user existence check.

**`tests/Feature/Settings/MembershipReEntryTest.php` — +5 cases**:

1. *soft-deleted business_members row does not block a new active row* — insert + soft-delete + insert new; assert both rows coexist under the new index.
2. *`Business::members()` excludes soft-deleted rows* — verifies the `wherePivotNull` fix.
3. *`User::businesses()` excludes soft-deleted rows* — mirror.
4. *`attachOrRestoreMember()` restores a soft-deleted row instead of duplicating* — assert the restored row shares the original `id` and total row count stays at 1.
5. *full cycle: invite → soft-delete membership → re-invite → accept lands on restored row* — exercises the end-to-end R-19A + R-19B integration through the HTTP layer.

All pre-existing cases stay green (including `StaffTest.php`, which still uses `$business->members()->attach()` directly in its pre-existing `'admin can invite staff'` and `'cannot invite existing member'` coverage).

### No scope drift

No unplanned refactors. `SlotGeneratorService`, the R-17 bookability scopes, the R-18 generated-column reads, the D-075 closure-after-response dispatch, and the `bookability` shared prop are all untouched. The `EXPIRY_HOURS` constant remains the single source of truth for invitation expiry.

---

## Current Project State

- **Backend**:
  - `InvitationController::accept()` splits on existing-user and uses the helper for the attach in both branches.
  - `AcceptInvitationRequest` has dynamic rules: password-only for existing-user, empty when the session user already matches the invitation, name+password+confirmation for the new-user branch.
  - `Business::attachOrRestoreMember()` is the single home for adding a user to a business.
  - `Business::members()` and `User::businesses()` filter trashed pivot rows via `wherePivotNull('deleted_at')`.
  - `RegisterController::store()` uses the helper for the initial admin attach.
  - Invitation routes moved out of the `guest` middleware group.
- **Database**: `business_members` now carries `UNIQUE(business_id, user_id, deleted_at)` — same shape as `providers`.
- **Frontend**: `auth/accept-invitation.tsx` renders four states driven by `isExistingUser` + `authUserEmail`. Uses Wayfinder `accept()` and `logout()` actions. Bundle size unchanged (~963 kB main chunk).
- **Config / i18n**: no changes. New user-facing strings flow through `__()` and the existing translation file pipeline.
- **Tests**: full Pest suite green on Postgres — **518 passed, 2248 assertions**. +10 from R-18's baseline of 508.
- **Decisions**: **D-079** recorded in `docs/decisions/DECISIONS-AUTH.md`. No existing decision superseded — D-079 extends D-061 and D-063.
- **Dependencies**: no changes.

---

## How to Verify Locally

```bash
php artisan test --compact
vendor/bin/pint --dirty --format agent
npm run build
```

All three green: **518 passed** (in ~24 s), `{"result":"pass"}`, Vite build in ~700 ms with only the pre-existing large-chunk warning.

Targeted checks:

```bash
php artisan test --compact --filter='InvitationTest|MembershipReEntry'
# → 16 passed

grep -rn "business_members\|->members(\|->businesses(\|BusinessMember::" app/
# → only the helper, the SoftDeletes-aware model-level queries, and the
#   relation definitions remain; no raw attach() outside the helper's body
```

Manual smoke for the D-079 existing-user flow:

1. Register business A as admin `alice@example.com`.
2. Sign out. Register business B as admin `bob@example.com`.
3. As Bob, go to `/dashboard/settings/staff` and invite `alice@example.com`.
4. Copy the invite link from the mail log; open it in a new incognito window.
5. The accept page should say "You already have a riservo.ch account. Sign in to accept." and show a password-only form.
6. Submit Alice's password → redirect to Business B's dashboard. Alice is logged in; session `current_business_id` = B.
7. In a new tab, log in as Alice → lands on Business A's dashboard (her oldest active membership per `ResolveTenantContext`).

---

## What the Next Session Needs to Know

Review Round 2 is fully closed. The next agent returns to the main roadmap: `docs/ROADMAP.md`. No review-remediation work remains open.

### Conventions that future work must not break (post-R-19)

- **D-079 — restore-or-create is the only membership-add path.** No new code may call `$business->members()->attach(...)` directly. Routes through `Business::attachOrRestoreMember($user, $role)`. Rationale: the only way to ship "add user to business" on top of the new uniqueness index without a future uniqueness-violation incident.
- **D-079 — soft-deleted pivot rows stay out of live reads.** `Business::members()` and `User::businesses()` filter `deleted_at`. Any new relation or eager-load on `business_members` must apply the same filter, unless the caller explicitly wants trashed rows for historical display (no such caller exists today). `BusinessMember::query()` is soft-delete-aware via the model scope; use it for direct pivot reads.
- **D-079 — invitation routes are public.** `GET` / `POST /invite/{token}` sit outside `guest` and outside `auth`. Do not wrap them in either — the existing-user branch needs to handle all three session states.
- **Earlier conventions are unchanged**: D-078 (structural bookability single scope, R-17), D-066 read/write symmetry (R-18), D-077/D-076/D-075/D-074/D-073 and earlier remain as described in prior handoffs.
- **Factories / test helpers**: `tests/Pest.php::attachStaff` / `attachAdmin` / `attachProvider` still use raw pivot attaches in test setup. This is acceptable — tests pre-seed clean state and never exercise the re-entry path through the helper via those functions. Tests that need restore-or-create semantics call `Business::attachOrRestoreMember` directly (see `tests/Feature/Settings/MembershipReEntryTest.php`).

---

## Open Questions / Deferred Items

R-19 adds three new `docs/BACKLOG.md` entries under "Tenancy (R-19 carry-overs)":

- **R-2B — Business-switcher UI** in the dashboard header. Post-D-079 multi-business membership is reachable through the invite flow but not yet switchable in-app.
- **Admin-driven member deactivation + re-invite flow**. D-079's restore-or-create helper unblocks this but no UI drives soft-delete of a `business_members` row today.
- **"Leave business" self-serve UX** for staff members. Same prerequisite.

Earlier carry-overs remain unchanged:

- **R-16** — frontend code splitting (deferred, tracked in `docs/BACKLOG.md`).
- R-17 carry-overs: admin email/push notification when a service crosses into unbookable post-launch; richer "provider is on vacation" UX on the public page; per-user banner dismiss / ack history.
- R-9 / R-8 manual QA — carry-over.
- Orphan-logo cleanup — carry-over.
- Profile + onboarding logo upload deduplication — deferred.
- Per-business invite-lifetime override — carry-over.
- Real `/dashboard/settings/notifications` page — no product driver.
- Per-business email branding — post-launch (D-075).
- Mail rendering smoke-test in CI — post-MVP.
- Failure observability for after-response dispatch — post-MVP.
- Customer email verification flow — post-MVP.
- Customer profile page — post-MVP.
- Scheduler-lag alerting — carry-over.
- `X-RateLimit-Remaining` / `Retry-After` headers on auth-recovery throttle — carry-over.
- SMS / WhatsApp reminder channel — SPEC §9 post-MVP.
- Browser-test infrastructure (Pest Browser, Playwright) — carry-over.
- Popup widget i18n — carry-over.
- `docs/ARCHITECTURE-SUMMARY.md` stale terminology — carry-over.
- Real-concurrency smoke test — carry-over.
- Availability-exception race — carry-over.
- Parallel test execution (`paratest`) — carry-over.
- Slug-alias history — carry-over.
- Booking-flow state persistence — carry-over.
