# Stripe Connect Payments — Adversarial Code Review (Round 3)
Reviewed range: bc991e8 + currently-staged diff
Date: 2026-04-29
Prior reviews: round 1 (2026-04-28), round 2 (2026-04-29) — both in git history of `docs/REVIEW.md`.

## TL;DR

Round 2 is **partial**: G-002, G-004, G-005, G-006, and the G-008 deferral verify cleanly; G-001, G-003 partial, and G-007 still have reachable gaps.
Drift is **minor but real**: the highest-impact fix is H-001, because the new Stripe dispute redirect endpoint can still deep-link from a resolved historical dispute Pending Action even when no open dispute exists.
Checks passed after a sequential rerun: `php artisan test tests/Feature tests/Unit --compact` (978 / 4246), focused deeplink + login-link tests, `./vendor/bin/phpstan`, `vendor/bin/pint --test --format agent`, and `npx tsc --noEmit`.
Discarded harness note: an earlier parallel run of two Pest files collided on the shared testing database migration lifecycle; the same tests passed sequentially.

## Round 2 Verification (Part A)

### G-001 / D-184 — Status: Incomplete

**Evidence:** routes are admin-only in `routes/web.php:168` and `routes/web.php:181`; the controller resolves Stripe IDs server-side in `app/Http/Controllers/Dashboard/StripeDashboardLinkController.php:44`, `app/Http/Controllers/Dashboard/StripeDashboardLinkController.php:69`, and `app/Http/Controllers/Dashboard/StripeDashboardLinkController.php:87`; booking-detail props remove raw IDs and whitelist PA payloads in `app/Http/Controllers/Dashboard/BookingController.php:261`, `app/Http/Controllers/Dashboard/BookingController.php:273`, `app/Http/Controllers/Dashboard/BookingController.php:290`, `app/Http/Controllers/Dashboard/BookingController.php:315`, `app/Http/Controllers/Dashboard/BookingController.php:969`, `app/Http/Controllers/Dashboard/BookingController.php:996`, and `app/Http/Controllers/Dashboard/BookingController.php:1023`; React uses Wayfinder redirect links in `resources/js/components/dashboard/booking-detail-sheet.tsx:5` and `resources/js/components/dashboard/booking-detail-sheet.tsx:147`.

**Repro:** `php artisan test tests/Feature/Dashboard/StripeDashboardLinkControllerTest.php --compact` passed 12 tests / 60 assertions, including the Inertia no-raw-ID regression guard at `tests/Feature/Dashboard/StripeDashboardLinkControllerTest.php:201`. Cross-tenant payment access, cross-booking refund nesting, staff payment/dispute attempts, and no-PA dispute are covered at `tests/Feature/Dashboard/StripeDashboardLinkControllerTest.php:97`, `tests/Feature/Dashboard/StripeDashboardLinkControllerTest.php:125`, `tests/Feature/Dashboard/StripeDashboardLinkControllerTest.php:86`, `tests/Feature/Dashboard/StripeDashboardLinkControllerTest.php:183`, and `tests/Feature/Dashboard/StripeDashboardLinkControllerTest.php:174`. However, the dispute endpoint queries only `type` + `latest('id')` at `app/Http/Controllers/Dashboard/StripeDashboardLinkController.php:93`; it does not filter `status = pending`, unlike the booking-list eager load at `app/Http/Controllers/Dashboard/BookingController.php:93`. A direct admin GET can therefore redirect using a resolved historical dispute PA. Fresh finding: H-001.

### G-002 / D-185 — Status: Verified

**Evidence:** payout and connected-account payloads expose only `requirementsCount` in `app/Http/Controllers/Dashboard/PayoutsController.php:313` and `app/Http/Controllers/Dashboard/Settings/ConnectedAccountController.php:729`; React types/renderers consume the count in `resources/js/pages/dashboard/payouts.tsx:31`, `resources/js/pages/dashboard/payouts.tsx:331`, `resources/js/pages/dashboard/settings/connected-account.tsx:36`, and `resources/js/pages/dashboard/settings/connected-account.tsx:180`.

**Repro:** `rg` found no `requirementsCurrentlyDue` consumer in the in-scope React files. Remaining `requirements_currently_due` hits are DB persistence / Stripe sync paths, not Inertia payload array exposure.

### G-003 partial — Status: Incomplete

**Evidence:** the shared `formatMoney` helper exists at `resources/js/lib/format-money.ts:14`; documented call sites consume it at `resources/js/components/dashboard/refund-dialog.tsx:60`, `resources/js/components/dashboard/booking-detail-sheet.tsx:141`, `resources/js/components/dashboard/booking-detail-sheet.tsx:145`, and `resources/js/components/dashboard/booking-detail-sheet.tsx:369`; the deferred input parser is unchanged at `resources/js/components/dashboard/refund-dialog.tsx:177`. But a money-rendering `toFixed(2)` fallback remains in `resources/js/pages/dashboard/payouts.tsx:613`.

**Repro:** `rg "toFixed\\(2\\)"` over the in-scope frontend files still reports `resources/js/pages/dashboard/payouts.tsx:622`. The refund input `max` attribute at `resources/js/components/dashboard/refund-dialog.tsx:129` is part of the deferred input/parser work; the payouts fallback is display rendering. Fresh finding: H-002.

### G-004 — Status: Verified

**Evidence:** `payment-success.tsx` imports the Wayfinder helper at `resources/js/pages/booking/payment-success.tsx:6` and renders `bookingShow.url(booking.token)` at `resources/js/pages/booking/payment-success.tsx:47`; the generated route helper targets `/bookings/{token}` at `resources/js/actions/App/Http/Controllers/Booking/BookingManagementController.ts:3`.

**Repro:** `rg` found no `/bookings/${token}` literal in `resources/js/pages/booking/payment-success.tsx`.

### G-005 — Status: Verified

**Evidence:** backend failures now throw `ValidationException::withMessages(['login_link' => ...])` in `app/Http/Controllers/Dashboard/PayoutsController.php:128`; the successful JSON shape remains `url` only at `app/Http/Controllers/Dashboard/PayoutsController.php:145`; React derives display error from `http.errors.login_link` in `resources/js/pages/dashboard/payouts.tsx:445` and resets pending state in `resources/js/pages/dashboard/payouts.tsx:459`; the regression test asserts `assertJsonValidationErrors(['login_link'])` at `tests/Feature/Dashboard/PayoutsControllerTest.php:387`.

**Repro:** `php artisan test tests/Feature/Dashboard/PayoutsControllerTest.php --compact --filter=login-link` passed 4 tests / 7 assertions. `rg` found no local `setError` state in `resources/js/pages/dashboard/payouts.tsx`.

### G-007 — Status: Incomplete

**Evidence:** `PayoutStatusBadge` falls back to `t('Unknown')` at `resources/js/pages/dashboard/payouts.tsx:590`; `formatRefundReason` falls back to `t('Unknown')` at `resources/js/components/dashboard/booking-detail-sheet.tsx:536`. `PaymentStatusBadge` still falls back to the raw status string at `resources/js/components/dashboard/payment-status-badge.tsx:31`, and its comment still documents raw-status fallback at `resources/js/components/dashboard/payment-status-badge.tsx:12`.

**Repro:** `rg "?? status|return schedule.interval"` shows raw fallback paths in `PaymentStatusBadge` and also in `formatSchedule` at `resources/js/pages/dashboard/payouts.tsx:637`. A new payment status or payout schedule interval would render an untranslated internal token. Fresh finding: H-003.

### G-006 / D-183 — Status: Verified

**Evidence:** the Settings booking copy comment now cites D-183 and locked roadmap decision #43 at `resources/js/pages/dashboard/settings/booking.tsx:77`; the accepted D-183 body records the same precedence at `docs/decisions/DECISIONS-PAYMENTS.md:1095`.

**Repro:** confirmed only the comment/decision linkage; I did not re-litigate the CH-centric copy.

### G-008 — Status: Out-of-scope

**Evidence:** the deferred banner a11y entry exists in `docs/BACKLOG.md:10`; the handoff lists the same deferral in `docs/HANDOFF.md:68`.

**Repro:** confirmed the backlog entry exists and did not review or fix banner behavior.

## Drift Findings (Part B)

### H-001 — Dispute Stripe-link endpoint does not require an open dispute PA

**Severity:** Medium
**Category:** Stripe dashboard redirect / lifecycle state
**Location:** `app/Http/Controllers/Dashboard/StripeDashboardLinkController.php:93`, `app/Http/Controllers/Dashboard/BookingController.php:93`, `tests/Feature/Dashboard/StripeDashboardLinkControllerTest.php:174`

**What is wrong:** The dispute deeplink action says it reads the booking's open dispute PA, but it queries only `type = payment.dispute_opened`, orders by latest id, and accepts whatever row it finds. The booking-list payload correctly filters pending actions by `status = pending`; the direct redirect endpoint does not.

**How to break it:** Create a paid booking with `stripe_connected_account_id`, then create a `payment.dispute_opened` Pending Action with `status = resolved` and a `payload.dispute_id`. As admin, GET `/dashboard/bookings/{booking}/stripe-link/dispute`. There is no open dispute PA, so the Round 3 mandate expects 404, but the controller will read the resolved row's `dispute_id` and 302 to Stripe. If a newer resolved historical row exists alongside an older pending row, `latest('id')->first()` selects the historical row.

**Suggested fix shape:** Import/use `PendingActionStatus` and add `->where('status', PendingActionStatus::Pending->value)` before `latest('id')`. Add tests for resolved-only dispute PA returning 404 and pending+newer-resolved selecting the pending/open row or returning 404 by explicit product choice.

### H-002 — A `toFixed(2)` money-rendering fallback still survives in Payouts

**Severity:** Low
**Category:** Money / currency formatting
**Location:** `resources/js/pages/dashboard/payouts.tsx:613`, `resources/js/lib/format-money.ts:14`

**What is wrong:** G-003 partial introduced `formatMoney`, but the Payouts page still has its own `formatAmount()` helper with an Intl try/catch and a `${(amountCents / 100).toFixed(2)} ${code}` fallback. That violates the Round 3 "NO `toFixed(2)` survives in in-scope frontend files for money rendering" gate.

**How to break it:** Feed Payouts a payout/balance currency that makes `Intl.NumberFormat` throw, such as an empty or invalid currency from a stale/cache/error fixture. The UI will render the hand-rolled `toFixed(2)` fallback instead of using the shared display helper or failing loudly.

**Suggested fix shape:** Replace `formatAmount()` with the shared `formatMoney(amountCents, currency)` path and remove the catch fallback. If invalid currency should be tolerated, normalize or reject it server-side before it reaches the React money renderer.

### H-003 — Unknown payment/schedule values still render raw internal strings

**Severity:** Low
**Category:** Copy / i18n
**Location:** `resources/js/components/dashboard/payment-status-badge.tsx:12`, `resources/js/components/dashboard/payment-status-badge.tsx:31`, `resources/js/pages/dashboard/payouts.tsx:637`, `resources/js/pages/dashboard/payouts.tsx:590`, `resources/js/components/dashboard/booking-detail-sheet.tsx:536`

**What is wrong:** `PayoutStatusBadge` and `formatRefundReason` now use `t('Unknown')`, but `PaymentStatusBadge` still returns `status` for unknown values and `formatSchedule()` still returns `schedule.interval` for unknown intervals. This leaves the old G-007 raw-string leak pattern reachable.

**How to break it:** Add a backend payment status value before updating the React map, or let Stripe introduce a payout schedule interval not covered by `daily|weekly|monthly|manual`. The UI renders `requires_capture`, `instant`, or another raw token instead of localized fallback copy.

**Suggested fix shape:** Change both defaults to `t('Unknown')`, update the `PaymentStatusBadge` comment, and add small frontend-shape tests or source-level contract tests that assert unknown payment status, payout status, refund reason, and schedule interval all use the localized fallback.

### H-004 — Round 2 documentation overstates the new coverage and still carries a stale CH-copy invariant

**Severity:** Low
**Category:** Documentation / decision hygiene
**Location:** `docs/decisions/DECISIONS-PAYMENTS.md:1123`, `tests/Feature/Dashboard/StripeDashboardLinkControllerTest.php:86`, `tests/Feature/Dashboard/StripeDashboardLinkControllerTest.php:125`, `docs/HANDOFF.md:119`, `docs/decisions/DECISIONS-PAYMENTS.md:1095`

**What is wrong:** D-184 says `StripeDashboardLinkControllerTest.php` covers "3 endpoints × happy / staff-403 / cross-tenant-404 / missing-id-404 / cross-booking-refund-404 / dispute-no-PA-404", but the actual file does not have staff-refund, cross-tenant-refund, cross-tenant-dispute, or resolved-dispute/no-open-PA cases. Separately, `docs/HANDOFF.md` still says "No hardcoded 'CH' literal anywhere", which contradicts D-183's accepted position that CH-centric copy is deliberate until the locale-list audit.

**How to break it:** A future maintainer trusts the D-184 coverage claim and misses H-001, or a future reviewer trusts the stale Handoff invariant and reopens G-006 despite D-183.

**Suggested fix shape:** Either add the missing D-184 tests or narrow the D-184 consequence text to the paths actually covered. Update the Handoff invariant to match D-183: config owns gate state; locked roadmap decision #43 / D-183 owns CH-centric copy until the locale-list audit.

## Coverage Gaps

- Add a deeplink regression test where a booking has only a resolved `payment.dispute_opened` PA; expected 404.
- Add a deeplink regression test where a booking has both a pending dispute PA and a newer resolved dispute PA; pin the intended selection semantics.
- Add explicit staff-refund, cross-tenant-refund, and cross-tenant-dispute tests, or remove the D-184 claim that those paths are covered.
- Add an urgent `pending_payment_action` prop-shape test with `leaked_*` keys; current regression coverage proves the dispute payload whitelist but not the urgent PA whitelist directly.
- Add frontend/source-level assertions for unknown `PaymentStatusBadge`, `PayoutStatusBadge`, `formatRefundReason`, and `formatSchedule` fallbacks.
- Add a source-level assertion that in-scope money display code calls `formatMoney` and does not contain display `toFixed(2)` fallbacks.
- Add a focused component-shape assertion for `http.errors.login_link` rendering if/when the project adds a non-browser React test harness; the server envelope is covered.

## D-ID Drift

- D-184 — dispute deeplink behavior says no open dispute should 404, but the controller accepts resolved historical dispute PAs because it does not filter `status = pending` (`app/Http/Controllers/Dashboard/StripeDashboardLinkController.php:93`).
- D-184 — decision text overstates test coverage for all endpoint combinations; the current test file covers the main paths but not staff-refund, cross-tenant-refund, cross-tenant-dispute, or resolved-dispute/no-open-PA.
- D-183 — accepted decision is coherent, but `docs/HANDOFF.md:119` still carries the stale "No hardcoded 'CH' literal anywhere" invariant.

## What I Did Not Cover

- I did not run Pest Browser tests or the manual Stripe end-to-end walkthrough.
- I did not hit real Stripe APIs; all Stripe behavior was reviewed through local code, fakes, and existing tests.
- I did not review unrelated auth, scheduling, calendar sync, or generic Cashier subscription code beyond the payments paths needed for this review.
- I did not create new regression tests or modify production code; this pass only overwrote `docs/REVIEW.md`.
