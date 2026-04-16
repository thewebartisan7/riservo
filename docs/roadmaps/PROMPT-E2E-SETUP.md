# Prompt — E2E-0: Infrastructure & Tooling Setup

## Your task

You are the setup agent for the riservo.ch E2E test infrastructure. This session has **no functional tests** as its output. Your only deliverable is a working E2E testing foundation that every subsequent session builds on.

Unlike a typical "stub everything" setup, this session fully implements two shared helpers (`BusinessSetup` and `AuthHelper`). The other two helpers (`OnboardingHelper`, `BookingFlowHelper`) remain stubs — their owners (E2E-2 and E2E-3) implement them in parallel.

Do not write any test logic beyond the single smoke test described below.

---

## Step 1 — Read context first

Before touching any file, read:

1. `docs/README.md`
2. `docs/ARCHITECTURE-SUMMARY.md`
3. `docs/roadmaps/ROADMAP-E2E.md` — focus on the E2E-0 section, the Testing Guidelines, and the Helper Contract.
4. `routes/web.php` — so your coverage ledger lists every named route.
5. `tests/Pest.php` — reuse global helpers (`attachStaff`, `attachAdmin`, `attachProvider`) rather than re-implementing.
6. Any factory under `database/factories/` you expect `BusinessSetup` to call.

---

## Step 2 — Inspect existing test setup

Document findings at the top of your plan before proceeding:

- Pest version: `pestphp/pest ^4.5` (browser testing is native to Pest 4 via the plugin — do **not** use Laravel Dusk).
- `pestphp/pest-plugin-browser` — **not** installed; E2E-0 installs it.
- `playwright` — **not** installed; E2E-0 installs and runs `npx playwright install --with-deps`.
- `tests/Browser/` — does **not** exist; E2E-0 creates the tree.
- `phpunit.xml` currently declares only `Unit` and `Feature` testsuites; add a `Browser` testsuite.
- Testing env in `phpunit.xml` already sets `riservo_ch_testing`, `QUEUE_CONNECTION=sync`, `MAIL_MAILER=array`, `CACHE_STORE=array`, `SESSION_DRIVER=array`. If browser tests need a separate DB to avoid collisions with the feature suite, scope `DB_DATABASE=riservo_ch_e2e` inside the new `Browser` testsuite's `<php>` block only.
- `tests/Pest.php` applies `RefreshDatabase` to the `Feature` suite via `pest()->...->in('Feature')`. Add a parallel `->in('Browser')` binding.

---

## Step 3 — Install and configure pest-plugin-browser

```bash
composer require pestphp/pest-plugin-browser --dev
npm install playwright@latest
npx playwright install --with-deps
```

- Commit `composer.lock` and `package-lock.json`.
- Verify `./vendor/bin/pest --version` still works.
- Verify Pest's `visit()` function is available by running the smoke test in Step 7.

**Note on the API**: Pest 4's browser API is the **global `visit($url)`** function returning a chainable `$page` object. Do **not** use `Pest\Browser\browser()` (that is Dusk-style and does not exist in pest-plugin-browser). The helpers described below accept `$page` as their first parameter (type `mixed` / `PendingPage` — whatever the plugin exposes).

---

## Step 4 — Configure the E2E test environment

- Add a `Browser` testsuite to `phpunit.xml` pointing at `tests/Browser`.
- Optionally scope `DB_DATABASE=riservo_ch_e2e` and `APP_URL=http://127.0.0.1` inside that testsuite's `<php>` block so browser tests never collide with the feature-test DB (Postgres only; use `postgres` CLI to `CREATE DATABASE riservo_ch_e2e` locally, or add a `setup` note to the developer's README).
- Confirm `MAIL_MAILER=array` and `QUEUE_CONNECTION=sync` are inherited from the existing testing env (queued notifications must run inline so E2E assertions on mailables are deterministic).

Do **not** create `.env.e2e` — the `phpunit.xml` env block is sufficient.

---

## Step 5 — Configure Pest for browser tests

Update `tests/Pest.php`:

- Bind `pest()->extend(TestCase::class)->use(RefreshDatabase::class)->in('Browser')` so every browser test gets a clean DB.
- Configure Pest browser defaults:
  ```php
  pest()->browser()->timeout(10000); // 10s implicit wait
  ```
- Do **not** change any existing `->in('Feature')` binding.

Screenshots and traces are written automatically by Pest on test failure. Default locations:

- `tests/Browser/Screenshots/`
- `tests/Browser/Traces/`

Both are gitignored (Step 6).

---

## Step 6 — Create directory tree and helper files

Directory tree (`tests/Browser/...`):

```
tests/Browser/
├── Auth/                  # E2E-1
├── Onboarding/            # E2E-2
├── Booking/               # E2E-3
├── Dashboard/             # E2E-4
├── Settings/              # E2E-5
├── Embed/                 # E2E-6
├── CrossCutting/          # E2E-6
├── Support/               # helpers
├── Screenshots/           # .gitignored
├── Traces/                # .gitignored
├── SmokeTest.php          # see Step 7
└── route-coverage.md      # coverage ledger (see Step 6b)
```

Add to `.gitignore`:
```
tests/Browser/Screenshots/
tests/Browser/Traces/
```

### Step 6a — Fully implement `BusinessSetup` and `AuthHelper` (not stubs)

These two helpers are shared by every downstream session. Implement them completely.

#### `tests/Browser/Support/BusinessSetup.php`

```php
public static function createBusinessWithAdmin(array $overrides = []): array;
    // Returns ['business' => Business, 'admin' => User]
    // admin: email_verified_at = now(), password = bcrypt('password')
    // BusinessMember attached via attachAdmin() (global helper from tests/Pest.php)

public static function createBusinessWithStaff(int $count = 1, array $overrides = []): array;
    // Returns ['business', 'admin', 'staff' => Collection<User>]
    // Each staff attached via attachStaff()

public static function createBusinessWithService(array $serviceOverrides = []): array;
    // Returns ['business', 'admin', 'service'] — business has one active Service

public static function createLaunchedBusiness(array $overrides = []): array;
    // Returns ['business', 'admin', 'provider', 'service', 'customer']
    // Business has:
    //   - BusinessHour rows for Mon–Fri 09:00–18:00, closed Sat/Sun (ISO 1–7 per D-024)
    //   - one Service (60 min, 30-min slot_interval, buffer_before=0, buffer_after=0)
    //   - admin opted in as Provider with AvailabilityRules matching BusinessHours
    //   - Service attached to the provider via provider_services pivot
    //   - onboarding_completed_at=now(), onboarding_step=5
    //   - one seed Customer row

public static function createBusinessWithProviders(int $providerCount = 2, array $overrides = []): array;
    // Same as createLaunchedBusiness but with N providers (used by E2E-3 / E2E-4 / E2E-5).
```

#### `tests/Browser/Support/AuthHelper.php`

```php
public static function loginAs($page, User $user, string $password = 'password'): mixed;
    // Visits /login, fills email + password, presses submit. Returns the page after redirect.

public static function loginViaMagicLink($page, User $user): mixed;
    // Updates $user->magic_link_token, builds a signed URL via URL::temporarySignedRoute(
    //   'magic-link.verify', now()->addMinutes(15), ['user' => $user->id, 'token' => $token]
    // ), visits it, returns the page.

public static function logout($page): mixed;
    // Posts to /logout via the browser form (not a direct HTTP call).

public static function loginAsCustomer($page, User $customerUser, string $password = 'password'): mixed;
    // Same as loginAs but expects a redirect to /my-bookings.
```

### Step 6b — Stub `OnboardingHelper` and `BookingFlowHelper`

Create both files with class definitions and stub method signatures. Method bodies must be empty except for a `// TODO: implemented by E2E-2` (or E2E-3) comment and a `throw new \RuntimeException('Not yet implemented — see ROADMAP-E2E.md E2E-2')` so nobody accidentally consumes them before their owner session lands.

#### `tests/Browser/Support/OnboardingHelper.php`

```php
public static function completeWizard($page, User $admin, array $options = []): Business;
public static function advanceToStep($page, User $admin, int $step): void;
```

#### `tests/Browser/Support/BookingFlowHelper.php`

```php
public static function bookAsGuest($page, Business $business, Service $service, array $customerDetails = []): string;
public static function bookAsRegistered($page, Business $business, Service $service, Customer $customer): string;
```

### Step 6c — Create the route-coverage ledger

Create `tests/Browser/route-coverage.md` with a Markdown table listing every named route in `routes/web.php`, with columns:

| Name | Method + Path | Role | Covered by |
|---|---|---|---|

Leave the "Covered by" column blank for now. Each session appends its own row references (e.g., "E2E-1: `Auth/LoginTest.php::'admin logs in and is redirected to dashboard'`").

---

## Step 7 — Write and run the smoke test

Create `tests/Browser/SmokeTest.php`:

```php
<?php

it('loads the landing page without JS errors', function () {
    $page = visit('/');
    $page->assertSee('riservo') // adjust to match actual <title> or on-page copy in resources/js/pages/welcome.tsx
        ->assertNoJavaScriptErrors()
        ->assertNoConsoleLogs();
});
```

Run it:

```bash
./vendor/bin/pest tests/Browser/SmokeTest.php
```

The smoke test must pass before this session is considered complete.

---

## Step 8 — Configure CI

Add or update `.github/workflows/browser-tests.yml` (or equivalent) with a headless browser job:

```yaml
jobs:
  browser-tests:
    runs-on: ubuntu-latest
    needs: [unit-feature-tests]   # must depend on the existing unit/feature job
    steps:
      - uses: actions/checkout@v4
      - uses: actions/setup-node@v4
        with:
          node-version: lts/*
      - name: Install JS dependencies
        run: npm ci
      - name: Cache Playwright browsers
        uses: actions/cache@v4
        with:
          path: ~/.cache/ms-playwright
          key: playwright-${{ runner.os }}-${{ hashFiles('package-lock.json') }}
      - name: Install Playwright Chromium
        run: npx playwright install --with-deps chromium
      - name: Setup PHP
        uses: shivammathur/setup-php@v2
        with:
          php-version: '8.3'
      - name: Install PHP dependencies
        run: composer install --no-interaction --prefer-dist
      - name: Run browser tests
        run: ./vendor/bin/pest tests/Browser --parallel
```

If no prior CI exists, create a minimal workflow with two jobs: `unit-feature-tests` (runs `php artisan test` on Unit + Feature testsuites) and `browser-tests` (depends on the former).

---

## Step 9 — Final checks

Before closing this session:

1. `php artisan test` (Unit + Feature only, since the default testsuites in `phpunit.xml` exclude `Browser` or the `Browser` suite is not included by default) — must be green.
2. `./vendor/bin/pest tests/Browser` — smoke test must pass.
3. `npm run build` — must be clean.
4. `.gitignore` includes `tests/Browser/Screenshots/` and `tests/Browser/Traces/`.
5. `tests/Browser/route-coverage.md` exists with the full list of named routes.
6. Commit all changes with message: `test: E2E infrastructure setup (E2E-0)`.

---

## What you must NOT do

- Do not write any test logic beyond the smoke test.
- Do not implement `OnboardingHelper` or `BookingFlowHelper` bodies — stubs only.
- Do not modify application source code.
- Do not modify the existing `Unit` or `Feature` test configuration in any way that changes their behaviour.
- Do not use the term "collaborator" anywhere. The codebase uses **staff** (dashboard role) and **provider** (bookable identity). See D-061.
- Do not mock the database — Pest 4 browser testing supports real DB via `RefreshDatabase` natively.
- Do not install Laravel Dusk — we are using Pest 4's native browser plugin.
