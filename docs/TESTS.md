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
