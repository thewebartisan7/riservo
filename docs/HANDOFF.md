# Handoff

**Session**: E2E-0 — Infrastructure & Tooling Setup
**Date**: 2026-04-16
**Status**: Infrastructure complete; Unit+Feature suite green (481 passed) and the single Browser smoke test green (1 passed). No application code changed.

---

## What Was Built

Session E2E-0 lays the groundwork for browser testing under
`docs/roadmaps/ROADMAP-E2E.md`. It installs tooling, configures the Pest
harness, scaffolds the test tree, fully implements two shared helpers, stubs
the other two, and adds a CI job. Plan file:
`docs/plans/PLAN-E2E-0.md` (moved to `docs/archive/plans/` on close).

### Tooling installed

- `pestphp/pest-plugin-browser` `^4.3` (dev).
- `playwright@1.59` (prod-scoped in `package.json` to stay alongside Vite).
- Chromium downloaded to `~/Library/Caches/ms-playwright/` via
  `npx playwright install --with-deps chromium`. CI installs on demand.

### Harness configuration

- `phpunit.xml` now declares three testsuites: `Unit`, `Feature`, `Browser`.
  The `Browser` testsuite points at `tests/Browser/` and reuses the existing
  testing `<php>` env block (Postgres `riservo_ch_testing`,
  `QUEUE_CONNECTION=sync`, `MAIL_MAILER=array`, `CACHE_STORE=array`,
  `SESSION_DRIVER=array`).
- `tests/Pest.php` binds `RefreshDatabase` to both `Feature` and `Browser`,
  and sets Pest's browser timeout to 10 seconds.
- `.gitignore` adds `tests/Browser/Screenshots/` and `tests/Browser/Traces/`.

### Directory tree

```
tests/Browser/
├── Auth/                  (empty, owned by E2E-1)
├── Onboarding/            (empty, owned by E2E-2)
├── Booking/               (empty, owned by E2E-3)
├── Dashboard/             (empty, owned by E2E-4)
├── Settings/              (empty, owned by E2E-5)
├── Embed/                 (empty, owned by E2E-6)
├── CrossCutting/          (empty, owned by E2E-6)
├── Support/
│   ├── BusinessSetup.php       (fully implemented — E2E-0)
│   ├── AuthHelper.php          (fully implemented — E2E-0)
│   ├── OnboardingHelper.php    (stub, owned by E2E-2)
│   └── BookingFlowHelper.php   (stub, owned by E2E-3)
├── Screenshots/           (.gitignored)
├── Traces/                (.gitignored)
├── SmokeTest.php          (single smoke test)
└── route-coverage.md      (coverage ledger of every named route)
```

Each empty per-feature directory has a `.gitkeep` so the tree is visible on
checkout.

### Shared helpers (E2E-0 owns)

`Tests\Browser\Support\BusinessSetup` exposes five static factory methods
for downstream sessions: `createBusinessWithAdmin`,
`createBusinessWithStaff`, `createBusinessWithService`,
`createLaunchedBusiness`, `createBusinessWithProviders`. All admin/staff
users get `email_verified_at=now()` and plaintext password `password`.
`createLaunchedBusiness` builds a business with `onboarded()` state,
Mon–Fri 09:00–18:00 BusinessHours (ISO 1–5 per D-024; weekends absent),
one Service (60 min duration, 30 min slot interval, no buffers), an admin
opted-in as provider with matching AvailabilityRules, the Service attached
to the provider, and a seed Customer row.

`Tests\Browser\Support\AuthHelper` exposes `loginAs`, `loginViaMagicLink`
(signed URL generated via `URL::temporarySignedRoute('magic-link.verify', …)`,
matching `MagicLinkController::store`), `logout`, and `loginAsCustomer`.

`OnboardingHelper` and `BookingFlowHelper` are stubs that throw
`RuntimeException` pointing to E2E-2 / E2E-3 respectively.

### Smoke test

`tests/Browser/SmokeTest.php` visits `/` and asserts on the landing-page
"riservo" copy (matches `resources/js/pages/welcome.tsx:14`), plus no JS
errors and no console logs. Runs in ~1 s after warmup.

### CI

`.github/workflows/ci.yml` gains a new `browser-tests` job, depending on
the existing `tests` job. The `tests` job now runs
`php artisan test --testsuite=Unit --testsuite=Feature --compact` so it
does not try to run browser tests without Playwright. The new
`browser-tests` job installs Node + npm deps, caches Playwright browsers,
installs Chromium with deps, builds the frontend, migrates against the
same Postgres 16 service, and runs `./vendor/bin/pest --testsuite=Browser
--parallel`. Screenshots and traces are uploaded as a GitHub Actions
artifact on failure.

---

## Deviations from Plan

- **Per-testsuite `<php>` env block**: PHPUnit's schema does not support
  `<php>` as a child of `<testsuite>`. The plan proposed scoping
  `DB_DATABASE=riservo_ch_e2e` and `APP_URL=http://127.0.0.1` only to the
  Browser suite; instead, the Browser suite reuses the existing testing
  `<php>` block. `RefreshDatabase` + sequential Pest execution means
  browser and feature tests cannot collide on the shared `riservo_ch_testing`
  DB within a single run. Parallel runs in CI use separate Postgres
  services per job, so DB naming is irrelevant there.
- **CI workflow file**: the plan considered creating
  `.github/workflows/browser-tests.yml`. Instead, the new job was appended
  to the existing `.github/workflows/ci.yml` (alongside `pint`, `larastan`,
  `tests`) so CI stays configured in one file.

---

## How to Verify Locally

```bash
# Unit + Feature (fast path — no Playwright needed)
php artisan test --testsuite=Unit --testsuite=Feature --compact
# → 481 passed (~29 s)

# Browser smoke only (needs Chromium installed locally)
./vendor/bin/pest --testsuite=Browser
# → 1 passed (~1 s)

vendor/bin/pint --dirty --format agent
# → {"result":"pass"}

npm run build
# → clean Vite build
```

First-time Playwright setup on a fresh checkout:

```bash
npm install
npx playwright install --with-deps chromium
```

Running `php artisan test` without the `--testsuite` flag now runs all
three suites, including Browser — that works too, provided Playwright is
installed. For the fast pre-commit loop prefer the explicit Unit+Feature
run above.

---

## What the Next Session Needs to Know

The E2E roadmap now unblocks five sessions that can run in parallel:

- **E2E-1 Authentication Flows** — owns `tests/Browser/Auth/`. Uses
  `AuthHelper` read-only.
- **E2E-2 Business Onboarding Wizard** — owns `tests/Browser/Onboarding/`.
  Owns `OnboardingHelper` (currently a stub).
- **E2E-3 Public Booking Flow** — owns `tests/Browser/Booking/`. Owns
  `BookingFlowHelper` (currently a stub).
- **E2E-4 Dashboard: Bookings / Calendar / Customers** — owns
  `tests/Browser/Dashboard/`.
- **E2E-5 Dashboard: Settings** — owns `tests/Browser/Settings/`.

E2E-6 (Embed & Cross-Cutting) waits for E2E-3's `BookingFlowHelper`.
E2E-7 (Google Calendar Sync UI) is deferred until main-roadmap Session 12
lands.

Every session appends rows to `tests/Browser/route-coverage.md` — the
orchestrator verifies completeness when the roadmap closes.

### Conventions for downstream E2E sessions

- **Never write `collaborator`.** Use **staff** (dashboard role) and
  **provider** (bookable identity) — see D-061.
- **`BusinessSetup` and `AuthHelper` are read-only for E2E-1..6.** Do not
  edit them. If a new setup shape is needed, propose it in the session
  plan so E2E-0 can extend these helpers.
- Tests interact through the browser API (`visit`, `click`, `type`,
  `press`, `assertSee`, `assertUrlIs`, …). Direct DB reads are allowed
  only for assertion purposes.
- Each test uses `RefreshDatabase` (applied globally). No shared state.
- Signed URLs (magic link, invitation, verification, password reset) are
  generated inside the test via Laravel helpers and then `visit()`ed.
- `Carbon::setTestNow()` should be used before generating signed URLs
  whose expiry is asserted, to keep assertions deterministic.

---

## Current Project State

- **Backend**: no application code changed.
- **Frontend**: no application code changed. Playwright added as a devtime
  peer dependency of the browser plugin.
- **Config**:
  - `phpunit.xml` adds `Browser` testsuite.
  - `tests/Pest.php` binds `RefreshDatabase` to `Browser`, sets 10 s
    browser timeout.
  - `.gitignore` ignores `tests/Browser/Screenshots/` and
    `tests/Browser/Traces/`.
- **Tests**: 481 Unit+Feature passed (unchanged from Session R-15 baseline
  minus non-browser drift); 1 Browser smoke passed.
- **Decisions**: no new decisions; D-001 – D-076 untouched.
- **Migrations**: none.
- **i18n**: no changes.
- **Routes**: no changes.

---

## Open Questions / Deferred Items

- **E2E-2 — wizard helper**: `OnboardingHelper::completeWizard` must cover
  the five onboarding steps, admin-as-provider opt-in, and the launch
  gate (D-062). Stub in place; E2E-2 owns the implementation.
- **E2E-3 — booking helper**: `BookingFlowHelper::bookAsGuest` /
  `bookAsRegistered` must cover provider choice on/off, honeypot,
  customer-prefill, and return the management token. Stub in place;
  E2E-3 owns the implementation.
- **Pest browser parallelism**: the CI browser-tests job uses
  `--parallel`. E2E-1..6 authors should verify locally with `--parallel`
  before landing — if a test relies on implicit singleton state it will
  surface there.
- Carry-over items from R-15:
  - R-16 frontend code splitting (standalone session).
  - All carry-over items previously listed in the R-15 HANDOFF remain
    deferred (SMS/WhatsApp reminders, popup widget i18n, slug-alias
    history, etc.).
