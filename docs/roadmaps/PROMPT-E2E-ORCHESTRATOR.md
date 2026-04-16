# Prompt — E2E Orchestrator (Phase 2–4)

## Precondition — verify before doing anything

E2E-0 must be complete before this session starts. Verify by running:

```bash
./vendor/bin/pest tests/Browser/SmokeTest.php
```

If the smoke test does not pass, **stop immediately** and report the failure. Do not spawn any subagents until the smoke test is green.

Also confirm the following exist before proceeding:

- `tests/Browser/Support/BusinessSetup.php` — **fully implemented** (not a stub).
- `tests/Browser/Support/AuthHelper.php` — **fully implemented** (not a stub).
- `tests/Browser/Support/OnboardingHelper.php` — stub signatures present, bodies empty.
- `tests/Browser/Support/BookingFlowHelper.php` — stub signatures present, bodies empty.
- `tests/Browser/route-coverage.md` — route coverage ledger with every named route from `routes/web.php`.
- `tests/Browser/Screenshots/` and `tests/Browser/Traces/` are gitignored.
- `phpunit.xml` has a `Browser` testsuite bound to `tests/Browser`.

If any of these are missing, E2E-0 is incomplete. Stop and report.

---

## Your role

You are the **orchestrator agent** for the riservo.ch E2E test implementation. You do not write tests yourself during Phase 2. Your job is to:

1. Spawn **five parallel subagents** for sessions E2E-1 through E2E-5 simultaneously.
2. Wait for all five to report completion.
3. Execute **E2E-6** (Embed, Cross-Cutting & Accessibility) yourself once subagents A–E are done. E2E-6 depends on E2E-3 (BookingFlowHelper must be filled) — if you want to start E2E-6 early, wait until subagent C (E2E-3) reports complete.
4. Run the final verification and produce a completion report.

Read `docs/roadmaps/ROADMAP-E2E.md` now if you have not already. Every session checklist and file-ownership rule defined there governs what your subagents must do.

**Do not spawn a subagent for E2E-7 (Google Calendar Sync UI). That session is deferred pending main-roadmap Session 12.**

---

## Phase 2 — Spawn all subagents simultaneously

Spawn the following five subagents **at the same time** using the `Task` tool (single message, multiple Task calls). Do not wait for one to finish before starting the next.

Each subagent must:

- Write tests only; never modify application source code.
- Use factories for test data; never the main database seeder.
- Avoid `sleep()` and fixed timeouts; use Pest's built-in waiting (`pressAndWaitFor`, `wait`, implicit assertion waits).
- Ensure `./vendor/bin/pest tests/Browser` passes (for their slice) before reporting done.
- Update `tests/Browser/route-coverage.md` with a row for each route they cover.
- Never use the word "collaborator"; use **staff** (dashboard role) or **provider** (bookable identity) per D-061.
- Use the Pest 4 native API: global `visit($url)` returns a chainable `$page` object. There is no `Pest\Browser\browser()` function.

---

### Subagent A — E2E-1: Authentication Flows

> You are a test-writing subagent for the riservo.ch project. Your assignment is **E2E-1 — Authentication Flows**.
>
> Start by reading:
> - `docs/README.md`
> - `docs/ARCHITECTURE-SUMMARY.md`
> - `docs/roadmaps/ROADMAP-E2E.md` (E2E-1 section and Testing Guidelines)
> - `routes/web.php` (auth routes — register, login, logout, magic-link, password reset, invitation, customer register, email verification)
> - `resources/js/pages/auth/` (all eight auth pages)
> - `tests/Browser/Support/AuthHelper.php` (completed in E2E-0 — read-only reference; do not modify)
> - `tests/Browser/Support/BusinessSetup.php` (completed in E2E-0 — read-only reference; do not modify)
>
> Then implement every item in the E2E-1 checklist from the roadmap.
>
> **Files to create:**
> - `tests/Browser/Auth/RegistrationTest.php`
> - `tests/Browser/Auth/EmailVerificationTest.php`
> - `tests/Browser/Auth/LoginTest.php`
> - `tests/Browser/Auth/LogoutTest.php`
> - `tests/Browser/Auth/MagicLinkRequestTest.php`
> - `tests/Browser/Auth/MagicLinkVerifyTest.php`
> - `tests/Browser/Auth/PasswordResetTest.php`
> - `tests/Browser/Auth/InviteAcceptanceTest.php`
> - `tests/Browser/Auth/CustomerRegistrationTest.php`
> - `tests/Browser/Auth/RouteProtectionTest.php`
>
> **Files to modify:**
> - `tests/Browser/route-coverage.md` — append coverage rows for the auth-related named routes.
>
> **Do not modify any file in `tests/Browser/Support/`.** Do not touch any file outside `tests/Browser/Auth/` and the coverage ledger.
>
> When done: run `./vendor/bin/pest tests/Browser/Auth`, confirm all new tests pass, report back with the test count, which routes are covered, and any blockers.

---

### Subagent B — E2E-2: Business Onboarding Wizard

> You are a test-writing subagent for the riservo.ch project. Your assignment is **E2E-2 — Business Onboarding Wizard**.
>
> Start by reading:
> - `docs/README.md`
> - `docs/ARCHITECTURE-SUMMARY.md`
> - `docs/roadmaps/ROADMAP-E2E.md` (E2E-2 section and Testing Guidelines)
> - `docs/decisions/DECISIONS-DASHBOARD-SETTINGS.md` — specifically D-040 (wizard resume), D-041 (invitation service pre-assignment), D-062 (admin-as-provider launch gate), D-042 (logo upload).
> - `routes/web.php` (onboarding routes)
> - `resources/js/pages/onboarding/step-1.tsx` through `step-5.tsx`
> - `resources/js/pages/dashboard/welcome.tsx` (post-wizard landing)
> - `tests/Browser/Support/OnboardingHelper.php` — **stubs only; you are its exclusive owner, fill them in**
> - `tests/Browser/Support/BusinessSetup.php` — completed in E2E-0, read-only reference
>
> Then implement every item in the E2E-2 checklist from the roadmap.
>
> **Files to create:**
> - `tests/Browser/Onboarding/WizardHappyPathTest.php`
> - `tests/Browser/Onboarding/WizardAdminAsProviderTest.php`
> - `tests/Browser/Onboarding/WizardSlugCheckTest.php`
> - `tests/Browser/Onboarding/WizardLogoUploadTest.php`
> - `tests/Browser/Onboarding/WizardStaffInviteTest.php`
> - `tests/Browser/Onboarding/WizardLaunchGateTest.php`
> - `tests/Browser/Onboarding/WizardResumeTest.php`
> - `tests/Browser/Onboarding/WizardValidationTest.php`
> - `tests/Browser/Onboarding/WizardPostCompletionTest.php`
> - `tests/Browser/Onboarding/DashboardWelcomeTest.php`
>
> **Files to modify:**
> - `tests/Browser/Support/OnboardingHelper.php` — fill in the stubs (implement `completeWizard` and `advanceToStep`).
> - `tests/Browser/route-coverage.md` — append onboarding routes.
>
> **Do not modify any other file in `tests/Browser/Support/`.** Do not touch any file outside `tests/Browser/Onboarding/`, `tests/Browser/Support/OnboardingHelper.php`, and the coverage ledger.
>
> When done: run `./vendor/bin/pest tests/Browser/Onboarding`, confirm all new tests pass, report back with the test count, which routes are covered, and any blockers.

---

### Subagent C — E2E-3: Public Booking Flow

> You are a test-writing subagent for the riservo.ch project. Your assignment is **E2E-3 — Public Booking Flow**.
>
> Start by reading:
> - `docs/README.md`
> - `docs/ARCHITECTURE-SUMMARY.md`
> - `docs/SPEC.md` (sections 5–6: availability engine, public booking flow)
> - `docs/roadmaps/ROADMAP-E2E.md` (E2E-3 section and Testing Guidelines)
> - `docs/decisions/DECISIONS-BOOKING-AVAILABILITY.md` — especially D-031 (status blocking availability), D-045 (honeypot), D-065 / D-066 (overlap constraint), D-068 (server-side provider choice enforcement), D-070 (canonical service pre-filter path).
> - `docs/decisions/DECISIONS-AUTH.md` — D-074 (customer registration open, no prior booking required).
> - `routes/web.php` (public booking + customer area routes)
> - `resources/js/pages/booking/show.tsx` (the public booking funnel)
> - `resources/js/pages/bookings/show.tsx` (guest booking management via token)
> - `resources/js/pages/customer/bookings.tsx` (registered customer's own bookings)
> - `tests/Browser/Support/BookingFlowHelper.php` — **stubs only; you are its exclusive owner, fill them in**
> - `tests/Browser/Support/BusinessSetup.php` and `AuthHelper.php` — completed in E2E-0, read-only references
>
> Then implement every item in the E2E-3 checklist from the roadmap.
>
> **Files to create:**
> - `tests/Browser/Booking/LandingPageTest.php`
> - `tests/Browser/Booking/ServicePreFilterTest.php`
> - `tests/Browser/Booking/GuestBookingHappyPathTest.php`
> - `tests/Browser/Booking/AnyAvailableProviderTest.php`
> - `tests/Browser/Booking/AutoAssignProviderTest.php`
> - `tests/Browser/Booking/NoAvailabilityUxTest.php`
> - `tests/Browser/Booking/ValidationTest.php`
> - `tests/Browser/Booking/HoneypotTest.php`
> - `tests/Browser/Booking/ConfirmationAndCancelTest.php`
> - `tests/Browser/Booking/RegisteredCustomerFlowTest.php`
> - `tests/Browser/Booking/MyBookingsTest.php`
> - `tests/Browser/Booking/RateLimitTest.php`
>
> **Files to modify:**
> - `tests/Browser/Support/BookingFlowHelper.php` — fill in the stubs (implement `bookAsGuest` and `bookAsRegistered`).
> - `tests/Browser/route-coverage.md` — append public booking + customer area routes.
>
> **Do not modify `BusinessSetup.php` or `AuthHelper.php`.** Do not touch any file outside `tests/Browser/Booking/`, `tests/Browser/Support/BookingFlowHelper.php`, and the coverage ledger.
>
> When done: run `./vendor/bin/pest tests/Browser/Booking`, confirm all new tests pass, report back with the test count, which routes are covered, and any blockers.

---

### Subagent D — E2E-4: Dashboard Bookings, Calendar & Customer Directory

> You are a test-writing subagent for the riservo.ch project. Your assignment is **E2E-4 — Dashboard Bookings, Calendar & Customer Directory**.
>
> Start by reading:
> - `docs/README.md`
> - `docs/ARCHITECTURE-SUMMARY.md`
> - `docs/roadmaps/ROADMAP-E2E.md` (E2E-4 section and Testing Guidelines)
> - `docs/decisions/DECISIONS-DASHBOARD-SETTINGS.md` — D-049 (internal notes), D-051 (manual booking semantics), D-067 (soft-deleted provider historical display).
> - `routes/web.php` (dashboard routes + dashboard api routes + dashboard customers routes)
> - `resources/js/pages/dashboard.tsx`, `dashboard/bookings.tsx`, `dashboard/calendar.tsx`, `dashboard/customers.tsx`, `dashboard/customer-show.tsx`
> - `tests/Browser/Support/BusinessSetup.php` and `AuthHelper.php` — completed in E2E-0, read-only references
>
> Then implement every item in the E2E-4 checklist from the roadmap.
>
> **Files to create:**
> - `tests/Browser/Dashboard/DashboardHomeTest.php`
> - `tests/Browser/Dashboard/BookingsListTest.php`
> - `tests/Browser/Dashboard/BookingDetailPanelTest.php`
> - `tests/Browser/Dashboard/StatusTransitionsTest.php`
> - `tests/Browser/Dashboard/InternalNotesTest.php`
> - `tests/Browser/Dashboard/ManualBookingTest.php`
> - `tests/Browser/Dashboard/CalendarViewsTest.php`
> - `tests/Browser/Dashboard/CalendarProviderFilterTest.php`
> - `tests/Browser/Dashboard/CalendarStaffScopeTest.php`
> - `tests/Browser/Dashboard/CustomerDirectoryTest.php`
> - `tests/Browser/Dashboard/CustomerDetailTest.php`
>
> **Files to modify:**
> - `tests/Browser/route-coverage.md` — append dashboard routes.
>
> **Do not modify any file in `tests/Browser/Support/`.** Do not touch any file outside `tests/Browser/Dashboard/` and the coverage ledger.
>
> When done: run `./vendor/bin/pest tests/Browser/Dashboard`, confirm all new tests pass, report back with the test count, which routes are covered, and any blockers.

---

### Subagent E — E2E-5: Dashboard Settings

> You are a test-writing subagent for the riservo.ch project. Your assignment is **E2E-5 — Dashboard Settings**.
>
> Start by reading:
> - `docs/README.md`
> - `docs/ARCHITECTURE-SUMMARY.md`
> - `docs/roadmaps/ROADMAP-E2E.md` (E2E-5 section and Testing Guidelines)
> - `docs/decisions/DECISIONS-DASHBOARD-SETTINGS.md` — D-062 (admin-as-provider), D-067 (soft-delete historical display), D-076 (canonical storage URL + physical logo delete).
> - `docs/decisions/DECISIONS-BOOKING-AVAILABILITY.md` — D-068 (server-side provider choice enforcement).
> - `routes/web.php` — the whole `dashboard/settings` group, including Staff, Providers, Account, Services, Hours, Exceptions, Profile, Booking, Embed.
> - `resources/js/pages/dashboard/settings/` — every file in that tree.
> - `tests/Browser/Support/BusinessSetup.php` and `AuthHelper.php` — completed in E2E-0, read-only references
>
> Then implement every item in the E2E-5 checklist from the roadmap.
>
> **Files to create:**
> - `tests/Browser/Settings/BusinessProfileTest.php`
> - `tests/Browser/Settings/BookingSettingsTest.php`
> - `tests/Browser/Settings/BusinessHoursTest.php`
> - `tests/Browser/Settings/BusinessExceptionsTest.php`
> - `tests/Browser/Settings/ServicesTest.php`
> - `tests/Browser/Settings/StaffTest.php`
> - `tests/Browser/Settings/ProvidersTest.php`
> - `tests/Browser/Settings/AccountTest.php`
> - `tests/Browser/Settings/EmbedShareTest.php`
>
> **Files to modify:**
> - `tests/Browser/route-coverage.md` — append settings routes.
>
> **Do not include a Calendar Integration test** — that page does not exist in the codebase; the E2E-7 session is deferred pending main-roadmap Session 12.
>
> **Do not modify any file in `tests/Browser/Support/`.** Do not touch any file outside `tests/Browser/Settings/` and the coverage ledger.
>
> When done: run `./vendor/bin/pest tests/Browser/Settings`, confirm all new tests pass, report back with the test count, which routes are covered, and any blockers.

---

## Phase 3 — E2E-6: Embed, Cross-Cutting & Accessibility (you execute this directly)

**Wait for Subagent C (E2E-3) to report completion before starting Phase 3.** E2E-6 uses `BookingFlowHelper::bookAsGuest`, which is filled in by Subagent C. You may start as soon as C reports green, even if A/B/D/E are still running — but all five must complete before Phase 4.

Read the E2E-6 section of `docs/roadmaps/ROADMAP-E2E.md` and implement it yourself.

**Files to create:**
- `tests/Browser/Embed/IframeEmbedTest.php`
- `tests/Browser/Embed/PopupEmbedTest.php`
- `tests/Browser/CrossCutting/AuthorizationEdgeCasesTest.php`
- `tests/Browser/CrossCutting/AccessibilityTest.php`
- `tests/Browser/CrossCutting/TimezoneTest.php`
- `tests/Browser/CrossCutting/SmokeAllPagesTest.php`

**Files to modify:**
- `tests/Browser/route-coverage.md` — append embed + cross-cutting rows.

Do not modify any Support file. Use the helpers as they are.

---

## Phase 4 — Final verification (you execute this directly)

Wait for all Phase 2 subagents and Phase 3 to complete before running this.

1. Run the full suite:
   - `php artisan test` (Unit + Feature) — must be green.
   - `./vendor/bin/pest tests/Browser --parallel` — must be green.
2. Run `npm run build` — must be clean.
3. Verify `tests/Browser/route-coverage.md` — every named route has a non-empty "Covered by" column. Flag any gaps.
4. Create `docs/reviews/E2E-IMPLEMENTATION-REPORT.md` with:
   - Total E2E test count by session.
   - Routes covered (should match the ledger).
   - Any tests skipped and why (e.g., Calendar Integration UI deferred pending Session 12).
   - Any flaky tests discovered and how they were fixed.
   - Any application bugs surfaced during testing (not fixed in this session — logged as follow-ups).
   - Open follow-up items for future sessions.
5. Commit all changes: `test: E2E test suite implementation (E2E-1 through E2E-6)`.

---

## File ownership reference

| File / Directory | Owner |
|---|---|
| `tests/Browser/Support/BusinessSetup.php` | E2E-0 (complete); all other sessions read-only |
| `tests/Browser/Support/AuthHelper.php` | E2E-0 (complete); all other sessions read-only |
| `tests/Browser/Support/OnboardingHelper.php` | Subagent B (E2E-2) fills stubs; others read-only |
| `tests/Browser/Support/BookingFlowHelper.php` | Subagent C (E2E-3) fills stubs; others read-only |
| `tests/Browser/Auth/` | Subagent A (E2E-1) |
| `tests/Browser/Onboarding/` | Subagent B (E2E-2) |
| `tests/Browser/Booking/` | Subagent C (E2E-3) |
| `tests/Browser/Dashboard/` | Subagent D (E2E-4) |
| `tests/Browser/Settings/` | Subagent E (E2E-5) |
| `tests/Browser/Embed/` | Orchestrator (Phase 3) |
| `tests/Browser/CrossCutting/` | Orchestrator (Phase 3) |
| `tests/Browser/route-coverage.md` | All subagents append rows for their routes; orchestrator verifies completeness in Phase 4 |
| `docs/reviews/E2E-IMPLEMENTATION-REPORT.md` | Orchestrator (Phase 4) |

---

## Execution order

```
[Precondition] smoke test green; BusinessSetup + AuthHelper fully implemented; OnboardingHelper + BookingFlowHelper stubs present
    │
    ├─▶ Subagent A — E2E-1  Authentication             ─┐
    ├─▶ Subagent B — E2E-2  Onboarding Wizard           │
    ├─▶ Subagent C — E2E-3  Public Booking              │  all five parallel, spawned simultaneously
    ├─▶ Subagent D — E2E-4  Dashboard/Calendar/CRM      │
    └─▶ Subagent E — E2E-5  Settings                    ─┘
                              │
                    wait for C │  (E2E-6 depends on BookingFlowHelper)
                              ▼
                 Phase 3: E2E-6  Embed + Cross-Cutting  [orchestrator]
                              │
                    wait for all five + E2E-6 to finish
                              ▼
                 Phase 4: Verification + Report          [orchestrator]
```

---

## Rules for every agent in this session

- Write tests only. Never modify application source code. If a test reveals a bug, document it in the final report; do not fix it in this session.
- Use factories for all test data. Never the main database seeder.
- No `sleep()` or fixed timeouts. Use Pest's built-in waiting.
- Every test is independently runnable — no cross-test shared state (`RefreshDatabase` is bound to the `Browser` suite).
- Pest API is `visit($url)` — global function returning a chainable `$page`. Not `browser()->visit()`; not Laravel Dusk.
- Terminology: **staff** for dashboard role, **provider** for bookable identity. "Collaborator" is retired (D-061).
- On completion, always run `./vendor/bin/pest tests/Browser/<YourDir>` and confirm green before reporting done.
