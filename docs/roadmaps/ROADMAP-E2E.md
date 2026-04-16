# riservo.ch — End-to-End Testing Roadmap (Pest 4 Browser)

> Version: 0.2 — Reviewed & restructured against the current codebase (2026-04-16)
> Status: Planning
> Scope: Full end-to-end browser test coverage across all user-facing flows.
> Tooling: **Pest 4 native browser testing** via `pestphp/pest-plugin-browser` (Playwright under the hood, PHP test authoring).
> Format: WHAT is defined here. HOW is decided by the agent at plan time.
> Every session must leave the full test suite (unit + feature + E2E) green and `npm run build` clean.

---

## Gap & Consistency Report (2026-04-16 pass)

This roadmap was audited against the live codebase. The following issues were found in the previous draft (v0.1) and corrected in v0.2. Implementing agents must follow v0.2 wording, not v0.1.

### Terminology corrections (D-061 retirement of "collaborator")

- The v0.1 roadmap called bookable people **"collaborators"**. The codebase retired that term (D-061) and split it into two distinct concepts:
  - **Staff** — dashboard membership via `business_members` pivot, role `admin` or `staff`. Staff have dashboard access; they are **not** automatically bookable.
  - **Provider** — a first-class `providers` row for a bookable person within a business. Providers have schedules, exceptions, service attachments, and bookings. A staff member becomes bookable by adding a Provider row; an admin can be their own first provider (D-062).
- `allow_collaborator_choice` → **`allow_provider_choice`** (field name, Inertia prop, and SPEC wording).
- `CollaboratorService` pivot → **`ProviderService`** (D-061).
- v0.2 uses **staff** for dashboard-role tests and **provider** for availability/booking tests. "Collaborator" no longer appears.

### Missing routes and pages (added in v0.2)

| Missing coverage | Covered by (v0.2) |
|---|---|
| `customer.register` — `/customer/register` + page `auth/customer-register.tsx` (D-074) | **E2E-1** (customer registration section) |
| `magic-link.verify` — signed URL landing (one-time token per D-037) | **E2E-1** (magic link) |
| `verification.verify` — signed email-verification link | **E2E-1** (registration → verify) |
| `onboarding.enable-owner-as-provider` — one-click admin-as-provider recovery when launch is blocked (D-062) | **E2E-2** (launch gate) |
| `dashboard.welcome` — `/dashboard/welcome` post-onboarding page (`dashboard/welcome.tsx`) | **E2E-2** (post-wizard) |
| `dashboard.customers.*` — Customer Directory (CRM) list + detail + search API | **E2E-4** (new Customer Directory section) |
| `customer.bookings` — `/my-bookings` registered-customer page + cancel | **E2E-3** (registered-customer section) |
| `settings.staff.*` — invite, resend-invitation, cancel-invitation, staff detail, avatar upload | **E2E-5** (Staff & Invitations) |
| `settings.providers.*` — provider toggle, schedule, services sync, exceptions CRUD | **E2E-5** (Providers) |
| `settings.account.*` — admin-as-provider toggle, schedule, exceptions CRUD, services sync (D-062) | **E2E-5** (Account / admin-as-provider) |
| `settings.exceptions.*` — business-level exceptions CRUD | **E2E-5** (Business Exceptions) |
| Auth-recovery throttle (D-072) — per-email + per-IP on `/magic-link` and `/forgot-password` | **E2E-1** (validation + rate-limit) |
| `public/embed.js` popup snippet — `data-riservo-open`, optional `data-riservo-service` | **E2E-6** (popup embed) |
| Soft-deleted provider historical display (D-067) — booking detail shows `(deactivated)` suffix | **E2E-4** (booking detail panel) |
| Manual booking `source=manual`, auto-confirmed, no pending state (D-051) | **E2E-4** (manual booking) |
| Postgres GIST overlap constraint backstop (D-065, D-066) — manual booking into an occupied slot | **E2E-4** (manual booking error path) |
| Public page with `?embed=1` query stripping nav (D-054) | **E2E-6** (iframe embed) |
| Canonical path-form service pre-filter `/{slug}/{service-slug}` (D-070) | **E2E-3** + **E2E-6** |
| Honeypot field `website` on public booking (D-045) — server rejects 422 when filled | **E2E-3** (validation) |
| Cross-business tenant scoping (D-063) — session-pinned `current_business_id` | **E2E-6** (authorization) |
| Customer Directory admin-only scope (staff should not access) | **E2E-4** + **E2E-6** |

### Items corrected (inaccurate in v0.1)

- v0.1 described the **Calendar Integration settings page** as something to test in E2E-5. **That page does not exist** — Session 12 (Google Calendar Sync) has not started. In v0.2 it is explicitly deferred and tracked as a future item behind Session 12.
- v0.1 sample code used `use function Pest\Browser\browser; browser()->visit('/')`. The actual Pest 4 API is the **global `visit()`** function returning a chainable `$page` object. Helper signatures in v0.2 are updated accordingly.
- v0.1 proposed a separate `.env.e2e` and a dedicated E2E database. `phpunit.xml` already configures a testing environment (Postgres `riservo_ch_testing`, `QUEUE_CONNECTION=sync`, `MAIL_MAILER=array`, `CACHE_STORE=array`, `SESSION_DRIVER=array`). v0.2 keeps that environment and uses `DB_DATABASE=riservo_ch_e2e` only if E2E tests must run in parallel with feature tests against the same Postgres instance.
- v0.1 deferred the database-reset-strategy choice to the agent. Pest 4 natively supports `RefreshDatabase` inside browser tests (confirmed in docs). v0.2 prescribes `RefreshDatabase` and removes the open choice.
- v0.1 had E2E-3 depend on E2E-2 and E2E-4/E2E-5 depend on E2E-2. In v0.2, if E2E-0 **fully implements** `BusinessSetup` and `AuthHelper` (not stubs), then E2E-1 through E2E-5 can all run in parallel. Only E2E-6 needs to wait for E2E-3 (for `BookingFlowHelper`).
- The smoke test title check was `riservo` in v0.1; the landing page title may differ. v0.2 lets E2E-0 read the actual title from `resources/js/pages/welcome.tsx` at implementation time.

### Pre-existing packages / config (E2E-0 must not re-install)

- `pestphp/pest` 4.5 and `pestphp/pest-plugin-laravel` 4.1 are already installed.
- `pestphp/pest-plugin-browser` is **not** installed — E2E-0 installs it.
- Playwright is **not** installed on the npm side — E2E-0 runs `npm install playwright@latest` and `npx playwright install --with-deps`.
- Global Pest helpers already present in `tests/Pest.php`: `attachStaff()`, `attachAdmin()`, `attachProvider()` — reuse from `tests/Browser/Support/BusinessSetup.php`, do not duplicate.
- `phpunit.xml` testsuites are `Unit` and `Feature` only. E2E-0 adds a `Browser` testsuite (or uses directory-based filtering via `./vendor/bin/pest --filter=tests/Browser`) so the default feature run stays untouched.
- `RefreshDatabase` is applied globally in `tests/Pest.php` via `pest()->...->in('Feature')`. E2E-0 adds a parallel `->in('Browser')` binding.

---

## Overview

End-to-end tests complement the existing unit and feature suites (currently ~500 tests across `tests/Unit/` and `tests/Feature/`) by driving a real browser through actual user journeys. They cover UI interactions, multi-step flows, and integration between Inertia/React and the Laravel backend that HTTP-level feature tests cannot reach.

The target toolchain is **Pest 4's native browser testing plugin** (`pestphp/pest-plugin-browser`, which wraps Playwright's Chromium driver in Pest's `visit()` API). Tests are authored in PHP alongside the existing Pest suite. Node.js + Playwright is a peer dependency for browser binaries.

---

## Testing Guidelines

These guidelines apply to every E2E session. Deviations must be documented in the session plan.

### Isolation

- Each test uses `RefreshDatabase` (applied globally to the `Browser` directory in `tests/Pest.php`). No shared state between tests.
- No test may rely on data seeded by the development seeder. Preconditions are built with factories inside each test (or through shared helpers in `tests/Browser/Support/`).
- No shared browser session between tests: `visit()` opens a fresh browser context per test.

### Setup via factories, not seeders

- Repeated setup (create a business, complete onboarding, attach a provider, generate bookings) is encapsulated in `tests/Browser/Support/` helpers.
- `BusinessSetup` and `AuthHelper` are fully implemented in E2E-0 so all downstream sessions can use them read-only and run in parallel. Sessions that own other helpers (E2E-2 → `OnboardingHelper`; E2E-3 → `BookingFlowHelper`) extend stubs created in E2E-0.

### Test what the user sees

- Tests interact through the browser: `click`, `type`, `press`, `select`, `check`, `attach`, etc. They assert visible UI state (`assertSee`, `assertUrlIs`, `assertValue`, `assertAttributeContains`, `assertVisible`).
- Direct DB reads are permitted only for assertion purposes (e.g., confirm a booking row was created, then assert the UI reflects it).
- Direct API calls from tests are reserved for setting up signed URLs (magic link, invitation, password reset, email verification) that the browser then follows.

### Coverage contract

- Every named route in `routes/web.php` is touched by at least one E2E test. A coverage matrix lives in `tests/Browser/route-coverage.md` (E2E-0 creates the skeleton, each session appends its rows).
- Every multi-step flow (wizard, booking funnel, invite acceptance) has a happy-path test that walks every step and at least one test covering a critical validation failure.
- Authorization boundaries are tested: each protected route rejects guests, customers, and wrong-business staff. Cross-tenant access (D-063) is exercised in E2E-6.

### Page objects and helpers

- Repeated interactions (login, business creation, onboarding to a known state, booking funnel traversal) live in `tests/Browser/Support/`. No copy-paste.
- Frontend route generation uses Wayfinder in the app — tests may hardcode URL paths where it keeps them concise, but must not invent paths that diverge from `routes/web.php`.

### Stability

- No `sleep()`. Use Pest's built-in waiting via `pressAndWaitFor()`, `wait()`, or implicit waits in assertions.
- Tests run deterministically against business timezone `Europe/Zurich`. Where a test asserts times, it uses `Carbon::setTestNow()` in the Pest `beforeEach()` to freeze the clock.
- Freeze the clock before generating signed URLs whose expiry is asserted (magic link, invitation).

### Failure artefacts

- On failure, Pest writes a screenshot and Playwright trace to `tests/Browser/Screenshots/` and `tests/Browser/Traces/`. Both directories are gitignored (E2E-0 sets this up).

### CI

- Browser tests run as a separate CI job that depends on the unit/feature job passing first. Playwright Chromium is cached between runs.
- Browser tests are runnable locally with `./vendor/bin/pest --filter=Browser` or `./vendor/bin/pest tests/Browser`.
- The default `php artisan test` run stays Unit + Feature only until E2E-0 explicitly adds the `Browser` testsuite.

### Local command cheatsheet (post-E2E-0)

One-time setup on a fresh checkout:

```bash
composer install
npm install
npx playwright install --with-deps chromium
```

Everyday commands:

```bash
# Fast path — Unit + Feature only (no Playwright needed)
php artisan test --testsuite=Unit --testsuite=Feature --compact

# Browser suite only
./vendor/bin/pest --testsuite=Browser

# A single browser test file
./vendor/bin/pest tests/Browser/SmokeTest.php

# Full suite (Unit + Feature + Browser)
php artisan test --compact
```

Debugging flags for the browser suite:

```bash
./vendor/bin/pest --testsuite=Browser --headed       # show the browser window
./vendor/bin/pest --testsuite=Browser --debug        # verbose assertion output
./vendor/bin/pest --testsuite=Browser --browser=firefox   # webkit / chrome also accepted
./vendor/bin/pest --testsuite=Browser --parallel     # what CI runs
```

Failure artefacts (gitignored): screenshots at `tests/Browser/Screenshots/`,
Playwright traces at `tests/Browser/Traces/`. CI uploads both as a GitHub
Actions artifact on failure.

---

## Session E2E-0 — Infrastructure & Tooling Setup

Status: **[MUST RUN FIRST — blocks all other sessions]**
Parallelisable with: **none**
Full suite green on completion: **yes** (unit + feature + one smoke browser test).

### Prerequisites

- [ ] None (this is the first session).

### Deliverable checklist

**Install and verify tooling**
- [ ] `composer require pestphp/pest-plugin-browser --dev` and commit `composer.lock`.
- [ ] `npm install playwright@latest` and commit `package-lock.json`.
- [ ] `npx playwright install --with-deps` (for local dev); CI step installs on-demand.
- [ ] Verify `./vendor/bin/pest --version` still works and the Pest browser binary is discoverable.

**Configure the Pest test harness**
- [ ] Add a `Browser` testsuite to `phpunit.xml` pointing at `tests/Browser`.
- [ ] In `tests/Pest.php`, bind `pest()->extend(TestCase::class)->use(RefreshDatabase::class)->in('Browser')` so `RefreshDatabase` is applied to all browser tests.
- [ ] Set `pest()->browser()->timeout(10000)` (10s) and default to Chrome in `tests/Pest.php`.
- [ ] Add `pest()->project()->github('<org>/<repo>')` if not already set (for `todo()` linkage).

**Environment**
- [ ] Confirm `phpunit.xml` already declares the testing env (Postgres `riservo_ch_testing`, `QUEUE_CONNECTION=sync`, `MAIL_MAILER=array`, `CACHE_STORE=array`, `SESSION_DRIVER=array`). If the local dev DB collides, switch `DB_DATABASE` to `riservo_ch_e2e` for the Browser suite only (via a `<php>` block scoped to the `Browser` testsuite).
- [ ] Ensure `APP_URL` resolves to the test server started by Pest's browser plugin (it binds to `127.0.0.1` by default — no app server command required).

**Directory layout**
- [ ] Create directory skeleton:
  - `tests/Browser/`
  - `tests/Browser/Auth/`
  - `tests/Browser/Onboarding/`
  - `tests/Browser/Booking/`
  - `tests/Browser/Dashboard/`
  - `tests/Browser/Settings/`
  - `tests/Browser/Embed/`
  - `tests/Browser/CrossCutting/`
  - `tests/Browser/Support/`
  - `tests/Browser/Screenshots/` (gitignored)
  - `tests/Browser/Traces/` (gitignored)
- [ ] Add `tests/Browser/Screenshots/` and `tests/Browser/Traces/` to `.gitignore`.

**Fully implement shared helpers (not stubs)**
- [ ] `tests/Browser/Support/BusinessSetup.php` with the full methods listed under "Helper contract" below.
- [ ] `tests/Browser/Support/AuthHelper.php` with the full methods listed under "Helper contract" below.
- [ ] `tests/Browser/Support/OnboardingHelper.php` with **stub** method signatures + `// TODO: E2E-2` — owned by E2E-2.
- [ ] `tests/Browser/Support/BookingFlowHelper.php` with **stub** method signatures + `// TODO: E2E-3` — owned by E2E-3.

**Coverage tracking**
- [ ] Create `tests/Browser/route-coverage.md` — a table with one row per named route in `routes/web.php` and a "covered by" column (empty at E2E-0; each session appends).

**Smoke test**
- [ ] Create `tests/Browser/SmokeTest.php`:
  ```php
  it('loads the landing page', function () {
      $page = visit('/');
      $page->assertSee('riservo')  // adjust to actual <title> or on-page copy
          ->assertNoJavaScriptErrors()
          ->assertNoConsoleLogs();
  });
  ```
- [ ] Run `./vendor/bin/pest tests/Browser/SmokeTest.php` — must pass.
- [ ] Run `./vendor/bin/pest` (full suite) — must pass (unit + feature + browser smoke).

**CI**
- [ ] Add `.github/workflows/browser-tests.yml` (or equivalent). Required steps:
  - `actions/setup-node@v4` with `node-version: lts/*`
  - `npm ci`
  - `npx playwright install --with-deps`
  - Cache the Playwright binary cache directory
  - Run `./vendor/bin/pest tests/Browser --parallel`
- [ ] CI browser job depends on the unit/feature job passing.

### Files created

- `tests/Browser/Support/BusinessSetup.php`
- `tests/Browser/Support/AuthHelper.php`
- `tests/Browser/Support/OnboardingHelper.php`
- `tests/Browser/Support/BookingFlowHelper.php`
- `tests/Browser/SmokeTest.php`
- `tests/Browser/route-coverage.md`
- `tests/Browser/Screenshots/.gitkeep` (if needed)
- `tests/Browser/Traces/.gitkeep` (if needed)
- `.github/workflows/browser-tests.yml` (or amend existing CI)

### Files modified

- `composer.json`, `composer.lock`
- `package.json`, `package-lock.json`
- `phpunit.xml` (add `Browser` testsuite)
- `tests/Pest.php` (bind `RefreshDatabase` + configure Pest browser defaults)
- `.gitignore` (screenshots, traces)

### Helper contract (E2E-0 must implement fully)

```php
// BusinessSetup.php — fully implemented; downstream sessions are read-only consumers.
public static function createBusinessWithAdmin(array $overrides = []): array;
    // Returns ['business' => Business, 'admin' => User] with admin attached via BusinessMember (role=admin).
    // admin has email_verified_at=now() and 'password' as plaintext password.

public static function createBusinessWithStaff(int $count = 1, array $overrides = []): array;
    // Returns ['business', 'admin', 'staff' => Collection<User>]; each staff attached via BusinessMember (role=staff).

public static function createBusinessWithService(array $serviceOverrides = []): array;
    // Returns ['business', 'admin', 'service'] with one active Service attached to the business.

public static function createLaunchedBusiness(array $overrides = []): array;
    // Returns ['business', 'admin', 'provider', 'service', 'customer'] representing a business that has completed onboarding:
    //   - BusinessHour rows for all 7 days (Mon–Fri 09:00–18:00, closed on weekends)
    //   - one Service (60 min, 30-min interval, no buffers)
    //   - admin opted in as Provider with AvailabilityRules mirroring BusinessHours
    //   - Service attached to the provider via provider_services pivot
    //   - onboarding_completed_at=now(), onboarding_step=5
    //   - one seed Customer row

public static function createBusinessWithProviders(int $providerCount = 2, array $overrides = []): array;
    // Used by E2E-3 / E2E-4 / E2E-5 tests that need multiple providers.
```

```php
// AuthHelper.php — fully implemented.
public static function loginAs($page, User $user, string $password = 'password'): mixed;
    // $page is a Pest Browser page. Fills /login form and submits. Returns the page after redirect.

public static function loginViaMagicLink($page, User $user): mixed;
    // Generates a temporary signed URL via the MagicLinkNotification route and visits it directly.

public static function logout($page): mixed;
    // Clicks the logout control. Returns the page.

public static function loginAsCustomer($page, User $customerUser, string $password = 'password'): mixed;
    // Submits /login form with a customer-role user; expects redirect to /my-bookings.
```

```php
// OnboardingHelper.php — STUB in E2E-0, implemented in E2E-2.
public static function completeWizard($page, User $admin, array $options = []): Business;
public static function advanceToStep($page, User $admin, int $step): void;
```

```php
// BookingFlowHelper.php — STUB in E2E-0, implemented in E2E-3.
public static function bookAsGuest($page, Business $business, Service $service, array $customerDetails = []): string;
public static function bookAsRegistered($page, Business $business, Service $service, Customer $customer): string;
```

### What E2E-0 must NOT do

- No functional test logic beyond the single smoke test.
- No application source-code changes.
- No modification of `tests/Unit/` or `tests/Feature/` or their global config.
- No implementation inside `OnboardingHelper` or `BookingFlowHelper` bodies (stubs only — TODO comments point to the owning session).

---

## Session E2E-1 — Authentication Flows

Status: **[CAN RUN IN PARALLEL]**
Parallelisable with: **E2E-2, E2E-3, E2E-4, E2E-5**
Full suite green on completion: **yes**.

### Prerequisites

- [ ] E2E-0 complete (helpers `BusinessSetup`, `AuthHelper` fully implemented).

### Test checklist

**Business owner registration (`GET/POST /register`, `auth/register.tsx`)**
- [ ] Fresh visitor submits the registration form (name, email, business name, password, password confirmation) and is redirected to `/email/verify` (verification notice). Business is created with `onboarding_step=1` and admin `BusinessMember` is attached.
- [ ] Registering with an already-used email shows the "email has already been taken" validation error and stays on `/register`.
- [ ] Submitting with missing `name`, `email`, `password`, or `business_name` shows per-field errors under the corresponding inputs.
- [ ] Password confirmation mismatch shows an error.

**Email verification (`GET /email/verify`, `GET /email/verify/{id}/{hash}`, `POST /email/verification-notification`)**
- [ ] Unverified admin who visits `/dashboard` is redirected to `/email/verify`.
- [ ] Clicking "resend verification" submits `/email/verification-notification` and shows a flash-success message.
- [ ] Following a valid signed verification link marks the email verified and redirects to `/onboarding/step/1` (not `/dashboard`).
- [ ] Following a tampered verification link shows an error page (403 or invalid-signature notice).

**Password login (`GET/POST /login`, `auth/login.tsx`)**
- [ ] Admin with completed onboarding logs in with correct credentials → redirected to `/dashboard`.
- [ ] Admin without onboarding logs in → redirected to `/onboarding/step/{current}`.
- [ ] Staff logs in → redirected to `/dashboard`.
- [ ] Customer-role user logs in → redirected to `/my-bookings` (confirmed in `tests/Feature/Auth/LoginTest.php`).
- [ ] Wrong password shows `email`-scoped error.
- [ ] Unverified-email admin who logs in is redirected to `/email/verify`.
- [ ] After 5 wrong attempts, the 6th submit returns a throttled error (`email` session error) — covers the built-in Laravel login throttle.

**Logout (`POST /logout`)**
- [ ] Authenticated admin clicks "Sign out"; is redirected to `/login` (or `/`).
- [ ] Immediately revisiting `/dashboard` redirects to `/login`.

**Magic link request (`GET/POST /magic-link`, `auth/magic-link.tsx`)**
- [ ] Admin submits email on `/magic-link` → success flash shown, no redirect.
- [ ] Staff submits email on `/magic-link` → same success flow.
- [ ] Unknown email submitted → form still shows success (no user enumeration per D-072).
- [ ] Rate-limit: per-email throttle fires after N submissions within the window (value from `config/auth.throttle.*`); test asserts an error is shown on the (N+1)-th submit.
- [ ] Rate-limit: per-IP throttle fires after more attempts across different emails.

**Magic link verify (`GET /magic-link/verify/{user}`, signed)**
- [ ] Test generates a signed URL via `URL::temporarySignedRoute('magic-link.verify', …, ['user' => $user->id, 'token' => $fresh])`. Visiting it logs the user in and redirects them by role (admin → dashboard/onboarding, staff → dashboard, customer → my-bookings).
- [ ] One-time use: visiting the same link a second time shows an invalid-link message (token has been consumed per D-037).
- [ ] Expired signed URL (past 15-min expiry via frozen clock) shows the expired-link error page.
- [ ] Tampered signature (invalid hash) shows the invalid-link error page.

**Password reset — request (`GET/POST /forgot-password`, `auth/forgot-password.tsx`)**
- [ ] Admin enters an email and submits → success flash shown.
- [ ] Unknown email → success flash (no enumeration).
- [ ] Per-email and per-IP throttle fires per D-072.

**Password reset — form (`GET /reset-password/{token}`, `POST /reset-password`, `auth/reset-password.tsx`)**
- [ ] Test calls the password-broker directly to mint a reset URL, then visits it in the browser.
- [ ] Submitting a new valid password → redirected to `/login` (or `/dashboard` depending on app behaviour) with flash-success; the new password works.
- [ ] Mismatched `password_confirmation` → field error.
- [ ] Invalid/expired token → error page.

**Staff invitation accept (`GET /invite/{token}`, `POST /invite/{token}`, `auth/accept-invitation.tsx`)**
- [ ] Admin-seeded `BusinessInvitation` → visiting `/invite/{token}` shows the set-password form with the business name and invited email visible.
- [ ] Staff sets a password and submits → `BusinessMember` row created with `role=staff`, user is logged in and redirected to `/dashboard`.
- [ ] Expired invitation (past 48 h per D-036) → error page, no user created.
- [ ] Re-visiting an already-accepted token → invalid-token error page (one-time use).
- [ ] Password-strength validation errors are shown on the set-password form.

**Customer registration (`GET/POST /customer/register`, `auth/customer-register.tsx`) — D-074**
- [ ] Fresh visitor fills the customer registration form (name, email, phone, password) → submitted → redirected to `/my-bookings` (or `/login`) with a new User + Customer row linked by email.
- [ ] Registering with an email that already has a Customer row links the existing Customer to the new User (no duplicate Customer) — assert via DB query in the test.
- [ ] No prior booking is required (D-074) — the test passes with a brand-new email.
- [ ] Missing required field shows per-field validation error.

**Route protection and role enforcement**
- [ ] Guest visits `/dashboard` → redirected to `/login`.
- [ ] Guest visits `/dashboard/settings/profile` → redirected to `/login`.
- [ ] Guest visits `/my-bookings` → redirected to `/login`.
- [ ] Staff visits `/dashboard/settings/profile` → 403 or redirect (admin-only per `role:admin` middleware).
- [ ] Staff visits `/dashboard/customers` → 403 or redirect.
- [ ] Customer visits `/dashboard` → redirected or 403.
- [ ] Admin without completed onboarding visits `/dashboard` → redirected to `/onboarding/step/{current}` (onboarded middleware).
- [ ] Admin with completed onboarding visits `/onboarding/step/1` → redirected to `/dashboard`.

### Files to create

- `tests/Browser/Auth/RegistrationTest.php`
- `tests/Browser/Auth/EmailVerificationTest.php`
- `tests/Browser/Auth/LoginTest.php`
- `tests/Browser/Auth/LogoutTest.php`
- `tests/Browser/Auth/MagicLinkRequestTest.php`
- `tests/Browser/Auth/MagicLinkVerifyTest.php`
- `tests/Browser/Auth/PasswordResetTest.php`
- `tests/Browser/Auth/InviteAcceptanceTest.php`
- `tests/Browser/Auth/CustomerRegistrationTest.php`
- `tests/Browser/Auth/RouteProtectionTest.php`

### Files to modify

- `tests/Browser/route-coverage.md` — tick all auth-related named routes.

Does **not** modify any file in `tests/Browser/Support/` (AuthHelper was completed in E2E-0).

---

## Session E2E-2 — Business Onboarding Wizard

Status: **[CAN RUN IN PARALLEL]**
Parallelisable with: **E2E-1, E2E-3, E2E-4, E2E-5**
Full suite green on completion: **yes**.

### Prerequisites

- [ ] E2E-0 complete (`BusinessSetup`, `AuthHelper` ready; `OnboardingHelper` stubs created).

### Test checklist

**Wizard happy-path (`GET/POST /onboarding/step/{1..5}`)**
- [ ] Freshly registered, email-verified admin visits `/onboarding/step/1` → page renders with pre-filled business name.
- [ ] Step 1: fills business description, contact info (phone, email, address), and a valid slug → submits → redirect to `/onboarding/step/2`.
- [ ] Step 2: sets weekly hours on at least two days (open/close windows) → submits → redirect to `/onboarding/step/3`.
- [ ] Step 3: creates a first service (name, duration 60, price 50, default slot interval) → submits → redirect to `/onboarding/step/4`.
- [ ] Step 4: skips staff invites → submits → redirect to `/onboarding/step/5`.
- [ ] Step 5: public booking URL is visible, the "Copy" button is present, "Go to Dashboard" is enabled, clicking it redirects to `/dashboard/welcome`.
- [ ] After completion, `Business.onboarding_completed_at` is non-null and `onboarding_step = 5` (asserted via DB read).

**Admin opts in as provider (D-062)**
- [ ] During Step 3, admin checks "Yes, I'll take bookings myself"; the weekly-schedule form for the admin's own availability appears and is completed.
- [ ] On completion, a `Provider` row exists for the admin (`user_id` set, not soft-deleted).
- [ ] The public `/{slug}` page lists the admin as a bookable provider.

**Slug availability check (`POST /onboarding/slug-check`)**
- [ ] Typing a free slug shows "available" indicator via the `useHttp` endpoint.
- [ ] Typing an already-taken slug shows "unavailable".
- [ ] Typing a reserved slug (`dashboard`, `login`, `bookings`, `booking`, `register`, `api`, `admin` per D-039/D-046) shows "unavailable".
- [ ] Typing the business's own current slug shows "available" (self-match is permitted).

**Logo upload (`POST /onboarding/logo-upload`)**
- [ ] Attaching a valid image → JSON response with `path` + `url`; logo preview shown in Step 1.
- [ ] Attaching a non-image (PDF) → 422 error.

**Staff invite during onboarding (Step 4)**
- [ ] Admin enters a staff email + selects service(s) from the checkboxes (service pre-assignment via `service_ids` JSON per D-041) → submits → after wizard completion, a `BusinessInvitation` row exists with the selected `service_ids`.
- [ ] Multiple invites can be added on Step 4.

**Launch gate (D-062)**
- [ ] Admin completes Steps 1–4 but creates an active service with zero providers in Step 3 (does not opt in as provider, does not invite a staff provider) → clicks "Launch" on Step 5 → redirected back to Step 3 with a visible error naming the service(s) blocking launch.
- [ ] Admin clicks the "Enable me as a provider" one-click recovery (`POST /onboarding/enable-owner-as-provider`) → admin's Provider row is created, attached to every active service, with a default schedule; the admin is returned to Step 5; "Launch" now succeeds.

**Wizard resume (D-040)**
- [ ] Admin completes Steps 1–2 and closes the browser (session persists). New `visit()` call — admin logs in again and lands on `/onboarding/step/3` (not Step 1).

**Validation errors**
- [ ] Step 1 submitted with empty `name` → error visible; user stays on Step 1.
- [ ] Step 1 submitted with slug format `INVALID SLUG!` → slug error visible.
- [ ] Step 2 submitted with `close_time <= open_time` → error visible.
- [ ] Step 3 submitted with `duration_minutes = 0` → error visible.

**Post-wizard state**
- [ ] Admin with `onboarding_completed_at` visiting `/onboarding/step/1` is redirected to `/dashboard`.
- [ ] `/dashboard/welcome` shows the public booking URL, the business name, and next-step CTAs. Clicking "Go to dashboard" lands on `/dashboard`.

### Files to create

- `tests/Browser/Onboarding/WizardHappyPathTest.php`
- `tests/Browser/Onboarding/WizardAdminAsProviderTest.php`
- `tests/Browser/Onboarding/WizardSlugCheckTest.php`
- `tests/Browser/Onboarding/WizardLogoUploadTest.php`
- `tests/Browser/Onboarding/WizardStaffInviteTest.php`
- `tests/Browser/Onboarding/WizardLaunchGateTest.php`
- `tests/Browser/Onboarding/WizardResumeTest.php`
- `tests/Browser/Onboarding/WizardValidationTest.php`
- `tests/Browser/Onboarding/WizardPostCompletionTest.php`
- `tests/Browser/Onboarding/DashboardWelcomeTest.php`

### Files to modify

- `tests/Browser/Support/OnboardingHelper.php` — fill in stubs (exclusive owner).
- `tests/Browser/route-coverage.md` — append onboarding routes.

Does **not** modify `BusinessSetup.php` or any other support file.

---

## Session E2E-3 — Public Booking Flow

Status: **[CAN RUN IN PARALLEL]**
Parallelisable with: **E2E-1, E2E-2, E2E-4, E2E-5**
Full suite green on completion: **yes**.

### Prerequisites

- [ ] E2E-0 complete (`BusinessSetup.createLaunchedBusiness`, `AuthHelper` ready; `BookingFlowHelper` stubs created).

### Test checklist

**Business landing page (`GET /{slug}`, `booking/show.tsx`)**
- [ ] Visiting `/{slug}` for a launched business shows the business name, description, logo, and list of active services.
- [ ] Inactive (`is_active=false`) services are not listed.
- [ ] Services without any eligible provider (D-062) are hidden from the list.
- [ ] Visiting `/{nonexistent-slug}` shows a 404-style "Not found" page (or the framework's default 404 view).

**Service pre-filter (canonical path form per D-070)**
- [ ] Visiting `/{slug}/{service-slug}` auto-selects the matching service and skips the service-selection step:
  - If `allow_provider_choice=true` the flow opens on the Provider step.
  - If `allow_provider_choice=false` the flow opens on the Date/Time step.
- [ ] Visiting `/{slug}/{unknown-service-slug}` falls through to the service picker (no error — per D-070).

**Guest booking — happy path (provider choice enabled)**
- [ ] Customer completes: service → provider (pick a specific named provider) → date → time slot → fills customer details (name, email, phone, optional notes) → summary → confirm.
- [ ] Confirmation step shows service name, provider name, correct date/time (rendered in business timezone `Europe/Zurich`), and a management link (`/bookings/{token}`).
- [ ] Booking row exists with `status=pending` or `status=confirmed` (depending on `Business.confirmation_mode`), `source=riservo`.
- [ ] Customer row is created via `Customer::firstOrCreate(['email' => …])` — a second booking with the same email re-uses the existing Customer.

**Guest booking — "Any available" provider**
- [ ] With `allow_provider_choice=true` and 2+ providers, selecting "Any available" completes the flow. The confirmation names the auto-assigned provider.

**Guest booking — automatic provider assignment (D-068)**
- [ ] With `allow_provider_choice=false`, the provider-selection step is skipped entirely. Booking completes with a silently-assigned provider.
- [ ] Even when the client submits a specific `provider_id`, the server ignores it (per D-068). Test: mutate the request via the browser devtools is impractical — instead test this at the HTTP level in feature tests; at the browser level, assert the provider step is absent.

**No-availability UX (D-038 public slot UX)**
- [ ] When no slots exist for the currently visible week, the "No availability this week" message is shown and the "Try next week" button navigates forward.
- [ ] When a specific provider has no slots but other providers do, the "No availability for [Name] — view other providers" prompt is shown.
- [ ] Calendar days with no slots are visually greyed out (not hidden).

**Customer-details form validation**
- [ ] Submit with empty `name` / `email` / `phone` → per-field errors, no advance.
- [ ] Invalid email format → email error.
- [ ] Filling the honeypot field `website` (D-045) → form submit returns 422; UI shows a generic error (test asserts the booking was not created via DB).

**Confirmation and management link**
- [ ] After booking, `/bookings/{token}` shows service, provider, date/time, status, and customer info.
- [ ] If booking is within the cancellation window (D-016), "Cancel booking" button is visible.
- [ ] Clicking cancel and confirming → booking row `status=cancelled`, UI shows the cancelled state.
- [ ] If booking is outside the cancellation window, the cancel button is hidden or disabled.

**Registered-customer booking flow (D-074)**
- [ ] Customer who has registered (`/customer/register`) and is logged in visits `/{slug}`; the customer-details form is pre-filled with their name/email/phone (via `customerPrefill`).
- [ ] Completing the booking creates a Booking with the existing Customer row linked to the logged-in User.

**Registered customer's own bookings list (`GET /my-bookings`, `customer/bookings.tsx`)**
- [ ] Logged-in customer visits `/my-bookings` → two sections render: "Upcoming" and "Past". Each row shows service, business name, provider (with `(deactivated)` suffix when soft-deleted per D-067), date/time in the business timezone, and status.
- [ ] An upcoming row with `can_cancel=true` shows an inline Cancel button; clicking it posts to `POST /my-bookings/{booking}/cancel` and the row moves to Past with `status=cancelled`.
- [ ] An upcoming row with `can_cancel=false` (outside the cancellation window) does not show the Cancel button.
- [ ] Empty states: "No upcoming bookings" / "No past bookings" are shown when the respective section is empty.

**Rate limiting (`booking-create` — 5/min/IP)**
- [ ] After 5 successful or attempted booking submissions in a minute, the 6th returns 429. Test asserts via direct POST; UI may not have an explicit rate-limit surface, so the HTTP response is the assertion.

**Rate limiting (`booking-api` — 60/min/IP)**
- [ ] Rapidly querying `/booking/{slug}/slots` or `/booking/{slug}/available-dates` 60+ times in a minute returns 429.

### Files to create

- `tests/Browser/Booking/LandingPageTest.php`
- `tests/Browser/Booking/ServicePreFilterTest.php`
- `tests/Browser/Booking/GuestBookingHappyPathTest.php`
- `tests/Browser/Booking/AnyAvailableProviderTest.php`
- `tests/Browser/Booking/AutoAssignProviderTest.php`
- `tests/Browser/Booking/NoAvailabilityUxTest.php`
- `tests/Browser/Booking/ValidationTest.php`
- `tests/Browser/Booking/HoneypotTest.php`
- `tests/Browser/Booking/ConfirmationAndCancelTest.php`
- `tests/Browser/Booking/RegisteredCustomerFlowTest.php`
- `tests/Browser/Booking/MyBookingsTest.php`
- `tests/Browser/Booking/RateLimitTest.php`

### Files to modify

- `tests/Browser/Support/BookingFlowHelper.php` — fill in stubs (exclusive owner).
- `tests/Browser/route-coverage.md` — append public booking + customer area routes.

Does **not** modify `BusinessSetup.php` or `AuthHelper.php`.

---

## Session E2E-4 — Dashboard: Bookings, Calendar & Customer Directory

Status: **[CAN RUN IN PARALLEL]**
Parallelisable with: **E2E-1, E2E-2, E2E-3, E2E-5**
Full suite green on completion: **yes**.

### Prerequisites

- [ ] E2E-0 complete (`BusinessSetup.createLaunchedBusiness` usable; `AuthHelper` ready).

### Test checklist

**Dashboard home (`GET /dashboard`, `dashboard.tsx`)**
- [ ] Admin lands on the dashboard and sees the four stat cards (`Today`, `This week`, `Upcoming`, `Awaiting confirmation`) with the counts from the `stats` prop.
- [ ] Admin sees the "Today's bookings" list with today's seeded bookings (service, time, customer, provider, status badge) rendered in the business timezone.
- [ ] Clicking "View all bookings" (or equivalent link) navigates to `/dashboard/bookings`.
- [ ] Staff visits `/dashboard` → page renders without 403 and without JS errors; stat counts reflect the server-side scope the controller applies for the staff role.
- [ ] Guest visiting `/dashboard` is redirected to `/login` (covered in E2E-1 route protection — cross-reference).

**Bookings list (`GET /dashboard/bookings`, `dashboard/bookings.tsx`)**
- [ ] Admin sees bookings across all providers.
- [ ] Staff linked to a Provider sees only their own bookings.
- [ ] Filter by status (`pending`, `confirmed`, `cancelled`, `completed`, `no_show`) updates the list via Inertia partial reload (URL reflects the filter).
- [ ] Filter by provider (admin only) updates the list.
- [ ] Filter by date range updates the list.
- [ ] Pagination navigates to page 2 and returns the expected bookings.
- [ ] Server-side sort (by start time asc/desc) changes list order.

**Booking detail panel (open from list row)**
- [ ] Clicking a row opens the panel with customer name, email, phone, service, provider, date/time, status, and internal notes (D-049).
- [ ] For a soft-deleted provider (D-067), the panel shows the provider's name with a `(deactivated)` suffix.
- [ ] External bookings (`source=google_calendar`) show "External Event" with no customer details (factory-seeded; actual Google sync is deferred to Session 12).

**Status transitions (`PATCH /dashboard/bookings/{booking}/status`)**
- [ ] Admin confirms a `pending` booking → status chip becomes `confirmed`.
- [ ] Admin cancels a `confirmed` booking → status chip becomes `cancelled`.
- [ ] Admin marks as `no_show` → chip updates.
- [ ] Admin marks as `completed` → chip updates.
- [ ] Staff cannot change status on admin-only actions where SPEC disallows (assert button absent or disabled).

**Internal notes (`PATCH /dashboard/bookings/{booking}/notes`)**
- [ ] Admin adds a note → reload shows the note persisted.
- [ ] Clearing the note persists the empty state.

**Manual booking creation (`POST /dashboard/bookings`)**
- [ ] Admin opens the manual booking dialog, searches for an existing customer by name/email, selects them, picks service + provider + date + time slot → submits → new booking appears in the list with `source=manual`, `status=confirmed` (D-051).
- [ ] Admin creates a new customer inline (email not in directory) → new Customer row is created.
- [ ] Manual booking into a slot that overlaps an existing booking → error surface shows a "slot unavailable" message (Postgres GIST constraint D-066 acts as a backstop; feature tests cover the race condition).

**Calendar views (`GET /dashboard/calendar`, `dashboard/calendar.tsx`)**
- [ ] Month view renders without JS errors and shows seeded bookings in the correct day cells.
- [ ] Week view renders with time-proportional blocks; a seeded booking at 10:00 Mon is visible.
- [ ] Day view renders hour-by-hour with the current time indicator visible when in range.
- [ ] Switching view (day / week / month) updates URL state and re-renders.
- [ ] Navigation (prev / today / next) updates the date range and fetches new bookings.
- [ ] Clicking a booking block opens the booking detail panel.

**Calendar — admin provider filter**
- [ ] Admin toggles individual providers off; their bookings disappear.
- [ ] Toggling all providers off shows "No providers selected" empty state.
- [ ] Each provider's bookings are colour-coded (assert data-attribute or computed style).

**Calendar — staff scope**
- [ ] Staff linked to Provider X logs in → calendar shows only Provider X's bookings; filter UI is absent.
- [ ] Staff not linked to any Provider sees an empty calendar with a helpful message.

**Customer Directory (`GET /dashboard/customers`, `dashboard.customers.*`) — admin only**
- [ ] Admin visits `/dashboard/customers` → table of all customers who have at least one booking with the business.
- [ ] Search by name / email / phone filters the list (Inertia partial reload via `GET /dashboard/api/customers/search`).
- [ ] Pagination works.
- [ ] Clicking a customer row navigates to `/dashboard/customers/{customer}`.
- [ ] Customer detail page shows contact info, total visits, last visit date, and the full booking history with this business only (scoped via `whereHas('bookings', …->where('business_id', …))`).
- [ ] Staff who attempts `/dashboard/customers` → 403 or redirect.

**Dashboard API routes (consumed by UI, covered indirectly)**
- [ ] `dashboard.api.available-dates` and `dashboard.api.slots` are exercised during manual-booking creation (slot picker).
- [ ] `dashboard.api.customers.search` is exercised during manual-booking customer search and Customer Directory search.

### Files to create

- `tests/Browser/Dashboard/DashboardHomeTest.php`
- `tests/Browser/Dashboard/BookingsListTest.php`
- `tests/Browser/Dashboard/BookingDetailPanelTest.php`
- `tests/Browser/Dashboard/StatusTransitionsTest.php`
- `tests/Browser/Dashboard/InternalNotesTest.php`
- `tests/Browser/Dashboard/ManualBookingTest.php`
- `tests/Browser/Dashboard/CalendarViewsTest.php`
- `tests/Browser/Dashboard/CalendarProviderFilterTest.php`
- `tests/Browser/Dashboard/CalendarStaffScopeTest.php`
- `tests/Browser/Dashboard/CustomerDirectoryTest.php`
- `tests/Browser/Dashboard/CustomerDetailTest.php`

### Files to modify

- `tests/Browser/route-coverage.md` — append dashboard routes.

Does **not** modify any `Support/` file (all helpers already complete from E2E-0).

---

## Session E2E-5 — Dashboard: Settings

Status: **[CAN RUN IN PARALLEL]**
Parallelisable with: **E2E-1, E2E-2, E2E-3, E2E-4**
Full suite green on completion: **yes**.

### Prerequisites

- [ ] E2E-0 complete.

### Test checklist

**Business profile (`GET/PUT /dashboard/settings/profile`, `dashboard/settings/profile.tsx`)**
- [ ] Admin edits name, description, phone, email, address → saves → changes persist on reload.
- [ ] Admin uploads a logo via `POST /dashboard/settings/profile/logo` → logo preview visible in the header.
- [ ] Admin removes the logo (empty submit) → logo reverts to initials (D-076 physical delete: old file is missing from disk — assert via `Storage::disk('public')->assertMissing($old)`).
- [ ] Admin changes the slug → new URL is shown; visiting the old `/{old-slug}` returns 404; `/{new-slug}` loads the public page.
- [ ] `POST /dashboard/settings/profile/slug-check` — live-availability endpoint returns "available" / "unavailable" / "reserved" correctly (same contract as onboarding slug-check).

**Booking settings (`GET/PUT /dashboard/settings/booking`, `dashboard/settings/booking.tsx`)**
- [ ] Toggle `confirmation_mode` from auto to manual → saves → new public bookings are created with `status=pending`.
- [ ] Toggle `allow_provider_choice` off → saves → public `/{slug}` page no longer shows the provider-selection step (D-068 server enforcement).
- [ ] Change `cancellation_window_hours` → saves → customer management page shows the cancel button only when outside the new window.
- [ ] Change `reminder_hours` (e.g. `[24,1]` → `[24]`) → saves → value persists.
- [ ] Change `payment_mode` → saves (backend enum; UI shows the selected value).

**Business hours (`GET/PUT /dashboard/settings/hours`, `dashboard/settings/hours.tsx`)**
- [ ] Admin disables a day → saves → public booking calendar greys out that day.
- [ ] Admin adds a second time window to one day (morning + afternoon) → saves → public page offers slots in both windows.
- [ ] Invalid range (close ≤ open) → validation error, no save.

**Business exceptions (`GET /dashboard/settings/exceptions`, `dashboard/settings/exceptions.tsx`, CRUD routes)**
- [ ] Admin adds a full-day closure for a future date → date is unavailable on the public page.
- [ ] Admin adds a partial-day `block` exception (10:00–11:00) → that window is unavailable.
- [ ] Admin adds an `open` exception that extends availability outside normal hours → slot appears.
- [ ] Admin edits an exception → updated values persist.
- [ ] Admin deletes an exception → date/window becomes available again.

**Services (`GET /dashboard/settings/services`, `dashboard/settings/services/*`)**
- [ ] Admin creates a new service (name, duration, price, buffers, slot interval, active providers) → service appears on public page.
- [ ] Admin edits a service (change price, duration) → updated values visible publicly.
- [ ] Admin deactivates a service (`is_active=false`) → service disappears from public page.
- [ ] Admin assigns a provider to the service → provider is selectable for that service in the booking flow.
- [ ] Admin removes a provider from the service → provider is no longer offered.

**Staff & Invitations (`GET /dashboard/settings/staff`, `settings.staff.*`)**
- [ ] Admin invites a new staff member by email (optionally with pre-assigned services per D-041) → invitation appears in the list with "pending" state.
- [ ] Admin resends an invitation → new token generated; old link is invalid.
- [ ] Admin cancels a pending invitation → invitation removed from the list.
- [ ] Admin opens staff detail (`/dashboard/settings/staff/{user}`) → sees name, email, role, provider status.
- [ ] Admin uploads a staff avatar (`POST /dashboard/settings/staff/{user}/avatar`) → avatar visible in the staff list.
- [ ] Admin soft-deletes a staff member → removed from active staff list; their Provider row is also soft-deleted per D-061.

**Providers (`settings.providers.*`)**
- [ ] Admin toggles a staff member on as a provider (`POST /dashboard/settings/providers/{provider}/toggle`) → Provider row exists; appears in booking flow.
- [ ] Admin toggles the provider off → soft-deleted; disappears from booking flow; historical bookings still show them with `(deactivated)` suffix (D-067).
- [ ] Admin updates provider weekly schedule (`PUT /dashboard/settings/providers/{provider}/schedule`) → AvailabilityRules reflect the change; public slots align.
- [ ] Admin syncs provider services (`PUT /dashboard/settings/providers/{provider}/services`) → pivot rows updated.
- [ ] Admin adds / edits / deletes a provider exception (CRUD on `/dashboard/settings/providers/{provider}/exceptions`) → exceptions affect public slot generation.

**Account — admin as own provider (`settings.account.*`, `dashboard/settings/account.tsx`) (D-062)**
- [ ] Admin who has not opted in sees the "Become bookable" CTA.
- [ ] Toggle on (`POST /dashboard/settings/account/toggle-provider`) → admin's Provider row is created, attached to all active services (per D-062 default).
- [ ] Admin updates their own weekly schedule (`PUT /dashboard/settings/account/schedule`) → persists.
- [ ] Admin adds / edits / deletes own exception.
- [ ] Admin updates own service attachments (`PUT /dashboard/settings/account/services`).
- [ ] Admin with `upcomingBookingsCount > 0` who tries to toggle off sees a confirmation AlertDialog; confirming soft-deletes the Provider row but leaves historical bookings intact (D-067).

**Embed & Share (`GET /dashboard/settings/embed`, `dashboard/settings/embed.tsx`)**
- [ ] Page renders the iframe snippet and the popup JS snippet.
- [ ] Copy buttons trigger clipboard writes (assert by intercepting the `navigator.clipboard.writeText` call or by asserting the button's transient "Copied" state).
- [ ] Live preview iframe renders the business's `/{slug}` inside the page.
- [ ] Per-service snippets are shown if the business has services.

### Calendar Integration (Google) — **DEFERRED**

- [ ] **[DEFERRED — DEPENDS ON: ROADMAP Session 12 (Google Calendar Sync)]**
  The Calendar Integration settings page does not exist in the codebase as of 2026-04-16. Once Session 12 lands, a follow-up browser session (proposed E2E-7) will cover:
  - Settings page renders for admin; connect/disconnect buttons visible.
  - Connected state shows linked Google account email.
  - Unauthorized access (guest/staff) redirects or 403s.
  - OAuth round-trip is not exercised end-to-end in the browser suite; UI states are covered with factory-seeded `CalendarIntegration` rows.

### Files to create

- `tests/Browser/Settings/BusinessProfileTest.php`
- `tests/Browser/Settings/BookingSettingsTest.php`
- `tests/Browser/Settings/BusinessHoursTest.php`
- `tests/Browser/Settings/BusinessExceptionsTest.php`
- `tests/Browser/Settings/ServicesTest.php`
- `tests/Browser/Settings/StaffTest.php`
- `tests/Browser/Settings/ProvidersTest.php`
- `tests/Browser/Settings/AccountTest.php`
- `tests/Browser/Settings/EmbedShareTest.php`

### Files to modify

- `tests/Browser/route-coverage.md` — append settings routes.

Does **not** modify any `Support/` file.

---

## Session E2E-6 — Embed Modes & Cross-Cutting Concerns

Status: **[DEPENDS ON: E2E-3]** (uses `BookingFlowHelper`)
Parallelisable with: **none** (must run after E2E-3; E2E-5 is optional for coverage of the Embed & Share settings page, but E2E-6 itself does not modify settings files).
Full suite green on completion: **yes**.

### Prerequisites

- [ ] E2E-0 complete.
- [ ] E2E-3 complete (`BookingFlowHelper` implemented).

### Test checklist

**Iframe embed mode (`GET /{slug}?embed=1`, D-054)**
- [ ] Visiting `/{slug}?embed=1` strips the main navigation and footer; the booking form is flush with the viewport.
- [ ] The full booking funnel completes in embed mode; confirmation screen renders.
- [ ] Service pre-filter + embed together (`/{slug}/{service-slug}?embed=1`) works as in E2E-3 (service auto-selected).

**Popup embed mode (`public/embed.js`)**
- [ ] Test fixture HTML file (served by a tiny test route or loaded inline via Pest's ability to navigate to a file URL or data URL) includes `<script src="/embed.js" data-slug="{slug}"></script>` and `<button data-riservo-open>Book Now</button>`.
- [ ] Clicking the button opens the booking form in a modal overlay (iframe inside a lightbox).
- [ ] Booking completes inside the popup; closing the popup returns to the host page.
- [ ] Per-button `data-riservo-service="{service-slug}"` pre-filters to that service in the popup.
- [ ] Multiple buttons with different services on the same page work independently.

**Authorization edge cases**
- [ ] Admin of Business A logs in, then visits a deep URL for Business B's dashboard (`/dashboard/customers/{customer-of-B}`) → 403 or redirect (D-063 tenant context).
- [ ] Staff of Business A cannot see Business B's bookings list (filtered out server-side).
- [ ] A user who belongs to both Business A and Business B sees the correct scoped content based on the session's `current_business_id` (D-063). Switching businesses (if UI exists) updates the scope.
- [ ] Booking token tampering: editing the token in the URL returns a "booking not found" page.
- [ ] Signed URL tampering: invalid signature on magic-link-verify, email-verify, reset-password → error page.

**Accessibility — keyboard-only booking funnel**
- [ ] Full guest booking funnel completes using only Tab, Shift+Tab, Enter, Space, and arrow keys (for date picker and radio groups).
- [ ] Focus moves to the first interactive element of each step on step-transition (no focus trapping on the previous step).
- [ ] `assertNoAccessibilityIssues()` passes on the landing page and every booking step.

**Accessibility — onboarding wizard**
- [ ] Step transitions move focus to the step heading or first input.
- [ ] Progress indicator has accessible labels (`aria-current="step"` on active step).
- [ ] `assertNoAccessibilityIssues()` passes on each wizard step.

**Timezone display**
- [ ] Seed a booking for a business with `timezone=Europe/Zurich` at 10:00 local; assert the confirmation page and customer management page show "10:00" regardless of the browser test runner's system timezone. Run the test with `visit(...)->withTimezone('America/New_York')` to prove wall-clock rendering uses the business timezone (D-005, D-030, D-071).
- [ ] Same for the dashboard calendar: admin sees local business times.

**Smoke of every top-level page (`assertNoSmoke()`)**
- [ ] Visit every top-level named route (home, login, register, magic-link, forgot-password, customer-register, /{slug}, /bookings/{token}, /dashboard, /dashboard/bookings, /dashboard/calendar, /dashboard/customers, all settings pages, my-bookings) as the appropriate role and `assertNoSmoke()` (no JS errors, no console errors).

### Files to create

- `tests/Browser/Embed/IframeEmbedTest.php`
- `tests/Browser/Embed/PopupEmbedTest.php`
- `tests/Browser/CrossCutting/AuthorizationEdgeCasesTest.php`
- `tests/Browser/CrossCutting/AccessibilityTest.php`
- `tests/Browser/CrossCutting/TimezoneTest.php`
- `tests/Browser/CrossCutting/SmokeAllPagesTest.php`

### Files to modify

- `tests/Browser/route-coverage.md` — append embed + cross-cutting routes.

Does **not** modify any `Support/` file.

---

## Session E2E-7 — Google Calendar Sync UI (DEFERRED — Session 12 dependency)

Status: **[DEPENDS ON: ROADMAP Session 12 — Google Calendar Sync]**
Parallelisable with: itself is blocked until Session 12 lands.
Full suite green on completion: **yes** (when enabled).

Session 12 has not started in the main delivery roadmap. Until it lands, the Calendar Integration UI pages and routes do not exist, so this E2E session is a placeholder. When Session 12 is implemented, this session should:

- [ ] Cover the settings page for connecting / disconnecting Google Calendar per provider.
- [ ] Assert connected-state UI (account email, last-synced timestamp, error state).
- [ ] Assert disconnected-state UI (Connect button visible, revoke flow when available).
- [ ] Factory-seed a `CalendarIntegration` row; do not exercise the real OAuth round-trip with Google.
- [ ] Verify external bookings (`source=google_calendar`) appear in dashboard views with "External Event" labelling (already stubbed in E2E-4 via factories).

Track this session in `docs/ROADMAP.md` alongside Session 12.

---

## Notes for implementing agents

- **Pest 4 browser API**: the global `visit($url)` returns a chainable `$page`. Helpers like `click`, `type`, `press`, `assertSee`, `assertUrlIs`, `assertNoJavaScriptErrors`, `withTimezone`, `withLocale`, etc. are all on `$page`. See `pestphp/pest@4.x/docs/browser-testing`.
- **Signed URL fixtures**: magic-link, invitation, verification, and password-reset links are generated inside the test using the same Laravel helpers the app uses (`URL::temporarySignedRoute`, `Password::createToken`, `BusinessInvitation::factory()`). The browser then visits the URL.
- **Parallelism**: `./vendor/bin/pest tests/Browser --parallel` is recommended in CI. Each test resets the DB via `RefreshDatabase`, so there is no cross-test state to contend with.
- **Screenshots & traces**: Pest writes them to `tests/Browser/Screenshots/` and `tests/Browser/Traces/`. Both are gitignored.
- **Do not fix app bugs in a test session**. If a browser test reveals a bug, document it in the session's implementation report and open a follow-up; the session owner writes tests only.
- **Terminology**: never write "collaborator" — use **staff** for dashboard membership and **provider** for bookable identity. File names, method names, variable names, and page assertions must use the current terms.
- **Route coverage ledger**: keep `tests/Browser/route-coverage.md` in sync — each session ticks the routes it covers. The orchestrator verifies the ledger is complete in Phase 4.

---

## Dependency graph

```
E2E-0  Infrastructure & Tooling Setup                 [serial — MUST RUN FIRST]
  │    (implements BusinessSetup + AuthHelper; stubs OnboardingHelper + BookingFlowHelper)
  │
  ├─▶ E2E-1  Authentication Flows                     ─┐
  ├─▶ E2E-2  Business Onboarding Wizard               │  parallel group A
  ├─▶ E2E-3  Public Booking Flow                      │  (run simultaneously after E2E-0)
  ├─▶ E2E-4  Dashboard: Bookings/Calendar/Customers   │
  └─▶ E2E-5  Dashboard: Settings                      ─┘
                            │
                            │  wait for E2E-3 only
                            ▼
                   E2E-6  Embed & Cross-Cutting        [depends on E2E-3]
                            │
                            │  (pending main roadmap Session 12)
                            ▼
                   E2E-7  Google Calendar Sync UI      [DEFERRED]
```

Explanation of the parallelisation:

- **E2E-0 is the only hard serial prerequisite.** It installs tooling, configures the harness, and fully implements `BusinessSetup` + `AuthHelper` so all downstream sessions can use them read-only.
- **E2E-1 through E2E-5 can run in parallel** after E2E-0. No two of them touch the same file:
  - E2E-1 owns `tests/Browser/Auth/`.
  - E2E-2 owns `tests/Browser/Onboarding/` and `tests/Browser/Support/OnboardingHelper.php`.
  - E2E-3 owns `tests/Browser/Booking/` and `tests/Browser/Support/BookingFlowHelper.php`.
  - E2E-4 owns `tests/Browser/Dashboard/`.
  - E2E-5 owns `tests/Browser/Settings/`.
  - All five sessions append to `tests/Browser/route-coverage.md`. Merge conflicts are structural (distinct rows) and the orchestrator resolves them in Phase 4.
- **E2E-6 depends on E2E-3**: its popup-embed and cross-tenant booking tests use `BookingFlowHelper::bookAsGuest`.
- **E2E-7 is deferred** until main-roadmap Session 12 lands.

---

*This roadmap defines the WHAT. The HOW — specific browser-interaction strategy, helper internals, CI matrix sharding — is decided by the agent at plan time. Active session plans live in `docs/plans/`; completed plans move to `docs/archive/plans/`.*
