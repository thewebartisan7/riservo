# Prompt — E2E-0: Infrastructure & Tooling Setup

## Your task

You are the setup agent for the riservo.ch E2E test infrastructure. This session has **no functional tests** as its output. Your only deliverable is a working E2E testing foundation that every subsequent session builds on.

Do not write any test logic beyond the single smoke test described below.

---

## Step 1 — Read context first

Before touching any file, read:

1. `docs/README.md`
2. `docs/ARCHITECTURE-SUMMARY.md`
3. `docs/roadmaps/ROADMAP-E2E.md` — focus on the E2E-0 section and the Testing Guidelines

---

## Step 2 — Inspect existing test setup

Before installing anything, check what already exists:

- Read `composer.json` — note the current Pest version and any browser testing packages already installed.
- Read `phpunit.xml` or `pest.php` (whichever is present) — understand the existing test configuration.
- List `tests/` — note the current directory structure (Unit, Feature, etc.).
- Check if `tests/Browser/` already exists.

Document your findings in a short note at the top of the plan before proceeding. If `pestphp/pest-plugin-browser` is already installed, skip the install step and note it.

---

## Step 3 — Install and configure pest-plugin-browser

- `composer require pestphp/pest-plugin-browser --dev`
- Install Playwright's Chromium binary as required by the plugin (follow the plugin's documented install command).
- Verify the binary is available.

---

## Step 4 — Configure the E2E test environment

- Create `.env.e2e` (or `.env.testing` if the project does not already use one for feature tests — if `.env.testing` already exists for feature tests, use `.env.e2e` to avoid collisions):
    - `APP_URL` pointing to the app server used during E2E runs (evaluate whether `pest-plugin-browser` starts its own server or requires one externally — document the chosen approach)
    - A dedicated E2E database (e.g., `DB_DATABASE=riservo_e2e`) so E2E runs never touch the development or feature-test database
    - `MAIL_MAILER=array`
    - `QUEUE_CONNECTION=sync` (so queued jobs run inline, making email and notification assertions deterministic)
    - `CACHE_DRIVER=array`
    - Any other env values required for a clean, isolated test environment

- Configure the database reset strategy: evaluate what `pest-plugin-browser` supports (per-test `RefreshDatabase`, transaction rollback, or `migrate:fresh` per suite run) and choose the most reliable option. Document the choice and the rationale in a comment in `pest.php` or the E2E config file.

---

## Step 5 — Configure Pest for E2E tests

Update `pest.php` (or create a separate `pest.browser.php` if the plugin requires it) to:

- Register E2E tests under the `e2e` group so they can be run with `php artisan test --group=e2e` without running unit/feature tests, and excluded from the default run if browser tests should not run in every `php artisan test` invocation.
- Ensure the existing unit and feature test configuration is not modified in any way.
- Configure automatic screenshot and browser trace capture on test failure. Store artefacts in `tests/Browser/screenshots/` and `tests/Browser/traces/` (add both to `.gitignore`).

---

## Step 6 — Create helper stubs

Create the following files in `tests/Browser/Support/`. Each file must have the correct namespace, a class definition, and **stub method signatures with empty bodies and a `// TODO: implemented by subagent E2E-X` comment**. Do not implement any logic — that is the job of subsequent session agents.

### `tests/Browser/Support/BusinessSetup.php`

Methods to stub:

```php
// Creates a Business + admin User via factories; returns ['business' => Business, 'admin' => User]
public static function createBusinessWithAdmin(array $overrides = []): array

// Creates a Business + admin User + N collaborators; returns ['business', 'admin', 'collaborators']
public static function createBusinessWithCollaborators(int $count = 1, array $overrides = []): array

// Creates a Business + admin User + 1 Service; returns ['business', 'admin', 'service']
public static function createBusinessWithService(array $serviceOverrides = []): array

// Creates a fully ready business (admin + service + collaborator + business hours set)
// This is the baseline state needed by booking flow tests
public static function createLaunchedBusiness(array $overrides = []): array
```

### `tests/Browser/Support/AuthHelper.php`

Methods to stub:

```php
// Logs in a user (admin or collaborator) via the browser login form
public static function loginAs(Browser $browser, User $user, string $password = 'password'): void

// Logs in via magic link (generates a signed URL and visits it directly)
public static function loginViaMagicLink(Browser $browser, User $user): void

// Logs out the current user via the browser
public static function logout(Browser $browser): void
```

### `tests/Browser/Support/OnboardingHelper.php`

Methods to stub:

```php
// Drives the full 5-step wizard to completion for the given admin user
// Returns the completed Business model
public static function completeWizard(Browser $browser, User $admin, array $options = []): Business

// Drives the wizard to a specific step and stops (for step-specific tests)
public static function advanceToStep(Browser $browser, User $admin, int $step): void
```

### `tests/Browser/Support/BookingFlowHelper.php`

Methods to stub:

```php
// Drives the full public booking funnel as a guest customer
// Returns the booking confirmation URL
public static function bookAsGuest(Browser $browser, Business $business, Service $service, array $customerDetails = []): string

// Drives the full public booking funnel as a registered customer
public static function bookAsRegistered(Browser $browser, Business $business, Service $service, Customer $customer): string
```

---

## Step 7 — Write and run the smoke test

Create `tests/Browser/SmokeTest.php`:

```php
<?php

use function Pest\Browser\browser;

it('loads the application root', function () {
    browser()
        ->visit('/')
        ->assertStatus(200)
        ->assertTitleContains('riservo'); // adjust to the actual <title> content
});
```

Run it: `php artisan test --group=e2e`

The smoke test must pass before this session is considered complete.

---

## Step 8 — Configure CI

Add or update the CI configuration (`.github/workflows/` or equivalent) to include a headless E2E step:

- Install Playwright Chromium binary in CI.
- Cache the binary between runs using the CI platform's cache action.
- Run `php artisan test --group=e2e` as a separate CI job (not mixed into the unit/feature job).
- The E2E CI job depends on the unit/feature job passing first.

If the project has no CI configuration yet, create a minimal GitHub Actions workflow file. Document any required environment secrets or variables.

---

## Step 9 — Final checks

Before closing this session:

1. `php artisan test` — full suite (unit + feature) must be green. E2E-0 must not have broken anything.
2. `php artisan test --group=e2e` — smoke test must pass.
3. `npm run build` — must be clean.
4. Confirm `.gitignore` includes `tests/Browser/screenshots/` and `tests/Browser/traces/`.
5. Commit all changes with message: `test: E2E infrastructure setup (E2E-0)`.

---

## What you must NOT do

- Do not write any test logic beyond the smoke test.
- Do not modify application source code.
- Do not modify the existing unit/feature test configuration in a way that changes their behaviour.
- Do not implement any logic inside the helper stubs — empty method bodies only.
