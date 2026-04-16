# PLAN — E2E-0: Infrastructure & Tooling Setup

> Status: awaiting approval
> Scope: Pest 4 native browser-testing foundation + fully-implemented `BusinessSetup` and `AuthHelper`. No functional test logic beyond a single smoke test.
> Parallelisable with: nothing — E2E-0 blocks E2E-1..6.

---

## 1 — Findings from current codebase

### Installed tooling
- `pestphp/pest ^4.5` + `pestphp/pest-plugin-laravel ^4.1` — present.
- `pestphp/pest-plugin-browser` — **not installed**. This session installs it.
- `playwright` — **not installed** on the npm side. This session installs it and runs `npx playwright install --with-deps`.
- No `tests/Browser/` directory yet.

### phpunit.xml current state
- Two testsuites: `Unit` and `Feature` (lines 8–13).
- Testing env (lines 20–38): Postgres `riservo_ch_testing`, `QUEUE_CONNECTION=sync`, `MAIL_MAILER=array`, `CACHE_STORE=array`, `SESSION_DRIVER=array`, `BCRYPT_ROUNDS=4`.

### tests/Pest.php current state
- `RefreshDatabase` is applied via `pest()->extend(TestCase::class)->use(RefreshDatabase::class)->in('Feature')` (lines 21–23).
- Global helpers exist: `attachStaff`, `attachAdmin`, `attachProvider` (lines 56–82). `BusinessSetup` will call these — not re-implement.

### routes/web.php
- ~70 named routes across auth, onboarding, dashboard, settings, customer, public booking, and API. Full list will populate `tests/Browser/route-coverage.md`.

### CI (`.github/workflows/ci.yml`)
- Three jobs: `pint` (code style), `larastan` (static analysis), `tests` (unit + feature against `riservo_ch_testing` on Postgres 16).
- This session **adds** a fourth job `browser-tests`, depending on `tests` passing. No workflow-file split — we extend `ci.yml`.

### Domain facts that shape `BusinessSetup`
- `BusinessFactory` defaults: `timezone = Europe/Zurich`, `allow_provider_choice=true`, `confirmation_mode=Auto`, `cancellation_window_hours=24`, `reminder_hours=[24,1]`. Has an `onboarded()` state (sets `onboarding_step=5`, `onboarding_completed_at=now()`).
- `ServiceFactory` defaults: `is_active=true`, `buffer_before=0`, `slot_interval_minutes=15`, duration 30/45/60/90, price 20–150. We will force `duration_minutes=60`, `slot_interval_minutes=30`, `buffer_after=0` in `createLaunchedBusiness` to match the roadmap's helper contract.
- `BusinessHourFactory`: `day_of_week` is ISO 1–7, `open_time/close_time` HH:MM strings.
- `AvailabilityRuleFactory`: `provider_id`, `business_id`, `day_of_week`, `start_time`, `end_time` (HH:MM strings).
- `BusinessMemberRole` enum: **only `Admin` and `Staff`**. Customer role is **not** a pivot role — a user is a "customer" iff a `Customer` row exists with matching `user_id` (see `User::isCustomer()` at `app/Models/User.php:52`). This informs `AuthHelper::loginAsCustomer`.
- `User` fillable includes `magic_link_token` (line 19). Magic-link verify uses `URL::temporarySignedRoute('magic-link.verify', now()->addMinutes(15), ['user' => $user->id, 'token' => $token])` (see `MagicLinkController.php:39-43`) and compares `$request->query('token')` to the stored token.

### Landing page content
- `resources/js/pages/welcome.tsx:14` renders `<h1>riservo</h1>`. Smoke test uses `assertSee('riservo')` — copy matches.

---

## 2 — Deliverables

### 2a — Package installs
1. `composer require pestphp/pest-plugin-browser --dev` — commit `composer.json` + `composer.lock`.
2. `npm install playwright@latest` — commit `package.json` + `package-lock.json`.
3. `npx playwright install --with-deps chromium` — downloads Chromium binary (no VCS effect). Local-only; CI installs independently.

### 2b — `.gitignore`
Append:
```
tests/Browser/Screenshots/
tests/Browser/Traces/
```

### 2c — `phpunit.xml`
Add a third testsuite scoped to `tests/Browser`, with a dedicated `<php>` block for DB and URL isolation:

```xml
<testsuite name="Browser">
    <directory>tests/Browser</directory>
    <php>
        <env name="DB_DATABASE" value="riservo_ch_e2e"/>
        <env name="APP_URL" value="http://127.0.0.1"/>
    </php>
</testsuite>
```

Unit + Feature behaviour unchanged. Default `php artisan test` runs all three suites; feature-only runs still work via `--testsuite=Feature`.

### 2d — `tests/Pest.php`
Add, after the existing `in('Feature')` block:

```php
pest()->extend(TestCase::class)
    ->use(RefreshDatabase::class)
    ->in('Browser');

pest()->browser()->timeout(10000);
```

Nothing touches the existing `in('Feature')` binding.

### 2e — Directory tree
```
tests/Browser/
├── Auth/                  (empty — E2E-1)
├── Onboarding/            (empty — E2E-2)
├── Booking/               (empty — E2E-3)
├── Dashboard/             (empty — E2E-4)
├── Settings/              (empty — E2E-5)
├── Embed/                 (empty — E2E-6)
├── CrossCutting/          (empty — E2E-6)
├── Support/
│   ├── BusinessSetup.php       (fully implemented — 2f)
│   ├── AuthHelper.php          (fully implemented — 2g)
│   ├── OnboardingHelper.php    (stub only — 2h)
│   └── BookingFlowHelper.php   (stub only — 2h)
├── Screenshots/           (.gitignored; .gitkeep to preserve tree)
├── Traces/                (.gitignored; .gitkeep to preserve tree)
├── SmokeTest.php          (2j)
└── route-coverage.md      (2i)
```

Empty per-feature directories get a `.gitkeep` so the tree is visible on checkout.

### 2f — `BusinessSetup.php` (fully implemented)
Namespace: `Tests\Browser\Support`. Uses factories + the global `attachAdmin` / `attachStaff` helpers. Signatures exactly as specified by the task:

```php
public static function createBusinessWithAdmin(array $overrides = []): array;
public static function createBusinessWithStaff(int $count = 1, array $overrides = []): array;
public static function createBusinessWithService(array $serviceOverrides = []): array;
public static function createLaunchedBusiness(array $overrides = []): array;
public static function createBusinessWithProviders(int $providerCount = 2, array $overrides = []): array;
```

`createLaunchedBusiness` builds:
- `Business` with `onboarded()` state (onboarding_step=5, onboarding_completed_at=now()).
- `BusinessHour` rows for Mon–Fri (ISO 1–5) `09:00`–`18:00`; no rows for Sat/Sun (closed days are modelled as absent rows per the codebase).
- One `Service` (60 min, 30 min slot interval, buffers = 0).
- Admin user attached via `attachAdmin`.
- Admin opted in as `Provider`; `AvailabilityRule` rows for Mon–Fri mirroring the business hours; Service attached via the `provider_service` pivot.
- One seed `Customer` row (no user link).

`createBusinessWithProviders` is a superset of `createLaunchedBusiness` — same Business/Service setup, with `$providerCount` Providers (first is the admin, subsequent are staff users), each attached to the Service with matching Mon–Fri availability rules.

`$overrides` is forwarded to `Business::factory()->onboarded()` so callers can pass `['slug' => 'acme', 'allow_provider_choice' => false]` etc. For setup helpers that return services/customers, `$serviceOverrides` is forwarded to `Service::factory()`.

### 2g — `AuthHelper.php` (fully implemented)
Namespace: `Tests\Browser\Support`. Four static methods:

```php
public static function loginAs($page, User $user, string $password = 'password'): mixed;
public static function loginViaMagicLink($page, User $user): mixed;
public static function logout($page): mixed;
public static function loginAsCustomer($page, User $customerUser, string $password = 'password'): mixed;
```

- `loginAs` calls `$page->visit('/login')->type(...)->press('Log in')` (adjust label from actual button text in `resources/js/pages/auth/login.tsx`; cross-reference during implementation). Returns the resulting page.
- `loginViaMagicLink` sets a fresh `$user->magic_link_token = Str::random(64)`, builds `URL::temporarySignedRoute('magic-link.verify', now()->addMinutes(15), ['user' => $user->id, 'token' => $token])`, and visits the URL.
- `logout` posts to `/logout` **via the browser** — clicks the visible "Sign out" control. (If the current page does not have a logout button, the helper short-circuits to `visit('/login')` after calling `Auth::logout()` on the backend — matches how Dusk-style helpers handle route-specific flows. I'll verify at implementation time which is accurate.)
- `loginAsCustomer` expects the resulting page to match the customer redirect (`/my-bookings`). Matches `LoginController::redirectPath` which routes customers to `route('customer.bookings')`.

### 2h — `OnboardingHelper.php` + `BookingFlowHelper.php` (stubs)
Namespace: `Tests\Browser\Support`. Bodies throw `RuntimeException` pointing to the owning session:

```php
class OnboardingHelper {
    public static function completeWizard($page, User $admin, array $options = []): Business {
        throw new \RuntimeException('Not yet implemented — see ROADMAP-E2E.md E2E-2');
    }
    public static function advanceToStep($page, User $admin, int $step): void {
        throw new \RuntimeException('Not yet implemented — see ROADMAP-E2E.md E2E-2');
    }
}
```

Same shape for `BookingFlowHelper` with E2E-3 reference.

### 2i — `tests/Browser/route-coverage.md`
Markdown table with columns `Name | Method + Path | Role | Covered by`, one row per named route in `routes/web.php`. The "Covered by" column is blank — each downstream session appends.

### 2j — `tests/Browser/SmokeTest.php`
```php
<?php

it('loads the landing page without JS errors', function () {
    $page = visit('/');
    $page->assertSee('riservo')
        ->assertNoJavaScriptErrors()
        ->assertNoConsoleLogs();
});
```

### 2k — CI (`.github/workflows/ci.yml`)
Append a `browser-tests` job that depends on the existing `tests` job. Uses the same Postgres 16 service, installs `riservo_ch_e2e` database alongside `riservo_ch_testing`, installs Playwright Chromium with a cache, runs `./vendor/bin/pest --testsuite=Browser --parallel`.

---

## 3 — Execution order

1. Install composer + npm packages (2a). Verify `./vendor/bin/pest --version` still works and global `visit()` is registered.
2. Write `.gitignore` changes (2b).
3. Edit `phpunit.xml` (2c).
4. Edit `tests/Pest.php` (2d).
5. Create directory tree + `.gitkeep`s (2e).
6. Write `BusinessSetup.php` (2f) → write a throwaway factory-only test to verify the helpers succeed at the DB layer (will not commit this throwaway; verification only).
7. Write `AuthHelper.php` (2g).
8. Write `OnboardingHelper.php` + `BookingFlowHelper.php` stubs (2h).
9. Write `route-coverage.md` (2i) — derive rows from `php artisan route:list --except-vendor --json`.
10. Write `SmokeTest.php` (2j).
11. Run the smoke test — must pass.
12. Run `php artisan test --testsuite=Unit --testsuite=Feature --compact` — must remain green.
13. Run `vendor/bin/pint --dirty --format agent`.
14. Run `npm run build` — must succeed.
15. Update CI (2k).
16. Update `docs/HANDOFF.md` to note the new foundation.
17. Move this plan to `docs/archive/plans/`.
18. Commit with the message `test: E2E infrastructure setup (E2E-0)`.

---

## 4 — What this plan does NOT do

- No functional test logic beyond the smoke test.
- No app source changes.
- No `OnboardingHelper` / `BookingFlowHelper` method bodies.
- No modification of `tests/Unit/` or `tests/Feature/`.
- No Laravel Dusk, no `Pest\Browser\browser()` (uses global `visit()`).
- No `.env.e2e` — `phpunit.xml`'s `<php>` block scopes the Browser DB inline.

---

## 5 — Risks and mitigations

| Risk | Mitigation |
|---|---|
| `pest-plugin-browser` version incompatibility with Pest 4.5 | The plugin is maintained by the Pest team and targets Pest 4+. If `composer require` fails we stop and surface the conflict before proceeding. |
| `npx playwright install --with-deps` requires elevated privileges on Linux; on macOS it prompts or downloads to `~/Library/Caches/ms-playwright` | Local install runs under the current user. CI runs as root inside the ubuntu-latest runner where `--with-deps` works without prompts. |
| Existing dev DB colliding with `riservo_ch_e2e` | Creating the DB once (`createdb riservo_ch_e2e`) is a one-time developer step. I will add a one-line note to `docs/HANDOFF.md` and in the commit message. |
| Smoke test fails because `assertSee('riservo')` doesn't find the string (SSR vs. client render) | The `<h1>riservo</h1>` is in Inertia's initial HTML response. If this still flakes, fall back to `$page->assertTitleContains('Welcome')`. |
| Pest `pest()->browser()->timeout(10000)` API shape | If the plugin exposes a different configuration surface, switch to the actual documented method; the 10s default is advisory. |
| `php artisan route:list --json` includes Ignition/devtool routes we don't want | Filter with `--except-vendor` when populating `route-coverage.md`. |

---

## 6 — Verification commands at completion

```bash
# Smoke
./vendor/bin/pest tests/Browser/SmokeTest.php

# Full browser suite (should be just the smoke test at this point)
./vendor/bin/pest --testsuite=Browser

# Unit + Feature unchanged
php artisan test --testsuite=Unit --testsuite=Feature --compact

# Style and build
vendor/bin/pint --dirty --format agent
npm run build
```

All four must pass before the session closes.
