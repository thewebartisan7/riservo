# Handoff

**Session**: MVPC-4 — Provider Self-Service Settings (Session 4 of `docs/roadmaps/ROADMAP-MVP-COMPLETION.md`)
**Date**: 2026-04-17
**Status**: Code complete; Feature + Unit suite **669 passed / 2758 assertions** (post-MVPC-3 baseline 638, +31 cases). Pint clean. `npm run build` green. Wayfinder regenerated. Plan archived to `docs/archive/plans/PLAN-MVPC-4-PROVIDER-SETTINGS.md`.

> Full-suite Browser/E2E run is the developer's session-close check (`tests/Browser` takes 2+ minutes). The iteration loop used `php artisan test tests/Feature tests/Unit --compact` throughout.

---

## What Was Built

MVPC-4 opens two settings surfaces to staff (not just admins): **Account** (profile / password / avatar — admin + staff) and **Availability** (weekly schedule + exceptions — admin + staff with an active Provider row; services edit stays admin-only). The previously-misnamed `AccountController` (which actually managed admin-as-own-provider availability) was refactored in place: profile/password/avatar moved into it, schedule/exception/services lifted to a new `AvailabilityController`. A typo-recovery flow on the verify-email page lets users fix a wrong email without admin intervention.

Plan: `docs/archive/plans/PLAN-MVPC-4-PROVIDER-SETTINGS.md`. Four new decisions:

- **D-096** — Account/Availability route split. `AccountController` is profile/password/avatar (shared admin+staff) plus admin-only `toggleProvider`. `AvailabilityController` is schedule/exceptions (shared admin+staff with active Provider row) plus admin-only `updateServices`. Form requests are shared between admin-side `ProviderController` and self-side `AvailabilityController`; auth is enforced by route middleware; controllers re-derive the active provider from `auth()->user()` so a misconfigured route can't write to another person's data. D-079 lockout still works without new middleware (`ResolveTenantContext` queries SoftDeletes-scoped `BusinessMember::query()`).
- **D-097** — Email change commits immediately, nulls `email_verified_at`, dispatches the standard Laravel `VerifyEmail` notification. No shadow staging table. Typo-recovery via a new auth-only `POST /email/change` endpoint backing an inline form on `verification.notice`. Cross-references DECISIONS-AUTH D-038.
- **D-098** — Password update branches on null vs non-null `password` column. `UpdateAccountPasswordRequest` reads server-side state to decide whether `current_password` is required. Magic-link-only users set first password without it; subsequent changes require it. Frontend reads the new `hasPassword` Inertia prop to render the right form.
- **D-099** — `auth.has_active_provider` shared Inertia prop drives Availability nav visibility for both admin and staff. Computed lazily in `HandleInertiaRequests::share()`. Page-level defense: `AvailabilityController.show` aborts 404 when no active provider row.

### Backend

- **New** `App\Services\ProviderScheduleService` — `buildScheduleFromBusinessHours`, `buildScheduleFromProvider`, `writeProviderSchedule`, `writeFromBusinessHours`. Extracted from the legacy AccountController so AccountController.toggleProvider (seeds new provider's schedule) and AvailabilityController.updateSchedule consume one implementation.
- **New** `App\Http\Controllers\Dashboard\Settings\AvailabilityController` — `show`, `updateSchedule`, `storeException`, `updateException`, `destroyException`, `updateServices`. Every action derives the active provider from `auth()->user() + tenant()` and aborts 404 if no active row. Service edit (admin-only via routing) sits next to the others to keep the controller cohesive.
- **Refactored** `App\Http\Controllers\Dashboard\Settings\AccountController` — now `edit` (profile/password/avatar payload), `updateProfile`, `updatePassword`, `uploadAvatar`, `removeAvatar` (shared admin+staff) plus the kept admin-only `toggleProvider`. Old `updateSchedule` / `*Exception` / `updateServices` methods removed (moved to `AvailabilityController`).
- **New** `App\Http\Requests\Dashboard\Settings\UpdateAccountProfileRequest` — name + email rules; `unique:users,email,{actor.id}`.
- **New** `App\Http\Requests\Dashboard\Settings\UpdateAccountPasswordRequest` — branches on `auth()->user()->password === null` (D-098).
- **New** `App\Http\Requests\Auth\ChangeEmailRequest` — slim email-only validator for the typo-recovery endpoint.
- **New** `App\Http\Controllers\Auth\EmailVerificationController::changeEmail` — auth-only (NOT verified-required) handler that updates the email, nulls verified_at, dispatches verification, flashes a status message back to the notice page.
- **Modified** `UpdateProviderScheduleRequest`, `StoreProviderExceptionRequest`, `UpdateProviderExceptionRequest`, `UpdateProviderServicesRequest` — `authorize()` relaxed to `return true`. PHPDoc updated to point at route middleware as the gate (D-096 §3).
- **Modified** `App\Http\Middleware\HandleInertiaRequests` — added `auth.has_active_provider` resolver (D-099).

### Routes

`routes/web.php` restructure within the existing dashboard sub-groups (D-081):

- **Admin-only** `dashboard/settings` sub-group: `account/toggle-provider` (POST) and **new** `availability/services` (PUT). Removed: old `account/schedule`, `account/exceptions*`, `account/services` route names — they're replaced by `availability/*` in the shared sub-group.
- **Shared** `dashboard/settings` sub-group: GET `account` + 4 mutating account routes (profile, password, avatar POST/DELETE), GET `availability` + 4 mutating availability routes (schedule, exceptions POST/PUT/DELETE).
- **Auth-only (top-level)**: new `POST /email/change` (`verification.change-email`), throttled `6,1`. Lives in the `auth` middleware group, NOT inside `verified` — the whole point is to recover from a wrong address that locks the user behind verification.

Route count: **6 Account routes** + **5 Availability routes** + **1 Email-change route**.

### Frontend

- **Rewritten** `resources/js/pages/dashboard/settings/account.tsx` — three sections (Profile, Password, Avatar) plus admin-only "Bookable provider" toggle section. Profile + Password use `<Form>` with render props. Avatar upload uses `useHttp` for multipart; avatar remove uses `<Form action={...} method="delete">` with an `onSuccess` callback to clear the local preview URL.
- **New** `resources/js/pages/dashboard/settings/availability.tsx` — Schedule (existing `WeekScheduleEditor`), Exceptions (existing `ExceptionDialog`), Services (admin: checkbox edit; staff: read-only badges + helper text "Ask an admin to update which services you perform").
- **Updated** `resources/js/components/settings/settings-nav.tsx` — items support `requiresActiveProvider` flag; both admin and staff nav include Account + Availability + Calendar Integration. Availability hidden when `auth.has_active_provider === false`.
- **Updated** `resources/js/pages/auth/verify-email.tsx` — adds an inline "Wrong email? Change it." form posting to `verification.change-email`. Renders the current email in the descriptive copy. Hidden by default; revealed on link click.
- **Updated** `resources/js/types/index.d.ts` — `auth` extended with `has_active_provider: boolean`.

### Tests

New + rewritten Settings tests:

- **`tests/Feature/Settings/SettingsAuthorizationTest.php`** — 9 cases (was 4). New: staff with provider can access availability; staff without provider 404s on availability; staff cannot toggle-provider on themselves; staff with provider cannot edit services; soft-deleted staff cannot reach any settings page (D-079 lock); admin admin-only matrix moves Account out and adds Availability + admin-only sub-actions.
- **`tests/Feature/Settings/AccountTest.php`** — REWRITTEN (was 16 schedule/exception cases). Now 20 cases covering: view (admin + staff + magic-link-only), profile update (admin + staff), email change re-verification (positive + null-out + uniqueness + same-email no-op), password (rejects without current, rejects wrong current, accepts correct, magic-link-only first-set, post-set requires current), avatar (upload + JSON shape + replace deletes old + image validation + remove deletes file + nulls user.avatar), toggle-provider preserved (creates with services, warns on unstaffed, restores soft-deleted).
- **`tests/Feature/Settings/AvailabilityTest.php`** — NEW, 13 cases. Covers: admin self-provider view + staff self-provider view (canEditServices flag); 404 on no provider row (GET + schedule update); admin + staff schedule update; staff full exception CRUD (store + update + destroy); cross-provider exception edit/delete forbidden in same business; cross-business exception forbidden (tenant pinning); admin services update + foreign-id rejection; staff services update forbidden (admin-only); invalid window rejected.
- **`tests/Feature/Billing/ReadOnlyEnforcementTest.php`** — extended dataset with 7 new mutating Account + Availability rows (profile, password, avatar POST/DELETE, schedule, exception store, services). Added a positive read-test asserting `/account` and `/availability` GET pass through for read-only admins. The structural route-introspection test remains green: every new mutating route inherits `billing.writable` from the outer group.

### Decisions recorded

D-096 through D-099 in `docs/decisions/DECISIONS-DASHBOARD-SETTINGS.md`. D-097 cross-referenced in `docs/decisions/DECISIONS-AUTH.md` D-038 (extension paragraph).

---

## Current Project State

- **Backend**:
  - `App\Models\Business` — unchanged.
  - `App\Models\User` — unchanged (still `password` nullable from the original migration; verified by D-098).
  - `App\Services\ProviderScheduleService` — new shared schedule helpers.
  - `App\Http\Controllers\Dashboard\Settings\AccountController` — refactored: profile/password/avatar + admin-only toggle-provider.
  - `App\Http\Controllers\Dashboard\Settings\AvailabilityController` — new: schedule + exceptions + services.
  - `App\Http\Controllers\Auth\EmailVerificationController` — added `changeEmail` for typo recovery.
  - `App\Http\Middleware\HandleInertiaRequests` — added `auth.has_active_provider` resolver.
  - `App\Http\Requests\Auth\ChangeEmailRequest` — new.
  - `App\Http\Requests\Dashboard\Settings\UpdateAccountProfileRequest` — new.
  - `App\Http\Requests\Dashboard\Settings\UpdateAccountPasswordRequest` — new (D-098 branching).
  - `App\Http\Requests\Dashboard\Settings\Update*Request` (4 provider FormRequests) — `authorize()` relaxed.
- **Routes**: 12 new/changed dashboard settings routes (matrix in D-096 §2). 1 new auth-level route (`verification.change-email`).
- **Frontend**: 2 rewritten pages (`account.tsx`, `verify-email.tsx`), 1 new page (`availability.tsx`), 1 updated component (`settings-nav.tsx`), types extended.
- **Tests**: Feature + Unit **669 passed / 2758 assertions** (+31 cases over MVPC-3 baseline 638). Browser suite untouched. The structural billing.writable invariant test remains green.
- **Decisions**: D-096..D-099 recorded in `DECISIONS-DASHBOARD-SETTINGS.md`. D-038 cross-reference added in `DECISIONS-AUTH.md`. No existing decision superseded.
- **Dependencies**: no new packages.

---

## How to Verify Locally

```bash
php artisan test tests/Feature tests/Unit --compact     # 669 passed (iteration loop)
php artisan test --compact                              # full suite incl. Browser (run by developer at close)
vendor/bin/pint --dirty --format agent                  # {"result":"pass"}
php artisan wayfinder:generate                          # idempotent
npm run build                                           # green, ~1.3s
```

Targeted:

```bash
php artisan test tests/Feature/Settings --compact                           # 9 + 20 + 13 = 42 settings cases (excl. ProfileTest, ProviderTest, StaffTest)
php artisan test tests/Feature/Billing/ReadOnlyEnforcementTest.php --compact # 26 cases incl. 7 new Account/Availability mutations
php artisan route:list --path=dashboard/settings/account                    # 6 routes
php artisan route:list --path=dashboard/settings/availability               # 5 routes
```

Manual smoke (recommended before next session):

1. **Account profile change** (any flow):
   - Sign in as admin or staff; visit `/dashboard/settings/account`.
   - Change name → assert flash success.
   - Change email → assert flash includes the new email + a verification link arrives in MailHog (or your local mail driver) at the new address.
   - Hit any dashboard route → 302 to `/email/verify`. Click the link in MailHog → restored.
2. **Typo recovery**:
   - Change email to a deliberate typo. Land on `/email/verify`. Click "Wrong email? Change it." → submit correct email → flash status, fresh link sent.
3. **Password set / change**:
   - Magic-link-only user (use a freshly-invited staff who never set a password): visit Account → "Set password" form (no current_password field) → submit → assert "Password set." flash.
   - Same user revisits Account → "Change password" form (current_password required now).
4. **Availability self-service**:
   - Admin without provider toggle: Account → flip "Bookable provider" on → Availability appears in nav.
   - Visit Availability → see weekly schedule (seeded from business hours) + zero exceptions + service checkboxes.
   - Edit schedule → save → reload → assert persisted.
   - Add an exception → assert appears in list.
   - As staff with a provider: same flow but services are read-only badges + helper text.
5. **Read-only billing gate**:
   - Cancel the business's subscription via Stripe CLI to read_only state.
   - Try to update Account profile / Availability schedule → 302 to `/dashboard/settings/billing` with error flash.
   - GET `/dashboard/settings/account` and `/dashboard/settings/availability` still render normally (safe verbs pass through).

---

## What the Next Session Needs to Know

Next up: **Session 5 — Advanced Calendar Interactions** in `docs/roadmaps/ROADMAP-MVP-COMPLETION.md`. Session 5 is the last session in this roadmap and ships drag/resize/click-to-create on the dashboard calendar plus REVIEW-1 issue #8 fixes (nested `<li>` hydration error in `current-time-indicator.tsx`/`week-view.tsx`, mobile view-switcher).

### Conventions MVPC-4 established that future work must not break

- **D-096 — `AccountController` is identity, `AvailabilityController` is schedule/exceptions.** Any future "edit my own provider data" code paths use `AvailabilityController`, not `AccountController`. The admin-only "be your own provider" toggle stays on Account (it answers "am I bookable?", which is identity).
- **D-096 — FormRequest `authorize()` is `return true` for shared validation rules.** When two controllers (admin-side and self-side) share validation, the FormRequest must NOT carry role-based authorize logic. Route middleware is the gate; the controller re-derives the actor's resource. Adding role checks back to a shared FormRequest is a regression because it implicitly forks the validation contract.
- **D-097 — Email changes go through the `MustVerifyEmail` flow, not a custom flow.** `$user->sendEmailVerificationNotification()` is the entry point. Any new "change email" call site must use it; do not invent a parallel verification pipeline.
- **D-098 — `current_password` validation reads server state.** When adding new password-change UX (e.g., a forced rotation flow), branch on `auth()->user()->password === null` server-side, not on a request flag.
- **D-099 — `auth.has_active_provider` is the single truth on bookability for the actor.** Any new UI surface that should appear/hide based on "am I a provider?" reads this prop. Server-side, the same predicate is one query: `Provider::where(...)->exists()` (auto-applies SoftDeletes scope).
- **`ProviderScheduleService` is the only home for schedule build/write logic.** Any new caller that needs to seed/read/persist a provider's weekly availability must go through this service. Reproducing the day-grouping logic inline is a duplication smell.
- **`auth.user.has_active_provider` is computed lazily via Inertia closure.** Adding a non-lazy `auth.user.has_active_provider` (eager) would re-introduce the per-request DB hit on routes that don't render the settings nav. Keep it as `fn () => $this->resolveHasActiveProvider($request)`.

### MVPC-4 hand-off notes

- **Old route names removed**: `settings.account.update-schedule`, `settings.account.store-exception`, `settings.account.update-exception`, `settings.account.destroy-exception`, `settings.account.update-services`. Anything that imports from `@/actions/App/Http/Controllers/Dashboard/Settings/AccountController` for these actions will break — they now live on `AvailabilityController`. Wayfinder regen during this session catches it.
- **`AccountController.edit` payload shape changed**. Old keys (`schedule`, `exceptions`, `services`, `upcomingBookingsCount`) are gone. New keys: `user`, `hasPassword`, `isAdmin`, `isProvider`, `hasProviderRow`. Any test that asserted the old shape would have failed (and did during cluster 5; rewritten).
- **`verification.notice` page now reads `currentEmail` from props.** Any future change to `EmailVerificationController::notice` must keep returning that prop or the verify-email page will lose its "we sent a link to {email}" copy.
- **Avatar upload pattern matches `StaffController.uploadAvatar` exactly** — same validation rule (`File::image()->max(2048)->types(['jpg','jpeg','png','webp'])`), same JSON `{ path, url }` response. If `StaffController.uploadAvatar` evolves (e.g. per-business avatars per D-022 future), the self-upload endpoint should evolve in lockstep.
- **D-097 typo-recovery endpoint sits OUTSIDE `verified` middleware**. This is intentional — without that, the user can't reach the page to fix the typo. The trade-off is that an attacker with a session token could change the user's email. We accept this because (a) the email change requires uniqueness validation, (b) a session token holder can already change everything else on the dashboard, and (c) the new email goes through verification before granting access to the dashboard.

---

## Open Questions / Deferred Items

New from MVPC-4:
- **Admin-driven email recovery from the database**. If a user typos their email AND logs out before clicking "Wrong email? Change it.", they're stuck at login (the magic link goes to the typo address, password reset goes to the typo address). Recovery requires manual DB intervention or admin contacting the user out-of-band. Tracked as a backlog item.
- **Per-business avatar opt-in (D-022 follow-up)**. Avatar is on `users.avatar`, one per person across all businesses. If multi-business membership becomes a real product flow, may want per-business avatars.

Earlier carry-overs unchanged from MVPC-3 hand-off:
- Tighten billing freeload envelope (`past_due` write-allowed window — Stripe dunning).
- Tenancy (R-19 carry-overs): R-2B business-switcher UI; admin-driven member deactivation + re-invite; "leave business" self-serve.
- R-16 frontend code splitting (deferred).
- R-17 carry-overs: admin email/push notification on bookability flip; richer "vacation" UX; banner ack history.
- R-9 / R-8 manual QA.
- Orphan-logo cleanup.
- Profile + onboarding logo upload deduplication.
- Per-business invite-lifetime override.
- Real `/dashboard/settings/notifications` page.
- Per-business email branding.
- Mail rendering smoke-test in CI.
- Failure observability for after-response dispatch.
- Customer email verification flow.
- Customer profile page.
- Scheduler-lag alerting.
- `X-RateLimit-Remaining` / `Retry-After` headers on auth-recovery throttle.
- SMS / WhatsApp reminder channel.
- Browser-test infrastructure beyond Pest Browser.
- Popup widget i18n.
- `docs/ARCHITECTURE-SUMMARY.md` stale terminology.
- Real-concurrency smoke test.
- Availability-exception race.
- Parallel test execution (`paratest`).
- Slug-alias history.
- Booking-flow state persistence.
