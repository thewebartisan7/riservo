# Handoff

**Session**: MVPC-1 — Google OAuth Foundation (Session 1 of `docs/roadmaps/ROADMAP-MVP-COMPLETION.md`)
**Date**: 2026-04-17
**Status**: Code complete; full Pest suite green (**784 passed / 3264 assertions** — Unit 15 + Feature 520 + Browser 249); Pint clean; `npm run build` green; plan archived to `docs/archive/plans/PLAN-MVPC-1-OAUTH-FOUNDATION.md`.

---

## Suite-count context

The previous R-19 handoff reported **518 passed**. The actual pre-MVPC-1 baseline was higher because the E2E browser test suite (commit `ec7a65b`, "test: E2E test suite implementation E2E-1 through E2E-6") landed between R-19 close and MVPC-1 kickoff without updating HANDOFF. The pre-session full-suite count was effectively **767 passed**; MVPC-1 adds **+17** cases to reach **784 passed**.

Breakdown:
- Feature: 503 → **520** (+17): 16 new in `tests/Feature/Settings/CalendarIntegrationTest.php` + 1 new in `tests/Feature/Settings/SettingsAuthorizationTest.php` (`staff can access shared settings pages`).
- Unit: 15 (unchanged).
- Browser: 249 (unchanged).

---

## What Was Built

Session 1 stands up the Google OAuth round-trip in isolation so Session 2 (webhooks, push/pull, pending actions) lands on a stable foundation. No sync logic in this session — only the plumbing that lets a user click "Connect Google Calendar", grant consent, and land back with tokens encrypted-at-rest.

Plan: `docs/archive/plans/PLAN-MVPC-1-OAUTH-FOUNDATION.md`. Two new decisions:
- **D-080** — `docs/decisions/DECISIONS-CALENDAR-INTEGRATIONS.md` — Socialite + `google/apiclient:^2.15` stack.
- **D-081** — `docs/decisions/DECISIONS-DASHBOARD-SETTINGS.md` — settings routes split into admin-only and admin+staff shared groups; the shared inner group carries no additional middleware because the outer dashboard group already enforces `role:admin,staff`.

### Composer dependencies

- `laravel/socialite` (v5) — installed for OAuth authorization-code flow.
- `google/apiclient:^2.15` — installed now even though Session 2 is the first caller, so Session 2 starts green with no composer step.

### Config + env

- `config/services.php` — new `google` block reading `GOOGLE_CLIENT_ID`, `GOOGLE_CLIENT_SECRET`, `GOOGLE_REDIRECT_URI` from env.
- `.env.example` — new "Google Calendar OAuth" section with placeholders; `GOOGLE_REDIRECT_URI` interpolates `${APP_URL}/dashboard/settings/calendar-integration/callback`.

### Database

- Migration `database/migrations/2026_04_16_100014_extend_calendar_integrations_for_oauth.php` extends the existing `calendar_integrations` table (created in the original Session 2 migration `2026_04_16_100010_...`):
  - Adds `token_expires_at` (nullable `timestamp`).
  - Adds `google_account_email` (nullable string). Picked a dedicated column rather than reusing `calendar_id` to avoid mutating a column's semantic meaning mid-roadmap — Session 2 keeps `calendar_id` strictly for the destination Google calendar ID from day one.
  - Drops the existing non-unique `(user_id, provider)` index and installs a **unique** index on the same pair (enforces one Google connection per user).
- `access_token` and `refresh_token` columns were already present (both `text`, nullable for `refresh_token`) — unchanged. Encryption-at-rest is via model cast, not column type.

### Model + factory

- `app/Models/CalendarIntegration.php`:
  - Fillable gains `token_expires_at` and `google_account_email`.
  - Cast `token_expires_at` → `datetime`.
  - Existing `encrypted` casts on `access_token` / `refresh_token` retained; `#[Hidden]` attribute retained.
- `database/factories/CalendarIntegrationFactory.php` gains `token_expires_at => now()->addHour()` and `google_account_email => fake()->safeEmail()`; `calendar_id` default flipped to `null` (Session 2 will set it during the configuration step).

### Controller

`app/Http/Controllers/Dashboard/Settings/CalendarIntegrationController.php` with four actions:

- `index(Request)` — renders `dashboard/settings/calendar-integration` with `connected: bool`, `googleAccountEmail: string | null`, and an `error: null` prop (reserved slot for Session 2's sync-error surface).
- `connect(Request)` (POST) — builds the Socialite redirect to Google with scopes `openid` + `email` + `calendar.events` and `access_type=offline` + `prompt=consent` (which guarantees a refresh token on every consent). **Inertia detection**: when the request carries `X-Inertia: true`, returns `Inertia::location($targetUrl)` so the client can perform a full `window.location` change (an external 302 cannot navigate the browser from XHR). Non-Inertia POSTs receive the raw `RedirectResponse` — the browser follows the 302 natively.
- `callback(Request)` — three branches: (1) if Google returned `?error=...` (user denied consent, invalid scope, etc.), short-circuit to the settings page with a recoverable flash error; (2) if `Socialite::driver('google')->user()` throws (invalid state, token exchange failure, network error), catch, `report()` the exception, and redirect with a generic flash error — never 500; (3) success path: upsert the integration row via `updateOrCreate(['user_id' => ..., 'provider' => 'google'], [...])`, **preserve** the previously stored refresh token if Socialite returned null (defense against a re-consent), pin `google_account_email`, store the token expiry derived from `expiresIn`, flash success, redirect to the index route.
- `disconnect(Request)` — leaves `// TODO Session 2: stop webhook watch here` at the call site, then deletes the user's integration row, flashes success, redirects to the index. No-op-safe when nothing is connected.

### Routing

`routes/web.php` now has two sibling settings groups inside the existing outer dashboard group at `routes/web.php:98`:

1. `Route::middleware('role:admin')->prefix('dashboard/settings')->group(...)` — admin-only (unchanged existing pages).
2. `Route::prefix('dashboard/settings')->group(...)` — **no inner middleware** (outer group already enforces `role:admin,staff`, per D-081). Calendar Integration lives here. Four routes:
   - `GET /dashboard/settings/calendar-integration` → `index` (name: `settings.calendar-integration`)
   - `POST /dashboard/settings/calendar-integration/connect` → `connect` (name: `settings.calendar-integration.connect`)
   - `GET /dashboard/settings/calendar-integration/callback` → `callback` (name: `settings.calendar-integration.callback`)
   - `DELETE /dashboard/settings/calendar-integration` → `disconnect` (name: `settings.calendar-integration.disconnect`)

Connect is POST (not GET) because `Socialite::redirect()` writes OAuth state into the session cookie; GET with session side effects is the wrong shape. Callback is GET (Google returns via 302 + query params, which is CSRF-exempt for GET).

### Frontend

- `resources/js/pages/dashboard/settings/calendar-integration.tsx` — new page. Two states:
  - **Not connected**: explanatory copy + `<Form action={connect()} method="post">` (Inertia) wrapping a "Connect Google Calendar" button. The controller's Inertia-branch in `connect()` returns `Inertia::location(...)` which makes the client navigate to Google.
  - **Connected**: shows the Google account email + `<Form action={disconnect()} method="delete">` wrapping a destructive "Disconnect" button. No teaser copy about Session 2 — the connected state shows only the account email and the Disconnect action.
  - Error banner slot (`<Alert variant="error">`) renders either `flash.error` (one-shot, set by the callback when Google returns an error or Socialite throws) or the reserved `error` prop (Session 2's persistent sync-error surface). Flash wins when both are present since a just-failed OAuth attempt is more actionable than a stored sync error.
- `resources/js/components/settings/settings-nav.tsx` — now reads `auth.role` from `usePage<PageProps>()`. Admin sees the union (existing items + Calendar Integration under "You"); staff sees only the shared group (Calendar Integration). Session 4 will extend the staff branch with Account and Availability.
- Wayfinder regenerated: `resources/js/actions/App/Http/Controllers/Dashboard/Settings/CalendarIntegrationController.ts` + matching route files are produced on `php artisan wayfinder:generate` (and by the Vite plugin on `npm run build`). These files are gitignored per project convention — regenerate locally before frontend work.

### Tests

- `tests/Feature/Settings/CalendarIntegrationTest.php` — new file, 16 cases covering: connect redirect native-POST branch (three independent scope assertions for ordering-resilience + `access_type=offline` + `prompt=consent`); connect Inertia-visit branch (409 + `X-Inertia-Location` header points at Google); callback persistence with encryption-at-rest proof (raw DB read does NOT equal the plaintext; model cast DOES — the "starts with `eyJ`" envelope-format check was removed on review because it couples to Laravel internals); callback upsert (second connect replaces the row); callback refresh-token preservation when Socialite returns null; callback Google-error branch (`?error=access_denied` redirects with flash error, no row persisted); callback Socialite-throws branch (no fake registered → redirect with flash error, no 500); disconnect delete; disconnect no-op; admin/staff access (200); guest 302 to `/login`; customer-only 403; unverified admin 302 to `verification.notice`; un-onboarded admin 302 to onboarding; Inertia prop assertions for connected/not-connected state.
- `tests/Feature/Settings/SettingsAuthorizationTest.php` — deliberate contract evolution for D-081: the old "staff cannot access any settings page" split into two tests, "staff cannot access any admin-only settings page" (unchanged set of admin-only routes) and "staff can access shared settings pages" (Calendar Integration today). The admin matrix gains Calendar Integration. No existing assertion was weakened; the new shape accurately names the access model after the role split.

### No scope drift

No unplanned refactors. No changes to the `CalendarProvider` interface (Session 2 introduces it), no webhook plumbing, no `bookings` schema changes, no `SlotGeneratorService` nullsafe, no `calendar_pending_actions` table, no notification suppression. Account + Availability settings are untouched — Session 4 still owns their role-split migration. `SettingsNav` is the only layout touchpoint; `settings-layout.tsx` itself was not modified.

---

## Current Project State

- **Backend**:
  - `Dashboard\Settings\CalendarIntegrationController` is the single home for OAuth connect/disconnect.
  - `CalendarIntegration` model gains `token_expires_at` + `google_account_email` fillable/casts; encrypted casts for both tokens remain the only way to round-trip them.
  - Settings routes split into two groups; the shared group uses no extra middleware and relies on the outer `role:admin,staff` guard.
- **Database**: `calendar_integrations` carries `UNIQUE(user_id, provider)` + `token_expires_at` + `google_account_email`. Existing columns (tokens, `calendar_id`, webhook fields) unchanged.
- **Frontend**: New page `dashboard/settings/calendar-integration`. Nav is role-aware. Bundle size 967 kB (was 963 kB — +4 kB for the new page + Alert import).
- **Config / i18n**: `services.google` added. `.env.example` gains three Google env vars. All new user-facing strings flow through `__()` / `useTrans`.
- **Tests**: Pest suite 781 passed, 3253 assertions (Feature 517, Unit 15, Browser 249). +14 from actual pre-session baseline.
- **Decisions**: D-080 + D-081 recorded. No existing decision superseded.
- **Dependencies**: `laravel/socialite` (v5.26) + `google/apiclient` (^2.15) added. No removals.

---

## How to Verify Locally

```bash
php artisan test --compact        # 781 passed
vendor/bin/pint --dirty --format agent  # {"result":"pass"}
npm run build                     # green, ~1.6s
php artisan wayfinder:generate    # idempotent; no diff after run
```

Targeted checks:

```bash
php artisan test --compact --filter='CalendarIntegrationTest|SettingsAuthorization'
# → 17 passed (13 CalendarIntegration + 4 SettingsAuthorization)

php artisan route:list --path=calendar-integration
# → 4 routes: GET|HEAD, DELETE, GET|HEAD (callback), POST (connect)
```

Manual smoke (requires real Google OAuth credentials):

1. Set `GOOGLE_CLIENT_ID` / `GOOGLE_CLIENT_SECRET` / `GOOGLE_REDIRECT_URI` in `.env`. The redirect URI must match exactly what you configure in Google Cloud Console.
2. Register a business, onboard, sign in as admin, visit `/dashboard/settings/calendar-integration`.
3. Click Connect → Google consent → redirect back. Page should show the Google account email + a Disconnect button.
4. Click Disconnect → row deleted, UI returns to the not-connected state.
5. Sign in as a staff member (invited via `/dashboard/settings/staff`) → settings nav shows **only** Calendar Integration. Other settings URLs return 403.

---

## What the Next Session Needs to Know

Next up: **Session 2 — Google Calendar Sync (Bidirectional)** in `docs/roadmaps/ROADMAP-MVP-COMPLETION.md`. Session 2 owns webhooks, push/pull jobs, sync tokens, event mutation, the `bookings.customer_id` / `bookings.service_id` nullability + `bookings.external_title` migration, `calendar_pending_actions`, notification suppression for `source = google_calendar`, and the `CalendarProvider` interface + `GoogleCalendarProvider` implementation.

### Conventions MVPC-1 established that future work must not break

- **D-080 — the OAuth provider is Socialite; the Calendar API client is `google/apiclient`.** No fallback HTTP client, no custom token refresh. Session 2 instantiates `Google\Client` in `GoogleCalendarProvider::clientFor(CalendarIntegration $integration)` using the encrypted tokens from the row; the token-refresh callback writes rotated access tokens + new `token_expires_at` back to the row.
- **D-081 — the shared settings inner group carries no middleware.** Adding a new shared settings page is a one-line route-file change inside the shared group. Admin-only pages stay in the admin-only group. Do NOT re-declare `role:admin,staff` on the inner shared group — the outer dashboard group is the single source of truth for that guard.
- **`CalendarIntegration` is scoped to the User, not the Business.** A provider who works at two businesses has one Google connection, shared across their businesses. Session 2's destination-calendar-per-provider configuration must stay user-keyed.
- **`google_account_email` is the display-only Google account email; `calendar_id` is Session 2's destination calendar ID.** Do not overload either column.
- **Session 2 must preserve the refresh-token fallback in `callback()`.** A re-consent that returns a null refresh token must not overwrite the stored one.
- **Earlier conventions remain unchanged**: D-079 (restore-or-create membership + soft-delete filter), D-078 (structural bookability scope), D-077/D-076/D-075/D-074/D-073/D-067/D-066/D-063/D-061/D-062 remain as described in prior handoffs.

### Session 2 hand-off notes

- The `disconnect()` action has a `// TODO Session 2: stop webhook watch here` comment at the exact call site. Replace with `app(CalendarProviderFactory::class)->for($integration)->stopWatch($integration)` (or the Session 2-agent-chosen equivalent), wrapped in try/catch so a dead/already-stopped channel does not block local row deletion.
- The `error` prop on the Inertia page is already plumbed through `index()` but always null today. Session 2 writes the most recent sync error (from `sync_error` / `sync_error_at`, which Session 2 adds via migration) into this prop, and the frontend renders it in the pre-existing `<Alert variant="error">` banner slot.
- Session 2 is free to rename `GOOGLE_SCOPES` in the controller or add new scopes (e.g., `https://www.googleapis.com/auth/calendar.readonly` for the conflict calendars if Google demands a broader scope). Today's three scopes are the MVP minimum.

---

## Open Questions / Deferred Items

No new carry-overs from MVPC-1.

Earlier carry-overs remain unchanged from the post-R-19 list:
- Tenancy (R-19 carry-overs): R-2B business-switcher UI; admin-driven member deactivation + re-invite flow; "leave business" self-serve UX.
- R-16 frontend code splitting (deferred).
- R-17 carry-overs: admin email/push notification when a service crosses into unbookable post-launch; richer "provider is on vacation" UX on the public page; per-user banner dismiss / ack history.
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
