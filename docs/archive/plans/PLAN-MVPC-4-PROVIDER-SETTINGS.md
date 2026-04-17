# PLAN-MVPC-4 — Provider Self-Service Settings

> Session 4 of `docs/roadmaps/ROADMAP-MVP-COMPLETION.md`.
> Status: Draft, awaiting developer approval.
> Baseline: Feature + Unit suite **638 passed / 2640 assertions** (post-MVPC-3).

---

## Context

Sessions 1–3 of the MVP completion roadmap shipped:
- MVPC-1 (commit `5388d8a`): Google OAuth foundation. Settings access split (D-081) created a shared `admin+staff` sub-group inside the dashboard route file. Today only Calendar Integration sits in that group.
- MVPC-2 (commit `132535e`): Bidirectional Google Calendar sync.
- MVPC-3 (commit `8da1c5d`): Cashier subscription billing. The `billing.writable` middleware (D-090) wraps every mutating dashboard route.

This session opens two more settings surfaces to admins **and** staff:

- **Account** (admin + staff) — own profile, password, avatar.
- **Availability** (admin + staff with an active Provider row) — own schedule, exceptions, service attachments (display-only for staff; editable for admin-as-self-provider).

Both surfaces exist only as admin-only flows today, with the additional twist that the file currently named `Dashboard\Settings\AccountController` is **not** an account-profile controller at all — it's actually the admin-as-own-provider availability controller (toggle, schedule, exceptions, services). This session repurposes the name to fit the product surface and moves the schedule/exception/services bodies to a new `AvailabilityController`.

Locked decisions binding this session (per `ROADMAP-MVP-COMPLETION.md` "Cross-cutting decisions"):

- #8 — Calendar Integration, Account, and Availability are accessible to `admin` and `staff` (when staff has a Provider row). Account + Availability extend the D-081 shared route group.
- D-061 — Staff role does not imply bookability. A staff user without an active Provider row sees Account + Calendar Integration but not Availability.
- D-062 — Admin opted in as their own provider edits their own data through this page.
- D-067 — Historical-provider semantics. Out of scope this session.
- D-079 — Soft-deleted member rows resolve to no tenant; `EnsureUserHasRole` aborts. No new middleware needed (verified in audit below).
- D-090 — `billing.writable` gates every mutating dashboard verb. New mutating routes inherit it because they live inside the gated group.

---

## Goal

Open Account and Availability for self-service, while keeping the destructive / commercial controls (toggle a person bookable, change which services they perform) admin-only.

End state:

- Staff with a Provider row manage their own schedule and exceptions without an admin's help.
- Staff without a Provider row see Account but not Availability.
- Every user (admin or staff) updates their own profile, password, and avatar from a real Account page.
- The admin-as-own-provider experience is preserved — same toggle on the Account page, same services-edit (admin-only) on the Availability page.
- Read-only businesses (`subscription_state = read_only`) can read both pages but cannot mutate (302 to `settings.billing`).

---

## Scope

### In
- Refactor `Dashboard\Settings\AccountController` to manage profile / password / avatar (admin + staff). Keep its admin-only `toggleProvider` action.
- New `Dashboard\Settings\AvailabilityController` for schedule / exceptions / services. Schedule + exceptions are admin + staff with a Provider row; services edit is admin-only.
- Restructure `routes/web.php` per the audit matrix below. New shared sub-actions land in the existing D-081 shared group; admin-only carve-outs stay in the admin-only sub-group.
- Email re-verification on email change via the standard `MustVerifyEmail` flow (commit + null `email_verified_at` + dispatch the existing verification notification).
- Password update branches on null vs non-null `password` column (magic-link-only users set first password without `current_password`; everyone else changes with it).
- Avatar upload/remove via new self-scoped endpoints (immediate-upload pattern from D-042).
- New shared Inertia prop `auth.has_active_provider` drives Availability nav visibility.
- Test contract evolution: `SettingsAuthorizationTest` matrix update, `AccountTest` rewrite, new `AvailabilityTest`, `ReadOnlyEnforcementTest` dataset extension.
- Record D-096 through D-099 in `docs/decisions/DECISIONS-DASHBOARD-SETTINGS.md`.

### Out
- Refactoring admin-side `StaffController`, `ProviderController`, `BillingController`.
- Exposing business-level settings (Profile, Booking, Hours, Exceptions, Services, Staff, Embed, Billing) to staff.
- Letting staff edit which services they perform.
- D-067 historical-provider semantics (admin-side path).
- Anything from Session 5 (drag/resize/click-to-create on the calendar, mobile view-switcher polish, REVIEW-1 issue #8 nested-`<li>` fix).
- Moving `WeekScheduleEditor` out of `resources/js/components/onboarding/` (works fine where it is; rename is a low-value follow-up).

---

## Audit matrix — current settings routes vs Session 4 target

The roadmap explicitly demands this table land in the plan before any code change. Implementing agent reads it to know which routes move and which stay.

| Path | Verb(s) | Today | Session 4 | Notes |
|------|---------|-------|-----------|-------|
| `/dashboard/settings/profile` | GET, PUT, POST `/logo`, POST `/slug-check` | admin | admin | Business profile — stays admin-only |
| `/dashboard/settings/booking` | GET, PUT | admin | admin | Stays |
| `/dashboard/settings/hours` | GET, PUT | admin | admin | Business-level working hours |
| `/dashboard/settings/exceptions` | GET, POST, PUT/{}, DELETE/{} | admin | admin | Business-level exceptions (not provider) |
| `/dashboard/settings/services` | full CRUD | admin | admin | Stays |
| `/dashboard/settings/staff` | full CRUD + invite + avatar | admin | admin | Stays |
| `/dashboard/settings/providers/{provider}/*` | toggle, schedule, services, exceptions | admin | admin | Admin-managing-others path stays |
| `/dashboard/settings/embed` | GET | admin | admin | Stays |
| `/dashboard/settings/billing` | GET + 4 POST | admin (outside `billing.writable`) | admin (unchanged) | Per D-090 |
| `/dashboard/settings/calendar-integration/*` | 7 routes | admin + staff | admin + staff | Shared group from D-081 |
| **`/dashboard/settings/account`** | **GET** | admin (today: provider/schedule payload) | **admin + staff** (NEW: profile/password/avatar payload) | Repurposed |
| `/dashboard/settings/account/profile` | PUT | n/a | admin + staff | NEW |
| `/dashboard/settings/account/password` | PUT | n/a | admin + staff | NEW |
| `/dashboard/settings/account/avatar` | POST, DELETE | n/a | admin + staff | NEW (separate self-scoped endpoint) |
| `/dashboard/settings/account/toggle-provider` | POST | admin | admin (unchanged) | Stays admin-only |
| `/dashboard/settings/account/schedule` | PUT | admin | **removed** | Body moves to `availability/schedule` |
| `/dashboard/settings/account/exceptions*` | POST, PUT/{}, DELETE/{} | admin | **removed** | Bodies move to `availability/exceptions*` |
| `/dashboard/settings/account/services` | PUT | admin | **removed** | Body moves to `availability/services` (admin-only) |
| **`/dashboard/settings/availability`** | **GET** | n/a | **admin + staff w/ active Provider row** | NEW page |
| `/dashboard/settings/availability/schedule` | PUT | n/a | admin + staff w/ active Provider row | NEW |
| `/dashboard/settings/availability/exceptions` | POST | n/a | admin + staff w/ active Provider row | NEW |
| `/dashboard/settings/availability/exceptions/{exception}` | PUT, DELETE | n/a | admin + staff w/ active Provider row | NEW |
| `/dashboard/settings/availability/services` | PUT | n/a | **admin only** | Self-provider services edit; staff sees read-only |

### D-079 verification — soft-deleted members already locked out
- `BusinessMember` uses `SoftDeletes`. The model's default query auto-applies `SoftDeletingScope`.
- `ResolveTenantContext::resolveMembership` uses `BusinessMember::query()` for both the pinned-id lookup (line 67) and the fallback (line 77). Both are scoped, so a soft-deleted member resolves to `null` → no tenant → `EnsureUserHasRole` aborts 403.
- **Conclusion**: no new middleware needed. Add an explicit `SettingsAuthorizationTest` case to lock the behaviour.

---

## Key design decisions

### Decision 1 — Refactor existing `AccountController`, do not run two parallel controllers
The current `AccountController` is misnamed: every action it owns (`edit`, `toggleProvider`, `updateSchedule`, `storeException`, `updateException`, `destroyException`, `updateServices`) is about admin-as-own-provider availability — not about identity. Building a new "real" `AccountController` alongside the existing one would leave the codebase with two controllers fighting over the same name and route prefix.

**Decision**: refactor in place.
- `AccountController` becomes the profile/password/avatar controller (admin + staff) plus the kept admin-only `toggleProvider`.
- Availability bodies (`updateSchedule`, exceptions, `updateServices`) are lifted into a new `AvailabilityController`.
- The schedule-rebuild helpers (`buildScheduleFromBusinessHours`, `buildScheduleFromProvider`, `writeProviderSchedule`, `writeScheduleFromBusinessHours`) extract to a new `App\Services\ProviderScheduleService`. Both `AccountController.toggleProvider` (still calls `writeScheduleFromBusinessHours` when creating) and `AvailabilityController.updateSchedule` consume the service. No private duplication.

### Decision 2 — Shared FormRequests, authorize relaxed; route middleware is the gate
Existing `UpdateProviderScheduleRequest`, `StoreProviderExceptionRequest`, `UpdateProviderExceptionRequest`, `UpdateProviderServicesRequest` currently authorize on admin role. After Session 4 they're consumed by both `ProviderController` (admin-only via outer route middleware) and `AvailabilityController` (shared via outer route middleware).

**Decision**: relax `authorize()` to `return true` on all four. The auth gate is route middleware, not FormRequest. The PHPDoc on each Request points at the routing as the source of truth. Both controllers also defensively re-derive the active provider from `auth()->user()` + `tenant()->business()`, so a misconfigured route can't authorise a write to the wrong provider's data.

### Decision 3 — Email change semantics: commit + null + dispatch (D-097)
When a user changes their email:
1. Write the new value to `users.email` immediately (subject to `unique:users,email,{self}` validation).
2. Null `users.email_verified_at`.
3. Call `$user->sendEmailVerificationNotification()` to dispatch the standard Laravel notification to the new address.
4. The user remains logged in (session unchanged). Any `verified` middleware route routes them through `verification.notice` until they click the link.
5. The OLD email no longer routes anything (it's gone from `users.email`).

**Why not a shadow staging table?** Two-state semantics ("pending email" vs "current email") would force every login, magic-link, and password-reset path to also branch on staging. Single-state with re-verification matches Laravel's default `MustVerifyEmail` flow and reuses the existing `verification.notice` Inertia page already built in MVPC-1.

**Notification dispatch path**: `sendEmailVerificationNotification()` invokes `Notification::send($this, new VerifyEmail)`. `VerifyEmail` in Laravel core implements `ShouldQueue`, so it goes to the queue. This is consistent with our `Booking*Notification` pattern (D-075 carve-out: interactive notifications go after-response; bulk/system notifications queue). Verification on email-change is not as interactive as the magic-link flow, and queueing matches `RegisterController::store` which already calls the same method without after-response wrapping. **Lock: no after-response wrapping** — trust the existing dispatch path.

**Typo recovery — verification.notice page gets a "Wrong email? Change it again" link.** D-097 commits the new email immediately; if the user typo'd ("john@gmial.com"), the verification link goes to a dead address and the user is locked behind `verification.notice` with no obvious recovery path. Add one Inertia link on the `auth/verify-email` page back to `settings.account` (visible only when the user has a tenant — admin or staff with a settings page to land on). Cheap, honest, no new column. Customers don't see this surface; only business users hit `verification.notice` because customer auth doesn't require verification (D-038).

### Decision 4 — Password update branches on null `password` (D-098)
Schema confirms `users.password` is nullable (migration `0001_01_01_000000_create_users_table.php` line 16). Magic-link-only users genuinely have `password = null`.

**Decision**: `UpdateAccountPasswordRequest::rules()` reads `$this->user()->password === null` to decide:
- **Set first password** (`password === null`): `password` and `password_confirmation` required; no `current_password`.
- **Change existing password** (`password !== null`): `current_password` (validated with `current_password` rule, which uses `Hash::check` against the authenticated user's hash) + `password` + `password_confirmation`.

The frontend reads a new `props.has_password: boolean` to render the right form (toggle the `current_password` field's visibility). A user cannot bypass the `current_password` check by lying about `has_password` because the FormRequest reads server-side state, not the request payload.

### Decision 5 — Avatar self-endpoint is new, not a reuse of `StaffController.uploadAvatar` (part of D-096)
`StaffController.uploadAvatar` accepts `{user}` in the URL and runs `ensureUserBelongsToBusiness($user, $business)` — admin-acting-on-anyone semantics. Reusing it for self-upload would either need the URL to leak the actor's user id (mismatch with the rest of the Account flow, which derives the actor from `auth()->user()`) or a special "self" sentinel. Both are worse than a new endpoint.

**Decision**: new `account.uploadAvatar` (POST `/dashboard/settings/account/avatar`) and `account.removeAvatar` (DELETE `/dashboard/settings/account/avatar`) on `AccountController`. Validation rules mirror StaffController exactly (`File::image()->max(2048)->types(['jpg', 'jpeg', 'png', 'webp'])`). On upload the old file is deleted from `Storage::disk('public')` and the user's `avatar` column is updated; the response is JSON `{ path, url }` per D-042. On remove, the file is deleted and `avatar` is nulled.

### Decision 6 — `auth.has_active_provider` shared Inertia prop (D-099)
Computed in `HandleInertiaRequests::share()`:
```php
'has_active_provider' => fn () => $this->resolveHasActiveProvider($request),
```
Returns `false` when no tenant or no user. Otherwise:
```php
Provider::where('business_id', tenant()->businessId())
    ->where('user_id', $request->user()->id)
    ->whereNull('deleted_at')
    ->exists()
```
Lives under `auth` (next to `role` and `email_verified`) so the existing `auth.role` consumers find it natural.

Settings nav uses it to conditionally include Availability for both admin and staff. The page itself defends with `abort(404)` if accessed without an active provider row — middleware-level gating is overkill for a single page (no need for a new alias).

### Decision 7 — Toggle Provider stays on Account page (admin-only)
The "Bookable provider" toggle currently lives on `account.tsx` and conceptually answers "am I a bookable person?" — that's identity, not availability. The toggle stays where it is; it's just admin-visible only on the new Account page (`auth.role === 'admin'`).

A staff user does not see the toggle (per D-061: staff don't make themselves bookable; admins decide who's bookable). If a staff user opens Account, the toggle section is omitted entirely.

### Decision 8 — Component reuse, no extraction in this session
- `WeekScheduleEditor` (`resources/js/components/onboarding/week-schedule-editor.tsx`) is already shared between `account.tsx` and `staff/show.tsx`. Reuse it on `availability.tsx` as-is.
- `ExceptionDialog` (`resources/js/components/settings/exception-dialog.tsx`) takes `storeUrl` and `updateUrl` as props — already a pure shared component. Reuse on `availability.tsx`.
- The `WeekScheduleEditor` lives under `components/onboarding/` which is a naming smell now that it's used outside onboarding. **Out of scope** to move it; rename is a no-op for behaviour and would inflate this PR.

---

## Implementation steps

### Cluster 1 — Backend route + controller restructure

1. Create `app/Services/ProviderScheduleService.php` with two pure methods extracted from `AccountController`:
   - `buildScheduleFromBusinessHours(Business): array`
   - `buildScheduleFromProvider(Provider): array`
   - `writeProviderSchedule(Provider, Business, array $schedule): void`
   - `writeFromBusinessHours(Provider, Business): void` (composition of the first and third).
2. Create `app/Http/Controllers/Dashboard/Settings/AvailabilityController.php`:
   - `show(Request)`: derives `$user`, `$business`, `$provider`. Aborts 404 if no active provider row. Returns Inertia render with `schedule`, `exceptions`, `services` (read-only flags + assigned ids), `canEditServices` boolean (`tenant()->role() === Admin`), `upcomingBookingsCount`.
   - `updateSchedule(UpdateProviderScheduleRequest)`: derives `$provider` via shared `activeProviderForActorOrFail($user, $business)` helper. Calls `ProviderScheduleService::writeProviderSchedule`. Redirects to `settings.availability`.
   - `storeException(StoreProviderExceptionRequest)`: same actor derivation; create exception scoped to `provider_id` + `business_id`.
   - `updateException(UpdateProviderExceptionRequest, AvailabilityException)`: verifies `exception->provider_id === self.provider->id` and `exception->business_id === tenant.businessId`; aborts 403 otherwise. Updates.
   - `destroyException(Request, AvailabilityException)`: same scope check; deletes.
   - `updateServices(UpdateProviderServicesRequest)`: derives provider from actor (admin-only via routing); `provider->services()->sync($request->validated('service_ids'))`.
3. Refactor `app/Http/Controllers/Dashboard/Settings/AccountController.php`:
   - `edit(Request)`: returns Inertia payload `{ user: { name, email, avatar_url }, has_password: bool, is_admin: bool, isProvider, hasProviderRow, upcomingBookingsCount }`. Drop the old schedule/exceptions/services payload (now lives on Availability).
   - `updateProfile(UpdateAccountProfileRequest)`: writes name + email. If email changed, null `email_verified_at` and call `$user->sendEmailVerificationNotification()`. Redirect to `settings.account` with `success`.
   - `updatePassword(UpdateAccountPasswordRequest)`: writes hashed `password`. Redirect to `settings.account` with `success` (translated label "Password set" or "Password changed" based on prior null-state).
   - `uploadAvatar(Request)`: validates `avatar` per D-042 rules; deletes old file; stores new; updates `users.avatar`; returns JSON `{ path, url }`.
   - `removeAvatar(Request)`: deletes file from storage; nulls `users.avatar`; redirect to `settings.account`.
   - `toggleProvider(Request)`: kept verbatim (admin-only via routing). Calls `ProviderScheduleService::writeFromBusinessHours` on create.
   - **Delete**: `updateSchedule`, `storeException`, `updateException`, `destroyException`, `updateServices`, `activeProviderOrFail`, the four schedule helpers (now in service).
4. Form requests:
   - **Create** `app/Http/Requests/Dashboard/Settings/UpdateAccountProfileRequest.php`:
     - `name` required string max:255
     - `email` required email max:255 unique:users,email,{actor.id}
     - `authorize() { return true; }`
   - **Create** `app/Http/Requests/Dashboard/Settings/UpdateAccountPasswordRequest.php`:
     - If `$this->user()->password === null`: `password` required + confirmed + `Password::defaults()`.
     - Else: `current_password` required + `current_password` rule; `password` required + confirmed + `Password::defaults()`.
     - `authorize() { return true; }`
   - **Modify** the four provider FormRequests to `authorize() { return true; }` and update PHPDoc.
5. `app/Http/Middleware/HandleInertiaRequests.php`:
   - Add `auth.has_active_provider` resolver (closure for laziness; only computed on requests that need it).
   - Returns `false` when no tenant or no user.
6. `routes/web.php`:
   - Inside the **admin-only** sub-group, REMOVE: `account.update-schedule`, `account.{store,update,destroy}-exception`, `account.update-services`. KEEP: `account.toggle-provider`. ADD: `availability.update-services` (PUT `/dashboard/settings/availability/services`, → `AvailabilityController@updateServices`).
   - Inside the **shared** sub-group (existing D-081 group at line 211), ADD:
     - `GET /account` → `AccountController@edit` (name `settings.account`)
     - `PUT /account/profile` → `updateProfile` (name `settings.account.update-profile`)
     - `PUT /account/password` → `updatePassword` (name `settings.account.update-password`)
     - `POST /account/avatar` → `uploadAvatar` (name `settings.account.upload-avatar`)
     - `DELETE /account/avatar` → `removeAvatar` (name `settings.account.remove-avatar`)
     - `GET /availability` → `AvailabilityController@show` (name `settings.availability`)
     - `PUT /availability/schedule` → `updateSchedule` (name `settings.availability.update-schedule`)
     - `POST /availability/exceptions` → `storeException` (name `settings.availability.store-exception`)
     - `PUT /availability/exceptions/{exception}` → `updateException`
     - `DELETE /availability/exceptions/{exception}` → `destroyException`
   - **Remove from admin-only group**: the existing `GET /account` route line — its replacement now lives in the shared group.
   - All shared mutating routes inherit `billing.writable` from the outer group (D-090).
7. `php artisan wayfinder:generate` to refresh the typed route helpers.

### Cluster 2 — Inertia shared prop + nav

1. Update `resources/js/types/index.d.ts`: extend `auth` to include `has_active_provider: boolean`.
2. Update `resources/js/components/settings/settings-nav.tsx`:
   - Add a new `availabilityItem: NavItem` constant (`{ label: 'Availability', href: '/dashboard/settings/availability' }`).
   - Admin nav: add `availabilityItem` after `Account` in the "You" group, conditional on `page.props.auth.has_active_provider` at render time (filter the items array).
   - Staff nav: replace the current single-item list with `[ accountItem, availabilityItem (conditional), calendarIntegrationItem ]` under the "You" group. `accountItem = { label: 'Account', href: '/dashboard/settings/account' }`.

### Cluster 3 — Account page (admin + staff)

1. Rewrite `resources/js/pages/dashboard/settings/account.tsx`:
   - Layout: `SettingsLayout` with title/eyebrow/heading/description.
   - **Profile section**: `<Form>` posting `account.updateProfile` (Wayfinder import). Fields: name, email. Error display via `FieldError`. After a successful PUT that included an email change, the page re-renders with `props.email_changed = true` (server flashes). Show a yellow info banner from the next render: "Verification link sent to {new_email}." Tactically: the controller writes `session()->flash('emailVerificationPending', $user->email)`; the page reads `usePage<PageProps>().props.flash.emailVerificationPending` (extend the shared flash payload to include this new key). Easier alternative: use a regular `flash.success` with the translated message and skip the new key — pick the easier.
   - **Password section**: `<Form>` posting `account.updatePassword`. Render `current_password` only when `props.has_password === true`. Section heading switches "Set password" / "Change password" by the same flag. Submit button label switches similarly.
   - **Avatar section**: upload uses `useHttp` POST to `account.uploadAvatar` (multipart is the honest reason to break the `<Form>` convention — `useHttp` handles multipart automatically per the project's frontend rules). Remove uses `<Form action={removeAvatar()} method="delete">` to stay consistent with the rest of the post-MVPC-3 codebase. Reuse the avatar preview pattern from current `account.tsx` (Avatar / AvatarImage / AvatarFallback).
   - **Admin-only "Bookable provider" toggle section** (`auth.role === 'admin'`): kept verbatim from current page. Uses `account.toggleProvider`.
   - All strings via `useTrans()`.
2. The current page's `<WeekScheduleEditor>`, `<ExceptionDialog>`, services-checkbox sections move OUT of `account.tsx` entirely. They're rendered on the new `availability.tsx`.

### Cluster 4 — Availability page (admin + staff with active Provider row)

1. Create `resources/js/pages/dashboard/settings/availability.tsx`:
   - Props: `schedule: DaySchedule[]`, `exceptions: ExceptionData[]`, `services: ServiceAssignment[]`, `canEditServices: boolean`, `upcomingBookingsCount: number`.
   - **Schedule section**: same `<WeekScheduleEditor>` + form as today's `account.tsx`, posting to `availability.updateSchedule`.
   - **Exceptions section**: same list + `ExceptionDialog` as today, posting to `availability.{store,update,destroy}Exception`.
   - **Services section**:
     - When `canEditServices`: same checkbox + Save form as today, posting to `availability.updateServices`. Section title "Services I perform".
     - When `!canEditServices`: read-only badges with helper text "Ask an admin to update which services you perform." Section title "Services you perform".
2. Inertia 404 from `AvailabilityController::show` (when no active provider row) is handled by Inertia's existing exception pipeline — no UI fallback needed. The nav already hides the item.

### Cluster 5 — Tests

1. **`tests/Feature/Settings/SettingsAuthorizationTest.php`** — update matrix:
   - Move `/dashboard/settings/account` from staff-forbidden to staff-allowed list.
   - Add `/dashboard/settings/availability` to a new "staff with provider can access" matrix; add `attachProvider($business, $staff)` setup row. Also assert staff-without-provider gets 404 on the same path.
   - Add a "shared GET, admin-only mutation" matrix asserting:
     - Staff cannot POST `/dashboard/settings/account/toggle-provider` (403)
     - Staff cannot PUT `/dashboard/settings/availability/services` (403)
   - Add a soft-deleted-member case. Subtle: `actingAs($staff)` does NOT run middleware — it just sets the `Auth::user()` for the next request. `ResolveTenantContext` runs per request from middleware, queries `BusinessMember::query()` (SoftDeletes-scoped), and finds nothing for a soft-deleted membership → no tenant set → `EnsureUserHasRole` aborts 403. There's no per-request memoization of the membership lookup outside the request lifecycle, so a single `actingAs($staff)->get(...)` call after the soft-delete is the right shape. Verify by reading `ResolveTenantContext::resolveMembership` once before writing the test; if any future change introduces request-lifetime caching there, this test must split into two requests (one to populate cache, one to verify the soft-delete invalidates).
2. **`tests/Feature/Settings/AccountTest.php`** — REWRITE:
   - DELETE the existing 16 cases (they're about schedule/exceptions/services — those move to AvailabilityTest).
   - WRITE new cases:
     - Admin can view account page; staff can view account page; staff includes `has_password` and `has_active_provider` accurate.
     - Admin updates profile (name) — persists.
     - Admin changes email — `email_verified_at` is null after; `Notification::fake()` + `assertSentTo($user, VerifyEmail::class)`.
     - Email change rejects if the new email is already taken by another user.
     - Email change accepts re-submitting the same email (unique excludes self).
     - Password change with current password (correct) — persists hashed.
     - Password change rejects wrong current password (validation error).
     - Password set from null — succeeds without current_password; subsequent change requires current.
     - Avatar upload validates image; old file deleted; user.avatar updated; JSON shape `{ path, url }` preserved.
     - Avatar remove deletes file and nulls user.avatar.
     - Staff (without provider) can do everything above; staff (with provider) ditto. (Dataset over actor.)
     - Toggle-provider remains admin-only — staff POST returns 403; admin POST works (existing assertions retained, route name unchanged).
3. **`tests/Feature/Settings/AvailabilityTest.php`** — NEW:
   - Admin self-provider can view; staff self-provider can view; both see schedule/exceptions/services-assigned.
   - Admin updates schedule; staff updates schedule.
   - Admin stores/updates/destroys exception; staff stores/updates/destroys exception.
   - Admin (self-provider) can PUT services; staff cannot (403).
   - Staff without provider row gets 404 on GET and every mutation.
   - Cannot edit/destroy an exception belonging to another provider in the same business (403).
   - Cannot edit/destroy an exception belonging to a provider in another business (403).
   - Cross-tenant: a user member of business A pinned to A cannot read business B's data even if they're also a provider in B (the page returns A's payload, which is empty for them as a provider in A only). This locks D-063 reach.
4. **`tests/Feature/Billing/ReadOnlyEnforcementTest.php`** — extend the dataset:
   - Add rows for `account.updateProfile` (PUT), `account.updatePassword` (PUT), `account.uploadAvatar` (POST), `account.removeAvatar` (DELETE), `availability.updateSchedule` (PUT), `availability.storeException` (POST). Each should redirect to `/dashboard/settings/billing` for a read-only admin.
   - Add a positive read-side test: GET `/dashboard/settings/account` and GET `/dashboard/settings/availability` (with a provider row) both return 200 for a read-only admin (the gate passes safe verbs).

### Cluster 6 — Verification + close

1. `php artisan test tests/Feature tests/Unit --compact` — should pass; expect ~+25 to +30 cases vs baseline 638 → roughly 663–668.
2. `vendor/bin/pint --dirty --format agent`
3. `php artisan wayfinder:generate`
4. `npm run build`
5. Rewrite `docs/HANDOFF.md` for Session 4 close (suite count, bullets per the MVPC-3 hand-off shape).
6. Tick Session 4 checkboxes in `docs/roadmaps/ROADMAP-MVP-COMPLETION.md`.
7. Record D-096 through D-099 in `docs/decisions/DECISIONS-DASHBOARD-SETTINGS.md`.
8. Move `docs/plans/PLAN-MVPC-4-PROVIDER-SETTINGS.md` to `docs/archive/plans/`.

---

## Files to create / modify

### Backend — create
- `app/Services/ProviderScheduleService.php`
- `app/Http/Controllers/Dashboard/Settings/AvailabilityController.php`
- `app/Http/Requests/Dashboard/Settings/UpdateAccountProfileRequest.php`
- `app/Http/Requests/Dashboard/Settings/UpdateAccountPasswordRequest.php`

### Backend — modify
- `app/Http/Controllers/Dashboard/Settings/AccountController.php` (refactor; delete schedule/exception/services methods; add profile/password/avatar)
- `app/Http/Requests/Dashboard/Settings/UpdateProviderScheduleRequest.php` (relax authorize)
- `app/Http/Requests/Dashboard/Settings/StoreProviderExceptionRequest.php` (relax authorize)
- `app/Http/Requests/Dashboard/Settings/UpdateProviderExceptionRequest.php` (relax authorize)
- `app/Http/Requests/Dashboard/Settings/UpdateProviderServicesRequest.php` (relax authorize)
- `app/Http/Middleware/HandleInertiaRequests.php` (add `has_active_provider`)
- `routes/web.php` (route restructure per matrix)

### Frontend — create
- `resources/js/pages/dashboard/settings/availability.tsx`

### Frontend — modify
- `resources/js/pages/dashboard/settings/account.tsx` (rewrite)
- `resources/js/pages/auth/verify-email.tsx` (add "Wrong email? Change it again" link to `settings.account` for authenticated business users with a tenant — D-097 typo recovery)
- `resources/js/components/settings/settings-nav.tsx` (Account + conditional Availability for both roles)
- `resources/js/types/index.d.ts` (extend `auth` with `has_active_provider`)

### Tests — create
- `tests/Feature/Settings/AvailabilityTest.php`

### Tests — modify
- `tests/Feature/Settings/SettingsAuthorizationTest.php` (matrix evolution)
- `tests/Feature/Settings/AccountTest.php` (rewrite for profile/password/avatar)
- `tests/Feature/Billing/ReadOnlyEnforcementTest.php` (extend dataset)

### Docs
- `docs/HANDOFF.md` (rewrite for Session 4 close)
- `docs/roadmaps/ROADMAP-MVP-COMPLETION.md` (tick Session 4 checkboxes)
- `docs/decisions/DECISIONS-DASHBOARD-SETTINGS.md` (D-096 through D-099)

---

## Tests (summary)

| Area | Cases | Notes |
|------|-------|-------|
| `SettingsAuthorizationTest` matrix evolution | +4 (move account, add availability with-and-without provider, soft-delete lock, admin-only mutation matrix) | Locks D-081 extension |
| `AccountTest` rewrite | ~14 (profile, password set, password change, avatar upload, avatar remove, email re-verification, validation errors, toggle preserved) | Replaces 16 old cases |
| `AvailabilityTest` new | ~12 (admin schedule + exceptions + services, staff schedule + exceptions, staff-services-forbidden, no-provider 404s, cross-tenant) | Replaces what AccountTest used to cover |
| `ReadOnlyEnforcementTest` extension | +6–8 (5–6 mutations + 2 reads positive) | Locks D-090 reach |
| **Net delta** | **+25 to +30 cases** | Baseline 638 → expect 663–668 |

---

## Verification

```bash
php artisan test tests/Feature tests/Unit --compact     # iteration loop — Feature + Unit only
vendor/bin/pint --dirty --format agent
php artisan wayfinder:generate                          # before npm run build
npm run build
```

Targeted commands the agent will run during iteration:
```bash
php artisan test tests/Feature/Settings --compact
php artisan test tests/Feature/Billing/ReadOnlyEnforcementTest.php --compact
php artisan route:list --path=dashboard/settings
```

Browser/E2E full suite (`php artisan test --compact`) is the **developer's session-close check**, not part of iteration.

---

## Decisions to record

All four land in `docs/decisions/DECISIONS-DASHBOARD-SETTINGS.md`. The email-change decision genuinely cross-cuts to AUTH but is homed here per the prompt's instruction ("the natural topical home; not FOUNDATIONS or AUTH unless the decision genuinely cross-cuts"). I include a one-line cross-reference inside DECISIONS-AUTH.md pointing back to D-097.

- **D-096** — Account / Availability route split. Account is shared admin+staff for profile/password/avatar; Availability is shared admin+staff scoped to active Provider row; admin-only carve-outs are `account.toggleProvider` and `availability.updateServices`. Extends D-081.
- **D-097** — Email change re-verification: commit immediately, null `email_verified_at`, dispatch the standard `VerifyEmail` notification. No shadow staging table; reuses Laravel's `MustVerifyEmail` flow + the existing `verification.notice` page.
- **D-098** — Password update branches on null vs non-null `password` column. FormRequest reads `auth()->user()->password` to decide between "set first password" and "change password" rule sets. Magic-link-only users set without `current_password`.
- **D-099** — `auth.has_active_provider` shared Inertia prop drives Availability nav visibility for both admin and staff. Computed lazily in `HandleInertiaRequests::share()`. Page itself defends with 404 on no-provider; middleware-level gate not introduced.

---

## Open questions

All five questions raised in the brief are resolved in "Key design decisions" above:

1. **Component extraction scope** → Decision 8: reuse existing `WeekScheduleEditor` + `ExceptionDialog` as-is; defer the misleading `components/onboarding/` location of `WeekScheduleEditor` as a no-op rename follow-up.
2. **Account controller existence** → Decision 1: refactor in place. Existing AccountController is misnamed for what it does today; lift schedule/exception/services to a new AvailabilityController and repurpose AccountController for profile/password/avatar.
3. **Avatar endpoint reuse vs new endpoint** → Decision 5: new self-scoped endpoints (`POST/DELETE /dashboard/settings/account/avatar`) on AccountController. Validation rules mirror StaffController exactly; routes differ because URL semantics differ (no `{user}` binding).
4. **Email change re-verification — interim state** → Decision 3 (D-097): commit + null + dispatch. The OLD email is gone immediately; user keeps their session and routes through `verification.notice` until clicking the new link.
5. **Staff visibility of Availability nav item** → Decision 6 (D-099): shared prop `auth.has_active_provider`.

One residual question for the developer to confirm before Cluster 3:

**Q1 — Should the "Bookable provider" toggle stay on the Account page, or move to a small admin-only header section on the Availability page?**

My take (Decision 7): keep it on Account where it already lives. It conceptually answers "am I bookable?", which is identity, not availability. The toggle is admin-visible only. If the developer would prefer the toggle on the Availability page header instead (so Account is purely about identity-the-user-can-edit), say so before Cluster 3 and I'll move it there with no other change to the design.

---

## Risks and notes

- **Test contract evolution is the riskiest piece.** Replacing 16 AccountTest cases requires care to avoid regressing coverage of the schedule/exception/services flow — every assertion that exists today must reappear in `AvailabilityTest` (ideally as the same shape, just rewired to the new route names and the new controller).
- **Email verification on email change must use the existing `VerifyEmail` notification path**, not a parallel one — the `verification.verify` route validates by hash and id; sending a custom notification would skip Laravel's validator. `sendEmailVerificationNotification()` is the entry point.
- **`current_password` rule** uses the authenticated user's hash via `Auth::guard()`. Validate that with a quick sanity test before relying on it (Laravel docs: "The field under validation must match the authenticated user's password.").
- **`billing.writable` already wraps the shared sub-group** (it's nested inside the gated outer group at line 118 of `routes/web.php`). New shared mutating routes inherit it for free; the read GET routes pass the safe-method check. The `ReadOnlyEnforcementTest` dataset extension is the behavioural lock; the structural route-introspection test from MVPC-3 (in the same file) automatically picks up the new mutating routes because they live inside the gated outer group — no need to extend that part. Verify it stays green after implementation as the canary for the gate's structural integrity.
- **`ResolveTenantContext` uses `BusinessMember::query()`** which auto-applies SoftDeletes. The roadmap's "verify the existing `is_active` middleware applies" gate is satisfied by this scope, not by a separate middleware. No new middleware needed; lock with one explicit test.
- **Wayfinder regen** is required before `npm run build` because the frontend imports route helpers from `@/actions/...` paths that change when routes/controller methods change. Run `php artisan wayfinder:generate` after Cluster 1 and again after any route adjustment.
