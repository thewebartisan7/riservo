# Tests

## Normal Usage

Run all tests:

```bash
php artisan test --compact
```

This runs the full suite, including browser/E2E tests under `tests/Browser`, so it can take 2+ minutes.

Run only feature tests:

```bash
php artisan test --compact tests/Feature
```

Run only unit tests:

```bash
php artisan test --compact tests/Unit
```

Run a specific file:

```bash
php artisan test --compact tests/Feature/Settings/CalendarIntegrationTest.php
```

Run by filter:

```bash
php artisan test --compact --filter=calendar
php artisan test --compact tests/Feature --filter=CalendarIntegration
php artisan test --compact tests/Unit --filter=TimeWindow
```

## E2E / Browser Tests

Run only browser tests:

```bash
php artisan test --compact tests/Browser
```

Run a specific browser test file:

```bash
php artisan test --compact tests/Browser/Settings/AccountTest.php
```

Run browser tests by filter:

```bash
php artisan test --compact tests/Browser --filter=Account
```

## Sandbox Workaround

In Codex sandbox runs, `php artisan test` for `Feature` or `Unit` may still load Pest's browser plugin because Pest loads plugins globally from `vendor/pest-plugins.json`, which includes `Pest\\Browser\\Plugin`.

That can cause sandbox-only failures even when no browser tests are being selected.

Workaround added for Codex:

- `bin/pest-no-browser`
  - runs Pest directly
  - removes only `Pest\\Browser\\Plugin` from the loaded plugin list
- `composer.json`
  - `test:unit` uses `@php bin/pest-no-browser --compact --testsuite=Unit`
  - `test:feature` uses `@php bin/pest-no-browser --compact --testsuite=Feature`
- `tests/Pest.php`
  - includes a small guard, but the main workaround is the wrapper above

This was added only to make non-browser test runs usable inside the sandbox. Normal local usage should still prefer `php artisan test ...`.

