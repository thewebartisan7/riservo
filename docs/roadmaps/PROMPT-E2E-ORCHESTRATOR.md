# Prompt — E2E Orchestrator (Phase 2–4)

## Precondition — verify before doing anything

E2E-0 must be complete before this session starts. Verify by running:

```bash
php artisan test --group=e2e
```

If the smoke test in `tests/Browser/SmokeTest.php` does not pass, **stop immediately** and report the failure. Do not spawn any subagents until the smoke test is green.

Also confirm the following exist before proceeding:

- `tests/Browser/Support/BusinessSetup.php`
- `tests/Browser/Support/AuthHelper.php`
- `tests/Browser/Support/OnboardingHelper.php`
- `tests/Browser/Support/BookingFlowHelper.php`

If any of these are missing, E2E-0 is incomplete. Stop and report.

---

## Your role

You are the **orchestrator agent** for the riservo.ch E2E test implementation. You do not write tests yourself. Your job is to:

1. Spawn **parallel subagents** for all independent sessions simultaneously.
2. Wait for all subagents to report completion.
3. Execute **E2E-6** (Embed, Cross-Cutting & Accessibility) yourself once subagents are done.
4. Run the final verification and produce a completion report.

Read `docs/roadmaps/ROADMAP-E2E.md` now if you have not already. Every session checklist and file-ownership rule defined there governs what your subagents must do.

---

## Phase 2 — Spawn all subagents simultaneously

Spawn the following five subagents **at the same time** using the `Task` tool. Do not wait for one to finish before starting the next.

---

### Subagent A — E2E-1: Authentication Flows

> You are a test-writing subagent for the riservo.ch project. Your assignment is **E2E-1 — Authentication Flows**.
>
> Start by reading:
> - `docs/README.md`
> - `docs/ARCHITECTURE-SUMMARY.md`
> - `docs/roadmaps/ROADMAP-E2E.md` (E2E-1 section and Testing Guidelines)
> - `routes/web.php` (auth routes)
> - `resources/js/pages/` (auth pages)
> - `tests/Browser/Support/AuthHelper.php` (the stubs you must fill in)
>
> Then implement every item in the E2E-1 checklist from the roadmap.
>
> **Your files:**
> - Create: `tests/Browser/Auth/RegistrationTest.php`
> - Create: `tests/Browser/Auth/LoginTest.php`
> - Create: `tests/Browser/Auth/MagicLinkTest.php`
> - Create: `tests/Browser/Auth/PasswordResetTest.php`
> - Create: `tests/Browser/Auth/InviteAcceptanceTest.php`
> - Create: `tests/Browser/Auth/RouteProtectionTest.php`
> - Extend: `tests/Browser/Support/AuthHelper.php` — fill in the stub methods (do not add new public methods without checking the stub first)
>
> **Do not touch any file outside `tests/Browser/Auth/` and `tests/Browser/Support/AuthHelper.php`.**
>
> When done: run `php artisan test --group=e2e`, confirm all new tests pass, report back with test count and any blockers.

---

### Subagent B — E2E-2: Business Onboarding Wizard

> You are a test-writing subagent for the riservo.ch project. Your assignment is **E2E-2 — Business Onboarding Wizard**.
>
> Start by reading:
> - `docs/README.md`
> - `docs/ARCHITECTURE-SUMMARY.md`
> - `docs/roadmaps/ROADMAP-E2E.md` (E2E-2 section and Testing Guidelines)
> - `routes/web.php` (onboarding routes)
> - `resources/js/pages/` (onboarding pages)
> - `tests/Browser/Support/OnboardingHelper.php` and `tests/Browser/Support/BusinessSetup.php` (the stubs you must fill in)
>
> Then implement every item in the E2E-2 checklist from the roadmap.
>
> **Your files:**
> - Create: `tests/Browser/Onboarding/WizardHappyPathTest.php`
> - Create: `tests/Browser/Onboarding/WizardAdminAsProviderTest.php`
> - Create: `tests/Browser/Onboarding/WizardSlugCheckTest.php`
> - Create: `tests/Browser/Onboarding/WizardValidationTest.php`
> - Create: `tests/Browser/Onboarding/WizardResumeTest.php`
> - Create: `tests/Browser/Onboarding/WizardPostCompletionTest.php`
> - Extend: `tests/Browser/Support/OnboardingHelper.php` — fill in stubs
> - Extend: `tests/Browser/Support/BusinessSetup.php` — fill in stubs (you are the primary owner of this file)
>
> **Do not touch any file outside `tests/Browser/Onboarding/`, `tests/Browser/Support/OnboardingHelper.php`, and `tests/Browser/Support/BusinessSetup.php`.**
>
> When done: run `php artisan test --group=e2e`, confirm all new tests pass, report back with test count and any blockers.

---

### Subagent C — E2E-3: Public Booking Flow

> You are a test-writing subagent for the riservo.ch project. Your assignment is **E2E-3 — Public Booking Flow**.
>
> Start by reading:
> - `docs/README.md`
> - `docs/ARCHITECTURE-SUMMARY.md`
> - `docs/SPEC.md` (sections 5 and 6)
> - `docs/roadmaps/ROADMAP-E2E.md` (E2E-3 section and Testing Guidelines)
> - `routes/web.php` (public booking routes)
> - `resources/js/pages/` (booking pages)
> - `tests/Browser/Support/BookingFlowHelper.php` and `tests/Browser/Support/BusinessSetup.php` (stubs to fill in)
>
> Then implement every item in the E2E-3 checklist from the roadmap.
>
> **Your files:**
> - Create: `tests/Browser/Booking/BusinessLandingPageTest.php`
> - Create: `tests/Browser/Booking/GuestBookingFlowTest.php`
> - Create: `tests/Browser/Booking/AutoAssignBookingTest.php`
> - Create: `tests/Browser/Booking/BookingManagementTest.php`
> - Create: `tests/Browser/Booking/NoAvailabilityUxTest.php`
> - Create: `tests/Browser/Booking/ValidationTest.php`
> - Create: `tests/Browser/Booking/RateLimitTest.php`
> - Extend: `tests/Browser/Support/BookingFlowHelper.php` — fill in stubs
> - Extend: `tests/Browser/Support/BusinessSetup.php` — add methods only if they do not already exist; do not modify existing methods
>
> **Do not touch any file outside `tests/Browser/Booking/`, `tests/Browser/Support/BookingFlowHelper.php`, and `tests/Browser/Support/BusinessSetup.php`.**
>
> When done: run `php artisan test --group=e2e`, confirm all new tests pass, report back with test count and any blockers.

---

### Subagent D — E2E-4: Dashboard Bookings & Calendar

> You are a test-writing subagent for the riservo.ch project. Your assignment is **E2E-4 — Dashboard Bookings & Calendar**.
>
> Start by reading:
> - `docs/README.md`
> - `docs/ARCHITECTURE-SUMMARY.md`
> - `docs/roadmaps/ROADMAP-E2E.md` (E2E-4 section and Testing Guidelines)
> - `routes/web.php` (dashboard routes)
> - `resources/js/pages/Dashboard/` (dashboard pages)
> - `tests/Browser/Support/AuthHelper.php` and `tests/Browser/Support/BusinessSetup.php` (stubs to read — do not re-implement, only call)
>
> Then implement every item in the E2E-4 checklist from the roadmap.
>
> **Your files:**
> - Create: `tests/Browser/Dashboard/BookingsListTest.php`
> - Create: `tests/Browser/Dashboard/BookingDetailPanelTest.php`
> - Create: `tests/Browser/Dashboard/StatusTransitionsTest.php`
> - Create: `tests/Browser/Dashboard/ManualBookingTest.php`
> - Create: `tests/Browser/Dashboard/CalendarViewsTest.php`
> - Create: `tests/Browser/Dashboard/CalendarFilterTest.php`
> - Read-only: `tests/Browser/Support/AuthHelper.php` and `tests/Browser/Support/BusinessSetup.php` — call the methods but do not add to these files
>
> **Do not modify any Support files. Do not touch any file outside `tests/Browser/Dashboard/`.**
>
> When done: run `php artisan test --group=e2e`, confirm all new tests pass, report back with test count and any blockers.

---

### Subagent E — E2E-5: Dashboard Settings

> You are a test-writing subagent for the riservo.ch project. Your assignment is **E2E-5 — Dashboard Settings**.
>
> Start by reading:
> - `docs/README.md`
> - `docs/ARCHITECTURE-SUMMARY.md`
> - `docs/roadmaps/ROADMAP-E2E.md` (E2E-5 section and Testing Guidelines)
> - `routes/web.php` (settings routes)
> - `resources/js/pages/Dashboard/settings/` (settings pages)
> - `tests/Browser/Support/AuthHelper.php` and `tests/Browser/Support/BusinessSetup.php` (read-only — call the methods but do not modify these files)
>
> Then implement every item in the E2E-5 checklist from the roadmap.
>
> **Your files:**
> - Create: `tests/Browser/Settings/BusinessProfileTest.php`
> - Create: `tests/Browser/Settings/BookingSettingsTest.php`
> - Create: `tests/Browser/Settings/BusinessHoursTest.php`
> - Create: `tests/Browser/Settings/BusinessExceptionsTest.php`
> - Create: `tests/Browser/Settings/ServicesTest.php`
> - Create: `tests/Browser/Settings/CollaboratorsTest.php`
> - Create: `tests/Browser/Settings/EmbedShareTest.php`
> - Create: `tests/Browser/Settings/CalendarIntegrationTest.php`
> - Read-only: `tests/Browser/Support/AuthHelper.php` and `tests/Browser/Support/BusinessSetup.php`
>
> **Do not modify any Support files. Do not touch any file outside `tests/Browser/Settings/`.**
>
> When done: run `php artisan test --group=e2e`, confirm all new tests pass, report back with test count and any blockers.

---

## Phase 3 — E2E-6: Embed, Cross-Cutting & Accessibility (you execute this directly)

**Wait for Subagents C and E to report completion before starting Phase 3.** You may start as soon as both report green — you do not need to wait for A, B, and D if they are still running, but all five must complete before Phase 4.

E2E-6 depends on:
- Subagent C (embed booking flow)
- Subagent E (embed snippet from settings)

Read the E2E-6 section of `docs/roadmaps/ROADMAP-E2E.md` and implement it yourself.

**Your files:**
- Create: `tests/Browser/Embed/EmbedModeTest.php`
- Create: `tests/Browser/Embed/PopupEmbedTest.php`
- Create: `tests/Browser/CrossCutting/AuthorizationEdgeCasesTest.php`
- Create: `tests/Browser/CrossCutting/AccessibilityTest.php`
- Create: `tests/Browser/CrossCutting/TimezoneTest.php`

Do not modify any Support files. Use the helpers as they are.

---

## Phase 4 — Final verification (you execute this directly)

Wait for all Phase 2 subagents and Phase 3 to complete before running this.

1. Run the full suite: `php artisan test` — unit + feature + E2E must all be green.
2. Run `npm run build` — must be clean.
3. Create `docs/reviews/E2E-IMPLEMENTATION-REPORT.md` with:
    - Total E2E test count by session.
    - Any tests skipped and why (e.g., dependency on an incomplete feature session).
    - Any flaky tests found and how they were fixed.
    - Open follow-up items for future sessions.
4. Commit all changes: `test: E2E test suite implementation (E2E-1 through E2E-6)`.

---

## File ownership reference

| File / Directory | Who may modify |
|---|---|
| `tests/Browser/Support/AuthHelper.php` | Subagent A (fills in stubs); D and E read-only |
| `tests/Browser/Support/OnboardingHelper.php` | Subagent B (fills in stubs) |
| `tests/Browser/Support/BookingFlowHelper.php` | Subagent C (fills in stubs) |
| `tests/Browser/Support/BusinessSetup.php` | Subagent B (primary, fills in stubs); C may add methods if missing; D and E read-only |
| `tests/Browser/Auth/` | Subagent A |
| `tests/Browser/Onboarding/` | Subagent B |
| `tests/Browser/Booking/` | Subagent C |
| `tests/Browser/Dashboard/` | Subagent D |
| `tests/Browser/Settings/` | Subagent E |
| `tests/Browser/Embed/` | Orchestrator (Phase 3) |
| `tests/Browser/CrossCutting/` | Orchestrator (Phase 3) |
| `docs/reviews/E2E-IMPLEMENTATION-REPORT.md` | Orchestrator (Phase 4) |

---

## Execution order

```
[Precondition] smoke test green, all Support stubs present
    │
    ├─▶ Subagent A — E2E-1  ─┐
    ├─▶ Subagent B — E2E-2   │  all parallel, spawned simultaneously
    ├─▶ Subagent C — E2E-3   │
    ├─▶ Subagent D — E2E-4   │
    └─▶ Subagent E — E2E-5  ─┘
                              │
              wait for C + E  │  wait for all five
                    │         │
                    ▼         ▼
           Phase 3: E2E-6 [orchestrator]
                    │
                    ▼
           Phase 4: verification + report [orchestrator]
```

---

## Rules for every agent in this session

- Write tests only. Do not modify application source code. If a test reveals a bug, document it in the report — do not fix it.
- Use factories for all test data. Do not use the main database seeder.
- Do not use `sleep()` or fixed timeouts. Use Playwright's built-in waiting.
- Every test must be independently runnable. No cross-test shared state.
- On completion, always run `php artisan test --group=e2e` and confirm green before reporting done.
