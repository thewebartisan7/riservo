# Browser Baseline Fixes and Payments E2E-P0 Setup

This plan lives at `docs/PLAN.md` and follows `.claude/references/PLAN.md`. It is a living execution record for the Browser-suite repair and the first parked Payments E2E setup slice. No new D-NNN architectural decision is introduced in this run; the Payments roadmap remains parked except for the E2E-P0 foundation helpers.


## Purpose / Big Picture

The Browser suite had two launch blockers: 11 pre-existing failures in the public booking flow, and no reusable browser-mode foundation for the Payments E2E roadmap. After this session, the existing browser baseline is green again, and future Payments E2E sessions can build on a fake-Stripe world builder, route-aware page objects, and a signed Stripe Connect webhook helper without touching real Stripe.


## Progress

- [x] (2026-04-29 00:00Z) Phase 0 gate rerun with corrected Postgres username `mir`; local Postgres was reachable and Browser tests could start.
- [x] (2026-04-29 00:00Z) Read the Browser-test support files and roadmap context: `tests/Browser/Support/BusinessSetup.php`, `tests/Browser/Support/BookingFlowHelper.php`, `tests/Pest.php`, `docs/TESTS.md`, `.claude/references/PLAN.md`, and `docs/roadmaps/ROADMAP-E2E-PAYMENTS.md`.
- [x] (2026-04-29 00:00Z) Diagnosed the 11 Browser failures as one date-picker root cause: PHP `travelTo()` froze the server clock, but the browser calendar still rendered the real month.
- [x] (2026-04-29 00:00Z) Added full target-date calendar navigation to `BookingFlowHelper` and rewired the direct booking tests to use it.
- [x] (2026-04-29 00:00Z) Implemented E2E-P0 Payments helpers, page objects, FakeStripeClient browser defaults, Stripe event helper, README, and the single smoke test.
- [x] (2026-04-29 00:00Z) Verified Browser suite: `php artisan test tests/Browser --compact` passed 250 tests / 958 assertions.
- [x] (2026-04-29 00:00Z) Verified Feature + Unit suite: `php artisan test tests/Feature tests/Unit --compact` passed 980 tests / 4249 assertions.
- [x] (2026-04-29 00:00Z) Verified static/tooling checks: Pint, PHPStan, Wayfinder generation, Vite build, and `npx tsc --noEmit`.
- [x] (2026-04-29 00:00Z) Updated `docs/HANDOFF.md` and restored `docs/REVIEW.md` after the corrected Phase 0 credentials made the earlier blocker note stale.
- [x] (2026-04-29 00:00Z) Fixed the remaining `BookingsListPaymentFilterTest` factory flake by giving all same-provider bookings deterministic non-overlapping windows.


## Surprises & Discoveries

- **Observation**: `docs/TESTING.md` is not present in this repo; the current testing guide is `docs/TESTS.md`.
  **Evidence**: `sed -n '1,220p' docs/TESTING.md` failed with "No such file", while `docs/TESTS.md` exists and documents the suite.
  **Consequence**: Used `docs/TESTS.md` for repository testing conventions.

- **Observation**: The 11 Browser failures shared one root cause, but not one test file.
  **Evidence**: The failing pages expected seeded `09:00` slots after clicking a day number. Screenshots and scripts showed the client calendar opened the real browser month while server-side seed dates used the frozen PHP date.
  **Consequence**: Fixed the shared Browser support helper and only changed direct tests that bypassed it.

- **Observation**: Pest Browser files already extend `Tests\TestCase` globally.
  **Evidence**: Registering a dedicated base class for `Browser/Payments` collided with the existing Browser registration in `tests/Pest.php`.
  **Consequence**: `PaymentsTestCase` shipped as a trait with a `beforeEach` hook, not as a base class. This is the only roadmap-shape deviation.

- **Observation**: Running two Pest sessions against `riservo_ch_testing` at the same time can corrupt the visible schema mid-run.
  **Evidence**: A concurrent local run reported `SQLSTATE[42703]: Undefined column: column "country" of relation "businesses" does not exist` while another Browser suite was refreshing the same Postgres test database.
  **Consequence**: Treat Browser/Feature runs as single-writer against the shared testing database. The final non-overlapping Browser rerun passed 250 / 958.

- **Observation**: `BookingsListPaymentFilterTest` still had random factory times in three tests.
  **Evidence**: A full-suite run hit `bookings_no_provider_overlap` when two random factory bookings for the same provider overlapped.
  **Consequence**: Added deterministic non-overlapping booking windows for every booking created in that file.


## Decision Log

- **Decision**: Keep Phase 1 fixes in test/support code only.
  **Rationale**: The production booking flow was not regressed; the failure came from the test harness assuming PHP clock travel also affected the browser calendar.
  **Date / Author**: 2026-04-29 / Codex.

- **Decision**: Implement `PaymentsTestCase` as a Pest trait rather than a base class.
  **Rationale**: Pest already applies `Tests\TestCase` to the Browser tree. A trait gives the Payments files the same setup API without fighting the global Browser base.
  **Date / Author**: 2026-04-29 / Codex.

- **Decision**: Keep Payments page objects thin and assertion-oriented.
  **Rationale**: E2E-P0 is infrastructure. P1..P7 should add scenario-specific flows, not inherit speculative helper behavior that has not been exercised yet.
  **Date / Author**: 2026-04-29 / Codex.


## Review

No Codex review round was requested or run in this execution session. The working tree is staged for the maintainer to review and commit.


## Outcomes & Retrospective

The Browser baseline is green again: 250 tests / 958 assertions. The prior 11 failures were all fixed with a single full-date calendar selection helper plus direct-test rewires. No Browser test was skipped or marked `todo()`.

E2E-P0 is available for the parked Payments E2E roadmap. It includes a reusable world builder, fake Stripe browser adapter, signed Connect webhook dispatch helper, route-aware page objects, documentation, and one smoke test proving the active connected-account state can be rendered in a browser.


## Context and Orientation

Browser tests live under `tests/Browser`. Shared booking setup lives in `tests/Browser/Support/BusinessSetup.php`; public booking funnel helpers live in `tests/Browser/Support/BookingFlowHelper.php`. These tests seed Laravel state in PHP and then drive a real browser. That distinction matters: Laravel's `travelTo()` changes server-side time, but JavaScript `new Date()` in the browser still sees the machine clock.

Payments E2E support now lives under `tests/Browser/Support/Payments`. `PaymentsWorld` creates a launched Business with admin, provider, service, customer, and optional Stripe connected-account state. `PaymentsTestCase` wires the fake Stripe client and exposes the Connect webhook dispatcher. The page objects under `tests/Browser/Support/Payments/Pages` wrap Pest Browser `Page` objects and expose intent-named actions and assertions.


## Phase 1 — Browser fixes

All 11 pre-existing failures had the same diagnosis: tests seeded availability on the next Tuesday relative to the frozen PHP clock, then clicked only a day number in the browser calendar. Because the browser month was not frozen, the click happened in the wrong month and the `09:00` slot was never visible.

- `Tests\Browser\Booking\AnyAvailableProviderTest` - diagnosis: shared booking funnel clicked the target day in the browser's current month. Fix: `BookingFlowHelper::driveFunnel()` now calls full target-date selection before asserting the `09:00` slot.
- `Tests\Browser\Booking\AutoAssignProviderTest` - diagnosis: same shared helper path when provider choice is skipped. Fix: same `BookingFlowHelper` date navigation.
- `Tests\Browser\Booking\ConfirmationAndCancelTest` - diagnosis: booking-management setup used the shared funnel and landed on the wrong month. Fix: same `BookingFlowHelper` date navigation.
- `Tests\Browser\Booking\GuestBookingHappyPathTest::it books a guest appointment end-to-end with a specific provider` - diagnosis: direct day-number click bypassed the shared helper. Fix: store the seeded `CarbonImmutable` date and call `BookingFlowHelper::selectDateAndTime()`.
- `Tests\Browser\Booking\GuestBookingHappyPathTest::it creates a pending booking when confirmation_mode is manual` - diagnosis: same direct click bypass. Fix: same full-date helper.
- `Tests\Browser\Booking\GuestBookingHappyPathTest::it reuses an existing Customer row when the email already exists` - diagnosis: same direct click bypass. Fix: same full-date helper.
- `Tests\Browser\Booking\HoneypotTest` - diagnosis: direct date click in the wrong browser month. Fix: same full-date helper.
- `Tests\Browser\Booking\RegisteredCustomerFlowTest::it pre-fills the customer form for a logged-in customer user` - diagnosis: direct date click in the wrong browser month. Fix: same full-date helper.
- `Tests\Browser\Booking\RegisteredCustomerFlowTest::it creates a booking linked to the existing Customer when a registered customer completes the flow` - diagnosis: direct date click in the wrong browser month. Fix: same full-date helper.
- `Tests\Browser\Booking\ValidationTest` - diagnosis: validation test selected the seeded date by day number only. Fix: same full-date helper.
- `Tests\Browser\Embed\IframeEmbedTest::it completes a full guest booking through the iframe embed` - diagnosis: embed flow used the shared funnel with the same month mismatch. Fix: same `BookingFlowHelper` date navigation.


## Phase 2 — E2E-P0 setup

E2E-P0 shipped only the reusable setup layer and one smoke test:

- `tests/Browser/Support/Payments/PaymentsTestCase.php`: Pest trait wiring `RefreshDatabase`, `FakeStripeClient::forBrowser($this)`, `paymentsWorld()`, and `dispatchStripeConnectEvent()`.
- `tests/Browser/Support/Payments/PaymentsWorld.php`: fluent builder with `default()`, connected-account states, online/customer-choice payment modes, and a `build()` array of seeded models.
- `tests/Browser/Support/Payments/Pages/*`: thin page objects for Connected Account, public Booking, Booking Summary, Payment Success, Booking Management, Dashboard Booking Detail, Payouts, and Settings Booking.
- `tests/Support/Billing/FakeStripeClient.php`: `forBrowser()` mode plus stub URL registry for login links, account links, and checkout sessions using `https://stripe.test/external/{uuid}`.
- `tests/Support/Billing/StripeEventBuilder.php`: canonical `checkout.session.*` event payload builder for browser-mode webhook dispatch.
- `tests/Browser/Support/Payments/README.md`: helper and page-object documentation with usage snippets.
- `tests/Browser/Payments/SmokeTest.php`: active connected-account smoke test.

Post-Hardening drift items were baked into the helper contracts:

- D-184: dashboard booking-detail page object asserts server-side redirect paths, not raw Stripe dashboard URLs or exposed raw IDs.
- D-185: connected-account and payouts page objects assert `requirementsCount` style copy, not raw `requirements_currently_due` field paths.
- D-183: settings page object treats CH-centric copy as locked literal copy; supported-country config affects gate state only.
- G-005 / D-184 era `useHttp`: booking summary and payouts page objects assert discriminator-keyed Inertia validation errors, not custom local toast state.
- `refundRowPayload`: dashboard booking-detail refunds assertions use `stripe_refund_id_last4` and `has_stripe_link` expectations, not raw `stripe_refund_id`.

Deviation from the roadmap: `PaymentsTestCase` is a trait rather than a base class, because the Browser suite already has a Pest base-class registration. The public API exposed to tests is the same setup surface the roadmap asked for.


## Plan of Work

Phase 1 edited only Browser tests and Browser support. The reusable date selector reads the browser's visible month using JavaScript, clicks the next-month button until the target date's month is visible, waits for that day to be enabled, then selects the requested slot.

Phase 2 added the Payments support namespace and extended the test Stripe fake. Page objects intentionally avoid pre-building P1..P7 scenario flows; future sessions should add only the helpers their scenario proves.


## Concrete Steps

Commands run from `/Users/mir/Projects/riservo`:

```bash
psql -h 127.0.0.1 -p 5432 -U mir -d riservo_ch_testing -c '\dt' 2>&1 | head -20
php artisan test tests/Browser --compact
php artisan test tests/Feature tests/Unit --compact
vendor/bin/pint --dirty --format agent
./vendor/bin/phpstan
php artisan wayfinder:generate --no-interaction
npm run build
npx tsc --noEmit
```


## Validation and Acceptance

Acceptance is the command output:

```text
php artisan test tests/Browser --compact
Tests:    250 passed (958 assertions)

php artisan test tests/Feature tests/Unit --compact
Tests:    980 passed (4249 assertions)

./vendor/bin/phpstan
[OK] No errors
```

`npm run build` completed with the pre-existing Vite large-chunk warning. `npx tsc --noEmit` produced no output and exited 0. `vendor/bin/pint --dirty --format agent` fixed formatting in `tests/Pest.php` and `tests/Support/Billing/FakeStripeClient.php`.


## Idempotence and Recovery

The test changes are additive and can be rerun safely. The fake Stripe browser defaults are opt-in through `FakeStripeClient::forBrowser()`; existing Feature-test behavior through `FakeStripeClient::for($test)` remains strict unless a test registers explicit mocks. The Stripe webhook helper signs requests with a test-only secret configured inside the test before dispatch.


## Artifacts and Notes

Phase 0 originally failed with username `postgres`; the maintainer corrected the username to `mir`, and the corrected database command succeeded. The stale blocker paragraph in `docs/REVIEW.md` was therefore restored to the prior Round 3 review content instead of being kept as a session result.

No test was weakened. No `todo()` marker was added.


## Interfaces and Dependencies

- `BookingFlowHelper::selectDateAndTime(mixed $page, CarbonImmutable $targetDate, string $time = '09:00'): void` is the shared Browser helper for date/time selection.
- `PaymentsWorld::default()->withActiveStripeAccount()->withOnlinePaymentMode()->build()` returns `business`, `admin`, `provider`, `service`, `customer`, and `connectedAccount`.
- `FakeStripeClient::forBrowser(TestCase $test)` registers browser-safe defaults and external stub URLs. `FakeStripeClient::for(TestCase $test)` keeps existing Feature-test semantics.
- `PaymentsTestCase::dispatchStripeConnectEvent(string $type, array $payload = []): void` builds a canonical Stripe event, signs it, posts it to `/webhooks/stripe-connect`, and asserts HTTP 200.
- Dashboard Stripe links remain server-side redirect endpoints generated by the application route layer; page objects do not assert raw `https://dashboard.stripe.com/...` values.
