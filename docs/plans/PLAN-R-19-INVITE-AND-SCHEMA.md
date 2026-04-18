---
name: PLAN-R-19-INVITE-AND-SCHEMA
description: "R-19: existing-user invitation flow + business_members schema drift"
type: plan
status: shipped
created: 2026-04-16
updated: 2026-04-16
---

# PLAN-R-19 — Existing-user invitation flow + `business_members` schema drift

- **Round**: REVIEW-2 remediation, Round 2, Session C (final).
- **Roadmap**: `docs/reviews/ROADMAP-REVIEW-2.md` §R-19.
- **Source findings**: `docs/reviews/REVIEW-2.md` MEDIUM-1 (invitation flow) + INFO-1 (schema drift).
- **Related decisions**: D-061 (uniqueness shape + `providers` first-class), D-063 (tenant context), D-075 (closure-after-response dispatch). A single new decision is recorded — see §7.
- **Split**: not split. Single session, single plan. Escape hatch: see §9.
- **Baseline**: post-R-18 state, `508 passed / 2176 assertions`.

---

## 1. Goals

Close the two residual items on the invitation + tenancy surface:

1. **R-19A (MEDIUM-1)** — `InvitationController::accept()` splits on existing-user vs new-user. Existing-user branch never creates or rewrites a `User` row; it authenticates the invitee against their existing password, then attaches membership + provider + services for the invitation's business.
2. **R-19B (INFO-1)** — `business_members` uniqueness becomes `(business_id, user_id, deleted_at)`, matching `providers` per D-061. A `Business::attachOrRestoreMember()` helper is the single home for the restore-or-create re-entry semantic, and every soft-delete-aware reader of `business_members` is audited and fixed in the same session.

No change to the invite-time contract in `StaffController::invite()`: it still accepts existing emails. Rejection moves to the acceptance layer (auth failure or email mismatch), matching the roadmap.

## 2. Clarifications settled (from plan-review)

- **Q1 — Existing-user auth mechanism**: **(a) login required on the accept page.** Aligned with D-006's password-first posture for business users and avoids introducing a second trust-sensitive redemption flow.
- **Q2 — Split clause**: **single session.** Escape hatch in §9 if implementation uncovers more auth/UX branching than expected.
- **Q3 — Helper home**: **(a) method on `Business`**. Same idiom as `Business::removeLogoIfCleared()`; co-located with the `members()` relation it mutates.

## 3. Design

### 3.1 Acceptance branch (R-19A)

`InvitationController::show(string $token)` passes an `isExistingUser` boolean to the Inertia page so the UI renders the correct form. The logic is `User::where('email', $invitation->email)->exists()` — no `User` row is touched.

`InvitationController::accept(AcceptInvitationRequest $request, string $token)` branches:

1. Resolve the pending invitation (existing `findPendingInvitation()` stays).
2. Compute `$isExistingUser = User::where('email', $invitation->email)->exists()`.
3. **If not existing user** — current behaviour, extracted into a private method `createUserAndAttach()`: create user, mark verified, call `Business::attachOrRestoreMember()` (new helper), create provider, attach services, `Auth::login()`, pin session.
4. **If existing user** — new private method `attachExistingUser()`:
   - If no user is currently authenticated: call `Auth::attempt(['email' => $invitation->email, 'password' => $request->password])`. On failure, throw a ValidationException with a `password` field error (reuses Inertia error rendering; no new error page).
   - If a user IS currently authenticated AND their email **matches** `$invitation->email`: proceed without re-auth. No password re-prompt for a session that is already valid.
   - If a user IS currently authenticated AND their email **does not match**: reject with a clear error (`__('You are signed in as :email. Please sign out to accept this invitation as :target.', [...])`). No attach runs. Controller returns a redirect back to `invitation.show` with a flash error; the page includes a "Sign out and try again" action.
   - After successful auth: run the DB transaction — `Business::attachOrRestoreMember($user, BusinessMemberRole::Staff)`, `Provider::create([...])`, attach services — then `$invitation->update(['accepted_at' => now()])`, regenerate session, and pin `current_business_id` to the invitation's business.
   - Do **not** touch the user's `name`, `password`, or `email_verified_at`. The user already authenticated (either by password here or by an existing session), so their email is already verified from their original registration path.

### 3.2 `AcceptInvitationRequest` rules

Single request class with dynamic rules based on invitation lookup. Same endpoint contract as today; the shape of `$request->validated()` differs per branch:

```php
public function rules(): array
{
    $invitation = BusinessInvitation::where('token', $this->route('token'))->first();
    $isExistingUser = $invitation !== null
        && User::where('email', $invitation->email)->exists();

    if ($isExistingUser) {
        return ['password' => ['required', 'string']];
    }

    return [
        'name' => ['required', 'string', 'max:255'],
        'password' => ['required', 'confirmed', Password::defaults()],
    ];
}
```

Rationale: one request class, one endpoint, one routing shape. The `token` is already a route parameter, and the lookup is a single cheap query on an indexed column.

### 3.3 Frontend branch

`resources/js/pages/auth/accept-invitation.tsx` receives `isExistingUser: boolean` and `authUserEmail: string | null` (via `usePage().props.auth`, already shared). Renders three states:

- `isExistingUser === false` → current form (name + password + confirmation). Unchanged.
- `isExistingUser === true && !authUserEmail` → "You already have a riservo.ch account. Sign in to accept" — email (read-only) + password. Submits to the same `accept()` endpoint.
- `isExistingUser === true && authUserEmail === invitation.email` → "You are signed in as {email}. Accept this invitation?" — single "Accept" button, same endpoint, no password.
- `isExistingUser === true && authUserEmail !== invitation.email` → "You are signed in as {a}. This invitation is for {b}." — a "Sign out and try again" link (POST `logout` with a redirect back to the invite URL).

All strings via `__()`. No new notifications, no new routes.

### 3.4 Restore-or-create helper (R-19B)

On `App\Models\Business`:

```php
public function attachOrRestoreMember(User $user, BusinessMemberRole $role): BusinessMember
{
    $trashed = BusinessMember::withTrashed()
        ->where('business_id', $this->id)
        ->where('user_id', $user->id)
        ->first();

    if ($trashed !== null) {
        $trashed->restore();
        $trashed->update(['role' => $role->value]);

        return $trashed;
    }

    $this->members()->attach($user->id, ['role' => $role->value]);

    return BusinessMember::query()
        ->where('business_id', $this->id)
        ->where('user_id', $user->id)
        ->firstOrFail();
}
```

Called from `InvitationController::accept()` in both branches so the new-user path shares the helper (no divergence). The method is idempotent from the caller's perspective.

### 3.5 Migration (R-19B)

New migration: `2026_04_16_XXXXXX_update_business_members_unique_index.php`.

```php
public function up(): void
{
    Schema::table('business_members', function (Blueprint $table) {
        $table->dropUnique(['business_id', 'user_id']);
        $table->unique(['business_id', 'user_id', 'deleted_at']);
    });
}

public function down(): void
{
    Schema::table('business_members', function (Blueprint $table) {
        $table->dropUnique(['business_id', 'user_id', 'deleted_at']);
        $table->unique(['business_id', 'user_id']);
    });
}
```

Safe against the seeder (reseeded pre-launch, no historical duplicates). Mirrors the `providers` shape exactly.

### 3.6 Soft-delete audit

Eloquent's `BelongsToMany` does **not** auto-apply a pivot model's `SoftDeletes` scope — the `BusinessMember` pivot has `use SoftDeletes`, but `$business->members()` currently returns rows regardless of `deleted_at`. This is latent today (no deactivation flow exists), but it becomes live the moment the new unique index allows a restored-after-soft-delete cycle. Fix in the same session:

- `Business::members()` → add `->wherePivotNull('deleted_at')` (and let the existing `admins()`/`staff()` inherit via `$this->members()`).
- `User::businesses()` → add `->wherePivotNull('deleted_at')`.
- `ResolveTenantContext` → already uses `BusinessMember::query()` which applies the model's SoftDeletes scope automatically; verify with a targeted test, no code change.
- `LoginController::pinCurrentBusiness()` + `MagicLinkController::verify()` → both use `BusinessMember::query()->orderBy(...)->first()`, same story — soft-delete-aware by model scope. Verify with tests, no code change.
- `StaffController::index`/`show`/`ensureUserBelongsToBusiness` → flow through `$business->members()`, so they inherit the relation-level fix. No further code change.
- Any other `business_members` consumer in `app/` — none found in the grep (`Business.php`, `User.php`, `BusinessMember.php`, `StaffController.php`, `ResolveTenantContext.php` are the only matches; covered above).

No intentional trashed-inclusive reads exist today. No `withTrashed()` pivot query in `app/`. No historical-trash UI to preserve.

### 3.7 Helper in tests

`tests/Pest.php::attachProvider()` currently does `$business->members()->attach(...)` when the member is not present. After the relation gains `->wherePivotNull('deleted_at')`, the existence check continues to behave correctly because a trashed member will look "missing" to the relation. No helper change needed unless a test wants to exercise the soft-delete-then-reattach cycle, in which case it uses `Business::attachOrRestoreMember()` directly.

## 4. Files to touch

Backend:
- `app/Http/Controllers/Auth/InvitationController.php` — split `accept()`, pass `isExistingUser` from `show()`.
- `app/Http/Requests/Auth/AcceptInvitationRequest.php` — dynamic rules by branch.
- `app/Models/Business.php` — add `attachOrRestoreMember()`, tighten `members()` with `wherePivotNull('deleted_at')`.
- `app/Models/User.php` — tighten `businesses()` with `wherePivotNull('deleted_at')`.
- `database/migrations/2026_04_16_XXXXXX_update_business_members_unique_index.php` — new migration.

Frontend:
- `resources/js/pages/auth/accept-invitation.tsx` — four-state branch (new user / existing not signed in / existing signed in matching / existing signed in mismatching).
- `resources/js/types/index.d.ts` — extend `InvitationData` with `isExistingUser: boolean` if typed; add to shared-prop types if needed.

Tests (new or extended):
- `tests/Feature/Auth/InvitationTest.php` — extend with existing-user branch coverage (+4 tests).
- `tests/Feature/Settings/MembershipReEntryTest.php` — new file (+4 tests).
- `tests/Feature/Settings/StaffTest.php` — no changes expected; existing cases stay green.

Docs:
- `docs/decisions/DECISIONS-AUTH.md` — new decision **D-079** (next free ID; highest live decision is D-078). See §7.
- `docs/HANDOFF.md` — full rewrite at session close; note Round 2 closure.
- `docs/reviews/ROADMAP-REVIEW-2.md` — R-19 → complete with closing date; round status → **CLOSED**; add final "Outcome" line.
- Move `docs/plans/PLAN-R-19-INVITE-AND-SCHEMA.md` → `docs/archive/plans/`.
- Move `docs/reviews/REVIEW-2.md` and `docs/reviews/ROADMAP-REVIEW-2.md` → `docs/archive/reviews/`. Keep the numbered roadmap filename.

## 5. Tests

### R-19A (invitation branch), extending `tests/Feature/Auth/InvitationTest.php`

1. **existing user accepting invite does not recreate user or touch user fields** — pre-create `$existing = User::factory()->create(['email' => 'admin@other.test', 'name' => 'Alice', ...])`; invite that email into `$businessB`; POST accept with `password = 'password'`. Assert:
   - `User::where('email', ...)` count is still 1.
   - `$existing->refresh()->name` is still `'Alice'`, `password` hash unchanged, `email_verified_at` unchanged.
   - `business_members` row for `($businessB, $existing)` exists with role `staff`.
   - `providers` row exists for `($businessB, $existing)`.
   - `service_ids` on the invitation are attached to the provider.
   - Session's `current_business_id === $businessB->id`.
   - Any pre-existing membership in another business is untouched.
2. **cannot accept existing-user invite while signed in as a different user** — `actingAs(User $other)`, POST accept, assert no attach happened and controller responded with a redirect-back + session-flash error containing the mismatch message.
3. **already-signed-in-as-invitee accepts without password** — `actingAs($existing)`, POST accept with no password field, assert attach happened and redirect to dashboard. (Guards against regressing the "already authenticated, no password required" path.)
4. **new user invite still creates user + membership** — existing test `'invitation can be accepted'` kept green; confirm it exercises the new-user branch (name + password provided, `User` created fresh, `attachOrRestoreMember()` called under the hood).
5. **accept-invitation page renders the correct branch** — render `GET /invite/{token}` for an email that does not exist → assert `isExistingUser === false`; render for an email that does exist → assert `isExistingUser === true`.

### R-19B (schema + helper + audit), new `tests/Feature/Settings/MembershipReEntryTest.php`

1. **soft-deleted business_members row does not block a new active row** — insert a membership, `$member->delete()`, attach a new one for the same `($business, $user)`. Assert both rows present in DB.
2. **`Business::members()` excludes soft-deleted rows** — insert + soft-delete one membership + insert another active membership → the relation returns only the active one.
3. **`User::businesses()` excludes soft-deleted rows** — mirror.
4. **`Business::attachOrRestoreMember()` restores an existing soft-deleted row** — insert + soft-delete, call helper, assert no duplicate row and the original row is restored.
5. **full cycle: invite → soft-delete membership → re-invite → accept lands on the restored row, not a duplicate** — pre-create user + membership, soft-delete it, invite same email, accept as existing user, assert `BusinessMember::withTrashed()` count is 1 (restored), not 2 (duplicate).

Total new tests: **9** (4 in InvitationTest + 5 in MembershipReEntryTest). Plus one kept-green regression. Expected suite count: **517 passed** (508 + 9).

## 6. Constraints carried in

From `CLAUDE.md` + D-033 / D-061 / D-063 / D-066 / D-075 / D-076 / D-078 and the HANDOFF file:

- `BusinessInvitation::EXPIRY_HOURS` stays the single source for expiry. Not hardcoded.
- All user-facing strings through `__()`. Any new key registered under `lang/*/messages.*` if needed.
- All datetimes UTC; session pin writes `business_id` only, no timestamps.
- Tenant surfaces use `tenant()->business()` / `tenant()->businessId()`.
- `InvitationNotification` dispatch path is untouched (D-075 closure-after-response preserved). R-19 does not send new notifications.
- No Fortify, no Jetstream. Auth remains custom.
- No `whereHas('providers')` / `whereDoesntHave('providers')` — R-17's `structurallyBookable` scope is untouched.
- D-066 read/write symmetry preserved — nothing in R-19 touches `SlotGeneratorService` or the booking path.

## 7. Decision to record — D-079

New entry at the bottom of `docs/decisions/DECISIONS-AUTH.md`:

```
### D-079 — Existing-user invitation flow + business_members uniqueness parity
- Date: 2026-04-16
- Status: accepted
- Extends: D-061 (uniqueness shape on providers), D-063 (tenant context)
- Context: the invitation flow still assumed every invitee was a brand-new
  user; acceptance crashed on a globally-unique `users.email` collision for
  any invitee already registered elsewhere on the platform. Adjacent to that,
  `business_members` carried `UNIQUE(business_id, user_id)` while `providers`
  carried `UNIQUE(business_id, user_id, deleted_at)` — the two tables that
  together define membership disagreed on the re-entry shape.
- Decision:
  1. `business_members` uniqueness is `(business_id, user_id, deleted_at)`,
     matching D-061 on `providers`. Migration lands in this session.
  2. Membership re-entry is restore-or-create, homed in
     `Business::attachOrRestoreMember(User, BusinessMemberRole): BusinessMember`.
     Every caller that needs to add a user to a business goes through this
     method; no raw `->attach()` for membership outside the helper's body.
  3. `InvitationController::accept()` branches on
     `User::where('email', $invitation->email)->exists()`. The new-user
     branch creates the user as before. The existing-user branch does NOT
     create or mutate the user; it authenticates the invitee and calls the
     helper, creates the provider row, attaches services, and pins
     `current_business_id` to the invitation's business.
  4. Existing-user authentication at acceptance is login-required (password
     against the existing User). Rejected alternative: magic-link redemption
     — rejected because the invitee is almost always an admin of another
     business (password-first per D-006) and a second trust-sensitive
     redemption path would duplicate MagicLinkController flow without
     proportionate UX gain.
- Consequences:
  - Multi-business membership is now reachable through the invite flow
     without touching the existing user's credentials.
  - `Business::members()` and `User::businesses()` apply a
     `wherePivotNull('deleted_at')` filter because Eloquent's BelongsToMany
     does not auto-apply a pivot's SoftDeletes scope. Every consumer of
     those relations is audited in the same session.
  - Session pinning after acceptance writes the invitation's business into
     `current_business_id`, matching the LoginController / MagicLinkController
     pinning convention (D-063).
  - The restore-or-create helper also applies to any future deactivate-then
     -re-invite admin flow (not in MVP; R-2B still deferred).
```

This entry extends D-061 and D-063; it does not supersede either.

## 8. Out-of-scope items (confirm in BACKLOG)

Per the roadmap §"Out of scope":

- Business-switcher UI in the dashboard header — this is R-2B. **Not currently in `docs/BACKLOG.md`** (verified by grep). Add a short entry under a new "Tenancy (R-19 carry-overs)" section at plan-close time.
- Bulk deactivate-and-re-invite admin flows — no admin deactivate flow exists; add to the same BACKLOG section.
- "Leave business" UX for members — same.
- Historical-trash display on the staff settings page — not present today; no preservation needed. Nothing to record.

## 9. Split-clause escape hatch

If implementation reveals unexpected complexity — e.g., the "authenticated-as-different-user" UX needs its own intermediate page, or the `wherePivotNull('deleted_at')` fix cascades into a test-harness change that itself grows, or typed frontend shared props require broader refactoring — I will stop coding, propose a split into R-19A (invite) + R-19B (schema + audit) via a short message, and wait for approval before continuing. I do not expect this to trigger.

## 10. Session-close checklist

- `php artisan test --compact` → green (expected ~517).
- `vendor/bin/pint --dirty --format agent` → `{"result":"pass"}`.
- `npm run build` → green (frontend was touched).
- `docs/HANDOFF.md` rewritten — last R-19 handoff; notes Round 2 closed; points the next session at `docs/ROADMAP.md` rather than the archived roadmap.
- `docs/reviews/ROADMAP-REVIEW-2.md` — R-19 → **complete** (2026-04-16); header Status: **OPEN** → **CLOSED** (2026-04-16); final "Outcome" line mirroring ROADMAP-REVIEW-1.
- D-079 added to `docs/decisions/DECISIONS-AUTH.md`.
- `docs/BACKLOG.md` — new "Tenancy (R-19 carry-overs)" section with R-2B + bulk-deactivate + leave-business entries.
- Move `docs/plans/PLAN-R-19-INVITE-AND-SCHEMA.md` → `docs/archive/plans/`.
- Archive step (after all of the above): move `docs/reviews/REVIEW-2.md` + `docs/reviews/ROADMAP-REVIEW-2.md` → `docs/archive/reviews/`. `docs/reviews/` is left empty (per `docs/reviews/CLAUDE.md`).

---

**Status**: draft, awaiting approval. No code will be written before approval.
