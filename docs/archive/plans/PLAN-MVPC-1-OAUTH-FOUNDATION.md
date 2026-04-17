# PLAN-MVPC-1 — Google OAuth Foundation

- **Session**: MVPC-1 (first session of `docs/roadmaps/ROADMAP-MVP-COMPLETION.md`)
- **Source**: `ROADMAP-MVP-COMPLETION.md §Session 1 — Google OAuth Foundation`
- **Cross-cutting decisions this session implements**: locked #1 (HTTP client / SDK — Socialite + `google/apiclient`) and #8 (settings access split — Calendar Integration is admin + staff). Recorded as fresh IDs **D-080** and **D-081**.
- **Related live decisions**: D-052 (Dashboard\\Settings namespace), D-055 (settings sub-navigation via nested layout), D-061 / D-063 (tenant context + role model), D-079 (multi-business membership reachable via invite).
- **Baseline**: post-R-19 state, suite at **518 passed / 2248 assertions**; Pint clean; Vite build clean.

---

## 1. Context

Sessions 1–11 of the original MVP roadmap shipped. REVIEW-2 closed. The next deliverable is the five-session `ROADMAP-MVP-COMPLETION.md`. Session 1 stands up OAuth plumbing for Google Calendar in isolation so Session 2 (sync logic: webhooks, push/pull, pending actions) lands on a stable foundation.

The `calendar_integrations` table already exists from the original Session 2 migration (`2026_04_16_100010_create_calendar_integrations_table.php`) with columns for `access_token` (text, encrypted via model cast), `refresh_token` (text nullable, encrypted), `calendar_id`, `webhook_channel_id`, `webhook_expiry`, plus a non-unique `(user_id, provider)` index. The model (`app/Models/CalendarIntegration.php`) is wired into `User::calendarIntegration()` as a `HasOne`, and a factory + a single relationship test reference it (`tests/Feature/Models/UserRelationshipTest.php`). No controller, no routes, no UI, no OAuth.

Settings today is admin-only. The single group `Route::middleware('role:admin')->prefix('dashboard/settings')->group(...)` wraps every settings route. `resources/js/components/settings/settings-nav.tsx` is a static nav that has no knowledge of the viewer's role. No settings route is reachable by a `staff` user today — `tests/Feature/Settings/SettingsAuthorizationTest.php` asserts exactly that.

## 2. Goal

Deliver a verified Google OAuth round-trip — "Connect Google Calendar" button → Google consent → callback → tokens persisted encrypted-at-rest on a `CalendarIntegration` row scoped to `auth()->user()` — plus a matching "Disconnect" action that deletes the row. Settings gains a first shared (admin + staff) page. No sync logic, no webhooks, no event writes.

## 3. Scope

### In scope

1. Composer dependencies: `laravel/socialite` and `google/apiclient:^2.15`. The Google SDK is not called in Session 1 but lands here so Session 2 can move straight into sync.
2. `services.google` config block + `.env.example` entries (`GOOGLE_CLIENT_ID`, `GOOGLE_CLIENT_SECRET`, `GOOGLE_REDIRECT_URI`).
3. Extend `calendar_integrations`: add `token_expires_at` (nullable timestamp); drop the current non-unique `(user_id, provider)` index; install a unique index on `(user_id, provider)`. Access / refresh token columns are already present and already encrypted by cast — not recreated.
4. `CalendarIntegration` model: add `token_expires_at` to fillable and cast to `datetime`.
5. `App\Http\Controllers\Dashboard\Settings\CalendarIntegrationController` — `index`, `connect`, `callback`, `disconnect`. Minimal bodies. Only persistent side effect is upsert/delete of the user's `CalendarIntegration` row.
6. `connect()` uses Socialite's `Socialite::driver('google')` with scopes `openid`, `email`, `https://www.googleapis.com/auth/calendar.events`, and parameters `access_type=offline`, `prompt=consent`. Returns the Socialite redirect.
7. `callback()`: handles Socialite's callback, upserts the integration for the authenticated user, pins `provider = 'google'`, stores access + refresh tokens (encrypted by model cast), token expiry, and Google account email on `calendar_id` (reusing the column that already exists — it will be renamed/repurposed in Session 2's config step, but holding the Google account email here is the lowest-drift landing spot; see §5).
8. `disconnect()` deletes the row and leaves `// TODO Session 2: stop webhook watch here` at the call site.
9. New route middleware group `role:admin,staff` under `prefix('dashboard/settings')` — the existing admin-only group is unchanged.
10. Route `GET /dashboard/settings/calendar-integration` → `index`. `GET /dashboard/settings/calendar-integration/connect` → `connect`. `GET /dashboard/settings/calendar-integration/callback` → `callback`. `DELETE /dashboard/settings/calendar-integration` → `disconnect`. All under the new shared group.
11. Page `resources/js/pages/dashboard/settings/calendar-integration.tsx` — minimal UI: not-connected state with a "Connect Google Calendar" button that POSTs to the connect route; connected state showing the linked Google account email + "Disconnect" button; reserved slot for the error banner Session 2 will write to (rendered as an early-return region driven by a future `error` prop, wired up but empty in Session 1).
12. `settings-nav.tsx` becomes role-aware: reads `auth.role` from shared props; admin sees the union (existing admin items + Calendar Integration); staff sees only Calendar Integration grouped under "You".
13. Wayfinder regeneration (`php artisan wayfinder:generate`) picks up the new controller + named routes; frontend imports routes via Wayfinder.
14. Feature tests:
    - `connect` redirects to Google with the correct scopes + `access_type=offline` + `prompt=consent`.
    - `callback` persists the integration row with tokens encrypted-at-rest (read the raw DB row, assert it does **not** contain the plaintext token).
    - `callback` upserts — a second connect with a new refresh token replaces the old one, does not duplicate.
    - `disconnect` removes the row.
    - Role access matrix: admin 200, staff 200, customer 403, guest 302 → `/login`, unverified user 302 → `/email/verify`, un-onboarded admin 302 → `/onboarding/step/{n}`.
    - `SettingsAuthorizationTest` updated so the staff-forbidden matrix excludes `/dashboard/settings/calendar-integration`, which now returns 200 — see §8 for the deliberate contract change.
15. `vendor/bin/pint --dirty --format agent` clean, `php artisan test --compact` green, `npm run build` green, Wayfinder regenerated.

### Explicitly out of scope (Session 2 owns these)

- Webhook channel setup, `startWatch`, `stopWatch`.
- Push / pull jobs, `syncIncremental`, sync tokens.
- Event creation / update / delete in Google Calendar.
- `bookings.customer_id` / `bookings.service_id` nullability and `bookings.external_title`.
- `CalendarProvider` interface + `GoogleCalendarProvider` implementation (the `google/apiclient` dependency lands here only so Session 2 can skip the install step).
- `calendar_pending_actions` table.
- Configuration step after callback (pick destination calendar, pick conflict calendars, preview, "Connect and import").
- Notification suppression for `source = google_calendar`.
- `SlotGeneratorService` nullsafe on `service?->buffer_*`.
- Settings → Account page (Session 4), Settings → Availability page (Session 4). They are NOT moved into the new shared group by this session — only Calendar Integration lands in the shared group today. Session 4 extends the group to cover the two additional pages.

## 4. Key design decisions

### 4.1 HTTP client / SDK — record as **D-080**

The locked roadmap decision is Socialite for OAuth + `google/apiclient:^2.15` for Calendar API calls. Recorded in `docs/decisions/DECISIONS-CALENDAR-INTEGRATIONS.md` as **D-080**, with the full rationale verbatim (built-in token refresh, typed event objects, batch support, resilience to API edge cases not worth re-implementing on top of Guzzle). D-080 also captures the install timing: both packages land in Session 1 even though Session 1 does not call `Google\Client`, so Session 2 can start with a green baseline.

### 4.2 Settings access split — record as **D-081**

The locked roadmap decision is that Calendar Integration, Account (Session 4), and Availability (Session 4) are accessible to both `admin` and `staff`. Every other settings page remains admin-only. **Session 1 implements the split for Calendar Integration only**; Session 4 extends the same group to cover Account and Availability without renaming or reshaping it.

**Middleware group naming**: `role:admin,staff` at the router level, identical to the dashboard-level guard already in `routes/web.php:98` (`Route::middleware(['verified', 'role:admin,staff', 'onboarded'])->group(...)`). No new middleware alias needed — `role` is already registered in `bootstrap/app.php:24`. The group is not named in the codebase beyond its inline middleware stack; there's no route-name prefix to choose.

**Open-question resolution (from the brief)**: the middleware spec is generic (`role:admin,staff`) rather than per-page-specific (e.g., `role:calendar-integration-allowed`). Session 4 reuses the same generic group for Account + Availability with a one-line route-file addition (move the three new routes out of the admin-only `prefix('dashboard/settings')` group and into the shared `prefix('dashboard/settings')` group). No rename required; no decision needs to be revisited. D-081 documents the generic naming and the extension path.

### 4.3 `CalendarIntegration` schema deltas

The table already has `access_token`, `refresh_token`, and `calendar_id` — no reason to rebuild. Deltas:

- **Add** `token_expires_at` (nullable `timestamp`). Session 2 reads this to decide whether to refresh before a push; Session 1 just stores what Socialite returns.
- **Drop** the existing non-unique index `calendar_integrations_user_id_provider_index` and **install** `UNIQUE(user_id, provider)`. D-079's parity logic does not apply here (the row is not soft-deleted and there's no re-entry-after-soft-delete semantic); uniqueness is a straightforward "one Google connection per user".

Migration filename: `database/migrations/2026_04_16_100014_extend_calendar_integrations_for_oauth.php` (next available `2026_04_16_*` slot after R-19's `100013`).

### 4.4 Where to put the Google account email

The existing `calendar_id` column was scoped to Session 2's domain (destination Google calendar ID). Session 1 does not yet know or need the destination calendar — that is the config-step decision in Session 2. But the callback **does** return the Google account email, which is shown in the connected-state UI.

Two options considered:

- **(a)** Reuse `calendar_id` to temporarily hold the Google account email in Session 1; Session 2's config step rewrites it to the chosen destination calendar ID and moves the account email into a new `google_account_email` column.
- **(b)** Add a `google_account_email` column in Session 1 and leave `calendar_id` strictly for Session 2's destination calendar ID from day one.

Picked **(b)**. Reason: (a) mutates a column's semantic meaning mid-roadmap, which is brittle — the row is queryable from Session 1's UI and we'd have to teach the UI "this field means X until Session 2 ships, then Y." (b) is one column, self-explanatory, and Session 2 only has to write to `calendar_id` without touching the display name. Cost: one extra column in the migration; Session 2's migration does not need to add it. D-080's consequences capture this.

So the migration adds `token_expires_at` **and** `google_account_email` (nullable string). The model gains both as fillable; `token_expires_at` casts to `datetime`.

### 4.5 Route shape and Wayfinder

Routes are named `settings.calendar-integration`, `settings.calendar-integration.connect`, `settings.calendar-integration.callback`, `settings.calendar-integration.disconnect`. Matches the existing convention (`settings.profile`, `settings.hours`, `settings.services.*`).

Route verbs:
- `GET /dashboard/settings/calendar-integration` → `index` (renders page)
- `POST /dashboard/settings/calendar-integration/connect` → `connect` (Socialite redirect). **POST, not GET** — the Socialite `redirect()` call writes OAuth state into the session cookie, and a GET that side-effects is an anti-pattern even though our `<Form>` wrapping means prefetch is not a concern. POST is the safer convention for OAuth initiation.
- `GET /dashboard/settings/calendar-integration/callback` → `callback` (Google redirects here after consent; must be GET because Google uses `response_type=code` in a 302)
- `DELETE /dashboard/settings/calendar-integration` → `disconnect`

Both Connect and Disconnect buttons on the frontend render as Inertia `<Form action={...} method="post|delete">` wrappers around a `Button`, using the Wayfinder-generated typed actions. Symmetric shape; no raw `<a href>`.

**CSRF concern on callback**: Google's consent → app callback is a third-party 302 that does NOT carry a CSRF token. Laravel's `VerifyCsrfToken` middleware is enforced globally for web routes. Socialite callbacks use GET, and Laravel's CSRF protection does not apply to GET requests — so the callback passes through without any CSRF exclusion needed. (Session 2's Google webhook POST endpoint will need `$except[]` in `bootstrap/app.php`, but Session 1 does not touch it.)

### 4.6 Upsert semantics for `callback`

The row is keyed by `(user_id, provider = 'google')`. The callback does `CalendarIntegration::updateOrCreate(['user_id' => $user->id, 'provider' => 'google'], [...])`. This is the only place that writes tokens. Idempotent against a user re-running the connect flow (e.g., after revoking access in Google Account settings).

The refresh token is only returned by Google on the first consent **unless** `prompt=consent` is used — which we pass explicitly, so the refresh token always comes back. Defensive code: if Socialite's response has a null `refreshToken`, we preserve whatever is already stored (do not overwrite to null); covered by a dedicated test.

### 4.7 Decryption check in tests

`access_token` and `refresh_token` use the `encrypted` cast — Laravel encrypts on write and decrypts on read. To prove encryption at rest, the test reads the raw DB row via a raw query (`DB::table('calendar_integrations')->where('user_id', $user->id)->first()`) and asserts:

- The raw `access_token` string does NOT equal the plaintext Socialite returned.
- The raw `access_token` string starts with `eyJ` (Laravel's base64-encoded JSON envelope prefix).
- The model-level read (`$user->calendarIntegration->access_token`) DOES equal the plaintext.

Same three assertions for `refresh_token`.

### 4.8 Role-aware nav

The nav reads `auth.role` from shared props (populated by `HandleInertiaRequests::share` via `tenant()->role()`). Two navs in one component:

```tsx
const { t } = useTrans();
const role = usePage<PageProps>().props.auth.role;

const adminGroups = [...existing admin nav groups, with 'Calendar Integration' inserted under 'You'];
const staffGroups = [
    { label: 'You', items: [{ label: 'Calendar Integration', href: '/dashboard/settings/calendar-integration' }] },
];
const groups = role === 'admin' ? adminGroups : staffGroups;
```

Staff with no Provider row still sees Calendar Integration because every staff user can connect their own calendar regardless of whether they're currently bookable. (Session 4 will add Account + Availability conditionally based on whether an active Provider row exists.)

### 4.9 Provider abstraction is NOT introduced

No `CalendarProvider` interface, no `GoogleCalendarProvider`, no `CalendarProviderFactory`. Session 2 introduces the interface along with the two concrete methods (`pushEvent`, `syncIncremental`, …) it needs to invoke. Adding an interface in Session 1 would either (a) have one implementation with one method (`getAccountEmail`), which is a premature abstraction, or (b) pre-commit to the Session 2 method shape before the Session 2 agent gets to design it. Session 1 calls Socialite directly; Session 2 adds the interface above it.

## 5. Implementation steps, in order

1. **Install packages.** `composer require laravel/socialite google/apiclient:^2.15`. Verify Socialite's service provider is auto-discovered (it is). `google/apiclient` has no provider to register.
2. **Config.** Add `services.google` block to `config/services.php`:
   ```php
   'google' => [
       'client_id' => env('GOOGLE_CLIENT_ID'),
       'client_secret' => env('GOOGLE_CLIENT_SECRET'),
       'redirect' => env('GOOGLE_REDIRECT_URI'),
   ],
   ```
   Add the three env vars to `.env.example` under a new `# Google Calendar` section at the end, placeholders only (`GOOGLE_CLIENT_ID=`, `GOOGLE_CLIENT_SECRET=`, `GOOGLE_REDIRECT_URI="${APP_URL}/dashboard/settings/calendar-integration/callback"`).
3. **Migration.** `php artisan make:migration extend_calendar_integrations_for_oauth --table=calendar_integrations --no-interaction`.
   - `up()`: `dropIndex(['user_id', 'provider'])`; `$table->timestamp('token_expires_at')->nullable()->after('refresh_token')`; `$table->string('google_account_email')->nullable()->after('calendar_id')`; `$table->unique(['user_id', 'provider'])`.
   - `down()`: reverse in mirror order.
4. **Model.** Update `app/Models/CalendarIntegration.php`: add `token_expires_at` and `google_account_email` to `#[Fillable]`; add `token_expires_at` cast to `datetime`. Existing hidden / encrypted casts stay.
5. **Factory.** Update `CalendarIntegrationFactory` with `token_expires_at => now()->addHour()` and `google_account_email => fake()->safeEmail()`. Preserves existing users of the factory.
6. **Controller.** `php artisan make:controller Dashboard/Settings/CalendarIntegrationController --no-interaction`. Implement four actions:
   - `index(Request $request): Response` — render `dashboard/settings/calendar-integration`; pass `connected: bool`, `googleAccountEmail: string | null`, `error: string | null` (reserved slot for Session 2, always null today).
   - `connect(Request $request): RedirectResponse` (POST) — return `Socialite::driver('google')->scopes([...])->with(['access_type' => 'offline', 'prompt' => 'consent'])->redirect()`. Socialite's `redirect()` returns a 302 response regardless of the inbound verb.
   - `callback(Request $request): RedirectResponse` — `$googleUser = Socialite::driver('google')->user()`; `CalendarIntegration::updateOrCreate(['user_id' => $request->user()->id, 'provider' => 'google'], [ … ])`; preserve the existing refresh token if the Socialite response has a null one; flash a success message; redirect to `settings.calendar-integration`.
   - `disconnect(Request $request): RedirectResponse` — `// TODO Session 2: stop webhook watch here`; `$request->user()->calendarIntegration()->delete()`; flash success; redirect back.
7. **Routes.** Edit `routes/web.php`. Inside the existing `Route::middleware(['verified', 'role:admin,staff', 'onboarded'])->group(...)` at line 98, add a new nested `Route::prefix('dashboard/settings')->group(function () { … })` **as a sibling of** the existing `Route::middleware('role:admin')->prefix('dashboard/settings')->group(...)`. The new group carries **no additional middleware** — the outer group already enforces `role:admin,staff`, and declaring it again would read as if the inner block narrowed access when it does not. The new prefix-only group holds the four calendar-integration routes. Wayfinder automatically generates per-route function stubs under `@/routes/settings`.
8. **Wayfinder.** `php artisan wayfinder:generate`.
9. **Frontend page.** Create `resources/js/pages/dashboard/settings/calendar-integration.tsx` using `SettingsLayout` as layout. Two states:
   - **Not connected**: heading, description copy, `<Form action={connect()} method="post">` wrapping a `Button`. Icon is optional — COSS UI primitives only.
   - **Connected**: shows `googleAccountEmail` and a `<Form action={disconnect()} method="delete">` with a destructive `Button`. No teaser copy about Session 2 — show only the account email + Disconnect.
   - **Error banner slot**: `{error && <Alert variant="destructive">{error}</Alert>}` — renders nothing in Session 1.
10. **Settings nav.** Edit `resources/js/components/settings/settings-nav.tsx`: read `auth.role` from `usePage`; branch between admin nav groups (existing + Calendar Integration inserted under "You") and staff nav groups (single "You" group with only Calendar Integration). Keep the active-state logic unchanged.
11. **i18n.** All user-facing strings on the new page via `useTrans().t(...)`. New keys written to `lang/en/messages.*` if the existing file pipeline requires it; otherwise inline English strings (the `__()` infra is already cascade-keyed per existing pages).
12. **Tests.** Write / update:
    - `tests/Feature/Settings/CalendarIntegrationTest.php` — new file, ~10 cases (see §6).
    - `tests/Feature/Settings/SettingsAuthorizationTest.php` — extend the staff `routes` array to assert forbidden on admin-only routes; add a separate assertion that `/dashboard/settings/calendar-integration` returns 200 for the staff user. Updated test also exercises the admin union (200 on every admin route **plus** Calendar Integration).
    - `tests/Feature/Models/UserRelationshipTest.php` — unchanged (existing `it has one calendar integration` case still passes; the relation shape didn't change).
13. **Pint + build.** `vendor/bin/pint --dirty --format agent`; `npm run build`. Confirm the Wayfinder-generated `@/routes/settings` file is present and imports resolve.
14. **Full suite.** `php artisan test --compact`. Target: **528 passed** (518 baseline + ~10 new cases; exact count depends on how the access matrix resolves into individual `test()` blocks).

## 6. Tests

### New file — `tests/Feature/Settings/CalendarIntegrationTest.php`

Base setup: `Business::factory()->onboarded()->create()`, `$admin` attached as admin, `$staff` attached as staff.

1. **connect redirects to Google with the right scopes and params** — `actingAs($admin)->post(route('settings.calendar-integration.connect'))` → assert 302 to `accounts.google.com/o/oauth2/auth`; parse the `scope` querystring and run **three independent** `assertStringContainsString` calls (one each for `openid`, `email`, `https://www.googleapis.com/auth/calendar.events`) so the assertion survives Socialite scope-ordering changes; assert `access_type=offline` and `prompt=consent` as independent querystring params.
2. **callback persists the integration with tokens encrypted at rest** — stub `Socialite::driver('google')->user()` to return a fake user with access/refresh tokens + email; GET the callback route; assert `CalendarIntegration` row exists for `$admin` with `provider = 'google'`. Encryption-at-rest proof is exactly two assertions per token: (a) the raw DB value (via `DB::table('calendar_integrations')`) does NOT equal the plaintext, and (b) the model-cast read (`$integration->access_token`) DOES equal the plaintext. No coupling to Laravel's envelope format (no `eyJ` prefix check). Same pair for `refresh_token`.
3. **callback upserts: a second connect replaces the existing row** — stub a first callback; stub a second callback with a different `accessToken`; assert exactly one row for the user; assert the new token is stored.
4. **callback preserves the existing refresh token if Socialite returns null** — seed a row with a refresh token; stub a callback where Socialite's returned refresh token is null; assert the stored refresh token is unchanged.
5. **disconnect deletes the row** — seed an integration for `$admin`; `actingAs($admin)->delete(...)`; assert the row is gone; assert redirect back to the index with flash success.
6. **disconnect is a no-op when nothing is connected** — `actingAs($admin)->delete(...)` without a seeded row; assert 302, no error, no row created.
7. **admin reaches the page (200)** — `actingAs($admin)->get(route('settings.calendar-integration'))->assertOk()`.
8. **staff reaches the page (200)** — `actingAs($staff)->get(route('settings.calendar-integration'))->assertOk()`.
9. **guest is redirected to /login** — `get(route('settings.calendar-integration'))->assertRedirect('/login')`.
10. **customer is forbidden** — create a customer-only user; `actingAs($customer)->get(...)->assertStatus(403)`.
11. **unverified admin is redirected to verify notice** — admin without `email_verified_at`; `assertRedirect(route('verification.notice'))`.
12. **un-onboarded admin is redirected to onboarding** — admin of a business whose `onboarding_completed_at` is null; `assertRedirect('/onboarding/step/...')`.
13. **page exposes `connected=false` when no integration** — Inertia props assertion via `AssertableInertia` helper (use the existing `assertInertia(fn (Assert $page) => $page->where('connected', false))` pattern).
14. **page exposes `connected=true` and `googleAccountEmail` when connected** — seed a row; assert `connected` = true and email matches.

### Extended file — `tests/Feature/Settings/SettingsAuthorizationTest.php`

- **`staff cannot access any admin-only settings page`** (renamed from `staff cannot access any settings page`) — routes list narrows to the admin-only set: remove `/dashboard/settings/account` (admin-only today, Session 4 moves it) — actually **keep it admin-only for Session 1**, per scope. The deliberate contract change here is splitting the test into two: the existing admin-only routes stay asserting 403 for staff, and a second `test('staff can access shared settings pages')` asserts 200 on `/dashboard/settings/calendar-integration`.
- **`admin can access all settings pages`** — extend the routes array with `/dashboard/settings/calendar-integration`.

### Extended factory usage

No new changes to `tests/Feature/Models/UserRelationshipTest.php` — the `CalendarIntegration` factory now returns two extra fields; existing assertions still pass because they don't look at them.

## 7. Files to create / modify

### Create

- `database/migrations/2026_04_16_100014_extend_calendar_integrations_for_oauth.php`
- `app/Http/Controllers/Dashboard/Settings/CalendarIntegrationController.php`
- `resources/js/pages/dashboard/settings/calendar-integration.tsx`
- `tests/Feature/Settings/CalendarIntegrationTest.php`

### Modify

- `composer.json` + `composer.lock` (via `composer require`)
- `config/services.php` — add `google` block
- `.env.example` — add Google env vars
- `app/Models/CalendarIntegration.php` — fillable + casts
- `database/factories/CalendarIntegrationFactory.php` — add new fields
- `routes/web.php` — add new `role:admin,staff` settings group with four routes
- `resources/js/components/settings/settings-nav.tsx` — role-aware nav
- `resources/js/actions/App/Http/Controllers/Dashboard/Settings/CalendarIntegrationController.ts` (auto-generated by Wayfinder; committed)
- `resources/js/routes/settings/…` auto-generated deltas
- `tests/Feature/Settings/SettingsAuthorizationTest.php` — split into admin-only + shared matrices

### Docs

- `docs/decisions/DECISIONS-CALENDAR-INTEGRATIONS.md` — add **D-080** (Socialite + `google/apiclient`).
- `docs/decisions/DECISIONS-DASHBOARD-SETTINGS.md` — add **D-081** (settings access split: shared `role:admin,staff` group, generic naming, extension path for Session 4).
- `docs/HANDOFF.md` — overwrite to reflect MVPC-1 state at session close.
- `docs/roadmaps/ROADMAP-MVP-COMPLETION.md` — tick the Session 1 checkboxes.
- Move `docs/plans/PLAN-MVPC-1-OAUTH-FOUNDATION.md` → `docs/archive/plans/` at session close.

## 8. Deliberate contract changes

One existing test contract changes in a way that merits calling out:

**`SettingsAuthorizationTest::staff cannot access any settings page`** — the test name and assertion change. Session 1 is the first time any settings route is reachable by a staff user, so "staff cannot access **any** settings page" is no longer the truth the codebase asserts. The test splits into:

1. `staff cannot access any admin-only settings page` — asserts 403 on the seven routes that stay admin-only.
2. `staff can access the shared calendar integration page` — asserts 200 on `/dashboard/settings/calendar-integration`.

This is an intentional evolution of the contract driven by D-081 (locked roadmap decision #8), not a patch to paper over a failing test. The new test name makes the access matrix explicit and future Session 4 extensions are a one-line addition.

No other existing tests should need changes. If the full suite surfaces an unexpected failure during implementation, the failing test's assertion is preserved and the implementation is adjusted — not the other way around.

## 9. Constraints carried in

From `CLAUDE.md` + the referenced decisions:

- Inertia v3 + React. Forms via `<Form>`, `useForm`, or `useHttp` per the frontend CLAUDE.md. Standalone HTTP requests use `useHttp` (not fetch/axios) — not needed here; the connect flow is a standard GET navigation and disconnect is an Inertia form submit.
- All user-facing strings via `__()` / `useTrans().t(...)`. English base.
- Frontend route references via Wayfinder (`@/actions/...` or `@/routes/...`) — no hardcoded paths in TSX. Exception is the nav list, which has always used string literals for the `href` attribute because the nav is a static list; the new entry follows the same pattern for visual consistency with existing nav items. If the developer prefers Wayfinder URLs here, it's a trivial refactor at review time.
- Tenant-scoped queries respect `tenant()` / `App\Support\TenantContext`. The `CalendarIntegration` is scoped to the authenticated **User**, not the Business — per SPEC §11 ("OAuth 2.0 flow per provider — each connects their own Google account via the linked User"). A provider who works at two businesses has one Google connection, shared across their businesses. Session 2 will introduce the destination-calendar-per-provider configuration, still keyed on `user_id`.
- Migrations extend; do not recreate. Confirmed in §4.3.
- Pint clean before session close.
- No Laravel Fortify, no Jetstream, no Zap.
- Tenant pin semantics: the callback redirects back to the settings page without touching `current_business_id`, because Calendar Integration is not business-scoped.
- No scope drift: no refactor of existing controllers, models, or tests outside what's strictly required to land the Calendar Integration page and the role split.

## 10. Decisions to record

### D-080 — Calendar integration stack: Socialite + google/apiclient

File: `docs/decisions/DECISIONS-CALENDAR-INTEGRATIONS.md`.

- **Date**: 2026-04-16
- **Status**: accepted
- **Context**: The MVP-completion roadmap mandates Google Calendar sync. The OAuth flow and the Calendar API calls are two separable concerns. Rolling our own on top of Guzzle would mean re-implementing token refresh, typed event-object mapping, batch semantics, quota-aware retries, and the handful of Google-API edge cases the SDK already covers.
- **Decision**: Use `laravel/socialite` for the OAuth authorization-code flow (consent, callback, token exchange, refresh) and `google/apiclient:^2.15` for Calendar API calls once Session 2 starts making them. Both packages install in Session 1 even though Session 1 only calls Socialite, so Session 2 lands on a green baseline.
- **Consequences**: ~7 MB dependency footprint for `google/apiclient`. One-time install cost is paid by the Socialite + SDK gain on implementation velocity and correctness for every subsequent calendar feature. No replacement for either package is easy to evaluate later without a concrete failure to motivate the swap.

### D-081 — Settings routes split into two middleware groups; shared group is `role:admin,staff`

File: `docs/decisions/DECISIONS-DASHBOARD-SETTINGS.md`.

- **Date**: 2026-04-16
- **Status**: accepted
- **Extends**: D-052 (Dashboard\\Settings namespace), D-055 (settings sub-navigation via nested layout).
- **Context**: Pre-MVPC-1, every settings route sat inside a single `Route::middleware('role:admin')->prefix('dashboard/settings')->group(...)`. The MVP completion roadmap opens three settings pages to `staff` (Calendar Integration in MVPC-1; Account + Availability in MVPC-4).
- **Decision**:
  1. Settings routes split into two sibling groups under `prefix('dashboard/settings')`:
     - `Route::middleware('role:admin')->prefix('dashboard/settings')->group(...)` — admin-only (existing pages; unchanged).
     - `Route::prefix('dashboard/settings')->group(...)` — shared. **No additional middleware** on this inner group: the outer dashboard group at `routes/web.php:98` already wraps with `role:admin,staff`, so re-declaring it here would be redundant and read as if the inner group narrowed access. Calendar Integration lands here in MVPC-1. Account and Availability move here (or their new sibling routes land here) in MVPC-4.
  2. The shared group is named by intent, not by middleware: its role is "no inner guard beyond what the outer group already enforces." No route-name prefix, no new middleware alias, no page-specific name.
  3. Settings nav (`settings-nav.tsx`) derives visibility from the shared `auth.role` prop. Admin sees the union of items across both groups; staff sees only the shared group's items.
  4. Staff users who lack a Provider row still reach every shared page the permission layer permits — including Availability, once Session 4 ships it (Session 4 adds the provider-row check at the controller level, not the middleware level). Session 1 is not affected: Calendar Integration is reachable by every staff user.
- **Consequences**:
  - Adding a new shared settings page in future sessions is a one-line route file change (move the route into the shared group).
  - `SettingsAuthorizationTest` splits into "admin-only matrix" and "shared matrix", which is the honest shape of the access model going forward.
  - Admin-only pages remain admin-only indefinitely — no staff access is implied or granted by this split.

## 11. Verification at session close

Commands (per the brief):

```bash
php artisan test --compact
vendor/bin/pint --dirty --format agent
npm run build
php artisan wayfinder:generate
```

Expected outcomes:

- `test --compact` — green; suite count goes from **518** baseline to roughly **528** (within ±1 case depending on how many assertions in the access matrix get split into separate `test()` blocks).
- `pint --dirty --format agent` — `{"result":"pass"}`.
- `npm run build` — Vite build clean; bundle size impact dominated by the new page component (small, no new dependencies).
- `wayfinder:generate` — Wayfinder files regenerated before `npm run build`; `git status` shows the auto-generated TypeScript deltas committed.

Manual smoke (documented in HANDOFF but not run in CI):
1. `composer require` done; `.env.example` populated.
2. Add real `GOOGLE_CLIENT_ID` / `GOOGLE_CLIENT_SECRET` / `GOOGLE_REDIRECT_URI` to `.env`.
3. Register a business, onboard, sign in as admin, visit `/dashboard/settings/calendar-integration`, click Connect, grant consent on Google, verify the redirect lands back on the settings page showing the connected account email.
4. Click Disconnect, verify the row deletes and the UI returns to the not-connected state.
5. Sign in as staff, visit the same page, verify 200 + the admin-only nav items are not shown.

## 12. Session-close checklist

- `php artisan test --compact` → green, target ~528 passed.
- `vendor/bin/pint --dirty --format agent` → `{"result":"pass"}`.
- `npm run build` → green.
- `php artisan wayfinder:generate` → committed.
- D-080 added to `docs/decisions/DECISIONS-CALENDAR-INTEGRATIONS.md`.
- D-081 added to `docs/decisions/DECISIONS-DASHBOARD-SETTINGS.md`.
- `docs/HANDOFF.md` rewritten (overwrite) with the MVPC-1 state, the 528-test count, the two new decisions, and a short "what Session 2 needs to know" note (Session 2 builds on the `CalendarIntegration` row, introduces the config step, and may rename `calendar_id` semantics once the destination-calendar picker ships).
- Roadmap checkboxes ticked on `docs/roadmaps/ROADMAP-MVP-COMPLETION.md` §Session 1.
- This plan moved from `docs/plans/` to `docs/archive/plans/`.

## 13. Open questions (flagged for developer)

The brief called out one open question — the shared-group naming — resolved in §4.2. Two additional questions surface from the implementation detail but are low-risk defaults:

- **(Q1)** Nav copy for the connected-state page: should Session 1 hint at the Session 2 configuration ("Sync behaviour is configured after connect") or stay silent and let Session 2 rewrite the page entirely? **Default**: stay silent. Display just the account email + Disconnect. Cost of the hint is that Session 2 rewrites it anyway; benefit is that the user understands the flow is WIP. Leaning silent to avoid reading-too-much into a Session 1 affordance.
- **(Q2)** Where to store the Google account email: reuse `calendar_id` vs add a new `google_account_email` column. Resolved in §4.4 — picked the new column. Flagging because it's one extra column in the migration.

Neither blocks planning. Both are committed above; the developer can flip either in review with a one-line change to this plan.

## 14. Escape hatch

If implementation reveals a material complication — e.g., Socialite's Google driver does not return the refresh token reliably under our scope set, the `role:admin,staff` group inadvertently opens a currently-admin-only route somewhere I didn't expect, or the test fake for Socialite has a Laravel 13 API change — I stop, report the specific problem with a short proposal (rarely a full split; typically a one-paragraph plan patch), and wait for approval before continuing. I do not expect this to trigger.

---

**Status**: draft, awaiting approval. No code will be written before approval.
